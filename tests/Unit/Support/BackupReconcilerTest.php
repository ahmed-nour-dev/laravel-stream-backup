<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Support;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Enums\ReconciliationOutcome;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Support\BackupReconciler;
use Ahmednour\StreamBackup\Support\BackupVerifier;
use Ahmednour\StreamBackup\Tests\TestCase;
use Carbon\CarbonImmutable;

/**
 * Covers the acceptance criteria from issue #23 ("Make backup completion
 * and remote-object state idempotent") that are about BackupReconciler
 * specifically:
 *
 * - Re-running finalization for the same logical backup is safe (no
 *   duplicate work, no exception) once it is already Completed.
 * - A crash between "the remote object was fully written" and "the row
 *   was marked Completed" is detected and recovered without operator
 *   intervention.
 * - Retries never overwrite a valid Completed backup — reconcile() must
 *   be a pure no-op once a row is Completed, even if called again.
 * - A remote object that doesn't match what we expected (wrong size, or
 *   present but empty) is flagged rather than silently adopted.
 * - Orphaned/suspect rows (a path recorded, but not yet Completed) can be
 *   listed for cleanup via findSuspectBackups().
 *
 * Uses the `local` destination driver so the "remote object" is a real
 * file on disk — no S3/SFTP mocking needed to prove the reconciliation
 * decision tree end-to-end.
 */
