<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Events\BackupCleanupFailed;
use Ahmednour\StreamBackup\Exceptions\CleanupFailedException;
use Ahmednour\StreamBackup\Jobs\BackupCleanupJob;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Support\BackupReconciler;
use Ahmednour\StreamBackup\Support\BackupVerifier;
use Ahmednour\StreamBackup\Support\PreflightChecker;
use Ahmednour\StreamBackup\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;

/**
 * Regression tests for issue #55: retention cleanup must never fail
 * silently, and must never drop a row whose remote object is still there.
 */
final class BackupCleanupJobTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sbr_cleanup_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }

        $this->app['config']->set('filesystems.disks.local_test', [
            'driver' => 'local',
            'root'   => $this->root,
        ]);

        // A disk with `'throw' => false` whose delete() always fails.
        Storage::extend('always_false', static function (): Filesystem {
            $disk = Mockery::mock(Filesystem::class);
            $disk->shouldReceive('delete')->andReturn(false);

            return $disk;
        });
        $this->app['config']->set('filesystems.disks.false_disk', ['driver' => 'always_false']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('stream-backup.retention', ['daily' => 1, 'weekly' => 0, 'monthly' => 0]);
    }

    private function makeCompleted(string $path, int $daysAgo, string $disk = 'local_test', bool $writeFile = true): Backup
    {
        if ($writeFile) {
            @mkdir(dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, 'dump');
        }

        return Backup::create([
            'attempt_group_id' => (string) Str::uuid(),
            'database_name'    => 'sbr_test_db',
            'disk'             => $disk,
            'path'             => $path,
            'status'           => BackupStatus::Completed->value,
            'retention_tier'   => 'daily',
            'started_at'       => CarbonImmutable::now()->subDays($daysAgo),
            'finished_at'      => CarbonImmutable::now()->subDays($daysAgo),
        ]);
    }

    private function runJob(): void
    {
        dispatch_sync(new BackupCleanupJob());
    }

    public function test_it_deletes_expired_backups_and_their_files(): void
    {
        $keep = $this->makeCompleted('db/today.sql.gz', 0);
        $old1 = $this->makeCompleted('db/old1.sql.gz', 1);
        $old2 = $this->makeCompleted('db/old2.sql.gz', 2);

        $this->runJob();

        self::assertNotNull(Backup::find($keep->id));
        self::assertNull(Backup::find($old1->id));
        self::assertNull(Backup::find($old2->id));
        self::assertFileExists($this->root . '/db/today.sql.gz');
        self::assertFileDoesNotExist($this->root . '/db/old1.sql.gz');
        self::assertFileDoesNotExist($this->root . '/db/old2.sql.gz');
    }

    public function test_unresolvable_disk_is_logged_reported_and_keeps_the_row(): void
    {
        Event::fake([BackupCleanupFailed::class]);
        Log::spy();

        $this->makeCompleted('db/today.sql.gz', 0, 'missing_disk', writeFile: false);
        $stale = $this->makeCompleted('db/old.sql.gz', 1, 'missing_disk', writeFile: false);

        try {
            $this->runJob();
            self::fail('Expected CleanupFailedException');
        } catch (CleanupFailedException $e) {
            self::assertStringContainsString('could not delete 1 of 1', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }

        self::assertNotNull(Backup::find($stale->id), 'Row must be kept so the next run retries it.');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'stream-backup: failed to delete expired backup'
                && $ctx['backup_id'] === $stale->id
                && $ctx['disk'] === 'missing_disk'
                && $ctx['path'] === 'db/old.sql.gz')
            ->once();

        Event::assertDispatched(
            BackupCleanupFailed::class,
            fn (BackupCleanupFailed $event) => count($event->failures) === 1
                && $event->failures[0]['backup_id'] === $stale->id,
        );
    }

    public function test_delete_returning_false_is_treated_as_a_failure(): void
    {
        Event::fake([BackupCleanupFailed::class]);
        Log::spy();

        $this->makeCompleted('db/today.sql.gz', 0, 'false_disk', writeFile: false);
        $stale = $this->makeCompleted('db/old.sql.gz', 1, 'false_disk', writeFile: false);

        $this->expectException(CleanupFailedException::class);

        try {
            $this->runJob();
        } finally {
            self::assertNotNull(Backup::find($stale->id), 'A false delete() must not drop the row.');
            Log::shouldHaveReceived('warning')->once();
            Event::assertDispatched(BackupCleanupFailed::class);
        }
    }

    public function test_one_failing_disk_does_not_stop_other_deletes(): void
    {
        Event::fake([BackupCleanupFailed::class]);

        $this->makeCompleted('db/today.sql.gz', 0);
        $good = $this->makeCompleted('db/good.sql.gz', 1);
        $bad  = $this->makeCompleted('db/bad.sql.gz', 2, 'missing_disk', writeFile: false);

        try {
            $this->runJob();
            self::fail('Expected CleanupFailedException');
        } catch (CleanupFailedException $e) {
            self::assertStringContainsString('could not delete 1 of 2', $e->getMessage());
        }

        self::assertNull(Backup::find($good->id));
        self::assertFileDoesNotExist($this->root . '/db/good.sql.gz');
        self::assertNotNull(Backup::find($bad->id));
    }

    public function test_preflight_fails_when_the_s3_cleanup_disk_cannot_be_built(): void
    {
        $this->app['config']->set('stream-backup.destination.driver', 's3');
        $this->app['config']->set('stream-backup.default_disk', 'broken_s3');
        $this->app['config']->set('filesystems.disks.broken_s3', ['driver' => 'not-installed']);

        $uploader = Mockery::mock(UploadDriver::class);
        $uploader->shouldNotReceive('preflight');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require league/flysystem-aws-s3-v3');

        (new PreflightChecker($uploader, $this->app['config']))->check();
    }

    public function test_preflight_passes_when_the_s3_disk_resolves(): void
    {
        $this->app['config']->set('stream-backup.destination.driver', 's3');
        $this->app['config']->set('stream-backup.default_disk', 's3');

        $uploader = Mockery::mock(UploadDriver::class);
        $uploader->shouldReceive('preflight')->once();

        (new PreflightChecker($uploader, $this->app['config']))->check();
    }

    public function test_preflight_skips_the_disk_check_for_non_s3_destinations(): void
    {
        $this->app['config']->set('stream-backup.destination.driver', 'sftp');
        $this->app['config']->set('stream-backup.default_disk', 'not_a_laravel_disk');

        $uploader = Mockery::mock(UploadDriver::class);
        $uploader->shouldReceive('preflight')->once();

        (new PreflightChecker($uploader, $this->app['config']))->check();
    }

    public function test_reconciler_cleanup_orphan_reports_a_false_delete(): void
    {
        Log::spy();

        $backup = Backup::create([
            'attempt_group_id' => (string) Str::uuid(),
            'database_name'    => 'sbr_test_db',
            'disk'             => 'false_disk',
            'path'             => 'db/orphan.sql.gz',
            'status'           => BackupStatus::Uploading->value,
            'started_at'       => CarbonImmutable::now()->subHour(),
        ]);

        $reconciler = new BackupReconciler($this->app->make(BackupVerifier::class));

        self::assertFalse($reconciler->cleanupOrphan($backup));
        Log::shouldHaveReceived('warning')->once();
    }
}
