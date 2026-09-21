<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Performance\Support;

/**
 * Small helpers shared by the large-stream performance tests for reading
 * this PHP process's own memory footprint.
 */
trait MeasuresProcessMemory
{
    /**
     * PHP's real (system-allocated) peak memory usage so far, after forcing
     * a GC pass so a run's transient garbage doesn't inflate the reading.
     *
     * Note: memory_get_peak_usage(true) is a historical high-water mark for
     * the whole process and never decreases — callers must diff two
     * successive readings (taken before/after the operation under test) to
     * get that operation's own contribution, not an absolute per-call value.
     */
    private function peakMemoryBytes(): int
    {
        gc_collect_cycles();

        return memory_get_peak_usage(true);
    }

    /**
     * Best-effort resident set size (kB) of this PHP process, read from
     * /proc/self/status. Returns null when unavailable (non-Linux runners,
     * restricted containers) — callers should treat RSS assertions as
     * "where practical" and skip them when this returns null.
     */
    private function currentRssKb(): ?int
    {
        $status = @file_get_contents('/proc/self/status');

        if ($status === false) {
            return null;
        }

        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function formatMb(int|float $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
}
