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

    // -- FK-dependency ordering (TableRestorer::orderTables) -----------------
    // These are pure (no DB), so they run in CI. The MySQL-gated
    // RestoreRollbackTest::test_case_c_... exercises the full end-to-end
    // ordering against information_schema.

    public function test_order_tables_puts_fk_parent_before_child_even_when_child_sorts_first(): void
    {
        // Dump (alphabetical) order: child before parent — the ordering that
        // would orphan the child's FK without the dependency sort.
        $ordered = TableRestorer::orderTables(
            ['accessories', 'widgets'],
            [['widgets', 'accessories']],
        );

        self::assertSame(['widgets', 'accessories'], $ordered);
    }

    public function test_order_tables_preserves_dump_order_when_there_are_no_fk_relationships(): void
    {
        $ordered = TableRestorer::orderTables(['zebra', 'alpha', 'mango'], []);

        self::assertSame(['zebra', 'alpha', 'mango'], $ordered);
    }

    public function test_order_tables_is_stable_for_already_ordered_input(): void
    {
        // Parent already precedes child — output must be unchanged.
        $ordered = TableRestorer::orderTables(
            ['widgets', 'accessories'],
            [['widgets', 'accessories']],
        );

        self::assertSame(['widgets', 'accessories'], $ordered);
    }

    public function test_order_tables_ignores_self_references(): void
    {
        // A table referencing itself imposes no cross-table ordering.
        $ordered = TableRestorer::orderTables(['a', 'b'], [['a', 'a']]);

        self::assertSame(['a', 'b'], $ordered);
    }

    public function test_order_tables_orders_multi_level_chains(): void
    {
        // C → B → A (C references B, B references A): emit A, B, C regardless
        // of the shuffled input order.
        $ordered = TableRestorer::orderTables(
            ['c', 'a', 'b'],
            [['b', 'c'], ['a', 'b']],
        );

        self::assertSame(['a', 'b', 'c'], $ordered);
    }

    public function test_order_tables_falls_back_to_dump_order_on_a_cycle_instead_of_deadlocking(): void
    {
        // Mutual FK (A → B and B → A) cannot be ordered. Must not loop
        // forever; falls back to insertion order.
        $ordered = TableRestorer::orderTables(
            ['a', 'b'],
            [['a', 'b'], ['b', 'a']],
        );

        self::assertSame(['a', 'b'], $ordered);
    }
}
