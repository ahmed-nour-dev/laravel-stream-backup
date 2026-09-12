<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Restore\SqlDumpParser;
use Ahmednour\StreamBackup\Tests\Support\BuildsRestoreFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Measurable memory/temp-storage assertions for the streaming restore
 * parser — the part of the "large-scale restore" acceptance criteria that a
 * row-count assertion alone can't prove: that a single large table's dump
 * text is never fully resident in PHP memory, no matter how it's fed.
 *
 * SqlDumpParser buffers each table into a `php://temp/maxmemory:2MB` stream
 * (SqlDumpParser::TEMP_MAX_MEMORY), which PHP transparently spills to a real
 * OS temp file once that threshold is exceeded — the userland process never
 * holds the overflow. This test feeds a single table's dump text (~15 MB,
 * well past the 2 MB threshold) through feed() in 64 KB chunks — the same
 * chunk size RestorePipeline uses from its decompressor pipe — and asserts
 * the PHP memory footprint stays a small fraction of the data volume.
 *
 * Directly exercises the acceptance criterion: "Tests prove that restore
 * does not require database-sized temporary storage."
 */
final class SqlDumpParserBoundedMemoryTest extends TestCase
{
    use BuildsRestoreFixtures;

    private const ROW_COUNT      = 300_000;
    private const FEED_CHUNK     = 64 * 1024; // matches stream-backup.read_chunk default
    private const MAX_MEMORY_DELTA = 8 * 1024 * 1024; // 8 MB ceiling

    public function test_feeding_a_large_single_table_dump_keeps_php_memory_bounded(): void
    {
        $dump = $this->mysqldumpTableBlock(
            'sbr_bigtable',
            "CREATE TABLE `sbr_bigtable` (\n"
            . "  `id` INT UNSIGNED NOT NULL,\n"
            . "  `payload` VARCHAR(64) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;",
            $this->generateRowTuples(self::ROW_COUNT),
        );

        $dumpSize = strlen($dump);
        self::assertGreaterThan(
            10 * 1024 * 1024,
            $dumpSize,
            'Fixture must be well past the 2 MB spill threshold to prove anything.',
        );

        gc_collect_cycles();
        $baselinePeak = memory_get_peak_usage(true);

        $parser = new SqlDumpParser(['sbr_bigtable']);

        foreach (str_split($dump, self::FEED_CHUNK) as $chunk) {
            $parser->feed($chunk);
        }

        $blocks = $parser->finish();

        $peakDelta = memory_get_peak_usage(true) - $baselinePeak;

        self::assertLessThan(
            self::MAX_MEMORY_DELTA,
            $peakDelta,
            sprintf(
                'Parsing a %.1f MB single-table dump increased peak PHP memory by %.1f MB; '
                . 'expected it to stay under %.1f MB, proving the table buffer spills to disk '
                . 'instead of accumulating in process memory.',
                $dumpSize / 1024 / 1024,
                $peakDelta / 1024 / 1024,
                self::MAX_MEMORY_DELTA / 1024 / 1024,
            ),
        );

        // Correctness survived the spill-to-disk round trip: not just "small
        // memory", but the actual data made it through intact.
        self::assertArrayHasKey('sbr_bigtable', $blocks);

        $insertLineCount = 0;
        $sawFirstRow      = false;
        $sawLastRow       = false;
        while (($line = fgets($blocks['sbr_bigtable'])) !== false) {
            if (str_starts_with($line, 'INSERT INTO')) {
                $insertLineCount++;
                $sawFirstRow = $sawFirstRow || str_contains($line, "(1,'");
                $sawLastRow  = $sawLastRow || str_contains($line, '(' . self::ROW_COUNT . ",'");
            }
        }

        self::assertSame((int) ceil(self::ROW_COUNT / 500), $insertLineCount);
        self::assertTrue($sawFirstRow, 'First row (id=1) must survive the spill-to-disk round trip.');
        self::assertTrue($sawLastRow, 'Last row (id=' . self::ROW_COUNT . ') must survive the spill-to-disk round trip.');

        fclose($blocks['sbr_bigtable']);
    }
}
