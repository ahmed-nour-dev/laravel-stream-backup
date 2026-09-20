<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Ahmednour\StreamBackup\Restore\TableRestorer;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * Verifies TableRestorer's shadow-table rollback guarantee against a real
 * MySQL server.
 *
 * Skipped unless a test MySQL database is reachable via the standard env
 * vars (same gate as StreamPipelineSmokeTest):
 *   STREAM_BACKUP_TEST_HOST / _PORT / _USER / _PASSWORD / _DATABASE
 *
 * Two cases:
 *  - Case A: a non-skippable failure on the SECOND table must roll back EVERY
 *    table processed so far (proving rollbackShadows), AND the FK constraint
 *    and secondary index must survive the rename round-trip (InnoDB repoints
 *    child FK metadata to follow a parent RENAME — assert it lands back on the
 *    real table, not a dangling _sbr_* name).
 *  - Case B: a skippable statement, with skip_on_error EXPLICITLY enabled,
 *    leaves the restore known-incomplete, so the _sbr_* shadow must be
 *    RETAINED (not dropped) so the last-known-good data survives for manual
 *    recovery.
 *
 * Plus a fail-fast-by-default regression test: with no skip_on_error
 * override at all, a statement error that would otherwise match
 * skippable_error_codes must still abort and roll back — restore.skip_on_error
 * defaults to false (see config/stream-backup.php).
 */
final class RestoreRollbackTest extends TestCase
{
    /** @var array<int, string> tables + their shadow names to drop between/after runs */
    private const CLEANUP_TABLES = [
        'orders', 'customers', 'widgets', 'accessories',
        '_sbr_orders', '_sbr_customers', '_sbr_widgets', '_sbr_accessories',
    ];

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A dedicated MySQL connection used only by these feature tests. It
        // is not the default, so the package's SQLite-backed unit harness is
        // unaffected.
        $app['config']->set('database.connections.mysql_test', [
            'driver'   => 'mysql',
            'host'     => env('STREAM_BACKUP_TEST_HOST'),
            'port'     => (int) env('STREAM_BACKUP_TEST_PORT', 3306),
            'database' => env('STREAM_BACKUP_TEST_DATABASE'),
            'username' => env('STREAM_BACKUP_TEST_USER'),
            'password' => env('STREAM_BACKUP_TEST_PASSWORD', ''),
            'charset'  => 'utf8mb4',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlConfigured()) {
            try {
                $this->cleanSchema();
            } catch (\Throwable) {
                // Best-effort: never let cleanup mask a test failure.
            }
        }

        parent::tearDown();
    }

