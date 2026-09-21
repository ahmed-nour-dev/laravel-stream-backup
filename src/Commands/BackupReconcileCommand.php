<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Commands;

use Ahmednour\StreamBackup\Jobs\ReconcileBackupsJob;
use Ahmednour\StreamBackup\Support\BackupReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Operator-facing entry point for the reconciliation mechanism described
 * in BackupReconciler: runs synchronously by default (unlike `backup:cleanup`)
 * because it's typically invoked ad hoc, while diagnosing a specific stuck
 * or seemingly-failed backup, and the operator wants the outcome table
 * immediately rather than watching queue logs.
 */
class BackupReconcileCommand extends Command
{
    protected $signature = 'backup:reconcile
        {--grace=30 : Minutes a non-completed backup must be idle before it is inspected}
        {--queue : Dispatch to the queue instead of running synchronously}
        {--clean : Delete the remote object for any row whose outcome is size_mismatch or verification_failed}';

    protected $description = 'Reconcile backups stuck between "remote object written" and "database finalized", and report orphaned remote objects.';

    public function handle(BackupReconciler $reconciler): int
    {
        $grace = max(0, (int) $this->option('grace'));

        if ($this->option('queue')) {
            $queue      = config('stream-backup.schedule.queue');
            $connection = config('stream-backup.schedule.connection');

            ReconcileBackupsJob::dispatch($grace)->onConnection($connection)->onQueue($queue);
            $this->info('Reconciliation job dispatched.');
            return self::SUCCESS;
        }

        $threshold = CarbonImmutable::now()->subMinutes($grace);
        $backups   = $reconciler->findSuspectBackups($threshold);

        if ($backups->isEmpty()) {
            $this->info('Nothing to reconcile.');
            return self::SUCCESS;
        }

        $rows = [];
        $cleaned = 0;

        foreach ($backups as $backup) {
            $outcome = $reconciler->reconcile($backup);

            $cleanedThisRow = false;
            if ($this->option('clean') && $outcome->isOrphanCandidate()) {
                $cleanedThisRow = $reconciler->cleanupOrphan($backup);
                if ($cleanedThisRow) {
                    $cleaned++;
                }
            }

            $rows[] = [
                $backup->id,
                $backup->attempt_group_id ?? '-',
                $backup->path,
                $outcome->value,
                $cleanedThisRow ? 'deleted' : ($outcome->isOrphanCandidate() ? 'kept (pass --clean)' : '-'),
            ];
        }

        $this->table(['Backup ID', 'Attempt Group', 'Path', 'Outcome', 'Remote Object'], $rows);

        $this->info(sprintf(
            'Inspected %d backup(s); %d deleted remote object(s).',
            count($rows),
            $cleaned,
        ));

        return self::SUCCESS;
    }
}
