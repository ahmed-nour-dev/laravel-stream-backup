<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\AbstractProcessDumper;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Jobs\RunBackupJob;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Models\BackupAttempt;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Proves the acceptance criteria from "Track backup attempts separately
 * from logical backup records": a queue retry of RunBackupJob must not
 * produce a second, apparently-independent `backups` row. Instead, one
 * `backups` row represents the logical operation and every execution
 * (attempt) — including the failed one(s) — is recorded on its own
 * `backup_attempts` row underneath it.
 *
 * The retry itself is simulated by invoking the same RunBackupJob
 * instance's handle() twice — exactly what Laravel's queue worker does
 * for an automatic retry, since it re-executes the job from the same
 * serialized payload (same BackupContext, same attemptGroupId) rather
 * than constructing a new one.
 */
final class RunBackupJobAttemptTrackingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sbr_attempt_tracking_' . bin2hex(random_bytes(6));
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

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
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
        $app['config']->set('stream-backup.encryption.driver', 'none');
        $app['config']->set('stream-backup.verify_after_upload', false);
    }

    public function test_a_failed_attempt_followed_by_a_successful_retry_shares_one_logical_backup(): void
    {
        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);

        $counter = new AttemptCounter();
        $this->app->make(DumperFactory::class)->extend(
            'fake-flaky',
            fn () => new FlakyDatabaseDumper($this->app->make('config'), $counter),
        );

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_flaky_db',
            connectionName: 'testing',
            disk:           'local_test',
            driver:         'fake-flaky',
        );

        $job = new RunBackupJob($context);

        try {
            $this->app->call([$job, 'handle']);
            self::fail('Expected the first attempt (dumper exits non-zero) to throw.');
        } catch (\Throwable) {
            // expected — RunBackupJob rethrows after recording the failure.
        }

        self::assertSame(1, Backup::count(), 'The failed attempt must not create its own backup row.');

        $backup = Backup::first();
        self::assertNotNull($backup);
        self::assertSame($context->attemptGroupId, $backup->attempt_group_id);
        self::assertSame(BackupStatus::Failed, $backup->status);
        self::assertNotEmpty($backup->error_message);

        // Simulates Laravel re-running the same queued job for its retry:
        // same job instance, same $context, same attemptGroupId.
        $this->app->call([$job, 'handle']);

        self::assertSame(
            1,
            Backup::count(),
            'A retry of the same logical backup must reuse the existing backup row, not create a second one.',
        );

        $backup->refresh();
        self::assertSame(
            BackupStatus::Completed,
            $backup->status,
            'The logical backup must reflect the outcome of the latest attempt.',
        );
        self::assertNotNull($backup->finished_at);

        $attempts = BackupAttempt::where('backup_id', $backup->id)->orderBy('attempt_number')->get();
        self::assertCount(2, $attempts, 'Each execution of the job must be recorded as its own attempt.');

        [$firstAttempt, $secondAttempt] = $attempts;

        self::assertSame(1, $firstAttempt->attempt_number);
        self::assertSame(BackupStatus::Failed, $firstAttempt->status);
        self::assertNotEmpty($firstAttempt->error_message);
        self::assertNotNull($firstAttempt->started_at);
        self::assertNotNull($firstAttempt->finished_at);
        self::assertNotNull($firstAttempt->duration, 'Attempt duration must be observable even on failure.');

        self::assertSame(2, $secondAttempt->attempt_number);
        self::assertSame(BackupStatus::Completed, $secondAttempt->status);
        self::assertNull($secondAttempt->error_message);
        self::assertNotNull($secondAttempt->duration);
    }
}

final class AttemptCounter
{
    public int $calls = 0;
}

/**
 * Fails its first invocation (dumper process exits non-zero) and succeeds
 * on every subsequent one — simulating a database dump that fails once
 * (e.g. transient connection blip) and succeeds on retry.
 */
final class FlakyDatabaseDumper extends AbstractProcessDumper
{
    public function __construct(
        Config $config,
        private readonly AttemptCounter $counter,
    ) {
        parent::__construct(new BinaryLocator(), $config);
    }

    protected function buildCommand(BackupContext $context): array
    {
        $this->counter->calls++;

        if ($this->counter->calls === 1) {
            return ['sh', '-c', 'echo "connection reset by peer" 1>&2; exit 1'];
        }

        return ['sh', '-c', 'printf "fake dump bytes"'];
    }

    public function name(): string
    {
        return 'fake-flaky';
    }
}
