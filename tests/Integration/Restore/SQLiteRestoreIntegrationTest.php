<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Integration\Restore;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Restore\SqliteDumpParser;
use Ahmednour\StreamBackup\Restore\SqliteRestorer;
use Ahmednour\StreamBackup\Tests\Support\BuildsIntegrationBackups;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Round-trips a database through the real `sqlite3 .dump` and SqliteRestorer.
 *
 * Skipped unless `sqlite3` is on PATH.
 */
final class SQLiteRestoreIntegrationTest extends TestCase
{
    use BuildsIntegrationBackups;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->binaryAvailable('sqlite3')) {
            self::markTestSkipped('sqlite3 is not available on PATH.');
        }

        $this->dbPath = sys_get_temp_dir() . '/sbr_sqlite_restore_it_' . bin2hex(random_bytes(6)) . '.sqlite';
        touch($this->dbPath);

        $this->app['config']->set('database.connections.sqlite_it', [
            'driver'                  => 'sqlite',
            'database'                => $this->dbPath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_it');
        @unlink($this->dbPath);

        parent::tearDown();
    }

    public function test_real_sqlite3_dump_round_trips_through_the_restorer(): void
    {
        $db = DB::connection('sqlite_it');
        $db->statement('CREATE TABLE sbr_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT)');
        $db->statement('CREATE TABLE sbr_posts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES sbr_users(id), body TEXT)');
        $db->statement('CREATE INDEX sbr_posts_user_idx ON sbr_posts(user_id)');
        $db->statement('CREATE VIEW sbr_post_view AS SELECT id, body FROM sbr_posts');
        $db->statement("CREATE TRIGGER sbr_users_touch AFTER INSERT ON sbr_users BEGIN UPDATE sbr_posts SET body = CASE WHEN new.id > 0 THEN body ELSE 'x' END WHERE 0; END");
        $db->insert("INSERT INTO sbr_users (name, note) VALUES ('alice', NULL), ('bob', 'multi' || char(10) || 'line; -- x'), ('it''s', 'q\"uote')");
        $db->insert("INSERT INTO sbr_posts (id, user_id, body) VALUES (1, 1, 'hello'), (2, 2, 'world')");
        $db->delete('DELETE FROM sbr_users WHERE id = 3'); // leaves sqlite_sequence ahead of max(id)

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   $this->dbPath,
            connectionName: 'sqlite_it',
            disk:           'unused',
            driver:         'sqlite',
        );
        $stream = $this->app->make(DumperFactory::class)->make('sqlite')->dump($context);
        $dump   = $this->drain($stream);
        $stream->close();

        $users = $db->table('sbr_users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $posts = $db->table('sbr_posts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $db->statement('DELETE FROM sbr_posts');
        $db->statement("UPDATE sbr_users SET name = 'tampered'");
        $db->statement('DROP TRIGGER sbr_users_touch');

        $parser = new SqliteDumpParser();
        $parser->feed($dump);
        $result = (new SqliteRestorer())->restore($parser->finish(), 'sqlite_it', microtime(true), $parser->getForeignKeys());

        self::assertEqualsCanonicalizing(['sbr_users', 'sbr_posts'], $result->tablesRestored);
        self::assertSame($users, $db->table('sbr_users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        self::assertSame($posts, $db->table('sbr_posts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());

        $objects = $db->table('sqlite_master')->pluck('name')->all();
        foreach (['sbr_posts_user_idx', 'sbr_post_view', 'sbr_users_touch'] as $object) {
            self::assertContains($object, $objects);
        }

        $db->insert("INSERT INTO sbr_users (name) VALUES ('next')");
        self::assertSame(4, (int) $db->table('sbr_users')->where('name', 'next')->value('id'));
    }
}
