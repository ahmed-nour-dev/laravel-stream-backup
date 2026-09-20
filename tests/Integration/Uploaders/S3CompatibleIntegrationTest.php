<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Integration\Uploaders;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Support\BackupVerifier;
use Ahmednour\StreamBackup\Tests\Support\BuildsIntegrationBackups;
use Ahmednour\StreamBackup\Tests\TestCase;
use Aws\S3\S3ClientInterface;
use Illuminate\Support\Facades\DB;

/**
 * Full-pipeline (dump -> compress -> [encrypt] -> checksum -> upload ->
 * verify -> download -> [decrypt] -> decompress) integration coverage
 * against a real S3-compatible service (MinIO in CI — no real cloud
 * credentials required), driven through RunBackupJob exactly as the `sync`
 * queue connection runs it (see issue: "Add integration-test matrix for
 * database and destination drivers").
 *
 * The dump source is SQLite (self-contained, no extra service dependency)
 * so this suite is focused on the upload/verification path rather than the
 * dumper itself, which SQLiteDumpIntegrationTest already covers.
 *
 * Skipped unless `sqlite3` and `gzip` are on PATH and a MinIO (or other
 * S3-compatible) endpoint is reachable via:
 *   STREAM_BACKUP_TEST_S3_ENDPOINT / _KEY / _SECRET / _BUCKET / _REGION
 */
