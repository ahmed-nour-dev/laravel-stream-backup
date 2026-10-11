<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Contracts\DumpRestorer;
use Ahmednour\StreamBackup\DTOs\RestoreResult;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Replays SqliteDumpParser output into a SQLite connection.
 *
 * ROLLBACK MODEL: every restore, full or selective, drops and recreates the
 * dumped tables inside ONE transaction. SQLite DDL is transactional, so a
 * failure at any point rolls the drops, new tables and loaded rows back
 * together and leaves the database file as it was.
 *
 * Why not build a new file and swap it in? A swap loses any write made to the
 * live file while the restore runs, leaves every open connection pointing at
 * the old file, and would discard tables that are not in the dump (including
 * this package's own `backups` / `restores` tables when they share the file).
 * The transaction touches only the dumped tables and keeps everything else.
 * The trade-off is that SQLite's single write lock is held for the whole
 * restore, so other writers wait (up to their busy timeout) instead of
 * racing it.
 *
 * ORDERING: tables and rows load first, then indexes, triggers and
 * sqlite_sequence values. Foreign-key enforcement is switched off for the
 * restore (it cannot change inside a transaction) and put back afterwards. If
 * it was on, the restored data is checked with PRAGMA foreign_key_check before
 * commit, so a restore that would break referential integrity rolls back.
 *
 * FK BOUNDARY GUARD: as for MySQL and PostgreSQL, a foreign key crossing the
 * restore set aborts the restore before anything is touched.
 *
 * Not restored: virtual tables, and anything in the dump that is not a table,
 * view, index or trigger.
 */
final class SqliteRestorer implements DumpRestorer
{
    public function restore(array $tableBlocks, string $connection, float $startTime, array $dumpFkEdges = [], bool $selective = false): RestoreResult
    {
        $db = DB::connection($connection);

        if ($db->getDriverName() !== 'sqlite') {
            throw new RestoreFailedException(
                'SQLite restore requires a sqlite connection (detected driver: ' . $db->getDriverName() . ').'
            );
        }

        $names     = array_keys($tableBlocks);
        $totalRows = 0;
        $current   = null;
        $restoreFk = null;

        try {
            $this->assertNoForeignKeyBoundaryViolations($db, $names, $dumpFkEdges);

            $foreignKeysWereOn = (int) ($db->selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0) === 1;
            if ($foreignKeysWereOn && $db->transactionLevel() === 0) {
                $db->unprepared('PRAGMA foreign_keys = OFF');
                $restoreFk = true;
            }

            $db->beginTransaction();

            try {
                $kinds = [];
                foreach ($tableBlocks as $name => $stream) {
                    rewind($stream);
                    $kinds[$name] = trim((string) fgets($stream));
                }

                foreach ($kinds as $name => $kind) {
                    $db->unprepared(
                        ($kind === 'view' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . self::quote($name)
                    );
                }

                /** @var array<string, int> $postOffsets */
                $postOffsets = [];

                foreach ($tableBlocks as $name => $stream) {
                    $current = $name;
                    [$rows, $offset] = $this->replay($db, $stream, true);
                    $totalRows += $rows;
                    if ($offset !== null) {
                        $postOffsets[$name] = $offset;
                    }
                }

                foreach ($postOffsets as $name => $offset) {
                    $current = $name;
                    fseek($tableBlocks[$name], $offset);
                    $this->replay($db, $tableBlocks[$name], false);
                }

                $current = null;

                if ($foreignKeysWereOn) {
                    $this->assertForeignKeysHold($db, $names);
                }

                $db->commit();
            } catch (\Throwable $e) {
                if ($db->transactionLevel() > 0) {
                    $db->rollBack();
                }

                throw $e;
            }
        } catch (RestoreFailedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Restore] SQLite restore failed and was rolled back: ' . $e->getMessage());

            throw new RestoreFailedException(
                'SQLite restore failed' . ($current !== null ? " while restoring `{$current}`" : '')
                . ' and was rolled back: ' . $e->getMessage(),
                0,
                $e,
            );
        } finally {
            if ($restoreFk === true && $db->transactionLevel() === 0) {
                $db->unprepared('PRAGMA foreign_keys = ON');
            }
            foreach ($tableBlocks as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return new RestoreResult(
            tablesRestored: array_keys(array_filter($kinds, static fn (string $k): bool => $k === 'table')),
            totalRowsAffected: $totalRows,
            durationSeconds: microtime(true) - $startTime,
        );
    }

    /**
     * Execute framed statements. With $untilMarker, stop at the post-data
     * marker and return the stream offset just after it.
     *
     * @param  resource $stream
     * @return array{0: int, 1: ?int} [rows inserted, post-data offset]
     */
    private function replay(Connection $db, $stream, bool $untilMarker): array
    {
        $rows = 0;

        while (($header = fgets($stream)) !== false) {
            $header = rtrim($header, "\n");

            if ($header === 'M') {
                if ($untilMarker) {
                    return [$rows, (int) ftell($stream)];
                }
                continue;
            }

            if ($header === '' || $header[0] !== 'S') {
                throw new RestoreFailedException("Corrupt SQLite restore buffer (unexpected frame '{$header}').");
            }

            $length    = (int) substr($header, 1);
            $statement = '';
            while (strlen($statement) < $length) {
                $part = fread($stream, $length - strlen($statement));
                if ($part === false || $part === '') {
                    throw new RestoreFailedException('Corrupt SQLite restore buffer (truncated statement).');
                }
                $statement .= $part;
            }
            fgets($stream); // frame-terminating newline

            $db->unprepared($statement);

            if (stripos(ltrim(substr($statement, 0, 32)), 'INSERT') === 0
                && stripos($statement, 'sqlite_sequence') === false
            ) {
                $rows++;
            }
        }

        return [$rows, null];
    }

    /**
     * @param string[] $names
     *
     * @throws RestoreFailedException If a restored table now violates a foreign key.
     */
    private function assertForeignKeysHold(Connection $db, array $names): void
    {
        $set        = array_flip(array_map('strtolower', $names));
        $violations = [];

        foreach ($db->select('PRAGMA foreign_key_check') as $row) {
            if (isset($set[strtolower((string) ($row->table ?? ''))]) || isset($set[strtolower((string) ($row->parent ?? ''))])) {
                $violations[] = sprintf('`%s` row %s references missing `%s`', $row->table ?? '?', $row->rowid ?? '?', $row->parent ?? '?');
            }
        }

        if ($violations !== []) {
            throw new RestoreFailedException(
                'The restored data violates foreign keys: ' . implode('; ', array_slice($violations, 0, 5))
                . (count($violations) > 5 ? sprintf(' (and %d more)', count($violations) - 5) : '')
            );
        }
    }

    /**
     * @param string[]                                $names
     * @param array<int, array{0: string, 1: string}> $dumpFkEdges
     */
    private function assertNoForeignKeyBoundaryViolations(Connection $db, array $names, array $dumpFkEdges): void
    {
        if ($names === []) {
            return;
        }

        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            $names,
            $this->liveForeignKeyEdges($db),
            $dumpFkEdges,
        );

        if ($violations === []) {
            return;
        }

        $set   = array_flip($names);
        $lines = [];
        foreach ($violations as [$parent, $child]) {
            $lines[] = isset($set[$parent])
                ? "`{$parent}` (restored) is referenced by a foreign key on `{$child}` (not restored)"
                : "`{$child}` (restored) has a foreign key referencing `{$parent}` (not restored)";
        }

        throw new RestoreFailedException(sprintf(
            'Restore aborted: the restored tables (%s) have a foreign-key relationship crossing the restore boundary: %s. '
            . 'Include the related table(s) in the restore, or remove the foreign key, before retrying.',
            implode(', ', array_map(static fn (string $t): string => "`{$t}`", $names)),
            implode('; ', $lines),
        ));
    }

    /**
     * @return array<int, array{0: string, 1: string}> live [parent, child] edges
     */
    private function liveForeignKeyEdges(Connection $db): array
    {
        $edges = [];

        $tables = $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'");
        foreach ($tables as $table) {
            foreach ($db->select('PRAGMA foreign_key_list(' . self::quote((string) $table->name) . ')') as $fk) {
                $edges[] = [(string) $fk->table, (string) $table->name];
            }
        }

        return $edges;
    }

    private static function quote(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
