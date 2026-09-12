<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Pipelines\RestorePipeline;
use Ahmednour\StreamBackup\Tests\Support\BuildsRestoreFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Failure-mode integration coverage for RestorePipeline that does NOT require
 * a real MySQL server: every scenario here fails during download/decrypt/
 * decompress, before TableRestorer ever opens a database connection, so
 * these run unconditionally (no STREAM_BACKUP_TEST_* env vars needed).
 *
 * Scenarios (see issue: "Add large-scale restore integration tests for
 * streaming, dependencies, and rollback"):
 *  - Corrupted encrypted backup.
 *  - Wrong encryption key.
 *  - Destination/network interruption (a connection that drops mid-transfer,
 *    modelled here as a truncated local file — the practical proxy available
 *    without a live S3/SFTP endpoint; it exercises the exact same code path
 *    a dropped network read would: the decompressor receives a truncated
 *    stream and exits non-zero).
 *
 * Acceptance criterion under test: "Encryption/decryption failures are
 * surfaced as restore failures and do not leave an apparently successful
 * restore" — every case below asserts the pipeline throws rather than
 * returning a RestoreResult.
 */
final class RestorePipelineFailureModesTest extends TestCase
{
    use BuildsRestoreFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is required.');
        }

        if (! extension_loaded('zlib')) {
            self::markTestSkipped('ext-zlib is required to build gzip fixtures.');
        }

        if (trim((string) @shell_exec('command -v gzip 2>/dev/null')) === '') {
            self::markTestSkipped('gzip is not available on PATH.');
        }

        $this->root = sys_get_temp_dir() . '/sbr_failure_modes_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('stream-backup.destination.driver', 'local');
        $app['config']->set('stream-backup.default_disk', 'local_test');
        $app['config']->set('stream-backup.compression.driver', 'gzip');
    }

    public function test_corrupted_encrypted_backup_fails_the_restore_instead_of_succeeding_partially(): void
    {
        $plaintext = $this->sampleDump();
        $key       = random_bytes(32);

        $encrypted = $this->encryptAes256Gcm($this->gzipCompress($plaintext), $key);

        // Flip a byte inside the first ciphertext frame (past the 13-byte
        // header + 4-byte length + into the 16-byte GCM tag), which must
        // fail authentication even though the key is correct.
        $corruptOffset             = 13 + 4 + 5;
        $encrypted[$corruptOffset] = chr(ord($encrypted[$corruptOffset]) ^ 0xFF);

        $result = $this->attemptRestore($encrypted, 'openssl-aes-256-gcm', $key);

        self::assertInstanceOf(PipelineException::class, $result);
        self::assertStringContainsStringIgnoringCase(
            'corrupt',
            $result->getPrevious()?->getMessage() ?? $result->getMessage(),
        );
    }

    public function test_wrong_encryption_key_fails_the_restore_instead_of_decrypting_garbage(): void
    {
        $plaintext  = $this->sampleDump();
        $rightKey   = random_bytes(32);
        $wrongKey   = random_bytes(32);

        $encrypted = $this->encryptAes256Gcm($this->gzipCompress($plaintext), $rightKey);

        $result = $this->attemptRestore($encrypted, 'openssl-aes-256-gcm', $wrongKey);

        self::assertInstanceOf(PipelineException::class, $result);
        self::assertStringContainsStringIgnoringCase(
            'key is wrong',
            $result->getPrevious()?->getMessage() ?? $result->getMessage(),
        );
    }

    public function test_stream_truncated_mid_transfer_fails_loudly_instead_of_restoring_a_partial_dump(): void
    {
        $plaintext  = $this->sampleDump();
        $compressed = $this->gzipCompress($plaintext);

        // Model a dropped destination/network connection: the download
        // stream ends part-way through a valid gzip member. The spawned
        // decompressor must exit non-zero on the truncated input, and that
        // failure must propagate out of the pipeline rather than being
        // swallowed (which would otherwise silently restore a truncated,
        // partial dataset and report success).
        $truncated = substr($compressed, 0, intdiv(strlen($compressed), 2));

        $result = $this->attemptRestore($truncated, 'none', '');

        self::assertInstanceOf(PipelineException::class, $result);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function sampleDump(): string
    {
        return $this->mysqldumpTableBlock(
            'sbr_failuremode_widgets',
            "CREATE TABLE `sbr_failuremode_widgets` (\n"
            . "  `id` INT UNSIGNED NOT NULL,\n"
            . "  `payload` VARCHAR(64) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;",
            $this->generateRowTuples(200),
        );
    }

    /**
     * Configure encryption, write $fileBytes as the backup file, run
     * RestorePipeline against it, and return the thrown Throwable (the test
     * fails outright if no exception is thrown, since every scenario in this
     * class must fail, never "succeed" against corrupt/incomplete input).
     */
    private function attemptRestore(string $fileBytes, string $encryptionDriver, string $rawKey): \Throwable
    {
        $this->app['config']->set('stream-backup.encryption.driver', $encryptionDriver);
        if ($encryptionDriver !== 'none') {
            $this->app['config']->set('stream-backup.encryption.key', base64_encode($rawKey));
        }
        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);

        $path = $this->writeLocalBackupFile($this->root, 'backup.sql.gz', $fileBytes);

        $backup = new Backup([
            'id'                => 1,
            'database_name'     => 'irrelevant',
            'disk'              => 'local_test',
            'path'              => $path,
            'encryption_driver' => $encryptionDriver,
        ]);

        $context = new RestoreContext(
            backupId: 1,
            tables: [],
            connectionName: 'testing',
            databaseName: 'irrelevant',
            disk: 'local_test',
        );

        $pipeline = $this->app->make(RestorePipeline::class);

        try {
            $pipeline->run($context, $backup);
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected RestorePipeline::run() to throw for corrupt/incomplete/misconfigured input, but it returned a result.');
    }
}
