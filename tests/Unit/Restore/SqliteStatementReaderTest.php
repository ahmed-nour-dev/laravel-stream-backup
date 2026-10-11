<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Restore\SqliteStatementReader;
use PHPUnit\Framework\TestCase;

class SqliteStatementReaderTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function read(string $sql, int $chunk = 4096): array
    {
        $reader = new SqliteStatementReader();
        $out    = [];
        foreach (str_split($sql, $chunk) as $piece) {
            array_push($out, ...$reader->feed($piece));
        }

        return array_merge($out, $reader->finalize());
    }

    public function test_splits_statements_and_keeps_multiline_ones_together(): void
    {
        $this->assertSame(
            ["CREATE TABLE t (\n  id INTEGER\n)", 'INSERT INTO t VALUES(1)'],
            $this->read("CREATE TABLE t (\n  id INTEGER\n);\nINSERT INTO t VALUES(1);\n"),
        );
    }

    public function test_semicolons_and_comment_markers_inside_quotes_are_data(): void
    {
        $sql = "INSERT INTO t VALUES('a; b -- c /* d */ ''e;'''); INSERT INTO t VALUES(\"x;y\");";

        $this->assertSame([
            "INSERT INTO t VALUES('a; b -- c /* d */ ''e;''')",
            'INSERT INTO t VALUES("x;y")',
        ], $this->read($sql));
    }

    public function test_bracket_and_backtick_identifiers_do_not_split(): void
    {
        $this->assertSame(
            ['CREATE TABLE [a;b] (`c;d` INT)'],
            $this->read('CREATE TABLE [a;b] (`c;d` INT);'),
        );
    }

    public function test_comments_are_dropped(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            array_map(static fn (string $s): string => preg_replace('/\s+/', ' ', $s), $this->read("-- lead; x\nSELECT 1; -- trail; y\n/* block; */ SELECT 2;\n")),
        );
    }

    public function test_trigger_body_is_one_statement_even_with_case_expressions(): void
    {
        $sql = "CREATE TRIGGER trg AFTER INSERT ON t\nBEGIN\n  UPDATE t SET a = CASE WHEN 1 THEN 2 END;\n  DELETE FROM t WHERE 0;\nEND;\nSELECT 9;\n";

        $statements = $this->read($sql);

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('CREATE TRIGGER trg', $statements[0]);
        $this->assertStringEndsWith('END', $statements[0]);
        $this->assertStringContainsString('DELETE FROM t WHERE 0;', $statements[0]);
        $this->assertSame('SELECT 9', $statements[1]);
    }

    public function test_result_does_not_depend_on_chunk_boundaries(): void
    {
        $sql = "CREATE TRIGGER trg AFTER INSERT ON t BEGIN SELECT 'a;--b'; END;\n-- c\nINSERT INTO t VALUES('x''y;'); /* z */ SELECT 1;\n";

        $this->assertSame($this->read($sql), $this->read($sql, 1));
        $this->assertSame($this->read($sql), $this->read($sql, 3));
    }
}
