<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Performance;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Pipelines\RestorePipeline;
use Ahmednour\StreamBackup\Tests\Support\BuildsRestoreFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Memory regression for restore: the whole RestorePipeline (gzip, parser,
 * restorer) must keep PHP memory flat as one table grows.
 *
 * Drives a SQLite restore because it needs no external service. The gzip
 * backup is written to disk in small pieces, so the test itself never holds
 * the dump in memory. The assertion is deliberately not scaled to the stream
 * size: the small and the large run must fit under the SAME ceiling.
 */
final class LargeRestoreMemoryTest extends TestCase
{
    use BuildsRestoreFixtures;

    private const PEAK_MEMORY_CEILING_BYTES = 24 * 1024 * 1024;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pdo_sqlite', 'zlib'] as $extension) {
            if (! extension_loaded($extension)) {
                self::markTestSkipped("ext-{$extension} is required.");
            }
        }
        if (trim((string) @shell_exec('command -v gzip 2>/dev/null')) === '') {
            self::markTestSkipped('gzip is not available on PATH.');
        }

        $this->root = sys_get_temp_dir() . '/sbr_restore_perf_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        touch($this->root . '/target.sqlite');

        $this->app['config']->set('database.connections.sqlite_perf', [
            'driver'   => 'sqlite',
            'database' => $this->root . '/target.sqlite',
            'prefix'   => '',
        ]);
        $this->app['config']->set('stream-backup.destination.driver', 'local');
        $this->app['config']->set('stream-backup.default_disk', 'local_test');
        $this->app['config']->set('stream-backup.compression.driver', 'gzip');
        $this->app['config']->set('stream-backup.encryption.driver', 'none');
        $this->app['config']->set('filesystems.disks.local_test.root', $this->root);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_perf');
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_sqlite_restore_memory_stays_flat_as_the_table_grows(): void
    {
        $peaks = [];

        foreach ([20_000, 400_000] as $rows) {
            $this->writeDump($rows);

            gc_collect_cycles();
            $before = memory_get_peak_usage(true);

            $result = $this->restore();

            $peaks[$rows] = max(0, memory_get_peak_usage(true) - $before);

            self::assertSame($rows, $result->totalRowsAffected);
            self::assertSame($rows, DB::connection('sqlite_perf')->table('sbr_perf')->count());
        }

        foreach ($peaks as $rows => $peak) {
            self::assertLessThan(
                self::PEAK_MEMORY_CEILING_BYTES,
                $peak,
                sprintf('Restoring %d rows grew PHP peak memory by %.1f MB; restore must not buffer the table.', $rows, $peak / 1048576),
            );
        }
    }

    private function writeDump(int $rows): void
    {
        $gz = gzopen($this->root . '/backup.sql.gz', 'wb1');
        gzwrite($gz, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE sbr_perf (id INTEGER PRIMARY KEY, payload TEXT);\n");

        $piece = '';
        for ($i = 1; $i <= $rows; $i++) {
            $piece .= "INSERT INTO sbr_perf VALUES({$i},'" . md5((string) $i) . str_repeat('x', 64) . "');\n";
            if ($i % 5000 === 0) {
                gzwrite($gz, $piece);
                $piece = '';
            }
        }
        gzwrite($gz, $piece . "COMMIT;\n");
        gzclose($gz);
    }

    private function restore(): \Ahmednour\StreamBackup\DTOs\RestoreResult
    {
        $backup = new Backup([
            'id'                => 1,
            'database_name'     => 'irrelevant',
            'disk'              => 'local_test',
            'path'              => 'backup.sql.gz',
            'encryption_driver' => 'none',
            'dump_driver'       => 'sqlite3',
        ]);

        $context = new RestoreContext(
            backupId: 1,
            tables: [],
            connectionName: 'sqlite_perf',
            databaseName: 'irrelevant',
            disk: 'local_test',
        );

        return $this->app->make(RestorePipeline::class)->run($context, $backup);
    }
}