final class BackupReconcilerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sbr_reconciler_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        foreach (glob(__DIR__ . '/../../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    protected function tearDown(): void
    {
        $items = glob($this->root . '/*') ?: [];
        foreach ($items as $item) {
            @unlink($item);
        }
        @rmdir($this->root);

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
    }

    private function reconciler(): BackupReconciler
    {
        return new BackupReconciler($this->app->make(BackupVerifier::class));
    }

    /**
     * `driver` is required for Storage::disk('local_test') (used by
     * cleanupOrphan()); `root` is what BackupVerifier's own local-path
     * resolution reads directly from config.
     */
    private function configureLocalDisk(): void
    {
        $this->app['config']->set('filesystems.disks.local_test', [
            'driver' => 'local',
            'root'   => $this->root,
        ]);
    }

    private function makeBackup(array $overrides = []): Backup
    {
        return Backup::create(array_merge([
            'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'database_name'    => 'sbr_test_db',
            'disk'             => 'local_test',
            'status'           => BackupStatus::Uploading->value,
            'started_at'       => CarbonImmutable::now()->subMinutes(45),
        ], $overrides));
    }

    private function writeRemoteObject(string $path, string $contents): void
    {
        $full = $this->root . '/' . ltrim($path, '/');
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    public function test_already_completed_backup_is_a_pure_no_op(): void
    {
        $this->configureLocalDisk();

        $backup = $this->makeBackup([
            'status'      => BackupStatus::Completed->value,
            'path'        => 'db/completed.sql.gz',
            'size'        => 100,
            'finished_at' => CarbonImmutable::now(),
        ]);

        $outcome = $this->reconciler()->reconcile($backup);

        self::assertSame(ReconciliationOutcome::AlreadyCompleted, $outcome);

        // Calling it again changes nothing — proves idempotency, not just
        // a single successful call.
        $outcome2 = $this->reconciler()->reconcile($backup);
        self::assertSame(ReconciliationOutcome::AlreadyCompleted, $outcome2);
    }

    public function test_no_remote_object_is_left_untouched(): void
    {
        $this->configureLocalDisk();

        $backup = $this->makeBackup([
            'status' => BackupStatus::Failed->value,
            'path'   => 'db/never-uploaded.sql.gz',
        ]);

        $outcome = $this->reconciler()->reconcile($backup);

        self::assertSame(ReconciliationOutcome::NoRemoteObject, $outcome);

        $backup->refresh();
        self::assertSame(BackupStatus::Failed, $backup->status, 'A genuinely missing object must not be finalized.');
    }

    public function test_crash_between_upload_and_finalization_is_recovered(): void
    {
        $this->configureLocalDisk();

        $contents = "\x1f\x8b" . str_repeat('x', 98); // gzip magic + padding, 100 bytes
        $this->writeRemoteObject('db/crashed-before-finalize.sql.gz', $contents);

        $backup = $this->makeBackup([
            'status'            => BackupStatus::Uploading->value,
            'path'              => 'db/crashed-before-finalize.sql.gz',
            'size'              => null, // crash happened before this was persisted
            'encryption_driver' => null,
        ]);

        $outcome = $this->reconciler()->reconcile($backup);

        self::assertSame(ReconciliationOutcome::Finalized, $outcome);

        $backup->refresh();
        self::assertSame(BackupStatus::Completed, $backup->status);
        self::assertSame(100, $backup->size);
        self::assertNotNull($backup->finished_at);
        self::assertNull($backup->error_message);
    }

    public function test_does_not_overwrite_a_backup_completed_by_a_concurrent_worker(): void
    {
        $this->configureLocalDisk();

        $contents = "\x1f\x8b" . str_repeat('x', 98);
        $this->writeRemoteObject('db/race.sql.gz', $contents);

        $backup = $this->makeBackup([
            'status'            => BackupStatus::Uploading->value,
            'path'              => 'db/race.sql.gz',
            'size'              => null,
            'encryption_driver' => null,
        ]);

        // Simulate RunBackupJob finishing normally for this same row a
        // moment before the reconciler's own finalize() UPDATE runs. $backup
        // itself is deliberately left stale (still believing Uploading) so
        // reconcile() walks all the way to finalize()'s atomic UPDATE and
        // loses that race, rather than short-circuiting on the status check.
        Backup::query()->whereKey($backup->id)->update([
            'status'      => BackupStatus::Completed->value,
            'size'        => 999,
            'finished_at' => CarbonImmutable::now(),
        ]);

        $outcome = $this->reconciler()->reconcile($backup);

        self::assertSame(ReconciliationOutcome::AlreadyCompleted, $outcome);

        $backup->refresh();
        self::assertSame(999, $backup->size, 'The legitimately-completed size must survive untouched.');
    }

    public function test_zero_byte_remote_object_is_flagged_not_finalized(): void
    {
        $this->configureLocalDisk();
        $this->writeRemoteObject('db/empty.sql.gz', '');

        $backup = $this->makeBackup([
            'status' => BackupStatus::Failed->value,
            'path'   => 'db/empty.sql.gz',
        ]);

        $outcome = $this->reconciler()->reconcile($backup);

        self::assertSame(ReconciliationOutcome::SizeMismatch, $outcome);
        self::assertTrue($outcome->isOrphanCandidate());

        $backup->refresh();
        self::assertSame(BackupStatus::Failed, $backup->status);
    }

    public function test_size_mismatch_against_previously_recorded_size_is_flagged(): void
    {
        $this->configureLocalDisk();
        $this->writeRemoteObject('db/partial.sql.gz', "\x1f\x8b" . str_repeat('x', 8));

        $backup = $this->makeBackup([
            'status' => BackupStatus::Uploading->value,
            'path'   => 'db/partial.sql.gz',
            'size'   => 5_000_000, // what the pipeline expected to write
        ]);

        $outcome = $this->reconciler()->reconcile($backup);

        self::assertSame(ReconciliationOutcome::SizeMismatch, $outcome);

        $backup->refresh();
        self::assertNotSame(BackupStatus::Completed, $backup->status);
    }

    public function test_cleanup_orphan_deletes_the_remote_object(): void
    {
        $this->configureLocalDisk();
        $this->writeRemoteObject('db/orphan.sql.gz', '');

        $backup = $this->makeBackup([
            'status' => BackupStatus::Failed->value,
            'path'   => 'db/orphan.sql.gz',
        ]);

        self::assertFileExists($this->root . '/db/orphan.sql.gz');

        $deleted = $this->reconciler()->cleanupOrphan($backup);

        self::assertTrue($deleted);
        self::assertFileDoesNotExist($this->root . '/db/orphan.sql.gz');
    }

    public function test_find_suspect_backups_excludes_completed_and_pathless_rows(): void
    {
        $this->configureLocalDisk();

        $suspect = $this->makeBackup([
            'status' => BackupStatus::Uploading->value,
            'path'   => 'db/suspect.sql.gz',
        ]);
        $this->makeBackup([
            'status'      => BackupStatus::Completed->value,
            'path'        => 'db/done.sql.gz',
            'finished_at' => CarbonImmutable::now(),
        ]);
        $this->makeBackup([
            'status' => BackupStatus::Pending->value,
            'path'   => null,
        ]);

        $found = $this->reconciler()->findSuspectBackups();

        self::assertCount(1, $found);
        self::assertSame($suspect->id, $found->first()->id);
    }

    public function test_find_suspect_backups_honours_the_grace_threshold(): void
    {
        $this->configureLocalDisk();

        $recent = $this->makeBackup([
            'status'     => BackupStatus::Uploading->value,
            'path'       => 'db/recent.sql.gz',
            'updated_at' => CarbonImmutable::now(),
        ]);

        $found = $this->reconciler()->findSuspectBackups(CarbonImmutable::now()->subMinutes(30));

        self::assertTrue($found->isEmpty(), 'A row updated moments ago must not be treated as stale yet.');
        unset($recent);
    }
}
