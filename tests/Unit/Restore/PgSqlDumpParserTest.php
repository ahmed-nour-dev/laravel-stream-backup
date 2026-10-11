<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Exceptions\TableNotFoundException;
use Ahmednour\StreamBackup\Restore\PgSqlDumpParser;
use Ahmednour\StreamBackup\Tests\Support\BuildsPgDumpFixtures;
use Ahmednour\StreamBackup\Tests\TestCase;

class PgSqlDumpParserTest extends TestCase
{
    use BuildsPgDumpFixtures;

    /**
     * @param  string[] $tables
     * @return array<string, string>
     */
    private function parse(string $dump, array $tables = [], int $chunk = 4096): array
    {
        $parser = new PgSqlDumpParser($tables);
        foreach (str_split($dump, $chunk) as $piece) {
            $parser->feed($piece);
        }

        return array_map(static fn ($stream) => (string) stream_get_contents($stream), $parser->finish());
    }

    public function test_splits_a_full_dump_into_one_block_per_table(): void
    {
        $blocks = $this->parse($this->pgDump());

        $this->assertSame(['sbr_pg_users', 'sbr_pg_posts'], array_keys($blocks));
    }

    public function test_block_is_ordered_pre_data_then_data_then_post_data(): void
    {
        $users = $this->parse($this->pgDump())['sbr_pg_users'];

        $create  = strpos($users, 'CREATE TABLE public.sbr_pg_users');
        $seq     = strpos($users, 'CREATE SEQUENCE public.sbr_pg_users_id_seq');
        $default = strpos($users, 'SET DEFAULT nextval');
        $copy    = strpos($users, 'COPY public.sbr_pg_users');
        $setval  = strpos($users, 'setval');
        $marker  = strpos($users, PgSqlDumpParser::POST_DATA_MARKER);
        $pkey    = strpos($users, 'sbr_pg_users_pkey');

        foreach ([$create, $seq, $default, $copy, $setval, $marker, $pkey] as $offset) {
            $this->assertNotFalse($offset);
        }
        $this->assertTrue($create < $seq && $seq < $default && $default < $copy && $copy < $setval && $setval < $marker && $marker < $pkey);
    }

    public function test_keeps_only_encoding_preamble_sets(): void
    {
        $users = $this->parse($this->pgDump())['sbr_pg_users'];

        $this->assertStringContainsString("SET client_encoding = 'UTF8';", $users);
        $this->assertStringContainsString('SET standard_conforming_strings = on;', $users);
        $this->assertStringNotContainsString('statement_timeout', $users);
        $this->assertStringNotContainsString('set_config', $users);
    }

    public function test_foreign_key_belongs_to_the_child_after_the_foreign_keys_marker(): void
    {
        $blocks = $this->parse($this->pgDump());

        $posts = $blocks['sbr_pg_posts'];
        $this->assertGreaterThan(
            strpos($posts, PgSqlDumpParser::FOREIGN_KEYS_MARKER),
            strpos($posts, 'sbr_pg_posts_user_fk'),
        );
        $this->assertGreaterThan(
            strpos($posts, PgSqlDumpParser::POST_DATA_MARKER),
            strpos($posts, PgSqlDumpParser::FOREIGN_KEYS_MARKER),
        );
        $this->assertLessThan(
            strpos($posts, PgSqlDumpParser::FOREIGN_KEYS_MARKER),
            strpos($posts, 'sbr_pg_posts_pkey'),
        );
        $this->assertStringContainsString('sbr_pg_posts_user_idx', $posts);
        $this->assertStringNotContainsString('sbr_pg_posts_user_fk', $blocks['sbr_pg_users']);
    }

    public function test_copy_data_that_looks_like_a_header_stays_data(): void
    {
        $users = $this->parse($this->pgDump())['sbr_pg_users'];

        $this->assertStringContainsString("3\t-- Name: not_a_header; Type: TABLE;", $users);
        $this->assertStringNotContainsString('not_a_header', $this->parse($this->pgDump())['sbr_pg_posts']);
    }

