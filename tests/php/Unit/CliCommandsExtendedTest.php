<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behavioural tests for WC_Multi_Store_CLI_Commands.
 *
 * CliCommandsTest only checks that methods exist; this file drives the commands
 * with the bootstrap's minimal WP_CLI stub and asserts on what they log, which
 * collaborators they call, and the exit (error) behaviour.
 *
 * The bootstrap WP_CLI stub has no confirm()/colorize(), so paths that need them
 * (queue clear, dlq clear/retry-all, config import after parsing, queue status
 * with active stores) cannot be exercised here.
 */
class CliCommandsExtendedTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_CLI_Commands $cli;

    /** @var array<int, array{0:string,1:array,2:array}> */
    private array $formatted = [];

    protected function setUp(): void
    {
        parent::setUp();
        WP_CLI::$logged = [];
        $this->formatted = [];
        $this->cli = new WC_Multi_Store_CLI_Commands();
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('wp_parse_url')->alias(fn($url, $component = -1) => parse_url($url, $component));
        Functions\when('sanitize_sql_orderby')->alias(fn($v) => $v);
        Functions\when('WP_CLI\Utils\format_items')->alias(function ($format, $items, $fields) {
            $this->formatted[] = [$format, $items, $fields];
        });
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Sync::reset_instance();
        parent::tearDown();
    }

    /** Collect all logged messages of a given level. */
    private function logged(string $level): array
    {
        $out = [];
        foreach (WP_CLI::$logged as [$lvl, $msg]) {
            if ($lvl === $level) {
                $out[] = $msg;
            }
        }
        return $out;
    }

    private function fakeWpdb(array $row = [], array $vars = [], array $results = [], int $queryResult = 0): object
    {
        return new class($row, $vars, $results, $queryResult) extends wpdb {
            public array $queries = [];
            public function __construct(private array $row, private array $vars, private array $results, private int $queryResult) {}
            public function get_row(string $query, string $output = OBJECT): mixed
            {
                return $this->row ?: null;
            }
            public function get_var(string $query): mixed
            {
                foreach ($this->vars as $needle => $value) {
                    if (str_contains($query, (string) $needle)) {
                        return $value;
                    }
                }
                return null;
            }
            public function get_results(string $query, string $output = OBJECT): array|object|null
            {
                return $this->results;
            }
            public function query(string $query): int|false
            {
                $this->queries[] = $query;
                return $this->queryResult;
            }
        };
    }

    private function expectProgressBar(int &$ticks, bool &$finished): void
    {
        Functions\when('WP_CLI\Utils\make_progress_bar')->alias(function () use (&$ticks, &$finished) {
            return new class($ticks, $finished) {
                public function __construct(private int &$ticks, private bool &$finished) {}
                public function tick(): void { $this->ticks++; }
                public function finish(): void { $this->finished = true; }
            };
        });
    }

    // ─── sync: argument validation ─────────────────

    public function test_sync_without_selector_errors_with_usage_hint(): void
    {
        try {
            $this->cli->sync([], []);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--all', $e->getMessage());
            $this->assertStringContainsString('--product=<id>', $e->getMessage());
            $this->assertStringContainsString('--sku=<sku>', $e->getMessage());
        }
    }

    public function test_sync_warns_when_sync_disabled_but_still_proceeds_to_validation(): void
    {
        try {
            $this->cli->sync([], []);
        } catch (\RuntimeException) {
        }

        $warnings = $this->logged('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('disabled', $warnings[0]);
    }

    public function test_sync_does_not_warn_when_sync_enabled(): void
    {
        Functions\when('get_option')->alias(fn($o, $d = false) => $o === 'wc_multi_store_sync_settings' ? ['enabled' => true] : $d);

        try {
            $this->cli->sync([], []);
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->logged('warning'));
    }

    public function test_sync_unknown_product_id_errors(): void
    {
        Functions\expect('wc_get_product')->once()->with(999)->andReturn(false);

        try {
            $this->cli->sync([], ['product' => '999']);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Product ID 999 not found.', $e->getMessage());
        }
    }

    public function test_sync_unknown_sku_errors_with_sku_in_message(): void
    {
        Functions\expect('wc_get_product_id_by_sku')->once()->with('NOPE-1')->andReturn(0);

        try {
            $this->cli->sync([], ['sku' => 'NOPE-1']);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Product with SKU "NOPE-1" not found.', $e->getMessage());
        }
    }

    public function test_sync_unknown_category_slug_errors(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/category-sync.php';
        Functions\expect('get_term_by')->once()->with('slug', 'ghost-cat', 'product_cat')->andReturn(false);

        try {
            $this->cli->sync([], ['category' => 'ghost-cat']);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Category "ghost-cat" not found.', $e->getMessage());
        }
    }

    public function test_sync_numeric_category_is_resolved_by_id_not_slug(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/category-sync.php';
        Functions\expect('get_term')->once()->with(42, 'product_cat')->andReturn(null);
        Functions\expect('get_term_by')->never();

        try {
            $this->cli->sync([], ['category' => '42']);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Category "42" not found.', $e->getMessage());
        }
    }

    public function test_sync_all_with_no_published_products_errors(): void
    {
        Functions\expect('wc_get_products')->once()->with([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids',
        ])->andReturn([]);

        try {
            $this->cli->sync([], ['all' => true]);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('No published products found.', $e->getMessage());
        }
    }

    public function test_sync_selector_priority_product_wins_over_sku_and_all(): void
    {
        $product = \Mockery::mock(WC_Product::class);
        $product->shouldReceive('get_id')->andReturn(7);
        Functions\expect('wc_get_product')->once()->with(7)->andReturn($product);
        Functions\expect('wc_get_product_id_by_sku')->never();
        Functions\expect('wc_get_products')->never();

        $this->cli->sync([], ['product' => '7', 'sku' => 'X', 'all' => true, 'dry-run' => true]);

        $this->assertStringContainsString('Would sync 1 product(s)', $this->logged('log')[0]);
    }

    // ─── sync: dry run ─────────────────────────────

    private function storesOption(): array
    {
        return [
            'https://a.example.com' => ['status' => 'active'],
            'https://b.example.com' => ['status' => 'active'],
            'https://c.example.com' => ['status' => 'inactive'],
        ];
    }

    private function useStores(array $stores): void
    {
        Functions\when('get_option')->alias(
            fn($o, $d = false) => $o === 'wc_multi_store_sync_stores' ? $stores : $d
        );
    }

    public function test_sync_dry_run_lists_active_stores_and_does_not_queue(): void
    {
        $this->useStores($this->storesOption());
        Functions\expect('wc_get_products')->once()->andReturn([1, 2, 3]);
        Functions\expect('WP_CLI\Utils\make_progress_bar')->never();

        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('add_product')->never();
        WC_MSS()->queue_manager = $queue;

        $this->cli->sync([], ['all' => true, 'dry-run' => true, 'type' => 'quantity']);

        $logs = $this->logged('log');
        $this->assertSame('Dry run: Would sync 3 product(s) with type "quantity".', $logs[0]);
        $this->assertContains('  -> https://a.example.com', $logs);
        $this->assertContains('  -> https://b.example.com', $logs);
        $this->assertNotContains('  -> https://c.example.com', $logs);
        $this->assertSame(
            ['Dry run complete. 3 product(s) would be queued to 2 store(s).'],
            $this->logged('success')
        );
    }

    public function test_sync_dry_run_store_filter_ignores_trailing_slash(): void
    {
        $this->useStores($this->storesOption());
        Functions\expect('wc_get_products')->once()->andReturn([1]);

        $this->cli->sync([], ['all' => true, 'dry-run' => true, 'store' => 'https://b.example.com/']);

        $logs = $this->logged('log');
        $this->assertContains('  -> https://b.example.com', $logs);
        $this->assertNotContains('  -> https://a.example.com', $logs);
        $this->assertSame(
            ['Dry run complete. 1 product(s) would be queued to 1 store(s).'],
            $this->logged('success')
        );
    }

    public function test_sync_dry_run_defaults_type_to_full_product(): void
    {
        Functions\expect('wc_get_products')->once()->andReturn([5]);

        $this->cli->sync([], ['all' => true, 'dry-run' => true]);

        $this->assertStringContainsString('type "full_product"', $this->logged('log')[0]);
    }

    // ─── sync: real queueing ───────────────────────

    public function test_sync_queues_each_product_with_wp_cli_trigger_and_normal_priority(): void
    {
        Functions\expect('wc_get_products')->once()->andReturn([10, 11, 12]);
        $ticks = 0;
        $finished = false;
        $this->expectProgressBar($ticks, $finished);

        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('add_product')
            ->with(10, 'wp_cli', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL, 'price_quantity')->once()->andReturn(2);
        $queue->shouldReceive('add_product')
            ->with(11, 'wp_cli', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL, 'price_quantity')->once()->andReturn(0);
        $queue->shouldReceive('add_product')
            ->with(12, 'wp_cli', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL, 'price_quantity')->once()->andReturn(3);
        WC_MSS()->queue_manager = $queue;

        $this->cli->sync([], ['all' => true, 'type' => 'price_quantity']);

        $this->assertSame(3, $ticks);
        $this->assertTrue($finished);
        $this->assertSame(['Queuing 3 product(s) for sync...'], $this->logged('log'));
        $this->assertSame(['Queued 5 item(s) for 3 product(s).'], $this->logged('success'));
    }

    public function test_sync_by_sku_queues_the_resolved_product_id(): void
    {
        Functions\expect('wc_get_product_id_by_sku')->once()->with('ABC-001')->andReturn(55);
        $ticks = 0;
        $finished = false;
        $this->expectProgressBar($ticks, $finished);

        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('add_product')->once()
            ->with(55, 'wp_cli', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL, 'full_product')->andReturn(1);
        WC_MSS()->queue_manager = $queue;

        $this->cli->sync([], ['sku' => 'ABC-001']);

        $this->assertSame(['Queued 1 item(s) for 1 product(s).'], $this->logged('success'));
    }

    // ─── queue ─────────────────────────────────────

    public function test_queue_status_prints_one_row_per_status_with_ucfirst_labels(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(['pending' => 4, 'processing' => 1, 'completed' => 9, 'failed' => 2, 'total' => 16]);

        $this->cli->queue(['status'], []);

        $this->assertSame(['Queue Status:'], array_slice($this->logged('log'), 0, 1));
        $this->assertCount(1, $this->formatted);
        [$format, $rows, $fields] = $this->formatted[0];
        $this->assertSame('table', $format);
        $this->assertSame(['Status', 'Count'], $fields);
        $this->assertSame([
            ['Status' => 'Pending', 'Count' => 4],
            ['Status' => 'Processing', 'Count' => 1],
            ['Status' => 'Completed', 'Count' => 9],
            ['Status' => 'Failed', 'Count' => 2],
            ['Status' => 'Total', 'Count' => 16],
        ], $rows);
    }

    public function test_queue_status_skips_circuit_breaker_table_when_no_active_stores(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb();

        $this->cli->queue(['status'], []);

        $this->assertCount(1, $this->formatted, 'Only the queue table is printed');
        $this->assertNotContains('Circuit Breaker Status:', $this->logged('log'));
    }

    public function test_queue_process_empty_reports_success(): void
    {
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('process_queue')->once()
            ->with(30, \Mockery::type('callable'))->andReturn(['status' => 'empty']);
        WC_MSS()->queue_manager = $queue;

        $this->cli->queue(['process'], []);

        $this->assertSame(['Processing queue (batch size: 30)...'], $this->logged('log'));
        $this->assertSame(['Queue is empty, nothing to process.'], $this->logged('success'));
    }

    public function test_queue_process_skipped_warns_that_processor_is_running(): void
    {
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('process_queue')->once()->andReturn(['status' => 'skipped']);
        WC_MSS()->queue_manager = $queue;

        $this->cli->queue(['process'], ['batch-size' => '5']);

        $this->assertSame(['Processing queue (batch size: 5)...'], $this->logged('log'));
        $this->assertSame(['Queue processor is already running.'], $this->logged('warning'));
        $this->assertSame([], $this->logged('success'));
    }

    public function test_queue_process_summarises_result_counts(): void
    {
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('process_queue')->once()->andReturn([
            'status' => 'done', 'processed' => 10, 'success' => 8, 'errors' => 2, 'remaining' => 40,
        ]);
        WC_MSS()->queue_manager = $queue;

        $this->cli->queue(['process'], []);

        $this->assertSame(['Processed 10 item(s): 8 success, 2 errors, 40 remaining.'], $this->logged('success'));
    }

    public function test_queue_process_non_verbose_callback_logs_progress_only_every_15th_and_last(): void
    {
        $callback = null;
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('process_queue')->once()->andReturnUsing(function ($size, $cb) use (&$callback) {
            $callback = $cb;
            // Simulate 16 items: 14 ok, 2 error. Item 15 and 16 (last) trigger a log line.
            for ($i = 1; $i <= 16; $i++) {
                $cb([
                    'status' => $i <= 14 ? 'success' : 'error',
                    'index' => $i, 'total' => 16, 'sku' => 'S' . $i, 'product_id' => $i,
                    'store_url' => 'https://a.example.com', 'message' => '',
                ]);
            }
            return ['status' => 'done', 'processed' => 16, 'success' => 14, 'errors' => 2, 'remaining' => 0];
        });
        WC_MSS()->queue_manager = $queue;

        $this->cli->queue(['process'], []);

        $progress = array_values(array_filter($this->logged('log'), fn($m) => str_starts_with($m, '[')));
        $this->assertSame(['[15/16] 14 success, 1 errors', '[16/16] 14 success, 2 errors'], $progress);
    }

    public function test_queue_retry_reports_reset_count(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([], [], [], 3);

        $this->cli->queue(['retry'], []);

        $this->assertSame(['3 failed item(s) reset to pending.'], $this->logged('success'));
    }

    public function test_queue_retry_with_nothing_failed_reports_zero(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([], [], [], 0);

        $this->cli->queue(['retry'], []);

        $this->assertSame(['0 failed item(s) reset to pending.'], $this->logged('success'));
    }

    public function test_queue_unknown_action_is_silent(): void
    {
        $this->cli->queue(['bogus'], []);

        $this->assertSame([], WP_CLI::$logged);
    }

    // ─── stores ────────────────────────────────────

    public function test_stores_list_empty_logs_message_and_prints_no_table(): void
    {
        $this->cli->stores(['list'], []);

        $this->assertSame(['No stores configured.'], $this->logged('log'));
        $this->assertSame([], $this->formatted);
    }

    public function test_stores_list_builds_rows_with_defaults_and_exclusion_counts(): void
    {
        $this->useStores([
            'https://a.example.com' => [
                'status' => 'active',
                'auth_method' => 'oauth',
                'exclude_categories' => [1, 2, 3],
                'exclude_tags' => [9],
            ],
            'https://b.example.com' => [],
        ]);

        $this->cli->stores(['list'], ['format' => 'json']);

        $this->assertCount(1, $this->formatted);
        [$format, $rows, $fields] = $this->formatted[0];
        $this->assertSame('json', $format);
        $this->assertSame(['URL', 'Status', 'Auth', 'Excluded Categories', 'Excluded Tags'], $fields);
        $this->assertSame([
            ['URL' => 'https://a.example.com', 'Status' => 'active', 'Auth' => 'oauth', 'Excluded Categories' => 3, 'Excluded Tags' => 1],
            ['URL' => 'https://b.example.com', 'Status' => 'inactive', 'Auth' => 'basic_auth', 'Excluded Categories' => 0, 'Excluded Tags' => 0],
        ], $rows);
    }

    public function test_stores_list_defaults_to_table_format(): void
    {
        $this->useStores(['https://a.example.com' => ['status' => 'active']]);

        $this->cli->stores(['list'], []);

        $this->assertSame('table', $this->formatted[0][0]);
    }

    public function test_stores_list_ignores_corrupted_numeric_keys(): void
    {
        $this->useStores([0 => ['status' => 'active']]);

        $this->cli->stores(['list'], []);

        $this->assertSame(['No stores configured.'], $this->logged('log'));
    }

    #[DataProvider('storeSubcommandsRequiringStore')]
    public function test_stores_test_and_reset_require_store_option(string $action): void
    {
        try {
            $this->cli->stores([$action], []);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Please specify --store=<url>.', $e->getMessage());
        }
    }

    public static function storeSubcommandsRequiringStore(): array
    {
        return ['test' => ['test'], 'reset' => ['reset']];
    }

    public function test_stores_test_unknown_store_errors_with_normalised_url(): void
    {
        $this->useStores(['https://a.example.com' => ['status' => 'active']]);

        try {
            $this->cli->stores(['test'], ['store' => 'https://missing.example.com///']);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Store "https://missing.example.com" not found.', $e->getMessage());
        }
    }

    public function test_stores_reset_clears_circuit_breaker_transient_for_normalised_url(): void
    {
        $deleted = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $this->cli->stores(['reset'], ['store' => 'https://a.example.com/']);

        $this->assertCount(1, $deleted);
        $this->assertStringEndsWith(md5('https://a.example.com'), $deleted[0]);
        $this->assertSame(
            ['Circuit breaker reset for https://a.example.com. Requests will resume immediately.'],
            $this->logged('success')
        );
    }

    // ─── history ───────────────────────────────────

    public function test_history_prints_statistics_and_reports_empty_history(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([
            'total_syncs' => 10, 'successful_syncs' => 8, 'failed_syncs' => 2, 'avg_duration_ms' => 123.6,
        ], ['COUNT(*) FROM' => 0]);

        $this->cli->history([], ['days' => '30']);

        $logs = $this->logged('log');
        $this->assertSame('Sync Statistics (last 30 days):', $logs[0]);
        $this->assertSame('  Total syncs: 10', $logs[1]);
        $this->assertSame('  Successful: 8', $logs[2]);
        $this->assertSame('  Failed: 2', $logs[3]);
        $this->assertSame('  Success rate: 80%', $logs[4]);
        $this->assertSame('  Avg duration: 124ms', $logs[5]);
        $this->assertSame('No sync history found.', end($logs));
        $this->assertSame([], $this->formatted);
    }

    public function test_history_defaults_to_seven_days_and_zero_success_rate(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb();

        $this->cli->history([], []);

        $logs = $this->logged('log');
        $this->assertSame('Sync Statistics (last 7 days):', $logs[0]);
        $this->assertSame('  Success rate: 0%', $logs[4]);
        $this->assertSame('  Avg duration: 0ms', $logs[5]);
    }

    public function test_history_formats_rows_with_host_sku_fallback_and_duration(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(
            ['total_syncs' => 2, 'successful_syncs' => 1, 'failed_syncs' => 1, 'avg_duration_ms' => 10],
            ['COUNT(*)' => 2],
            [
                [
                    'created_at' => '2024-01-15 10:00:00', 'product_sku' => 'SKU-1',
                    'store_url' => 'https://shop.example.com/path', 'sync_type' => 'quantity',
                    'status' => 'success', 'duration_ms' => 250,
                ],
                [
                    'created_at' => '2024-01-14 09:00:00', 'product_sku' => '',
                    'store_url' => 'not a url', 'sync_type' => 'full_product',
                    'status' => 'error',
                ],
            ]
        );

        $this->cli->history([], ['format' => 'csv', 'status' => 'error']);

        $this->assertCount(1, $this->formatted);
        [$format, $rows, $fields] = $this->formatted[0];
        $this->assertSame('csv', $format);
        $this->assertSame(['Date', 'SKU', 'Store', 'Type', 'Status', 'Duration'], $fields);
        $this->assertSame([
            'Date' => '2024-01-15 10:00:00', 'SKU' => 'SKU-1', 'Store' => 'shop.example.com',
            'Type' => 'quantity', 'Status' => 'success', 'Duration' => '250ms',
        ], $rows[0]);
        $this->assertSame('-', $rows[1]['SKU']);
        $this->assertSame('not a url', $rows[1]['Store']);
        $this->assertSame('0ms', $rows[1]['Duration']);
    }

    // ─── config ────────────────────────────────────

    #[DataProvider('configActions')]
    public function test_config_requires_file_option(string $action): void
    {
        try {
            $this->cli->config([$action], []);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Please specify --file=<path>.', $e->getMessage());
        }
    }

    public static function configActions(): array
    {
        return ['export' => ['export'], 'import' => ['import']];
    }

    public function test_config_import_missing_file_errors(): void
    {
        $path = sys_get_temp_dir() . '/wc-mss-cli-missing-' . uniqid() . '.json';

        try {
            $this->cli->config(['import'], ['file' => $path]);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('File not found: ' . $path, $e->getMessage());
        }
    }

    public function test_config_import_invalid_json_errors_before_confirmation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wcmss');
        file_put_contents($path, '{not json');

        try {
            $this->cli->config(['import'], ['file' => $path]);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid JSON in config file.', $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_config_export_unwritable_path_errors(): void
    {
        $path = sys_get_temp_dir() . '/wc-mss-no-such-dir-' . uniqid() . '/out.json';
        Functions\when('wp_json_encode')->alias(fn($d, $f = 0) => json_encode($d, $f));

        try {
            @$this->cli->config(['export'], ['file' => $path]);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to write to ' . $path . '.', $e->getMessage());
        }
    }

    public function test_config_export_writes_redacted_stores_and_notes_keys_were_excluded(): void
    {
        $this->exportFixture();
        $path = tempnam(sys_get_temp_dir(), 'wcmss');

        try {
            $this->cli->config(['export'], ['file' => $path]);
            $data = json_decode((string) file_get_contents($path), true);
        } finally {
            unlink($path);
        }

        $this->assertSame('***REDACTED***', $data['stores']['https://a.example.com']['consumer_key']);
        $this->assertSame('***REDACTED***', $data['stores']['https://a.example.com']['consumer_secret']);
        $this->assertSame('***REDACTED***', $data['webhook_settings']['webhook_secret']);
        $this->assertStringNotContainsString('real-key', json_encode($data));
        $this->assertSame(["Configuration exported to $path."], $this->logged('success'));
        $this->assertSame(
            ['Note: API keys were excluded. Use --include-keys to include them.'],
            $this->logged('log')
        );
    }

    public function test_config_export_with_include_keys_keeps_secrets_and_omits_note(): void
    {
        $this->exportFixture();
        $path = tempnam(sys_get_temp_dir(), 'wcmss');

        try {
            $this->cli->config(['export'], ['file' => $path, 'include-keys' => true]);
            $data = json_decode((string) file_get_contents($path), true);
        } finally {
            unlink($path);
        }

        $this->assertSame('real-key', $data['stores']['https://a.example.com']['consumer_key']);
        $this->assertSame('real-secret', $data['stores']['https://a.example.com']['consumer_secret']);
        $this->assertSame('whsec', $data['webhook_settings']['webhook_secret']);
        $this->assertSame([], $this->logged('log'));
    }

    private function exportFixture(): void
    {
        Functions\when('wp_json_encode')->alias(fn($d, $f = 0) => json_encode($d, $f));
        Functions\when('get_option')->alias(function ($o, $d = false) {
            return match ($o) {
                'wc_multi_store_sync_stores' => ['https://a.example.com' => [
                    'status' => 'active', 'consumer_key' => 'real-key', 'consumer_secret' => 'real-secret',
                ]],
                'wc_multi_store_sync_webhook_settings' => ['webhook_secret' => 'whsec', 'enabled' => true],
                default => $d,
            };
        });
    }

    // ─── dlq ───────────────────────────────────────

    public function test_dlq_list_empty(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb();

        $this->cli->dlq(['list'], []);

        $this->assertSame(['Dead letter queue is empty.'], $this->logged('log'));
        $this->assertSame([], $this->formatted);
    }

    public function test_dlq_list_truncates_error_to_60_chars_and_uses_fallbacks(): void
    {
        global $wpdb;
        $long = str_repeat('é', 100);
        $wpdb = $this->fakeWpdb([], ['COUNT(*)' => 2], [
            [
                'id' => 1, 'product_id' => 10, 'product_sku' => 'A', 'store_url' => 'https://s.example.com/x',
                'sync_type' => 'full_product', 'attempts' => 5, 'last_error' => $long, 'failed_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 2, 'product_id' => 11, 'product_sku' => '', 'store_url' => 'garbage',
                'sync_type' => 'quantity', 'attempts' => 3, 'last_error' => null, 'failed_at' => '2024-01-02 00:00:00',
            ],
        ]);

        $this->cli->dlq(['list'], ['format' => 'json', 'limit' => '2']);

        [$format, $rows, $fields] = $this->formatted[0];
        $this->assertSame('json', $format);
        $this->assertSame(['ID', 'Product ID', 'SKU', 'Store', 'Type', 'Attempts', 'Error', 'Failed At'], $fields);
        $this->assertSame(60, mb_strlen($rows[0]['Error']), 'multibyte-safe truncation');
        $this->assertSame('s.example.com', $rows[0]['Store']);
        $this->assertSame('-', $rows[1]['SKU']);
        $this->assertSame('garbage', $rows[1]['Store']);
        $this->assertSame('', $rows[1]['Error']);
        $this->assertSame(['Total: 2 item(s)'], array_slice($this->logged('log'), -1));
    }

    public function test_dlq_retry_unknown_item_errors(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb();

        try {
            $this->cli->dlq(['retry'], ['id' => '77']);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to retry item 77.', $e->getMessage());
        }
    }

    public function test_dlq_stats_titlecases_metric_names(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([], ['status = \'dead\'' => 0]);

        $this->cli->dlq(['stats'], []);

        $this->assertSame(['Dead Letter Queue Statistics:'], $this->logged('log'));
        [$format, $rows, $fields] = $this->formatted[0];
        $this->assertSame('table', $format);
        $this->assertSame(['Metric', 'Value'], $fields);
        $metrics = array_column($rows, 'Metric');
        $this->assertContains('Total Dead', $metrics);
        $this->assertContains('Total Retried', $metrics);
        $this->assertContains('Oldest Item', $metrics);
        $this->assertContains('By Store', $metrics);
    }

    public function test_dlq_unknown_action_is_silent(): void
    {
        $this->cli->dlq(['bogus'], []);

        $this->assertSame([], WP_CLI::$logged);
    }

    // ─── verify ────────────────────────────────────

    public function test_verify_announces_start_before_running(): void
    {
        try {
            $this->cli->verify([], []);
        } catch (\RuntimeException) {
            // No stores configured: the run aborts via WP_CLI::error.
        }

        $this->assertSame(['log', 'Starting stock verification...'], WP_CLI::$logged[0]);
    }
}
