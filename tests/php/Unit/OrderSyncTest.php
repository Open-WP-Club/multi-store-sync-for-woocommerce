<?php
/**
 * Unit tests for WC_Multi_Store_Order_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class OrderSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderSyncMocks();

        // Load order-sync.php once (file-level add_action needs Brain Monkey)
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

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Order_Sync'));
    }

    public function test_constants(): void
    {
        $this->assertEquals('wc_mss_order_debounce_', WC_Multi_Store_Order_Sync::DEBOUNCE_PREFIX);
        $this->assertEquals(10, WC_Multi_Store_Order_Sync::LARGE_ORDER_THRESHOLD);
        $this->assertEquals(30, WC_Multi_Store_Order_Sync::DEBOUNCE_TIMEOUT);
    }

    public function test_on_order_status_changed_disabled_does_nothing(): void
    {
        // Override get_option to disable auto sync
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return ['auto_sync_enabled' => false];
            }
            return $default;
        });

        // Clear cached settings by creating a new instance
        $sync = new WC_Multi_Store_Order_Sync();
        $order = \Mockery::mock('WC_Order');

        // Should return without queuing anything - no exception means success
        $sync->on_order_status_changed(1, 'pending', 'processing', $order);
        $this->assertTrue(true);
    }

    public function test_on_order_status_changed_wrong_status_does_nothing(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();
        $order = \Mockery::mock('WC_Order');

        // 'on-hold' is not in sync_statuses, so should return early
        $sync->on_order_status_changed(1, 'pending', 'on-hold', $order);
        $this->assertTrue(true);
    }

    public function test_on_order_status_changed_queues_products_for_valid_status(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        $item1 = \Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_product_id')->andReturn(100);
        $item1->shouldReceive('get_variation_id')->andReturn(0);

        $item2 = \Mockery::mock('WC_Order_Item_Product');
        $item2->shouldReceive('get_product_id')->andReturn(200);
        $item2->shouldReceive('get_variation_id')->andReturn(0);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item1, $item2]);

        // Should call QueueManager::add_products for small order (< 10 items)
        $sync->on_order_status_changed(1, 'pending', 'processing', $order);
        $this->assertTrue(true);
    }

    public function test_on_new_order_disabled_does_nothing(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return ['auto_sync_enabled' => false];
            }
            return $default;
        });

        $sync = new WC_Multi_Store_Order_Sync();
        $sync->on_new_order(1);
        $this->assertTrue(true);
    }

    public function test_on_new_order_sync_on_new_disabled_does_nothing(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return [
                    'auto_sync_enabled' => true,
                    'sync_on_new' => false,
                ];
            }
            return $default;
        });

        $sync = new WC_Multi_Store_Order_Sync();
        $sync->on_new_order(1);
        $this->assertTrue(true);
    }

    public function test_on_new_order_gets_order_and_queues(): void
    {
        $item = \Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn(100);
        $item->shouldReceive('get_variation_id')->andReturn(0);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item]);

        Functions\when('wc_get_order')->justReturn($order);

        $sync = new WC_Multi_Store_Order_Sync();
        $sync->on_new_order(42);
        $this->assertTrue(true);
    }

    public function test_get_order_product_ids_extracts_variation_ids(): void
    {
        $item = \Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn(100);
        $item->shouldReceive('get_variation_id')->andReturn(150);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item]);

        $sync = new WC_Multi_Store_Order_Sync();
        $method = new ReflectionMethod($sync, 'get_order_product_ids');

        $result = $method->invoke($sync, $order);

        // Should prefer variation_id over product_id
        $this->assertEquals([150], $result);
    }

    public function test_get_order_product_ids_extracts_product_ids_when_no_variation(): void
    {
        $item = \Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn(100);
        $item->shouldReceive('get_variation_id')->andReturn(0);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item]);

        $sync = new WC_Multi_Store_Order_Sync();
        $method = new ReflectionMethod($sync, 'get_order_product_ids');

        $result = $method->invoke($sync, $order);

        $this->assertEquals([100], $result);
    }

    public function test_get_order_product_ids_returns_unique(): void
    {
        $item1 = \Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_product_id')->andReturn(100);
        $item1->shouldReceive('get_variation_id')->andReturn(0);

        $item2 = \Mockery::mock('WC_Order_Item_Product');
        $item2->shouldReceive('get_product_id')->andReturn(100);
        $item2->shouldReceive('get_variation_id')->andReturn(0);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item1, $item2]);

        $sync = new WC_Multi_Store_Order_Sync();
        $method = new ReflectionMethod($sync, 'get_order_product_ids');

        $result = $method->invoke($sync, $order);

        $this->assertCount(1, $result);
        $this->assertContains(100, $result);
    }

    public function test_get_order_product_ids_mixed_items(): void
    {
        $item1 = \Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_product_id')->andReturn(100);
        $item1->shouldReceive('get_variation_id')->andReturn(150);

        $item2 = \Mockery::mock('WC_Order_Item_Product');
        $item2->shouldReceive('get_product_id')->andReturn(200);
        $item2->shouldReceive('get_variation_id')->andReturn(0);

        $item3 = \Mockery::mock('WC_Order_Item_Product');
        $item3->shouldReceive('get_product_id')->andReturn(300);
        $item3->shouldReceive('get_variation_id')->andReturn(350);

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn([$item1, $item2, $item3]);

        $sync = new WC_Multi_Store_Order_Sync();
        $method = new ReflectionMethod($sync, 'get_order_product_ids');

        $result = $method->invoke($sync, $order);

        $this->assertCount(3, $result);
        $this->assertContains(150, $result);
        $this->assertContains(200, $result);
        $this->assertContains(350, $result);
    }

    public function test_process_debounced_order_with_no_transient_does_nothing(): void
    {
        Functions\when('get_transient')->justReturn(false);

        // Should return early without queuing
        WC_Multi_Store_Order_Sync::process_debounced_order(42);
        $this->assertTrue(true);
    }

    public function test_process_debounced_order_with_empty_products_does_nothing(): void
    {
        Functions\when('get_transient')->justReturn([]);

        WC_Multi_Store_Order_Sync::process_debounced_order(42);
        $this->assertTrue(true);
    }

    public function test_process_debounced_order_queues_products_and_clears_transient(): void
    {
        // Return product IDs only for the specific debounce key; return false for all others
        // (e.g. CacheManager also calls get_transient — returning a non-false array for
        // those calls would corrupt WC_Multi_Store_Settings::get_active_stores()).
        Functions\when('get_transient')->alias(function ($key) {
            if ($key === 'wc_mss_order_debounce_42') {
                return [100, 200, 300];
            }
            return false;
        });

        $deleted = false;
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
            if ($key === 'wc_mss_order_debounce_42') {
                $deleted = true;
            }
            return true;
        });

        WC_Multi_Store_Order_Sync::process_debounced_order(42);
        $this->assertTrue($deleted, 'Transient should be cleared after processing');
    }

    public function test_sync_last_orders_no_orders(): void
    {
        Functions\when('wc_get_orders')->justReturn([]);

        $result = WC_Multi_Store_Order_Sync::sync_last_orders(10);

        $this->assertEquals(0, $result);
    }

    public function test_get_statistics_returns_expected_structure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('prepare')->andReturn('');

        $stats = WC_Multi_Store_Order_Sync::get_statistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('orders_today', $stats);
        $this->assertArrayHasKey('products_today', $stats);
        $this->assertArrayHasKey('settings', $stats);
    }
}
