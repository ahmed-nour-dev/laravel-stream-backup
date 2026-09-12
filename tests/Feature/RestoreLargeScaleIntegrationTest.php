<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Pipelines\RestorePipeline;
use Ahmednour\StreamBackup\Tests\Support\BuildsRestoreFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Full-pipeline (download -> decrypt -> decompress -> parse -> restore)
 * integration coverage for realistic large-scale restore scenarios, against
 * a real MySQL server via RestorePipeline exactly as RunRestoreJob drives it
 * — the gap RestoreRollbackTest (which calls TableRestorer directly) and
 * SqlDumpParserBoundedMemoryTest (which never touches a database) each leave
 * open.
 *
 * Scenarios (see issue: "Add large-scale restore integration tests for
 * streaming, dependencies, and rollback"):
 *  - Large compressed backup restore.
 *  - Large compressed + encrypted backup restore.
 *  - Many tables.
 *  - Partial/table-selective restore.
 *
 * Skipped unless a test MySQL database is reachable via the standard env
 * vars (same gate as RestoreRollbackTest / StreamPipelineSmokeTest):
 *   STREAM_BACKUP_TEST_HOST / _PORT / _USER / _PASSWORD / _DATABASE
 *
 * Point STREAM_BACKUP_TEST_DATABASE at a disposable database: every table
 * this suite touches is prefixed `sbr_ls_` and cleaned up before and after
 * each test, but a full restore replays DROP TABLE against real table
 * names, so this must never point at a database with tables you care about.
 */
final class RestoreLargeScaleIntegrationTest extends TestCase
{
    use BuildsRestoreFixtures;

    private const TABLE_PREFIX = 'sbr_ls_';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sbr_large_scale_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        if ($this->mysqlConfigured()) {
            $this->dropPrefixedTables();
        }
    }

    protected function tearDown(): void
    {
        if ($this->mysqlConfigured()) {
            try {
                $this->dropPrefixedTables();
            } catch (\Throwable) {
                // Best-effort: never let cleanup mask a test failure.
            }
        }

        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.mysql_test', [
            'driver'   => 'mysql',
            'host'     => env('STREAM_BACKUP_TEST_HOST'),
            'port'     => (int) env('STREAM_BACKUP_TEST_PORT', 3306),
            'database' => env('STREAM_BACKUP_TEST_DATABASE'),
            'username' => env('STREAM_BACKUP_TEST_USER'),
            'password' => env('STREAM_BACKUP_TEST_PASSWORD', ''),
            'charset'  => 'utf8mb4',
        ]);

        $app['config']->set('stream-backup.destination.driver', 'local');
        $app['config']->set('stream-backup.default_disk', 'local_test');
        $app['config']->set('stream-backup.compression.driver', 'gzip');
        $app['config']->set('stream-backup.encryption.driver', 'none');
    }

    public function test_large_compressed_backup_restore_of_a_single_large_table(): void
    {
        $this->skipUnlessMysqlAvailable();

        $table = self::TABLE_PREFIX . 'bigtable';
        $rowCount = 50_000;

        $dump = $this->mysqldumpTableBlock(
            $table,
            "CREATE TABLE `{$table}` (\n"
            . "  `id` INT UNSIGNED NOT NULL,\n"
            . "  `payload` VARCHAR(64) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;",
            $this->generateRowTuples($rowCount),
        );

        gc_collect_cycles();
        $peakBefore = memory_get_peak_usage(true);

        $result = $this->runRestore($dump);

        $peakDelta = memory_get_peak_usage(true) - $peakBefore;

        self::assertSame([$table], $result->tablesRestored);
        self::assertSame(0, $result->skippedStatements);

        $count = $this->db()->selectOne("SELECT COUNT(*) AS n FROM `{$table}`");
        self::assertSame($rowCount, (int) $count->n);

        // "does not require database-sized temporary storage": the dump text
        // alone is multiple MB; the pipeline's PHP-side memory footprint
        // must stay a small, bounded fraction of that regardless of table
        // size (streamed decompression + spill-to-disk parsing + PDO::exec
        // per batch, never a single in-memory blob of the whole table).
        self::assertLessThan(
            16 * 1024 * 1024,
            $peakDelta,
            sprintf('Restoring a %d-row table increased peak PHP memory by %.1f MB.', $rowCount, $peakDelta / 1024 / 1024),
        );
    }

    public function test_large_compressed_and_encrypted_backup_restore(): void
    {
        $this->skipUnlessMysqlAvailable();

        if (! extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is required.');
        }

        $bigTable   = self::TABLE_PREFIX . 'enc_big';
        $smallTableA = self::TABLE_PREFIX . 'enc_small_a';
        $smallTableB = self::TABLE_PREFIX . 'enc_small_b';
        $bigRowCount = 20_000;

        $dump = $this->mysqldumpTableBlock(
            $bigTable,
            "CREATE TABLE `{$bigTable}` (\n"
            . "  `id` INT UNSIGNED NOT NULL,\n"
            . "  `payload` VARCHAR(64) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;",
            $this->generateRowTuples($bigRowCount),
        )
        . $this->mysqldumpTableBlock(
            $smallTableA,
            "CREATE TABLE `{$smallTableA}` (`id` INT UNSIGNED NOT NULL, `name` VARCHAR(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;",
            ["(1,'alpha')", "(2,'beta')"],
        )
        . $this->mysqldumpTableBlock(
            $smallTableB,
            "CREATE TABLE `{$smallTableB}` (`id` INT UNSIGNED NOT NULL, `name` VARCHAR(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;",
            ["(1,'gamma')"],
        );

        $key = random_bytes(32);

        gc_collect_cycles();
        $peakBefore = memory_get_peak_usage(true);

        $result = $this->runRestore($dump, encrypt: true, key: $key);

        $peakDelta = memory_get_peak_usage(true) - $peakBefore;

        self::assertEqualsCanonicalizing([$bigTable, $smallTableA, $smallTableB], $result->tablesRestored);

        $count = $this->db()->selectOne("SELECT COUNT(*) AS n FROM `{$bigTable}`");
        self::assertSame($bigRowCount, (int) $count->n);

        $a = $this->db()->select("SELECT `name` FROM `{$smallTableA}` ORDER BY `id`");
        self::assertSame(['alpha', 'beta'], array_map(static fn ($r) => $r->name, $a));

        $b = $this->db()->selectOne("SELECT `name` FROM `{$smallTableB}` WHERE `id` = 1");
        self::assertSame('gamma', $b->name);

        self::assertLessThan(
            16 * 1024 * 1024,
            $peakDelta,
            sprintf('Restoring an encrypted %d-row backup increased peak PHP memory by %.1f MB.', $bigRowCount, $peakDelta / 1024 / 1024),
        );
    }

    public function test_many_tables_restore_reconstructs_every_table(): void
    {
        $this->skipUnlessMysqlAvailable();

        $tableCount = 25;
        $rowsPerTable = 40;

        $dump = '';
        $expectedTables = [];

        for ($t = 1; $t <= $tableCount; $t++) {
            $table = sprintf('%stbl_%02d', self::TABLE_PREFIX, $t);
            $expectedTables[] = $table;

            $rows = [];
            for ($i = 1; $i <= $rowsPerTable; $i++) {
                $rows[] = "({$i},{$t})";
            }

            $dump .= $this->mysqldumpTableBlock(
                $table,
                "CREATE TABLE `{$table}` (`id` INT UNSIGNED NOT NULL, `tag` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;",
                $rows,
            );
        }

        $result = $this->runRestore($dump);

        self::assertEqualsCanonicalizing($expectedTables, $result->tablesRestored);

        foreach ($expectedTables as $i => $table) {
            $expectedTag = $i + 1;
            $row = $this->db()->selectOne("SELECT `tag` FROM `{$table}` WHERE `id` = 1");
            self::assertNotNull($row, "Table `{$table}` must exist with its data after a {$tableCount}-table restore.");
            self::assertSame($expectedTag, (int) $row->tag);

            $count = $this->db()->selectOne("SELECT COUNT(*) AS n FROM `{$table}`");
            self::assertSame($rowsPerTable, (int) $count->n);
        }
    }

    public function test_partial_table_selective_restore_only_touches_requested_tables(): void
    {
        $this->skipUnlessMysqlAvailable();

        $alpha = self::TABLE_PREFIX . 'sel_alpha';
        $beta  = self::TABLE_PREFIX . 'sel_beta';
        $gamma = self::TABLE_PREFIX . 'sel_gamma';

        $db = $this->db();
        foreach ([$alpha, $beta, $gamma] as $table) {
            $db->unprepared("CREATE TABLE `{$table}` (`id` INT UNSIGNED NOT NULL, `value` VARCHAR(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB");
            $db->insert("INSERT INTO `{$table}` (`id`, `value`) VALUES (1, 'original')");
        }

        $dump = $this->mysqldumpTableBlock(
            $alpha,
            "CREATE TABLE `{$alpha}` (`id` INT UNSIGNED NOT NULL, `value` VARCHAR(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;",
            ["(1,'restored-alpha')"],
        )
        . $this->mysqldumpTableBlock(
            $beta,
            "CREATE TABLE `{$beta}` (`id` INT UNSIGNED NOT NULL, `value` VARCHAR(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;",
            ["(1,'restored-beta')"],
        )
        . $this->mysqldumpTableBlock(
            $gamma,
            "CREATE TABLE `{$gamma}` (`id` INT UNSIGNED NOT NULL, `value` VARCHAR(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;",
            ["(1,'restored-gamma')"],
        );

        // Only `beta` is requested — SqlDumpParser must extract just that
        // table's block, and TableRestorer must never touch alpha/gamma.
        $result = $this->runRestore($dump, tables: [$beta]);

        self::assertSame([$beta], $result->tablesRestored);

        $alphaRow = $db->selectOne("SELECT `value` FROM `{$alpha}` WHERE `id` = 1");
        self::assertSame('original', $alphaRow->value, 'alpha was not requested and must be untouched.');

        $betaRow = $db->selectOne("SELECT `value` FROM `{$beta}` WHERE `id` = 1");
        self::assertSame('restored-beta', $betaRow->value);

        $gammaRow = $db->selectOne("SELECT `value` FROM `{$gamma}` WHERE `id` = 1");
        self::assertSame('original', $gammaRow->value, 'gamma was not requested and must be untouched.');

        // No leftover rollback shadow for the one table that WAS restored.
        $shadow = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ?',
            ['_sbr_' . $beta],
        );
        self::assertSame(0, (int) $shadow->n);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  string[]  $tables
     */
    private function runRestore(string $plaintextDump, bool $encrypt = false, string $key = '', array $tables = []): \Ahmednour\StreamBackup\DTOs\RestoreResult
    {
        $compressed = $this->gzipCompress($plaintextDump);
        $bytes      = $encrypt ? $this->encryptAes256Gcm($compressed, $key) : $compressed;

        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);
        $this->app['config']->set('stream-backup.encryption.driver', $encrypt ? 'openssl-aes-256-gcm' : 'none');
        if ($encrypt) {
            $this->app['config']->set('stream-backup.encryption.key', base64_encode($key));
        }

        $path = $this->writeLocalBackupFile($this->root, 'backup.sql.gz', $bytes);

        $backup = new Backup([
            'id'                => 1,
            'database_name'     => (string) env('STREAM_BACKUP_TEST_DATABASE'),
            'disk'              => 'local_test',
            'path'              => $path,
            'encryption_driver' => $encrypt ? 'openssl-aes-256-gcm' : 'none',
        ]);

        $context = new RestoreContext(
            backupId: 1,
            tables: $tables,
            connectionName: 'mysql_test',
            databaseName: (string) env('STREAM_BACKUP_TEST_DATABASE'),
            disk: 'local_test',
        );

        $pipeline = $this->app->make(RestorePipeline::class);

        return $pipeline->run($context, $backup);
    }

    private function mysqlConfigured(): bool
    {
        return ! empty(env('STREAM_BACKUP_TEST_HOST'))
            && ! empty(env('STREAM_BACKUP_TEST_USER'))
            && ! empty(env('STREAM_BACKUP_TEST_DATABASE'));
    }

    private function skipUnlessMysqlAvailable(): void
    {
        if (! $this->mysqlConfigured()) {
            self::markTestSkipped('STREAM_BACKUP_TEST_HOST/_USER/_DATABASE are not configured for the large-scale restore integration tests.');
        }

        try {
            $this->db()->getPdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('Could not connect to the MySQL test database: ' . $e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    private function db()
    {
        return DB::connection('mysql_test');
    }

    private function dropPrefixedTables(): void
    {
        $db = $this->db();

        $rows = $db->select(
            "SELECT table_name AS name FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name LIKE CONCAT('%', ?, '%')",
            [self::TABLE_PREFIX],
        );

        if ($rows === []) {
            return;
        }

        $db->unprepared('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $row) {
            $name = is_object($row) ? $row->name : $row['name'];
            $db->unprepared("DROP TABLE IF EXISTS `{$name}`");
        }
        $db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
    }
}
