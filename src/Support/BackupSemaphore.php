<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Atomic concurrency cap for backup jobs.
 *
 * A cache lock guards a token→timestamp map of currently-held slots. Each
 * acquired slot is identified by an opaque token returned by acquire() and
 * surrendered via release($token).
 *
 * Per-slot lease ($slotTtl): a worker that is SIGKILL'd (OOM, supervisor -9)
 * before reaching release() does not permanently leak a slot — the next
 * acquire() lazily evicts any slot older than $slotTtl.
 *
 * Internal mutex (MUTEX_TTL): the cache lock only guards the
 * read-modify-write of the slot map and lasts milliseconds; it is deliberately
 * tiny and independent of the per-slot lease, so a worker that dies while
 * holding it cannot block other jobs for long.
 *
 * acquire()/release() MUST be paired in a try/finally.
 */
final class BackupSemaphore
{
    private const LOCK_KEY  = 'stream-backup:semaphore:lock';
    private const SLOTS_KEY = 'stream-backup:semaphore:slots';

    /**
     * TTL (seconds) of the internal cache lock guarding the slot map. Kept
     * small so a worker dying mid-critical-section self-heals quickly and so
     * a dead mutex holder never blocks other jobs for more than a few seconds.
     */
    private const MUTEX_TTL = 10;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $maxConcurrent,
        private readonly int $slotTtl = 21600,
    ) {
    }

    /**
     * Acquire a concurrency slot.
     *
     * @return string|null Opaque slot token, or null when every slot is busy.
     */
    public function acquire(): ?string
    {
        $lock = $this->cache->lock(self::LOCK_KEY, self::MUTEX_TTL);

        $result = $lock->block(5, function (): ?string {
            $slots = $this->evictStale($this->slots());

            if (count($slots) >= $this->maxConcurrent) {
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $slots[$token] = microtime(true);
            $this->cache->forever(self::SLOTS_KEY, $slots);

            return $token;
        });

        return is_string($result) ? $result : null;
    }

    /**
     * Release a previously acquired slot.
     *
     * Unknown/expired tokens are a safe no-op (the slot may already have been
     * evicted as stale by a concurrent acquire()). Never throws: release runs
     * in a finally block and must not mask the job's own exception; the
     * per-slot lease is the safety net if this call cannot obtain the mutex.
     */
    public function release(string $token): void
    {
        $lock = $this->cache->lock(self::LOCK_KEY, self::MUTEX_TTL);

        try {
            $lock->block(5, function () use ($token): void {
                $slots = $this->evictStale($this->slots());
                unset($slots[$token]);
                $this->cache->forever(self::SLOTS_KEY, $slots);
            });
        } catch (LockTimeoutException) {
            // Best-effort: the lease ($slotTtl) reclaims the slot anyway.
        }
    }

    /**
     * Number of currently-held (non-stale) slots.
     *
     * Read-only: filters the map in memory WITHOUT persisting the pruned map,
     * so this call can never undo a slot a concurrent acquire() just added or
     * resurrect one a concurrent release() just removed.
     */
    public function active(): int
    {
        return count($this->filterStale($this->slots()));
    }

    /**
     * @return array<string, float>
     */
    private function slots(): array
    {
        $raw = $this->cache->get(self::SLOTS_KEY, []);

        if (! is_array($raw)) {
            return [];
        }

        $cast = [];
        foreach ($raw as $token => $acquiredAt) {
            if (is_string($token) && (is_int($acquiredAt) || is_float($acquiredAt))) {
                $cast[$token] = (float) $acquiredAt;
            }
        }

        return $cast;
    }

    /**
     * Drop and persist the eviction of stale slots. MUST run under the lock.
     *
     * @param array<string, float> $slots
     * @return array<string, float> pruned map
     */
    private function evictStale(array $slots): array
    {
        $live = $this->filterStale($slots);

        if (count($live) !== count($slots)) {
            $this->cache->forever(self::SLOTS_KEY, $live);
        }

        return $live;
    }

    /**
     * @param array<string, float> $slots
     * @return array<string, float>
     */
    private function filterStale(array $slots): array
    {
        $cutoff = microtime(true) - $this->slotTtl;

        return array_filter(
            $slots,
            static fn (float $acquiredAt): bool => $acquiredAt > $cutoff,
        );
    }
}
