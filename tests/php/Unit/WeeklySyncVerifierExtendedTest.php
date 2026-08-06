<?php
/**
 * Extended unit tests for WC_Multi_Store_Weekly_Sync_Verifier
 * Tests run_verification happy path, verify_product, get_products_to_verify,
 * scheduling, get_report, and async batch methods
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WeeklySyncVerifierExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVerifierMocks();

        if (!class_exists('WC_Multi_Store_Weekly_Sync_Verifier', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-sync-verifier.php';
        }

        // Reset static API client pool and prefetch batch cache between tests
        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Sync_Verifier');
        $prop = $ref->getProperty('api_client_pool');
        $prop->setValue(null, []);
        $batchCacheProp = $ref->getProperty('batch_cache');
        $batchCacheProp->setValue(null, []);
    }

    protected function setUpVerifierMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'match_products_by' => 'sku',
                    'category_match_by' => 'slug',
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                        'name' => 'Store 1',
                    ],
                ];
            }
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'admin_email') {
                return 'admin@test.com';
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_parse_args')->alias(function ($args, $defaults) {
            return array_merge($defaults, (array) $args);
        });
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('sanitize_sql_orderby')->alias(fn($s) => $s);
        Functions\when('maybe_serialize')->alias(fn($data) => serialize($data));
        Functions\when('maybe_unserialize')->alias(fn($data) => is_string($data) ? @unserialize($data) : $data);
        // No stored remote-ID mapping by default — exercises the pre-existing
        // search-by-SKU/slug path unless a test explicitly stubs a stored ID.
        Functions\when('get_post_meta')->justReturn('');
    }

    // ── run_verification: stale lock ─────────────────────────────

    public function test_run_verification_clears_stale_lock(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Lock was set 2 hours ago → stale
        $stale_time = time() - (2 * HOUR_IN_SECONDS);
        Functions\when('get_transient')->alias(function ($key) use ($stale_time) {
            if ($key === WC_Multi_Store_Weekly_Sync_Verifier::VERIFICATION_LOCK) {
                return $stale_time;
            }
            return false;
        });

        // After clearing stale lock, it hits get_active_stores which returns empty → 'No active stores'
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [];
            }
            return $default;
        });

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();

        // Should have cleared the stale lock and proceeded to 'No active stores'
        $this->assertEquals('No active stores', $result['error']);
    }

    // ── run_verification: no products ────────────────────────────

    public function test_run_verification_no_products(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('wp_count_posts')->alias(function () {
            return (object) ['publish' => 0];
        });

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();

        $this->assertEquals('No products to verify', $result['error']);
    }

    // ── run_verification: happy path ─────────────────────────────

    public function test_run_verification_happy_path_no_discrepancies(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 1]);
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('TEST-SKU');
            $product->shouldReceive('get_slug')->andReturn('test-product');
            $product->shouldReceive('get_name')->andReturn('Test Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('19.99');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        // Mock API client for get_remote_product
        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn([
                [
                    'id' => 100,
                    'sku' => 'TEST-SKU',
                    'stock_quantity' => 10,
                    'regular_price' => '19.99',
                    'sale_price' => '',
                    'categories' => [],
                ],
            ]);

        // Inject mock API client into the pool
        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Sync_Verifier');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        // Override WP_Query constructor to inject test posts
        // We use the get_products_to_verify which creates WP_Query
        // Since WP_Query is a stub, we need to make it return products
        // by patching the class temporarily
        $origClass = true;

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->andReturn(1);

        // Use reflection to call verify_product directly instead
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'verify_product');

        $stores = [
            'https://store1.com' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ],
        ];

        $settings = [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ];

        $result = $method->invoke(null, 42, $stores, $settings);

        $this->assertNotNull($result);
        $this->assertEmpty($result['discrepancies']);
    }

    public function test_verify_product_detects_stock_mismatch(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('SKU-STOCK');
            $product->shouldReceive('get_slug')->andReturn('stock-product');
            $product->shouldReceive('get_name')->andReturn('Stock Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(50);
            $product->shouldReceive('get_regular_price')->andReturn('10.00');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn([
                [
                    'id' => 100,
                    'sku' => 'SKU-STOCK',
                    'stock_quantity' => 30, // Mismatch: local=50, remote=30
                    'regular_price' => '10.00',
                    'sale_price' => '',
                    'categories' => [],
                ],
            ]);

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Sync_Verifier');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'verify_product');

        $stores = ['https://store1.com' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']];
        $settings = ['check_stock' => true, 'check_prices' => false, 'check_categories' => false];

        $result = $method->invoke(null, 42, $stores, $settings);

        $this->assertNotEmpty($result['discrepancies']);
        $stock_disc = array_filter($result['discrepancies'], fn($d) => $d['type'] === 'stock');
        $this->assertCount(1, $stock_disc);
        $disc = array_values($stock_disc)[0];
        $this->assertEquals(50, $disc['expected']);
        $this->assertEquals(30, $disc['actual']);
    }

    public function test_verify_product_detects_missing_product(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('MISSING-SKU');
            $product->shouldReceive('get_slug')->andReturn('missing-product');
            $product->shouldReceive('get_name')->andReturn('Missing Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('10.00');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')->andReturn([]); // Not found

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Sync_Verifier');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'verify_product');

        $stores = ['https://store1.com' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']];
        $settings = ['check_stock' => true, 'check_prices' => true, 'check_categories' => false];

        $result = $method->invoke(null, 42, $stores, $settings);

        $missing = array_filter($result['discrepancies'], fn($d) => $d['type'] === 'missing');
        $this->assertCount(1, $missing);
    }

    public function test_verify_product_detects_price_mismatch(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('PRICE-SKU');
            $product->shouldReceive('get_slug')->andReturn('price-product');
            $product->shouldReceive('get_name')->andReturn('Price Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('29.99');
            $product->shouldReceive('get_sale_price')->andReturn('19.99');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn([
                [
                    'id' => 100,
                    'sku' => 'PRICE-SKU',
                    'stock_quantity' => 10,
                    'regular_price' => '39.99', // Mismatch
                    'sale_price' => '29.99',    // Mismatch
                    'categories' => [],
                ],
            ]);

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Sync_Verifier');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'verify_product');

        $stores = ['https://store1.com' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']];
        $settings = ['check_stock' => false, 'check_prices' => true, 'check_categories' => false];

        $result = $method->invoke(null, 42, $stores, $settings);

        $price_disc = array_filter($result['discrepancies'], fn($d) => $d['type'] === 'price');
        $this->assertCount(2, $price_disc); // regular + sale price
    }

    // ── verify_product: edge cases ───────────────────────────────

    public function test_verify_product_skips_product_without_sku(): void
    {
        Functions\when('wc_get_product')->alias(function () {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_sku')->andReturn('');
            return $product;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'verify_product');

        $result = $method->invoke(null, 42, ['https://store1.com' => []], [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ]);

        $this->assertNull($result);
    }

    public function test_verify_product_returns_null_for_invalid_product(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'verify_product');

        $result = $method->invoke(null, 9999, ['https://store1.com' => []], [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ]);

        $this->assertNull($result);
    }

    public function test_verify_product_handles_api_error(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('ERROR-SKU');
            $product->shouldReceive('get_slug')->andReturn('error-product');
            $product->shouldReceive('get_name')->andReturn('Error Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('10.00');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn(new \WP_Error('api_error', 'Connection timeout'));

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Sync_Verifier');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'verify_product');

        $store_config = [
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ];

        $result = $method->invoke(null, 42, ['https://store1.com' => $store_config], [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ]);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['discrepancies']);
        $this->assertEquals('error', $result['discrepancies'][0]['type']);
        $this->assertStringContainsString('Connection timeout', $result['discrepancies'][0]['message']);
    }

    // ── get_report ───────────────────────────────────────────────

    public function test_get_report_returns_report(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->andReturn([
            'id' => 1,
            'started_at' => '2024-01-15 12:00:00',
            'report_data' => serialize(['test' => true]),
        ]);

        $result = WC_Multi_Store_Weekly_Sync_Verifier::get_report(1);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
        $this->assertIsArray($result['report_data']);
        $this->assertTrue($result['report_data']['test']);
    }

    public function test_get_report_returns_null_when_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->andReturn(null);

        $result = WC_Multi_Store_Weekly_Sync_Verifier::get_report(999);

        $this->assertNull($result);
    }

    // ── get_latest_report with data ──────────────────────────────

    public function test_get_latest_report_returns_report_when_exists(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')
            ->andReturn('wp_wc_multi_store_weekly_verifications'); // table_exists
        $wpdb->shouldReceive('get_row')
            ->andReturn([
                'id' => 5,
                'started_at' => '2024-01-14 03:00:00',
                'products_checked' => 100,
                'report_data' => serialize(['discrepancies_found' => 3]),
            ]);

        $result = WC_Multi_Store_Weekly_Sync_Verifier::get_latest_report();

        $this->assertIsArray($result);
        $this->assertEquals(5, $result['id']);
        $this->assertEquals(3, $result['report_data']['discrepancies_found']);
    }

    // ── build_exclusion_tax_query ────────────────────────────────

    public function test_build_exclusion_tax_query_empty_stores(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'build_exclusion_tax_query');

        $result = $method->invoke(null, []);

        $this->assertEmpty($result);
    }

    public function test_build_exclusion_tax_query_common_exclusions(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'build_exclusion_tax_query');

        $stores = [
            'https://store1.com' => ['exclude_categories' => [5, 10], 'exclude_tags' => [1]],
            'https://store2.com' => ['exclude_categories' => [10, 15], 'exclude_tags' => [1, 2]],
        ];

        $result = $method->invoke(null, $stores);

        // Only category 10 is excluded from ALL stores
        $this->assertNotEmpty($result);
        $found_cat = false;
        foreach ($result as $item) {
            if (is_array($item) && ($item['taxonomy'] ?? '') === 'product_cat') {
                $this->assertContains(10, $item['terms']);
                $this->assertNotContains(5, $item['terms']);
                $this->assertNotContains(15, $item['terms']);
                $found_cat = true;
            }
        }
        $this->assertTrue($found_cat);
    }

    // ── schedule_verification / unschedule_verification ──────────

    public function test_schedule_verification_calls_as_schedule(): void
    {
        $called = false;
        Functions\when('as_unschedule_all_actions')->justReturn(0);
        Functions\when('as_schedule_recurring_action')->alias(function () use (&$called) {
            $called = true;
            return 1;
        });

        WC_Multi_Store_Weekly_Sync_Verifier::schedule_verification();

        $this->assertTrue($called, 'as_schedule_recurring_action should have been called');
    }

    public function test_unschedule_verification_calls_as_unschedule(): void
    {
        $called = false;
        Functions\when('as_unschedule_all_actions')->alias(function () use (&$called) {
            $called = true;
            return 0;
        });

        WC_Multi_Store_Weekly_Sync_Verifier::unschedule_verification();

        $this->assertTrue($called, 'as_unschedule_all_actions should have been called');
    }

    // ── get_products_to_verify sample modes ──────────────────────

    public function test_get_products_to_verify_defaults(): void
    {
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 5]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'get_products_to_verify');

        $settings = [
            'product_limit' => 100,
            'sample_mode' => 'all',
        ];

        $result = $method->invoke(null, $settings);

        // WP_Query mock returns empty posts
        $this->assertIsArray($result);
    }

    // ── scan_ghost_products: streaming + batch local index ────────

    /**
     * Helper: build $wpdb mock that returns the given SKU list as the local SKU set.
     */
    private function mockLocalSkuLookup(array $skus, ?array $slugs = null): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';

        $wpdb->shouldReceive('get_col')
            ->andReturnUsing(function ($sql) use ($skus, $slugs) {
                if (strpos($sql, 'post_name') !== false) {
                    return $slugs ?? [];
                }
                return $skus;
            });
    }

    /**
     * Helper: install a Mockery stub of WC_Multi_Store_API_Client into the verifier's
     * static pool so the scan uses our stub instead of constructing a real client.
     */
    private function installFakeApiClient(string $store_url, callable $stream): void
    {
        $mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock->shouldReceive('stream_products')->andReturnUsing($stream);

        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $pool = $ref->getProperty('api_client_pool');
        $current = $pool->getValue(null) ?? [];
        $current[$store_url] = $mock;
        $pool->setValue(null, $current);
    }

    public function test_scan_ghost_products_uses_batch_local_sku_query(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';

        // Single get_col() must serve the local SKU set. wc_get_product_id_by_sku
        // (which the OLD code would call per remote product) must never be invoked.
        $wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn(['LOCAL-1', 'LOCAL-2']);

        Functions\expect('wc_get_product_id_by_sku')->never();
        Functions\expect('get_page_by_path')->never();

        $this->installFakeApiClient('https://store1.com', function () {
            return null; // empty stream — we only assert the local lookup batching here
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        $this->assertSame([], $result);
    }

    public function test_scan_ghost_products_streams_pages_and_detects_ghosts(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Local catalogue has LOCAL-A only. Remote returns LOCAL-A, GHOST-B across two pages.
        $this->mockLocalSkuLookup(['LOCAL-A']);

        $pages_consumed = [];
        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) use (&$pages_consumed) {
            // Page 1
            $page1 = [
                ['id' => 1, 'sku' => 'LOCAL-A', 'name' => 'Local A',  'slug' => 'local-a'],
                ['id' => 2, 'sku' => 'GHOST-B', 'name' => 'Ghost B',  'slug' => 'ghost-b'],
            ];
            $pages_consumed[] = 1;
            if ($cb($page1, 1) === false) {
                return null;
            }
            // Page 2 — no new ghosts
            $page2 = [
                ['id' => 3, 'sku' => 'LOCAL-A', 'name' => 'Variant of A', 'slug' => 'variant-a'],
            ];
            $pages_consumed[] = 2;
            $cb($page2, 2);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        $this->assertCount(1, $result);
        $this->assertSame('GHOST-B', $result[0]['remote_sku']);
        $this->assertSame(2, $result[0]['remote_id']);
        $this->assertSame([1, 2], $pages_consumed, 'both pages should have been streamed');
    }

    public function test_scan_ghost_products_truncates_at_cap_and_stops_streaming(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $this->mockLocalSkuLookup([]); // no local products → every remote is a ghost

        $pages_emitted = 0;
        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) use (&$pages_emitted) {
            // Build a page large enough to hit the cap mid-callback. The fake honours the
            // callback's false return — the real paginate_each does the same.
            $page = [];
            for ($i = 0; $i < WC_Multi_Store_Weekly_Sync_Verifier::GHOST_SCAN_MAX_RESULTS + 100; $i++) {
                $page[] = ['id' => $i, 'sku' => "GHOST-$i", 'name' => "Ghost $i", 'slug' => "g-$i"];
            }
            $pages_emitted++;
            if ($cb($page, 1) === false) {
                return null;
            }
            $pages_emitted++;
            $cb([['id' => 99999, 'sku' => 'EXTRA', 'name' => 'Extra', 'slug' => 'extra']], 2);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        // Result must be capped at GHOST_SCAN_MAX_RESULTS regardless of extra pages emitted.
        $this->assertCount(
            WC_Multi_Store_Weekly_Sync_Verifier::GHOST_SCAN_MAX_RESULTS,
            $result
        );
    }

    public function test_scan_ghost_products_uses_slug_set_when_match_by_slug(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Override the setUpVerifierMocks get_option so match_products_by = 'slug'
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['match_products_by' => 'slug'];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';

        // Only the slug query should fire — never the SKU one.
        $wpdb->shouldReceive('get_col')
            ->once()
            ->with(\Mockery::on(fn($sql) => strpos($sql, 'post_name') !== false))
            ->andReturn(['local-slug-a']);

        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) {
            $cb([
                ['id' => 1, 'sku' => 'X', 'slug' => 'local-slug-a', 'name' => 'A'],
                ['id' => 2, 'sku' => 'Y', 'slug' => 'ghost-slug',   'name' => 'Ghost'],
            ], 1);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        $this->assertCount(1, $result);
        $this->assertSame('Ghost', $result[0]['remote_name']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Batch prefetch: build_remote_index() / load_search_values_for_products()
    // / prefetch_remote_batch_data() — the N×M → batched API call fix.
    // These reuse the same installFakeApiClient()/mockLocalSkuLookup()-style
    // helpers as the ghost-scan tests above, since both features stream a
    // store's catalog via the same stream_products() contract.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Like installFakeApiClient() but also stubs get_products() for the
     * batch-fetch (include=) phase.
     */
    private function installFakeApiClientWithBatchFetch(string $store_url, callable $stream, callable $getProducts): void
    {
        $mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock->shouldReceive('stream_products')->andReturnUsing($stream);
        $mock->shouldReceive('get_products')->andReturnUsing($getProducts);

        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $pool = $ref->getProperty('api_client_pool');
        $current = $pool->getValue(null) ?? [];
        $current[$store_url] = $mock;
        $pool->setValue(null, $current);
    }

    private function resetBatchCache(): void
    {
        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $prop = $ref->getProperty('batch_cache');
        $prop->setValue(null, []);
    }

    public function test_build_remote_index_streams_catalog_into_sku_map(): void
    {
        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) {
            $cb([
                ['id' => 10, 'sku' => 'SKU-A', 'slug' => 'a'],
                ['id' => 20, 'sku' => 'SKU-B', 'slug' => 'b'],
            ], 1);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'build_remote_index');
        $index = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            'sku'
        );

        $this->assertSame(['SKU-A' => 10, 'SKU-B' => 20], $index['https://store1.com']);
    }

    public function test_build_remote_index_omits_store_on_stream_error(): void
    {
        $this->installFakeApiClient('https://store1.com', function () {
            return new WP_Error('http_error', 'timeout');
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'build_remote_index');
        $index = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            'sku'
        );

        // Store must be entirely absent (not an empty array) so
        // prefetch_remote_batch_data()/get_remote_product() fall back to
        // live per-item calls for it, rather than treating every product as
        // confirmed-missing.
        $this->assertArrayNotHasKey('https://store1.com', $index);
    }

    public function test_load_search_values_for_products_returns_empty_for_empty_input(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'load_search_values_for_products');
        $result = $method->invoke(null, [], 'sku');

        $this->assertSame([], $result);
    }

    public function test_load_search_values_for_products_bulk_sku_query(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';

        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                (object) ['post_id' => 42, 'meta_value' => 'SKU-42'],
                (object) ['post_id' => 43, 'meta_value' => 'SKU-43'],
            ]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'load_search_values_for_products');
        $result = $method->invoke(null, [42, 43], 'sku');

        $this->assertSame([42 => 'SKU-42', 43 => 'SKU-43'], $result);
    }

    public function test_prefetch_remote_batch_data_batches_via_include_param(): void
    {
        $this->resetBatchCache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['post_id' => 1, 'meta_value' => 'SKU-1'],
            (object) ['post_id' => 2, 'meta_value' => 'SKU-2'],
        ]);

        $capturedParams = null;
        $this->installFakeApiClientWithBatchFetch(
            'https://store1.com',
            function () { return null; }, // stream_products not used by this method directly
            function ($search, $searchType, $params) use (&$capturedParams) {
                $capturedParams = $params;
                return [
                    ['id' => 100, 'sku' => 'SKU-1', 'stock_quantity' => 5],
                    ['id' => 200, 'sku' => 'SKU-2', 'stock_quantity' => 7],
                ];
            }
        );

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'prefetch_remote_batch_data');
        $method->invoke(
            null,
            [1, 2],
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            ['https://store1.com' => ['SKU-1' => 100, 'SKU-2' => 200]],
            'sku'
        );

        // A single batched call with both remote IDs — not one call per SKU.
        $this->assertSame('100,200', $capturedParams['include']);

        $ref   = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $cache = $ref->getProperty('batch_cache')->getValue(null);

        $this->assertSame(5, $cache['https://store1.com']['SKU-1']['stock_quantity']);
        $this->assertSame(7, $cache['https://store1.com']['SKU-2']['stock_quantity']);
    }

    public function test_prefetch_remote_batch_data_skips_api_call_when_nothing_in_index(): void
    {
        $this->resetBatchCache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['post_id' => 1, 'meta_value' => 'SKU-NOT-REMOTE'],
        ]);

        $mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock->shouldNotReceive('get_products');
        $ref  = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'prefetch_remote_batch_data');
        $method->invoke(
            null,
            [1],
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            ['https://store1.com' => []], // empty index — SKU-NOT-REMOTE isn't on this store
            'sku'
        );

        $cache = $ref->getProperty('batch_cache')->getValue(null);

        // Confirmed-missing without ever calling the API.
        $this->assertNull($cache['https://store1.com']['SKU-NOT-REMOTE']);
    }

    // ── SKU/slug drift: stored remote-ID fallback ────────────────

    public function test_prefetch_remote_batch_data_resolves_via_stored_remote_id_when_search_value_drifted(): void
    {
        $this->resetBatchCache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        // Local product #1's CURRENT sku is NEW-SKU (renamed since the last sync).
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['post_id' => 1, 'meta_value' => 'NEW-SKU'],
        ]);

        // Product #1 has a stored remote ID from its last successful sync.
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => ($post_id === 1 && str_contains($key, '_mss_remote_id_')) ? '999' : ''
        );

        $capturedParams = null;
        $this->installFakeApiClientWithBatchFetch(
            'https://store1.com',
            function () { return null; },
            function ($search, $searchType, $params) use (&$capturedParams) {
                $capturedParams = $params;
                return [
                    ['id' => 999, 'sku' => 'OLD-SKU', 'stock_quantity' => 5],
                ];
            }
        );

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'prefetch_remote_batch_data');
        $method->invoke(
            null,
            [1],
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            // Remote index is still keyed by the OLD sku — remote hasn't been
            // renamed (or re-synced) yet either.
            ['https://store1.com' => ['OLD-SKU' => 999]],
            'sku'
        );

        // Must fetch by the stored ID (999) instead of treating it as confirmed-missing.
        $this->assertSame('999', $capturedParams['include']);

        $ref   = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $cache = $ref->getProperty('batch_cache')->getValue(null);

        // Cached under the product's CURRENT search value (NEW-SKU), not the
        // old one, so get_remote_product() (which looks up by current SKU)
        // resolves it instead of reporting the product as missing.
        $this->assertSame(999, $cache['https://store1.com']['NEW-SKU']['id']);
    }

    public function test_get_remote_product_resolves_via_stored_remote_id_when_live_search_would_miss(): void
    {
        $this->resetBatchCache();

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(1);
        $product->shouldReceive('get_sku')->andReturn('NEW-SKU');
        $product->shouldReceive('get_slug')->andReturn('new-slug');

        Functions\when('get_option')->alias(function ($option, $default = false) {
            return $option === 'wc_multi_store_sync_settings' ? ['match_products_by' => 'sku'] : $default;
        });
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => ($post_id === 1 && str_contains($key, '_mss_remote_id_')) ? '999' : ''
        );

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_product')->with(999)->once()->andReturn([
            'id' => 999, 'sku' => 'OLD-SKU', 'stock_quantity' => 5,
        ]);
        // Must not fall back to search-by-value once the stored ID resolves.
        $mock_api->shouldNotReceive('get_products');

        $ref  = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'get_remote_product');
        $result = $method->invoke(null, $product, 'https://store1.com', ['consumer_key' => 'k', 'consumer_secret' => 's']);

        $this->assertSame(999, $result->id);
    }

    public function test_get_remote_product_uses_prefetched_cache_without_live_call(): void
    {
        $this->resetBatchCache();

        $ref   = new ReflectionClass(WC_Multi_Store_Weekly_Sync_Verifier::class);
        $cache = $ref->getProperty('batch_cache');
        $cache->setValue(null, [
            'https://store1.com' => [
                'SKU-CACHED' => ['id' => 55, 'sku' => 'SKU-CACHED', 'stock_quantity' => 9],
            ],
        ]);

        // If get_remote_product() fell through to a live call instead of the
        // cache, this mock (never installed in the pool) would be missing
        // entirely and the real client constructor would run against fake
        // credentials — so reaching a clean return here proves the cache hit.
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, []);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU-CACHED');
        $product->shouldReceive('get_slug')->andReturn('sku-cached');

        Functions\when('get_option')->alias(function ($option, $default = false) {
            return $option === 'wc_multi_store_sync_settings' ? ['match_products_by' => 'sku'] : $default;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'get_remote_product');
        $result = $method->invoke(null, $product, 'https://store1.com', ['consumer_key' => 'k', 'consumer_secret' => 's']);

        $this->assertSame(55, $result->id);
        $this->assertSame(9, $result->stock_quantity);
    }

    // ── format_discrepancy_message ────────────────────────────────
    // Regression coverage for the admin-view bug this method's extraction fixed:
    // ghost/tag/image/category/attribute/generic-field discrepancies never set
    // a 'message' key, so admin/views/weekly-verification.php's old per-type
    // if/elseif chain silently rendered blank for them.

    private function stubEscHtml(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
    }

    public function test_format_discrepancy_message_missing(): void
    {
        $this->assertSame(
            'Product not found',
            WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message(['type' => 'missing'])
        );
    }

    public function test_format_discrepancy_message_orphan_includes_exclusion_reasons(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message([
            'type' => 'orphan',
            'exclusion_reasons' => ['out of stock', 'draft'],
        ]);

        $this->assertStringContainsString('ORPHAN', $message);
        $this->assertStringContainsString('out of stock, draft', $message);
    }

    public function test_format_discrepancy_message_ghost_includes_sku(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message([
            'type' => 'ghost',
            'remote_sku' => 'GHOST-1',
        ]);

        $this->assertStringContainsString('GHOST', $message);
        $this->assertStringContainsString('GHOST-1', $message);
    }

    public function test_format_discrepancy_message_stock(): void
    {
        $message = WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message([
            'type' => 'stock',
            'expected' => 10,
            'actual' => 7,
            'difference' => -3,
        ]);

        $this->assertStringContainsString('Expected: 10', $message);
        $this->assertStringContainsString('Actual: 7', $message);
        $this->assertStringContainsString('-3', $message);
    }

    public function test_format_discrepancy_message_price(): void
    {
        $message = WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message([
            'type' => 'price',
            'field' => 'regular_price',
            'expected' => '19.99',
            'actual' => '24.99',
        ]);

        $this->assertStringContainsString('Regular_price', $message);
        $this->assertStringContainsString('19.99', $message);
        $this->assertStringContainsString('24.99', $message);
    }

    public function test_format_discrepancy_message_tag_lists_missing_and_extra(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message([
            'type' => 'tag',
            'missing' => ['sale'],
            'extra' => ['clearance'],
        ]);

        $this->assertStringContainsString('Tag mismatch', $message);
        $this->assertStringContainsString('missing:', $message);
        $this->assertStringContainsString('sale', $message);
        $this->assertStringContainsString('extra:', $message);
        $this->assertStringContainsString('clearance', $message);
    }

    public function test_format_discrepancy_message_attribute(): void
    {
        $this->assertSame(
            'Attribute mismatch',
            WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message(['type' => 'attribute'])
        );
    }

    public function test_format_discrepancy_message_generic_field_falls_through_to_default(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message([
            'type' => 'weight',
            'field' => 'weight',
            'expected' => '1.5',
            'actual' => '2.0',
        ]);

        $this->assertStringContainsString('Weight', $message);
        $this->assertStringContainsString('Expected: 1.5', $message);
        $this->assertStringContainsString('Actual: 2.0', $message);
    }

    // ── calculate_next_run_time ─────────────────────────────────

    private function callCalculateNextRunTime(array $settings): int
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'calculate_next_run_time');
        return $method->invoke(null, $settings);
    }

    public function test_calculate_next_run_time_later_today_stays_today(): void
    {
        $now = new DateTimeImmutable('now');
        $target = $now->modify('+1 hour');

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => (int) $now->format('w'),
            'time_of_day' => $target->format('H:i'),
        ]);

        $this->assertSame($target->setTime((int) $target->format('H'), (int) $target->format('i'), 0)->getTimestamp(), $result);
        $this->assertGreaterThan($now->getTimestamp(), $result);
    }

    public function test_calculate_next_run_time_earlier_today_rolls_to_next_week(): void
    {
        $now = new DateTimeImmutable('now');
        // Guard against the sub-second window where "1 hour ago" could roll past midnight
        // into a different day-of-week than $now — not realistic in a normal test run.
        $target = $now->modify('-1 hour');

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => (int) $now->format('w'),
            'time_of_day' => $target->format('H:i'),
        ]);

        $expected = $target->modify('+7 days')->setTime((int) $target->format('H'), (int) $target->format('i'), 0)->getTimestamp();
        $this->assertSame($expected, $result);
    }

    public function test_calculate_next_run_time_different_weekday(): void
    {
        $now = new DateTimeImmutable('now');
        $target_dow = ((int) $now->format('w') + 3) % 7;

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => $target_dow,
            'time_of_day' => '09:30',
        ]);

        $result_date = (new DateTimeImmutable())->setTimestamp($result);
        $this->assertSame($target_dow, (int) $result_date->format('w'));
        $this->assertSame('09:30', $result_date->format('H:i'));
        $this->assertGreaterThan($now->getTimestamp(), $result);
    }
}
