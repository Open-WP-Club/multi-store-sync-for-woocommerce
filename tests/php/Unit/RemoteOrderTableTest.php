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

    public function test_insert_encodes_every_json_field(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_wc_mss_remote_orders',
            \Mockery::on(fn(array $data): bool =>
                $data['billing_address'] === '{"city":"Sofia"}'
                && $data['shipping_address'] === '{"city":"Plovdiv"}'
                && $data['line_items'] === '[]'
                && $data['order_meta'] === '{"source":"api"}'
            ),
            \Mockery::type('array')
        )->andReturn(true);

        $this->assertSame(1, WC_Multi_Store_Remote_Order_Table::insert([
            'billing_address' => ['city' => 'Sofia'],
            'shipping_address' => ['city' => 'Plovdiv'],
            'line_items' => [],
            'order_meta' => ['source' => 'api'],
        ]));
    }

    public function test_insert_line_item_encodes_meta_data(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 10;
        $wpdb->shouldReceive('insert')->once()->with('wp_wc_mss_remote_orders', \Mockery::any(), \Mockery::any())->andReturn(true);
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_wc_mss_remote_order_items',
            \Mockery::on(fn(array $data): bool => $data['meta_data'] === '{"size":"XL"}'),
            \Mockery::type('array')
        )->andReturn(true);

        $this->assertSame(10, WC_Multi_Store_Remote_Order_Table::insert([
            'line_items' => [['product_name' => 'T-shirt', 'meta_data' => ['size' => 'XL']]],
        ]));
    }

    public function test_insert_line_items_reports_partial_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = 'disk full';
        $wpdb->shouldReceive('insert')->twice()->andReturn(true, false);

        $method = (new ReflectionClass('WC_Multi_Store_Remote_Order_Table'))->getMethod('insert_line_items');

        $this->assertFalse($method->invoke(null, 10, [
            ['product_name' => 'First'],
            ['product_name' => 'Second'],
        ]));
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

    public function test_update_encodes_all_optional_json_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')->once()->with(
            'wp_wc_mss_remote_orders',
            \Mockery::on(fn(array $data): bool =>
                is_string($data['billing_address'])
                && is_string($data['shipping_address'])
                && is_string($data['line_items'])
                && is_string($data['order_meta'])
            ),
            ['id' => 1],
            null,
            ['%d']
        )->andReturn(1);

        $this->assertTrue(WC_Multi_Store_Remote_Order_Table::update(1, [
            'billing_address' => [],
            'shipping_address' => [],
            'line_items' => [],
            'order_meta' => [],
        ]));
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

    public function test_get_order_items_returns_empty_array(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->assertSame([], WC_Multi_Store_Remote_Order_Table::get_order_items(1));
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

    public function test_get_count_applies_status_customer_and_date_filters(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->with(
            \Mockery::on(fn(string $sql): bool =>
                str_contains($sql, 'status = %s')
                && str_contains($sql, 'customer_email = %s')
                && str_contains($sql, 'date_created >= %s')
                && str_contains($sql, 'date_created <= %s')
            ),
            ['completed', 'buyer@example.com', '2026-01-01', '2026-01-31']
        )->andReturn('FILTERED COUNT');
        $wpdb->shouldReceive('get_var')->once()->with('FILTERED COUNT')->andReturn('4');

        $this->assertSame(4, WC_Multi_Store_Remote_Order_Table::get_count([
            'status' => 'completed',
            'customer_email' => 'buyer@example.com',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]));
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

    public function test_get_orders_supports_ascending_allowed_order(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->andReturn(' LIMIT 10 OFFSET 0');
        $wpdb->shouldReceive('get_results')->once()->with(
            \Mockery::on(fn(string $sql): bool => str_contains($sql, 'ORDER BY total ASC'))
        )->andReturn([]);

        $this->assertSame([], WC_Multi_Store_Remote_Order_Table::get_orders([
            'orderby' => 'total',
            'order' => 'asc',
            'limit' => 10,
        ]));
    }

    public function test_get_orders_uses_descending_order_for_invalid_direction(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->andReturn(' LIMIT 20 OFFSET 0');
        $wpdb->shouldReceive('get_results')->once()->with(
            \Mockery::on(fn(string $sql): bool => str_contains($sql, 'ORDER BY status DESC'))
        )->andReturn([]);

        $this->assertSame([], WC_Multi_Store_Remote_Order_Table::get_orders([
            'orderby' => 'status',
            'order' => 'sideways',
        ]));
    }

    public function test_get_orders_can_disable_pagination(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->never();
        $wpdb->shouldReceive('get_results')->once()->with(
            \Mockery::on(fn(string $sql): bool => !str_contains($sql, ' LIMIT '))
        )->andReturn([]);

        $this->assertSame([], WC_Multi_Store_Remote_Order_Table::get_orders(['limit' => 0]));
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

    public function test_get_statistics_returns_safe_defaults_when_no_aggregate_row_exists(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = WC_Multi_Store_Remote_Order_Table::get_statistics();

        $this->assertSame(0, $result['total_orders']);
        $this->assertSame([], $result['by_status']);
    }

    public function test_get_statistics_applies_store_and_date_filters_to_both_queries(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->twice()->andReturnUsing(fn(string $sql): string => $sql);
        $wpdb->shouldReceive('get_row')->once()->andReturn([]);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = WC_Multi_Store_Remote_Order_Table::get_statistics([
            'store_url' => 'https://shop.example.com',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->assertSame([], $result['by_status']);
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
