<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\DTOs;

/**
 * Value object returned by the restore pipeline on success.
 *
 * $skippedStatements > 0 means the restore is a best-effort success (only
 * possible when restore.skip_on_error is explicitly enabled): RunRestoreJob
 * persists this as RestoreStatus::CompletedWithWarnings rather than
 * Completed, so it is never mistaken for a fully clean restore.
 */
final class RestoreResult
{
    /**
     * @param string[] $tablesRestored      List of table names that were restored
     * @param int      $totalRowsAffected    Total rows inserted across all tables
     * @param float    $durationSeconds      Wall-clock duration of the restore
     * @param int      $skippedStatements    Statements skipped by the skip-on-error
     *                                      safety net (e.g. DEFINER/privilege errors)
     */
    public function __construct(
        public readonly array $tablesRestored,
        public readonly int $totalRowsAffected,
        public readonly float $durationSeconds,
        public readonly int $skippedStatements = 0,
    ) {
    }
}
