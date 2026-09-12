<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Support;

use Ahmednour\StreamBackup\Exceptions\IdleTimeoutExceededException;
use Ahmednour\StreamBackup\Exceptions\MaxRuntimeExceededException;
use Ahmednour\StreamBackup\Support\TimeoutGuard;
use PHPUnit\Framework\TestCase;

final class TimeoutGuardTest extends TestCase
{
    public function test_zero_max_runtime_disables_the_safeguard(): void
    {
        $guard = new TimeoutGuard(maxRuntimeSeconds: 0, idleTimeoutSeconds: 0);

        usleep(50_000);

        $guard->checkMaxRuntime();
        $guard->checkIdle();
        $guard->check();

        self::assertTrue(true, 'No exception should be thrown when both budgets are disabled.');
    }

    public function test_max_runtime_trips_once_elapsed_time_exceeds_the_budget(): void
    {
        // Sub-second budget so the test runs fast; TimeoutGuard measures in
        // whole seconds internally but a >=1s sleep with a 0s deadline is
        // avoided here — instead we exercise the boundary directly via a
        // tiny budget and a real (short) sleep.
        $guard = new TimeoutGuard(maxRuntimeSeconds: 1, idleTimeoutSeconds: 0);

        $guard->checkMaxRuntime(); // well within budget, must not throw

        sleep(2);

        $this->expectException(MaxRuntimeExceededException::class);
        $guard->checkMaxRuntime();
    }

    public function test_idle_timeout_trips_when_no_progress_is_marked(): void
    {
        $guard = new TimeoutGuard(maxRuntimeSeconds: 0, idleTimeoutSeconds: 1);

        $guard->checkIdle(); // fresh guard, must not throw

        sleep(2);

        $this->expectException(IdleTimeoutExceededException::class);
        $guard->checkIdle();
    }

    public function test_marking_progress_resets_the_idle_clock(): void
    {
        $guard = new TimeoutGuard(maxRuntimeSeconds: 0, idleTimeoutSeconds: 1);

        sleep(1);
        $guard->markProgress();

        // Immediately after markProgress(), even though 1s already passed
        // since construction, idle must not have tripped.
        $guard->checkIdle();

        self::assertTrue(true);
    }

    public function test_check_enforces_both_budgets(): void
    {
        $guard = new TimeoutGuard(maxRuntimeSeconds: 1, idleTimeoutSeconds: 100);

        sleep(2);

        $this->expectException(MaxRuntimeExceededException::class);
        $guard->check();
    }

    public function test_negative_values_are_treated_as_disabled(): void
    {
        $guard = new TimeoutGuard(maxRuntimeSeconds: -5, idleTimeoutSeconds: -5);

        $guard->check();

        self::assertTrue(true);
    }
}
