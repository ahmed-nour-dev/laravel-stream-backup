<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Pipelines\RestorePipeline;
use Ahmednour\StreamBackup\Tests\Support\BuildsRestoreFixtures;
use Ahmednour\StreamBackup\Tests\Support\BuildsSqliteDumpFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Restores `sqlite3 .dump`-shaped backups into a real, file-backed SQLite
 * database through the full RestorePipeline (download, optional decryption,
 * gzip, parse, restore). Needs only pdo_sqlite and gzip, so it runs in the
 * default suite.
 */
final class SqliteRestoreTest extends TestCase
{
    use BuildsRestoreFixtures;
    use BuildsSqliteDumpFixtures;

    private string $root;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pdo_sqlite', 'zlib', 'openssl'] as $extension) {
            if (! extension_loaded($extension)) {
                self::markTestSkipped("ext-{$extension} is required.");
            }
        }
        if (trim((string) @shell_exec('command -v gzip 2>/dev/null')) === '') {
            self::markTestSkipped('gzip is not available on PATH.');
        }

        $this->root = sys_get_temp_dir() . '/sbr_sqlite_restore_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        $this->dbPath = $this->root . '/target.sqlite';
        touch($this->dbPath); // Laravel's SQLite connector requires the file to exist.
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_target');
        $this->removeDirectory($this->root);

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
        $app['config']->set('stream-backup.compression.driver', 'gzip');
    }

    private function db(): \Illuminate\Database\Connection
    {
        $this->app['config']->set('database.connections.sqlite_target', [
            'driver'                  => 'sqlite',
            'database'                => $this->dbPath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        return DB::connection('sqlite_target');
    }

    /**
     * @param string[] $tables
     */
    private function runRestore(string $dump, array $tables = [], string $encryption = 'none', string $key = ''): \Ahmednour\StreamBackup\DTOs\RestoreResult
    {
        $bytes = $this->gzipCompress($dump);
        if ($encryption !== 'none') {
            $bytes = $this->encryptAes256Gcm($bytes, $key);
            $this->app['config']->set('stream-backup.encryption.key', base64_encode($key));
        }

        $this->app['config']->set('stream-backup.encryption.driver', $encryption);
        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);
        $this->db(); // register the connection config

        $backup = new Backup([
            'id'                => 1,
            'database_name'     => 'irrelevant',
            'disk'              => 'local_test',
            'path'              => $this->writeLocalBackupFile($this->root, 'backup.sql.gz', $bytes),
            'encryption_driver' => $encryption,
            'dump_driver'       => 'sqlite3',
        ]);

        $context = new RestoreContext(
            backupId: 1,
            tables: $tables,
            connectionName: 'sqlite_target',
            databaseName: 'irrelevant',
            disk: 'local_test',
        );

        return $this->app->make(RestorePipeline::class)->run($context, $backup);
    }

    public function test_full_restore_loads_rows_indexes_triggers_views_and_sequences(): void
    {
        $result = $this->runRestore($this->sqliteDump());

        self::assertEqualsCanonicalizing(['sbr_users', 'sbr_posts'], $result->tablesRestored);
        self::assertSame(5, $result->totalRowsAffected);

        $db    = $this->db();
        $users = $db->table('sbr_users')->orderBy('id')->get();
        self::assertSame([1, 2, 5], $users->pluck('id')->map(fn ($i) => (int) $i)->all());
        self::assertNull($users[0]->note);
        self::assertSame("line one\nline two; -- not a comment", $users[1]->note);
        self::assertSame("multi\nline", $users[2]->note);
        self::assertSame('it\'s "quoted"', $users[2]->name);

        $names = $db->table('sqlite_master')->pluck('name')->all();
        foreach (['sbr_posts_user_idx', 'sbr_users_touch', 'sbr_post_view'] as $object) {
            self::assertContains($object, $names);
        }
        self::assertCount(2, $db->table('sbr_post_view')->get());

        // sqlite_sequence was restored (40), not left at the max rowid (5).
        $db->insert("INSERT INTO sbr_users (name) VALUES ('carol')");
        self::assertSame(41, (int) $db->table('sbr_users')->where('name', 'carol')->value('id'));
    }

    public function test_encrypted_and_compressed_backup_restores(): void
    {
        $result = $this->runRestore($this->sqliteDump(), [], 'openssl-aes-256-gcm', random_bytes(32));

        self::assertSame(5, $result->totalRowsAffected);
        self::assertSame(3, $this->db()->table('sbr_users')->count());
    }

    public function test_restore_replaces_existing_tables_and_keeps_unrelated_ones(): void
    {
        $db = $this->db();
        $db->statement('CREATE TABLE sbr_users (id INTEGER PRIMARY KEY, stale TEXT)');
        $db->insert("INSERT INTO sbr_users VALUES (500, 'old')");
        $db->statement('CREATE TABLE sbr_unrelated (id INTEGER PRIMARY KEY)');
        $db->insert('INSERT INTO sbr_unrelated VALUES (7)');

        $this->runRestore($this->sqliteDump());

        self::assertSame(0, $db->table('sbr_users')->where('id', 500)->count());
        self::assertFalse($db->getSchemaBuilder()->hasColumn('sbr_users', 'stale'));
        self::assertSame([7], $db->table('sbr_unrelated')->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_foreign_key_enforcement_is_back_on_after_the_restore(): void
    {
        $this->runRestore($this->sqliteDump());

        self::assertSame(1, (int) $this->db()->selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_failed_restore_leaves_the_database_as_it_was(): void
    {
        $db = $this->db();
        $db->statement('CREATE TABLE sbr_users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert("INSERT INTO sbr_users VALUES (500, 'keep-me')");

        // The last INSERT is invalid, after the tables were dropped and recreated.
        $broken = str_replace("INSERT INTO sbr_posts VALUES(11,2,'world');", "INSERT INTO sbr_posts VALUES(11,2,'world','extra-column');", $this->sqliteDump());

        try {
            $this->runRestore($broken);
            self::fail('Expected the restore to fail.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('rolled back', ($e->getPrevious() ?? $e)->getMessage());
        }

        self::assertSame(['keep-me'], $db->table('sbr_users')->pluck('name')->all());
        self::assertFalse($db->getSchemaBuilder()->hasTable('sbr_posts'));
        self::assertSame(1, (int) $db->selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_restore_that_breaks_a_foreign_key_rolls_back(): void
    {
        $orphan = str_replace("INSERT INTO sbr_posts VALUES(11,2,'world');", "INSERT INTO sbr_posts VALUES(11,999,'orphan');", $this->sqliteDump());

        try {
            $this->runRestore($orphan);
            self::fail('Expected the restore to fail.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('violates foreign keys', ($e->getPrevious() ?? $e)->getMessage());
        }

        self::assertFalse($this->db()->getSchemaBuilder()->hasTable('sbr_posts'));
    }

    public function test_selective_restore_of_one_side_of_a_foreign_key_is_refused(): void
    {
        $this->runRestore($this->sqliteDump());
        $this->db()->update("UPDATE sbr_users SET name = 'changed' WHERE id = 1");

        try {
            $this->runRestore($this->sqliteDump(), ['sbr_users']);
            self::fail('Expected the foreign-key boundary guard to refuse.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('crossing the restore boundary', ($e->getPrevious() ?? $e)->getMessage());
        }

        self::assertSame('changed', $this->db()->table('sbr_users')->where('id', 1)->value('name'));
    }

    public function test_selective_restore_of_a_dependency_closed_set_leaves_other_tables_alone(): void
    {
        $db = $this->db();
        $db->statement('CREATE TABLE sbr_unrelated (id INTEGER PRIMARY KEY)');
        $db->insert('INSERT INTO sbr_unrelated VALUES (7)');

        $result = $this->runRestore($this->sqliteDump(), ['sbr_users', 'sbr_posts']);

        self::assertEqualsCanonicalizing(['sbr_users', 'sbr_posts'], $result->tablesRestored);
        self::assertSame(2, $db->table('sbr_posts')->count());
        self::assertSame([7], $db->table('sbr_unrelated')->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_a_large_table_restores(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20000; $i++) {
            $rows[] = "INSERT INTO sbr_big VALUES({$i},'" . str_repeat('x', 40) . "');";
        }
        $dump = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE sbr_big (id INTEGER PRIMARY KEY, payload TEXT);\n"
            . implode("\n", $rows) . "\nCOMMIT;\n";

        $result = $this->runRestore($dump);

        self::assertSame(20000, $result->totalRowsAffected);
        self::assertSame(20000, $this->db()->table('sbr_big')->count());
    }
}
