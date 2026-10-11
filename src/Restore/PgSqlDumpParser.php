<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Contracts\DumpParser;
use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Exceptions\TableNotFoundException;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Streaming parser for plain-format `pg_dump` output (`-F p`).
 *
 * pg_dump scatters one table's objects across the file: CREATE TABLE and
 * sequences first, then COPY data, then constraints, indexes and foreign keys
 * after all the data. This parser routes every statement to the table it
 * belongs to in a single pass, then assembles one stream per table:
 *
 *   preamble SETs
 *   CREATE TABLE
 *   owned sequences (CREATE SEQUENCE)
 *   OWNED BY, column defaults, COPY data, sequence setval   (stream order)
 *   -- @@sbr:post-data@@
 *   primary/unique/check constraints, indexes, triggers, comments
 *   -- @@sbr:foreign-keys@@
 *   foreign keys
 *
 * PgSqlRestorer replays each section for every table before moving on to the
 * next, so cyclic foreign keys never need dependency ordering and a foreign
 * key never runs before its parent's key exists.
 *
 * Memory is bounded the same way as SqlDumpParser: each table's data goes to a
 * php://temp stream that spills to disk past TEMP_MAX_MEMORY. Only DDL text
 * (CREATE TABLE, CREATE SEQUENCE) is held in memory.
 *
 * Restored: tables, their data, owned sequences, defaults, constraints,
 * indexes, triggers and table/column comments. Not restored: functions,
 * types, views, extensions, ACLs and ownership.
 */
final class PgSqlDumpParser implements DumpParser
{
    public const POST_DATA_MARKER = '-- @@sbr:post-data@@';

    public const FOREIGN_KEYS_MARKER = '-- @@sbr:foreign-keys@@';

    private const TEMP_MAX_MEMORY = 2 * 1024 * 1024;

    private const HEADER_PATTERN = '/^-- (?:Data for )?Name: (.*); Type: ([A-Z][A-Z ]*); Schema: ([^;]*); Owner: .*$/';

    /** Block types whose statements belong in the pre-data part. */
    private const DEFAULT_TYPES = ['DEFAULT'];

    /** Block types attributed to a table via the statement text, run after all data. */
    private const POST_TYPES = [
        'CONSTRAINT', 'FK CONSTRAINT', 'CHECK CONSTRAINT', 'INDEX', 'TRIGGER',
        'COMMENT', 'TABLE ATTACH', 'INDEX ATTACH', 'POLICY', 'ROW SECURITY',
    ];

    /** Preamble statements worth replaying: they affect how row data is read. */
    private const PREAMBLE_PATTERN = '/^SET (client_encoding|standard_conforming_strings) = .*;$/';

    private bool $selectAll;

    /** @var array<string, int> */
    private array $requestedLookup;

    private string $pending = '';

    private bool $finished = false;

    private bool $foundAnyTable = false;

    private bool $inCopy = false;

    /** @var list<string> */
    private array $preamble = [];

    /** @var array{type: string, name: string, schema: string, table: ?string, resolved: bool}|null */
    private ?array $block = null;

    /** Table whose CREATE TABLE is being read, for FK edge collection. */
    private ?string $createTable = null;

    /** @var list<string> Lines of the current block held until its table is known. */
    private array $held = [];

    /** @var array<string, true> Retained tables, in dump order. */
    private array $tables = [];

    /** @var array<string, string> table => CREATE TABLE text */
    private array $pre = [];

    /** @var array<string, resource> table => statements and data, in stream order */
    private array $rest = [];

    /** @var array<string, resource> table => constraints, indexes, ... */
    private array $post = [];

    /** @var array<string, resource> table => foreign keys */
    private array $fk = [];

    /** @var array<string, string> qualified sequence name => CREATE SEQUENCE text */
    private array $sequenceSql = [];

    /** @var array<string, string> qualified sequence name => owning table key */
    private array $sequenceOwner = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $foreignKeys = [];

