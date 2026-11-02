<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Storage;

class PreflightChecker
{
    public function __construct(
        private readonly UploadDriver $uploader,
        private readonly Config $config,
    ) {
    }

    public function check(): void
    {
        $this->checkCleanupDiskResolves();
        $this->uploader->preflight();
    }

    /**
     * S3 uploads go through the AWS SDK directly, but retention cleanup
     * deletes through the Laravel disk. Without league/flysystem-aws-s3-v3
     * that disk can't be built, so backups would succeed while cleanup
     * fails on every run. Fail the first backup instead.
     */
    private function checkCleanupDiskResolves(): void
    {
        if ($this->config->get('stream-backup.destination.driver') !== 's3') {
            return;
        }

        $disk = (string) $this->config->get('stream-backup.default_disk', 'spaces');

        try {
            Storage::disk($disk);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Pre-flight check failed: filesystem disk '{$disk}' cannot be built ({$e->getMessage()}). "
                . 'Retention cleanup deletes backups through this disk. For S3 disks, run: '
                . 'composer require league/flysystem-aws-s3-v3 "^3.0"',
                0,
                $e,
            );
        }
    }
}
