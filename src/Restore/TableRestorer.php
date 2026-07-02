<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\DTOs\RestoreResult;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Executes parsed table SQL blocks against a database connection.
 *
 * ROLLBACK GUARANTEE (shadow tables)
 * ----------------------------------
 * MySQL/InnoDB implicitly commits on DDL (DROP/CREATE/ALTER/TRUNCATE), so a
 * plain DB transaction CANNOT roll back a restore that contains structure
 * changes — the previous implementation's beginTransaction()/rollBack() was a
 * false promise for the default path (structure + data).
 *
 * Instead, each table being restored that already exists in the target
 * database is renamed aside to a `_sbr_*` "shadow" name BEFORE its dump block
 * runs; the dump's own DROP/CREATE/INSERT then execute unmodified against the
 * real table name. On any failure, `rollbackShadows()` drops the partially
 * created new table and renames the shadow back, restoring the original —
 * across all tables processed so far. This gives real cross-table rollback
 * for the DDL path.
 *
 * VISIBILITY TRADE-OFF (not a single atomic swap)
 * -----------------------------------------------
 * Unlike a build-into-shadows-then-swap design, each table goes live under
 * its real name as soon as its own loop iteration finishes. Live traffic
 * during a long restore can therefore observe a half-old/half-new database
 * (e.g. an already-restored `orders` referencing a not-yet-restored
 * `customers`). This is the accepted cost of the simpler, no-SQL-rewrite
 * mechanism; restore against a database with no live traffic if you need
 * snapshot visibility.
 *
 * FK CAVEAT
 * ---------
 * InnoDB auto-updates a child table's FK metadata to follow a RENAME TABLE of
 * the parent. For a SELECTIVE restore of a table that is an FK parent of a
 * table NOT included in the restore, the rename repoints the unrestored
 * child's FK to `_sbr_*`, which is dropped on success → orphaned FK metadata.
 * Full-schema restores are safe (every child is recreated referencing fresh
 * parents). Disable `restore.atomic_restore` to opt out of shadow tables.
 *
 * SKIP-ON-ERROR INTERACTION
 * -------------------------
 * When `skip_on_error` swallows statements (`skippedCount > 0`) the restore is
 * known-incomplete, so the `_sbr_*` shadows are RETAINED (not dropped) so the
 * last-known-good data survives for manual recovery. Only a fully-clean
 * restore auto-drops the superseded originals.
 *
 * Execution flow:
 *   1. Fail fast if any stale `_sbr_*` shadows exist (a previous crashed
 *      restore) — never silently destroy what could be an original.
 *   2. Disable FK checks (SET FOREIGN_KEY_CHECKS = 0).
 *   3. For each table: rename original aside (if atomic_restore + exists),
 *      then execute its dump block via executeBuffer().
 *   4. On clean success: drop the renamed-aside originals.
 *      On incomplete success (skipped > 0): retain shadows + warn.
 *   5. On any Throwable: rollbackShadows(), then re-throw.
 *   6. finally: re-enable FK checks + close buffers.
 */
final class TableRestorer
{
    private readonly DefinerStripper $definerStripper;
    private readonly StatementFilter $statementFilter;
    private readonly bool $stripDefiners;
    private readonly bool $skipOnError;
    private readonly bool $shadowTables;

    /** @var array<int, int> */
    private readonly array $skippableCodes;

    private int $skippedCount = 0;

    /**
     * Map of real table name => its rename-aside shadow name, populated only
     * for tables whose original was renamed aside during the current restore.
     *
     * @var array<string, string>
     */
    private array $shadows = [];

    public function __construct(Config $config)
    {
        $this->definerStripper = new DefinerStripper();
        $this->statementFilter = new StatementFilter();
        $this->stripDefiners   = (bool) $config->get('stream-backup.restore.strip_definers', true);
        $this->skipOnError     = (bool) $config->get('stream-backup.restore.skip_on_error', true);
        $this->skippableCodes  = array_map('intval', (array) $config->get('stream-backup.restore.skippable_error_codes', [1227]));
        $this->shadowTables   = (bool) $config->get('stream-backup.restore.atomic_restore', true);
    }

