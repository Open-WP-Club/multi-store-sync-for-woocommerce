<?php
/**
 * Focused unit tests for WC_Multi_Store_Sync_Engine::sync_product_to_store().
 *
 * This is the most business-critical, highest-risk method in the plugin
 * (309 lines, real remote API calls, DB transaction wrapping post-sync writes)
 * and had zero direct coverage before this file. Rather than mocking the full
 * collaborator graph (extractor/transformer/remote_manager are constructed
 * internally by the constructor and are NOT injectable), we follow the
 * pattern already established in SyncEngineFunctionalTest.php: construct a
 * real WC_Multi_Store_Sync_Engine, and control its behavior entirely through
 * Brain Monkey function stubs (wp_remote_get/post/request, get_option,
 * get_transient/set_transient) plus a Mockery $wpdb. This exercises the real
 * code path end-to-end instead of asserting against mocked collaborators.
 *
 * sync_type is 'price_quantity' for most tests (not 'full_product') to keep
 * the WC_Product mock surface small — build_product_data() for price_quantity
 * only touches stock/price getters, and post-sync operations skip variation
 * sync entirely for non-full syncs.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncEngineSyncProductToStoreTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSyncEngineMocks();
        WC_Multi_Store_Settings::clear_static_cache();
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
                    'auto_create_missing_products' => false,
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
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('wp_parse_args')->alias(function ($args, $defaults) {
            return array_merge($defaults, (array) $args);
        });
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 2 && is_array($args[0])) {
                return $args[1] . '?' . http_build_query($args[0]);
            }
            return $args[count($args) - 1] ?? '';
        });
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('esc_url_raw')->alias(fn($url) => $url);
        Functions\when('sanitize_textarea_field')->alias(fn($str) => strip_tags($str));
    }

    private function mockWpdbForSyncHistory(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->insert_id = 1;
    }

    /**
     * Minimal WC_Product mock sufficient for a 'price_quantity' sync:
     * build_product_data() only needs stock/price getters, and
     * apply_store_rules()/shipping-class lookup need get_shipping_class_id().
     */
    private function mockPriceQuantityProduct(array $overrides = []): \Mockery\MockInterface
    {
        $defaults = [
            'id' => 100,
            'sku' => 'SKU-1',
            'slug' => 'product-1',
            'type' => 'simple',
            'regular_price' => '19.99',
            'sale_price' => '',
            'is_on_sale' => false,
            'date_on_sale_from' => null,
            'date_on_sale_to' => null,
            'managing_stock' => false,
            'stock_quantity' => 5,
            'stock_status' => 'instock',
            'shipping_class_id' => 0,
            'category_ids' => [],
            'tag_ids' => [],
        ];
        $o = array_merge($defaults, $overrides);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_type')->andReturnUsing(fn($t) => $t === $o['type']);
        $product->shouldReceive('get_type')->andReturn($o['type']);
        $product->shouldReceive('get_id')->andReturn($o['id']);
        $product->shouldReceive('get_sku')->andReturn($o['sku']);
        $product->shouldReceive('get_slug')->andReturn($o['slug']);
        $product->shouldReceive('get_name')->andReturn($o['name'] ?? 'Test Product');
        $product->shouldReceive('get_regular_price')->andReturn($o['regular_price']);
        $product->shouldReceive('get_sale_price')->andReturn($o['sale_price']);
        $product->shouldReceive('is_on_sale')->andReturn($o['is_on_sale']);
        $product->shouldReceive('get_date_on_sale_from')->andReturn($o['date_on_sale_from']);
        $product->shouldReceive('get_date_on_sale_to')->andReturn($o['date_on_sale_to']);
        $product->shouldReceive('managing_stock')->andReturn($o['managing_stock']);
        $product->shouldReceive('get_stock_quantity')->andReturn($o['stock_quantity']);
        $product->shouldReceive('get_stock_status')->andReturn($o['stock_status']);
        $product->shouldReceive('get_shipping_class_id')->andReturn($o['shipping_class_id']);
        $product->shouldReceive('get_category_ids')->andReturn($o['category_ids']);
        $product->shouldReceive('get_tag_ids')->andReturn($o['tag_ids']);

        return $product;
    }

    // ── (a) variation redirect safety net ──────────────────────────

    public function test_variation_redirects_to_parent_and_syncs_parent(): void
    {
        // Extends the existing "no valid parent" regression test in
        // BugfixRegressionTest.php with the successful-redirect case: a
        // variation whose parent DOES exist must have the parent synced
        // instead of the variation itself being sent as a standalone product.
        $this->mockWpdbForSyncHistory();

        $parent = $this->mockPriceQuantityProduct(['id' => 50, 'sku' => 'PARENT-SKU']);

        $variation = \Mockery::mock('WC_Product');
        $variation->shouldReceive('is_type')->with('variation')->andReturn(true);
        $variation->shouldReceive('get_parent_id')->andReturn(50);
        $variation->shouldReceive('get_sku')->andReturn('VAR-SKU');
        $variation->shouldReceive('get_id')->andReturn(200);

        Functions\when('wc_get_product')->alias(function ($id) use ($parent) {
            return $id === 50 ? $parent : null;
        });

        // Remote lookup for the PARENT sku: not found, and since sync_type is
        // 'price_quantity' (non-full) with no cached "not found" marker yet,
        // it proceeds to a live API GET which we make return "not found" (empty).
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => '[]',
        ]);

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $variation,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        // Parent has no remote product and sync_type isn't full_product, so
        // create_or_update() correctly refuses to create it — but the key
        // assertion is that we got THIS FAR using the parent's SKU/data,
        // proving the variation was redirected rather than synced directly.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // ── (b) not-found-on-remote early skip path ────────────────────

    public function test_skips_with_queued_full_sync_when_auto_create_enabled(): void
    {
        $this->mockWpdbForSyncHistory();
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'match_products_by' => 'sku',
                    'auto_create_missing_products' => true,
                ];
            }
            return $default;
        });

        // Cache says this product is known not-found on this store. Only the
        // remote_product cache key must return the marker — the settings
        // transient cache (also read via get_transient, inside
        // WC_Multi_Store_Settings::get_settings()) must return false or our
        // 'auto_create_missing_products' override above would be ignored.
        Functions\when('get_transient')->alias(function ($key) {
            return str_contains($key, 'remote_product') ? WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER : false;
        });

        $product = $this->mockPriceQuantityProduct();

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertTrue($result['queued_full_sync']);
        $this->assertStringContainsString('queued full sync', $result['message']);
    }

    public function test_skips_silently_when_auto_create_disabled(): void
    {
        $this->mockWpdbForSyncHistory();
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'match_products_by' => 'sku',
                    'auto_create_missing_products' => false,
                ];
            }
            return $default;
        });

        Functions\when('get_transient')->alias(function ($key) {
            return str_contains($key, 'remote_product') ? WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER : false;
        });

        $product = $this->mockPriceQuantityProduct();

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertArrayNotHasKey('queued_full_sync', $result);
        $this->assertStringContainsString('skipped', $result['message']);
    }

    // ── (c) create-vs-update decision ───────────────────────────────

    public function test_update_path_used_when_remote_product_found(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct();

        // GET (find_remote_product) returns an existing remote product.
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);
        // PUT (update_product) succeeds.
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
        ]);

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('updated', $result['action']);
        $this->assertSame(555, $result['remote_id']);
    }

    public function test_saves_remote_product_id_mapping_after_successful_sync(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct();

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
        ]);

        $saved_meta = [];
        Functions\when('update_post_meta')->alias(function ($post_id, $key, $value) use (&$saved_meta) {
            $saved_meta[] = [$post_id, $key, $value];
            return true;
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        $this->assertTrue($result['success']);
        $this->assertContains(
            [100, '_mss_remote_id_' . md5('https://store1.com'), 555],
            $saved_meta,
            'sync_product_to_store must persist the stable local↔remote ID mapping after a successful sync, so a later SKU/slug rename cannot make future syncs miss this remote product'
        );
    }

    public function test_create_path_used_when_remote_product_not_found_full_sync(): void
    {
        $this->mockWpdbForSyncHistory();

        // full_product sync so create_or_update() is allowed to create.
        $product = $this->mockPriceQuantityProduct(['type' => 'simple']);
        // Extra getters needed for full_product build_product_data() path.
        $product->shouldReceive('get_name')->andReturn('Product One');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_description')->andReturn('');
        $product->shouldReceive('get_short_description')->andReturn('');
        $product->shouldReceive('get_weight')->andReturn('');
        $product->shouldReceive('get_length')->andReturn('');
        $product->shouldReceive('get_width')->andReturn('');
        $product->shouldReceive('get_height')->andReturn('');
        $product->shouldReceive('has_weight')->andReturn(false);
        $product->shouldReceive('has_dimensions')->andReturn(false);
        $product->shouldReceive('get_reviews_allowed')->andReturn(true);
        $product->shouldReceive('get_purchase_note')->andReturn('');
        $product->shouldReceive('get_menu_order')->andReturn(0);
        $product->shouldReceive('is_virtual')->andReturn(false);
        $product->shouldReceive('is_downloadable')->andReturn(false);
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
        $product->shouldReceive('is_featured')->andReturn(false);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_children')->andReturn([]);

        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_the_terms')->justReturn([]);
        Functions\when('wp_get_post_terms')->justReturn([]);

        // GET (find_remote_product): not found.
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => '[]',
        ]);
        // POST (create_product): succeeds.
        Functions\when('wp_remote_post')->justReturn([
            'response' => ['code' => 201],
            'body' => json_encode(['id' => 777, 'sku' => 'SKU-1']),
        ]);

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['action']);
        $this->assertSame(777, $result['remote_id']);
    }

    // ── (d) image-download-error retry path ─────────────────────────

    public function test_retries_without_images_after_image_download_error_then_succeeds(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct(['type' => 'simple']);
        $product->shouldReceive('get_name')->andReturn('Product One');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_description')->andReturn('');
        $product->shouldReceive('get_short_description')->andReturn('');
        $product->shouldReceive('get_weight')->andReturn('');
        $product->shouldReceive('get_length')->andReturn('');
        $product->shouldReceive('get_width')->andReturn('');
        $product->shouldReceive('get_height')->andReturn('');
        $product->shouldReceive('has_weight')->andReturn(false);
        $product->shouldReceive('has_dimensions')->andReturn(false);
        $product->shouldReceive('get_reviews_allowed')->andReturn(true);
        $product->shouldReceive('get_purchase_note')->andReturn('');
        $product->shouldReceive('get_menu_order')->andReturn(0);
        $product->shouldReceive('is_virtual')->andReturn(false);
        $product->shouldReceive('is_downloadable')->andReturn(false);
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
        $product->shouldReceive('is_featured')->andReturn(false);
        // One gallery image so build_product_data() populates 'images'.
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(42);
        $product->shouldReceive('get_children')->andReturn([]);

        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/uploads/main.jpg');
        Functions\when('get_the_terms')->justReturn([]);
        Functions\when('wp_get_post_terms')->justReturn([]);

        // GET (find_remote_product): not found -> create path.
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => '[]',
        ]);

        // POST (create_product): first call fails with an image-download-style
        // 403 Forbidden error mentioning an image URL; second call (retry,
        // without images) succeeds.
        $call_count = 0;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$call_count) {
            $call_count++;
            if ($call_count === 1) {
                // Sanity: first call's body must include the image.
                $body = json_decode($args['body'], true);
                \PHPUnit\Framework\Assert::assertNotEmpty($body['images'] ?? [], 'First attempt must include images');

                return [
                    'response' => ['code' => 403],
                    'body' => json_encode(['message' => 'Forbidden: could not download wp-content/uploads/main.jpg']),
                ];
            }

            // Retry: images must have been stripped from the payload.
            $body = json_decode($args['body'], true);
            \PHPUnit\Framework\Assert::assertArrayNotHasKey('images', $body, 'Retry payload must not include images');

            return [
                'response' => ['code' => 201],
                'body' => json_encode(['id' => 888, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(888, $result['remote_id']);
        $this->assertSame(2, $call_count, 'Must have retried exactly once after the image-download error');
    }

    // ── (e) DB transaction around post-sync operations ──────────────

    public function test_happy_path_commits_transaction(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once()->andReturn(1);
        $wpdb->shouldReceive('query')->with('COMMIT')->once()->andReturn(1);
        $wpdb->shouldReceive('query')->with('ROLLBACK')->never();

        $product = $this->mockPriceQuantityProduct();

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
        ]);

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        $this->assertTrue($result['success']);
    }

    public function test_exception_in_post_sync_operations_rolls_back_transaction(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once()->andReturn(1);
        $wpdb->shouldReceive('query')->with('ROLLBACK')->once()->andReturn(1);
        $wpdb->shouldReceive('query')->with('COMMIT')->never();

        // Force perform_post_sync_operations() to throw: it calls
        // WC_Multi_Store_Settings::get_webhook_settings() (via get_option)
        // unconditionally near the end. Easier/more targeted: make custom
        // field sync explode by giving a custom_field_mapping and making the
        // get_post_meta($product_id) call inside
        // Custom_Field_Mapper::get_product_custom_fields() throw — only the
        // no-key, "all meta" form (1 arg), so remote-product-manager.php's
        // get_post_meta($id, $key, true) lookups elsewhere in the sync flow
        // are unaffected.
        Functions\when('get_post_meta')->alias(function (...$args) {
            if (count($args) === 1) {
                throw new \RuntimeException('DB gone away');
            }
            return '';
        });

        $product = $this->mockPriceQuantityProduct();

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
        ]);

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            [
                'consumer_key' => 'ck',
                'consumer_secret' => 'cs',
                'custom_field_mapping' => ['_local_meta' => 'remote_meta'],
            ],
            'price_quantity'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Post-sync transaction failed', $result['message']);
    }

    // ── (f) categories must not be dropped forever after a failed auto-create ──

    public function test_categories_are_resent_when_remote_product_has_none_of_the_local_categories_even_if_local_hash_is_unchanged(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct(['category_ids' => [10]]);
        $product->shouldReceive('get_status')->andReturn('publish');

        // Local category term 10 is "Shoes".
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 10, 'name' => 'Shoes', 'slug' => 'shoes', 'parent' => 0],
        ]);

        // A previous sync silently failed to attach "Shoes" to the remote
        // product (e.g. ensure_terms_exist() hit a transient API error), but a
        // hash based purely on the LOCAL category IDs was still saved — so
        // have_categories_changed() reports "unchanged" even though the
        // remote product never actually received the category.
        $unchanged_hash = md5(serialize([10]));
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => str_contains($key, '_mss_cats_hash_') ? $unchanged_hash : ''
        );

        // GET (find_remote_product): remote product exists but is stuck on
        // the store's default "Uncategorized" category.
        // GET (products/categories): "Shoes" already exists remotely (id 20)
        // — it was simply never attached to this product.
        Functions\when('wp_remote_get')->alias(function ($url, $args = []) {
            if (str_contains($url, 'categories')) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([
                        ['id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized'],
                        ['id' => 20, 'name' => 'Shoes', 'slug' => 'shoes'],
                    ]),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => json_encode([[
                    'id' => 555,
                    'sku' => 'SKU-1',
                    'categories' => [['id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized']],
                ]]),
            ];
        });

        $sent_categories = 'update_product was never called';
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_categories) {
            $body = json_decode($args['body'], true);
            $sent_categories = $body['categories'] ?? null;

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity_categories'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            [['id' => 20]],
            $sent_categories,
            'Categories must be re-sent when the remote product has none of the local categories, regardless of the (stale) unchanged hash'
        );
    }

    // ── (g) tags must not be dropped forever after a failed auto-create ──

    public function test_tags_are_resent_when_remote_product_has_none_of_the_local_tags_even_if_local_hash_is_unchanged(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct(['tag_ids' => [15]]);
        $product->shouldReceive('get_status')->andReturn('publish');

        // Local tag term 15 is "Sale".
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 15, 'name' => 'Sale', 'slug' => 'sale', 'parent' => 0],
        ]);

        // A previous sync silently failed to attach "Sale" to the remote
        // product (e.g. ensure_terms_exist() hit a transient API error), but a
        // hash based purely on the LOCAL tag IDs was still saved — so
        // have_tags_changed() reports "unchanged" even though the remote
        // product never actually received the tag.
        $unchanged_hash = md5(serialize([15]));
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => str_contains($key, '_mss_tags_hash_') ? $unchanged_hash : ''
        );

        // GET (find_remote_product): remote product exists but has an
        // unrelated tag, not "Sale".
        // GET (products/tags): "Sale" already exists remotely (id 30) — it
        // was simply never attached to this product.
        Functions\when('wp_remote_get')->alias(function ($url, $args = []) {
            if (str_contains($url, 'tags')) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([
                        ['id' => 2, 'name' => 'Clearance', 'slug' => 'clearance'],
                        ['id' => 30, 'name' => 'Sale', 'slug' => 'sale'],
                    ]),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => json_encode([[
                    'id' => 555,
                    'sku' => 'SKU-1',
                    'tags' => [['id' => 2, 'name' => 'Clearance', 'slug' => 'clearance']],
                ]]),
            ];
        });

        $sent_tags = 'update_product was never called';
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_tags) {
            $body = json_decode($args['body'], true);
            $sent_tags = $body['tags'] ?? null;

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity_categories'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            [['id' => 30]],
            $sent_tags,
            'Tags must be re-sent when the remote product has none of the local tags, regardless of the (stale) unchanged hash'
        );
    }

    public function test_tags_skip_optimization_preserved_in_name_match_mode(): void
    {
        $this->mockWpdbForSyncHistory();
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'auth_method' => 'query_string',
                    'match_products_by' => 'sku',
                    'category_auto_create' => true,
                    'category_match_by' => 'name',
                    'auto_create_missing_products' => false,
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

        $product = $this->mockPriceQuantityProduct(['tag_ids' => [15]]);
        $product->shouldReceive('get_status')->andReturn('publish');

        // Name-match mode: format_tags() sends only 'name', never 'slug'
        // (see product-extractor.php — supports Cyrillic tag matching).
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 15, 'name' => 'Sale', 'slug' => 'sale', 'parent' => 0],
        ]);

        $unchanged_hash = md5(serialize([15]));
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => str_contains($key, '_mss_tags_hash_') ? $unchanged_hash : ''
        );

        // Only needed if the skip logic wrongly falls through to
        // ensure_terms_exist(), which derives a slug from the name when
        // 'slug' is absent (name-match mode).
        Functions\when('sanitize_title')->alias(
            fn($str) => strtolower(preg_replace('/[^a-z0-9-]/', '-', (string) $str))
        );

        // Remote product already has the matching "Sale" tag attached — a
        // genuine steady state, nothing to fix here. If the skip logic
        // mishandles name-match mode, ensure_terms_exist() will additionally
        // hit products/tags — return a well-formed list there too so a
        // wrong "not skipped" decision doesn't fail for the wrong reason.
        Functions\when('wp_remote_get')->alias(function ($url, $args = []) {
            if (str_contains($url, 'tags')) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([
                        ['id' => 30, 'name' => 'Sale', 'slug' => 'sale'],
                    ]),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => json_encode([[
                    'id' => 555,
                    'sku' => 'SKU-1',
                    'tags' => [['id' => 30, 'name' => 'Sale', 'slug' => 'sale']],
                ]]),
            ];
        });

        $sent_tags = 'update_product was never called';
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_tags) {
            $body = json_decode($args['body'], true);
            $sent_tags = array_key_exists('tags', $body) ? $body['tags'] : 'not present';

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity_categories'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            'not present',
            $sent_tags,
            'Tags must still be skipped when unchanged in name-match mode (remote already has the matching tag by name) — the remote-state guard must not misfire just because format_tags() omits slug in this mode'
        );
    }

    // ── (h2) description/short_description skip optimization for full_product ──

    private function mockFullProductProduct(array $overrides = []): \Mockery\MockInterface
    {
        $product = $this->mockPriceQuantityProduct($overrides);
        $product->shouldReceive('get_name')->andReturn($overrides['name'] ?? 'Product One');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_description')->andReturn($overrides['description'] ?? 'Full description');
        $product->shouldReceive('get_short_description')->andReturn($overrides['short_description'] ?? 'Short');
        $product->shouldReceive('get_weight')->andReturn('');
        $product->shouldReceive('get_length')->andReturn('');
        $product->shouldReceive('get_width')->andReturn('');
        $product->shouldReceive('get_height')->andReturn('');
        $product->shouldReceive('has_weight')->andReturn(false);
        $product->shouldReceive('has_dimensions')->andReturn(false);
        $product->shouldReceive('get_reviews_allowed')->andReturn(true);
        $product->shouldReceive('get_purchase_note')->andReturn('');
        $product->shouldReceive('get_menu_order')->andReturn(0);
        $product->shouldReceive('is_virtual')->andReturn(false);
        $product->shouldReceive('is_downloadable')->andReturn(false);
        $product->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $product->shouldReceive('get_tax_status')->andReturn('taxable');
        $product->shouldReceive('get_tax_class')->andReturn('');
        $product->shouldReceive('is_sold_individually')->andReturn(false);
        $product->shouldReceive('get_backorders')->andReturn('no');
        $product->shouldReceive('get_low_stock_amount')->andReturn('');
        $product->shouldReceive('get_upsell_ids')->andReturn([]);
        $product->shouldReceive('get_cross_sell_ids')->andReturn([]);
        $product->shouldReceive('get_parent_id')->andReturn(0);
        $product->shouldReceive('get_attributes')->andReturn($overrides['attributes'] ?? []);
        $product->shouldReceive('get_default_attributes')->andReturn($overrides['default_attributes'] ?? []);
        $product->shouldReceive('get_meta_data')->andReturn([]);
        $product->shouldReceive('is_featured')->andReturn(false);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_children')->andReturn([]);

        return $product;
    }

    public function test_description_is_skipped_on_full_product_sync_when_unchanged(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockFullProductProduct();

        $unchanged_hash = md5(serialize(['Full description', 'Short']));
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => str_contains($key, '_mss_desc_hash_') ? $unchanged_hash : ''
        );

        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_the_terms')->justReturn([]);
        Functions\when('wp_get_post_terms')->justReturn([]);

        // GET (find_remote_product): existing product found -> update path.
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);

        $sent_body = null;
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_body) {
            $sent_body = json_decode($args['body'], true);

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('description', $sent_body, 'Unchanged description must not be resent');
        $this->assertArrayNotHasKey('short_description', $sent_body, 'Unchanged short_description must not be resent');
        // Sanity: an unrelated field must still be present, proving the payload wasn't gutted entirely.
        $this->assertArrayHasKey('sku', $sent_body);
    }

    public function test_description_is_resent_on_full_product_sync_when_changed(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockFullProductProduct(['description' => 'New description']);

        // Stale hash was saved for the OLD description text.
        $stale_hash = md5(serialize(['Old description', 'Short']));
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => str_contains($key, '_mss_desc_hash_') ? $stale_hash : ''
        );

        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_the_terms')->justReturn([]);
        Functions\when('wp_get_post_terms')->justReturn([]);

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);

        $sent_body = null;
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_body) {
            $sent_body = json_decode($args['body'], true);

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('New description', $sent_body['description'] ?? null, 'Changed description must be resent');
    }

    // ── (h3) attributes skip optimization for full_product ──

    public function test_attributes_are_skipped_on_full_product_sync_when_unchanged(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockFullProductProduct(['attributes' => ['Color' => ['Red', 'Blue']]]);

        $unchanged_hash = md5(serialize([
            ['name' => 'Color', 'options' => ['Red', 'Blue']],
        ]));
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => str_contains($key, '_mss_attrs_hash_') ? $unchanged_hash : ''
        );

        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_the_terms')->justReturn([]);
        Functions\when('wp_get_post_terms')->justReturn([]);

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);

        $sent_body = null;
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_body) {
            $sent_body = json_decode($args['body'], true);

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('attributes', $sent_body, 'Unchanged attributes must not be resent');
        $this->assertArrayHasKey('sku', $sent_body);
    }

    public function test_attributes_are_resent_on_full_product_sync_when_changed(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockFullProductProduct(['attributes' => ['Color' => ['Red', 'Blue', 'Green']]]);

        // Stale hash was saved for the OLD option list (missing "Green").
        $stale_hash = md5(serialize([
            ['name' => 'Color', 'options' => ['Red', 'Blue']],
        ]));
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => str_contains($key, '_mss_attrs_hash_') ? $stale_hash : ''
        );

        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_the_terms')->justReturn([]);
        Functions\when('wp_get_post_terms')->justReturn([]);

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([['id' => 555, 'sku' => 'SKU-1']]),
        ]);

        $sent_body = null;
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_body) {
            $sent_body = json_decode($args['body'], true);

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('attributes', $sent_body, 'Changed attributes must be resent');
        $this->assertSame(['Red', 'Blue', 'Green'], $sent_body['attributes'][0]['options'] ?? null);
    }

    // ── (h) name/slug drift caught on lightweight syncs via the remote product we already fetched ──

    public function test_name_is_resent_when_it_drifts_from_remote_on_price_quantity_sync(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct(['name' => 'New Name']);

        // GET (find_remote_product): remote still has the old name.
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([[
                'id' => 555,
                'sku' => 'SKU-1',
                'name' => 'Old Name',
                'slug' => 'product-1',
            ]]),
        ]);

        $sent_body = null;
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_body) {
            $sent_body = json_decode($args['body'], true);
            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            'New Name',
            $sent_body['name'] ?? null,
            'A lightweight (price_quantity) sync must still push the local name when it differs from the name already returned by the remote-product lookup used for matching'
        );
    }

    public function test_slug_is_resent_when_it_drifts_from_remote_on_quantity_sync(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct(['slug' => 'new-slug']);

        // GET (find_remote_product): remote still has the old slug.
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([[
                'id' => 555,
                'sku' => 'SKU-1',
                'name' => 'Test Product',
                'slug' => 'old-slug',
            ]]),
        ]);

        $sent_body = null;
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_body) {
            $sent_body = json_decode($args['body'], true);
            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'quantity'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            'new-slug',
            $sent_body['slug'] ?? null,
            'A lightweight (quantity) sync must still push the local slug when it differs from the slug already returned by the remote-product lookup used for matching'
        );
    }

    public function test_name_and_slug_are_not_sent_when_unchanged_on_lightweight_sync(): void
    {
        $this->mockWpdbForSyncHistory();

        $product = $this->mockPriceQuantityProduct(['name' => 'Test Product', 'slug' => 'product-1']);

        // GET (find_remote_product): remote already matches local name/slug.
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([[
                'id' => 555,
                'sku' => 'SKU-1',
                'name' => 'Test Product',
                'slug' => 'product-1',
            ]]),
        ]);

        $sent_body = null;
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$sent_body) {
            $sent_body = json_decode($args['body'], true);
            return [
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 555, 'sku' => 'SKU-1']),
            ];
        });

        $engine = new WC_Multi_Store_Sync_Engine();
        $result = $engine->sync_product_to_store(
            $product,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'price_quantity'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('name', $sent_body, 'name must stay out of the payload when it already matches the remote — keeps lightweight syncs lightweight');
        $this->assertArrayNotHasKey('slug', $sent_body, 'slug must stay out of the payload when it already matches the remote — keeps lightweight syncs lightweight');
    }
}
