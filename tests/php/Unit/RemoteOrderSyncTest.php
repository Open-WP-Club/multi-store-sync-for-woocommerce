<?php
/**
 * Unit tests for WC_Multi_Store_Remote_Order_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class RemoteOrderSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteOrderMocks();
    }

    protected function setUpRemoteOrderMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Remote_Order_Sync'));
    }

    public function test_sync_all_stores_empty_stores_returns_empty(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();

        $result = $sync->sync_all_stores();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_prepare_order_data_basic_structure(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'prepare_order_data');

        $order_data = [
            'id' => 123,
            'number' => '1001',
            'order_key' => 'wc_order_abc123',
            'status' => 'processing',
            'currency' => 'EUR',
            'total' => '99.99',
            'total_tax' => '19.99',
            'shipping_total' => '5.00',
            'discount_total' => '10.00',
            'payment_method' => 'stripe',
            'payment_method_title' => 'Credit Card',
            'billing' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
            ],
            'line_items' => [],
            'date_created' => '2024-01-15T10:30:00',
        ];

        $result = $method->invoke($sync, $order_data, 'https://store1.com');

        $this->assertEquals(123, $result['remote_order_id']);
        $this->assertEquals('https://store1.com', $result['remote_store_url']);
        $this->assertEquals('1001', $result['order_number']);
        $this->assertEquals('processing', $result['status']);
        $this->assertEquals('EUR', $result['currency']);
        $this->assertEquals(99.99, $result['total']);
        $this->assertEquals('John Doe', $result['customer_name']);
        $this->assertEquals('john@example.com', $result['customer_email']);
        $this->assertNotEmpty($result['sync_hash']);
    }

    public function test_prepare_order_data_customer_name_with_missing_parts(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'prepare_order_data');

        // Only first name
        $order_data = [
            'id' => 1,
            'billing' => ['first_name' => 'Jane'],
            'line_items' => [],
        ];

        $result = $method->invoke($sync, $order_data, 'https://store1.com');
        $this->assertEquals('Jane', $result['customer_name']);

        // Only last name
        $order_data['billing'] = ['last_name' => 'Smith'];
        $result = $method->invoke($sync, $order_data, 'https://store1.com');
        $this->assertEquals('Smith', $result['customer_name']);

        // No billing info
        $order_data['billing'] = [];
        $result = $method->invoke($sync, $order_data, 'https://store1.com');
        $this->assertEquals('', $result['customer_name']);
    }

    public function test_prepare_order_data_line_items(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'prepare_order_data');

        $order_data = [
            'id' => 1,
            'billing' => [],
            'line_items' => [
                [
                    'product_id' => 42,
                    'name' => 'Widget',
                    'sku' => 'WDG-001',
                    'quantity' => 3,
                    'subtotal' => '30.00',
                    'total' => '27.00',
                    'total_tax' => '5.13',
                ],
                [
                    'product_id' => 43,
                    'name' => 'Gadget',
                    'quantity' => 1,
                    'subtotal' => '50.00',
                    'total' => '50.00',
                ],
            ],
        ];

        $result = $method->invoke($sync, $order_data, 'https://store1.com');

        $this->assertCount(2, $result['line_items']);
        $this->assertEquals(42, $result['line_items'][0]['remote_product_id']);
        $this->assertEquals('Widget', $result['line_items'][0]['product_name']);
        $this->assertEquals('WDG-001', $result['line_items'][0]['product_sku']);
        $this->assertEquals(3, $result['line_items'][0]['quantity']);
        $this->assertEquals(27.0, $result['line_items'][0]['total']);
        $this->assertEquals(5.13, $result['line_items'][0]['tax_total']);
    }

    public function test_prepare_order_data_sync_hash_changes_with_data(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'prepare_order_data');

        $base = [
            'id' => 1,
            'status' => 'processing',
            'total' => '100.00',
            'billing' => [],
            'line_items' => [],
        ];

        $result1 = $method->invoke($sync, $base, 'https://store1.com');

        $modified = $base;
        $modified['status'] = 'completed';
        $result2 = $method->invoke($sync, $modified, 'https://store1.com');

        // Different status should produce different hash
        $this->assertNotEquals($result1['sync_hash'], $result2['sync_hash']);
    }

    public function test_prepare_order_data_sync_hash_same_for_same_data(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'prepare_order_data');

        $data = [
            'id' => 1,
            'status' => 'processing',
            'total' => '100.00',
            'billing' => [],
            'line_items' => [],
        ];

        $result1 = $method->invoke($sync, $data, 'https://store1.com');
        $result2 = $method->invoke($sync, $data, 'https://store1.com');

        $this->assertEquals($result1['sync_hash'], $result2['sync_hash']);
    }

    public function test_convert_date_valid_iso8601(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'convert_date');

        $result = $method->invoke($sync, '2024-01-15T10:30:00');

        $this->assertEquals('2024-01-15 10:30:00', $result);
    }

    public function test_convert_date_empty_string_returns_null(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'convert_date');

        $result = $method->invoke($sync, '');

        $this->assertNull($result);
    }

    public function test_convert_date_invalid_returns_null(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'convert_date');

        $result = $method->invoke($sync, 'not-a-date');

        // DateTime can parse many formats; truly invalid strings return null
        // 'not-a-date' will throw an exception caught by the try/catch
        $this->assertNull($result);
    }

    public function test_prepare_order_data_defaults_for_missing_fields(): void
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $method = new ReflectionMethod($sync, 'prepare_order_data');

        $minimal = ['id' => 99, 'billing' => [], 'line_items' => []];
        $result = $method->invoke($sync, $minimal, 'https://store1.com');

        $this->assertEquals(99, $result['remote_order_id']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals(0.0, $result['total']);
        $this->assertNull($result['payment_method']);
        $this->assertEmpty($result['line_items']);
    }

    public function test_schedule_sync_not_available_does_nothing(): void
    {
        // The ActionScheduler class doesn't exist in tests, so is_available() returns false
        $calls = [];
        foreach (['as_schedule_recurring_action', 'as_unschedule_all_actions'] as $fn) {
            Functions\when($fn)->alias(function () use (&$calls, $fn) {
                $calls[] = $fn;
            });
        }

        WC_Multi_Store_Remote_Order_Sync::schedule_sync('daily');

        $this->assertSame([], $calls);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleIntervals')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_schedule_sync_replaces_existing_action_with_mapped_interval(string $interval, int $seconds): void
    {
        class_alias(\stdClass::class, 'ActionScheduler');
        Functions\when('as_schedule_single_action')->justReturn(1);
        $calls = [];
        foreach (['as_schedule_recurring_action', 'as_unschedule_all_actions'] as $fn) {
            Functions\when($fn)->alias(function (...$args) use (&$calls, $fn) {
                $calls[] = [$fn, $args];
                return 1;
            });
        }

        $before = time();
        WC_Multi_Store_Remote_Order_Sync::schedule_sync($interval);

        $group = WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP;
        $this->assertCount(2, $calls);
        $this->assertSame(['as_unschedule_all_actions', ['wc_multi_store_sync_remote_orders', [], $group]], $calls[0]);
        $this->assertSame('as_schedule_recurring_action', $calls[1][0]);
        $this->assertGreaterThanOrEqual($before, $calls[1][1][0]);
        $this->assertSame([$seconds, 'wc_multi_store_sync_remote_orders', [], $group], array_slice($calls[1][1], 1));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function scheduleIntervals(): array
    {
        return [
            'hourly' => ['hourly', HOUR_IN_SECONDS],
            'twicedaily' => ['twicedaily', 12 * HOUR_IN_SECONDS],
            'daily' => ['daily', DAY_IN_SECONDS],
            'unknown falls back to daily' => ['weekly', DAY_IN_SECONDS],
        ];
    }

    // Patchwork may persist as_unschedule_all_actions from HealthCheckTrackingTest,
    // so run this in a separate process where the function won't exist.
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_unschedule_sync_without_function_does_nothing(): void
    {
        // as_unschedule_all_actions doesn't exist in this fresh process, so the
        // guard must return early instead of fataling with "undefined function".
        $this->assertFalse(function_exists('as_unschedule_all_actions'));

        WC_Multi_Store_Remote_Order_Sync::unschedule_sync();

        $this->assertFalse(function_exists('as_unschedule_all_actions'));
    }

    public function test_unschedule_sync_clears_the_remote_order_hook(): void
    {
        Functions\expect('as_unschedule_all_actions')
            ->once()
            ->with('wc_multi_store_sync_remote_orders', [], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);

        WC_Multi_Store_Remote_Order_Sync::unschedule_sync();

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }
}
