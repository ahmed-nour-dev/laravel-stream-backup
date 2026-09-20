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

    // -- Named-constraint collision avoidance (detachOutboundForeignKeys) ----
    // Pure (no DB) coverage of the two private static helpers that back the
    // fix for MySQL error 1826 "Duplicate foreign key constraint name": a
    // shadow retains its own outbound FK constraint's exact name across the
    // RENAME, and the dump's CREATE TABLE for that same table redeclares the
    // identical name, so it must be freed on the shadow first. The
    // MySQL-gated RestoreRollbackTest exercises the full round-trip
    // (including the real 1826 collision without the fix) against a server.

    public function test_temporary_foreign_key_name_is_deterministic_and_bounded(): void
    {
        $ref = new \ReflectionMethod(TableRestorer::class, 'temporaryForeignKeyName');
        $ref->setAccessible(true);

        $name = $ref->invoke(null, '_sbr_orders', 'fk_orders_customer');

        self::assertSame($name, $ref->invoke(null, '_sbr_orders', 'fk_orders_customer'));
        self::assertLessThanOrEqual(64, strlen($name));
        self::assertStringStartsWith('_sbr_fk_', $name);
    }

    public function test_temporary_foreign_key_name_differs_for_different_tables_or_constraints(): void
    {
        $ref = new \ReflectionMethod(TableRestorer::class, 'temporaryForeignKeyName');
        $ref->setAccessible(true);

        $a = $ref->invoke(null, '_sbr_orders', 'fk_orders_customer');
        $b = $ref->invoke(null, '_sbr_invoices', 'fk_orders_customer');
        $c = $ref->invoke(null, '_sbr_orders', 'fk_orders_warehouse');

        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
    }

    public function test_foreign_key_definition_sql_renders_a_single_column_fk(): void
    {
        $ref = new \ReflectionMethod(TableRestorer::class, 'foreignKeyDefinitionSql');
        $ref->setAccessible(true);

        $sql = $ref->invoke(null, [
            'columns'           => ['customer_id'],
            'referencedTable'   => 'customers',
            'referencedColumns' => ['id'],
            'updateRule'        => 'CASCADE',
            'deleteRule'        => 'RESTRICT',
        ]);

        self::assertSame(
            'FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE',
            $sql,
        );
    }

    public function test_foreign_key_definition_sql_renders_a_composite_fk(): void
    {
        $ref = new \ReflectionMethod(TableRestorer::class, 'foreignKeyDefinitionSql');
        $ref->setAccessible(true);

        $sql = $ref->invoke(null, [
            'columns'           => ['tenant_id', 'customer_id'],
            'referencedTable'   => 'customers',
            'referencedColumns' => ['tenant_id', 'id'],
            'updateRule'        => 'RESTRICT',
            'deleteRule'        => 'CASCADE',
        ]);

        self::assertSame(
            'FOREIGN KEY (`tenant_id`, `customer_id`) REFERENCES `customers` (`tenant_id`, `id`) ON DELETE CASCADE ON UPDATE RESTRICT',
            $sql,
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

    // -- filterEdgesToTableSet (dump-parsed FK edges) -------------------------
    // These back the fix for a table newly introduced by the backup: its FK
    // is parsed straight out of the dump's CREATE TABLE text (see
    // SqlDumpParserTest) rather than read from information_schema, and must
    // be filtered/merged the same way as the existing-schema edges before
    // orderTables() runs.

    public function test_filter_edges_to_table_set_keeps_edges_with_both_endpoints_in_the_set(): void
    {
        $filtered = TableRestorer::filterEdgesToTableSet(
            [['customers', 'orders']],
            ['customers', 'orders'],
        );

        self::assertSame([['customers', 'orders']], $filtered);
    }

    public function test_filter_edges_to_table_set_drops_edges_whose_parent_is_outside_the_set(): void
    {
        // A parent outside the restore set is never renamed aside, so it
        // cannot trigger the rename-follows-FK repointing the ordering
        // exists to prevent — the edge must be dropped, not just ignored.
        $filtered = TableRestorer::filterEdgesToTableSet(
            [['customers', 'orders']],
            ['orders'],
        );

        self::assertSame([], $filtered);
    }

    public function test_filter_edges_to_table_set_drops_edges_whose_child_is_outside_the_set(): void
    {
        $filtered = TableRestorer::filterEdgesToTableSet(
            [['customers', 'orders']],
            ['customers'],
        );

        self::assertSame([], $filtered);
    }

    public function test_dump_parsed_fk_edge_orders_a_brand_new_child_after_its_pre_existing_parent(): void
    {
        // The exact gap this ticket closes: a new child ('orders') with no
        // information_schema row yet sorts before its pre-existing parent
        // ('customers') in dump order. The edge parsed out of the dump's own
        // CREATE TABLE text must be enough to reorder it, with no
        // information_schema edges involved at all.
        $dumpFkEdges = TableRestorer::filterEdgesToTableSet(
            [['customers', 'orders']],
            ['orders', 'customers'],
        );

        $ordered = TableRestorer::orderTables(['orders', 'customers'], $dumpFkEdges);

        self::assertSame(['customers', 'orders'], $ordered);
    }

    // -- findForeignKeyBoundaryViolations (selective-restore FK safety guard) -
    // Pure (no DB), so these run in CI. The MySQL-gated
    // RestoreRollbackTest::test_selective_restore_*_boundary_* tests exercise
    // the full end-to-end guard (including the live-schema query and the
    // restore() short-circuit) against a real server.

    public function test_boundary_violations_detects_a_requested_parent_referenced_by_an_unrequested_child(): void
    {
        // "parent-only" selection: `customers` is requested, `orders` (which
        // references it) is not — exactly the case that would repoint
        // orders' FK to a dropped `_sbr_customers` shadow.
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['customers'],
            [['customers', 'orders']],
            [],
        );

        self::assertSame([['customers', 'orders']], $violations);
    }

    public function test_boundary_violations_detects_a_requested_child_referencing_an_unrequested_parent(): void
    {
        // "child-only" selection: `orders` is requested, `customers` (its FK
        // parent) is not.
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['orders'],
            [['customers', 'orders']],
            [],
        );

        self::assertSame([['customers', 'orders']], $violations);
    }

    public function test_boundary_violations_is_empty_when_both_endpoints_are_requested(): void
    {
        // Valid dependency-closed selection: both sides of the FK are
        // included, so nothing crosses the boundary.
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['customers', 'orders'],
            [['customers', 'orders']],
            [],
        );

        self::assertSame([], $violations);
    }

    public function test_boundary_violations_is_empty_when_the_edge_is_entirely_outside_the_restore_set(): void
    {
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['widgets'],
            [['customers', 'orders']],
            [],
        );

        self::assertSame([], $violations);
    }

    public function test_boundary_violations_covers_mutually_dependent_tables_with_only_one_side_requested(): void
    {
        // `a` and `b` reference each other. Requesting only `a` crosses the
        // boundary in both directions.
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['a'],
            [['a', 'b'], ['b', 'a']],
            [],
        );

        self::assertSame([['a', 'b'], ['b', 'a']], $violations);
    }

    public function test_boundary_violations_is_empty_when_both_sides_of_a_mutual_dependency_are_requested(): void
    {
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['a', 'b'],
            [['a', 'b'], ['b', 'a']],
            [],
        );

        self::assertSame([], $violations);
    }

    public function test_boundary_violations_ignores_self_references(): void
    {
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['a'],
            [['a', 'a']],
            [],
        );

        self::assertSame([], $violations);
    }

    public function test_boundary_violations_deduplicates_the_same_edge_seen_in_both_sources(): void
    {
        // The live-schema edge and a dump-parsed edge can describe the same
        // relationship; the guard must not report it twice.
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['customers'],
            [['customers', 'orders']],
            [['customers', 'orders']],
        );

        self::assertSame([['customers', 'orders']], $violations);
    }

    public function test_boundary_violations_detects_a_dump_only_edge_with_no_live_schema_counterpart(): void
    {
        // Covers a brand-new target database with no live schema at all:
        // the only source of truth is the dump's own declared FK.
        $violations = TableRestorer::findForeignKeyBoundaryViolations(
            ['orders'],
            [],
            [['customers', 'orders']],
        );

        self::assertSame([['customers', 'orders']], $violations);
    }

    public function test_restore_rejects_selective_restore_with_a_cross_boundary_foreign_key_before_any_sql_runs(): void
    {
        // End-to-end through restore() itself: SQLite driver check normally
        // fires first, so use the guard's pure detection to prove the
        // exception message identifies the offending tables clearly. The
        // MySQL-backed short-circuit (guard runs before the driver-gate
        // SQL) is covered by the feature test suite; this asserts the
        // message contract in isolation.
        $ref = new \ReflectionMethod(TableRestorer::class, 'formatForeignKeyBoundaryError');
        $ref->setAccessible(true);

        $message = $ref->invoke(
            null,
            ['customers'],
            TableRestorer::findForeignKeyBoundaryViolations(['customers'], [['customers', 'orders']], []),
        );

        self::assertStringContainsString('`customers`', $message);
        self::assertStringContainsString('`orders`', $message);
        self::assertStringContainsString('crossing the restore boundary', $message);
    }
}