    public function test_case_a_cross_table_failure_rolls_back_and_preserves_fk_and_indexes(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        // --- Seed originals (parent + child with FK + secondary index) -----
        $db->unprepared('CREATE TABLE `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB');

        $db->unprepared('CREATE TABLE `orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL,
            `total` DECIMAL(10,2) NOT NULL,
            `status` VARCHAR(20) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_orders_status` (`status`),
            CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
        ) ENGINE=InnoDB');

        $db->insert('INSERT INTO `customers` (`id`, `name`) VALUES (?, ?)', [1, 'original-customer']);
        $db->insert('INSERT INTO `orders` (`id`, `customer_id`, `total`, `status`) VALUES (?, ?, ?, ?)', [1, 1, 100.00, 'pending']);

        // --- Build a dump that fails on the SECOND table -------------------
        // customers block replays cleanly (DROP + CREATE + INSERT).
        $customersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `customers`;\n"
            . "CREATE TABLE `customers` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `name` VARCHAR(100) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `customers` (`id`, `name`) VALUES (1, 'restored-customer');\n"
        );

        // orders block succeeds up to the final INSERT, which uses a literal
        // string for a DECIMAL column → MySQL error 1366, which is NOT in the
        // default skippable_error_codes list, so it throws and triggers
        // rollbackShadows() for every table processed so far.
        $ordersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `orders`;\n"
            . "CREATE TABLE `orders` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `customer_id` INT UNSIGNED NOT NULL,\n"
            . "  `total` DECIMAL(10,2) NOT NULL,\n"
            . "  `status` VARCHAR(20) NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  KEY `idx_orders_status` (`status`),\n"
            . "  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `orders` (`id`, `customer_id`, `total`, `status`) VALUES (1, 1, 200.00, 'shipped');\n"
            . "INSERT INTO `orders` (`id`, `customer_id`, `total`, `status`) VALUES (2, 1, 'not-a-decimal', 'x');\n"
        );

        $restorer = new TableRestorer($this->app->make(Config::class));

        try {
            $restorer->restore(
                ['customers' => $customersBlock, 'orders' => $ordersBlock],
                'mysql_test',
                microtime(true),
            );
            self::fail('Expected RestoreFailedException from the malformed orders INSERT.');
        } catch (RestoreFailedException $e) {
            // Expected: the 1366 error is non-skippable, so rollback fired.
        }

        // --- Assert both tables reverted to their originals ----------------
        $customer = $db->selectOne('SELECT `name` FROM `customers` WHERE `id` = 1');
        self::assertNotNull($customer, 'customers must exist after rollback');
        self::assertSame('original-customer', $customer->name);

        $order = $db->selectOne('SELECT `total`, `status` FROM `orders` WHERE `id` = 1');
        self::assertNotNull($order, 'orders must exist after rollback');
        self::assertSame('pending', $order->status);
        self::assertEquals(100.00, (float) $order->total);

        // No leftover shadow tables: rollback renamed them back / dropped the
        // partial new tables. (CONCAT avoids LIKE wildcard-escape headaches.)
        $shadows = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() '
            . 'AND table_name LIKE CONCAT(?, ?, ?)',
            ['_', 'sbr_', '%']
        );
        self::assertNotNull($shadows);
        self::assertSame(0, (int) $shadows->n, 'No _sbr_* shadow tables should remain after rollback.');

        // --- FK integrity survived the rename round-trip -------------------
        // InnoDB repoints a child FK to follow a parent RENAME; rolling
        // customers back through _sbr_customers -> customers must leave
        // orders.customer_id referencing `customers`, not a dangling
        // _sbr_customers. Assert the constraint still resolves to customers.
        $fk = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.referential_constraints '
            . 'WHERE constraint_schema = DATABASE() '
            . 'AND table_name = ? '
            . 'AND referenced_table_name = ?',
            ['orders', 'customers']
        );
        self::assertNotNull($fk);
        self::assertSame(1, (int) $fk->n, 'FK on orders must still reference customers after rollback.');

        // --- Index integrity survived the rename round-trip ----------------
        $idx = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['orders', 'idx_orders_status']
        );
        self::assertNotNull($idx);
        self::assertGreaterThanOrEqual(1, (int) $idx->n, 'Index idx_orders_status must survive the rename round-trip.');

        // --- The FK is live (enforced), not just present as metadata -------
        // FK_CHECKS is back on (restore re-enables it in finally). A dangling
        // customer_id insert must be rejected now.
        $db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
        $enforced = false;
        try {
            $db->insert(
                'INSERT INTO `orders` (`customer_id`, `total`, `status`) VALUES (?, ?, ?)',
                [999999, 1.00, 'fk-probe']
            );
        } catch (\Throwable) {
            $enforced = true;
        }
        self::assertTrue($enforced, 'FK must be enforced (reject a dangling customer_id) after rollback.');
    }

    public function test_default_skip_on_error_is_false_and_fails_fast_even_for_a_configured_skippable_code(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        // Original data to protect.
        $db->unprepared('CREATE TABLE `widgets` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sku` VARCHAR(50) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_widgets_sku` (`sku`)
        ) ENGINE=InnoDB');
        $db->insert('INSERT INTO `widgets` (`id`, `sku`) VALUES (?, ?)', [1, 'ORIGINAL-SKU']);

        // Duplicate-key (1062) is in skippable_error_codes, but skip_on_error
        // itself is left at its default (false, per config/stream-backup.php)
        // — the error must still be fatal. This is the core acceptance
        // criterion: a restore SQL error fails by default regardless of
        // which codes skippable_error_codes lists.
        $this->app['config']->set('stream-backup.restore.skippable_error_codes', [1062]);
        self::assertFalse(
            (bool) $this->app['config']->get('stream-backup.restore.skip_on_error'),
            'Precondition: skip_on_error must default to false.'
        );

        $widgetsBlock = $this->buffer(
            "DROP TABLE IF EXISTS `widgets`;\n"
            . "CREATE TABLE `widgets` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `sku` VARCHAR(50) NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  UNIQUE KEY `uk_widgets_sku` (`sku`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `widgets` (`id`, `sku`) VALUES (1, 'RESTORED-SKU');\n"
            // Duplicate primary key (id=1) → MySQL error 1062. Skippable by
            // code, but skip_on_error=false means it must throw instead of
            // being swallowed.
            . "INSERT INTO `widgets` (`id`, `sku`) VALUES (1, 'DUP-SKU');\n"
        );

        $restorer = new TableRestorer($this->app->make(Config::class));

        try {
            $restorer->restore(
                ['widgets' => $widgetsBlock],
                'mysql_test',
                microtime(true),
            );
            self::fail('Expected RestoreFailedException: skip_on_error defaults to false.');
        } catch (RestoreFailedException $e) {
            // Expected: fail-fast by default.
        }

        // Rollback must have fired: the original survives under its real
        // name, and no _sbr_* shadow is left behind (this is a genuine
        // failure, not a known-incomplete best-effort success).
        $widget = $db->selectOne('SELECT `sku` FROM `widgets` WHERE `id` = 1');
        self::assertNotNull($widget, 'widgets must exist after rollback');
        self::assertSame('ORIGINAL-SKU', $widget->sku);

        $shadows = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() '
            . 'AND table_name LIKE CONCAT(?, ?, ?)',
            ['_', 'sbr_', '%']
        );
        self::assertNotNull($shadows);
        self::assertSame(0, (int) $shadows->n, 'No _sbr_* shadow tables should remain after a fail-fast rollback.');
    }

    public function test_case_b_explicit_skip_on_error_retains_shadow_tables_for_manual_recovery(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        // Original data to protect.
        $db->unprepared('CREATE TABLE `widgets` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sku` VARCHAR(50) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_widgets_sku` (`sku`)
        ) ENGINE=InnoDB');
        $db->insert('INSERT INTO `widgets` (`id`, `sku`) VALUES (?, ?)', [1, 'ORIGINAL-SKU']);

        // Explicitly opt into best-effort mode — skip_on_error now defaults
        // to false, so this test must turn it on itself. Also make
        // duplicate-key (1062) a SKIPPABLE error for this run only, so the
        // skip-on-error path can be exercised deterministically without the
        // SUPER/DEFINER (1227) privilege dance.
        $this->app['config']->set('stream-backup.restore.skip_on_error', true);
        $this->app['config']->set('stream-backup.restore.skippable_error_codes', [1062]);

        $widgetsBlock = $this->buffer(
            "DROP TABLE IF EXISTS `widgets`;\n"
            . "CREATE TABLE `widgets` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `sku` VARCHAR(50) NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  UNIQUE KEY `uk_widgets_sku` (`sku`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `widgets` (`id`, `sku`) VALUES (1, 'RESTORED-SKU');\n"
            // Duplicate primary key (id=1) → MySQL error 1062, now skippable
            // → swallowed, skippedCount becomes 1, restore "succeeds" but is
            // known-incomplete → shadows are RETAINED, not dropped.
            . "INSERT INTO `widgets` (`id`, `sku`) VALUES (1, 'DUP-SKU');\n"
        );

        $restorer = new TableRestorer($this->app->make(Config::class));

        $result = $restorer->restore(
            ['widgets' => $widgetsBlock],
            'mysql_test',
            microtime(true),
        );

        // The restore completed (no throw) but recorded a skipped statement.
        self::assertSame(1, $result->skippedStatements);
        self::assertSame(['widgets'], $result->tablesRestored);

        // The new (partial) table is live under its real name.
        $new = $db->selectOne('SELECT `sku` FROM `widgets` WHERE `id` = 1');
        self::assertNotNull($new);
        self::assertSame('RESTORED-SKU', $new->sku);

        // The shadow (original) is RETAINED — skip-on-error means the restore
        // is known-incomplete, so dropping the last-known-good original would
        // destroy the safety net the operator most needs.
        $shadow = $db->selectOne('SELECT `sku` FROM `_sbr_widgets` WHERE `id` = 1');
        self::assertNotNull($shadow, 'Shadow table _sbr_widgets must be retained when statements were skipped.');
        self::assertSame('ORIGINAL-SKU', $shadow->sku);
    }

    public function test_case_c_child_sorting_before_parent_keeps_fk_intact_on_clean_restore(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        // 'accessories' (child) sorts alphabetically BEFORE 'widgets'
        // (parent) — exactly mysqldump's default ordering, and exactly the
        // case Case A's customers/orders names accidentally avoid. Without
        // dependency ordering the child is recreated before the parent is
        // renamed aside; the parent's later rename then repoints the child's
        // fresh FK to _sbr_widgets, which dropShadows() drops at the end →
        // permanently orphaned FK metadata on a supposedly "clean" full restore.
        $db->unprepared('CREATE TABLE `widgets` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB');

        $db->unprepared('CREATE TABLE `accessories` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `widget_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_accessories_widget` FOREIGN KEY (`widget_id`) REFERENCES `widgets` (`id`)
        ) ENGINE=InnoDB');

        $db->insert('INSERT INTO `widgets` (`id`, `name`) VALUES (?, ?)', [1, 'original-widget']);
        $db->insert('INSERT INTO `accessories` (`id`, `widget_id`, `name`) VALUES (?, ?, ?)', [1, 1, 'original-accessory']);

        // Dump order is alphabetical (mysqldump default): accessories before widgets.
        $accessoriesBlock = $this->buffer(
            "DROP TABLE IF EXISTS `accessories`;\n"
            . "CREATE TABLE `accessories` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `widget_id` INT UNSIGNED NOT NULL,\n"
            . "  `name` VARCHAR(100) NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  CONSTRAINT `fk_accessories_widget` FOREIGN KEY (`widget_id`) REFERENCES `widgets` (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `accessories` (`id`, `widget_id`, `name`) VALUES (1, 1, 'restored-accessory');\n"
        );

        $widgetsBlock = $this->buffer(
            "DROP TABLE IF EXISTS `widgets`;\n"
            . "CREATE TABLE `widgets` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `name` VARCHAR(100) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `widgets` (`id`, `name`) VALUES (1, 'restored-widget');\n"
        );

        // Alphabetical (mysqldump) order — the ordering that exposes the bug.
        $tableBlocks = [
            'accessories' => $accessoriesBlock,
            'widgets'     => $widgetsBlock,
        ];

        $restorer = new TableRestorer($this->app->make(Config::class));

        $result = $restorer->restore($tableBlocks, 'mysql_test', microtime(true));

        // The dependency sort must have reordered parent-before-child.
        self::assertSame(['widgets', 'accessories'], $result->tablesRestored,
            'Restore must process the FK parent (widgets) before the child (accessories).');

        // Data restored.
        $widget = $db->selectOne('SELECT `name` FROM `widgets` WHERE `id` = 1');
        self::assertNotNull($widget);
        self::assertSame('restored-widget', $widget->name);

        $accessory = $db->selectOne('SELECT `name` FROM `accessories` WHERE `id` = 1');
        self::assertNotNull($accessory);
        self::assertSame('restored-accessory', $accessory->name);

        // No leftover shadow tables (clean restore drops them).
        $shadows = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name LIKE CONCAT(?, ?, ?)',
            ['_', 'sbr_', '%']
        );
        self::assertNotNull($shadows);
        self::assertSame(0, (int) $shadows->n);

        // THE key assertion: accessories' FK must resolve to `widgets` (the
        // fresh parent), NOT to a dropped _sbr_widgets shadow. Without the
        // dependency-ordering fix this would be 0 (dangling on _sbr_widgets).
        $fk = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.referential_constraints '
            . 'WHERE constraint_schema = DATABASE() '
            . 'AND table_name = ? AND referenced_table_name = ?',
            ['accessories', 'widgets']
        );
        self::assertNotNull($fk);
        self::assertSame(1, (int) $fk->n, 'FK on accessories must resolve to widgets (not a dropped _sbr_* shadow) after a clean restore.');

        // And no FK may reference a leftover _sbr_* shadow name.
        $dangling = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.referential_constraints '
            . 'WHERE constraint_schema = DATABASE() AND referenced_table_name LIKE CONCAT(?, ?, ?)',
            ['_', 'sbr_', '%']
        );
        self::assertNotNull($dangling);
        self::assertSame(0, (int) $dangling->n, 'No FK may reference a _sbr_* shadow name after a clean restore.');

        // Live FK enforcement: a dangling widget_id insert must be rejected
        // (proves the FK is enforced against the real widgets, not just
        // present as metadata).
        $db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
        $enforced = false;
        try {
            $db->insert(
                'INSERT INTO `accessories` (`widget_id`, `name`) VALUES (?, ?)',
                [999999, 'fk-probe']
            );
        } catch (\Throwable) {
            $enforced = true;
        }
        self::assertTrue($enforced, 'FK on accessories must be enforced (reject a dangling widget_id) after a clean restore.');
    }

    public function test_case_d_new_fk_child_table_orders_after_pre_existing_parent_via_dump_parsed_edge(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        // 'customers' is the ONLY table that pre-exists — 'orders' is brand
        // new, introduced by this backup for the first time. Because it
        // doesn't exist yet, information_schema.referential_constraints has
        // no row for it, so the existing-schema query alone (pre-fix) cannot
        // see that it depends on customers. This is the exact residual gap
        // described in TableRestorer's class docblock.
        $db->unprepared('CREATE TABLE `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB');
        $db->insert('INSERT INTO `customers` (`id`, `name`) VALUES (?, ?)', [1, 'original-customer']);

        $customersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `customers`;\n"
            . "CREATE TABLE `customers` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `name` VARCHAR(100) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `customers` (`id`, `name`) VALUES (1, 'restored-customer');\n"
        );

        $ordersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `orders`;\n"
            . "CREATE TABLE `orders` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `customer_id` INT UNSIGNED NOT NULL,\n"
            . "  `total` DECIMAL(10,2) NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `orders` (`id`, `customer_id`, `total`) VALUES (1, 1, 50.00);\n"
        );

