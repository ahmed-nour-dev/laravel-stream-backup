<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Jobs;

use Ahmednour\StreamBackup\Support\BackupReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled safety net for the crash window between "the upload finished"
 * and "the `backups` row was marked Completed": periodically re-inspects
 * every non-completed row whose remote object might actually be done, and
 * finalizes it if so. See BackupReconciler for the full decision tree.
 *
 * `graceMinutes` guards against reconciling a backup that is still being
 * actively written by a live worker — only rows whose `updated_at` is
 * older than the grace period are considered stale enough to inspect.
 */
class ReconcileBackupsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly ?int $graceMinutes = null)
    {
    }

    public function handle(BackupReconciler $reconciler, Config $config): void
    {
        $grace = $this->graceMinutes
            ?? max(0, (int) $config->get('stream-backup.schedule.reconcile.grace_minutes', 30));

        $threshold = CarbonImmutable::now()->subMinutes($grace);

        /** @var array<string, int> $tally */
        $tally = [];

        $reconciler->findSuspectBackups($threshold)->each(function ($backup) use ($reconciler, &$tally): void {
            $outcome = $reconciler->reconcile($backup);
            $tally[$outcome->value] = ($tally[$outcome->value] ?? 0) + 1;
        });

        if ($tally !== []) {
            Log::info('stream-backup: reconciliation sweep complete', $tally);
        }
    }
}
