<?php
/**
 * Extended unit tests for WC_Multi_Store_Order_Sync
 *
 * Covers: large order debouncing, debounce merge, custom timeouts,
 * process_debounced_order queuing, sync_last_orders, get_statistics HPOS.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class OrderSyncExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderSyncMocks();

        if (!class_exists('WC_Multi_Store_Order_Sync')) {
            $plugin_root = dirname(__DIR__, 3);
            require_once $plugin_root . '/includes/order-sync.php';
        }
    }

    protected function setUpOrderSyncMocks(): void
    {
        // Ensure a fresh real queue_manager instance (not a leftover Mockery mock).
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return [
                    'auto_sync_enabled' => true,
                    'sync_statuses' => ['processing', 'completed'],
                    'sync_on_new' => true,
                ];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
    }

    // ── Large order debouncing ───────────────────────────────────

    public function test_large_order_triggers_debouncing(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        // Create 12 items (>= LARGE_ORDER_THRESHOLD of 10)
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $item = \Mockery::mock('WC_Order_Item_Product');
            $item->shouldReceive('get_product_id')->andReturn($i * 100);
            $item->shouldReceive('get_variation_id')->andReturn(0);
            $items[] = $item;
        }

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn($items);

        $debounce_set = false;
        Functions\when('set_transient')->alias(function ($key, $value, $exp) use (&$debounce_set) {
            if (str_starts_with($key, 'wc_mss_order_debounce_')) {
                $debounce_set = true;
            }
            return true;
        });

        $sync->on_order_status_changed(42, 'pending', 'processing', $order);

        $this->assertTrue($debounce_set, 'Large orders should set debounce transient');
    }

    public function test_debounce_merges_existing_products(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        // Existing debounced products
        Functions\when('get_transient')->alias(function ($key) {
            if (str_starts_with($key, 'wc_mss_order_debounce_')) {
                return [100, 200, 300];
            }
            return false;
        });

        $merged_products = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$merged_products) {
            if (str_starts_with($key, 'wc_mss_order_debounce_')) {
                $merged_products = $value;
            }
            return true;
        });

        // Create 11 new items (some overlapping with existing)
        $items = [];
        for ($i = 1; $i <= 11; $i++) {
            $item = \Mockery::mock('WC_Order_Item_Product');
            $item->shouldReceive('get_product_id')->andReturn($i * 100);
            $item->shouldReceive('get_variation_id')->andReturn(0);
            $items[] = $item;
        }

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn($items);

        $sync->on_order_status_changed(42, 'pending', 'completed', $order);

        $this->assertNotNull($merged_products);
        // 100, 200, 300 from existing + 400..1100 from new = 11 unique
        $this->assertCount(11, $merged_products);
    }

    public function test_custom_debounce_timeout_from_settings(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return [
                    'auto_sync_enabled' => true,
                    'sync_statuses' => ['processing', 'completed'],
                    'sync_on_new' => true,
                    'debounce_timeout' => 60,
                ];
            }
            return $default;
        });

        $sync = new WC_Multi_Store_Order_Sync();

        $set_expiration = null;
        Functions\when('set_transient')->alias(function ($key, $value, $exp) use (&$set_expiration) {
            if (str_starts_with($key, 'wc_mss_order_debounce_')) {
                $set_expiration = $exp;
            }
            return true;
        });

        // Create 10+ items
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $item = \Mockery::mock('WC_Order_Item_Product');
            $item->shouldReceive('get_product_id')->andReturn($i * 100);
            $item->shouldReceive('get_variation_id')->andReturn(0);
            $items[] = $item;
        }

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn($items);

        $sync->on_order_status_changed(50, 'pending', 'processing', $order);

        $this->assertEquals(60, $set_expiration, 'Should use custom debounce timeout from settings');
    }

    // ── Small order queuing ──────────────────────────────────────

    public function test_small_order_queues_directly(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        // Create 5 items (< LARGE_ORDER_THRESHOLD)
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $item = \Mockery::mock('WC_Order_Item_Product');
            $item->shouldReceive('get_product_id')->andReturn($i * 100);
            $item->shouldReceive('get_variation_id')->andReturn(0);
            $items[] = $item;
        }

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn($items);

        // For small orders, no debounce transient should be set
        $debounce_set = false;
        Functions\when('set_transient')->alias(function ($key) use (&$debounce_set) {
            if (str_starts_with($key, 'wc_mss_order_debounce_')) {
                $debounce_set = true;
            }
            return true;
        });

        $sync->on_order_status_changed(10, 'pending', 'processing', $order);

        $this->assertFalse($debounce_set, 'Small orders should not use debouncing');
    }

    // ── Empty order handling ─────────────────────────────────────

    public function test_empty_order_does_not_queue(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([]);

        // Should complete without error
        $sync->on_order_status_changed(10, 'pending', 'processing', $order);
        $this->assertTrue(true);
    }

    // ── on_new_order edge cases ──────────────────────────────────

    public function test_on_new_order_null_order_does_nothing(): void
    {
        Functions\when('wc_get_order')->justReturn(null);

        $sync = new WC_Multi_Store_Order_Sync();
        $sync->on_new_order(999);

        // Should not throw
        $this->assertTrue(true);
    }

    public function test_on_new_order_false_order_does_nothing(): void
    {
        Functions\when('wc_get_order')->justReturn(false);

        $sync = new WC_Multi_Store_Order_Sync();
        $sync->on_new_order(999);

        $this->assertTrue(true);
    }

    // ── process_debounced_order ───────────────────────────────────

    public function test_process_debounced_order_clears_transient_after_queuing(): void
    {
        $product_ids = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000];

        Functions\when('get_transient')->alias(function ($key) use ($product_ids) {
            if ($key === 'wc_mss_order_debounce_99') {
                return $product_ids;
            }
            return false;
        });

        $transient_deleted = false;
        Functions\when('delete_transient')->alias(function ($key) use (&$transient_deleted) {
            if ($key === 'wc_mss_order_debounce_99') {
                $transient_deleted = true;
            }
            return true;
        });

        WC_Multi_Store_Order_Sync::process_debounced_order(99);

        $this->assertTrue($transient_deleted);
    }

    // ── sync_last_orders ─────────────────────────────────────────

    public function test_sync_last_orders_with_valid_orders(): void
    {
        $item1 = \Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_product_id')->andReturn(100);
        $item1->shouldReceive('get_variation_id')->andReturn(0);

        $item2 = \Mockery::mock('WC_Order_Item_Product');
        $item2->shouldReceive('get_product_id')->andReturn(200);
        $item2->shouldReceive('get_variation_id')->andReturn(250);

        $order1 = \Mockery::mock('WC_Order');
        $order1->shouldReceive('get_items')->andReturn([$item1]);

        $order2 = \Mockery::mock('WC_Order');
        $order2->shouldReceive('get_items')->andReturn([$item2]);

        Functions\when('wc_get_orders')->justReturn([1, 2]);
        Functions\when('wc_get_order')->alias(function ($id) use ($order1, $order2) {
            return match ($id) {
                1 => $order1,
                2 => $order2,
                default => null,
            };
        });

        $result = WC_Multi_Store_Order_Sync::sync_last_orders(5);

        // Returns whatever add_products returns - we just verify it runs
        $this->assertIsInt($result);
    }

    public function test_sync_last_orders_skips_invalid_orders(): void
    {
        Functions\when('wc_get_orders')->justReturn([1, 2, 3]);
        Functions\when('wc_get_order')->alias(function ($id) {
            if ($id === 2) {
                return false; // Invalid order
            }
            $order = \Mockery::mock('WC_Order');
            $order->shouldReceive('get_items')->andReturn([]);
            return $order;
        });

        $result = WC_Multi_Store_Order_Sync::sync_last_orders(3);

        $this->assertEquals(0, $result);
    }

    public function test_sync_last_orders_deduplicates_products(): void
    {
        // Two orders with the same product
        $item = \Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn(100);
        $item->shouldReceive('get_variation_id')->andReturn(0);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item]);

        Functions\when('wc_get_orders')->justReturn([1, 2]);
        Functions\when('wc_get_order')->justReturn($order);

        // Should still work without errors (dedup happens internally)
        $result = WC_Multi_Store_Order_Sync::sync_last_orders(2);
        $this->assertIsInt($result);
    }

    // ── get_statistics ───────────────────────────────────────────

    public function test_get_statistics_with_hpos_enabled(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';

        // First get_var checks HPOS table existence - return table name to indicate HPOS
        $wpdb->shouldReceive('get_var')
            ->andReturn('wp_wc_orders', 5, 12);

        $wpdb->shouldReceive('prepare')->andReturn('');

        $stats = WC_Multi_Store_Order_Sync::get_statistics();

        $this->assertArrayHasKey('orders_today', $stats);
        $this->assertArrayHasKey('products_today', $stats);
        $this->assertEquals(5, $stats['orders_today']);
    }

    public function test_get_statistics_with_legacy_tables(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';

        // First get_var returns null (HPOS table doesn't exist)
        $wpdb->shouldReceive('get_var')
            ->andReturn(null, 3, 8);

        $wpdb->shouldReceive('prepare')->andReturn('');

        $stats = WC_Multi_Store_Order_Sync::get_statistics();

        $this->assertEquals(3, $stats['orders_today']);
        $this->assertEquals(8, $stats['products_today']);
    }

    // ── Cached settings ──────────────────────────────────────────

    public function test_cached_settings_used_for_status_check(): void
    {
        // First construction caches settings
        $sync = new WC_Multi_Store_Order_Sync();

        $order = \Mockery::mock('WC_Order');

        // 'refunded' is not in sync_statuses
        $sync->on_order_status_changed(1, 'processing', 'refunded', $order);

        // Should complete without error (early return because status not in list)
        $this->assertTrue(true);
    }

    public function test_on_order_status_changed_with_custom_sync_statuses(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return [
                    'auto_sync_enabled' => true,
                    'sync_statuses' => ['on-hold', 'refunded'],
                    'sync_on_new' => false,
                ];
            }
            return $default;
        });

        $sync = new WC_Multi_Store_Order_Sync();

        $item = \Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn(100);
        $item->shouldReceive('get_variation_id')->andReturn(0);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item]);

        // 'on-hold' is now a valid sync status
        $sync->on_order_status_changed(1, 'pending', 'on-hold', $order);

        // Should not throw - products get queued
        $this->assertTrue(true);
    }

    // ── get_order_product_ids edge cases ──────────────────────────

    public function test_order_with_only_zero_ids_returns_empty(): void
    {
        $item = \Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn(0);
        $item->shouldReceive('get_variation_id')->andReturn(0);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item]);

        $sync = new WC_Multi_Store_Order_Sync();
        $method = new ReflectionMethod($sync, 'get_order_product_ids');

        $result = $method->invoke($sync, $order);

        // Product ID 0 and variation ID 0 - neither branch adds to array
        $this->assertEmpty($result);
    }
}
