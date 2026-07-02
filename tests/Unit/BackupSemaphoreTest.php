<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Support\BackupSemaphore;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Contracts\Cache\Repository;

final class BackupSemaphoreTest extends TestCase
{
    private const SLOTS_KEY = 'stream-backup:semaphore:slots';

    private function makeSemaphore(int $max, int $slotTtl = 21600): BackupSemaphore
    {
        return new BackupSemaphore(
            cache:         $this->app->make(Repository::class),
            maxConcurrent: $max,
            slotTtl:       $slotTtl,
        );
    }

    public function test_acquire_returns_distinct_tokens_under_limit(): void
    {
        $semaphore = $this->makeSemaphore(2);

        $a = $semaphore->acquire();
        $b = $semaphore->acquire();

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a, $b);
        self::assertSame(2, $semaphore->active());
    }

    public function test_acquire_returns_null_when_all_slots_busy(): void
    {
        $semaphore = $this->makeSemaphore(1);

        $token = $semaphore->acquire();
        self::assertNotNull($token);

        self::assertNull($semaphore->acquire());
        self::assertSame(1, $semaphore->active());
    }

    public function test_release_by_token_frees_the_slot(): void
    {
        $semaphore = $this->makeSemaphore(2);

        $a = $semaphore->acquire();
        $b = $semaphore->acquire();
        self::assertSame(2, $semaphore->active());

        $semaphore->release($a);
        self::assertSame(1, $semaphore->active());

        $semaphore->release($b);
        self::assertSame(0, $semaphore->active());
    }

    public function test_release_allows_new_acquire_after_hitting_limit(): void
    {
        $semaphore = $this->makeSemaphore(1);

        $token = $semaphore->acquire();
        self::assertNotNull($token);
        self::assertNull($semaphore->acquire());

        $semaphore->release($token);

        $newToken = $semaphore->acquire();
        self::assertNotNull($newToken);
        self::assertNotSame($token, $newToken);
    }

    public function test_release_with_unknown_token_is_a_safe_noop(): void
    {
        $semaphore = $this->makeSemaphore(2);

        // A token that was never acquired (e.g. a release() after the slot
        // already expired, or a double-release) must never throw — release()
        // runs in a finally block and must not mask the job's own exception.
        $semaphore->release('token-that-was-never-acquired');

        self::assertSame(0, $semaphore->active());
    }

    public function test_stale_slot_is_evicted_on_acquire_so_a_crashed_worker_does_not_leak(): void
    {
        $cache = $this->app->make(Repository::class);
        $semaphore = new BackupSemaphore(
            cache: $cache,
            maxConcurrent: 1,
            slotTtl: 21600,
        );

        // Simulate a worker that crashed (SIGKILL/OOM) before reaching
        // release(): a slot whose lease has expired (older than slotTtl).
        $deadToken = 'dead-worker-token';
        $cache->forever(self::SLOTS_KEY, [$deadToken => microtime(true) - 30000]);

        // The semaphore is at capacity (1/1) but the only slot is stale, so
        // acquire() must evict it and succeed rather than returning null.
        $token = $semaphore->acquire();

        self::assertNotNull($token);
        self::assertNotSame($deadToken, $token);
        self::assertSame(1, $semaphore->active());

        $slots = $cache->get(self::SLOTS_KEY, []);
        self::assertArrayNotHasKey($deadToken, $slots);
        self::assertArrayHasKey($token, $slots);
    }

    public function test_active_is_read_only_and_does_not_persist_pruning(): void
    {
        $cache = $this->app->make(Repository::class);
        $semaphore = new BackupSemaphore(
            cache: $cache,
            maxConcurrent: 2,
            slotTtl: 21600,
        );

        // A stale slot that active() must logically NOT count, but which
        // active() is forbidden from pruning (no mutex is held on a read).
        $deadToken = 'dead-worker-token';
        $cache->forever(self::SLOTS_KEY, [$deadToken => microtime(true) - 30000]);

        self::assertSame(0, $semaphore->active());

        // The underlying map is untouched: the stale slot is still there,
        // proving active() performed no read-modify-write. Only acquire()/
        // release() (which hold the mutex) are allowed to prune the map —
        // an unlocked active() write-back could resurrect/lose slots racing
        // against a concurrent acquire()/release().
        $retrieved = $cache->get(self::SLOTS_KEY, []);
        self::assertArrayHasKey($deadToken, $retrieved);
        self::assertCount(1, $retrieved);
    }
}
