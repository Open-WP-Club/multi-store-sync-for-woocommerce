<?php
/**
 * Unit tests for WC_Multi_Store_Remote_Order_List_Table
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class RemoteOrderListTableTest extends WC_Multi_Store_TestCase
{
    private ?WC_Multi_Store_Remote_Order_List_Table $table = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('WC_Multi_Store_Remote_Order_List_Table', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/remote-order-list-table.php';
        }

        $this->table = new WC_Multi_Store_Remote_Order_List_Table();
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Remote_Order_List_Table'));
    }

    public function test_extends_wp_list_table(): void
    {
        $this->assertInstanceOf('WP_List_Table', $this->table);
    }

    public function test_constructor_sets_args(): void
    {
        $this->assertEquals('remote_order', $this->table->_args['singular']);
        $this->assertEquals('remote_orders', $this->table->_args['plural']);
        $this->assertFalse($this->table->_args['ajax']);
    }

    // ─── get_columns ───────────────────────────────

    public function test_get_columns_returns_expected_columns(): void
    {
        $columns = $this->table->get_columns();

        $expected_keys = ['cb', 'order', 'date', 'status', 'store', 'customer', 'total', 'actions'];

        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey($key, $columns, "Missing column: {$key}");
        }
    }

    public function test_get_columns_has_checkbox(): void
    {
        $columns = $this->table->get_columns();

        $this->assertStringContainsString('checkbox', $columns['cb']);
    }

    // ─── get_sortable_columns ──────────────────────

    public function test_get_sortable_columns_returns_expected(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('get_sortable_columns');

        $sortable = $method->invoke($this->table);

        $this->assertArrayHasKey('order', $sortable);
        $this->assertArrayHasKey('date', $sortable);
        $this->assertArrayHasKey('status', $sortable);
        $this->assertArrayHasKey('total', $sortable);
        $this->assertArrayHasKey('customer', $sortable);
    }

    public function test_date_is_default_sorted(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('get_sortable_columns');

        $sortable = $method->invoke($this->table);

        // date column has true as second element (already sorted)
        $this->assertTrue($sortable['date'][1]);
    }

    // ─── get_bulk_actions ──────────────────────────

    public function test_get_bulk_actions_includes_delete(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('get_bulk_actions');

        $actions = $method->invoke($this->table);

        $this->assertArrayHasKey('delete', $actions);
    }

    // ─── column_cb ─────────────────────────────────

    public function test_column_cb_renders_checkbox(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_cb');

        $item = (object) ['id' => 42];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('type="checkbox"', $result);
        $this->assertStringContainsString('value="42"', $result);
        $this->assertStringContainsString('order_id[]', $result);
    }

    // ─── column_order ──────────────────────────────

    public function test_column_order_renders_link(): void
    {
        Functions\when('admin_url')->alias(fn($path) => 'http://example.com/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('esc_url')->alias(fn($url) => $url);
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
        Functions\when('wp_create_nonce')->justReturn('test_nonce');

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_order');

        $item = (object) ['id' => 1, 'order_number' => '1001'];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('#1001', $result);
        $this->assertStringContainsString('order-preview', $result);
    }

    // ─── column_date ───────────────────────────────

    public function test_column_date_formats_date(): void
    {
        Functions\when('date_i18n')->alias(fn($format, $ts) => date($format, $ts));
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_date');

        $item = (object) ['date_created' => '2024-06-15 10:30:00'];

        $result = $method->invoke($this->table, $item);

        $this->assertNotEquals('—', $result);
    }

    public function test_column_date_returns_dash_for_invalid_date(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_date');

        $item = (object) ['date_created' => ''];

        $result = $method->invoke($this->table, $item);

        $this->assertEquals('—', $result);
    }

    // ─── column_status ─────────────────────────────

    public function test_column_status_renders_mark_element(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_status');

        $item = (object) ['status' => 'processing'];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('<mark', $result);
        $this->assertStringContainsString('Processing', $result);
        $this->assertStringContainsString('status-processing', $result);
    }

    public function test_column_status_strips_wc_prefix(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_status');

        $item = (object) ['status' => 'wc-completed'];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('Completed', $result);
        $this->assertStringContainsString('status-completed', $result);
    }

    // ─── column_store ──────────────────────────────

    public function test_column_store_renders_hostname(): void
    {
        Functions\when('admin_url')->alias(fn($path) => 'http://example.com/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('esc_url')->alias(fn($url) => $url);
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_store');

        $item = (object) ['remote_store_url' => 'https://shop.example.com'];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('shop.example.com', $result);
    }

    // ─── column_customer ───────────────────────────

    public function test_column_customer_returns_dash_when_empty(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_customer');

        $item = (object) ['customer_email' => '', 'customer_name' => ''];

        $result = $method->invoke($this->table, $item);

        $this->assertEquals('—', $result);
    }

    public function test_column_customer_shows_name_and_email(): void
    {
        Functions\when('admin_url')->alias(fn($path) => 'http://example.com/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('esc_url')->alias(fn($url) => $url);
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_customer');

        $item = (object) [
            'customer_email' => 'john@example.com',
            'customer_name' => 'John Doe',
        ];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('John Doe', $result);
        $this->assertStringContainsString('john@example.com', $result);
    }

    public function test_column_customer_shows_email_only(): void
    {
        Functions\when('admin_url')->alias(fn($path) => 'http://example.com/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('esc_url')->alias(fn($url) => $url);
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_customer');

        $item = (object) [
            'customer_email' => 'john@example.com',
            'customer_name' => '',
        ];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('john@example.com', $result);
    }

    // ─── column_total ──────────────────────────────

    public function test_column_total_formats_price(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_total');

        $item = (object) ['total' => '99.99', 'currency' => 'USD'];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('$', $result);
        $this->assertStringContainsString('99.99', $result);
    }

    public function test_column_total_handles_eur_currency(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('column_total');

        $item = (object) ['total' => '50.00', 'currency' => 'EUR'];

        $result = $method->invoke($this->table, $item);

        $this->assertStringContainsString('€', $result);
    }

    // ─── get_currency_symbol ───────────────────────

    public function test_get_currency_symbol_known_currencies(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('get_currency_symbol');

        $this->assertEquals('$', $method->invoke($this->table, 'USD'));
        $this->assertEquals('€', $method->invoke($this->table, 'EUR'));
        $this->assertEquals('£', $method->invoke($this->table, 'GBP'));
        $this->assertEquals('¥', $method->invoke($this->table, 'JPY'));
        $this->assertEquals('A$', $method->invoke($this->table, 'AUD'));
        $this->assertEquals('C$', $method->invoke($this->table, 'CAD'));
    }

    public function test_get_currency_symbol_unknown_returns_code(): void
    {
        $ref = new ReflectionClass($this->table);
        $method = $ref->getMethod('get_currency_symbol');

        $this->assertEquals('BGN ', $method->invoke($this->table, 'BGN'));
        $this->assertEquals('PLN ', $method->invoke($this->table, 'PLN'));
    }
}