        // Deliberately handed to restore() with the new child BEFORE its
        // pre-existing parent — the exact ordering that orphans the FK
        // without the dump-parsed edge below. In production this comes from
        // SqlDumpParser::getForeignKeys() alongside its parse() call.
        $tableBlocks = [
            'orders'    => $ordersBlock,
            'customers' => $customersBlock,
        ];
        $dumpFkEdges = [['customers', 'orders']];

        $restorer = new TableRestorer($this->app->make(Config::class));

        $result = $restorer->restore($tableBlocks, 'mysql_test', microtime(true), $dumpFkEdges);

        // The dump-parsed edge must have reordered parent-before-child even
        // though information_schema has no constraint row for `orders` yet.
        self::assertSame(['customers', 'orders'], $result->tablesRestored,
            'Restore must process the pre-existing parent (customers) before the brand-new child (orders).');

        $order = $db->selectOne('SELECT `total` FROM `orders` WHERE `id` = 1');
        self::assertNotNull($order);
        self::assertEquals(50.00, (float) $order->total);

        // No leftover shadow tables (clean restore drops them).
        $shadows = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name LIKE CONCAT(?, ?, ?)',
            ['_', 'sbr_', '%']
        );
        self::assertNotNull($shadows);
        self::assertSame(0, (int) $shadows->n);

        // THE key assertion: orders' FK must resolve to `customers` (the
        // fresh parent), not a dropped _sbr_customers shadow.
        $fk = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.referential_constraints '
            . 'WHERE constraint_schema = DATABASE() '
            . 'AND table_name = ? AND referenced_table_name = ?',
            ['orders', 'customers']
        );
        self::assertNotNull($fk);
        self::assertSame(1, (int) $fk->n, 'FK on orders must resolve to customers (not a dropped _sbr_* shadow) after a clean restore.');

        // Live FK enforcement: a dangling customer_id insert must be rejected.
        $db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
        $enforced = false;
        try {
            $db->insert(
                'INSERT INTO `orders` (`customer_id`, `total`) VALUES (?, ?)',
                [999999, 1.00]
            );
        } catch (\Throwable) {
            $enforced = true;
        }
        self::assertTrue($enforced, 'FK on orders must be enforced (reject a dangling customer_id) after restore.');
    }

    // -- Selective-restore FK-boundary guard ---------------------------------
    // Ahmednour/laravel-stream-backup#19: restoring a table that has a live
    // foreign-key relationship with a table OUTSIDE the requested set must
    // fail fast, before any shadow table is created or any statement runs —
    // in either direction (parent-only or child-only), and regardless of
    // shadow tables being the mechanism that would otherwise orphan the FK.

    public function test_selective_restore_rejects_parent_only_selection_referenced_by_an_unrequested_live_child(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        $db->unprepared('CREATE TABLE `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB');

        $db->unprepared('CREATE TABLE `orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
        ) ENGINE=InnoDB');

        $db->insert('INSERT INTO `customers` (`id`, `name`) VALUES (?, ?)', [1, 'original-customer']);
        $db->insert('INSERT INTO `orders` (`id`, `customer_id`) VALUES (?, ?)', [1, 1]);

        // Only `customers` (the FK PARENT) is requested; `orders` (the live
        // child) is not part of this restore at all.
        $customersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `customers`;\n"
            . "CREATE TABLE `customers` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `name` VARCHAR(100) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `customers` (`id`, `name`) VALUES (1, 'restored-customer');\n"
        );

        $restorer = new TableRestorer($this->app->make(Config::class));

        try {
            $restorer->restore(
                ['customers' => $customersBlock],
                'mysql_test',
                microtime(true),
                [],
                true,
            );
            self::fail('Expected RestoreFailedException: customers is an FK parent of the unrequested orders table.');
        } catch (RestoreFailedException $e) {
            self::assertStringContainsString('customers', $e->getMessage());
            self::assertStringContainsString('orders', $e->getMessage());
        }

        // The guard must fire BEFORE any destructive work: original data
        // intact, no shadow table ever created.
        $customer = $db->selectOne('SELECT `name` FROM `customers` WHERE `id` = 1');
        self::assertNotNull($customer);
        self::assertSame('original-customer', $customer->name);

        $shadows = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name LIKE CONCAT(?, ?, ?)',
            ['_', 'sbr_', '%']
        );
        self::assertNotNull($shadows);
        self::assertSame(0, (int) $shadows->n, 'The guard must fire before any shadow table is created.');
    }

    public function test_selective_restore_rejects_child_only_selection_referencing_an_unrequested_live_parent(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        $db->unprepared('CREATE TABLE `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB');

        $db->unprepared('CREATE TABLE `orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
        ) ENGINE=InnoDB');

        $db->insert('INSERT INTO `customers` (`id`, `name`) VALUES (?, ?)', [1, 'original-customer']);
        $db->insert('INSERT INTO `orders` (`id`, `customer_id`) VALUES (?, ?)', [1, 1]);

        // Only `orders` (the FK CHILD) is requested; `customers` (its live
        // parent) is not part of this restore.
        $ordersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `orders`;\n"
            . "CREATE TABLE `orders` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `customer_id` INT UNSIGNED NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `orders` (`id`, `customer_id`) VALUES (1, 1);\n"
        );

        $restorer = new TableRestorer($this->app->make(Config::class));

        try {
            $restorer->restore(
                ['orders' => $ordersBlock],
                'mysql_test',
                microtime(true),
                [],
                true,
            );
            self::fail('Expected RestoreFailedException: orders references the unrequested customers table.');
        } catch (RestoreFailedException $e) {
            self::assertStringContainsString('customers', $e->getMessage());
            self::assertStringContainsString('orders', $e->getMessage());
        }

        $order = $db->selectOne('SELECT `customer_id` FROM `orders` WHERE `id` = 1');
        self::assertNotNull($order);
        self::assertSame(1, (int) $order->customer_id);

        $shadows = $db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name LIKE CONCAT(?, ?, ?)',
            ['_', 'sbr_', '%']
        );
        self::assertNotNull($shadows);
        self::assertSame(0, (int) $shadows->n, 'The guard must fire before any shadow table is created.');
    }

    public function test_selective_restore_allows_a_dependency_closed_selection_of_parent_and_child(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        $db->unprepared('CREATE TABLE `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB');

        $db->unprepared('CREATE TABLE `orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
        ) ENGINE=InnoDB');

        $db->insert('INSERT INTO `customers` (`id`, `name`) VALUES (?, ?)', [1, 'original-customer']);
        $db->insert('INSERT INTO `orders` (`id`, `customer_id`) VALUES (?, ?)', [1, 1]);

        // Both sides of the FK are requested — dependency-closed, so the
        // guard must NOT block this even though it's a selective restore.
        $customersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `customers`;\n"
            . "CREATE TABLE `customers` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `name` VARCHAR(100) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `customers` (`id`, `name`) VALUES (1, 'restored-customer');\n"
        );

        $ordersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `orders`;\n"
            . "CREATE TABLE `orders` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `customer_id` INT UNSIGNED NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `orders` (`id`, `customer_id`) VALUES (1, 1);\n"
        );

        $restorer = new TableRestorer($this->app->make(Config::class));

        $result = $restorer->restore(
            ['orders' => $ordersBlock, 'customers' => $customersBlock],
            'mysql_test',
            microtime(true),
            [],
            true,
        );

        self::assertSame(['customers', 'orders'], $result->tablesRestored);

        $customer = $db->selectOne('SELECT `name` FROM `customers` WHERE `id` = 1');
        self::assertNotNull($customer);
        self::assertSame('restored-customer', $customer->name);

        $order = $db->selectOne('SELECT `customer_id` FROM `orders` WHERE `id` = 1');
        self::assertNotNull($order);
        self::assertSame(1, (int) $order->customer_id);
    }

    public function test_non_selective_restore_skips_the_boundary_guard_even_with_a_cross_boundary_relationship(): void
    {
        $this->skipUnlessMysqlAvailable();
        $this->cleanSchema();

        $db = $this->db();

        $db->unprepared('CREATE TABLE `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB');

        $db->unprepared('CREATE TABLE `orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
        ) ENGINE=InnoDB');

        $db->insert('INSERT INTO `customers` (`id`, `name`) VALUES (?, ?)', [1, 'original-customer']);
        $db->insert('INSERT INTO `orders` (`id`, `customer_id`) VALUES (?, ?)', [1, 1]);

        $customersBlock = $this->buffer(
            "DROP TABLE IF EXISTS `customers`;\n"
            . "CREATE TABLE `customers` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `name` VARCHAR(100) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n"
            . "INSERT INTO `customers` (`id`, `name`) VALUES (1, 'restored-customer');\n"
        );

        $restorer = new TableRestorer($this->app->make(Config::class));

        // $selective defaults to false: acceptance criterion "Full restores
        // continue to work unchanged" — the guard must not run at all, even
        // though `orders` (live, untouched) has an FK on `customers`.
        $result = $restorer->restore(
            ['customers' => $customersBlock],
            'mysql_test',
            microtime(true),
        );

        self::assertSame(['customers'], $result->tablesRestored);

        $customer = $db->selectOne('SELECT `name` FROM `customers` WHERE `id` = 1');
        self::assertNotNull($customer);
        self::assertSame('restored-customer', $customer->name);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function mysqlConfigured(): bool
    {
        return ! empty(env('STREAM_BACKUP_TEST_HOST'))
            && ! empty(env('STREAM_BACKUP_TEST_USER'))
            && ! empty(env('STREAM_BACKUP_TEST_DATABASE'));
    }

    private function skipUnlessMysqlAvailable(): void
    {
        if (! $this->mysqlConfigured()) {
            self::markTestSkipped('STREAM_BACKUP_TEST_HOST/_USER/_DATABASE are not configured for the restore rollback test.');
        }

        try {
            $this->db()->getPdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('Could not connect to the MySQL test database: ' . $e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    private function db()
    {
        return DB::connection('mysql_test');
    }

    private function cleanSchema(): void
    {
        $db = $this->db();
        $db->unprepared('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::CLEANUP_TABLES as $table) {
            $db->unprepared("DROP TABLE IF EXISTS `{$table}`");
        }
        $db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return resource A rewound php://temp stream ready for TableRestorer.
     */
    private function buffer(string $sql)
    {
        $buf = fopen('php://temp', 'r+b');
        fwrite($buf, $sql);
        rewind($buf);

        return $buf;
    }
}
