<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\DTOs;

final class BackupContext
{
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
    ) {
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
        );
    }
}
