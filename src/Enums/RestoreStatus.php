<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Enums;

enum RestoreStatus: string
{
    case Pending       = 'pending';
    case Downloading   = 'downloading';
    case Decrypting    = 'decrypting';
    case Decompressing = 'decompressing';
    case Parsing       = 'parsing';
    case Importing     = 'importing';
    case Completed     = 'completed';

    /**
     * A best-effort restore (restore.skip_on_error = true) that finished
     * without throwing, but swallowed one or more skippable SQL errors.
     * Distinct from Completed so a caller can never mistake a
     * known-incomplete restore for a fully clean one. See RestoreResult's
     * $skippedStatements.
     */
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed        = 'failed';
    case Aborted       = 'aborted';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending       => in_array($next, [self::Downloading, self::Failed, self::Aborted], true),
            self::Downloading   => in_array($next, [self::Decrypting, self::Decompressing, self::Failed, self::Aborted], true),
            self::Decrypting    => in_array($next, [self::Decompressing, self::Failed, self::Aborted], true),
            self::Decompressing => in_array($next, [self::Parsing, self::Failed, self::Aborted], true),
            self::Parsing       => in_array($next, [self::Importing, self::Failed, self::Aborted], true),
            self::Importing     => in_array($next, [self::Completed, self::CompletedWithWarnings, self::Failed, self::Aborted], true),
            default             => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::CompletedWithWarnings, self::Failed, self::Aborted => true,
            default => false,
        };
    }
}
