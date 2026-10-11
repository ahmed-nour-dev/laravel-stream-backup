<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Integration\Restore;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Ahmednour\StreamBackup\Restore\PgSqlDumpParser;
use Ahmednour\StreamBackup\Restore\PgSqlRestorer;
use Ahmednour\StreamBackup\Tests\Support\BuildsIntegrationBackups;
use Ahmednour\StreamBackup\Tests\Support\BuildsPgDumpFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Restores plain-format pg_dump output into a real PostgreSQL server.
 *
 * Skipped unless STREAM_BACKUP_TEST_PGSQL_HOST / _USER / _DATABASE are set
 * (the same variables as the PostgreSQL dump integration test).
 */
final class PostgreSQLRestoreIntegrationTest extends TestCase
{
    use BuildsIntegrationBackups;
    use BuildsPgDumpFixtures;

    private const TABLES = ['sbr_pg_posts', 'sbr_pg_users', 'sbr_pg_other'];

    protected function setUp(): void
    {
        parent::setUp();

        if (empty(env('STREAM_BACKUP_TEST_PGSQL_HOST')) || empty(env('STREAM_BACKUP_TEST_PGSQL_USER')) || empty(env('STREAM_BACKUP_TEST_PGSQL_DATABASE'))) {
            self::markTestSkipped('STREAM_BACKUP_TEST_PGSQL_* are not configured.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('ext-pdo_pgsql is required.');
        }

        try {
            $this->cleanUp();
        } catch (\Throwable $e) {
            self::markTestSkipped('Could not connect to the PostgreSQL test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanUp();
        } catch (\Throwable) {
            // Never let cleanup mask a test failure.
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

    /**
     * @param string[] $tables
     */
    private function restore(string $dump, array $tables = [], bool $selective = false): \Ahmednour\StreamBackup\DTOs\RestoreResult
    {
        $parser = new PgSqlDumpParser($tables);
        $parser->feed($dump);
        $blocks = $parser->finish();

        return (new PgSqlRestorer())->restore($blocks, 'pgsql_test', microtime(true), $parser->getForeignKeys(), $selective);
    }

    public function test_full_restore_loads_rows_defaults_sequences_and_constraints(): void
    {
        $result = $this->restore($this->pgDump());

        self::assertEqualsCanonicalizing(['sbr_pg_users', 'sbr_pg_posts'], $result->tablesRestored);
        self::assertSame(5, $result->totalRowsAffected);

        $users = $this->db()->table('sbr_pg_users')->orderBy('id')->get();
        self::assertSame(['alice', 'bob'], [$users[0]->name, $users[1]->name]);
        self::assertStringStartsWith('-- Name: not_a_header', $users[2]->name);
        self::assertNull($users[0]->note);
        self::assertSame("line one\nline two\ttabbed", $users[1]->note);

        // setval ran, so the default draws the next id after the restored rows.
        $this->db()->insert("INSERT INTO sbr_pg_users (name) VALUES ('carol')");
        self::assertSame(4, (int) $this->db()->table('sbr_pg_users')->where('name', 'carol')->value('id'));

        // The foreign key is enforced.
        $this->expectException(\Throwable::class);
        $this->db()->insert("INSERT INTO sbr_pg_posts (id, user_id, body) VALUES (99, 12345, 'orphan')");
    }

    public function test_restore_replaces_existing_tables(): void
    {
        $this->db()->statement('CREATE TABLE sbr_pg_users (id integer PRIMARY KEY, name text, stale text)');
        $this->db()->insert("INSERT INTO sbr_pg_users (id, name, stale) VALUES (500, 'old', 'x')");

        $this->restore($this->pgDump());

        self::assertSame(0, $this->db()->table('sbr_pg_users')->where('id', 500)->count());
        self::assertFalse($this->db()->getSchemaBuilder()->hasColumn('sbr_pg_users', 'stale'));
    }

    public function test_failed_restore_rolls_back_to_the_previous_state(): void
    {
        $this->db()->statement('CREATE TABLE sbr_pg_users (id integer PRIMARY KEY, name text)');
        $this->db()->insert("INSERT INTO sbr_pg_users (id, name) VALUES (500, 'keep-me')");

        // A duplicate primary key in the data makes pass 2 fail after
        // tables were already dropped, recreated and loaded.
        $broken = str_replace("2\tbob", "1\tbob", $this->pgDump());

        try {
            $this->restore($broken);
            self::fail('Expected the restore to fail.');
        } catch (RestoreFailedException $e) {
            self::assertStringContainsString('rolled back', $e->getMessage());
        }

        self::assertSame(['keep-me'], $this->db()->table('sbr_pg_users')->pluck('name')->all());
        self::assertFalse($this->db()->getSchemaBuilder()->hasTable('sbr_pg_posts'));
    }

    public function test_selective_restore_of_one_side_of_a_foreign_key_is_refused(): void
    {
        $this->restore($this->pgDump());
        $this->db()->update("UPDATE sbr_pg_users SET name = 'changed' WHERE id = 1");

        try {
            $this->restore($this->pgDump(), ['sbr_pg_users'], true);
            self::fail('Expected the foreign-key boundary guard to refuse.');
        } catch (RestoreFailedException $e) {
            self::assertStringContainsString('crossing the restore boundary', $e->getMessage());
        }

        self::assertSame('changed', $this->db()->table('sbr_pg_users')->where('id', 1)->value('name'));
    }

    public function test_selective_restore_of_a_dependency_closed_set_works(): void
    {
        $this->db()->statement('CREATE TABLE sbr_pg_other (id integer PRIMARY KEY)');
        $this->db()->insert('INSERT INTO sbr_pg_other (id) VALUES (7)');

        $result = $this->restore($this->pgDump(), ['sbr_pg_users', 'sbr_pg_posts'], true);

        self::assertEqualsCanonicalizing(['sbr_pg_users', 'sbr_pg_posts'], $result->tablesRestored);
        self::assertSame([7], $this->db()->table('sbr_pg_other')->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_real_pg_dump_round_trips_through_the_restorer(): void
    {
        if (! $this->binaryAvailable('pg_dump')) {
            self::markTestSkipped('pg_dump is not available on PATH.');
        }

        $this->restore($this->pgDump());
        $this->db()->insert("INSERT INTO sbr_pg_users (name, note) VALUES ('dave', 'has \\\\ backslash')");

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   (string) env('STREAM_BACKUP_TEST_PGSQL_DATABASE'),
            connectionName: 'pgsql_test',
            disk:           'unused',
            driver:         'pgsql',
        );
        $stream = $this->app->make(DumperFactory::class)->make('pgsql')->dump($context);
        $dump   = $this->drain($stream);
        $stream->close();

        $before = $this->db()->table('sbr_pg_users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->db()->statement('DELETE FROM sbr_pg_posts');
        $this->db()->statement("UPDATE sbr_pg_users SET name = 'tampered'");

        $this->restore($dump, ['sbr_pg_users', 'sbr_pg_posts'], true);

        self::assertSame($before, $this->db()->table('sbr_pg_users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        self::assertSame(2, $this->db()->table('sbr_pg_posts')->count());
    }

    private function cleanUp(): void
    {
        $this->db()->statement('DROP TABLE IF EXISTS ' . implode(', ', self::TABLES) . ' CASCADE');
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    private function db()
    {
        return DB::connection('pgsql_test');
    }
}
