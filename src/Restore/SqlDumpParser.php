<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Exceptions\TableNotFoundException;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Streaming mysqldump SQL parser.
 *
 * Fed decompressed SQL incrementally, chunk by chunk, as it arrives from the
 * decompressor — it is never handed the full dump at once. It splits each
 * chunk into lines and extracts complete table blocks (DROP, CREATE, INSERT,
 * LOCK/UNLOCK) for the requested tables.
 *
 * Design constraints:
 * - O(1) memory per line — never loads the full dump into memory.
 * - Each extracted table block is written to a php://temp stream that
 *   auto-spills to disk after 2 MB, bounding RAM even for huge tables.
 * - Single pass through the stream: no seeking or re-reading. The full
 *   decompressed dump is never assembled anywhere, on disk or in memory —
 *   only the requested tables' own data is retained.
 *
 * mysqldump boundary markers detected:
 *   -- Table structure for table `xxx`   ← start of DDL block
 *   -- Dumping data for table `xxx`      ← start of DML block
 *   -- Table structure for table `yyy`   ← end of current table, start of next
 */
final class SqlDumpParser
{
    /**
     * Regex matching the mysqldump "Table structure" comment.
     * Captures the table name (with or without backtick quoting).
     */
    private const TABLE_STRUCTURE_PATTERN = '/^-- Table structure for table `?([^`]+)`?\s*$/';

    /**
     * Regex matching the mysqldump "Dumping data" comment.
     */
    private const TABLE_DATA_PATTERN = '/^-- Dumping data for table `?([^`]+)`?\s*$/';

    /**
     * Regex matching a `CONSTRAINT ... FOREIGN KEY (...) REFERENCES parent`
     * clause inside a CREATE TABLE statement. Captures the referenced
     * (parent) table name, with or without backtick quoting.
     */
    private const FOREIGN_KEY_PATTERN = '/FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+`?([A-Za-z0-9_]+)`?/i';

    /**
     * Max memory (bytes) for php://temp buffers before spilling to disk.
     * 2 MB keeps RAM bounded while avoiding unnecessary disk I/O for
     * small tables.
     */
    private const TEMP_MAX_MEMORY = 2 * 1024 * 1024;

    private readonly bool $selectAll;

    /** @var array<string, int> */
    private readonly array $requestedLookup;

    /** @var array<string, resource> Table name => php://temp resource */
    private array $buffers = [];

    /** The table we're currently capturing (null = skip). */
    private ?string $currentTable = null;

    /** Whether we found at least one table marker so far. */
    private bool $foundAnyTable = false;

    /** Bytes fed so far that don't yet form a complete line. */
    private string $pending = '';

    private bool $finished = false;

    /**
     * FK [parent, child] edges parsed directly out of the CREATE TABLE
     * statements captured while feeding this parser. Populated regardless of
     * whether the referenced (parent) table itself is one of the requested
     * tables — TableRestorer filters edges to its restore set.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $foreignKeys = [];

    /**
     * @param string[] $tables Tables to extract (empty = all tables)
     */
    public function __construct(array $tables = [])
    {
        $this->selectAll = $tables === [];
        $this->requestedLookup = array_flip(array_map('trim', $tables));
    }

    /**
     * Feed the next chunk of decompressed SQL as it streams in.
     *
     * Complete lines are routed immediately into the matching table's
     * bounded buffer; any trailing partial line is held until the next
     * call. Only requested-table data is ever retained.
     */
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
     * Finalize parsing once the source stream has ended: flush any trailing
     * partial line, validate the result, and return the extracted table
     * blocks rewound and ready for reading.
     *
     * @return array<string, resource> Map of table_name => php://temp stream
     *                                 containing the full SQL block for that table
     *
     * @throws TableNotFoundException  If any requested table is not found in the dump
     * @throws InvalidBackupException  If the stream is not valid mysqldump output
     */
    public function finish(): array
    {
        if ($this->finished) {
            throw new LogicException('SqlDumpParser::finish() has already been called.');
        }
        $this->finished = true;

        if ($this->pending !== '') {
            $this->processLine($this->pending);
            $this->pending = '';
        }

        if (! $this->foundAnyTable) {
            $this->closeBuffers();

            throw new InvalidBackupException(
                'No table markers found in the backup file. '
                . 'The file may be corrupt or not a valid mysqldump output.'
            );
        }

        if (! $this->selectAll) {
            $found   = array_keys($this->buffers);
            $missing = array_diff(array_keys($this->requestedLookup), $found);

            if ($missing !== []) {
                $this->closeBuffers();

                throw new TableNotFoundException(sprintf(
                    'The following tables were not found in the backup: %s',
                    implode(', ', array_map(fn (string $t) => "`{$t}`", $missing)),
                ));
            }
        }

        foreach ($this->buffers as $buf) {
            rewind($buf);
        }

        return $this->buffers;
    }

    /**
     * Process a single line (including its trailing newline, if any),
     * routing it into the currently-active table buffer.
     */
    private function processLine(string $line): void
    {
        $trimmedLine = rtrim($line, "\r\n");

        // Detect "Table structure for table `xxx`" markers.
        if (preg_match(self::TABLE_STRUCTURE_PATTERN, $trimmedLine, $matches) === 1) {
            $tableName = $matches[1];
            $this->foundAnyTable = true;

            if ($this->selectAll || isset($this->requestedLookup[$tableName])) {
                $this->currentTable = $tableName;

                if (! isset($this->buffers[$tableName])) {
                    $this->buffers[$tableName] = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
                    Log::info("[Restore] Found table `{$tableName}` in backup.");
                }
            } else {
                $this->currentTable = null;
            }

            // Write the marker line itself to the buffer if capturing.
            if ($this->currentTable !== null && isset($this->buffers[$this->currentTable])) {
                fwrite($this->buffers[$this->currentTable], $line);
            }

            return;
        }

        // Detect "Dumping data for table `xxx`" markers.
        if (preg_match(self::TABLE_DATA_PATTERN, $trimmedLine, $matches) === 1) {
            $tableName = $matches[1];

            if ($this->selectAll || isset($this->requestedLookup[$tableName])) {
                $this->currentTable = $tableName;

                // Ensure buffer exists (in case data section appears without structure).
                if (! isset($this->buffers[$tableName])) {
                    $this->buffers[$tableName] = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
                }
            } else {
                $this->currentTable = null;
            }
        }

        // Write the current line to the active table's buffer.
        if ($this->currentTable !== null && isset($this->buffers[$this->currentTable])) {
            // Capture FK edges straight out of the CREATE TABLE text so
            // that a table newly introduced by this backup (which has no
            // information_schema constraint row yet on the target) still
            // contributes an edge to TableRestorer's dependency sort.
            if (preg_match(self::FOREIGN_KEY_PATTERN, $line, $fkMatches) === 1) {
                $this->foreignKeys[] = [$fkMatches[1], $this->currentTable];
            }

            fwrite($this->buffers[$this->currentTable], $line);
        }
    }

    private function closeBuffers(): void
    {
        foreach ($this->buffers as $buf) {
            if (is_resource($buf)) {
                fclose($buf);
            }
        }
    }

    /**
     * FK [parent, child] edges parsed directly out of the CREATE TABLE
     * statements captured while feeding this parser.
     *
     * Unlike information_schema (which only knows about constraints that
     * already exist on the target), this reflects the dump's OWN declared
     * FKs — including a table the dump is introducing for the first time, so
     * TableRestorer's dependency sort can still order it after its parent.
     *
     * @return array<int, array{0: string, 1: string}> list of [parent, child]
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }
}