    /**
     * @param string[] $tables Tables to extract (empty = all). `public.` is optional.
     */
    public function __construct(array $tables = [])
    {
        $this->selectAll = $tables === [];
        $this->requestedLookup = array_flip(array_map([PgSqlIdentifier::class, 'normaliseRequested'], $tables));
    }

    public function feed(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->pending .= $chunk;

        while (($pos = strpos($this->pending, "\n")) !== false) {
            $line = substr($this->pending, 0, $pos + 1);
            $this->pending = substr($this->pending, $pos + 1);
            $this->processLine($line);
        }
    }

    /**
     * @return array<string, resource> table key => rewound stream
     *
     * @throws InvalidBackupException If the input is not pg_dump plain output.
     * @throws TableNotFoundException If a requested table is missing.
     */
    public function finish(): array
    {
        if ($this->finished) {
            throw new LogicException('PgSqlDumpParser::finish() has already been called.');
        }
        $this->finished = true;

        if ($this->pending !== '') {
            $this->processLine($this->pending);
            $this->pending = '';
        }
        $this->flushHeld();

        if (! $this->foundAnyTable) {
            $this->closeAll();

            throw new InvalidBackupException(
                'No table markers found in the backup file. '
                . 'The file may be corrupt or not a valid plain-format pg_dump output.'
            );
        }

        if (! $this->selectAll) {
            $missing = array_diff(array_keys($this->requestedLookup), array_keys($this->tables));

            if ($missing !== []) {
                $this->closeAll();

                throw new TableNotFoundException(sprintf(
                    'The following tables were not found in the backup: %s',
                    implode(', ', array_map(fn (string $t) => "`{$t}`", $missing)),
                ));
            }
        }

        $sequencesByTable = [];
        foreach ($this->sequenceOwner as $sequence => $table) {
            if (isset($this->sequenceSql[$sequence])) {
                $sequencesByTable[$table][] = $this->sequenceSql[$sequence];
            }
        }

        $out = [];
        foreach (array_keys($this->tables) as $table) {
            $stream = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');

            fwrite($stream, implode("\n", $this->preamble) . "\n");
            fwrite($stream, ($this->pre[$table] ?? '') . "\n");
            foreach ($sequencesByTable[$table] ?? [] as $sql) {
                fwrite($stream, $sql . "\n");
            }
            if (isset($this->rest[$table])) {
                rewind($this->rest[$table]);
                stream_copy_to_stream($this->rest[$table], $stream);
                fclose($this->rest[$table]);
            }
            // Both markers are always written once any later section exists,
            // so a stage never has to guess which section it is in.
            if (isset($this->post[$table]) || isset($this->fk[$table])) {
                fwrite($stream, "\n" . self::POST_DATA_MARKER . "\n");
                if (isset($this->post[$table])) {
                    rewind($this->post[$table]);
                    stream_copy_to_stream($this->post[$table], $stream);
                    fclose($this->post[$table]);
                }
                if (isset($this->fk[$table])) {
                    fwrite($stream, "\n" . self::FOREIGN_KEYS_MARKER . "\n");
                    rewind($this->fk[$table]);
                    stream_copy_to_stream($this->fk[$table], $stream);
                    fclose($this->fk[$table]);
                }
            }

            rewind($stream);
            $out[$table] = $stream;
        }

        $this->rest = $this->post = $this->fk = $this->pre = [];

        return $out;
    }

