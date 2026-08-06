<?php
/**
 * Unit tests for WC_Multi_Store_Webhook_Logger
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WebhookLoggerTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2024-03-15 14:30:00');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('esc_html')->alias(fn($text) => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    // ─── Constants ─────────────────────────────────

    public function test_log_type_constants_defined(): void
    {
        $this->assertEquals('order_received', WC_Multi_Store_Webhook_Logger::TYPE_ORDER_RECEIVED);
        $this->assertEquals('stock_deducted', WC_Multi_Store_Webhook_Logger::TYPE_STOCK_DEDUCTED);
        $this->assertEquals('stock_synced', WC_Multi_Store_Webhook_Logger::TYPE_STOCK_SYNCED);
        $this->assertEquals('auth_failed', WC_Multi_Store_Webhook_Logger::TYPE_AUTH_FAILED);
        $this->assertEquals('validation_error', WC_Multi_Store_Webhook_Logger::TYPE_VALIDATION_ERROR);
        $this->assertEquals('product_not_found', WC_Multi_Store_Webhook_Logger::TYPE_PRODUCT_NOT_FOUND);
        $this->assertEquals('rate_limited', WC_Multi_Store_Webhook_Logger::TYPE_RATE_LIMITED);
    }

    public function test_default_retention_days(): void
    {
        $this->assertEquals(90, WC_Multi_Store_Webhook_Logger::DEFAULT_RETENTION_DAYS);
    }

    public function test_table_name_constant(): void
    {
        $this->assertEquals('wc_mss_webhook_logs', WC_Multi_Store_Webhook_Logger::TABLE_NAME);
    }

    // ─── Type labels ───────────────────────────────

    public function test_get_type_label_returns_label_for_known_types(): void
    {
        $known_types = [
            WC_Multi_Store_Webhook_Logger::TYPE_ORDER_RECEIVED,
            WC_Multi_Store_Webhook_Logger::TYPE_STOCK_DEDUCTED,
            WC_Multi_Store_Webhook_Logger::TYPE_STOCK_SYNCED,
            WC_Multi_Store_Webhook_Logger::TYPE_AUTH_FAILED,
            WC_Multi_Store_Webhook_Logger::TYPE_VALIDATION_ERROR,
            WC_Multi_Store_Webhook_Logger::TYPE_PRODUCT_NOT_FOUND,
            WC_Multi_Store_Webhook_Logger::TYPE_RATE_LIMITED,
        ];

        foreach ($known_types as $type) {
            $label = WC_Multi_Store_Webhook_Logger::get_type_label($type);
            $this->assertNotEmpty($label);
            // Label should be different from raw type (it's human-readable)
            $this->assertNotEquals($type, $label);
        }
    }

    public function test_get_type_label_returns_raw_type_for_unknown(): void
    {
        $this->assertEquals('custom_type', WC_Multi_Store_Webhook_Logger::get_type_label('custom_type'));
    }

    // ─── Status badges ─────────────────────────────

    public function test_get_status_badge_success(): void
    {
        $badge = WC_Multi_Store_Webhook_Logger::get_status_badge('success');

        $this->assertStringContainsString('wc-mss-badge-success', $badge);
        $this->assertStringContainsString('<span', $badge);
    }

    public function test_get_status_badge_failed(): void
    {
        $badge = WC_Multi_Store_Webhook_Logger::get_status_badge('failed');

        $this->assertStringContainsString('wc-mss-badge-error', $badge);
    }

    public function test_get_status_badge_unknown_status(): void
    {
        $badge = WC_Multi_Store_Webhook_Logger::get_status_badge('pending');

        $this->assertStringContainsString('wc-mss-badge', $badge);
        $this->assertStringNotContainsString('wc-mss-badge-success', $badge);
        $this->assertStringNotContainsString('wc-mss-badge-error', $badge);
    }

    // ─── log() data formatting ─────────────────────

    public function test_log_inserts_data_into_database(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 42;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(function ($data) {
                    return $data['log_type'] === 'order_received'
                        && $data['store_url'] === 'https://store.example.com'
                        && $data['status'] === 'success'
                        && $data['created_at'] === '2024-03-15 14:30:00';
                })
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log('order_received', [
            'store_url' => 'https://store.example.com',
            'status' => 'success',
        ]);

        $this->assertEquals(42, $result);
    }

    public function test_log_returns_false_on_db_failure(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = 'Table not found';

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);

        $result = WC_Multi_Store_Webhook_Logger::log('test', []);

        $this->assertFalse($result);
    }

    // ─── Specialized log methods ───────────────────

    public function test_log_order_received_formats_data(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(function ($data) {
                    return $data['log_type'] === 'order_received'
                        && $data['remote_order_id'] === 999
                        && $data['store_url'] === 'https://remote.store'
                        && $data['request_ip'] === '192.168.1.100';
                })
            )
            ->andReturn(true);

        $order_data = [
            'id' => 999,
            'status' => 'processing',
            'total' => '49.99',
            'line_items' => [
                ['sku' => 'ABC-001', 'name' => 'Widget', 'quantity' => 2],
                ['sku' => 'DEF-002', 'name' => 'Gadget', 'quantity' => 1],
            ],
        ];

        $result = WC_Multi_Store_Webhook_Logger::log_order_received(
            $order_data,
            'https://remote.store',
            '192.168.1.100'
        );

        $this->assertEquals(1, $result);
    }

    public function test_log_stock_deducted_calculates_negative_quantity(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 5;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(function ($data) {
                    return $data['log_type'] === 'stock_deducted'
                        && $data['old_stock'] === 50
                        && $data['new_stock'] === 47
                        && $data['quantity_changed'] === -3
                        && $data['product_sku'] === 'TEST-SKU';
                })
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log_stock_deducted(
            101,          // product_id
            'TEST-SKU',   // sku
            50,           // old_stock
            47,           // new_stock
            3,            // quantity
            999,          // remote_order_id
            'https://shop.example.com'
        );

        $this->assertEquals(5, $result);
    }

    public function test_log_auth_failed_sets_failed_status(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 10;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(function ($data) {
                    return $data['log_type'] === 'auth_failed'
                        && $data['status'] === 'failed'
                        && $data['request_ip'] === '10.0.0.1'
                        && $data['error_message'] === 'Invalid secret';
                })
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log_auth_failed(
            'Invalid secret',
            '10.0.0.1'
        );

        $this->assertEquals(10, $result);
    }

    public function test_log_product_not_found_includes_sku(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 15;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(function ($data) {
                    return $data['log_type'] === 'product_not_found'
                        && $data['product_sku'] === 'MISSING-SKU'
                        && $data['status'] === 'failed';
                })
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log_product_not_found(
            'MISSING-SKU',
            1001,
            'https://store.example.com'
        );

        $this->assertEquals(15, $result);
    }

    public function test_log_rate_limited_includes_count(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 20;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(function ($data) {
                    return $data['log_type'] === 'rate_limited'
                        && $data['status'] === 'failed'
                        && $data['request_ip'] === '203.0.113.5';
                })
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log_rate_limited('203.0.113.5', 150);

        $this->assertEquals(20, $result);
    }

    // ─── IP detection ──────────────────────────────

    public function test_get_client_ip_from_cloudflare_header_when_proxy_trusted(): void
    {
        // CF-Connecting-IP is only trusted when REMOTE_ADDR itself is a
        // configured trusted proxy (see get_client_ip() docblock) — otherwise
        // it's attacker-controlled on any request that reaches this server directly.
        $_SERVER['REMOTE_ADDR'] = '172.16.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';

        Functions\when('apply_filters')->alias(function ($tag, $value) {
            return $tag === 'wc_mss_trusted_proxies' ? ['172.16.0.1'] : $value;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(fn($data) => $data['request_ip'] === '203.0.113.50')
            )
            ->andReturn(true);

        // log() without explicit request_ip falls back to get_client_ip()
        $result = WC_Multi_Store_Webhook_Logger::log('test', []);

        $this->assertEquals(1, $result);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    public function test_get_client_ip_from_forwarded_for_when_proxy_trusted(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 70.41.3.18';

        Functions\when('apply_filters')->alias(function ($tag, $value) {
            return $tag === 'wc_mss_trusted_proxies' ? ['10.0.0.1'] : $value;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(fn($data) => $data['request_ip'] === '203.0.113.50')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log('test', []);

        $this->assertEquals(1, $result);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_get_client_ip_ignores_proxy_header_without_trusted_proxy(): void
    {
        // Regression test for the fix: this file's get_client_ip() used to trust
        // CF-Connecting-IP/X-Forwarded-For unconditionally, letting any external
        // caller spoof the IP recorded in the webhook audit log. Without a
        // configured trusted proxy, REMOTE_ADDR must win even if a proxy header is present.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(fn($data) => $data['request_ip'] === '203.0.113.9')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log('test', []);

        $this->assertEquals(1, $result);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    public function test_get_client_ip_fallback_when_no_headers(): void
    {
        // Clear all IP headers
        unset(
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP'],
            $_SERVER['REMOTE_ADDR']
        );

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_webhook_logs',
                \Mockery::on(fn($data) => $data['request_ip'] === '0.0.0.0')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Webhook_Logger::log('test', []);

        $this->assertEquals(1, $result);
    }

    // ─── Cleanup ───────────────────────────────────

    public function test_cleanup_old_logs_uses_setting_retention(): void
    {
        global $wpdb;

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_log_retention_days' => 60];
            }
            return $default;
        });

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('DELETE FROM wp_wc_mss_webhook_logs WHERE created_at < ...');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(5);

        $deleted = WC_Multi_Store_Webhook_Logger::cleanup_old_logs();

        $this->assertEquals(5, $deleted);
    }

    public function test_cleanup_old_logs_with_explicit_days(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('DELETE ...');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(10);

        $deleted = WC_Multi_Store_Webhook_Logger::cleanup_old_logs(30);

        $this->assertEquals(10, $deleted);
    }

    // ─── get_count ─────────────────────────────────

    public function test_get_count_returns_integer(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('42');

        $this->assertEquals(42, WC_Multi_Store_Webhook_Logger::get_count());
    }
}
