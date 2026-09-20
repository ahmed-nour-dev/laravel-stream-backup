<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Integration\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Tests\Support\BuildsIntegrationBackups;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Exercises SQLiteDumper against a real, file-backed SQLite database, running
 * the real `sqlite3` CLI end to end (see issue: "Add integration-test matrix
 * for database and destination drivers").
 *
 * Skipped unless `sqlite3` is on PATH.
 */
final class SQLiteDumpIntegrationTest extends TestCase
{
    use BuildsIntegrationBackups;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->binaryAvailable('sqlite3')) {
            self::markTestSkipped('sqlite3 is not available on PATH.');
        }

        $this->dbPath = sys_get_temp_dir() . '/sbr_sqlite_it_' . bin2hex(random_bytes(6)) . '.sqlite';

        $this->app['config']->set('database.connections.sqlite_it', [
            'driver'   => 'sqlite',
            'database' => $this->dbPath,
            'prefix'   => '',
        ]);

        $db = DB::connection('sqlite_it');
        $db->statement('CREATE TABLE sbr_sqlite_it (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $db->insert('INSERT INTO sbr_sqlite_it (id, payload) VALUES (?, ?)', [1, 'sbr-sqlite-marker-alpha']);
        $db->insert('INSERT INTO sbr_sqlite_it (id, payload) VALUES (?, ?)', [2, 'sbr-sqlite-marker-beta']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_it');
        @unlink($this->dbPath);

        parent::tearDown();
    }

    public function test_sqlite3_dump_output_contains_the_seeded_table_and_rows(): void
    {
        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'sbr_sqlite_it_db',
            connectionName: 'sqlite_it',
            disk:           'unused',
            driver:         'sqlite',
        );

        $dumper = $this->app->make(DumperFactory::class)->make('sqlite');
        $stream = $dumper->dump($context);

        $output = $this->drain($stream);
        $stream->close(); // throws on a non-clean sqlite3 exit

        self::assertStringContainsString('CREATE TABLE sbr_sqlite_it', $output);
        self::assertStringContainsString('sbr-sqlite-marker-alpha', $output);
        self::assertStringContainsString('sbr-sqlite-marker-beta', $output);
    }
}