    /**
     * FK [parent, child] edges from every FOREIGN KEY in the dump, including
     * tables that are not being restored (see SqlDumpParser::getForeignKeys()).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    private function processLine(string $line): void
    {
        if ($this->inCopy) {
            $this->writeRest($line);
            if (rtrim($line, "\r\n") === '\.') {
                $this->inCopy = false;
            }

            return;
        }

        $trimmed = rtrim($line, "\r\n");

        if (preg_match(self::HEADER_PATTERN, $trimmed, $m) === 1) {
            $this->flushHeld();
            $this->startBlock($m[2], $m[1], $m[3]);

            return;
        }

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            return;
        }

        // psql meta-commands (\connect, \restrict, \unrestrict) are not SQL.
        if ($trimmed[0] === '\\') {
            return;
        }

        if ($this->block === null) {
            if (preg_match(self::PREAMBLE_PATTERN, $trimmed) === 1) {
                $this->preamble[] = $trimmed;
            }

            return;
        }

        $this->routeLine($line, $trimmed);
    }

    private function startBlock(string $type, string $name, string $schema): void
    {
        $table = null;

        switch ($type) {
            case 'TABLE':
                $table = PgSqlIdentifier::tableKey($schema, $name);
                $this->foundAnyTable = true;
                $this->createTable = $table;
                if ($this->isSelected($table)) {
                    $this->tables[$table] = true;
                    $this->pre[$table] = '';
                    Log::info("[Restore] Found table `{$table}` in backup.");
                }
                break;

            case 'TABLE DATA':
                $table = PgSqlIdentifier::tableKey($schema, $name);
                $this->createTable = null;
                break;

            case 'SEQUENCE SET':
                $this->createTable = null;
                break;

            default:
                $this->createTable = null;
        }

        $this->block = [
            'type'     => $type,
            'name'     => $name,
            'schema'   => $schema,
            'table'    => $table,
            'resolved' => $table !== null,
        ];
    }

    private function routeLine(string $line, string $trimmed): void
    {
        $block = &$this->block;
        $type  = $block['type'];

        if ($type === 'SEQUENCE') {
            $key = $block['schema'] . '.' . $block['name'];
            $this->sequenceSql[$key] = ($this->sequenceSql[$key] ?? '') . $line;

            return;
        }

        if ($type === 'SEQUENCE OWNED BY') {
            if (preg_match('/^ALTER SEQUENCE (' . PgSqlIdentifier::PART . ')\.(' . PgSqlIdentifier::PART . ') OWNED BY (' . PgSqlIdentifier::PART . ')\.(' . PgSqlIdentifier::PART . ')\./', $trimmed, $m) === 1) {
                $sequence = PgSqlIdentifier::unquote($m[1]) . '.' . PgSqlIdentifier::unquote($m[2]);
                $table    = PgSqlIdentifier::tableKey(PgSqlIdentifier::unquote($m[3]), PgSqlIdentifier::unquote($m[4]));
                $this->sequenceOwner[$sequence] = $table;
                if ($this->isRetained($table)) {
                    $this->writeRest($line, $table);
                }
            }

            return;
        }

        if ($type === 'SEQUENCE SET') {
            if (preg_match("/setval\('((?:[^']|'')*)'/", $trimmed, $m) === 1) {
                $parts = PgSqlIdentifier::parseDotted(str_replace("''", "'", $m[1]));
                if (count($parts) === 2) {
                    $table = $this->sequenceOwner[$parts[0] . '.' . $parts[1]] ?? null;
                    if ($table !== null && $this->isRetained($table)) {
                        $this->writeRest($line, $table);
                    }
                }
            }

            return;
        }

        if ($type === 'TABLE') {
            $this->collectCreateTableLine($line);
            if ($this->isRetained($block['table'])) {
                $this->pre[$block['table']] .= $line;
            }

            return;
        }

        if ($type === 'TABLE DATA') {
            if (! $this->isRetained($block['table'])) {
                // Still need to know where the COPY data ends.
                if (preg_match('/^COPY .* FROM stdin;$/', $trimmed) === 1) {
                    $this->inCopy = true;
                }

                return;
            }
            if (preg_match('/^COPY .* FROM stdin;$/', $trimmed) === 1) {
                $this->inCopy = true;
            }
            $this->writeRest($line, $block['table']);

            return;
        }

        if (in_array($type, self::DEFAULT_TYPES, true) || in_array($type, self::POST_TYPES, true)) {
            if (! $block['resolved']) {
                $this->held[] = $line;
                $table = $this->resolveTable($type, $trimmed);
                if ($table === null) {
                    return;
                }
                $block['table']    = $table;
                $block['resolved'] = true;
                $held = $this->held;
                $this->held = [];
                foreach ($held as $heldLine) {
                    $this->emit($type, $table, $heldLine);
                }

                return;
            }

            $this->emit($type, $block['table'], $line);
        }

        // Anything else (functions, views, types, ACLs...) is not restored.
    }

    private function emit(string $type, string $table, string $line): void
    {
        $this->collectForeignKey($table, $line);

        if (! $this->isRetained($table)) {
            return;
        }

        if (in_array($type, self::DEFAULT_TYPES, true)) {
            $this->writeRest($line, $table);
        } elseif ($type === 'FK CONSTRAINT') {
            $this->fk[$table] ??= fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
            fwrite($this->fk[$table], $line);
        } else {
            $this->writePost($line, $table);
        }
    }

    /**
     * Work out which table a DEFAULT / CONSTRAINT / INDEX / TRIGGER /
     * COMMENT statement belongs to, from its first line.
     */
    private function resolveTable(string $type, string $line): ?string
    {
        $id = PgSqlIdentifier::PART;

        if (preg_match("/^ALTER TABLE (?:ONLY )?({$id})\\.({$id})/", $line, $m) === 1
            || preg_match("/^CREATE (?:UNIQUE )?INDEX \\S+ ON (?:ONLY )?({$id})\\.({$id})/", $line, $m) === 1
            || preg_match("/^CREATE (?:OR REPLACE )?(?:CONSTRAINT )?TRIGGER .* ON ({$id})\\.({$id})/", $line, $m) === 1
            || preg_match("/^COMMENT ON (?:TABLE|COLUMN) ({$id})\\.({$id})/", $line, $m) === 1
            || preg_match("/^COMMENT ON (?:CONSTRAINT|TRIGGER|POLICY) \\S+ ON ({$id})\\.({$id})/", $line, $m) === 1
            || preg_match("/^CREATE POLICY \\S+ ON ({$id})\\.({$id})/", $line, $m) === 1
        ) {
            return PgSqlIdentifier::tableKey(PgSqlIdentifier::unquote($m[1]), PgSqlIdentifier::unquote($m[2]));
        }

        return null;
    }