    /**
     * Restore the given table blocks into the target database.
     *
     * @param array<string, resource> $tableBlocks Map of table_name => php://temp stream
     * @param string                  $connection  Laravel DB connection name
     * @return RestoreResult
     *
     * @throws RestoreFailedException If any SQL execution fails, or if stale
     *                                shadow tables from a previous crashed
     *                                restore are detected.
     */
    public function restore(array $tableBlocks, string $connection, float $startTime): RestoreResult
    {
        $db = DB::connection($connection);
        $totalRows      = 0;
        $tablesRestored = [];
        $currentTable   = null;
        $this->skippedCount = 0;
        $this->shadows      = [];

        /** @var array<string, bool> $processed table => hadOriginalRenamedAside */
        $processed = [];

        try {
            $driver = $this->driverName($db);

            if ($driver !== 'mysql') {
                throw new RestoreFailedException(
                    'Streaming restore currently targets MySQL only (detected driver: '
                    . ($driver ?? 'unknown')
                    . '). The dump replay, FK-check handling and shadow-table '
                    . 'rollback rely on MySQL-specific SQL.'
                );
            }

            $this->assertNoStaleShadows($db);

            $this->execSql($db, 'SET FOREIGN_KEY_CHECKS = 0');
            Log::info('[Restore] Disabled foreign key checks.');

            foreach ($tableBlocks as $tableName => $buffer) {
                $currentTable = $tableName;
                Log::info("[Restore] Restoring table `{$tableName}`...");

                $hadOriginal = false;
                if ($this->shadowTables && $this->tableExists($db, $tableName)) {
                    $this->renameAside($db, $tableName);
                    $hadOriginal = true;
                }
                $processed[$tableName] = $hadOriginal;

                $rowsForTable = $this->executeBuffer($db, $buffer, $tableName);
                $totalRows += $rowsForTable;
                $tablesRestored[] = $tableName;

                Log::info("[Restore] Table `{$tableName}` restored ({$rowsForTable} rows affected).");
            }

            // Success: the renamed-aside originals are now superseded by the
            // freshly-restored tables. Drop them — UNLESS the restore is known
            // to be incomplete (skipped statements), in which case retain the
            // shadows so the last-known-good data survives for manual recovery.
            if ($this->shadows === []) {
                // atomic_restore disabled (or no pre-existing tables) — nothing to clean up.
            } elseif ($this->skippedCount > 0) {
                $retained = array_map(
                    static fn (string $shadow): string => "`{$shadow}`",
                    array_values($this->shadows),
                );
                Log::warning(
                    '[Restore] Restore completed with ' . $this->skippedCount
                    . ' skipped statement(s); retaining rollback shadow tables '
                    . 'for manual inspection: ' . implode(', ', $retained)
                );
            } else {
                $this->dropShadows($db, $processed);
            }
        } catch (\Throwable $e) {
            // DDL cannot be rolled back by a DB transaction (MySQL implicitly
            // commits on DROP/CREATE). Rollback is achieved manually by
            // restoring each renamed-aside original and dropping any
            // partially-created new table.
            $this->rollbackShadows($db, $processed);

            if ($e instanceof RestoreFailedException) {
                throw $e;
            }

            $message = $currentTable !== null
                ? "Restore failed on table `{$currentTable}`: {$e->getMessage()}"
                : "Restore failed: {$e->getMessage()}";

            throw new RestoreFailedException($message, 0, $e);
        } finally {
            try {
                $this->execSql($db, 'SET FOREIGN_KEY_CHECKS = 1');
                Log::info('[Restore] Re-enabled foreign key checks.');
            } catch (\Throwable) {
                Log::warning('[Restore] Failed to re-enable foreign key checks.');
            }

            // Close all php://temp buffers.
            foreach ($tableBlocks as $buffer) {
                if (is_resource($buffer)) {
                    @fclose($buffer);
                }
            }
        }

        $duration = microtime(true) - $startTime;

        if ($this->skippedCount > 0) {
            Log::warning("[Restore] Completed with {$this->skippedCount} skipped statement(s) due to skippable errors.");
        }

        return new RestoreResult(
            tablesRestored:    $tablesRestored,
            totalRowsAffected: $totalRows,
            durationSeconds:   $duration,
            skippedStatements: $this->skippedCount,
        );
    }

