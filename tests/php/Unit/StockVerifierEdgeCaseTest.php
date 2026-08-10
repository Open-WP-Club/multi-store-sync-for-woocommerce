<?php
/**
 * Edge case tests for WC_Multi_Store_Stock_Verifier
 * Covers: verify_product_stock with null/mismatch stock, discrepancy CRUD,
 * auto-correct flow, cleanup, edge cases
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class StockVerifierEdgeCaseTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMocks();
    }

    protected function setUpMocks(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('untrailingslashit')->alias(fn($str) => rtrim($str, '/'));
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('sanitize_sql_orderby')->alias(fn($val) => $val);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        // No stored remote-ID mapping by default — exercises the pre-existing
        // search-by-SKU/slug path unless a test explicitly stubs a stored ID.
        Functions\when('get_post_meta')->justReturn('');
    }

    // ── verify_product_stock: early returns ──────────────────────

    public function test_verify_product_not_found_returns_error(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(999, 'https://store.com', 10);

        $this->assertFalse($result['success']);
        $this->assertEquals('Product not found', $result['error']);
    }

    public function test_verify_product_no_sku_returns_error(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('');
        Functions\when('wc_get_product')->justReturn($product);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://store.com', 10);

        $this->assertFalse($result['success']);
        $this->assertEquals('Product has no SKU', $result['error']);
    }

    public function test_verify_store_not_in_config_returns_error(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('TEST-001');
        Functions\when('wc_get_product')->justReturn($product);

        // Stores config doesn't include the target store
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://other-store.com' => [
                        'url' => 'https://other-store.com',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                    ],
                ];
            }
            return $default;
        });

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://unknown-store.com', 10);

        $this->assertFalse($result['success']);
        $this->assertEquals('Store not found in configuration', $result['error']);
    }

    // ── verify_product_stock: full API path ─────────────────────

    /**
     * Helper to set up product + store + HTTP mocks for verify tests
     */
    private function setUpVerifyMocks(string $sku, string $store_url, string $responseBody): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_slug')->andReturn(strtolower($sku));
        $product->shouldReceive('get_id')->andReturn(1);
        Functions\when('wc_get_product')->justReturn($product);

        Functions\when('get_option')->alias(function ($option, $default = false) use ($store_url) {
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    $store_url => [
                        'url' => $store_url,
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                        'auth_method' => 'query_string',
                        'status' => 'active',
                    ],
                ];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['match_products_by' => 'sku'];
            }
            return $default;
        });

        $httpResponse = [
            'response' => ['code' => 200],
            'body' => $responseBody,
        ];
        Functions\when('wp_remote_request')->justReturn($httpResponse);
        Functions\when('wp_remote_get')->justReturn($httpResponse);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 2 && is_array($args[0])) {
                return $args[1] . '?' . http_build_query($args[0]);
            }
            return $args[count($args) - 1] ?? '';
        });
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('rest_url')->alias(fn($p = '') => 'https://example.com/wp-json/' . ltrim($p, '/'));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        // Exclusion filter: no categories/tags → product not excluded
        Functions\when('wp_get_post_terms')->justReturn([]);
    }

    // ── verify_product_stock: SKU/slug drift, stored remote-ID fallback ──

    public function test_verify_product_stock_resolves_via_stored_remote_id_when_sku_drifted(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('NEW-SKU');
        $product->shouldReceive('get_slug')->andReturn('new-slug');
        $product->shouldReceive('get_id')->andReturn(1);
        Functions\when('wc_get_product')->justReturn($product);

        $store_url = 'https://store.com';
        Functions\when('get_option')->alias(function ($option, $default = false) use ($store_url) {
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    $store_url => [
                        'url' => $store_url,
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                        'auth_method' => 'query_string',
                        'status' => 'active',
                    ],
                ];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['match_products_by' => 'sku'];
            }
            return $default;
        });

        // Product #1 has a stored remote ID (999) from its last successful
        // sync, even though its SKU has since changed locally.
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => ($post_id === 1 && str_contains($key, '_mss_remote_id_')) ? '999' : ''
        );

        $fallback_search_called = false;
        Functions\when('wp_remote_get')->alias(function ($url, $args = []) use (&$fallback_search_called) {
            if (str_contains($url, 'products/999')) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['id' => 999, 'sku' => 'OLD-SKU', 'stock_quantity' => 5]),
                ];
            }
            $fallback_search_called = true;
            return ['response' => ['code' => 200], 'body' => '[]'];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 2 && is_array($args[0])) {
                return $args[1] . '?' . http_build_query($args[0]);
            }
            return $args[count($args) - 1] ?? '';
        });
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('rest_url')->alias(fn($p = '') => 'https://example.com/wp-json/' . ltrim($p, '/'));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_get_post_terms')->justReturn([]);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, $store_url, 5);

        $this->assertFalse($fallback_search_called, 'Must not fall back to search-by-value once the stored ID resolves');
        $this->assertTrue($result['success']);
        $this->assertEquals(5, $result['actual_stock']);
        $this->assertFalse($result['has_discrepancy']);
    }

    public function test_verify_null_remote_stock_shows_discrepancy(): void
    {
        $this->setUpVerifyMocks(
            'NULL-STOCK',
            'https://store.com',
            json_encode([['id' => 42, 'stock_quantity' => null, 'sku' => 'NULL-STOCK']])
        );

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->andReturn(1);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://store.com', 10);

        $this->assertTrue($result['success']);
        $this->assertNull($result['actual_stock']);
        $this->assertTrue($result['has_discrepancy']);
    }

    public function test_verify_matching_stock_no_discrepancy(): void
    {
        $this->setUpVerifyMocks(
            'MATCH-STOCK',
            'https://store.com',
            json_encode([['id' => 42, 'stock_quantity' => 25, 'sku' => 'MATCH-STOCK']])
        );

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://store.com', 25);

        $this->assertTrue($result['success']);
        $this->assertEquals(25, $result['actual_stock']);
        $this->assertFalse($result['has_discrepancy']);
        $this->assertEquals(0, $result['difference']);
    }

    public function test_verify_stock_difference_calculated_correctly(): void
    {
        $this->setUpVerifyMocks(
            'DIFF-STOCK',
            'https://store.com',
            json_encode([['id' => 42, 'stock_quantity' => 3, 'sku' => 'DIFF-STOCK']])
        );

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->andReturn(1);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://store.com', 10);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['has_discrepancy']);
        $this->assertEquals(3, $result['actual_stock']);
        $this->assertEquals(-7, $result['difference']); // 3 - 10 = -7
    }

    public function test_verify_remote_product_not_found(): void
    {
        $this->setUpVerifyMocks('MISSING', 'https://store.com', '[]');

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://store.com', 10);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found on remote', $result['error']);
    }

    public function test_verify_result_contains_all_expected_fields(): void
    {
        $this->setUpVerifyMocks(
            'FIELDS-CHECK',
            'https://store.com',
            json_encode([['id' => 42, 'stock_quantity' => 10, 'sku' => 'FIELDS-CHECK']])
        );

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://store.com', 10);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('product_id', $result);
        $this->assertArrayHasKey('sku', $result);
        $this->assertArrayHasKey('store_url', $result);
        $this->assertArrayHasKey('expected_stock', $result);
        $this->assertArrayHasKey('actual_stock', $result);
        $this->assertArrayHasKey('has_discrepancy', $result);
        $this->assertArrayHasKey('difference', $result);
        $this->assertArrayHasKey('verified_at', $result);
    }

    // ── get_discrepancies ────────────────────────────────────────

    public function test_get_discrepancies_returns_empty_array(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_discrepancies_with_store_url_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => 1, 'product_id' => 42, 'store_url' => 'https://store.com', 'difference' => -5],
        ]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies([
            'store_url' => 'https://store.com',
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals('https://store.com', $result[0]['store_url']);
    }

    public function test_get_discrepancies_with_product_id_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => 1, 'product_id' => 42, 'difference' => 3],
        ]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies([
            'product_id' => 42,
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]['product_id']);
    }

    public function test_get_discrepancies_custom_limit_and_offset(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies([
            'limit' => 10,
            'offset' => 20,
            'orderby' => 'difference',
            'order' => 'ASC',
        ]);

        $this->assertIsArray($result);
    }

    // ── get_discrepancy_count ────────────────────────────────────

    public function test_get_discrepancy_count_all_skips_where_clause(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        // 'all' should NOT call prepare — uses a simple COUNT(*) without WHERE
        $wpdb->shouldReceive('get_var')->andReturn('25');

        $count = WC_Multi_Store_Stock_Verifier::get_discrepancy_count('all');

        $this->assertEquals(25, $count);
    }

    public function test_get_discrepancy_count_returns_zero_on_null(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(null);

        $count = WC_Multi_Store_Stock_Verifier::get_discrepancy_count('pending');

        $this->assertEquals(0, $count);
    }

    // ── mark_resolved ────────────────────────────────────────────

    public function test_mark_resolved_sets_status_and_timestamp(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                return $data['status'] === 'resolved'
                    && $data['resolved_at'] === '2024-01-15 12:00:00'
                    && $where['id'] === 42;
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Stock_Verifier::mark_resolved(42);

        $this->assertTrue($result);
    }

    public function test_mark_resolved_returns_false_on_db_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')->andReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::mark_resolved(999);

        $this->assertFalse($result);
    }

    // ── mark_ignored ─────────────────────────────────────────────

    public function test_mark_ignored_sets_status(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                return $data['status'] === 'ignored' && $where['id'] === 5;
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Stock_Verifier::mark_ignored(5);

        $this->assertTrue($result);
    }

    public function test_mark_ignored_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')->andReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::mark_ignored(999);

        $this->assertFalse($result);
    }

    // ── auto_correct ─────────────────────────────────────────────

    public function test_auto_correct_returns_error_when_discrepancy_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn(null);

        $result = WC_Multi_Store_Stock_Verifier::auto_correct(999);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    public function test_auto_correct_returns_error_when_product_deleted(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn([
            'id' => 1,
            'product_id' => 999,
            'sku' => 'DELETED-001',
            'store_url' => 'https://store.com',
        ]);

        Functions\when('wc_get_product')->justReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::auto_correct(1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('product_not_found', $result->get_error_code());
    }

    // ── cleanup_old_discrepancies ────────────────────────────────

    public function test_cleanup_deletes_old_resolved_and_ignored(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->once()->andReturn(15);

        $result = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies(60);

        $this->assertEquals(15, $result);
    }

    public function test_cleanup_returns_zero_when_nothing_to_delete(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        $result = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies(30);

        $this->assertEquals(0, $result);
    }

    public function test_cleanup_with_custom_days_parameter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->once()->andReturn(3);

        $result = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies(7);

        $this->assertEquals(3, $result);
    }

    public function test_cleanup_returns_false_on_query_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->once()->andReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies(30);

        $this->assertFalse($result);
    }

    // ── schedule_verification ────────────────────────────────────

    public function test_schedule_verification_does_nothing_when_action_scheduler_unavailable(): void
    {
        // Action Scheduler is not available in test environment
        WC_Multi_Store_Stock_Verifier::schedule_verification(1, 'https://store.com', 10);

        // Should return early without error
        $this->assertTrue(true);
    }

    // ── Discrepancy with multiple filters combined ───────────────

    public function test_get_discrepancies_all_filters_combined(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([
            [
                'id' => 1,
                'product_id' => 42,
                'sku' => 'COMBO-001',
                'store_url' => 'https://store.com',
                'expected_stock' => 10,
                'actual_stock' => 5,
                'difference' => -5,
                'status' => 'pending',
            ],
        ]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies([
            'status' => 'pending',
            'store_url' => 'https://store.com',
            'product_id' => 42,
            'limit' => 1,
            'offset' => 0,
            'orderby' => 'detected_at',
            'order' => 'DESC',
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals(-5, $result[0]['difference']);
        $this->assertEquals('pending', $result[0]['status']);
    }

    // ── Empty status filter ──────────────────────────────────────

    public function test_get_discrepancies_empty_status_returns_all(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'resolved'],
        ]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies([
            'status' => '',
        ]);

        $this->assertCount(2, $result);
    }
}
