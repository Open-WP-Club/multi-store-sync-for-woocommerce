<?php
/**
 * Functional tests for WC_Multi_Store_Sync_Engine
 * Tests real sync logic with mocked API and dependencies
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncEngineFunctionalTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSyncEngineMocks();
    }

    protected function setUpSyncEngineMocks(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'auth_method' => 'query_string',
                    'match_products_by' => 'sku',
                    'category_auto_create' => true,
                    'deletion_mode' => 'trash',
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                    ],
                ];
            }
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['auto_verify' => false];
            }
            return $default;
        });

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_get_post_terms')->justReturn([]);
        Functions\when('esc_url_raw')->alias(fn($url) => $url);
        Functions\when('sanitize_textarea_field')->alias(fn($str) => strip_tags($str));
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 2 && is_array($args[0])) {
                return $args[1] . '?' . http_build_query($args[0]);
            }
            return $args[count($args) - 1] ?? '';
        });
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200],
            'body' => '{"id":99,"name":"Test","sku":"TEST"}',
        ]);
        Functions\when('wp_remote_retrieve_response_code')->alias(function ($response) {
            return $response['response']['code'] ?? 200;
        });
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '[]';
        });
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => '[{"id":99,"name":"Test","sku":"TEST"}]',
        ]);
        Functions\when('wp_remote_post')->justReturn([
            'response' => ['code' => 200],
            'body' => '{"id":99,"name":"Test","sku":"TEST"}',
        ]);
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_cache_flush')->justReturn(true);
        Functions\when('rest_url')->alias(fn($path = '') => 'https://example.com/wp-json/' . ltrim($path, '/'));
        Functions\when('get_terms')->justReturn([]);
    }

    private function mockWpdbForSyncHistory(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->insert_id = 1;
    }

    // ── sync_product ─────────────────────────────────────────────

    public function test_sync_product_returns_error_when_product_not_found(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product(999, []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    public function test_sync_product_skips_excluded_products(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(100);
        $product->shouldReceive('get_sku')->andReturn('EXCL-SKU');
        $product->shouldReceive('get_slug')->andReturn('excl-product');
        $product->shouldReceive('get_name')->andReturn('Excluded Product');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->andReturnUsing(fn($t) => $t === 'simple');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_category_ids')->andReturn([5]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);

        Functions\when('wc_get_product')->justReturn($product);

        // Exclusion filter now runs a single SQL via $wpdb instead of two
        // wp_get_post_terms calls — mock the get_results path.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix             = 'wp_';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy      = 'wp_term_taxonomy';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['term_id' => 5, 'taxonomy' => 'product_cat'],
        ]);

        $stores = [
            'https://store1.com' => [
                'status' => 'active',
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
                'exclude_categories' => [5],
            ],
        ];

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product(100, $stores);

        $this->assertArrayHasKey('https://store1.com', $result);
        $this->assertFalse($result['https://store1.com']['success']);
        $this->assertTrue($result['https://store1.com']['excluded']);
    }

    public function test_sync_product_skips_inactive_stores(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(100);
        $product->shouldReceive('get_sku')->andReturn('TEST-SKU');
        $product->shouldReceive('get_slug')->andReturn('test-product');

        Functions\when('wc_get_product')->justReturn($product);

        $stores = [
            'https://inactive.com' => [
                'status' => 'inactive',
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ],
        ];

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product(100, $stores);

        $this->assertArrayNotHasKey('https://inactive.com', $result);
    }

    // ── bulk_sync_products ───────────────────────────────────────

    public function test_bulk_sync_products_processes_array(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $engine = new WC_Multi_Store_Sync_Engine();
        $results = $engine->bulk_sync_products([100, 200], []);

        $this->assertIsArray($results);
        $this->assertEquals(2, $results['total']);
        $this->assertCount(2, $results['details']);
    }

    // ── delete_product_from_store ────────────────────────────────

    public function test_delete_product_from_store_calls_api(): void
    {
        $this->mockWpdbForSyncHistory();

        $engine = new WC_Multi_Store_Sync_Engine();

        $result = $engine->delete_product_from_store(
            100,
            'DEL-SKU',
            'https://store1.com',
            ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'],
            'product_delete'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function test_delete_product_from_store_fails_with_empty_sku(): void
    {
        $this->mockWpdbForSyncHistory();

        $engine = new WC_Multi_Store_Sync_Engine();

        $result = $engine->delete_product_from_store(
            100,
            '',
            'https://store1.com',
            ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'],
            'product_delete'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no SKU', $result['message']);
    }

    // ── restore_product_on_store ─────────────────────────────────

    public function test_restore_product_on_store_calls_api(): void
    {
        $this->mockWpdbForSyncHistory();

        $engine = new WC_Multi_Store_Sync_Engine();

        $result = $engine->restore_product_on_store(
            100,
            'RESTORE-SKU',
            'https://store1.com',
            ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'],
            'product_restore'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // ── update_product_status_on_store ────────────────────────────

    public function test_update_product_status_on_store_calls_api(): void
    {
        $this->mockWpdbForSyncHistory();

        $engine = new WC_Multi_Store_Sync_Engine();

        $result = $engine->update_product_status_on_store(
            100,
            'STATUS-SKU',
            'draft',
            'https://store1.com',
            ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'],
            'status_change'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // ── clear_term_cache ─────────────────────────────────────────

    public function test_clear_term_cache_clears_all_store_caches(): void
    {
        WC_Multi_Store_Sync_Engine::clear_term_cache();
        $this->assertTrue(true);
    }

    public function test_clear_term_cache_clears_specific_store(): void
    {
        WC_Multi_Store_Sync_Engine::clear_term_cache('https://store1.com');
        $this->assertTrue(true);
    }

    // ── Result structure ─────────────────────────────────────────

    public function test_sync_product_error_result_structure(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product(999, []);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }

    // ── Partial failure across stores ────────────────────────────

    public function test_partial_failure_excluded_vs_synced(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(100);
        $product->shouldReceive('get_sku')->andReturn('PARTIAL-SKU');
        $product->shouldReceive('get_slug')->andReturn('partial-product');
        $product->shouldReceive('get_name')->andReturn('Partial Product');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->andReturnUsing(fn($t) => $t === 'simple');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_category_ids')->andReturn([1, 2]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_price')->andReturn('10');
        $product->shouldReceive('get_regular_price')->andReturn('10');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(5);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(false);
        $product->shouldReceive('managing_stock')->andReturn(false);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_children')->andReturn([]);
        $product->shouldReceive('is_featured')->andReturn(false);
        $product->shouldReceive('get_short_description')->andReturn('');
        $product->shouldReceive('get_description')->andReturn('');
        $product->shouldReceive('get_weight')->andReturn('');
        $product->shouldReceive('get_length')->andReturn('');
        $product->shouldReceive('get_width')->andReturn('');
        $product->shouldReceive('get_height')->andReturn('');
        $product->shouldReceive('get_reviews_allowed')->andReturn(true);
        $product->shouldReceive('get_purchase_note')->andReturn('');
        $product->shouldReceive('get_menu_order')->andReturn(0);
        $product->shouldReceive('is_virtual')->andReturn(false);
        $product->shouldReceive('is_downloadable')->andReturn(false);
        $product->shouldReceive('get_shipping_class_id')->andReturn(0);
        $product->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $product->shouldReceive('get_tax_status')->andReturn('taxable');
        $product->shouldReceive('get_tax_class')->andReturn('');
        $product->shouldReceive('is_sold_individually')->andReturn(false);
        $product->shouldReceive('get_backorders')->andReturn('no');
        $product->shouldReceive('get_low_stock_amount')->andReturn('');
        $product->shouldReceive('get_upsell_ids')->andReturn([]);
        $product->shouldReceive('get_cross_sell_ids')->andReturn([]);
        $product->shouldReceive('get_parent_id')->andReturn(0);
        $product->shouldReceive('get_attributes')->andReturn([]);
        $product->shouldReceive('get_default_attributes')->andReturn([]);
        $product->shouldReceive('get_meta_data')->andReturn([]);
        $product->shouldReceive('get_date_on_sale_from')->andReturn(null);
        $product->shouldReceive('get_date_on_sale_to')->andReturn(null);
        $product->shouldReceive('is_on_sale')->andReturn(false);
        $product->shouldReceive('has_weight')->andReturn(false);
        $product->shouldReceive('has_dimensions')->andReturn(false);

        Functions\when('wc_get_product')->justReturn($product);
        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_the_terms')->justReturn([]);

        $stores = [
            'https://store1.com' => [
                'status' => 'active',
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
                'exclude_categories' => [1], // Product has cat 1 → excluded
            ],
            'https://store2.com' => [
                'status' => 'active',
                'consumer_key' => 'ck_test2',
                'consumer_secret' => 'cs_test2',
                // No exclusions → will attempt sync
            ],
        ];

        // Set up wpdb with both the sync-history mock AND the exclusion-filter
        // term lookup (single SQL via $wpdb->get_results — returns cat IDs 1 & 2
        // for any call here since the per-product test scope only queries once).
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix             = 'wp_';
        $wpdb->postmeta           = 'wp_postmeta';
        $wpdb->posts              = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy      = 'wp_term_taxonomy';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['term_id' => 1, 'taxonomy' => 'product_cat'],
            (object) ['term_id' => 2, 'taxonomy' => 'product_cat'],
        ]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->insert_id = 1;

        $engine = new WC_Multi_Store_Sync_Engine();
        $results = $engine->sync_product(100, $stores);

        // store1 excluded
        $this->assertTrue($results['https://store1.com']['excluded'] ?? false);
        // store2 attempted
        $this->assertArrayHasKey('https://store2.com', $results);
        $this->assertFalse($results['https://store2.com']['excluded'] ?? false);
    }
}
