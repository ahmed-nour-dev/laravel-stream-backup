<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\DTOs;

use Illuminate\Support\Str;

final class BackupContext
{
    /**
     * Stable identifier for one logical backup operation, shared by every
     * queue attempt (retry) of the same dispatch. RunBackupJob uses it to
     * find-or-create a single `backups` row per operation instead of one
     * per attempt — since the job payload is re-used verbatim across
     * automatic retries, this value stays constant for the life of the
     * dispatch regardless of how many times `handle()` runs.
     */
    public readonly string $attemptGroupId;

    /**
     * @param array<int, string> $extraDumpFlags
     * @param int $timeoutSeconds Per-tenant override (seconds) for the
     *  overall max-runtime safeguard. 0 (default) falls back to
     *  `stream-backup.timeouts.max_runtime`; > 0 overrides it for this
     *  context only (e.g. a larger tenant that legitimately needs longer).
     *  Populated from the `timeout` key of a `stream-backup.tenants` entry
     *  by ConfigTenantResolver. See RunBackupJob and StreamPipeline.
     */
    public function __construct(
        public readonly int|string|null $tenantId,
        public readonly string $databaseName,
        public readonly string $connectionName,
        public readonly string $disk,
        public readonly int $timeoutSeconds = 0,
        public readonly array $extraDumpFlags = [],
        public readonly ?int $backupId = null,
        public readonly ?string $driver = null, // null = use global config / auto-detect
        ?string $attemptGroupId = null,
    ) {
        $this->attemptGroupId = $attemptGroupId ?? (string) Str::uuid();
    }

    public function withBackupId(int $id): self
    {
        return new self(
            tenantId:        $this->tenantId,
            databaseName:    $this->databaseName,
            connectionName:  $this->connectionName,
            disk:            $this->disk,
            timeoutSeconds:  $this->timeoutSeconds,
            extraDumpFlags:  $this->extraDumpFlags,
            backupId:        $id,
            driver:          $this->driver,
            attemptGroupId:  $this->attemptGroupId,
        );
    }
}
