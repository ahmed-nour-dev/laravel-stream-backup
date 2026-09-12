<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Exceptions\TableNotFoundException;
use Ahmednour\StreamBackup\Restore\SqlDumpParser;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Covers the incremental feed()/finish() API that replaced the old
 * whole-stream parse(): the parser must reconstruct the same table blocks
 * regardless of how the input is chunked, including splits that land
 * mid-line or mid-marker — exactly what happens when data arrives from a
 * live decompressor pipe instead of a single pre-buffered stream.
 */
final class SqlDumpParserTest extends TestCase
{
    private const DUMP = <<<'SQL'
-- Table structure for table `customers`
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (`id` INT);
-- Dumping data for table `customers`
INSERT INTO `customers` VALUES (1);
-- Table structure for table `orders`
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (`id` INT);
-- Dumping data for table `orders`
INSERT INTO `orders` VALUES (1);

SQL;

    public function test_feed_in_a_single_chunk_extracts_all_tables(): void
    {
        $parser = new SqlDumpParser([]);
        $parser->feed(self::DUMP);
        $blocks = $parser->finish();

        self::assertSame(['customers', 'orders'], array_keys($blocks));
        self::assertStringContainsString("INSERT INTO `customers` VALUES (1);", stream_get_contents($blocks['customers']));
        self::assertStringContainsString("INSERT INTO `orders` VALUES (1);", stream_get_contents($blocks['orders']));
    }

    public function test_feed_split_into_arbitrary_byte_sized_chunks_matches_single_chunk_result(): void
    {
        $parser = new SqlDumpParser([]);

        // 7-byte chunks guarantee splits land mid-line and mid-marker, the
        // way a live decompressor pipe would deliver data.
        foreach (str_split(self::DUMP, 7) as $piece) {
            $parser->feed($piece);
        }

        $blocks = $parser->finish();

        self::assertSame(['customers', 'orders'], array_keys($blocks));
        self::assertSame(
            "-- Table structure for table `customers`\n"
            . "DROP TABLE IF EXISTS `customers`;\n"
            . "CREATE TABLE `customers` (`id` INT);\n"
            . "-- Dumping data for table `customers`\n"
            . "INSERT INTO `customers` VALUES (1);\n",
            stream_get_contents($blocks['customers'])
        );
    }

    public function test_only_requested_tables_are_buffered(): void
    {
        $parser = new SqlDumpParser(['orders']);
        $parser->feed(self::DUMP);
        $blocks = $parser->finish();

        self::assertSame(['orders'], array_keys($blocks));
    }

    public function test_missing_requested_table_throws(): void
    {
        $parser = new SqlDumpParser(['does_not_exist']);
        $parser->feed(self::DUMP);

        $this->expectException(TableNotFoundException::class);
        $parser->finish();
    }

    public function test_no_table_markers_throws_invalid_backup(): void
    {
        $parser = new SqlDumpParser([]);
        $parser->feed("SELECT 1;\n");

        $this->expectException(InvalidBackupException::class);
        $parser->finish();
    }

    public function test_trailing_line_without_newline_is_still_captured_on_finish(): void
    {
        $parser = new SqlDumpParser([]);
        $parser->feed("-- Table structure for table `customers`\nCREATE TABLE `customers` (`id` INT);");
        $blocks = $parser->finish();

        self::assertStringContainsString('CREATE TABLE `customers`', stream_get_contents($blocks['customers']));
    }
}
