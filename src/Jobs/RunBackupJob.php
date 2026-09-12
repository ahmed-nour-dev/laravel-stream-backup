<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Jobs;

use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Exceptions\BackupTimeoutException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Pipelines\StreamPipeline;
use Ahmednour\StreamBackup\Support\BackupPathBuilder;
use Ahmednour\StreamBackup\Support\BackupSemaphore;
use Ahmednour\StreamBackup\Support\BackupVerifier;
use Ahmednour\StreamBackup\Events\BackupFailed as BackupFailedEvent;
use Ahmednour\StreamBackup\Events\BackupStarting;
use Ahmednour\StreamBackup\Events\BackupSuccessful;
use Ahmednour\StreamBackup\Support\PreflightChecker;
use Ahmednour\StreamBackup\Support\RetentionClassifier;
use Ahmednour\StreamBackup\Support\TimeoutGuard;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Long-running backup job. `$timeout = 0` is critical — Laravel's default
 * 60-second worker timeout would SIGKILL a multi-hour backup mid-stream.
 * See README for required queue worker invocation.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 0;

    public int $tries = 3;

    public function __construct(public BackupContext $context)
    {
    }

    public function handle(
        StreamPipeline $pipeline,
        BackupPathBuilder $pathBuilder,
        BackupSemaphore $semaphore,
        CompressionDriver $compression,
        DumperFactory $dumperFactory,
        EncryptionFactory $encryptionFactory,
        RetentionClassifier $classifier,
        BackupVerifier $verifier,
        PreflightChecker $preflightChecker,
        Config $config,
    ): void {
        // SIGTERM handling: Supervisor sends SIGTERM on graceful stop. We
        // mark the backup as Aborted and let `finally` release the slot.
        $aborted = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$aborted): void {
                $aborted = true;
            });
        }

        try {
            $token = $semaphore->acquire();
        } catch (LockTimeoutException $e) {
            // Mutex contention — re-queue gracefully instead of burning a retry.
            static::dispatch($this->context)->delay(now()->addMinutes(5));
            return;
        }

        if ($token === null) {
            // All slots busy — re-queue with a delay so we don't hammer Redis.
            static::dispatch($this->context)->delay(now()->addMinutes(5));
            return;
        }

        $backup = null;
        try {
            $startedAt  = CarbonImmutable::now();
            // Independent of Laravel's queue $timeout (0/unlimited above):
            // a per-tenant BackupContext::$timeoutSeconds override wins over
            // the global stream-backup.timeouts.max_runtime default.
            $timeoutGuard = new TimeoutGuard(
                maxRuntimeSeconds: $this->context->timeoutSeconds > 0
                    ? $this->context->timeoutSeconds
                    : (int) $config->get('stream-backup.timeouts.max_runtime', 0),
                idleTimeoutSeconds: (int) $config->get('stream-backup.timeouts.idle_timeout', 0),
            );
            $dumper     = $dumperFactory->make($this->context->driver);
            $encryption = $encryptionFactory->make();
            $backup = Backup::create([
                'tenant_id'          => $this->context->tenantId,
                'database_name'      => $this->context->databaseName,
                'connection_name'    => $this->context->connectionName,
                'disk'               => $this->context->disk,
                'status'             => BackupStatus::Pending->value,
                'compression_driver' => $compression->name(),
                'dump_driver'        => $dumper->name(),
                'encryption_driver'  => $encryption->name() !== 'none' ? $encryption->name() : null,
                'started_at'         => $startedAt,
            ]);

            BackupStarting::dispatch($this->context, $backup);
            $preflightChecker->check();

            $extension = $encryption->name() !== 'none' ? 'sql.gz.enc' : 'sql.gz';
            $path      = $pathBuilder->build($this->context, $startedAt, $extension);
            $backup->forceFill([
                'path'           => $path,
                'retention_tier' => $classifier->classify($startedAt)->value,
            ])->save();
            $backup->markAs(BackupStatus::Dumping);

            $bucket = (string) ($config->get("filesystems.disks.{$this->context->disk}.bucket")
                ?? $this->context->disk);

            $metadata = new BackupMetadata(
                backupId:    (int) $backup->id,
                tenantId:    $this->context->tenantId,
                bucket:      $bucket,
                path:        $path,
                disk:        $this->context->disk,
                startedAt:   $startedAt,
                contentType: $encryption->name() !== 'none'
                    ? 'application/octet-stream'
                    : 'application/gzip',
            );

            $backup->markAs(BackupStatus::Uploading);
            // NOTE: must be `use (&$aborted)`, not an arrow fn — arrow
            // functions capture by value at creation time, so they would
            // never observe the SIGTERM handler flipping $aborted later.
            $result = $pipeline->run(
                $this->context,
                $metadata,
                cancellationRequested: static function () use (&$aborted): bool {
                    return $aborted;
                },
                timeoutGuard: $timeoutGuard,
            );

            if ($aborted) {
                throw new \RuntimeException('Backup aborted by SIGTERM.');
            }

            $backup->forceFill([
                'size'              => $result->sizeBytes,
                'checksum'          => $result->checksum,
                'upload_speed_mbps' => $result->speedMbps(),
            ])->save();

            // Re-checked here (status is still Uploading, which allows a
            // TimedOut transition) because verification is not itself
            // covered by the pipeline's per-iteration timeout checks.
            $timeoutGuard->checkMaxRuntime();

            if ((bool) $config->get('stream-backup.verify_after_upload', true)) {
                $backup->markAs(BackupStatus::Verifying);
                $verifier->verify($backup);
            }

            $finishedAt = CarbonImmutable::now();
            $backup->markAs(BackupStatus::Completed, [
                'finished_at' => $finishedAt,
                'duration'    => $finishedAt->getTimestamp() - $startedAt->getTimestamp(),
            ]);

            BackupSuccessful::dispatch($this->context, $backup);
        } catch (\Throwable $e) {
            $status = match (true) {
                $aborted                             => BackupStatus::Aborted,
                $e instanceof BackupTimeoutException => BackupStatus::TimedOut,
                default                               => BackupStatus::Failed,
            };

            if ($backup !== null) {
                try {
                    $backup->markAs($status, [
                        'error_message' => $e->getMessage(),
                        'finished_at'   => now(),
                    ]);
                } catch (\Throwable) {
                    $backup->forceFill([
                        'status'        => $status->value,
                        'error_message' => $e->getMessage(),
                        'finished_at'   => now(),
                    ])->save();
                }
            }

            if (! $aborted) {
                BackupFailedEvent::dispatch($this->context, $e);
            }

            throw $e;
        } finally {
            $semaphore->release($token);
        }
    }
}
