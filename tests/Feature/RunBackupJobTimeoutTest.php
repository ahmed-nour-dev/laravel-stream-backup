<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\AbstractProcessDumper;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Exceptions\IdleTimeoutExceededException;
use Ahmednour\StreamBackup\Jobs\RunBackupJob;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Drives RunBackupJob::handle() end-to-end (real proc_open dump process,
 * real local-disk upload driver) around a dump that stalls after its first
 * byte, proving the acceptance criteria at the level an operator actually
 * observes them: the persisted `backups` row, not just a thrown exception.
 *
 *  - "A backup cannot remain indefinitely stuck without an explicit
 *    configuration allowing it" — the stalled fixture would otherwise hang
 *    for 30s; with idle_timeout=1 it is caught almost immediately.
 *  - "Timeout cleanup leaves no orphaned child processes or multipart
 *    uploads" — StreamPipelineTimeoutTest already proves the pipeline layer
 *    aborts/terminates; this test proves the job layer surfaces that as
 *    BackupStatus::TimedOut instead of a generic Failed.
 */
final class RunBackupJobTimeoutTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sbr_job_timeout_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        $this->runPackageMigrations();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    private function runPackageMigrations(): void
    {
        foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('stream-backup.destination.driver', 'local');
        $app['config']->set('stream-backup.default_disk', 'local_test');
        $app['config']->set('stream-backup.compression.driver', 'gzip');
        $app['config']->set('stream-backup.encryption.driver', 'none');
        $app['config']->set('stream-backup.verify_after_upload', false);

        // idle_timeout small enough to trip quickly; max_runtime disabled so
        // only the idle safeguard is under test here.
        $app['config']->set('stream-backup.timeouts.idle_timeout', 1);
        $app['config']->set('stream-backup.timeouts.max_runtime', 0);
    }

    public function test_a_stalled_dump_marks_the_backup_timed_out_not_failed_or_stuck(): void
    {
        if (! extension_loaded('zlib')) {
            self::markTestSkipped('ext-zlib is required.');
        }

        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);

        $this->app->make(DumperFactory::class)->extend(
            'fake-job-stall',
            fn () => new StallAfterFirstByteJobFakeDumper(
                $this->app->make(\Ahmednour\StreamBackup\Support\BinaryLocator::class),
                $this->app->make('config'),
            ),
        );

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'jobtimeout_db',
            connectionName: 'testing',
            disk:           'local_test',
            driver:         'fake-job-stall',
        );

        $job = new RunBackupJob($context);

        try {
            $this->app->call([$job, 'handle']);
            self::fail('Expected RunBackupJob::handle() to rethrow the idle timeout.');
        } catch (IdleTimeoutExceededException) {
            // expected — RunBackupJob rethrows after recording the failure.
        }

        $backup = Backup::where('database_name', 'jobtimeout_db')->first();

        self::assertNotNull($backup, 'RunBackupJob must create the Backup tracking record before attempting the pipeline.');
        self::assertSame(
            BackupStatus::TimedOut,
            $backup->status,
            'An idle-timeout must be surfaced distinctly as TimedOut, not a generic Failed or a stuck non-terminal status.',
        );
        self::assertNotNull($backup->finished_at);
        self::assertNotEmpty($backup->error_message);
        self::assertStringContainsStringIgnoringCase('idle timeout', $backup->error_message);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirectory($item) : @unlink($item);
        }

        @rmdir($dir);
    }
}

/**
 * Emits one byte then sleeps for 30s — far longer than the 1s idle timeout
 * configured above — before ever producing more output or exiting.
 */
final class StallAfterFirstByteJobFakeDumper extends AbstractProcessDumper
{
    protected function buildCommand(BackupContext $context): array
    {
        return ['sh', '-c', 'printf x; sleep 30'];
    }

    public function name(): string
    {
        return 'fake-job-stall';
    }
}
