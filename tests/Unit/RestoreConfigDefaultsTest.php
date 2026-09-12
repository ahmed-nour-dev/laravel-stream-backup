<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Guards the published config default directly (no DB needed): a restore SQL
 * error must be fatal unless an operator explicitly opts into best-effort
 * recovery. TableRestorer reads this same key — see RestoreRollbackTest's
 * MySQL-gated behavioural coverage for the executed-statement path.
 */
final class RestoreConfigDefaultsTest extends TestCase
{
    public function test_skip_on_error_defaults_to_false(): void
    {
        self::assertFalse(config('stream-backup.restore.skip_on_error'));
    }

    public function test_skip_on_error_can_be_explicitly_opted_into(): void
    {
        config(['stream-backup.restore.skip_on_error' => true]);

        self::assertTrue(config('stream-backup.restore.skip_on_error'));
    }

    public function test_skippable_error_codes_default_to_the_definer_privilege_code(): void
    {
        self::assertSame([1227], config('stream-backup.restore.skippable_error_codes'));
    }
}
