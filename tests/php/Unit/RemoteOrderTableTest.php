<?php
/**
 * Unit tests for WC_Multi_Store_Remote_Order_Table
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class RemoteOrderTableTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Remote_Order_Table'));
    }

    public function test_db_version_constant(): void
    {
        $this->assertEquals('1.0', WC_Multi_Store_Remote_Order_Table::DB_VERSION);
    }

    public function test_has_required_methods(): void
    {
        $methods = [
            'create_table', 'insert', 'update', 'get', 'get_order_items',
            'get_orders', 'get_count', 'delete', 'order_exists',
            'cleanup_old_records', 'get_statistics',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_Remote_Order_Table', $method),
                "Missing method: {$method}"
            );
        }
    }

    // ─── insert ────────────────────────────────────

    public function test_insert_with_minimal_data(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_remote_orders',
                \Mockery::on(fn($data) =>
                    $data['status'] === 'pending'
                    && $data['currency'] === 'USD'
                    && $data['total'] === 0
                ),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Remote_Order_Table::insert([]);

        $this->assertEquals(1, $result);
    }

    public function test_insert_with_full_data(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 42;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_remote_orders',
                \Mockery::on(fn($data) =>
                    $data['remote_order_id'] === 1001
                    && $data['remote_store_url'] === 'https://store.com'
                    && $data['total'] === 99.99
                    && $data['customer_email'] === 'john@example.com'
                ),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Remote_Order_Table::insert([
            'remote_order_id' => 1001,
            'remote_store_url' => 'https://store.com',
            'order_number' => '1001',
            'total' => 99.99,
            'customer_email' => 'john@example.com',
            'status' => 'processing',
        ]);

        $this->assertEquals(42, $result);
    }

    public function test_insert_encodes_json_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $billing = ['first_name' => 'John', 'last_name' => 'Doe'];

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_remote_orders',
                \Mockery::on(fn($data) =>
                    is_string($data['billing_address'])
                    && json_decode($data['billing_address'], true) !== null
                ),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Remote_Order_Table::insert([
            'billing_address' => $billing,
        ]);

        $this->assertEquals(1, $result);
    }

    public function test_insert_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = 'DB error';

        $wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = WC_Multi_Store_Remote_Order_Table::insert([]);

        $this->assertFalse($result);
    }

    public function test_insert_with_line_items_calls_insert_line_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 10;

        // Main order insert
        $wpdb->shouldReceive('insert')
            ->times(2) // Once for order, once for line item
            ->andReturn(true);

        $result = WC_Multi_Store_Remote_Order_Table::insert([
            'line_items' => [
                ['product_name' => 'Product A', 'quantity' => 2, 'total' => 50.00],
            ],
        ]);

        $this->assertEquals(10, $result);
    }

    // ─── update ────────────────────────────────────

    public function test_update_returns_true_on_success(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $result = WC_Multi_Store_Remote_Order_Table::update(1, ['status' => 'completed']);

        $this->assertTrue($result);
    }

    public function test_update_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = 'DB error';

        $wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = WC_Multi_Store_Remote_Order_Table::update(1, ['status' => 'completed']);

        $this->assertFalse($result);
    }

    public function test_update_encodes_json_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_remote_orders',
                \Mockery::on(fn($data) =>
                    is_string($data['billing_address'])
                ),
                ['id' => 1],
                null,
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Remote_Order_Table::update(1, [
            'billing_address' => ['city' => 'Sofia'],
        ]);

        $this->assertTrue($result);
    }

    // ─── get ───────────────────────────────────────

    public function test_get_returns_order_with_decoded_json(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $order = (object) [
            'id' => 1,
            'billing_address' => '{"city":"Sofia"}',
            'shipping_address' => '{}',
            'line_items' => '[{"name":"Product"}]',
            'order_meta' => '{}',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn($order);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]); // order items

        $result = WC_Multi_Store_Remote_Order_Table::get(1);

        $this->assertIsObject($result);
        $this->assertIsArray($result->billing_address);
        $this->assertEquals('Sofia', $result->billing_address['city']);
        $this->assertIsArray($result->line_items);
    }

    public function test_get_returns_null_when_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = WC_Multi_Store_Remote_Order_Table::get(999);

        $this->assertNull($result);
    }

    // ─── get_order_items ───────────────────────────

    public function test_get_order_items_decodes_meta_data(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $items = [
            (object) [
                'id' => 1,
                'product_name' => 'Product A',
                'meta_data' => '{"key":"value"}',
            ],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn($items);

        $result = WC_Multi_Store_Remote_Order_Table::get_order_items(1);

        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]->meta_data);
        $this->assertEquals('value', $result[0]->meta_data['key']);
    }

    // ─── delete ────────────────────────────────────

    public function test_delete_removes_order_and_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // Delete line items first
        $wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_wc_mss_remote_order_items', ['remote_order_id' => 1], ['%d'])
            ->andReturn(3);

        // Delete order
        $wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_wc_mss_remote_orders', ['id' => 1], ['%d'])
            ->andReturn(1);

        $result = WC_Multi_Store_Remote_Order_Table::delete(1);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('delete')
            ->twice()
            ->andReturn(false);

        $result = WC_Multi_Store_Remote_Order_Table::delete(1);

        $this->assertFalse($result);
    }

    // ─── order_exists ──────────────────────────────

    public function test_order_exists_returns_id_when_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_var')->once()->andReturn('42');

        $result = WC_Multi_Store_Remote_Order_Table::order_exists(1001, 'https://store.com');

        $this->assertEquals('42', $result);
    }

    public function test_order_exists_returns_null_when_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $result = WC_Multi_Store_Remote_Order_Table::order_exists(9999, 'https://store.com');

        $this->assertNull($result);
    }

    // ─── get_count ─────────────────────────────────

    public function test_get_count_without_filters(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(\Mockery::pattern('/SELECT COUNT/'))
            ->andReturn('100');

        $result = WC_Multi_Store_Remote_Order_Table::get_count();

        $this->assertEquals(100, $result);
    }

    public function test_get_count_with_store_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT COUNT(*)...');
        $wpdb->shouldReceive('get_var')->once()->andReturn('25');

        $result = WC_Multi_Store_Remote_Order_Table::get_count([
            'store_url' => 'https://store.com',
        ]);

        $this->assertEquals(25, $result);
    }

    // ─── get_orders ────────────────────────────────

    public function test_get_orders_returns_decoded_json(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $orders = [
            (object) [
                'id' => 1,
                'billing_address' => '{"city":"Sofia"}',
                'shipping_address' => '{}',
                'line_items' => '[]',
                'order_meta' => '{}',
            ],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn($orders);

        $result = WC_Multi_Store_Remote_Order_Table::get_orders();

        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]->billing_address);
    }

    public function test_get_orders_sanitizes_orderby(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        // Should fallback to date_created for invalid orderby
        $result = WC_Multi_Store_Remote_Order_Table::get_orders([
            'orderby' => 'DROP TABLE; --',
        ]);

        $this->assertIsArray($result);
    }

    // ─── get_statistics ────────────────────────────

    public function test_get_statistics_returns_expected_keys(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $stats = [
            'total_orders' => 100,
            'total_revenue' => 5000.00,
            'average_order_value' => 50.00,
            'unique_customers' => 75,
            'store_count' => 3,
        ];

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn($stats);

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                ['status' => 'completed', 'count' => 80],
                ['status' => 'processing', 'count' => 20],
            ]);

        // Handle the case where prepare may or may not be called
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');

        $result = WC_Multi_Store_Remote_Order_Table::get_statistics();

        $this->assertArrayHasKey('total_orders', $result);
        $this->assertArrayHasKey('total_revenue', $result);
        $this->assertArrayHasKey('average_order_value', $result);
        $this->assertArrayHasKey('unique_customers', $result);
        $this->assertArrayHasKey('by_status', $result);
    }

    // ─── cleanup_old_records ───────────────────────

    public function test_cleanup_old_records_deletes_expired(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT/DELETE ...');
        $wpdb->shouldReceive('get_col')->once()->andReturn([1, 2, 3]);
        $wpdb->shouldReceive('query')->twice()->andReturn(3); // items + orders

        $deleted = WC_Multi_Store_Remote_Order_Table::cleanup_old_records(90);

        $this->assertEquals(3, $deleted);
    }

    public function test_cleanup_old_records_returns_zero_when_nothing_to_delete(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_col')->once()->andReturn([]);

        $deleted = WC_Multi_Store_Remote_Order_Table::cleanup_old_records();

        $this->assertEquals(0, $deleted);
    }

    // ─── calculate_hash (private) ──────────────────

    public function test_calculate_hash_is_deterministic(): void
    {
        $ref = new ReflectionClass('WC_Multi_Store_Remote_Order_Table');
        $method = $ref->getMethod('calculate_hash');

        $data = [
            'status' => 'processing',
            'total' => 99.99,
            'line_items' => [['name' => 'Product']],
            'date_modified' => '2024-06-15',
        ];

        $hash1 = $method->invoke(null, $data);
        $hash2 = $method->invoke(null, $data);

        $this->assertEquals($hash1, $hash2);
    }

    public function test_calculate_hash_differs_for_different_data(): void
    {
        $ref = new ReflectionClass('WC_Multi_Store_Remote_Order_Table');
        $method = $ref->getMethod('calculate_hash');

        $data1 = ['status' => 'processing', 'total' => 99.99];
        $data2 = ['status' => 'completed', 'total' => 99.99];

        $hash1 = $method->invoke(null, $data1);
        $hash2 = $method->invoke(null, $data2);

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_calculate_hash_returns_sha256(): void
    {
        $ref = new ReflectionClass('WC_Multi_Store_Remote_Order_Table');
        $method = $ref->getMethod('calculate_hash');

        $hash = $method->invoke(null, ['status' => 'pending']);

        $this->assertEquals(64, strlen($hash)); // SHA-256 produces 64 hex chars
    }
}
