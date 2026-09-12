<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Enums\RestoreStatus;
use Ahmednour\StreamBackup\Jobs\RunRestoreJob;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Models\Restore;
use Ahmednour\StreamBackup\Pipelines\RestorePipeline;
use Ahmednour\StreamBackup\Tests\Support\BuildsRestoreFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Proves the acceptance criterion "Encryption/decryption failures are
 * surfaced as restore failures and do not leave an apparently successful
 * restore" at the level an operator actually observes it: the persisted
 * `restores` row, not just a thrown exception.
 *
 * RestorePipelineFailureModesTest already proves RestorePipeline::run()
 * itself throws on corrupt/wrong-key input; this test drives the full
 * RunRestoreJob::handle() around that same failure and asserts the Restore
 * record lands on RestoreStatus::Failed with an error message — never
 * Completed, and never left stuck on a non-terminal status.
 *
 * No real MySQL is needed: the failure happens while decrypting, before
 * TableRestorer ever opens the target connection, so `connectionName` here
 * is left deliberately unconfigured. It must NOT be the same connection
 * used for the backups/restores tracking tables ('testing'): RunRestoreJob
 * calls DB::disconnect($context->connectionName) on failure, which — if it
 * were the shared 'testing' sqlite `:memory:` connection — would wipe the
 * in-memory database (and its schema) out from under the very Restore
 * record this test is about to assert on.
 */
final class RestoreJobFailureSurfacingTest extends TestCase
{
    use BuildsRestoreFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('openssl') || ! extension_loaded('zlib')) {
            self::markTestSkipped('ext-openssl and ext-zlib are required.');
        }

        $this->root = sys_get_temp_dir() . '/sbr_job_failure_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        $this->runPackageMigrations();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * The package's own tracking tables (backups/restores) on the in-memory
     * sqlite testing connection. Run directly rather than through
     * RefreshDatabase/artisan migrate, which — for this Testbench setup —
     * doesn't reliably pick up loadMigrationsFrom() registrations against a
     * `:memory:` connection.
     */
    private function runPackageMigrations(): void
    {
        foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }
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
        $app['config']->set('stream-backup.encryption.driver', 'openssl-aes-256-gcm');
    }

    public function test_a_wrong_encryption_key_marks_the_restore_record_failed_not_completed(): void
    {
        $plaintext = $this->mysqldumpTableBlock(
            'sbr_jobfail_widgets',
            "CREATE TABLE `sbr_jobfail_widgets` (`id` INT UNSIGNED NOT NULL, `payload` VARCHAR(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;",
            $this->generateRowTuples(100),
        );

        $rightKey = random_bytes(32);
        $wrongKey = random_bytes(32);
        $encrypted = $this->encryptAes256Gcm($this->gzipCompress($plaintext), $rightKey);

        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);
        $this->app['config']->set('stream-backup.encryption.key', base64_encode($wrongKey));

        $path = $this->writeLocalBackupFile($this->root, 'backup.sql.gz', $encrypted);

        $backup = Backup::create([
            'database_name'     => 'irrelevant',
            'disk'              => 'local_test',
            'path'              => $path,
            'status'            => BackupStatus::Completed,
            'encryption_driver' => 'openssl-aes-256-gcm',
        ]);

        $context = new RestoreContext(
            backupId: $backup->id,
            tables: [],
            connectionName: 'restore_target_unused',
            databaseName: 'irrelevant',
            disk: 'local_test',
        );

        $job = new RunRestoreJob($context);

        try {
            $job->handle($this->app->make(RestorePipeline::class));
            self::fail('Expected RunRestoreJob::handle() to rethrow the decryption failure.');
        } catch (\Throwable) {
            // Expected — RunRestoreJob rethrows after recording the failure.
        }

        $restore = Restore::where('backup_id', $backup->id)->first();

        self::assertNotNull($restore, 'RunRestoreJob must create the Restore tracking record before attempting the pipeline.');
        self::assertSame(
            RestoreStatus::Failed,
            $restore->status,
            'A decryption failure must never leave the restore record on a non-terminal status or mark it Completed.',
        );
        self::assertNotNull($restore->finished_at);
        self::assertNotEmpty($restore->error_message);
        self::assertStringContainsStringIgnoringCase('key is wrong', $restore->error_message);
        self::assertNull($restore->tables_restored, 'No tables were ever restored; this must not look like a (partial) success.');
    }
}