    /**
     * Execute SQL statements from a php://temp buffer against the connection.
     *
     * Reads the buffer line-by-line, accumulating multi-line statements
     * (delimited by `;`), and executes each complete statement.
     *
     * @param ConnectionInterface $db
     * @param resource           $buffer
     * @param string             $tableName
     * @return int Rows affected
     */
    private function executeBuffer(ConnectionInterface $db, $buffer, string $tableName): int
    {
        $totalRows = 0;
        $statement = '';

        while (($line = fgets($buffer)) !== false) {
            $trimmed = trim($line);

            // Skip empty lines and SQL comments (-- and /* ... */).
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $statement .= $line;

            // Execute when we reach a semicolon at the end of a line.
            // mysqldump always terminates statements with ";\n".
            if (str_ends_with($trimmed, ';')) {
                $cleanStatement = trim($statement);

                if ($cleanStatement !== '' && $cleanStatement !== ';') {
                    $totalRows += $this->executeStatement($db, $cleanStatement, $tableName);
                }

                $statement = '';
            }
        }

        // Execute any remaining partial statement.
        $remaining = trim($statement);
        if ($remaining !== '' && $remaining !== ';') {
            $totalRows += $this->executeStatement($db, $remaining, $tableName);
        }

        return $totalRows;
    }

    /**
     * Execute a single SQL statement, applying DEFINER stripping and the
     * configurable skip-on-error safety net.
     *
     * @param ConnectionInterface $db
     * @param string              $statement
     * @param string              $tableName
     * @return int Rows affected
     */
    private function executeStatement(ConnectionInterface $db, string $statement, string $tableName): int
    {
        if ($this->statementFilter->shouldSkip($statement)) {
            return 0;
        }

        if ($this->stripDefiners) {
            $statement = $this->definerStripper->stripDefiner($statement);
        }

        try {
            // Use PDO::exec() directly instead of $db->unprepared() to bypass
            // Laravel's query logging and event system. This avoids two issues:
            //
            // 1. Query listeners that format SQL with sprintf() crash on
            //    statements containing literal '%' characters (URLs, LIKE
            //    patterns, etc.), producing "The arguments array must contain
            //    N items, 0 given" errors.
            //
            // 2. PDO::exec() returns the actual number of affected rows,
            //    whereas unprepared() only returns true/false, giving
            //    inaccurate row counts.
            $pdo = method_exists($db, 'getPdo') ? $db->getPdo() : null;

            if ($pdo !== null) {
                $result = $pdo->exec($statement);

                return $result === false ? 0 : (int) $result;
            }

            return (int) $db->unprepared($statement);
        } catch (\Throwable $e) {
            $code = MySqlErrorExtractor::code($e);

            if ($this->skipOnError && $code !== null && in_array($code, $this->skippableCodes, true)) {
                Log::warning(
                    "[Restore] Skipped statement in `{$tableName}` due to skippable MySQL error (code {$code}): {$e->getMessage()}",
                    ['statement' => mb_substr($statement, 0, 500)]
                );

                $this->skippedCount++;

                return 0;
            }

            throw $e;
        }
    }

    /**
     * Run a raw DDL/admin statement (RENAME/DROP/SET), bypassing Laravel's
     * query logging/events for the same reason as executeStatement() — query
     * listeners that format SQL with sprintf() crash on literal '%'.
     */
    private function execSql(ConnectionInterface $db, string $sql): void
    {
        $pdo = method_exists($db, 'getPdo') ? $db->getPdo() : null;

        if ($pdo !== null) {
            $pdo->exec($sql);
            return;
        }

        $db->unprepared($sql);
    }

    /**
     * Normalised PDO driver name of the connection ('mysql', 'pgsql',
     * 'sqlite', ...) or null if it cannot be determined. Used to gate the
     * MySQL-specific shadow-table logic.
     */
    private function driverName(ConnectionInterface $db): ?string
    {
        $pdo = method_exists($db, 'getPdo') ? $db->getPdo() : null;

        if ($pdo === null) {
            return null;
        }

        $name = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return is_string($name) ? $name : null;
    }

    /**
     * Does the given table currently exist in the target database?
     *
     * Uses the WRITE connection ($useReadPdo = false): a read-replica false
     * negative would skip the rename-aside, leaving the dump's DROP TABLE to
     * destroy the original with no shadow to recover.
     */
    private function tableExists(ConnectionInterface $db, string $table): bool
    {
        $row = $db->selectOne(
            'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table],
            false,
        );

        return $row !== null;
    }

