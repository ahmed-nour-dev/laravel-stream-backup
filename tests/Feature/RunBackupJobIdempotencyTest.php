<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\AbstractProcessDumper;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Jobs\ReconcileBackupsJob;
use Ahmednour\StreamBackup\Jobs\RunBackupJob;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Models\BackupAttempt;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Exercises the idempotent-completion / remote-object-state acceptance
 * criteria from issue #23 at the RunBackupJob / ReconcileBackupsJob level
 * (BackupReconcilerTest covers the reconciliation decision tree in
 * isolation):
 *
 * - Remote objects are associated deterministically with their logical
 *   backup operation: every retry of the same attempt_group_id resolves
 *   to the exact same remote path, so a crashed attempt's partial/complete
 *   object is never orphaned at a path no later attempt will reuse.
 * - Re-running finalization for the same logical backup is safe: once a
 *   backup is Completed, a duplicate/late RunBackupJob delivery for the
 *   same attempt_group_id does not re-run the pipeline or touch the row.
 * - A crash between upload completion and database finalization is
 *   detected and recovered by the scheduled reconciliation sweep, wired
 *   through the real container (not a hand-built BackupReconciler).
 */
final class RunBackupJobIdempotencyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sbr_idempotency_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
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

    public function test_a_retry_reuses_the_exact_same_remote_path_as_the_failed_attempt(): void
    {
        if (! extension_loaded('zlib')) {
            self::markTestSkipped('ext-zlib is required.');
        }

        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);

        $counter = new IdempotencyAttemptCounter();
        $this->app->make(DumperFactory::class)->extend(
            'fake-idempotency-flaky',
            fn () => new FlakyOnceDumper($this->app->make(BinaryLocator::class), $this->app->make('config'), $counter),
        );

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_idempotent_db',
            connectionName: 'testing',
            disk:           'local_test',
            driver:         'fake-idempotency-flaky',
        );

        $job = new RunBackupJob($context);

        try {
            $this->app->call([$job, 'handle']);
            self::fail('Expected the first attempt to throw.');
        } catch (\Throwable) {
            // expected
        }

        $backup = Backup::where('attempt_group_id', $context->attemptGroupId)->first();
        self::assertNotNull($backup);
        $pathAfterFailedAttempt = $backup->path;
        self::assertNotEmpty($pathAfterFailedAttempt, 'A failed attempt must still record the remote path it targeted.');

        $this->app->call([$job, 'handle']);

        $backup->refresh();
        self::assertSame(BackupStatus::Completed, $backup->status);
        self::assertSame(
            $pathAfterFailedAttempt,
            $backup->path,
            'A retry of the same logical backup must target the exact same remote key as the failed attempt — '
            . 'otherwise the failed attempt\'s remote object (if it managed to write anything) is orphaned.',
        );
    }

    public function test_a_duplicate_delivery_of_an_already_completed_backup_is_a_no_op(): void
    {
        if (! extension_loaded('zlib')) {
            self::markTestSkipped('ext-zlib is required.');
        }

        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);

        $counter = new IdempotencyAttemptCounter();
        $this->app->make(DumperFactory::class)->extend(
            'fake-idempotency-ok',
            fn () => new AlwaysSucceedsDumper($this->app->make(BinaryLocator::class), $this->app->make('config'), $counter),
        );

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_completed_db',
            connectionName: 'testing',
            disk:           'local_test',
            driver:         'fake-idempotency-ok',
        );

        $job = new RunBackupJob($context);
        $this->app->call([$job, 'handle']);

        $backup = Backup::where('attempt_group_id', $context->attemptGroupId)->first();
        self::assertNotNull($backup);
        self::assertSame(BackupStatus::Completed, $backup->status);

        $finishedAtAfterFirstRun = $backup->finished_at;
        self::assertSame(1, $counter->calls);
        self::assertSame(1, BackupAttempt::where('backup_id', $backup->id)->count());

        // A late queue redelivery for the same dispatch (same attemptGroupId).
        $this->app->call([$job, 'handle']);

        $backup->refresh();
        self::assertSame(BackupStatus::Completed, $backup->status);
        self::assertEquals($finishedAtAfterFirstRun, $backup->finished_at, 'A no-op re-run must not touch the finalized row.');
        self::assertSame(1, $counter->calls, 'The dump pipeline must not run again for an already-completed backup.');
        self::assertSame(
            1,
            BackupAttempt::where('backup_id', $backup->id)->count(),
            'A no-op re-run must not create a new attempt row.',
        );
    }

    public function test_reconcile_backups_job_finalizes_a_backup_that_crashed_after_a_complete_upload(): void
    {
        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);
        $this->app['config']->set('stream-backup.schedule.reconcile.grace_minutes', 5);

        // A fully-written remote object, as if the upload finished but the
        // worker died before the final markAs(Completed) was persisted.
        $path = 'sbr_crash_db/2026/01/01/sbr_crash_db-20260101T000000Z.sql.gz';
        $full = $this->root . '/' . $path;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, "\x1f\x8b" . str_repeat('y', 118));

        $backup = Backup::create([
            'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'database_name'    => 'sbr_crash_db',
            'disk'             => 'local_test',
            'path'             => $path,
            'status'           => BackupStatus::Uploading->value,
            'started_at'       => CarbonImmutable::now()->subMinutes(10),
            'updated_at'       => CarbonImmutable::now()->subMinutes(10),
        ]);

        dispatch_sync(new ReconcileBackupsJob());

        $backup->refresh();
        self::assertSame(BackupStatus::Completed, $backup->status);
        self::assertSame(120, $backup->size);
        self::assertNotNull($backup->finished_at);
    }

    public function test_reconcile_backups_job_leaves_a_recently_updated_backup_alone(): void
    {
        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);
        $this->app['config']->set('stream-backup.schedule.reconcile.grace_minutes', 30);

        $path = 'sbr_live_db/2026/01/01/sbr_live_db-20260101T000000Z.sql.gz';
        $full = $this->root . '/' . $path;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, "\x1f\x8b" . str_repeat('y', 118));

        $backup = Backup::create([
            'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'database_name'    => 'sbr_live_db',
            'disk'             => 'local_test',
            'path'             => $path,
            'status'           => BackupStatus::Uploading->value,
            'started_at'       => CarbonImmutable::now(),
            'updated_at'       => CarbonImmutable::now(),
        ]);

        dispatch_sync(new ReconcileBackupsJob());

        $backup->refresh();
        self::assertSame(
            BackupStatus::Uploading,
            $backup->status,
            'A backup updated moments ago looks like a live worker still writing to it, not a crash — must not be touched yet.',
        );
    }
}

