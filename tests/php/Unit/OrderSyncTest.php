<?php
/**
 * Unit tests for WC_Multi_Store_Order_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class OrderSyncTest extends WC_Multi_Store_TestCase
{
    /** @var object{lines: list<array{0: string, 1: string}>} */
    private object $logSink;

    /** Capture logger output (wc_get_logger() sink). */
    private function captureLogs(): void
    {
        $this->logSink = new class {
            /** @var list<array{0: string, 1: string}> */
            public array $lines = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->lines[] = [$level, $message];
            }
        };
        Functions\when('wc_get_logger')->justReturn($this->logSink);
    }

    /** @return list<string> */
    private function loggedMessages(): array
    {
        return array_column($this->logSink->lines, 1);
    }

    /** Swap WC_MSS()->queue_manager for a Mockery double (setUp restores a real one). */
    private function mockQueueManager(): \Mockery\MockInterface
    {
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        WC_Multi_Store_Sync::instance()->queue_manager = $queue;
        return $queue;
    }

    /** @param list<int> $productIds */
    private function orderWithProducts(array $productIds): \Mockery\MockInterface
    {
        $items = [];
        foreach ($productIds as $id) {
            $item = \Mockery::mock('WC_Order_Item_Product');
            $item->shouldReceive('get_product_id')->andReturn($id);
            $item->shouldReceive('get_variation_id')->andReturn(0);
            $items[] = $item;
        }
        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn($items);
        return $order;
    }

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
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return ['auto_sync_enabled' => false];
            }
            return $default;
        });
        $this->captureLogs();
        $this->mockQueueManager()->shouldNotReceive('add_products');
        // A mock order with no expectations fails the test if its items are read.
        $order = \Mockery::mock('WC_Order');

        (new WC_Multi_Store_Order_Sync())->on_order_status_changed(1, 'pending', 'processing', $order);

        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_order_status_changed_wrong_status_does_nothing(): void
    {
        $this->captureLogs();
        $this->mockQueueManager()->shouldNotReceive('add_products');
        $order = \Mockery::mock('WC_Order');

        // 'on-hold' is not in sync_statuses, so should return early
        (new WC_Multi_Store_Order_Sync())->on_order_status_changed(1, 'pending', 'on-hold', $order);

        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_order_status_changed_queues_products_for_valid_status(): void
    {
        $this->captureLogs();
        $order = $this->orderWithProducts([100, 200]);
        $this->mockQueueManager()
            ->shouldReceive('add_products')
            ->once()
            ->with([100, 200], 'order_1', 10)
            ->andReturn(2);

        (new WC_Multi_Store_Order_Sync())->on_order_status_changed(1, 'pending', 'processing', $order);

        $this->assertSame([
            'Order #1 status changed: pending → processing (triggering sync)',
            'Order #1: 2 product(s) queued for sync (will be processed by next cron run)',
        ], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_new_order_disabled_does_nothing(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_orders') {
                return ['auto_sync_enabled' => false, 'sync_on_new' => true];
            }
            return $default;
        });
        $this->captureLogs();
        $lookups = 0;
        Functions\when('wc_get_order')->alias(function () use (&$lookups) {
            $lookups++;
            return false;
        });
        $this->mockQueueManager()->shouldNotReceive('add_products');

        (new WC_Multi_Store_Order_Sync())->on_new_order(1);

        $this->assertSame(0, $lookups, 'The order must not even be loaded when auto sync is off');
        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
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
        $this->captureLogs();
        $lookups = 0;
        Functions\when('wc_get_order')->alias(function () use (&$lookups) {
            $lookups++;
            return false;
        });
        $this->mockQueueManager()->shouldNotReceive('add_products');

        (new WC_Multi_Store_Order_Sync())->on_new_order(1);

        $this->assertSame(0, $lookups);
        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_new_order_gets_order_and_queues(): void
    {
        $this->captureLogs();
        $order = $this->orderWithProducts([100]);
        Functions\expect('wc_get_order')->once()->with(42)->andReturn($order);
        $this->mockQueueManager()
            ->shouldReceive('add_products')
            ->once()
            ->with([100], 'order_42', 10)
            ->andReturn(1);

        (new WC_Multi_Store_Order_Sync())->on_new_order(42);

        $this->assertSame([
            'New order #42 created (triggering sync)',
            'Order #42: 1 product(s) queued for sync (will be processed by next cron run)',
        ], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
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
        $this->captureLogs();
        Functions\when('get_transient')->justReturn(false);
        $deleted = 0;
        Functions\when('delete_transient')->alias(function () use (&$deleted) {
            $deleted++;
            return true;
        });
        $this->mockQueueManager()->shouldNotReceive('add_products');

        WC_Multi_Store_Order_Sync::process_debounced_order(42);

        $this->assertSame(0, $deleted);
        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_process_debounced_order_with_empty_products_does_nothing(): void
    {
        $this->captureLogs();
        Functions\when('get_transient')->justReturn([]);
        $deleted = 0;
        Functions\when('delete_transient')->alias(function () use (&$deleted) {
            $deleted++;
            return true;
        });
        $this->mockQueueManager()->shouldNotReceive('add_products');

        WC_Multi_Store_Order_Sync::process_debounced_order(42);

        $this->assertSame(0, $deleted);
        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
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

}