    /**
     * Deterministic, length-bounded shadow table name for a real table.
     *
     * MySQL caps identifiers at 64 chars; when `_sbr_` + the table name would
     * overflow, a stable hash suffix is used so the name is still bounded and
     * collision-free in practice.
     */
    public static function shadowName(string $table): string
    {
        $prefix = '_sbr_';

        if (strlen($prefix . $table) <= 64) {
            return $prefix . $table;
        }

        return $prefix . substr(sha1($table), 0, 16);
    }

    /**
     * Rename the existing original table aside to its shadow name so the
     * dump's DROP/CREATE/INSERT can run against the real name without
     * destroying the original. Records the mapping in $shadows.
     */
    private function renameAside(ConnectionInterface $db, string $table): void
    {
        $shadow = self::shadowName($table);

        $this->execSql($db, "RENAME TABLE `{$table}` TO `{$shadow}`");

        $this->shadows[$table] = $shadow;
    }

    /**
     * Drop the renamed-aside originals now that the fresh tables supersede
     * them. Best-effort per table: a failure leaves a shadow behind (the next
     * restore fail-fasts on it via assertNoStaleShadows) rather than aborting.
     *
     * @param array<string, bool> $processed
     */
    private function dropShadows(ConnectionInterface $db, array $processed): void
    {
        foreach ($processed as $table => $hadOriginal) {
            if (! $hadOriginal || ! isset($this->shadows[$table])) {
                continue;
            }

            $shadow = $this->shadows[$table];

            try {
                $this->execSql($db, "DROP TABLE IF EXISTS `{$shadow}`");
            } catch (\Throwable $dropError) {
                Log::error(
                    "[Restore] Failed to drop shadow `{$shadow}` for `{$table}`: "
                    . $dropError->getMessage()
                );
            }
        }
    }

    /**
     * Manual compensating rollback: for each processed table, drop the
     * partially-created new table and (if it had an original) rename the
     * shadow back to the real name. Processed in reverse order to respect FK
     * dependencies where possible. Each step is best-effort so one failure
     * does not prevent restoring the next original.
     *
     * @param array<string, bool> $processed
     */
    private function rollbackShadows(ConnectionInterface $db, array $processed): void
    {
        foreach (array_reverse($processed, true) as $table => $hadOriginal) {
            try {
                $this->execSql($db, "DROP TABLE IF EXISTS `{$table}`");

                if ($hadOriginal && isset($this->shadows[$table])) {
                    $shadow = $this->shadows[$table];
                    $this->execSql($db, "RENAME TABLE `{$shadow}` TO `{$table}`");
                    Log::warning("[Restore] Rolled back table `{$table}` from shadow `{$shadow}`.");
                } else {
                    Log::warning("[Restore] Dropped partially-created table `{$table}` during rollback.");
                }
            } catch (\Throwable $rollbackError) {
                // Never let one rollback failure block restoring the next
                // original. The original (if any) is still under its _sbr_
                // name for manual recovery; assertNoStaleShadows will surface
                // it on the next restore attempt.
                Log::error(
                    "[Restore] Rollback step failed for `{$table}`: "
                    . $rollbackError->getMessage()
                );
            }
        }
    }

    /**
     * Fail fast if any `_sbr_*` shadow tables already exist in the target
     * database — they indicate a previous restore crashed before cleanup.
     * Never silently drop them: a shadow may hold an original that must be
     * renamed back manually.
     */
    private function assertNoStaleShadows(ConnectionInterface $db): void
    {
        $rows = $db->select(
            'SELECT table_name AS name FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE()',
            [],
            false,
        );

        $stale = [];
        foreach ($rows as $row) {
            $name = is_object($row) && isset($row->name)
                ? (string) $row->name
                : (is_array($row) ? (string) ($row['name'] ?? '') : '');

            if (str_starts_with($name, '_sbr_')) {
                $stale[] = $name;
            }
        }

        if ($stale === []) {
            return;
        }

        throw new RestoreFailedException(
            'A previous restore appears to have crashed and left rollback shadow '
            . 'tables ('
            . implode(', ', array_map(static fn (string $t): string => "`{$t}`", $stale))
            . '). Resolve them manually (rename back to restore originals, or drop '
            . 'if disposable) before starting a new restore.'
        );
    }
}
