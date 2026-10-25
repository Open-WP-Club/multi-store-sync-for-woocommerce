<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * uninstall.php irreversibly drops tables and deletes options, so it must run
 * only when WordPress calls it AND the admin opted in, and it must stay in sync
 * with the tables the plugin really creates.
 */
class UninstallTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $queries = [];

    /** @var list<string> */
    private array $unscheduled = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options     = [];
        $this->queries     = [];
        $this->unscheduled = [];

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'multi-store-sync-for-woocommerce/wc-multi-store-sync.php');
        }

        Functions\when('get_option')->alias(fn(string $key, mixed $default = false) => $this->options[$key] ?? $default);
        Functions\when('as_unschedule_all_actions')->alias(function (string $hook) {
            $this->unscheduled[] = $hook;
        });

        $test = $this;
        global $wpdb;
        $wpdb = new class ($test) extends wpdb {
            public string $prefix  = 'wp_';
            public string $options = 'wp_options';

            public function __construct(private object $test)
            {
            }
            public function query(string $query): int|false
            {
                $this->test->recordQuery($query);
                return 1;
            }
        };
    }

    /** Public so the anonymous wpdb can reach it. */
    public function recordQuery(string $sql): void
    {
        $this->queries[] = $sql;
    }

    private function runUninstall(): void
    {
        include dirname(__DIR__, 3) . '/uninstall.php';
    }

    /** @return list<string> Table names from every DROP TABLE statement. */
    private function droppedTables(): array
    {
        $tables = [];
        foreach ($this->queries as $sql) {
            if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`$/', $sql, $m)) {
                $tables[] = $m[1];
            }
        }

        return $tables;
    }

    // ── guards ─────────────────────────────────────────────────────────────

    public function test_file_does_nothing_when_not_called_by_wordpress(): void
    {
        $php  = escapeshellarg(PHP_BINARY);
        $file = escapeshellarg(dirname(__DIR__, 3) . '/uninstall.php');

        exec("{$php} {$file} 2>&1", $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame([], $output, 'no WP_UNINSTALL_PLUGIN: it must exit before touching anything');
    }

    public static function optOutProvider(): array
    {
        return [
            'no settings saved'      => [[]],
            'setting absent'         => [['enabled' => true]],
            'setting explicitly off' => [['cleanup_on_uninstall' => false]],
            'setting zero'           => [['cleanup_on_uninstall' => 0]],
            'setting empty string'   => [['cleanup_on_uninstall' => '']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('optOutProvider')]
    public function test_data_is_kept_unless_the_admin_opted_in(array $settings): void
    {
        $this->options['wc_multi_store_sync_settings'] = $settings;

        $this->runUninstall();

        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->unscheduled);
    }

    // ── opted in ───────────────────────────────────────────────────────────

    public function test_opted_in_drops_each_plugin_table_exactly_once(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['cleanup_on_uninstall' => true];

        $this->runUninstall();

        $dropped = $this->droppedTables();
        $this->assertCount(12, $dropped);
        $this->assertSame($dropped, array_unique($dropped));
        foreach ($dropped as $table) {
            $this->assertStringStartsWith('wp_wc_', $table, 'only plugin tables, never core ones');
        }
    }

    public function test_every_table_the_plugin_creates_is_dropped_on_uninstall(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['cleanup_on_uninstall' => true];
        $this->runUninstall();

        $created = [
            'wc_mss_sync_history',
            'wc_multi_store_stock_discrepancies',
            ...$this->constantValues(WC_Multi_Store_Queue_Table::class, ['TABLE_NAME']),
            ...$this->constantValues(WC_Multi_Store_API_Usage_Tracker::class, ['TABLE_NAME']),
            ...$this->constantValues(WC_Multi_Store_Deletion_Audit::class, ['TABLE_NAME']),
            ...$this->constantValues(WC_Multi_Store_Remote_Order_Table::class, ['ORDERS_TABLE', 'ITEMS_TABLE']),
            ...$this->constantValues(WC_Multi_Store_Webhook_Logger::class, ['TABLE_NAME']),
            ...$this->constantValues(WC_Multi_Store_Dead_Letter_Queue::class, ['TABLE_NAME']),
            ...$this->constantValues(WC_Multi_Store_Conflict_Detector::class, ['HASHES_TABLE', 'LOG_TABLE']),
            ...$this->constantValues(WC_Multi_Store_Weekly_Verification_Report_Repository::class, ['TABLE_NAME']),
        ];
        $expected = array_map(fn(string $name) => 'wp_' . $name, $created);

        $this->assertEqualsCanonicalizing($expected, $this->droppedTables());
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function constantValues(string $class, array $names): array
    {
        $reflection = new ReflectionClass($class);

        return array_map(fn(string $name) => (string) $reflection->getConstant($name), $names);
    }

    public function test_options_and_transients_are_removed_with_prefix_patterns_that_cannot_match_other_plugins(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['cleanup_on_uninstall' => true];

        $this->runUninstall();

        $deletes = array_values(array_filter($this->queries, fn($q) => str_starts_with($q, 'DELETE FROM wp_options')));
        $this->assertCount(2, $deletes);

        $patterns = [];
        foreach ($deletes as $sql) {
            preg_match_all("/LIKE '([^']+)'/", $sql, $m);
            array_push($patterns, ...$m[1]);
        }
        $this->assertEqualsCanonicalizing([
            'wc\_mss\_%',
            'wc\_multi\_store\_sync\_%',
            '\_transient\_wc\_mss\_%',
            '\_transient\_timeout\_wc\_mss\_%',
        ], $patterns);

        // Underscores are escaped, so "wcXmssX" style names of other plugins are not swept up.
        foreach ($patterns as $pattern) {
            $this->assertStringNotContainsString('_', str_replace('\_', '', $pattern));
        }
        foreach ($deletes as $sql) {
            $this->assertStringContainsString('WHERE', $sql);
        }
    }

    public function test_scheduled_actions_are_cancelled_before_tables_are_dropped(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['cleanup_on_uninstall' => true];
        $order = [];
        Functions\when('as_unschedule_all_actions')->alias(function () use (&$order) {
            $order[] = 'unschedule';
        });
        $test = $this;
        global $wpdb;
        $wpdb = new class ($test, $order) extends wpdb {
            public string $prefix  = 'wp_';
            public string $options = 'wp_options';

            public function __construct(private object $test, private array &$order)
            {
            }
            public function query(string $query): int|false
            {
                $this->order[] = 'query';
                $this->test->recordQuery($query);
                return 1;
            }
        };

        $this->runUninstall();

        $this->assertSame('unschedule', $order[0]);
        $this->assertNotContains('unschedule', array_slice($order, array_search('query', $order, true)));
    }
}
