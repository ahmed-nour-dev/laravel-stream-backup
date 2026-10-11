<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Contracts\DumpParser;
use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Exceptions\TableNotFoundException;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Streaming parser for `sqlite3 .dump` output.
 *
 * The dump is a flat list of statements (PRAGMA, BEGIN, CREATE TABLE, one
 * INSERT per row, CREATE INDEX / TRIGGER / VIEW, COMMIT). Each statement is
 * attributed to the table it belongs to and appended to that table's bounded
 * php://temp stream, so memory stays flat however large a table is.
 *
 * Output stream layout, one per table or view:
 *
 *   "table\n" | "view\n"
 *   frames: "S<byte length>\n<statement>\n" ...   CREATE + INSERTs
 *   "M\n"                                         post-data marker
 *   frames ...                                    indexes, triggers, sqlite_sequence
 *
 * Statements are length-framed because they can span lines (CREATE TABLE,
 * trigger bodies, string literals), so SqliteRestorer never has to tokenise
 * the SQL a second time.
 *
 * Views are returned as their own entries keyed by view name. Skipped:
 * PRAGMA/BEGIN/COMMIT, `sqlite_sequence` housekeeping, `sqlite_master`
 * writes (virtual tables) and anything else that is not CREATE/INSERT.
 */
final class SqliteDumpParser implements DumpParser
{
    private const TEMP_MAX_MEMORY = 2 * 1024 * 1024;

    private const IDENT = '(?:"(?:[^"]|"")+"|`[^`]+`|\[[^\]]+\]|\'(?:[^\']|\'\')+\'|[A-Za-z_][A-Za-z0-9_$]*)';

    private bool $selectAll;

    /** @var array<string, string> lower-cased requested name => requested name */
    private array $requested;

    private SqliteStatementReader $reader;

    private bool $finished = false;

    private bool $foundAny = false;

    /** @var array<string, string> lower-cased name => canonical name, for every table in the dump */
    private array $known = [];

    /** @var array<string, 'table'|'view'> retained entries, in dump order */
    private array $kinds = [];

    /** @var array<string, resource> */
    private array $main = [];

    /** @var array<string, resource> */
    private array $post = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $foreignKeys = [];

    /**
     * @param string[] $tables Tables to extract (empty = all).
     */
    public function __construct(array $tables = [])
    {
        $this->selectAll = $tables === [];
        $this->requested = [];
        foreach ($tables as $table) {
            $table = trim($table);
            $this->requested[strtolower($table)] = $table;
        }
        $this->reader = new SqliteStatementReader();
    }

    public function feed(string $chunk): void
    {
        foreach ($this->reader->feed($chunk) as $statement) {
            $this->handle($statement);
        }
    }

    /**
     * @return array<string, resource> name => rewound stream
     *
     * @throws InvalidBackupException If no table or view was found.
     * @throws TableNotFoundException If a requested table is missing.
     */
    public function finish(): array
    {
        if ($this->finished) {
            throw new LogicException('SqliteDumpParser::finish() has already been called.');
        }
        $this->finished = true;

        foreach ($this->reader->finalize() as $statement) {
            $this->handle($statement);
        }

        if (! $this->foundAny) {
            $this->closeAll();

            throw new InvalidBackupException(
                'No tables found in the backup file. '
                . 'The file may be corrupt or not valid `sqlite3 .dump` output.'
            );
        }

        if (! $this->selectAll) {
            $found   = array_map('strtolower', array_keys($this->kinds));
            $missing = array_diff(array_keys($this->requested), $found);

            if ($missing !== []) {
                $this->closeAll();

                throw new TableNotFoundException(sprintf(
                    'The following tables were not found in the backup: %s',
                    implode(', ', array_map(fn (string $k) => "`{$this->requested[$k]}`", $missing)),
                ));
            }
        }

        $out = [];
        foreach ($this->kinds as $name => $kind) {
            $stream = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
            fwrite($stream, $kind . "\n");

            rewind($this->main[$name]);
            stream_copy_to_stream($this->main[$name], $stream);
            fclose($this->main[$name]);

            if (isset($this->post[$name])) {
                fwrite($stream, "M\n");
                rewind($this->post[$name]);
                stream_copy_to_stream($this->post[$name], $stream);
                fclose($this->post[$name]);
            }

            rewind($stream);
            $out[$name] = $stream;
        }

        $this->main = $this->post = [];

        return $out;
    }

