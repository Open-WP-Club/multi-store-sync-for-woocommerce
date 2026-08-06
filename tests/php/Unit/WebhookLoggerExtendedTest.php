<?php
/**
 * Extended unit tests for WC_Multi_Store_Webhook_Logger
 * Tests get_logs, get_stats, log_stock_synced, log_validation_error, delete methods
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WebhookLoggerExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
    }

    // ── log_stock_synced ─────────────────────────────────────────

    public function test_log_stock_synced(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 5;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = WC_Multi_Store_Webhook_Logger::log_stock_synced(
            42,
            'SKU-SYNC',
            25,
            'https://store1.com'
        );

        $this->assertEquals(5, $result);
    }

    // ── log_validation_error ─────────────────────────────────────

    public function test_log_validation_error(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 6;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = WC_Multi_Store_Webhook_Logger::log_validation_error(
            'Invalid order data',
            'https://store1.com',
            ['raw' => 'data']
        );

        $this->assertEquals(6, $result);
    }

    public function test_log_validation_error_without_store(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 7;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = WC_Multi_Store_Webhook_Logger::log_validation_error('Bad format');

        $this->assertEquals(7, $result);
    }

    // ── get_logs ─────────────────────────────────────────────────

    public function test_get_logs_returns_structure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_var')->andReturn(2); // total count
        $wpdb->shouldReceive('get_results')->andReturn([
            [
                'id' => 1,
                'log_type' => 'order_received',
                'request_data' => '{"order_id":123}',
                'response_data' => null,
            ],
            [
                'id' => 2,
                'log_type' => 'stock_deducted',
                'request_data' => null,
                'response_data' => '{"success":true}',
            ],
        ]);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => addcslashes($s, '_%\\'));

        $result = WC_Multi_Store_Webhook_Logger::get_logs();

        $this->assertArrayHasKey('logs', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['logs']);
        // JSON should be decoded
        $this->assertIsArray($result['logs'][0]['request_data']);
        $this->assertEquals(123, $result['logs'][0]['request_data']['order_id']);
    }

    public function test_get_logs_with_filters(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_var')->andReturn(1);
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => 1, 'log_type' => 'auth_failed', 'request_data' => null, 'response_data' => null],
        ]);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => addcslashes($s, '_%\\'));

        $result = WC_Multi_Store_Webhook_Logger::get_logs([
            'log_type' => 'auth_failed',
            'status' => 'failed',
            'per_page' => 10,
            'page' => 1,
        ]);

        $this->assertEquals(1, $result['total']);
    }

    public function test_get_logs_with_date_range(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_var')->andReturn(0);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => addcslashes($s, '_%\\'));

        $result = WC_Multi_Store_Webhook_Logger::get_logs([
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
        ]);

        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['logs']);
    }

    // ── get_stats ────────────────────────────────────────────────

    public function test_get_stats_returns_structure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn(
            [['log_type' => 'order_received', 'count' => '10']],       // type_counts
            [['status' => 'success', 'count' => '8'], ['status' => 'failed', 'count' => '2']], // status_counts
            [],  // store_activity
            [],  // daily_activity
        );
        $wpdb->shouldReceive('get_row')->andReturn([
            'total_changes' => '5',
            'total_quantity_changed' => '25',
            'unique_products' => '3',
        ]);

        $result = WC_Multi_Store_Webhook_Logger::get_stats(30);

        $this->assertArrayHasKey('type_counts', $result);
        $this->assertArrayHasKey('status_counts', $result);
        $this->assertArrayHasKey('store_activity', $result);
        $this->assertArrayHasKey('daily_activity', $result);
        $this->assertArrayHasKey('stock_stats', $result);
        $this->assertEquals(30, $result['period_days']);
        $this->assertEquals('10', $result['type_counts']['order_received']);
    }

    // ── get_count ────────────────────────────────────────────────

    public function test_get_count_returns_total(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('42');

        $count = WC_Multi_Store_Webhook_Logger::get_count();

        $this->assertEquals(42, $count);
    }

    // ── log failure ──────────────────────────────────────────────

    public function test_log_returns_false_on_db_error(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = 'Table not found';
        $wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = WC_Multi_Store_Webhook_Logger::log('order_received', [
            'store_url' => 'https://store1.com',
        ]);

        $this->assertFalse($result);
    }

    // ── extract_line_items_summary ───────────────────────────────

    public function test_extract_line_items_summary(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Webhook_Logger::class, 'extract_line_items_summary');

        $items = [
            ['sku' => 'SKU-1', 'name' => 'Product 1', 'quantity' => 2],
            ['sku' => 'SKU-2', 'name' => 'Product 2', 'quantity' => 1],
            ['name' => 'No SKU Product'], // missing sku and quantity
        ];

        $result = $method->invoke(null, $items);

        $this->assertCount(3, $result);
        $this->assertEquals('SKU-1', $result[0]['sku']);
        $this->assertEquals(2, $result[0]['quantity']);
        $this->assertEquals('N/A', $result[2]['sku']);
        $this->assertEquals(0, $result[2]['quantity']);
    }
}
