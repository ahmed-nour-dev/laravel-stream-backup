<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Exceptions;

/**
 * Thrown when a backup's total wall-clock runtime exceeds
 * `stream-backup.timeouts.max_runtime` (or a per-tenant BackupContext
 * override). This is independent of Laravel's own queue worker timeout,
 * which RunBackupJob deliberately disables ($timeout = 0) because backup
 * duration is dictated by database size, not by a fixed worker budget.
 */
class MaxRuntimeExceededException extends BackupTimeoutException
{
}
