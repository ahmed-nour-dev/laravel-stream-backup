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
 *
 * Also covers SqlDumpParser::getForeignKeys() — the half of the FK-dependency-
 * ordering fix that lives in the parser: extracting FK [parent, child] edges
 * directly out of each CREATE TABLE statement as it is captured, so a table
 * newly introduced by the backup (which has no information_schema constraint
 * row on the target yet) still contributes an edge for TableRestorer's
 * dependency sort. See TableRestorer's class docblock ("FK CAVEAT +
 * DEPENDENCY ORDERING") for the full picture.
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

    public function test_extracts_fk_edge_from_a_create_table_statement(): void
    {
        $parser = new SqlDumpParser([]);
        $parser->feed($this->dump([
            'customers' => "CREATE TABLE `customers` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB;\n",
            'orders' => "CREATE TABLE `orders` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `customer_id` INT UNSIGNED NOT NULL,\n"
                . "  PRIMARY KEY (`id`),\n"
                . "  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)\n"
                . ") ENGINE=InnoDB;\n",
        ]));

        $parser->finish();

        self::assertSame([['customers', 'orders']], $parser->getForeignKeys());
    }

    public function test_returns_no_edges_when_no_table_declares_a_foreign_key(): void
    {
        $parser = new SqlDumpParser([]);
        $parser->feed($this->dump([
            'widgets' => "CREATE TABLE `widgets` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB;\n",
        ]));

        $parser->finish();

        self::assertSame([], $parser->getForeignKeys());
    }

    public function test_extracts_multiple_fk_edges_across_tables(): void
    {
        $parser = new SqlDumpParser([]);
        $parser->feed($this->dump([
            'widgets' => "CREATE TABLE `widgets` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB;\n",
            'accessories' => "CREATE TABLE `accessories` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `widget_id` INT UNSIGNED NOT NULL,\n"
                . "  PRIMARY KEY (`id`),\n"
                . "  CONSTRAINT `fk_accessories_widget` FOREIGN KEY (`widget_id`) REFERENCES `widgets` (`id`)\n"
                . ") ENGINE=InnoDB;\n",
            'reviews' => "CREATE TABLE `reviews` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `widget_id` INT UNSIGNED NOT NULL,\n"
                . "  PRIMARY KEY (`id`),\n"
                . "  CONSTRAINT `fk_reviews_widget` FOREIGN KEY (`widget_id`) REFERENCES `widgets` (`id`)\n"
                . ") ENGINE=InnoDB;\n",
        ]));

        $parser->finish();

        self::assertSame(
            [['widgets', 'accessories'], ['widgets', 'reviews']],
            $parser->getForeignKeys(),
        );
    }

    public function test_edges_are_scoped_to_a_single_parser_instance(): void
    {
        $withFk = new SqlDumpParser([]);
        $withFk->feed($this->dump([
            'orders' => "CREATE TABLE `orders` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `customer_id` INT UNSIGNED NOT NULL,\n"
                . "  PRIMARY KEY (`id`),\n"
                . "  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)\n"
                . ") ENGINE=InnoDB;\n",
        ]));
        $withFk->finish();
        self::assertNotSame([], $withFk->getForeignKeys());

        $withoutFk = new SqlDumpParser([]);
        $withoutFk->feed($this->dump([
            'widgets' => "CREATE TABLE `widgets` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB;\n",
        ]));
        $withoutFk->finish();
        self::assertSame([], $withoutFk->getForeignKeys());
    }

    public function test_fk_edges_are_captured_even_for_a_table_excluded_from_a_selective_restore(): void
    {
        // Selective restore requesting ONLY `customers`; `orders` (which
        // declares the FK) is skipped entirely — never buffered. The edge
        // must still surface: TableRestorer's cross-boundary safety guard
        // (Ahmednour/laravel-stream-backup#19) needs it to detect that the
        // requested `customers` table is an FK parent of an unrequested live
        // table, purely from the dump text, with no information_schema row
        // required.
        $parser = new SqlDumpParser(['customers']);
        $parser->feed($this->dump([
            'customers' => "CREATE TABLE `customers` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB;\n",
            'orders' => "CREATE TABLE `orders` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `customer_id` INT UNSIGNED NOT NULL,\n"
                . "  PRIMARY KEY (`id`),\n"
                . "  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)\n"
                . ") ENGINE=InnoDB;\n",
        ]));

        $blocks = $parser->finish();

        self::assertSame(['customers'], array_keys($blocks), 'orders must not be buffered — it was not requested.');
        self::assertSame([['customers', 'orders']], $parser->getForeignKeys());
    }

    /**
     * Builds a minimal mysqldump-shaped string: a "Table structure" marker
     * followed by the given CREATE TABLE body, for each table.
     *
     * @param array<string, string> $tables table_name => CREATE TABLE body
     */
    private function dump(array $tables): string
    {
        $sql = '';
        foreach ($tables as $name => $createTableBody) {
            $sql .= "-- Table structure for table `{$name}`\n" . $createTableBody;
        }

        return $sql;
    }
}
