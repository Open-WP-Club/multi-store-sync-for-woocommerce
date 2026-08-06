<?php
/**
 * Tests for daily rate-limiting (claim_daily_slot) and email template helpers.
 *
 * Covers:
 * - claim_daily_slot: blocks each notification type when transient is set
 * - claim_daily_slot: sets transient with correct key/TTL on first call
 * - email_layout, data_row, action_button helper methods
 * - Low-stock template: threshold=0 and out-of-stock label
 * - Daily-summary health status badge logic
 * - Failed-sync template button rendering
 */

use Brain\Monkey\Functions;

class EmailNotificationsRateLimitTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') {
                return 'admin@example.com';
            }
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled'             => true,
                    'failed_sync_enabled' => true,
                    'api_error_enabled'   => true,
                    'low_stock_enabled'   => true,
                    'low_stock_threshold' => 10,
                    'recipient_email'     => 'admin@example.com',
                ];
            }
            return $default;
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_bloginfo')->justReturn('Test Store');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('esc_html')->alias(fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(fn($url) => $url);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . ltrim((string) $p, '/'));
        // Default: slot is free
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
    }

    // ── claim_daily_slot: blocks each notification type ─────────────

    public function test_failed_sync_not_sent_when_daily_slot_claimed(): void
    {
        Functions\when('get_transient')->justReturn(1); // slot already used

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_sku')->andReturn('WDG-001');
        Functions\when('wc_get_product')->justReturn($product);

        $sent = false;
        Functions\when('wp_mail')->alias(function () use (&$sent) {
            $sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_failed_sync_notification(1, 'https://store.com', 'Timeout');

        $this->assertFalse($sent, 'Email must not be sent when daily slot is already claimed');
    }

    public function test_api_error_not_sent_when_daily_slot_claimed(): void
    {
        Functions\when('get_transient')->justReturn(1);

        $sent = false;
        Functions\when('wp_mail')->alias(function () use (&$sent) {
            $sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Connection refused');

        $this->assertFalse($sent);
    }

    public function test_low_stock_not_sent_when_daily_slot_claimed(): void
    {
        Functions\when('get_transient')->justReturn(1);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_sku')->andReturn('WDG-001');
        Functions\when('wc_get_product')->justReturn($product);

        $sent = false;
        Functions\when('wp_mail')->alias(function () use (&$sent) {
            $sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_low_stock_notification(1, 'https://store.com', 3);

        $this->assertFalse($sent);
    }

    // ── claim_daily_slot: sets transient correctly on first call ─────

    public function test_claim_daily_slot_sets_transient_with_type_in_key_for_failed_sync(): void
    {
        $transient_key_set = '';
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$transient_key_set) {
            $transient_key_set = $key;
            return true;
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_sku')->andReturn('WDG-001');
        Functions\when('wc_get_product')->justReturn($product);

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_failed_sync_notification(1, 'https://store.com', 'Timeout');

        $this->assertStringContainsString('failed_sync', $transient_key_set);
    }

    public function test_claim_daily_slot_sets_transient_with_12_hour_ttl(): void
    {
        $ttl_used = 0;
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$ttl_used) {
            $ttl_used = $ttl;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Error');

        $this->assertEquals(12 * HOUR_IN_SECONDS, $ttl_used,
            'Transient TTL must equal 12 * HOUR_IN_SECONDS');
    }

    public function test_claim_daily_slot_sets_transient_with_api_error_type_in_key(): void
    {
        $transient_key_set = '';
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$transient_key_set) {
            $transient_key_set = $key;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Error');

        $this->assertStringContainsString('api_error', $transient_key_set);
    }

    public function test_claim_daily_slot_sets_transient_with_low_stock_type_in_key(): void
    {
        $transient_key_set = '';
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$transient_key_set) {
            $transient_key_set = $key;
            return true;
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_sku')->andReturn('WDG-001');
        Functions\when('wc_get_product')->justReturn($product);

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_low_stock_notification(1, 'https://store.com', 5);

        $this->assertStringContainsString('low_stock', $transient_key_set);
    }

    // ── email_layout helper ──────────────────────────────────────────

    public function test_email_layout_produces_valid_html_structure(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'email_layout');

        $html = $method->invoke($notifier, 'Test Title', '#ff0000', 'BADGE', '<p>Body</p>');

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Test Title', $html);
        $this->assertStringContainsString('BADGE', $html);
        $this->assertStringContainsString('<p>Body</p>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function test_email_layout_includes_footer_with_site_name(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'email_layout');

        $html = $method->invoke($notifier, 'Title', '#000', 'Badge', 'Content');

        $this->assertStringContainsString('Test Store', $html);
        $this->assertStringContainsString('Email Settings', $html);
    }

    public function test_email_layout_uses_accent_color_in_header(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'email_layout');

        $html = $method->invoke($notifier, 'Title', '#b32d2e', 'Badge', 'Content');

        $this->assertStringContainsString('#b32d2e', $html);
    }

    // ── data_row helper ──────────────────────────────────────────────

    public function test_data_row_renders_label_and_value(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'data_row');

        $html = $method->invoke($notifier, 'Product', 'Widget A');

        $this->assertStringContainsString('Product', $html);
        $this->assertStringContainsString('Widget A', $html);
        $this->assertStringContainsString('<tr>', $html);
        $this->assertStringContainsString('<td', $html);
    }

    public function test_data_row_applies_custom_value_color(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'data_row');

        $html = $method->invoke($notifier, 'Status', 'Failed', '#d63638');

        $this->assertStringContainsString('#d63638', $html);
        $this->assertStringContainsString('font-weight:600', $html);
    }

    public function test_data_row_default_value_color_is_dark(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'data_row');

        $html = $method->invoke($notifier, 'Label', 'Value');

        $this->assertStringContainsString('color:#1d2327', $html);
    }

    // ── action_button helper ─────────────────────────────────────────

    public function test_action_button_renders_anchor_with_correct_url(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'action_button');

        $html = $method->invoke($notifier, 'https://example.com/edit', 'Edit Product');

        $this->assertStringContainsString('https://example.com/edit', $html);
        $this->assertStringContainsString('Edit Product', $html);
        $this->assertStringContainsString('<a ', $html);
    }

    public function test_action_button_uses_provided_color(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'action_button');

        $html = $method->invoke($notifier, 'https://example.com', 'Go', '#2271b1');

        $this->assertStringContainsString('#2271b1', $html);
    }

    public function test_action_button_defaults_to_blue(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'action_button');

        $html = $method->invoke($notifier, 'https://example.com', 'Go');

        $this->assertStringContainsString('#0070a7', $html);
    }

    // ── low-stock template edge cases ────────────────────────────────

    public function test_low_stock_template_shows_out_of_stock_label_when_zero(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_low_stock_template');

        $data = [
            'product_name'   => 'Widget',
            'product_sku'    => 'WDG-001',
            'store_url'      => 'https://store.com',
            'stock_quantity' => 0,
            'threshold'      => 10,
            'timestamp'      => '2024-01-15 12:00:00',
            'edit_url'       => '',
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString('Out of Stock', $html);
    }

    public function test_low_stock_template_avoids_division_by_zero_when_threshold_is_zero(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_low_stock_template');

        $data = [
            'product_name'   => 'Widget',
            'product_sku'    => 'WDG-001',
            'store_url'      => 'https://store.com',
            'stock_quantity' => 5,
            'threshold'      => 0,
            'timestamp'      => '2024-01-15 12:00:00',
            'edit_url'       => '',
        ];

        // Should not throw a division-by-zero warning/error
        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString('Low Stock Alert', $html);
        $this->assertStringContainsString('0%', $html);
    }

    public function test_low_stock_template_shows_percentage_of_threshold(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_low_stock_template');

        $data = [
            'product_name'   => 'Widget',
            'product_sku'    => 'WDG-001',
            'store_url'      => 'https://store.com',
            'stock_quantity' => 5,
            'threshold'      => 10,
            'timestamp'      => '2024-01-15 12:00:00',
            'edit_url'       => 'https://example.com/edit',
        ];

        $html = $method->invoke($notifier, $data);

        // 5/10 = 50%
        $this->assertStringContainsString('50%', $html);
        $this->assertStringContainsString('of threshold', $html);
    }

    public function test_low_stock_template_renders_restock_button_when_edit_url_provided(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_low_stock_template');

        $data = [
            'product_name'   => 'Widget',
            'product_sku'    => 'WDG-001',
            'store_url'      => 'https://store.com',
            'stock_quantity' => 3,
            'threshold'      => 10,
            'timestamp'      => '2024-01-15 12:00:00',
            'edit_url'       => 'https://example.com/wp-admin/edit',
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString('https://example.com/wp-admin/edit', $html);
        $this->assertStringContainsString('Restock', $html);
    }

    // ── failed-sync template buttons ─────────────────────────────────

    public function test_failed_sync_template_renders_both_buttons_when_urls_provided(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_failed_sync_template');

        $data = [
            'product_name'  => 'Widget',
            'product_sku'   => 'WDG-001',
            'product_id'    => 42,
            'store_url'     => 'https://store.com',
            'error_message' => 'Timeout',
            'timestamp'     => '2024-01-15 12:00:00',
            'edit_url'      => 'https://example.com/wp-admin/post.php?post=42&action=edit',
            'logs_url'      => 'https://example.com/wp-admin/admin.php?page=logs',
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString('Edit Product', $html);
        $this->assertStringContainsString('View Logs', $html);
        $this->assertStringContainsString('Quick Actions', $html);
    }

    public function test_failed_sync_template_omits_buttons_when_no_urls(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_failed_sync_template');

        $data = [
            'product_name'  => 'Widget',
            'product_sku'   => 'WDG-001',
            'product_id'    => 42,
            'store_url'     => 'https://store.com',
            'error_message' => 'Timeout',
            'timestamp'     => '2024-01-15 12:00:00',
            'edit_url'      => '',
            'logs_url'      => '',
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringNotContainsString('Quick Actions', $html);
    }

    // ── daily-summary health badge logic ─────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('healthBadgeProvider')]
    public function test_daily_summary_template_shows_correct_health_badge(
        int $total,
        int $successful,
        float $rate,
        string $expected_label
    ): void {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_daily_summary_template');

        $data = [
            'date'            => 'January 14, 2024',
            'total_syncs'     => $total,
            'successful_syncs' => $successful,
            'failed_syncs'    => $total - $successful,
            'success_rate'    => $rate,
            'avg_duration'    => 100,
            'total_api_calls' => 50,
            'syncs_by_store'  => [],
            'dashboard_url'   => '',
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString($expected_label, $html);
    }

    public static function healthBadgeProvider(): array
    {
        return [
            'no activity'    => [0, 0, 0.0, 'No Activity'],
            'excellent (99%)' => [100, 99, 99.0, 'Excellent'],
            'excellent (100%)' => [50, 50, 100.0, 'Excellent'],
            'good (95%)'     => [100, 95, 95.0, 'Good'],
            'warning (75%)' => [100, 75, 75.0, 'Warning'],
            'critical (60%)' => [100, 60, 60.0, 'Critical'],
        ];
    }

    // ── api-error template suggested action ──────────────────────────

    public function test_api_error_template_contains_suggested_action_tip(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_api_error_template');

        $data = [
            'store_url'     => 'https://store.com',
            'error_message' => 'Connection refused',
            'timestamp'     => '2024-01-15 12:00:00',
            'settings_url'  => 'https://example.com/wp-admin/settings',
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString('Suggested action', $html);
        $this->assertStringContainsString('Store Settings', $html);
    }

    public function test_api_error_template_omits_button_when_no_settings_url(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_api_error_template');

        $data = [
            'store_url'     => 'https://store.com',
            'error_message' => 'Connection refused',
            'timestamp'     => '2024-01-15 12:00:00',
            'settings_url'  => '',
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringNotContainsString('Store Settings', $html);
    }
}
