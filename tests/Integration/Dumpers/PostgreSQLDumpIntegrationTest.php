<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Integration\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Tests\Support\BuildsIntegrationBackups;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Exercises PostgreSQLDumper against a real PostgreSQL server, running the
 * real `pg_dump` binary end to end (see issue: "Add integration-test matrix
 * for database and destination drivers").
 *
 * Restore does not support PostgreSQL backups (SqlDumpParser only
 * understands mysqldump output — see README "Roadmap"), so this only
 * exercises the dump side.
 *
 * Skipped unless `pg_dump` is on PATH and a test PostgreSQL server is
 * reachable via:
 *   STREAM_BACKUP_TEST_PGSQL_HOST / _PORT / _USER / _PASSWORD / _DATABASE
 */
final class PostgreSQLDumpIntegrationTest extends TestCase
{
    use BuildsIntegrationBackups;

    private const TABLE = 'sbr_pg_dump_it';

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->pgsqlConfigured()) {
            self::markTestSkipped('STREAM_BACKUP_TEST_PGSQL_HOST/_USER/_DATABASE are not configured for the PostgreSQL dump integration test.');
        }

        if (! $this->binaryAvailable('pg_dump')) {
            self::markTestSkipped('pg_dump is not available on PATH.');
        }

        try {
            $this->db()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->db()->statement('CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY, payload VARCHAR(64) NOT NULL)');
            $this->db()->insert('INSERT INTO ' . self::TABLE . ' (id, payload) VALUES (?, ?)', [1, 'sbr-pgsql-marker-alpha']);
            $this->db()->insert('INSERT INTO ' . self::TABLE . ' (id, payload) VALUES (?, ?)', [2, 'sbr-pgsql-marker-beta']);
        } catch (\Throwable $e) {
            self::markTestSkipped('Could not connect to the PostgreSQL test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pgsqlConfigured()) {
            try {
                $this->db()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
            } catch (\Throwable) {
                // Best-effort: never let cleanup mask a test failure.
            }
        }

        parent::tearDown();
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.pgsql_test', [
            'driver'   => 'pgsql',
            'host'     => env('STREAM_BACKUP_TEST_PGSQL_HOST'),
            'port'     => (int) env('STREAM_BACKUP_TEST_PGSQL_PORT', 5432),
            'database' => env('STREAM_BACKUP_TEST_PGSQL_DATABASE'),
            'username' => env('STREAM_BACKUP_TEST_PGSQL_USER'),
            'password' => env('STREAM_BACKUP_TEST_PGSQL_PASSWORD', ''),
        ]);
    }

    public function test_pg_dump_output_contains_the_seeded_table_and_rows(): void
    {
        $context = new BackupContext(
            tenantId:       null,
            databaseName:   (string) env('STREAM_BACKUP_TEST_PGSQL_DATABASE'),
            connectionName: 'pgsql_test',
            disk:           'unused',
            driver:         'pgsql',
        );

        $dumper = $this->app->make(DumperFactory::class)->make('pgsql');
        $stream = $dumper->dump($context);

        $output = $this->drain($stream);
        $stream->close(); // throws DumpFailedException/DumpPartialException on a non-clean pg_dump exit

        self::assertStringContainsString(self::TABLE, $output, 'pg_dump output must reference the seeded table.');
        self::assertStringContainsString('sbr-pgsql-marker-alpha', $output, 'pg_dump output must contain the seeded row data.');
        self::assertStringContainsString('sbr-pgsql-marker-beta', $output, 'pg_dump output must contain the seeded row data.');
    }

    private function pgsqlConfigured(): bool
    {
        return ! empty(env('STREAM_BACKUP_TEST_PGSQL_HOST'))
            && ! empty(env('STREAM_BACKUP_TEST_PGSQL_USER'))
            && ! empty(env('STREAM_BACKUP_TEST_PGSQL_DATABASE'));
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    private function db()
    {
        return DB::connection('pgsql_test');
    }
}
