<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\DTOs;

use Carbon\CarbonImmutable;

final class BackupMetadata
{
    public function __construct(
        public readonly int $backupId,
        public readonly int|string|null $tenantId,
        public readonly string $bucket,
        public readonly string $path,
        public readonly string $disk,
        public readonly CarbonImmutable $startedAt,
        public readonly string $contentType = 'application/gzip',
        public readonly ?int $attemptId = null,
    ) {
    }
}
