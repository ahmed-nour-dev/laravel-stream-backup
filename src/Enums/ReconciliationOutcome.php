<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Enums;

/**
 * What BackupReconciler::reconcile() found and did for one `backups` row.
 * See BackupReconciler's class docblock for the full decision tree.
 */
enum ReconciliationOutcome: string
{
    /** Already `Completed` — nothing to do. Re-running reconcile() is a no-op. */
    case AlreadyCompleted = 'already_completed';

    /** A valid remote object existed for a non-completed row; the row was finalized. */
    case Finalized = 'finalized';

    /** No remote object at `path` — genuinely never finished uploading. */
    case NoRemoteObject = 'no_remote_object';

    /** A remote object exists but its size doesn't match the recorded one (or is 0 bytes). */
    case SizeMismatch = 'size_mismatch';

    /** A remote object exists with the right size but fails magic-byte verification. */
    case VerificationFailed = 'verification_failed';

    /** The remote object couldn't be inspected (network/auth error) — left untouched. */
    case InspectionFailed = 'inspection_failed';

    /**
     * A remote object was found but is not trustworthy enough to adopt as
     * the logical backup's completion — a human (or an explicit cleanup
     * pass) should decide whether to delete it or re-run verification.
     */
    public function isOrphanCandidate(): bool
    {
        return $this === self::SizeMismatch || $this === self::VerificationFailed;
    }
}
