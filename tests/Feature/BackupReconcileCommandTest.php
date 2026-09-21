<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Jobs\ReconcileBackupsJob;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class BackupReconcileCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sbr_reconcile_cmd_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $item) {
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

    private function makeStaleUploadingBackup(string $path, string $contents): Backup
    {
        $full = $this->root . '/' . $path;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);

        return Backup::create([
            'attempt_group_id' => (string) Str::uuid(),
            'database_name'    => 'sbr_cmd_db',
            'disk'             => 'local_test',
            'path'             => $path,
            'status'           => BackupStatus::Uploading->value,
            'started_at'       => CarbonImmutable::now()->subHour(),
            'updated_at'       => CarbonImmutable::now()->subHour(),
        ]);
    }

    public function test_dry_run_finalizes_a_recoverable_backup_and_reports_it(): void
    {
        $this->app['config']->set('filesystems.disks.local_test', ['driver' => 'local', 'root' => $this->root]);

        $backup = $this->makeStaleUploadingBackup('db/found.sql.gz', "\x1f\x8b" . str_repeat('a', 18));

        $this->artisan('backup:reconcile')->assertSuccessful();

        $backup->refresh();
        self::assertSame(BackupStatus::Completed, $backup->status);
    }

    public function test_clean_flag_deletes_the_remote_object_for_an_orphan_candidate(): void
    {
        $this->app['config']->set('filesystems.disks.local_test', ['driver' => 'local', 'root' => $this->root]);

        $backup = $this->makeStaleUploadingBackup('db/orphan.sql.gz', ''); // 0 bytes -> SizeMismatch

        self::assertFileExists($this->root . '/db/orphan.sql.gz');

        $this->artisan('backup:reconcile --clean')->assertSuccessful();

        self::assertFileDoesNotExist($this->root . '/db/orphan.sql.gz');

        $backup->refresh();
        self::assertNotSame(BackupStatus::Completed, $backup->status, 'A deleted orphan is not a valid completion.');
    }

    public function test_queue_flag_dispatches_instead_of_running_synchronously(): void
    {
        $this->app['config']->set('filesystems.disks.local_test', ['driver' => 'local', 'root' => $this->root]);
        Bus::fake();

        $backup = $this->makeStaleUploadingBackup('db/queued.sql.gz', "\x1f\x8b" . str_repeat('a', 18));

        $this->artisan('backup:reconcile --queue')->assertSuccessful();

        Bus::assertDispatched(ReconcileBackupsJob::class);

        $backup->refresh();
        self::assertSame(
            BackupStatus::Uploading,
            $backup->status,
            'With --queue the reconciliation itself must not run inline.',
        );
    }
}
