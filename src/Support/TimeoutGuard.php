<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Exceptions\IdleTimeoutExceededException;
use Ahmednour\StreamBackup\Exceptions\MaxRuntimeExceededException;

/**
 * Wall-clock safeguard for long-running backups, polled cooperatively by
 * StreamPipeline (once per stream_select iteration) and RunBackupJob
 * (at phase boundaries outside the pipeline, e.g. before verification).
 *
 * Two independent budgets, both disabled by passing 0 (or a negative value):
 *
 *  - maxRuntimeSeconds: hard ceiling on total elapsed time since the guard
 *    was created, regardless of whether the pipeline is making progress.
 *  - idleTimeoutSeconds: resets every time markProgress() is called; trips
 *    when no forward progress has been observed for that long, catching a
 *    stuck database process, compressor, or upload even though the worker
 *    itself is still alive.
 *
 * Like the existing SIGTERM cancellation callback, this is a cooperative
 * check: it cannot interrupt a single blocking syscall (e.g. an in-flight
 * S3 uploadPart()) mid-call. It is checked at every stream_select loop
 * iteration and at phase boundaries, which bounds the worst case to one
 * blocking operation's natural duration.
 */
final class TimeoutGuard
{
    private readonly float $startedAt;

    private float $lastProgressAt;

    public function __construct(
        private readonly int $maxRuntimeSeconds = 0,
        private readonly int $idleTimeoutSeconds = 0,
    ) {
        $this->startedAt = microtime(true);
        $this->lastProgressAt = $this->startedAt;
    }

    /**
     * Record that the pipeline moved bytes since the last check, resetting
     * the idle-timeout clock.
     */
    public function markProgress(): void
    {
        $this->lastProgressAt = microtime(true);
    }

    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    /**
     * @throws MaxRuntimeExceededException
     */
    public function checkMaxRuntime(): void
    {
        if ($this->maxRuntimeSeconds <= 0) {
            return;
        }

        $elapsed = $this->elapsedSeconds();

        if ($elapsed >= $this->maxRuntimeSeconds) {
            throw new MaxRuntimeExceededException(sprintf(
                'Backup exceeded the maximum allowed runtime of %d second(s) (elapsed: %.1fs).',
                $this->maxRuntimeSeconds,
                $elapsed,
            ));
        }
    }

    /**
     * @throws IdleTimeoutExceededException
     */
    public function checkIdle(): void
    {
        if ($this->idleTimeoutSeconds <= 0) {
            return;
        }

        $idleFor = microtime(true) - $this->lastProgressAt;

        if ($idleFor >= $this->idleTimeoutSeconds) {
            throw new IdleTimeoutExceededException(sprintf(
                'Backup pipeline stalled: no progress for %.1f second(s) (idle timeout: %ds).',
                $idleFor,
                $this->idleTimeoutSeconds,
            ));
        }
    }

    /**
     * Convenience for call sites (the pipeline's stream_select loop) that
     * need both budgets enforced together.
     *
     * @throws MaxRuntimeExceededException
     * @throws IdleTimeoutExceededException
     */
    public function check(): void
    {
        $this->checkMaxRuntime();
        $this->checkIdle();
    }
}
