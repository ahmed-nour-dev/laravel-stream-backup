<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Ahmednour\StreamBackup\Restore\TableRestorer;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Unit-level coverage of TableRestorer's pure helper logic plus the driver
 * gate. The full shadow-table rollback round-trip is exercised against a real
 * MySQL server in tests/Feature/RestoreRollbackTest (gated on env vars).
 */
final class TableRestorerShadowTest extends TestCase
{
    public function test_shadow_name_prefixes_short_table(): void
    {
        self::assertSame('_sbr_users', TableRestorer::shadowName('users'));
    }

    public function test_shadow_name_is_deterministic_for_the_same_input(): void
    {
        self::assertSame(
            TableRestorer::shadowName('orders'),
            TableRestorer::shadowName('orders')
        );
    }

    public function test_shadow_name_keeps_readable_name_at_64_char_boundary(): void
    {
        // '_sbr_' (5) + 59 chars = 64 exactly → still under the MySQL limit,
        // so the readable name is preserved (no hash fallback).
        $name = str_repeat('b', 59);

        $shadow = TableRestorer::shadowName($name);

        self::assertSame('_sbr_' . $name, $shadow);
        self::assertSame(64, strlen($shadow));
    }

    public function test_shadow_name_falls_back_to_stable_hash_when_over_64_chars(): void
    {
        // '_sbr_' (5) + 60 chars = 65 → exceeds the 64-char identifier limit,
        // so a stable hash suffix keeps the name bounded and collision-free.
        $name = str_repeat('a', 60);

        $shadow = TableRestorer::shadowName($name);

        self::assertSame('_sbr_' . substr(sha1($name), 0, 16), $shadow);
        self::assertLessThanOrEqual(64, strlen($shadow));
    }

    public function test_shadow_name_hash_fallback_does_not_collide_for_distinct_long_names(): void
    {
        self::assertNotSame(
            TableRestorer::shadowName(str_repeat('a', 60)),
            TableRestorer::shadowName(str_repeat('b', 60))
        );
    }

    public function test_restore_rejects_non_mysql_driver_with_a_clear_error(): void
    {
        // The default Testbench connection is SQLite (:memory:). Shadow-table
        // rollback relies on MySQL-specific SQL (RENAME TABLE, FK-check
        // handling, InnoDB rename-follows-FK semantics), so restore() must
        // fail fast instead of running half the logic against a driver it
        // cannot safely roll back. (Pre-fix this path silently emitted
        // "SET FOREIGN_KEY_CHECKS" into SQLite and had no rollback at all.)
        $restorer = new TableRestorer($this->app->make(Config::class));

        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessageMatches('/MySQL only/');

        $restorer->restore([], 'testing', microtime(true));
    }
}
