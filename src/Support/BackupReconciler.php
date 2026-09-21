<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Enums\ReconciliationOutcome;
use Ahmednour\StreamBackup\Models\Backup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reconciles a `backups` row against the remote object its `path` points
 * at, so a crash between "the upload finished" and "the DB row was marked
 * Completed" never leaves a valid backup permanently misreported as
 * failed/stuck, and never leaves it ambiguous whether a remote object
 * belongs to a completed backup at all.
 *
 * This is deliberately a SEPARATE code path from RunBackupJob's normal
 * state machine (Backup::markAs() / BackupStatus::canTransitionTo()):
 * that state machine models what a *single running attempt* is allowed to
 * do to its own row. Reconciliation is an out-of-band administrative
 * operation — it is the one thing in this package allowed to move a row
 * from Failed/TimedOut/Aborted straight to Completed, because it is
 * acting on independent evidence (the remote object itself), not on the
 * job's own in-memory belief about what happened.
 *
 * Decision tree for reconcile($backup):
 *
 *   1. $backup->status === Completed          -> AlreadyCompleted (no-op)
 *   2. no `path` recorded                     -> NoRemoteObject
 *   3. remote object absent                   -> NoRemoteObject
 *   4. remote object present, 0 bytes, or size
 *      mismatches a previously recorded size  -> SizeMismatch
 *   5. remote object fails magic-byte check    -> VerificationFailed
 *   6. otherwise                               -> Finalized (row -> Completed)
 *
 * Only outcome 6 ever writes to the row, and it does so with an atomic
 * `UPDATE ... WHERE status != 'completed'` (see finalize()) so a
 * concurrently-finishing RunBackupJob attempt for the same row can never
 * be clobbered — whichever of the two finalizes first wins, and the other
 * becomes a no-op AlreadyCompleted.
 */
final class BackupReconciler
{
    public function __construct(
        private readonly BackupVerifier $verifier,
    ) {
    }

    /**
     * Non-completed rows worth inspecting: anything with a `path` that
     * isn't already `Completed`. Callers (ReconcileBackupsJob, the
     * `backup:reconcile` command) are expected to filter this further by
     * age — reconciling a backup that is still actively being written to
     * by a live worker is harmless (NoRemoteObject/SizeMismatch until the
     * write finishes) but wasteful.
     */
    public function findSuspectBackups(?CarbonImmutable $updatedBefore = null): Collection
    {
        return Backup::query()
            ->where('status', '!=', BackupStatus::Completed->value)
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->when(
                $updatedBefore !== null,
                fn ($query) => $query->where('updated_at', '<', $updatedBefore),
            )
            ->orderBy('id')
            ->get();
    }

    public function reconcile(Backup $backup): ReconciliationOutcome
    {
        if ($backup->status === BackupStatus::Completed) {
            return ReconciliationOutcome::AlreadyCompleted;
        }

        if (! is_string($backup->path) || $backup->path === '') {
            return ReconciliationOutcome::NoRemoteObject;
        }

        try {
            $remoteSize = $this->verifier->remoteSize($backup);
        } catch (\Throwable $e) {
            Log::warning('stream-backup: reconciliation could not inspect remote object', [
                'backup_id' => $backup->id,
                'path'      => $backup->path,
                'error'     => $e->getMessage(),
            ]);

            return ReconciliationOutcome::InspectionFailed;
        }

        if ($remoteSize === null) {
            return ReconciliationOutcome::NoRemoteObject;
        }

        if ($remoteSize <= 0) {
            return ReconciliationOutcome::SizeMismatch;
        }

        if ($backup->size !== null && (int) $backup->size !== $remoteSize) {
            return ReconciliationOutcome::SizeMismatch;
        }

        try {
            $probe = new Backup([
                'path'              => $backup->path,
                'disk'              => $backup->disk,
                'size'              => $backup->size ?? $remoteSize,
                'encryption_driver' => $backup->encryption_driver,
            ]);
            $this->verifier->verify($probe);
        } catch (\Throwable $e) {
            Log::warning('stream-backup: reconciliation found a remote object that failed verification', [
                'backup_id' => $backup->id,
                'path'      => $backup->path,
                'error'     => $e->getMessage(),
            ]);

            return ReconciliationOutcome::VerificationFailed;
        }

        return $this->finalize($backup, $remoteSize)
            ? ReconciliationOutcome::Finalized
            : ReconciliationOutcome::AlreadyCompleted;
    }

    /**
     * Deletes the remote object backing a row whose reconciliation outcome
     * was SizeMismatch or VerificationFailed — i.e. a confirmed-orphaned,
     * confirmed-not-a-valid-backup object. Never called automatically by
     * reconcile()/ReconcileBackupsJob: deleting remote data is destructive
     * enough to require an explicit, separate operator action (see the
     * `backup:reconcile --clean` command).
     *
     * Returns true if a delete was attempted and did not throw. A backup
     * that still has retries left will simply have this path overwritten
     * by its next attempt (every uploader truncates on open), so cleanup
     * is about reclaiming storage for rows that will never be retried
     * again, not about correctness.
     */
    public function cleanupOrphan(Backup $backup): bool
    {
        if (! is_string($backup->path) || $backup->path === '') {
            return false;
        }

        try {
            Storage::disk($backup->disk)->delete($backup->path);

            return true;
        } catch (\Throwable $e) {
            Log::warning('stream-backup: failed to delete orphaned remote object', [
                'backup_id' => $backup->id,
                'path'      => $backup->path,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Atomic compare-and-swap: only writes when the row is still not
     * Completed at the moment of the UPDATE. This is what makes
     * reconciliation safe to run concurrently with a live RunBackupJob
     * attempt for the same logical backup — it can never overwrite a
     * completion (by this or a later attempt) that landed first.
     */
    private function finalize(Backup $backup, int $remoteSize): bool
    {
        $now       = CarbonImmutable::now();
        $startedAt = $backup->started_at;
        $duration  = $startedAt !== null
            ? max(0, $now->getTimestamp() - $startedAt->getTimestamp())
            : null;

        $affected = Backup::query()
            ->whereKey($backup->id)
            ->where('status', '!=', BackupStatus::Completed->value)
            ->update([
                'status'        => BackupStatus::Completed->value,
                'size'          => $backup->size ?? $remoteSize,
                'error_message' => null,
                'finished_at'   => $now,
                'duration'      => $duration,
                'updated_at'    => $now,
            ]);

        if ($affected === 0) {
            return false;
        }

        $backup->refresh();

        Log::info('stream-backup: reconciled a backup to Completed from its remote object', [
            'backup_id' => $backup->id,
            'path'      => $backup->path,
        ]);

        return true;
    }
}
