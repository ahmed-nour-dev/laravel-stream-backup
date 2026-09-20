<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Integration\Uploaders;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Tests\Support\BuildsIntegrationBackups;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Full-pipeline (dump -> compress -> [encrypt] -> checksum -> upload ->
 * verify -> download -> [decrypt] -> decompress) integration coverage
 * against a real SFTP server (an ephemeral `atmoz/sftp` container in CI — no
 * real credentials required), driven through RunBackupJob exactly as the
 * `sync` queue connection runs it (see issue: "Add integration-test matrix
 * for database and destination drivers").
 *
 * The dump source is SQLite (self-contained, no extra service dependency)
 * so this suite is focused on the upload/verification path rather than the
 * dumper itself, which SQLiteDumpIntegrationTest already covers.
 *
 * Skipped unless `sqlite3` and `gzip` are on PATH and a test SFTP server is
 * reachable via:
 *   STREAM_BACKUP_TEST_SFTP_HOST / _PORT / _USER / _PASSWORD / _ROOT
 */
final class SftpIntegrationTest extends TestCase
{
    use BuildsIntegrationBackups;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sftpConfigured()) {
            self::markTestSkipped('STREAM_BACKUP_TEST_SFTP_HOST/_USER/_PASSWORD are not configured for the SFTP integration test.');
        }

        foreach (['sqlite3', 'gzip'] as $binary) {
            if (! $this->binaryAvailable($binary)) {
                self::markTestSkipped("{$binary} is not available on PATH.");
            }
        }

        $this->runPackageMigrations();

        $this->dbPath = sys_get_temp_dir() . '/sbr_sftp_it_' . bin2hex(random_bytes(6)) . '.sqlite';

        $this->app['config']->set('database.connections.sqlite_sftp_it', [
            'driver'   => 'sqlite',
            'database' => $this->dbPath,
            'prefix'   => '',
        ]);

        $db = DB::connection('sqlite_sftp_it');
        $db->statement('CREATE TABLE sbr_sftp_it (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $db->insert('INSERT INTO sbr_sftp_it (id, payload) VALUES (?, ?)', [1, 'sbr-sftp-round-trip-marker']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_sftp_it');
        @unlink($this->dbPath);

        parent::tearDown();
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('stream-backup.destination', [
            'driver'                => 'sftp',
            'host'                  => env('STREAM_BACKUP_TEST_SFTP_HOST'),
            'port'                  => (int) env('STREAM_BACKUP_TEST_SFTP_PORT', 22),
            'username'              => env('STREAM_BACKUP_TEST_SFTP_USER'),
            'password'              => env('STREAM_BACKUP_TEST_SFTP_PASSWORD'),
            'private_key'           => null,
            'passphrase'            => null,
            'visibility'            => 'public',
            'directory_visibility'  => 'public',
            'root'                  => env('STREAM_BACKUP_TEST_SFTP_ROOT', 'upload'),
        ]);

        $app['config']->set('stream-backup.compression.driver', 'gzip');
        $app['config']->set('stream-backup.encryption.driver', 'none');
    }

    public function test_unencrypted_backup_round_trips_through_a_real_sftp_service(): void
    {
        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_sftp_it_db',
            connectionName: 'sqlite_sftp_it',
            disk:           'sftp_unused',
            driver:         'sqlite',
        );

        $backup = $this->runBackupSync($context);

        self::assertSame(BackupStatus::Completed, $backup->status, (string) $backup->error_message);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $backup->checksum);

        $plaintext = $this->downloadAndDecompress($backup);
        self::assertStringContainsString('sbr-sftp-round-trip-marker', $plaintext);
    }

    public function test_encrypted_backup_round_trips_through_a_real_sftp_service(): void
    {
        $this->app['config']->set('stream-backup.encryption.driver', 'openssl-aes-256-gcm');
        $this->app['config']->set('stream-backup.encryption.key', base64_encode(random_bytes(32)));

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_sftp_it_db',
            connectionName: 'sqlite_sftp_it',
            disk:           'sftp_unused',
            driver:         'sqlite',
        );

        $backup = $this->runBackupSync($context);

        self::assertSame(BackupStatus::Completed, $backup->status, (string) $backup->error_message);
        self::assertSame('openssl-aes-256-gcm', $backup->encryption_driver);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $backup->checksum);

        $plaintext = $this->downloadAndDecompress($backup);
        self::assertStringContainsString('sbr-sftp-round-trip-marker', $plaintext);
    }

    private function sftpConfigured(): bool
    {
        return ! empty(env('STREAM_BACKUP_TEST_SFTP_HOST'))
            && ! empty(env('STREAM_BACKUP_TEST_SFTP_USER'))
            && ! empty(env('STREAM_BACKUP_TEST_SFTP_PASSWORD'));
    }
}
