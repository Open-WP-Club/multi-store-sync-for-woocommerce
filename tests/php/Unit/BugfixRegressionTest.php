<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Regression tests for bugfixes applied to the sync system
 *
 * Tests cover:
 * 1. Webhook processing flag prevents infinite sync loops
 * 2. Sale price cleared when product is no longer on sale
 * 3. Variation batch chunking for >100 items
 * 4. Batch partial failure logging
 * 5. Sync engine redirects variations to parent product
 * 6. Variation save queues parent product ID, not variation ID
 * 7. Variation sale price cleared when not on sale
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class BugfixRegressionTest extends WC_Multi_Store_TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // FIX: WC_MSS_WEBHOOK_PROCESSING prevents infinite sync loops
    // ═══════════════════════════════════════════════════════════════

    /**
     * on_product_save must skip when WC_MSS_WEBHOOK_PROCESSING is defined.
     * Without this check, webhook receiver calling $product->save() would
     * re-queue the product, creating an infinite sync loop.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_on_product_save_skips_during_webhook_processing(): void
    {
        // Must run in separate process because define() pollutes global state
        require_once dirname(__DIR__) . '/bootstrap.php';

        define('WC_MSS_WEBHOOK_PROCESSING', true);

        $this->setUpHooksMocks();
        WC_Multi_Store_Hooks::clear_settings_cache();

        $hooks = new WC_Multi_Store_Hooks();

        // wpdb insert should NOT be called because webhook flag is set
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('insert');

        $hooks->on_product_save(100);
        $this->assertTrue(true, 'on_product_save should skip when WC_MSS_WEBHOOK_PROCESSING is defined');
    }

    /**
     * on_new_product must skip queuing when webhook processing is active.
     * Uses the is_processing_webhook flag (avoids separate-process constraint of define()).
     */
    public function test_on_new_product_skips_during_webhook_processing(): void
    {
        $this->setUpHooksMocks();
        WC_Multi_Store_Hooks::clear_settings_cache();

        WC_Multi_Store_Webhook_Receiver::$is_processing_webhook = true;

        $sync = WC_Multi_Store_Sync::instance();
        $original_qm = $sync->queue_manager;
        $queue_mock = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $queue_mock->shouldNotReceive('add_product');
        $sync->queue_manager = $queue_mock;

        try {
            $hooks = new WC_Multi_Store_Hooks();
            $hooks->on_new_product(100);
        } finally {
            $sync->queue_manager = $original_qm;
        }

        $this->assertTrue(true);
    }

    /**
     * on_variation_save must skip queuing when webhook processing is active.
     */
    public function test_on_variation_save_skips_during_webhook_processing(): void
    {
        $this->setUpHooksMocks();
        WC_Multi_Store_Hooks::clear_settings_cache();

        WC_Multi_Store_Webhook_Receiver::$is_processing_webhook = true;

        $sync = WC_Multi_Store_Sync::instance();
        $original_qm = $sync->queue_manager;
        $queue_mock = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $queue_mock->shouldNotReceive('add_product');
        $sync->queue_manager = $queue_mock;

        try {
            $hooks = new WC_Multi_Store_Hooks();
            $hooks->on_variation_save(200);
        } finally {
            $sync->queue_manager = $original_qm;
        }

        $this->assertTrue(true);
    }

    /**
     * on_stock_change must skip queuing when webhook processing is active.
     */
    public function test_on_stock_change_skips_during_webhook_processing(): void
    {
        $this->setUpHooksMocks();
        WC_Multi_Store_Hooks::clear_settings_cache();

        WC_Multi_Store_Webhook_Receiver::$is_processing_webhook = true;

        $sync = WC_Multi_Store_Sync::instance();
        $original_qm = $sync->queue_manager;
        $queue_mock = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $queue_mock->shouldNotReceive('add_product');
        $sync->queue_manager = $queue_mock;

        $product = \Mockery::mock('WC_Product');

        try {
            $hooks = new WC_Multi_Store_Hooks();
            $hooks->on_stock_change($product);
        } finally {
            $sync->queue_manager = $original_qm;
        }

        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════════════════════════
    // FIX: Sale price cleared when product is no longer on sale
    // ═══════════════════════════════════════════════════════════════

    /**
     * get_price_data must send empty sale_price when product is not on sale,
     * so the remote store clears any stale sale that ended locally.
     */
    public function test_price_data_clears_sale_price_when_not_on_sale(): void
    {
        $product = $this->createMockProduct([
            'regular_price' => '100.00',
            'is_on_sale' => false,
        ]);

        $extractor = new WC_Multi_Store_Product_Extractor();
        $data = $extractor->get_price_data($product);

        $this->assertArrayHasKey('sale_price', $data);
        $this->assertSame('', $data['sale_price']);
        $this->assertNull($data['date_on_sale_from']);
        $this->assertNull($data['date_on_sale_to']);
    }

    /**
     * get_price_data must still send sale_price and dates when on sale
     */
    public function test_price_data_sends_sale_price_when_on_sale(): void
    {
        // Use mock objects with date() method instead of WC_DateTime
        $sale_from = \Mockery::mock();
        $sale_from->shouldReceive('date')->with('Y-m-d H:i:s')->andReturn('2024-01-01 00:00:00');
        $sale_to = \Mockery::mock();
        $sale_to->shouldReceive('date')->with('Y-m-d H:i:s')->andReturn('2024-12-31 23:59:59');

        $product = $this->createMockProduct([
            'regular_price' => '100.00',
            'is_on_sale' => true,
            'sale_price' => '75.00',
            'date_on_sale_from' => $sale_from,
            'date_on_sale_to' => $sale_to,
        ]);

        $extractor = new WC_Multi_Store_Product_Extractor();
        $data = $extractor->get_price_data($product);

        $this->assertEquals('75.00', $data['sale_price']);
        $this->assertEquals('2024-01-01 00:00:00', $data['date_on_sale_from']);
        $this->assertEquals('2024-12-31 23:59:59', $data['date_on_sale_to']);
    }

    /**
     * Variation sale price must be cleared when variation is not on sale
     */
    public function test_variation_data_clears_sale_price_when_not_on_sale(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('VAR-123');
        $variation->shouldReceive('get_regular_price')->andReturn('50.00');
        $variation->shouldReceive('is_on_sale')->andReturn(false);
        $variation->shouldReceive('managing_stock')->andReturn(false);
        $variation->shouldReceive('get_stock_status')->andReturn('instock');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_image_id')->andReturn(0);

        $extractor = new WC_Multi_Store_Product_Extractor();
        $data = $extractor->build_variation_data($variation);

        $this->assertArrayHasKey('sale_price', $data);
        $this->assertSame('', $data['sale_price']);
    }

    /**
     * Variation sale price must be sent when variation is on sale
     */
    public function test_variation_data_sends_sale_price_when_on_sale(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('VAR-123');
        $variation->shouldReceive('get_regular_price')->andReturn('50.00');
        $variation->shouldReceive('is_on_sale')->andReturn(true);
        $variation->shouldReceive('get_sale_price')->andReturn('35.00');
        $variation->shouldReceive('managing_stock')->andReturn(false);
        $variation->shouldReceive('get_stock_status')->andReturn('instock');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_image_id')->andReturn(0);

        $extractor = new WC_Multi_Store_Product_Extractor();
        $data = $extractor->build_variation_data($variation);

        $this->assertEquals('35.00', $data['sale_price']);
    }

    // ═══════════════════════════════════════════════════════════════
    // FIX: Batch chunking for >100 variations
    // ═══════════════════════════════════════════════════════════════

    /**
     * chunk_batch_operations must split creates/updates/deletes into
     * chunks that respect the 100-item API limit
     */
    public function test_chunk_batch_operations_splits_large_batches(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer();

        // Access private method via reflection
        $method = new ReflectionMethod($synchronizer, 'chunk_batch_operations');

        // 80 creates + 40 updates + 10 deletes = 130 items total → 2 chunks
        $creates = array_fill(0, 80, ['sku' => 'C', 'regular_price' => '10.00']);
        $updates = array_fill(0, 40, ['id' => 1, 'sku' => 'U', 'regular_price' => '20.00']);
        $deletes = array_fill(0, 10, 999);

        $chunks = $method->invoke($synchronizer, $creates, $updates, $deletes, 100);

        $this->assertCount(2, $chunks, 'Should split 130 items into 2 chunks of max 100');

        // First chunk should have 100 items
        $first_total = 0;
        foreach ($chunks[0] as $items) {
            $first_total += count($items);
        }
        $this->assertEquals(100, $first_total);

        // Second chunk should have 30 items
        $second_total = 0;
        foreach ($chunks[1] as $items) {
            $second_total += count($items);
        }
        $this->assertEquals(30, $second_total);
    }

    /**
     * chunk_batch_operations with items under 100 should return single chunk
     */
    public function test_chunk_batch_operations_single_chunk_under_limit(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer();
        $method = new ReflectionMethod($synchronizer, 'chunk_batch_operations');

        $creates = [['sku' => 'A'], ['sku' => 'B']];
        $updates = [['id' => 1, 'sku' => 'C']];
        $deletes = [100];

        $chunks = $method->invoke($synchronizer, $creates, $updates, $deletes, 100);

        $this->assertCount(1, $chunks, 'Should return single chunk when under 100 items');
        $this->assertCount(2, $chunks[0]['create']);
        $this->assertCount(1, $chunks[0]['update']);
        $this->assertCount(1, $chunks[0]['delete']);
    }

    /**
     * chunk_batch_operations with empty arrays returns empty
     */
    public function test_chunk_batch_operations_empty_returns_empty(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer();
        $method = new ReflectionMethod($synchronizer, 'chunk_batch_operations');

        $chunks = $method->invoke($synchronizer, [], [], [], 100);

        $this->assertEmpty($chunks);
    }

    // ═══════════════════════════════════════════════════════════════
    // FIX: Batch partial failure logging
    // ═══════════════════════════════════════════════════════════════

    /**
     * log_batch_partial_failures must detect and log individual item errors
     * in a batch API response that returned 200 OK overall
     */
    public function test_log_batch_partial_failures_detects_errors(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer();
        $method = new ReflectionMethod($synchronizer, 'log_batch_partial_failures');

        // Simulate batch response with partial failures
        $batch_result = [
            'create' => [
                ['id' => 100, 'sku' => 'OK-1'],
                ['error' => ['code' => 'woocommerce_rest_product_invalid_id', 'message' => 'Invalid product ID.']],
            ],
            'update' => [
                ['id' => 200, 'sku' => 'OK-2'],
            ],
        ];

        // Should not throw - just logs the error
        $method->invoke($synchronizer, $batch_result, 999);
        $this->assertTrue(true, 'Should handle partial failures without throwing');
    }

    /**
     * log_batch_partial_failures handles fully successful batch
     */
    public function test_log_batch_partial_failures_no_errors(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer();
        $method = new ReflectionMethod($synchronizer, 'log_batch_partial_failures');

        $batch_result = [
            'create' => [
                ['id' => 100, 'sku' => 'OK-1'],
                ['id' => 101, 'sku' => 'OK-2'],
            ],
        ];

        $method->invoke($synchronizer, $batch_result, 999);
        $this->assertTrue(true, 'Should handle all-success batch cleanly');
    }

    /**
     * log_batch_partial_failures handles empty batch result
     */
    public function test_log_batch_partial_failures_empty_result(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer();
        $method = new ReflectionMethod($synchronizer, 'log_batch_partial_failures');

        $method->invoke($synchronizer, [], 999);
        $this->assertTrue(true, 'Should handle empty result without errors');
    }

    // ═══════════════════════════════════════════════════════════════
    // FIX: Sync engine redirects variation to parent product
    // ═══════════════════════════════════════════════════════════════

    /**
     * sync_product_to_store must detect variation objects and redirect
     * to the parent product. Without this, variations would be created
     * as standalone simple products on the remote store.
     */
    public function test_sync_engine_has_variation_redirect_logic(): void
    {
        $this->setUpSyncEngineMocks();

        $engine = new WC_Multi_Store_Sync_Engine();

        // Create a variation product that returns a valid parent
        $variation = \Mockery::mock('WC_Product');
        $variation->shouldReceive('is_type')->with('variation')->andReturn(true);
        $variation->shouldReceive('get_parent_id')->andReturn(0);
        $variation->shouldReceive('get_sku')->andReturn('VAR-SKU');
        $variation->shouldReceive('get_id')->andReturn(200);

        // With parent_id = 0, wc_get_product returns null → should fail gracefully
        Functions\when('wc_get_product')->justReturn(null);

        $result = $engine->sync_product_to_store(
            $variation,
            'https://store1.com',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'full_product'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no valid parent', $result['message']);
    }


    // ═══════════════════════════════════════════════════════════════
    // FIX: on_variation_save queues parent ID, not variation ID
    // ═══════════════════════════════════════════════════════════════

    /**
     * on_variation_save must queue the parent product ID (50), not the variation ID (200).
     * If variation ID were queued, it would be created as a standalone simple product on
     * the remote store instead of being processed through the parent's variation sync flow.
     */
    public function test_on_variation_save_queues_parent_id_not_variation_id(): void
    {
        $this->setUpHooksMocks();
        WC_Multi_Store_Hooks::clear_settings_cache();

        Functions\when('wp_get_post_parent_id')->justReturn(50);
        Functions\when('get_post_status')->justReturn('publish');

        $sync = WC_Multi_Store_Sync::instance();
        $original_qm = $sync->queue_manager;
        $queue_mock = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $queue_mock->shouldReceive('add_product')
            ->once()
            ->with(50, 'variation_save', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL);
        $sync->queue_manager = $queue_mock;

        try {
            $hooks = new WC_Multi_Store_Hooks();
            $hooks->on_variation_save(200);
        } finally {
            $sync->queue_manager = $original_qm;
        }

        // Expectation verification happens in Mockery::close() during tearDown.
        // addToAssertionCount prevents PHPUnit marking the test as risky.
        $this->addToAssertionCount(1);
    }

    /**
     * on_variation_save must skip when parent doesn't exist (parent_id = 0)
     */
    public function test_on_variation_save_skips_when_no_parent(): void
    {
        $this->setUpHooksMocks();
        WC_Multi_Store_Hooks::clear_settings_cache();

        Functions\when('wp_get_post_parent_id')->justReturn(0);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('insert');

        $hooks = new WC_Multi_Store_Hooks();
        $hooks->on_variation_save(200);

        $this->assertTrue(true, 'Should not queue when variation has no parent');
    }

    // ═══════════════════════════════════════════════════════════════
    // FIX: Deletion audit status is wired up
    // ═══════════════════════════════════════════════════════════════

    /**
     * Deletion_Audit::update_status must accept valid statuses
     */
    public function test_deletion_audit_update_status_accepts_completed(): void
    {
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_deletion_audit',
                \Mockery::on(function ($data) {
                    return $data['status'] === 'completed'
                        && isset($data['completed_at']);
                }),
                ['id' => 5],
                \Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Deletion_Audit::update_status(5, 'completed');
        $this->assertTrue($result);
    }

    /**
     * Deletion_Audit::update_status must accept failed status with error message
     */
    public function test_deletion_audit_update_status_accepts_failed_with_message(): void
    {
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_deletion_audit',
                \Mockery::on(function ($data) {
                    return $data['status'] === 'failed'
                        && $data['error_message'] === 'API timeout'
                        && isset($data['completed_at']);
                }),
                ['id' => 7],
                \Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Deletion_Audit::update_status(7, 'failed', 'API timeout');
        $this->assertTrue($result);
    }

    /**
     * Deletion_Audit::update_status returns false on database error
     */
    public function test_deletion_audit_update_status_returns_false_on_error(): void
    {
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = WC_Multi_Store_Deletion_Audit::update_status(99, 'completed');
        $this->assertFalse($result);
    }

    // ═══════════════════════════════════════════════════════════════
    // FIX: sync_variations with multiple batch chunks
    // ═══════════════════════════════════════════════════════════════

    /**
     * sync_variations with >100 variations must make multiple batch API calls
     */
    public function test_sync_variations_large_batch_makes_multiple_api_calls(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') {
                return ['image_proxy_enabled' => false, 'delete_orphan_variations' => false];
            }
            return $default;
        });

        // Create 120 variation IDs
        $variation_ids = range(1, 120);

        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('VAR-SKU');
        $variation->shouldReceive('get_image_id')->andReturn(0);

        Functions\when('wc_get_product')->justReturn($variation);

        $extractor = \Mockery::mock('WC_Multi_Store_Product_Extractor');
        $extractor->shouldReceive('build_variation_data')
            ->andReturn(['sku' => 'VAR-SKU', 'regular_price' => '10.00']);

        $transformer = \Mockery::mock('WC_Multi_Store_Product_Transformer');

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer($extractor, $transformer);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_children')->andReturn($variation_ids);

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        // Remote has no variations
        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn([]);

        // Should make 2 batch calls (100 + 20)
        $batch_call_count = 0;
        $api->shouldReceive('batch_product_variations')
            ->twice()
            ->andReturnUsing(function ($parent_id, $batch) use (&$batch_call_count) {
                $batch_call_count++;
                $total = 0;
                foreach ($batch as $items) {
                    $total += count($items);
                }
                // First batch = 100, second = 20
                if ($batch_call_count === 1) {
                    \PHPUnit\Framework\Assert::assertEquals(100, $total);
                } else {
                    \PHPUnit\Framework\Assert::assertEquals(20, $total);
                }
                return ['create' => array_fill(0, $total, ['id' => 1])];
            });

        $result = $synchronizer->sync_variations($product, 500, $api, 'https://store.com');

        // 1 get + 2 batch calls = 3
        $this->assertEquals(3, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function setUpHooksMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('current_datetime')->justReturn(new \DateTimeImmutable('2024-01-15 12:00:00'));
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('wp_get_post_parent_id')->justReturn(50);

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'auto_sync_on_save' => true,
                    'auto_sync_new_products' => true,
                    'auto_sync_deletions' => true,
                    'auto_sync_restorations' => true,
                    'auto_sync_status' => true,
                    'stock_sync_enabled' => true,
                    'sync_type_default' => 'full_product',
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
            return $default;
        });
    }

    private function setUpSyncEngineMocks(): void
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
            return $default;
        });

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2024-01-01 12:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
    }

    private function createMockProduct(array $options = []): \Mockery\MockInterface
    {
        $defaults = [
            'id' => 123,
            'regular_price' => '100.00',
            'sale_price' => '',
            'is_on_sale' => false,
            'date_on_sale_from' => null,
            'date_on_sale_to' => null,
        ];

        $options = array_merge($defaults, $options);
        $product = \Mockery::mock('WC_Product');

        $product->shouldReceive('get_id')->andReturn($options['id']);
        $product->shouldReceive('get_regular_price')->andReturn($options['regular_price']);
        $product->shouldReceive('is_on_sale')->andReturn($options['is_on_sale']);
        $product->shouldReceive('get_sale_price')->andReturn($options['sale_price']);
        $product->shouldReceive('get_date_on_sale_from')->andReturn($options['date_on_sale_from']);
        $product->shouldReceive('get_date_on_sale_to')->andReturn($options['date_on_sale_to']);

        return $product;
    }
}
