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
        // Action Scheduler functions don't exist in tests, so is_available() returns false
        WC_Multi_Store_Remote_Order_Sync::schedule_sync('daily');
        $this->assertTrue(true);
    }

    // Patchwork may persist as_unschedule_all_actions from HealthCheckTrackingTest,
    // so run this in a separate process where the function won't exist.
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_unschedule_sync_without_function_does_nothing(): void
    {
        // as_unschedule_all_actions doesn't exist, so should return early
        WC_Multi_Store_Remote_Order_Sync::unschedule_sync();
        $this->assertTrue(true);
    }
}
