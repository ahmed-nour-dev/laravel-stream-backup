<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\MySQLDumper;
use Ahmednour\StreamBackup\Exceptions\BinaryNotFoundException;
use Ahmednour\StreamBackup\Exceptions\DumpFailedException;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\ProcessBackupStream;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Support\MySQLCredentialFile;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Regression coverage for the MySQL credential-file lifecycle: the file
 * must survive for as long as the mysqldump process needs it and be
 * deleted immediately once that specific process's resources are released,
 * rather than accumulating until PHP shutdown — see issue #17. This matters
 * most for long-lived queue workers, which run many dump jobs without ever
 * hitting a shutdown between them.
 */
final class MySQLDumperTest extends TestCase
{
    /**
     * @return array{0: MySQLDumper, 1: MySQLCredentialFile}
     */
    private function makeDumper(string $binary = '/bin/true'): array
    {
        config()->set('database.connections.mysql', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'test_db',
            'username' => 'root',
            'password' => 's3cret',
        ]);
        config()->set('stream-backup.dump.drivers.mysql.binary', $binary);

        $credentialFile = new MySQLCredentialFile();
        $dumper         = new MySQLDumper(new BinaryLocator(), $this->app->make('config'), $credentialFile);

        return [$dumper, $credentialFile];
    }

    private function makeContext(): BackupContext
    {
        return new BackupContext(
            tenantId:       null,
            databaseName:   'test_db',
            connectionName: 'mysql',
            disk:           'spaces',
        );
    }

    public function test_credential_file_exists_while_the_process_is_running_and_is_removed_after_a_successful_close(): void
    {
        [$dumper, $credentialFile] = $this->makeDumper('/bin/true');

        $stream = $dumper->dump($this->makeContext());
        self::assertInstanceOf(ProcessBackupStream::class, $stream);

        $path = $credentialFile->path();
        self::assertNotNull($path);
        self::assertFileExists($path, 'Credential file must still exist while the dump process is running.');

        while ($stream->read() !== null) {
            // drain
        }
        $stream->close();

        self::assertFileDoesNotExist($path, 'Credential file must be removed immediately after the process exits.');
    }

    public function test_credential_file_is_removed_even_when_the_dump_process_fails(): void
    {
        [$dumper, $credentialFile] = $this->makeDumper('/bin/false');

        $stream = $dumper->dump($this->makeContext());
        $path   = $credentialFile->path();
        self::assertNotNull($path);

        while ($stream->read() !== null) {
            // drain
        }

        try {
            $stream->close();
            self::fail('Expected DumpFailedException was not thrown.');
        } catch (DumpFailedException) {
            // expected — mysqldump exiting non-zero must still trigger cleanup
        }

        self::assertFileDoesNotExist($path);
    }

    public function test_credential_file_is_removed_when_the_binary_cannot_be_located(): void
    {
        [$dumper, $credentialFile] = $this->makeDumper('/nonexistent/path/mysqldump-does-not-exist');

        try {
            $dumper->dump($this->makeContext());
            self::fail('Expected BinaryNotFoundException was not thrown.');
        } catch (BinaryNotFoundException) {
            // expected
        }

        $path = $credentialFile->path();
        self::assertNotNull($path, 'The credential file is written before the binary is located.');
        self::assertFileDoesNotExist($path, 'A failed process start must not leak the credential file.');
    }

    public function test_no_credential_file_is_written_when_the_connection_is_unconfigured(): void
    {
        [$dumper, $credentialFile] = $this->makeDumper('/bin/true');

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'test_db',
            connectionName: 'nonexistent',
            disk:           'spaces',
        );

        try {
            $dumper->dump($context);
            self::fail('Expected PipelineException was not thrown.');
        } catch (PipelineException) {
            // expected
        }

        self::assertNull($credentialFile->path());
    }

    public function test_credential_file_is_removed_when_the_process_is_killed_without_close(): void
    {
        // Mirrors StreamPipeline's cancellation/timeout path, which
        // terminates the raw process resource directly instead of calling
        // BackupStream::close() — the destructor safety net must still
        // release the credential file. mysqldump's own flags are always
        // appended to the command, so a plain long-running binary (like
        // `sleep`) can't be reused directly; a tiny wrapper script that
        // ignores its arguments stands in for it instead.
        $script = tempnam(sys_get_temp_dir(), 'stream_bkp_test_sleep_');
        file_put_contents($script, "#!/bin/sh\nsleep 5\n");
        chmod($script, 0755);

        try {
            [$dumper, $credentialFile] = $this->makeDumper($script);
            $context = $this->makeContext();

            $stream = $dumper->dump($context);
            $pipes  = $stream->pipes();

            proc_terminate($pipes->process);
            proc_close($pipes->process);

            unset($stream);

            $path = $credentialFile->path();
            self::assertNotNull($path);
            self::assertFileDoesNotExist($path);
        } finally {
            @unlink($script);
        }
    }
}
