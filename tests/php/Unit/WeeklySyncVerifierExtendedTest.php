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

        if (!class_exists('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-remote-data-fetcher.php';
        }
        if (!class_exists('WC_Multi_Store_Weekly_Sync_Verifier', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-sync-verifier.php';
        }

        // Reset static API client pool and prefetch batch cache between tests
        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
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
        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
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

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
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

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
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

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
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

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
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

    // get_report / get_latest_report(-with-data) moved to
    // WeeklyVerificationReportRepositoryTest.php along with
    // WC_Multi_Store_Weekly_Verification_Report_Repository.

    // build_exclusion_tax_query / get_products_to_verify / scan_ghost_products /
    // build_remote_index / load_search_values_for_products /
    // prefetch_remote_batch_data / get_remote_product moved to
    // WeeklyVerificationRemoteDataFetcherTest.php along with
    // WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher.

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

    // format_discrepancy_message moved to WeeklyVerificationEmailNotifierTest.php
    // along with WC_Multi_Store_Weekly_Verification_Email_Notifier.

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
