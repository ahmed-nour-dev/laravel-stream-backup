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
 * Replays PgSqlDumpParser output into a PostgreSQL connection.
 *
 * ROLLBACK MODEL: the whole restore runs in ONE transaction. PostgreSQL DDL is
 * transactional, so a failure at any point (a bad statement, a COPY error, a
 * constraint that no longer holds) rolls back the drops, creates and loaded
 * data together and leaves the target as it was. There are no shadow tables;
 * `restore.atomic_restore` and `restore.skip_on_error` do not apply, because a
 * failed statement aborts a PostgreSQL transaction and cannot be skipped.
 *
 * ORDERING: pass 1 replays every table's pre-data part (CREATE TABLE,
 * sequences, defaults, data); pass 2 then adds constraints, indexes, foreign
 * keys and triggers. Foreign keys therefore never need dependency ordering,
 * and cyclic foreign keys work.
 *
 * LOADING DATA: COPY ... FROM stdin cannot go through PDO::exec(). Rows are
 * streamed through PDO's copyFromArray() (pgsqlCopyFromArray() before PHP 8.4)
 * in bounded batches, on the same connection and transaction as everything
 * else. Piping to `psql` would use a second connection and could not join the
 * transaction, which would give up all-or-nothing rollback.
 *
 * FK BOUNDARY GUARD: tables are dropped without CASCADE, and a foreign key
 * crossing the restore set (a restored table referenced by, or referencing, a
 * table that is not being restored) aborts the restore before anything is
 * touched, as in the MySQL restorer.
 */
final class PgSqlRestorer implements DumpRestorer
{
    private const COPY_BATCH_ROWS = 1000;

    private const COPY_BATCH_BYTES = 4 * 1024 * 1024;