    /**
     * @return array<int, array{0: string, 1: string}> [parent, child] edges
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    private function handle(string $statement): void
    {
        $head = substr($statement, 0, 256);
        $id   = self::IDENT;

        if (preg_match("/^CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?({$id})/i", $head, $m) === 1) {
            $name = self::unquote($m[1]);
            if (str_starts_with(strtolower($name), 'sqlite_')) {
                return; // sqlite_sequence is created implicitly by AUTOINCREMENT
            }

            $this->foundAny = true;
            $this->known[strtolower($name)] = $name;

            if (preg_match_all("/\\bREFERENCES\\s+({$id})/i", $statement, $refs) > 0) {
                foreach ($refs[1] as $parent) {
                    $this->foreignKeys[] = [self::unquote($parent), $name];
                }
            }

            if ($this->isSelected($name)) {
                $this->kinds[$name] = 'table';
                $this->main[$name]  = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
                Log::info("[Restore] Found table `{$name}` in backup.");
                $this->frame($this->main[$name], $statement);
            }

            return;
        }

        if (preg_match("/^CREATE\\s+(?:TEMP(?:ORARY)?\\s+)?VIEW\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?({$id})/i", $head, $m) === 1) {
            $name = self::unquote($m[1]);
            $this->foundAny = true;

            if ($this->isSelected($name)) {
                $this->kinds[$name] = 'view';
                $this->main[$name]  = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
                $this->frame($this->main[$name], $statement);
            }

            return;
        }

        if (preg_match("/^INSERT\\s+INTO\\s+({$id})\\s*(?:VALUES|\\()/i", $head, $m) === 1) {
            $name = self::unquote($m[1]);

            if (strtolower($name) === 'sqlite_sequence') {
                $this->attachSequence($statement);

                return;
            }

            $table = $this->retained($name);
            if ($table !== null && ($this->kinds[$table] ?? '') === 'table') {
                $this->frame($this->main[$table], $statement);
            }

            return;
        }

        if (preg_match("/^CREATE\\s+(?:UNIQUE\\s+)?INDEX\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?{$id}\\s+ON\\s+({$id})/i", $head, $m) === 1
            || preg_match("/^CREATE\\s+(?:TEMP(?:ORARY)?\\s+)?TRIGGER\\b.*?\\bON\\s+({$id})/is", $head, $m) === 1
        ) {
            $table = $this->retained(self::unquote($m[1]));
            if ($table !== null) {
                $this->frame($this->post($table), $statement);
            }
        }

        // PRAGMA, BEGIN, COMMIT, DELETE FROM sqlite_sequence, sqlite_master
        // writes and anything else: not part of a table restore.
    }

    /**
     * `INSERT INTO sqlite_sequence VALUES('users',5)` belongs to its table.
     * The row is replaced rather than appended, because inserting the table's
     * data has already created one.
     */
    private function attachSequence(string $statement): void
    {
        if (preg_match("/VALUES\\s*\\(\\s*('(?:[^']|'')*'|\"(?:[^\"]|\"\")*\")/i", $statement, $m) !== 1) {
            return;
        }

        $name  = self::unquote($m[1]);
        $table = $this->retained($name);
        if ($table === null) {
            return;
        }

        $this->frame($this->post($table), "DELETE FROM sqlite_sequence WHERE name = '" . str_replace("'", "''", $table) . "'");
        $this->frame($this->post($table), $statement);
    }

    /**
     * @return resource
     */
    private function post(string $table)
    {
        return $this->post[$table] ??= fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
    }

    /**
     * Canonical name of a retained entry, matched case-insensitively.
     */
    private function retained(string $name): ?string
    {
        $canonical = $this->known[strtolower($name)] ?? $name;

        return isset($this->kinds[$canonical]) ? $canonical : null;
    }

    private function isSelected(string $name): bool
    {
        return $this->selectAll || isset($this->requested[strtolower($name)]);
    }

    /**
     * @param resource $stream
     */
    private function frame($stream, string $statement): void
    {
        fwrite($stream, 'S' . strlen($statement) . "\n" . $statement . "\n");
    }

    private static function unquote(string $identifier): string
    {
        $first = $identifier[0];

        return match ($first) {
            '"'     => str_replace('""', '"', substr($identifier, 1, -1)),
            "'"     => str_replace("''", "'", substr($identifier, 1, -1)),
            '`', '[' => substr($identifier, 1, -1),
            default => $identifier,
        };
    }

    private function closeAll(): void
    {
        foreach ([$this->main, $this->post] as $streams) {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        $this->main = $this->post = [];
    }
}
