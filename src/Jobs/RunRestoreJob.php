<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Jobs;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Enums\RestoreStatus;
use Ahmednour\StreamBackup\Events\RestoreFailed;
use Ahmednour\StreamBackup\Events\RestoreStarting;
use Ahmednour\StreamBackup\Events\RestoreSuccessful;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Models\Restore;
use Ahmednour\StreamBackup\Pipelines\RestorePipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunRestoreJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Unlimited attempts so we can wait for the lock.
     */
    public int $tries = 0;

    /**
     * Fail on the first exception, because restores are destructive and non-idempotent.
     */
    public int $maxExceptions = 1;

    /**
     * Unlimited timeout so long restores aren't killed by the queue worker.
     */
    public int $timeout = 0;

    private ?Restore $restoreRecord = null;
    private bool $receivedSigterm = false;

    public function __construct(public RestoreContext $context)
    {
    }

    public function handle(RestorePipeline $pipeline): void
    {
        Log::debug("[Restore] Job started for backup ID {$this->context->backupId}. Context: ", ['context' => (array) $this->context]);
        $this->setupSignalHandlers();

        $lockName = 'stream-backup-slot';
        $lock = Cache::lock($lockName, 86400); // 24-hour max

        Log::debug("[Restore] Attempting to acquire lock: {$lockName}");
        if (! $lock->get()) {
            Log::debug("[Restore] Could not acquire lock, releasing job back to queue (delay 60s).");
            $this->release(60);
            return;
        }
        Log::debug("[Restore] Lock acquired: {$lockName}");

        try {
            if ($this->receivedSigterm) {
                Log::debug("[Restore] Job aborted due to SIGTERM before processing.");
                return;
            }

            Log::debug("[Restore] Fetching backup ID {$this->context->backupId}.");
            $backup = Backup::find($this->context->backupId);

            if ($backup === null) {
                throw new \InvalidArgumentException("Backup ID {$this->context->backupId} not found.");
            }

            if ($backup->status !== BackupStatus::Completed) {
                throw new \InvalidArgumentException("Backup ID {$this->context->backupId} is not completed (status: {$backup->status->value}).");
            }
            Log::debug("[Restore] Backup found and is completed.");

            $this->restoreRecord = Restore::create([
                'backup_id'        => $backup->id,
                'tenant_id'        => $this->context->tenantId,
                'database_name'    => $this->context->databaseName,
                'connection_name'  => $this->context->connectionName,
                'tables_requested' => $this->context->tables,
                'status'           => RestoreStatus::Pending,
                'started_at'       => now(),
            ]);

            Log::debug("[Restore] Created restore record ID {$this->restoreRecord->id}.");

            event(new RestoreStarting($this->context, $this->restoreRecord));

            Log::info("[Restore] Starting restore for backup {$backup->id} into connection {$this->context->connectionName}.");

            Log::debug("[Restore] Running RestorePipeline...");
            $result = $pipeline->run($this->context, $backup, function (RestoreStatus $status) {
                $this->restoreRecord->markAs($status);
            });
            Log::debug("[Restore] RestorePipeline completed.", ['result' => (array) $result]);

            // The restore target connection may have accumulated session
            // state (implicit DDL commits, FK check toggles, mysqldump
            // session variables) during the long restore. The Restore
            // model often shares the same PDO connection when the target
            // is the default connection, so disconnect to obtain a fresh,
            // clean connection for the final status update — same safety
            // measure used in handleFailure().
            try {
                DB::disconnect($this->context->connectionName);
            } catch (\Throwable) {
                // Best effort — the connection might already be broken.
            }

            // A restore that swallowed skippable SQL errors (skip_on_error
            // opt-in) is known-incomplete: it must never be recorded as an
            // indistinguishable Completed status alongside a fully clean
            // restore. See RestoreStatus::CompletedWithWarnings.
            $completionStatus = $result->skippedStatements > 0
                ? RestoreStatus::CompletedWithWarnings
                : RestoreStatus::Completed;

            $this->restoreRecord->markAs($completionStatus, [
                'tables_restored'     => $result->tablesRestored,
                'rows_affected'       => $result->totalRowsAffected,
                'skipped_statements'  => $result->skippedStatements,
                'finished_at'         => now(),
                'duration'            => (int) ceil($result->durationSeconds),
            ]);

            event(new RestoreSuccessful($this->context, $this->restoreRecord, $result));
            Log::info(sprintf(
                '[Restore] Successfully completed. Restored %d tables%s.',
                count($result->tablesRestored),
                $result->skippedStatements > 0 ? " ({$result->skippedStatements} statement(s) skipped)" : ''
            ));
        } catch (\Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        } finally {
            Log::debug("[Restore] Releasing lock: {$lockName}");
            $lock->release();
        }
    }

    private function handleFailure(\Throwable $e): void
    {
        Log::error("[Restore] Failed: {$e->getMessage()}", ['exception' => $e]);

        if ($this->restoreRecord !== null && ! $this->restoreRecord->status->isTerminal()) {
            $status = $this->receivedSigterm ? RestoreStatus::Aborted : RestoreStatus::Failed;

            // Only mark as aborted if it's not already a RestoreFailedException
            // (which means SQL failed, so the transaction was rolled back and it's a true failure).
            if ($e instanceof RestoreFailedException) {
                $status = RestoreStatus::Failed;
            }

            // The restore target connection may be in a locked or broken
            // state (e.g. mysqldump's LOCK TABLES, implicit DDL commits, or
            // a failed transaction that left the PDO connection unusable).
            // Disconnect it so the Restore model — which often shares the
            // same PDO connection — obtains a fresh, clean connection for
            // the status update.
            try {
                DB::disconnect($this->context->connectionName);
            } catch (\Throwable) {
                // Best effort — the connection might already be broken.
            }

            try {
                $this->restoreRecord->markAs($status, [
                    'finished_at'   => now(),
                    'error_message' => mb_substr($e->getMessage(), 0, 65535),
                ]);
            } catch (\Throwable $updateException) {
                // Never let a status-update failure mask the original error.
                Log::error('[Restore] Failed to update restore record: ' . $updateException->getMessage());
            }
        }

        event(new RestoreFailed($this->context, $e));
    }

    private function setupSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function (): void {
            Log::warning('[Restore] Received SIGTERM. Aborting restore gracefully.');
            $this->receivedSigterm = true;

            if ($this->restoreRecord !== null && ! $this->restoreRecord->status->isTerminal()) {
                try {
                    DB::disconnect($this->context->connectionName);
                } catch (\Throwable) {
                    // Best effort.
                }

                try {
                    $this->restoreRecord->markAs(RestoreStatus::Aborted, [
                        'finished_at'   => now(),
                        'error_message' => 'Aborted via SIGTERM',
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[Restore] Failed to mark as aborted: ' . $e->getMessage());
                }
            }

            exit(128 + SIGTERM);
        });
    }
}