    /**
     * Gather FK edges and identity-column sequences from CREATE TABLE text.
     */
    private function collectCreateTableLine(string $line): void
    {
        if ($this->createTable === null) {
            return;
        }

        $this->collectForeignKey($this->createTable, $line);

        if (preg_match('/SEQUENCE NAME (' . PgSqlIdentifier::PART . ')\.(' . PgSqlIdentifier::PART . ')/', $line, $m) === 1) {
            $this->sequenceOwner[PgSqlIdentifier::unquote($m[1]) . '.' . PgSqlIdentifier::unquote($m[2])] = $this->createTable;
        }
    }

    private function collectForeignKey(string $child, string $line): void
    {
        $id = PgSqlIdentifier::PART;

        if (preg_match("/\\bREFERENCES ({$id})\\.({$id})/", $line, $m) === 1) {
            $this->foreignKeys[] = [
                PgSqlIdentifier::tableKey(PgSqlIdentifier::unquote($m[1]), PgSqlIdentifier::unquote($m[2])),
                $child,
            ];
        }
    }

    private function flushHeld(): void
    {
        // A block whose table could not be determined is not restorable.
        $this->held = [];
    }

    private function isSelected(string $table): bool
    {
        return $this->selectAll || isset($this->requestedLookup[$table]);
    }

    private function isRetained(?string $table): bool
    {
        return $table !== null && isset($this->tables[$table]);
    }

    private function writeRest(string $line, ?string $table = null): void
    {
        $table ??= $this->block['table'] ?? null;
        if (! $this->isRetained($table)) {
            return;
        }

        $this->rest[$table] ??= fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
        fwrite($this->rest[$table], $line);
    }

    private function writePost(string $line, string $table): void
    {
        $this->post[$table] ??= fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
        fwrite($this->post[$table], $line);
    }

    private function closeAll(): void
    {
        foreach ([$this->rest, $this->post, $this->fk] as $streams) {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        $this->rest = $this->post = $this->fk = [];
    }
}