    public function restore(array $tableBlocks, string $connection, float $startTime, array $dumpFkEdges = [], bool $selective = false): RestoreResult
    {
        $db = DB::connection($connection);

        if ($db->getDriverName() !== 'pgsql') {
            throw new RestoreFailedException(
                'PostgreSQL restore requires a pgsql connection (detected driver: ' . $db->getDriverName() . ').'
            );
        }

        $tables     = array_keys($tableBlocks);
        $totalRows  = 0;
        $current    = null;

        try {
            $this->assertNoForeignKeyBoundaryViolations($db, $tables, $dumpFkEdges);

            $db->beginTransaction();

            try {
                foreach ($this->schemasOf($tables) as $schema) {
                    $db->unprepared('CREATE SCHEMA IF NOT EXISTS ' . PgSqlIdentifier::quote($schema));
                }

                if ($tables !== []) {
                    $db->unprepared('DROP TABLE IF EXISTS ' . implode(', ', array_map(
                        [PgSqlIdentifier::class, 'qualifiedFromKey'],
                        $tables,
                    )));
                }

                /** @var array<string, int> $postOffsets */
                $postOffsets = [];

                foreach ($tableBlocks as $table => $stream) {
                    $current = $table;
                    rewind($stream);
                    [$rows, $offset] = $this->replay($db, $stream, true);
                    $totalRows += $rows;
                    if ($offset !== null) {
                        $postOffsets[$table] = $offset;
                    }
                }

                foreach ($postOffsets as $table => $offset) {
                    $current = $table;
                    fseek($tableBlocks[$table], $offset);
                    $this->replay($db, $tableBlocks[$table], false);
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
            Log::error('[Restore] PostgreSQL restore failed and was rolled back: ' . $e->getMessage());

            throw new RestoreFailedException(
                'PostgreSQL restore failed' . ($current !== null ? " while restoring `{$current}`" : '')
                . ' and was rolled back: ' . $e->getMessage(),
                0,
                $e,
            );
        } finally {
            foreach ($tableBlocks as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return new RestoreResult(
            tablesRestored: $tables,
            totalRowsAffected: $totalRows,
            durationSeconds: microtime(true) - $startTime,
        );
    }

    /**
     * Replay one table's stream. With $untilMarker, stop at the post-data
     * marker and return the stream offset just after it.
     *
     * @param  resource $stream
     * @return array{0: int, 1: ?int} [rows copied, post-data offset]
     */
    private function replay(Connection $db, $stream, bool $untilMarker): array
    {
        $reader = new PgSqlStatementReader();
        $rows   = 0;

        while (($line = fgets($stream)) !== false) {
            if ($reader->isIdle()) {
                $text = rtrim($line, "\r\n");

                if ($text === PgSqlDumpParser::POST_DATA_MARKER) {
                    if ($untilMarker) {
                        return [$rows, (int) ftell($stream)];
                    }
                    continue;
                }

                if (preg_match('/^COPY (.+?) \((.*)\) FROM stdin;$/', $text, $m) === 1) {
                    $rows += $this->copyRows($db, $stream, $m[1], $m[2]);
                    continue;
                }
            }

            foreach ($reader->push($line) as $statement) {
                $this->execute($db, $statement);
            }
        }

        foreach ($reader->finalize() as $statement) {
            $this->execute($db, $statement);
        }

        return [$rows, null];
    }

    private function execute(Connection $db, string $statement): void
    {
        // Ownership and privileges refer to roles that may not exist here.
        if (preg_match('/^(?:GRANT|REVOKE)\b/i', $statement) === 1
            || preg_match('/^ALTER\s+(?:TABLE|SEQUENCE)\s+(?:ONLY\s+)?[^;]*?\sOWNER\s+TO\s/is', $statement) === 1
        ) {
            return;
        }

        // Preamble SETs must not outlive the restore's transaction.
        if (preg_match('/^SET\s+(?!LOCAL\b)/i', $statement) === 1) {
            $statement = 'SET LOCAL ' . substr($statement, 4);
        }

        $db->unprepared($statement);
    }

    /**
     * Stream COPY rows (everything up to the `\.` terminator) into the table.
     *
     * @param  resource $stream
     */
    private function copyRows(Connection $db, $stream, string $table, string $columns): int
    {
        $pdo   = $db->getPdo();
        $batch = [];
        $bytes = 0;
        $total = 0;

        $flush = function () use (&$batch, &$bytes, $pdo, $table, $columns): void {
            if ($batch === []) {
                return;
            }

            $ok = method_exists($pdo, 'copyFromArray')
                ? $pdo->copyFromArray($table, $batch, "\t", '\\N', $columns)
                : $pdo->pgsqlCopyFromArray($table, $batch, "\t", '\\N', $columns);

            if ($ok === false) {
                throw new RestoreFailedException("COPY into {$table} failed: " . implode(' ', (array) $pdo->errorInfo()));
            }

            $batch = [];
            $bytes = 0;
        };

        while (($line = fgets($stream)) !== false) {
            $row = rtrim($line, "\n");

            if (rtrim($row, "\r") === '\.') {
                break;
            }

            $batch[] = $row;
            $bytes  += strlen($row);
            $total++;

            if (count($batch) >= self::COPY_BATCH_ROWS || $bytes >= self::COPY_BATCH_BYTES) {
                $flush();
            }
        }

        $flush();

        return $total;
    }

    /**
     * @param  string[] $tables
     * @return string[]
     */
    private function schemasOf(array $tables): array
    {
        $schemas = [];
        foreach ($tables as $table) {
            [$schema] = PgSqlIdentifier::splitKey($table);
            if ($schema !== 'public') {
                $schemas[$schema] = $schema;
            }
        }

        return array_values($schemas);
    }

    /**
     * @param string[]                                $tables
     * @param array<int, array{0: string, 1: string}> $dumpFkEdges
     */
    private function assertNoForeignKeyBoundaryViolations(Connection $db, array $tables, array $dumpFkEdges): void
    {
        if ($tables === []) {
            return;
        }

        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            $tables,
            $this->liveForeignKeyEdges($db),
            $dumpFkEdges,
        );

        if ($violations === []) {
            return;
        }

        $set   = array_flip($tables);
        $lines = [];
        foreach ($violations as [$parent, $child]) {
            $lines[] = isset($set[$parent])
                ? "`{$parent}` (restored) is referenced by a foreign key on `{$child}` (not restored)"
                : "`{$child}` (restored) has a foreign key referencing `{$parent}` (not restored)";
        }

        throw new RestoreFailedException(sprintf(
            'Restore aborted: the restored tables (%s) have a foreign-key relationship crossing the restore boundary: %s. '
            . 'Include the related table(s) in the restore, or remove the foreign key, before retrying.',
            implode(', ', array_map(static fn (string $t): string => "`{$t}`", $tables)),
            implode('; ', $lines),
        ));
    }

    /**
     * @return array<int, array{0: string, 1: string}> live [parent, child] edges
     */
    private function liveForeignKeyEdges(Connection $db): array
    {
        $rows = $db->select(
            'SELECT pn.nspname AS parent_schema, p.relname AS parent, cn.nspname AS child_schema, c.relname AS child '
            . 'FROM pg_constraint k '
            . 'JOIN pg_class c ON c.oid = k.conrelid JOIN pg_namespace cn ON cn.oid = c.relnamespace '
            . 'JOIN pg_class p ON p.oid = k.confrelid JOIN pg_namespace pn ON pn.oid = p.relnamespace '
            . "WHERE k.contype = 'f'",
            [],
            false,
        );

        $edges = [];
        foreach ($rows as $row) {
            $edges[] = [
                PgSqlIdentifier::tableKey((string) $row->parent_schema, (string) $row->parent),
                PgSqlIdentifier::tableKey((string) $row->child_schema, (string) $row->child),
            ];
        }

        return $edges;
    }
}
