<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Jobs;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Enums\RetentionTier;
use Ahmednour\StreamBackup\Events\BackupCleanupFailed;
use Ahmednour\StreamBackup\Exceptions\CleanupFailedException;
use Ahmednour\StreamBackup\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes completed backups that exceed the per-tier keep limit.
 *
 * A backup whose remote object can't be deleted keeps its row, so the next
 * run retries it. Every such failure is logged; once all tiers have been
 * processed the job fires BackupCleanupFailed and throws, so the run lands
 * in failed_jobs instead of passing silently.
 */
class BackupCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Retrying won't fix a broken disk, and failed deletes are retried on
     * the next scheduled run anyway.
     */
    public int $tries = 1;

    /** @var list<array{backup_id: int|string, disk: ?string, path: string, retention_tier: string, tenant_id: ?string, error: string}> */
    private array $failures = [];

    private ?\Throwable $firstError = null;

    private int $attempted = 0;

    public function handle(Config $config): void
    {
        $this->failures   = [];
        $this->firstError = null;
        $this->attempted  = 0;

        foreach (RetentionTier::cases() as $tier) {
            $limit = (int) $config->get("stream-backup.retention.{$tier->value}", 0);
            if ($limit <= 0) {
                continue;
            }

            $tenantIds = Backup::query()
                ->where('status', BackupStatus::Completed->value)
                ->where('retention_tier', $tier->value)
                ->select('tenant_id')
                ->distinct()
                ->pluck('tenant_id');

            foreach ($tenantIds as $tenantId) {
                $this->pruneTierForTenant($tier, $tenantId, $limit);
            }
        }

        if ($this->failures !== []) {
            $this->reportFailures();
        }
    }

    private function pruneTierForTenant(RetentionTier $tier, ?string $tenantId, int $keep): void
    {
        $query = Backup::query()
            ->where('status', BackupStatus::Completed->value)
            ->where('retention_tier', $tier->value);

        $query = $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);

        $stale = $query->orderByDesc('finished_at')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($stale as $backup) {
            if (is_string($backup->path) && $backup->path !== '') {
                $this->attempted++;

                $error = $this->deleteRemote($backup);
                if ($error !== null) {
                    $this->recordFailure($backup, $tier, $tenantId, $error);
                    continue;
                }
            }

            $backup->delete();
        }
    }

    /**
     * Returns null on success, or the reason the delete failed. A disk with
     * `'throw' => false` reports failure by returning false instead of
     * throwing, so both cases count as a failure.
     */
    private function deleteRemote(Backup $backup): ?\Throwable
    {
        try {
            if (Storage::disk($backup->disk)->delete($backup->path) === false) {
                return new \RuntimeException(sprintf(
                    "Disk '%s' reported that '%s' could not be deleted.",
                    (string) $backup->disk,
                    $backup->path,
                ));
            }

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function recordFailure(Backup $backup, RetentionTier $tier, ?string $tenantId, \Throwable $error): void
    {
        $failure = [
            'backup_id'      => $backup->id,
            'disk'           => $backup->disk,
            'path'           => $backup->path,
            'retention_tier' => $tier->value,
            'tenant_id'      => $tenantId,
            'error'          => $error->getMessage(),
        ];

        Log::warning('stream-backup: failed to delete expired backup', $failure);

        $this->failures[] = $failure;
        $this->firstError ??= $error;
    }

    private function reportFailures(): void
    {
        event(new BackupCleanupFailed($this->failures));

        throw new CleanupFailedException(
            sprintf(
                'Retention cleanup could not delete %d of %d expired backup(s); their rows were kept. First error: %s',
                count($this->failures),
                $this->attempted,
                $this->failures[0]['error'],
            ),
            0,
            $this->firstError,
        );
    }
}