final class IdempotencyAttemptCounter
{
    public int $calls = 0;
}

/**
 * Fails (dumper process exits non-zero) on its first invocation, succeeds
 * on every subsequent one.
 */
final class FlakyOnceDumper extends AbstractProcessDumper
{
    public function __construct(
        BinaryLocator $locator,
        Config $config,
        private readonly IdempotencyAttemptCounter $counter,
    ) {
        parent::__construct($locator, $config);
    }

    protected function buildCommand(BackupContext $context): array
    {
        $this->counter->calls++;

        if ($this->counter->calls === 1) {
            return ['sh', '-c', 'echo "connection reset" 1>&2; exit 1'];
        }

        return ['sh', '-c', 'printf "fake dump bytes for retry test"'];
    }

    public function name(): string
    {
        return 'fake-idempotency-flaky';
    }
}

final class AlwaysSucceedsDumper extends AbstractProcessDumper
{
    public function __construct(
        BinaryLocator $locator,
        Config $config,
        private readonly IdempotencyAttemptCounter $counter,
    ) {
        parent::__construct($locator, $config);
    }

    protected function buildCommand(BackupContext $context): array
    {
        $this->counter->calls++;

        return ['sh', '-c', 'printf "fake dump bytes for completed-guard test"'];
    }

    public function name(): string
    {
        return 'fake-idempotency-ok';
    }
}
