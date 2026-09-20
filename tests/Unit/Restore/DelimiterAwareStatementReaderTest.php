<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Restore\DelimiterAwareStatementReader;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Covers the streaming state machine that replaced TableRestorer's old
 * "line ends with `;`" heuristic. Each test feeds the reader line-by-line
 * (mirroring how TableRestorer::executeBuffer() calls it via fgets()) and
 * asserts on the exact statements it emits.
 */
final class DelimiterAwareStatementReaderTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function readAll(DelimiterAwareStatementReader $reader, string $sql): array
    {
        $statements = [];

        foreach (preg_split('/(?<=\n)/', $sql) as $line) {
            if ($line === '') {
                continue;
            }

            foreach ($reader->feedLine($line) as $statement) {
                $statements[] = $statement;
            }
        }

        $tail = $reader->flush();

        if ($tail !== null) {
            $statements[] = $tail;
        }

        return $statements;
    }

    public function test_ordinary_statements_split_on_the_default_semicolon(): void
    {
        $sql = <<<'SQL'
        DROP TABLE IF EXISTS `foo`;
        CREATE TABLE `foo` (`id` INT);
        INSERT INTO `foo` VALUES (1);
        SQL;

        self::assertSame(
            [
                'DROP TABLE IF EXISTS `foo`',
                'CREATE TABLE `foo` (`id` INT)',
                'INSERT INTO `foo` VALUES (1)',
            ],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_semicolon_inside_a_string_literal_does_not_split_the_statement(): void
    {
        $sql = "INSERT INTO `foo` VALUES (1,'a;b'),(2,'c');\n";

        self::assertSame(
            ["INSERT INTO `foo` VALUES (1,'a;b'),(2,'c')"],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_backslash_escaped_quote_inside_a_string_does_not_end_it_early(): void
    {
        $sql = "INSERT INTO `foo` VALUES ('a\\'b;c');\n";

        self::assertSame(
            ["INSERT INTO `foo` VALUES ('a\\'b;c')"],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_doubled_single_quote_escaping_inside_a_string(): void
    {
        $sql = "INSERT INTO `foo` VALUES ('a''b;c');\n";

        self::assertSame(
            ["INSERT INTO `foo` VALUES ('a''b;c')"],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_doubled_backtick_escaping_inside_an_identifier(): void
    {
        $sql = "SELECT * FROM `weird``table`;\n";

        self::assertSame(
            ['SELECT * FROM `weird``table`'],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_stored_procedure_wrapped_in_a_delimiter_change_restores_as_one_statement(): void
    {
        $sql = <<<'SQL'
        DELIMITER ;;
        CREATE PROCEDURE `myproc`()
        BEGIN
          DECLARE x INT;
          SET x = 1;
          IF x > 0 THEN
            SELECT 1;
          END IF;
        END ;;
        DELIMITER ;
        SELECT 1;
        SQL;

        self::assertSame(
            [
                "CREATE PROCEDURE `myproc`()\nBEGIN\n  DECLARE x INT;\n  SET x = 1;\n  IF x > 0 THEN\n    SELECT 1;\n  END IF;\nEND",
                'SELECT 1',
            ],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_trigger_body_with_a_semicolon_inside_a_comment(): void
    {
        $sql = <<<'SQL'
        DELIMITER $$
        CREATE TRIGGER `trg` BEFORE INSERT ON `foo` FOR EACH ROW
        BEGIN
          -- this comment has a ; inside it
          SET NEW.x = 1;
        END$$
        DELIMITER ;
        SQL;

        self::assertSame(
            ["CREATE TRIGGER `trg` BEFORE INSERT ON `foo` FOR EACH ROW\nBEGIN\n  -- this comment has a ; inside it\n  SET NEW.x = 1;\nEND"],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_delimiter_directive_is_never_emitted_as_a_statement(): void
    {
        $sql = "DELIMITER \$\$\nSELECT 1\$\$\nDELIMITER ;\nSELECT 2;\n";

        $statements = $this->readAll(new DelimiterAwareStatementReader(), $sql);

        self::assertSame(['SELECT 1', 'SELECT 2'], $statements);
        foreach ($statements as $statement) {
            self::assertStringNotContainsStringIgnoringCase('DELIMITER', $statement);
        }
    }

    public function test_delimiter_directive_is_recognised_with_crlf_line_endings(): void
    {
        $sql = "DELIMITER \$\$\r\nSELECT 1\$\$\r\nDELIMITER ;\r\nSELECT 2;\r\n";

        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_decorative_comment_banner_is_dropped_instead_of_prefixing_the_next_statement(): void
    {
        // Regression guard: gluing mysqldump's "--" section banners onto the
        // front of the next statement would defeat StatementFilter's
        // prefix-based LOCK TABLES / UNLOCK TABLES detection.
        $sql = <<<'SQL'
        --
        -- Dumping data for table `foo`
        --

        LOCK TABLES `foo` WRITE;
        INSERT INTO `foo` VALUES (1);
        UNLOCK TABLES;
        SQL;

        self::assertSame(
            [
                'LOCK TABLES `foo` WRITE',
                'INSERT INTO `foo` VALUES (1)',
                'UNLOCK TABLES',
            ],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_versioned_comment_is_kept_verbatim_as_real_sql(): void
    {
        $sql = "/*!40101 SET @saved_cs_client = @@character_set_client */;\nCREATE TABLE `foo` (`id` INT);\n";

        self::assertSame(
            [
                '/*!40101 SET @saved_cs_client = @@character_set_client */',
                'CREATE TABLE `foo` (`id` INT)',
            ],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_trailing_decorative_comment_produces_no_dangling_statement(): void
    {
        $sql = "INSERT INTO `foo` VALUES (1);\n-- Dump completed on 2024-01-01  0:00:00\n";

        self::assertSame(
            ['INSERT INTO `foo` VALUES (1)'],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_flush_returns_null_when_nothing_but_whitespace_and_comments_remain(): void
    {
        $reader = new DelimiterAwareStatementReader();

        foreach (["\n", "-- just a comment\n", "   \n"] as $line) {
            $reader->feedLine($line);
        }

        self::assertNull($reader->flush());
    }

    public function test_double_dash_without_trailing_whitespace_is_not_treated_as_a_comment(): void
    {
        // MySQL only treats "--" as a comment start when followed by
        // whitespace/end-of-line.
        $sql = "SELECT --5;\n";

        self::assertSame(
            ['SELECT --5'],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_multiline_block_comment_hides_a_delimiter_look_alike_character(): void
    {
        $sql = "SELECT /* a ; b\nc ; d */ 1;\n";

        self::assertSame(
            ["SELECT /* a ; b\nc ; d */ 1"],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }

    public function test_multiple_statements_can_end_on_the_same_line(): void
    {
        $sql = "SET a = 1;SET b = 2;\n";

        self::assertSame(
            ['SET a = 1', 'SET b = 2'],
            $this->readAll(new DelimiterAwareStatementReader(), $sql)
        );
    }
}
