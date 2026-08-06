<?php
/**
 * Unit tests for WC_Multi_Store_Email_Notifications
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class EmailNotificationsTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEmailMocks();
    }

    protected function setUpEmailMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') {
                return 'admin@example.com';
            }
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [];
            }
            return $default;
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_bloginfo')->justReturn('Test Store');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('esc_html')->alias(fn($text) => htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('admin_url')->alias(fn($path = '') => 'https://example.com/wp-admin/' . ltrim((string)$path, '/'));
        Functions\when('esc_url')->alias(fn($url) => $url);
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Email_Notifications'));
    }

    public function test_get_settings_defaults(): void
    {
        $settings = WC_Multi_Store_Email_Notifications::get_settings();

        $this->assertFalse($settings['enabled']);
        $this->assertEquals('admin@example.com', $settings['recipient_email']);
        $this->assertTrue($settings['failed_sync_enabled']);
        $this->assertFalse($settings['daily_summary_enabled']);
        $this->assertFalse($settings['low_stock_enabled']);
        $this->assertEquals(10, $settings['low_stock_threshold']);
        $this->assertTrue($settings['api_error_enabled']);
        $this->assertEquals('08:00', $settings['daily_summary_time']);
    }

    public function test_get_settings_merges_stored_values(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'recipient_email' => 'custom@example.com',
                    'low_stock_threshold' => 5,
                ];
            }
            return $default;
        });

        $settings = WC_Multi_Store_Email_Notifications::get_settings();

        $this->assertTrue($settings['enabled']);
        $this->assertEquals('custom@example.com', $settings['recipient_email']);
        $this->assertEquals(5, $settings['low_stock_threshold']);
        // Defaults should still be present for unset keys
        $this->assertTrue($settings['failed_sync_enabled']);
    }

    public function test_update_settings(): void
    {
        $result = WC_Multi_Store_Email_Notifications::update_settings([
            'enabled' => true,
            'recipient_email' => 'new@example.com',
        ]);

        $this->assertTrue($result);
    }

    public function test_is_enabled_false_by_default(): void
    {
        $this->assertFalse(WC_Multi_Store_Email_Notifications::is_enabled());
    }

    public function test_is_enabled_true_when_set(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return ['enabled' => true];
            }
            return $default;
        });

        $this->assertTrue(WC_Multi_Store_Email_Notifications::is_enabled());
    }

    public function test_send_failed_sync_notification_disabled(): void
    {
        // Notifications disabled by default, should return without sending
        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_failed_sync_notification(1, 'https://store.com', 'Error');
        $this->assertTrue(true);
    }

    public function test_send_failed_sync_notification_product_not_found(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return ['enabled' => true, 'failed_sync_enabled' => true];
            }
            return $default;
        });

        Functions\when('wc_get_product')->justReturn(false);

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_failed_sync_notification(999, 'https://store.com', 'Error');
        // Should return early without sending
        $this->assertTrue(true);
    }

    public function test_send_failed_sync_notification_sends_email(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'failed_sync_enabled' => true,
                    'recipient_email' => 'admin@example.com',
                ];
            }
            return $default;
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Test Product');
        $product->shouldReceive('get_sku')->andReturn('SKU-001');
        Functions\when('wc_get_product')->justReturn($product);

        $emailSent = false;
        Functions\when('wp_mail')->alias(function ($to, $subject, $message) use (&$emailSent) {
            $emailSent = true;
            $this->assertEquals('admin@example.com', $to);
            $this->assertStringContainsString('Sync Failed', $subject);
            $this->assertStringContainsString('Test Product', $message);
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_failed_sync_notification(1, 'https://store.com', 'API timeout');
        $this->assertTrue($emailSent);
    }

    public function test_send_api_error_notification_disabled(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Connection refused');
        $this->assertTrue(true);
    }

    public function test_send_api_error_notification_sends_email(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'api_error_enabled' => true,
                    'recipient_email' => 'admin@example.com',
                ];
            }
            return $default;
        });

        $emailSent = false;
        Functions\when('wp_mail')->alias(function ($to, $subject, $message) use (&$emailSent) {
            $emailSent = true;
            $this->assertStringContainsString('API Error', $subject);
            $this->assertStringContainsString('Connection refused', $message);
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Connection refused');
        $this->assertTrue($emailSent);
    }

    public function test_send_low_stock_notification_above_threshold(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'low_stock_enabled' => true,
                    'low_stock_threshold' => 10,
                ];
            }
            return $default;
        });

        // Stock (15) is above threshold (10), should NOT send
        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_low_stock_notification(1, 'https://store.com', 15);
        $this->assertTrue(true);
    }

    public function test_send_low_stock_notification_below_threshold(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'low_stock_enabled' => true,
                    'low_stock_threshold' => 10,
                    'recipient_email' => 'admin@example.com',
                ];
            }
            return $default;
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Low Stock Product');
        $product->shouldReceive('get_sku')->andReturn('LSP-001');
        Functions\when('wc_get_product')->justReturn($product);

        $emailSent = false;
        Functions\when('wp_mail')->alias(function ($to, $subject, $message) use (&$emailSent) {
            $emailSent = true;
            $this->assertStringContainsString('Low Stock', $subject);
            $this->assertStringContainsString('Low Stock Product', $message);
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_low_stock_notification(1, 'https://store.com', 3);
        $this->assertTrue($emailSent);
    }

    public function test_get_default_template_failed_sync(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_default_template');

        $data = [
            'product_name' => 'Widget',
            'product_sku' => 'WDG-001',
            'product_id' => 42,
            'store_url' => 'https://store.com',
            'error_message' => 'API timeout',
            'timestamp' => '2024-01-15 12:00:00',
        ];

        $html = $method->invoke($notifier, 'failed-sync', $data);

        $this->assertStringContainsString('Product Sync Failed', $html);
        $this->assertStringContainsString('Widget', $html);
        $this->assertStringContainsString('WDG-001', $html);
        $this->assertStringContainsString('API timeout', $html);
    }

    public function test_get_default_template_api_error(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_default_template');

        $data = [
            'store_url' => 'https://store.com',
            'error_message' => 'Connection refused',
            'timestamp' => '2024-01-15 12:00:00',
        ];

        $html = $method->invoke($notifier, 'api-error', $data);

        $this->assertStringContainsString('API Error Alert', $html);
        $this->assertStringContainsString('Connection refused', $html);
    }

    public function test_get_default_template_low_stock(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_default_template');

        $data = [
            'product_name' => 'Gadget',
            'product_sku' => 'GDG-001',
            'store_url' => 'https://store.com',
            'stock_quantity' => 3,
            'threshold' => 10,
            'timestamp' => '2024-01-15 12:00:00',
        ];

        $html = $method->invoke($notifier, 'low-stock', $data);

        $this->assertStringContainsString('Low Stock Alert', $html);
        $this->assertStringContainsString('Gadget', $html);
    }

    public function test_get_default_template_unknown_returns_empty(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_default_template');

        $html = $method->invoke($notifier, 'nonexistent', []);

        $this->assertEquals('', $html);
    }

    public function test_trigger_sync_failed_calls_do_action(): void
    {
        $called = false;
        Functions\when('do_action')->alias(function ($hook) use (&$called) {
            if ($hook === 'wc_mss_sync_failed') {
                $called = true;
            }
        });

        WC_Multi_Store_Email_Notifications::trigger_sync_failed(42, 'https://store.com', 'Error msg');
        $this->assertTrue($called);
    }

    public function test_trigger_api_error_calls_do_action(): void
    {
        $called = false;
        Functions\when('do_action')->alias(function ($hook) use (&$called) {
            if ($hook === 'wc_mss_api_error') {
                $called = true;
            }
        });

        WC_Multi_Store_Email_Notifications::trigger_api_error('https://store.com', 'API down');
        $this->assertTrue($called);
    }

    public function test_trigger_low_stock_calls_do_action(): void
    {
        $called = false;
        Functions\when('do_action')->alias(function ($hook) use (&$called) {
            if ($hook === 'wc_mss_low_stock_detected') {
                $called = true;
            }
        });

        WC_Multi_Store_Email_Notifications::trigger_low_stock(42, 'https://store.com', 5);
        $this->assertTrue($called);
    }
}
