<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by BackupCleanupJob when one or more expired backups could not be
 * deleted from their disk. The matching `backups` rows are kept so the
 * next run retries them.
 */
class BackupCleanupFailed
{
    use Dispatchable;

    /**
     * @param  list<array{backup_id: int|string, disk: ?string, path: string, retention_tier: string, tenant_id: ?string, error: string}>  $failures
     */
    public function __construct(
        public readonly array $failures,
    ) {
    }
}