final class S3CompatibleIntegrationTest extends TestCase
{
    use BuildsIntegrationBackups;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->s3Configured()) {
            self::markTestSkipped('STREAM_BACKUP_TEST_S3_ENDPOINT/_KEY/_SECRET/_BUCKET are not configured for the S3-compatible integration test.');
        }

        foreach (['sqlite3', 'gzip'] as $binary) {
            if (! $this->binaryAvailable($binary)) {
                self::markTestSkipped("{$binary} is not available on PATH.");
            }
        }

        $this->runPackageMigrations();
        $this->ensureBucketExists();

        $this->dbPath = sys_get_temp_dir() . '/sbr_s3_it_' . bin2hex(random_bytes(6)) . '.sqlite';
        touch($this->dbPath); // Laravel's SQLiteConnector requires the file to pre-exist — it never creates it.

        $this->app['config']->set('database.connections.sqlite_s3_it', [
            'driver'   => 'sqlite',
            'database' => $this->dbPath,
            'prefix'   => '',
        ]);

        $db = DB::connection('sqlite_s3_it');
        $db->statement('CREATE TABLE sbr_s3_it (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $db->insert('INSERT INTO sbr_s3_it (id, payload) VALUES (?, ?)', [1, 'sbr-s3-round-trip-marker']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_s3_it');
        @unlink($this->dbPath);

        parent::tearDown();
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.disks.s3', [
            'driver'                  => 's3',
            'key'                     => env('STREAM_BACKUP_TEST_S3_KEY'),
            'secret'                  => env('STREAM_BACKUP_TEST_S3_SECRET'),
            'region'                  => env('STREAM_BACKUP_TEST_S3_REGION', 'us-east-1'),
            'bucket'                  => env('STREAM_BACKUP_TEST_S3_BUCKET'),
            'endpoint'                => env('STREAM_BACKUP_TEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
        ]);

        $app['config']->set('stream-backup.default_disk', 's3');
        $app['config']->set('stream-backup.destination.driver', 's3');
        $app['config']->set('stream-backup.compression.driver', 'gzip');
        $app['config']->set('stream-backup.encryption.driver', 'none');
    }

    public function test_unencrypted_backup_round_trips_through_a_real_s3_compatible_service(): void
    {
        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_s3_it_db',
            connectionName: 'sqlite_s3_it',
            disk:           's3',
            driver:         'sqlite',
        );

        $backup = $this->runBackupSync($context);

        self::assertSame(BackupStatus::Completed, $backup->status, (string) $backup->error_message);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $backup->checksum);

        $plaintext = $this->downloadAndDecompress($backup);
        self::assertStringContainsString('sbr-s3-round-trip-marker', $plaintext);
    }

    /**
     * Full checksum verification is opt-in and off by default — this proves
     * that with it enabled, a real backup against a real S3-compatible
     * service still passes: whichever path BackupVerifier takes (a
     * server-side full-object checksum if MinIO ever returns one, or the
     * streaming-download fallback otherwise), the content it verifies
     * against is exactly what ChecksumStream recorded during upload.
     */
    public function test_full_checksum_verification_passes_against_a_real_s3_compatible_service(): void
    {
        $this->app['config']->set('stream-backup.full_checksum_verification', true);

        $context = new BackupContext(
            tenantId:       null,
            // Distinct databaseName from the round-trip tests above/below:
            // BackupPathBuilder's path only has second-level timestamp
            // precision, so reusing the same name risks two tests in this
            // file landing on the identical remote path.
            databaseName:   'sbr_s3_it_db_checksum_match',
            connectionName: 'sqlite_s3_it',
            disk:           's3',
            driver:         'sqlite',
        );

        $backup = $this->runBackupSync($context);

        self::assertSame(BackupStatus::Completed, $backup->status, (string) $backup->error_message);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $backup->checksum);
    }

    /**
     * A corrupted recorded checksum must be caught by full verification —
     * re-running BackupVerifier against the same, untouched remote object
     * with a deliberately wrong expected checksum proves the mismatch path
     * actually compares against real remote content, not a mocked one.
     */
    public function test_full_checksum_verification_detects_a_checksum_mismatch_against_a_real_s3_compatible_service(): void
    {
        $this->app['config']->set('stream-backup.full_checksum_verification', true);

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_s3_it_db_checksum_mismatch',
            connectionName: 'sqlite_s3_it',
            disk:           's3',
            driver:         'sqlite',
        );

        $backup = $this->runBackupSync($context);
        self::assertSame(BackupStatus::Completed, $backup->status, (string) $backup->error_message);

        $backup->forceFill(['checksum' => str_repeat('0', 64)])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup checksum mismatch');

        $this->app->make(BackupVerifier::class)->verify($backup);
    }

    public function test_encrypted_backup_round_trips_through_a_real_s3_compatible_service(): void
    {
        $this->app['config']->set('stream-backup.encryption.driver', 'openssl-aes-256-gcm');
        $this->app['config']->set('stream-backup.encryption.key', base64_encode(random_bytes(32)));

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_s3_it_db',
            connectionName: 'sqlite_s3_it',
            disk:           's3',
            driver:         'sqlite',
        );

        $backup = $this->runBackupSync($context);

        self::assertSame(BackupStatus::Completed, $backup->status, (string) $backup->error_message);
        self::assertSame('openssl-aes-256-gcm', $backup->encryption_driver);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $backup->checksum);

        $plaintext = $this->downloadAndDecompress($backup);
        self::assertStringContainsString('sbr-s3-round-trip-marker', $plaintext);
    }

    /**
     * The test bucket is expected to pre-exist in real cloud usage, but an
     * ephemeral MinIO container starts empty — create it here (idempotently)
     * so this test works against a bare `docker run minio/minio` with no
     * separate bucket-provisioning step required.
     */
    private function ensureBucketExists(): void
    {
        $s3     = $this->app->make(S3ClientInterface::class);
        $bucket = (string) env('STREAM_BACKUP_TEST_S3_BUCKET');

        try {
            $s3->headBucket(['Bucket' => $bucket]);
        } catch (\Throwable) {
            try {
                $s3->createBucket(['Bucket' => $bucket]);
            } catch (\Throwable) {
                // Ignore races/already-exists — a genuine problem surfaces
                // on the first real putObject/headObject call below.
            }
        }
    }

    private function s3Configured(): bool
    {
        return ! empty(env('STREAM_BACKUP_TEST_S3_ENDPOINT'))
            && ! empty(env('STREAM_BACKUP_TEST_S3_KEY'))
            && ! empty(env('STREAM_BACKUP_TEST_S3_SECRET'))
            && ! empty(env('STREAM_BACKUP_TEST_S3_BUCKET'));
    }
}
