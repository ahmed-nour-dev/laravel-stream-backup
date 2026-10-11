<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Exceptions\TableNotFoundException;
use Ahmednour\StreamBackup\Restore\SqliteDumpParser;
use Ahmednour\StreamBackup\Tests\Support\BuildsSqliteDumpFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;

class SqliteDumpParserTest extends TestCase
{
    use BuildsSqliteDumpFixtures;

    /**
     * Decode a parser stream into [kind, pre-data statements, post-data statements].
     *
     * @param  resource $stream
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    private function decode($stream): array
    {
        $kind = trim((string) fgets($stream));
        $pre  = [];
        $post = [];
        $into = &$pre;

        while (($header = fgets($stream)) !== false) {
            $header = rtrim($header, "\n");
            if ($header === 'M') {
                $into = &$post;
                continue;
            }
            $length = (int) substr($header, 1);
            $into[] = (string) fread($stream, $length);
            fgets($stream);
        }

        return [$kind, $pre, $post];
    }

    /**
     * @param  string[] $tables
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    private function parse(array $tables = [], int $chunk = 4096): array
    {
        $parser = new SqliteDumpParser($tables);
        foreach (str_split($this->sqliteDump(), $chunk) as $piece) {
            $parser->feed($piece);
        }

        return array_map([$this, 'decode'], $parser->finish());
    }

    public function test_splits_the_dump_into_tables_and_views(): void
    {
        $blocks = $this->parse();

        $this->assertSame(['sbr_users', 'sbr_posts', 'sbr_post_view'], array_keys($blocks));
        $this->assertSame('table', $blocks['sbr_users'][0]);
        $this->assertSame('view', $blocks['sbr_post_view'][0]);
    }

    public function test_table_block_has_create_then_rows_with_literals_intact(): void
    {
        [, $pre] = $this->parse()['sbr_users'];

        $this->assertCount(4, $pre); // CREATE + 3 INSERTs
        $this->assertStringStartsWith('CREATE TABLE sbr_users', $pre[0]);
        $this->assertSame("INSERT INTO sbr_users VALUES(5,'it''s \"quoted\"','multi\nline')", $pre[3]);
    }

    public function test_indexes_triggers_and_sequence_values_go_after_the_marker(): void
    {
        $blocks = $this->parse();

        [, , $usersPost] = $blocks['sbr_users'];
        $this->assertCount(3, $usersPost);
        $this->assertSame("DELETE FROM sqlite_sequence WHERE name = 'sbr_users'", $usersPost[0]);
        $this->assertSame('INSERT INTO "sqlite_sequence" VALUES(\'sbr_users\',40)', $usersPost[1]);
        $this->assertStringStartsWith('CREATE TRIGGER sbr_users_touch', $usersPost[2]);
        $this->assertStringEndsWith('END', $usersPost[2]);

        [, , $postsPost] = $blocks['sbr_posts'];
        $this->assertSame(['CREATE INDEX sbr_posts_user_idx ON sbr_posts(user_id)'], $postsPost);
    }

    public function test_pragma_transaction_and_sequence_wipe_statements_are_dropped(): void
    {
        foreach ($this->parse() as [, $pre, $post]) {
            foreach (array_merge($pre, $post) as $statement) {
                $this->assertDoesNotMatchRegularExpression('/^(PRAGMA|BEGIN|COMMIT)/i', $statement);
                $this->assertNotSame('DELETE FROM "sqlite_sequence"', $statement);
            }
        }
    }

    public function test_foreign_key_edges_are_parent_then_child(): void
    {
        $parser = new SqliteDumpParser(['sbr_users']);
        $parser->feed($this->sqliteDump());
        $blocks = $parser->finish();

        $this->assertSame(['sbr_users'], array_keys($blocks));
        $this->assertSame([['sbr_users', 'sbr_posts']], $parser->getForeignKeys());
    }

    public function test_selective_request_is_case_insensitive(): void
    {
        $this->assertSame(['sbr_posts'], array_keys($this->parse(['SBR_Posts'])));
    }

    public function test_missing_requested_table_throws(): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->parse(['nope']);
    }

    public function test_non_dump_input_throws(): void
    {
        $parser = new SqliteDumpParser();
        $parser->feed("PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCOMMIT;\n");

        $this->expectException(InvalidBackupException::class);
        $parser->finish();
    }

    public function test_parsing_is_independent_of_chunk_boundaries(): void
    {
        $this->assertSame($this->parse(), $this->parse([], 5));
    }
}
