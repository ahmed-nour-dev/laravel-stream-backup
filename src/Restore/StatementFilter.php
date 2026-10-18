<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

/**
 * Decides which mysqldump statements must be skipped during a programmatic
 * restore.
 *
 * mysqldump emits several statements that are useless — or actively harmful —
 * when a dump is replayed piecemeal (per table) through a single PDO
 * connection. This pure, stateless class keeps
 * that policy in one trivially unit-testable place.
 *
 * Skipped statement families:
 *
 *   LOCK TABLES / UNLOCK TABLES
 *     mysqldump wraps every table block in LOCK/UNLOCK. Executing these
 *     during a programmatic restore implicitly commits any open
 *     transaction and locks the PDO connection so no
 *     table outside the lock list — including the restore's own tracking
 *     table — can be reached until UNLOCK TABLES runs, which may never
 *     happen if the restore fails mid-table. The restore already runs
 *     with FK checks disabled and gets its rollback from shadow tables
 *     (see TableRestorer), so the locks are unnecessary.
 *
 *   Session save/restore statements referencing @OLD_* user variables
 *     mysqldump snapshots the current session state at the start of the
 *     dump (e.g. SET @OLD_TIME_ZONE=@@TIME_ZONE, wrapped in a versioned
 *     comment) and replays it at the end (e.g. SET TIME_ZONE=@OLD_TIME_ZONE,
 *     likewise wrapped). SqlDumpParser splits the dump per table, so the
 *     "save" statement (in the dump header) never reaches a table buffer,
 *     while the matching "restore" footer lands in the last captured
 *     table's buffer. There @OLD_TIME_ZONE is NULL and MySQL rejects
 *     SET TIME_ZONE=@OLD_TIME_ZONE with error 1231 ("Variable 'time_zone'
 *     can't be set to the value of 'NULL'"). The same NULL problem affects
 *     every @OLD_* pair (SQL_MODE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS,
 *     SQL_NOTES, CHARACTER_SET_*, COLLATION_CONNECTION). The restore
 *     manages its own session state (FK checks), so this
 *     bookkeeping is both useless and dangerous here.
 */
final class StatementFilter
{
    /**
     * Matches an @OLD_* user-variable reference. This spelling is specific
     * to mysqldump's session save/restore bookkeeping and never appears in
     * real DDL/DML, so it is safe to use as a skip signal.
     */
    private const OLD_SESSION_VAR_PATTERN = '/@OLD_\w+/i';

    /**
     * Return true when the given statement must not be executed during restore.
     */
    public function shouldSkip(string $statement): bool
    {
        $upper = strtoupper(ltrim($statement));

        if (str_starts_with($upper, 'LOCK TABLES') || str_starts_with($upper, 'UNLOCK TABLES')) {
            return true;
        }

        // mysqldump session save/restore bookkeeping. Guarded by "is a SET
        // statement (plain or versioned-comment)" so DML that merely contains
        // the literal "@OLD_" in its data — e.g.
        //   INSERT INTO logs VALUES ('@OLD_TOKEN_CLEANUP')
        // — is never skipped.
        if (preg_match(self::OLD_SESSION_VAR_PATTERN, $statement) === 1
            && (str_starts_with($upper, '/*!') || str_starts_with($upper, 'SET'))) {
            return true;
        }

        return false;
    }
}
