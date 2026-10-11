<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Restore\PgSqlStatementReader;
use PHPUnit\Framework\TestCase;

class PgSqlStatementReaderTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function read(string $sql): array
    {
        $reader = new PgSqlStatementReader();
        $out    = [];
        foreach (preg_split('/(?<=\n)/', $sql, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            array_push($out, ...$reader->push($line));
        }

        return array_merge($out, $reader->finalize());
    }

    public function test_splits_on_semicolons_and_joins_multiline_statements(): void
    {
        $this->assertSame(
            ["CREATE TABLE t (\n    id integer\n)", 'SELECT 1'],
            $this->read("CREATE TABLE t (\n    id integer\n);\nSELECT 1;\n"),
        );
    }

    public function test_semicolons_inside_strings_and_identifiers_do_not_split(): void
    {
        $this->assertSame(
            ["COMMENT ON TABLE t IS 'a; b ''c;'''", 'SELECT "we;ird"'],
            $this->read("COMMENT ON TABLE t IS 'a; b ''c;''';\nSELECT \"we;ird\";\n"),
        );
    }

    public function test_escape_strings_honour_backslash_quotes(): void
    {
        $this->assertSame(
            ["SELECT E'it\\'s; fine'", 'SELECT 2'],
            $this->read("SELECT E'it\\'s; fine';\nSELECT 2;\n"),
        );
    }

    public function test_dollar_quoted_bodies_keep_their_semicolons(): void
    {
        $sql = "CREATE FUNCTION f() RETURNS int AS \$body\$\nBEGIN\n  RETURN 1;\nEND;\n\$body\$ LANGUAGE plpgsql;\nSELECT 3;\n";

        $statements = $this->read($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('RETURN 1;', $statements[0]);
        $this->assertSame('SELECT 3', $statements[1]);
    }

    public function test_comments_are_ignored(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->read("-- leading; comment\nSELECT 1; -- trailing; comment\n/* block; */ SELECT 2;\n"),
        );
    }

    public function test_is_idle_between_statements_only(): void
    {
        $reader = new PgSqlStatementReader();
        $this->assertTrue($reader->isIdle());

        $reader->push("CREATE TABLE t (\n");
        $this->assertFalse($reader->isIdle());

        $reader->push(");\n");
        $this->assertTrue($reader->isIdle());
    }
}