    public function test_foreign_key_edges_are_parent_then_child(): void
    {
        $parser = new PgSqlDumpParser();
        $parser->feed($this->pgDump());
        $parser->finish();

        $this->assertSame([['sbr_pg_users', 'sbr_pg_posts']], $parser->getForeignKeys());
    }

    public function test_selective_restore_keeps_requested_tables_and_still_reports_all_edges(): void
    {
        $parser = new PgSqlDumpParser(['public.sbr_pg_users']);
        $parser->feed($this->pgDump());
        $blocks = $parser->finish();

        $this->assertSame(['sbr_pg_users'], array_keys($blocks));
        $this->assertSame([['sbr_pg_users', 'sbr_pg_posts']], $parser->getForeignKeys());
    }

    public function test_missing_requested_table_throws(): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->parse($this->pgDump(), ['nope']);
    }

    public function test_non_dump_input_throws(): void
    {
        $this->expectException(InvalidBackupException::class);

        $this->parse("SELECT 1;\n");
    }

    public function test_parsing_is_independent_of_chunk_boundaries(): void
    {
        $this->assertSame($this->parse($this->pgDump()), $this->parse($this->pgDump(), [], 7));
    }

    public function test_non_public_schema_tables_are_keyed_with_their_schema(): void
    {
        $extra = <<<'SQL'

--
-- Name: sbr_pg_logs; Type: TABLE; Schema: audit; Owner: postgres
--

CREATE TABLE audit.sbr_pg_logs (
    id integer NOT NULL
);

--
-- Data for Name: sbr_pg_logs; Type: TABLE DATA; Schema: audit; Owner: postgres
--

COPY audit.sbr_pg_logs (id) FROM stdin;
1
\.

--
-- Name: sbr_pg_logs sbr_pg_logs_pkey; Type: CONSTRAINT; Schema: audit; Owner: postgres
--

ALTER TABLE ONLY audit.sbr_pg_logs
    ADD CONSTRAINT sbr_pg_logs_pkey PRIMARY KEY (id);

SQL;

        $blocks = $this->parse($this->pgDump($extra));

        $this->assertArrayHasKey('audit.sbr_pg_logs', $blocks);
        $this->assertStringContainsString('sbr_pg_logs_pkey', $blocks['audit.sbr_pg_logs']);
    }

    public function test_identity_column_sequence_setval_follows_its_table(): void
    {
        $dump = <<<'SQL'
SET client_encoding = 'UTF8';

--
-- Name: sbr_pg_ident; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sbr_pg_ident (
    id integer NOT NULL
);

ALTER TABLE public.sbr_pg_ident ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.sbr_pg_ident_id_seq
    START WITH 1
);

--
-- Name: sbr_pg_ident_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.sbr_pg_ident_id_seq', 9, true);

SQL;

        $this->assertStringContainsString("setval('public.sbr_pg_ident_id_seq', 9, true)", $this->parse($dump)['sbr_pg_ident']);
    }

    public function test_pg_dump_16_identity_block_follows_its_table(): void
    {
        $dump = <<<'SQL'
SET client_encoding = 'UTF8';

--
-- Name: audits; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.audits (
    id bigint NOT NULL,
    payload json
);

--
-- Name: audits_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

ALTER TABLE public.audits ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.audits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);

--
-- Data for Name: audits; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.audits (id, payload) FROM stdin;
1	{"a":1}
\.

--
-- Name: audits_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.audits_id_seq', 2, true);

SQL;

        foreach ([[], ['audits']] as $tables) {
            $sql = $this->parse($dump, $tables)['audits'];
            $create = strpos($sql, 'CREATE TABLE public.audits');
            $identity = strpos($sql, 'ADD GENERATED ALWAYS AS IDENTITY');
            $copy = strpos($sql, 'COPY public.audits');
            $setval = strpos($sql, "setval('public.audits_id_seq', 2, true)");

            $this->assertNotFalse($identity);
            $this->assertNotFalse($setval);
            $this->assertTrue($create < $identity && $identity < $copy && $copy < $setval);
            $this->assertStringContainsString('CACHE 1', $sql);
        }
    }
}
