<?php
/**
 * Extended unit tests for WC_Multi_Store_Email_Notifications
 *
 * Covers: schedule_daily_summary, send_daily_summary, low_stock threshold edge cases,
 * send_email headers, template rendering with store data.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class EmailNotificationsExtendedTest extends WC_Multi_Store_TestCase
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
        Functions\when('esc_html')->alias(fn($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('admin_url')->alias(fn($path = '') => 'https://example.com/wp-admin/' . ltrim((string)$path, '/'));
        Functions\when('esc_url')->alias(fn($url) => $url);
    }

    // ── schedule_daily_summary ───────────────────────────────────

    public function test_schedule_daily_summary_returns_early_when_scheduler_unavailable(): void
    {
        // Without real Action Scheduler functions, is_available() returns false
        // and schedule_daily_summary should return early without error
        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->schedule_daily_summary();

        $this->assertTrue(true);
    }

    // ── send_daily_summary ───────────────────────────────────────

    public function test_send_daily_summary_when_disabled_does_nothing(): void
    {
        // Default settings have daily_summary_enabled = false
        $email_sent = false;
        Functions\when('wp_mail')->alias(function () use (&$email_sent) {
            $email_sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_daily_summary();

        $this->assertFalse($email_sent);
    }

    public function test_send_daily_summary_sends_email_with_stats(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'daily_summary_enabled' => true,
                    'recipient_email' => 'admin@example.com',
                ];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $stats = (object) [
            'total_syncs' => 50,
            'successful_syncs' => 45,
            'failed_syncs' => 5,
            'avg_duration' => 250,
            'total_api_calls' => 100,
        ];

        $syncs_by_store = [
            ['store_url' => 'https://store1.com', 'total' => 30, 'successful' => 28, 'failed' => 2],
            ['store_url' => 'https://store2.com', 'total' => 20, 'successful' => 17, 'failed' => 3],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->andReturn($stats);
        $wpdb->shouldReceive('get_results')->andReturn($syncs_by_store);

        $email_subject = '';
        $email_body = '';
        Functions\when('wp_mail')->alias(function ($to, $subject, $message) use (&$email_subject, &$email_body) {
            $email_subject = $subject;
            $email_body = $message;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_daily_summary();

        $this->assertStringContainsString('Daily Sync Summary', $email_subject);
        $this->assertStringContainsString('50', $email_body); // total syncs
        $this->assertStringContainsString('45', $email_body); // successful
        $this->assertStringContainsString('store1.com', $email_body);
        $this->assertStringContainsString('store2.com', $email_body);
    }

    public function test_send_daily_summary_with_zero_syncs(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'daily_summary_enabled' => true,
                    'recipient_email' => 'admin@example.com',
                ];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $stats = (object) [
            'total_syncs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'avg_duration' => null,
            'total_api_calls' => 0,
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->andReturn($stats);
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $email_body = '';
        Functions\when('wp_mail')->alias(function ($to, $subject, $message) use (&$email_body) {
            $email_body = $message;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_daily_summary();

        $this->assertStringContainsString('0', $email_body);
    }

    // ── low_stock threshold edge cases ───────────────────────────

    public function test_low_stock_at_exact_threshold_sends_email(): void
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
        $product->shouldReceive('get_name')->andReturn('Threshold Product');
        $product->shouldReceive('get_sku')->andReturn('TP-001');
        Functions\when('wc_get_product')->justReturn($product);

        $email_sent = false;
        Functions\when('wp_mail')->alias(function () use (&$email_sent) {
            $email_sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_low_stock_notification(1, 'https://store.com', 10);

        $this->assertTrue($email_sent, 'Should send email when stock equals threshold');
    }

    public function test_low_stock_at_zero_sends_email(): void
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
        $product->shouldReceive('get_name')->andReturn('OOS Product');
        $product->shouldReceive('get_sku')->andReturn('OOS-001');
        Functions\when('wc_get_product')->justReturn($product);

        $email_sent = false;
        Functions\when('wp_mail')->alias(function () use (&$email_sent) {
            $email_sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_low_stock_notification(1, 'https://store.com', 0);

        $this->assertTrue($email_sent);
    }

    public function test_low_stock_disabled_does_not_send(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'low_stock_enabled' => false,
                ];
            }
            return $default;
        });

        $email_sent = false;
        Functions\when('wp_mail')->alias(function () use (&$email_sent) {
            $email_sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_low_stock_notification(1, 'https://store.com', 2);

        $this->assertFalse($email_sent);
    }

    // ── send_failed_sync with failed_sync_enabled false ──────────

    public function test_failed_sync_disabled_setting_does_not_send(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'failed_sync_enabled' => false,
                ];
            }
            return $default;
        });

        $email_sent = false;
        Functions\when('wp_mail')->alias(function () use (&$email_sent) {
            $email_sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_failed_sync_notification(1, 'https://store.com', 'Error');

        $this->assertFalse($email_sent);
    }

    // ── send_api_error with api_error_enabled false ──────────────

    public function test_api_error_disabled_setting_does_not_send(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'admin_email') return 'admin@example.com';
            if ($option === 'wc_multi_store_sync_email_settings') {
                return [
                    'enabled' => true,
                    'api_error_enabled' => false,
                ];
            }
            return $default;
        });

        $email_sent = false;
        Functions\when('wp_mail')->alias(function () use (&$email_sent) {
            $email_sent = true;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Connection refused');

        $this->assertFalse($email_sent);
    }

    // ── send_email headers ───────────────────────────────────────

    public function test_email_includes_html_content_type_header(): void
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

        $sent_headers = [];
        Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use (&$sent_headers) {
            $sent_headers = $headers;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Error');

        $this->assertIsArray($sent_headers);
        $has_html_header = false;
        foreach ($sent_headers as $header) {
            if (str_contains($header, 'text/html')) {
                $has_html_header = true;
                break;
            }
        }
        $this->assertTrue($has_html_header, 'Email should include text/html content type');
    }

    public function test_email_from_header_includes_blog_name(): void
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

        $sent_headers = [];
        Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use (&$sent_headers) {
            $sent_headers = $headers;
            return true;
        });

        $notifier = new WC_Multi_Store_Email_Notifications();
        $notifier->send_api_error_notification('https://store.com', 'Error');

        $has_from_header = false;
        foreach ($sent_headers as $header) {
            if (str_starts_with($header, 'From:') && str_contains($header, 'Test Store')) {
                $has_from_header = true;
                break;
            }
        }
        $this->assertTrue($has_from_header, 'Email From header should contain blog name');
    }

    // ── daily summary template ───────────────────────────────────

    public function test_daily_summary_template_with_store_data(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_daily_summary_template');

        $data = [
            'date' => 'January 14, 2024',
            'total_syncs' => 100,
            'successful_syncs' => 90,
            'failed_syncs' => 10,
            'success_rate' => 90.0,
            'avg_duration' => 250,
            'total_api_calls' => 200,
            'syncs_by_store' => [
                ['store_url' => 'https://store1.com', 'total' => 60, 'successful' => 55, 'failed' => 5],
                ['store_url' => 'https://store2.com', 'total' => 40, 'successful' => 35, 'failed' => 5],
            ],
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString('January 14, 2024', $html);
        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('90', $html);
        $this->assertStringContainsString('store1.com', $html);
        $this->assertStringContainsString('store2.com', $html);
        $this->assertStringContainsString('By Store', $html);
    }

    public function test_daily_summary_template_without_store_data(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'get_daily_summary_template');

        $data = [
            'date' => 'January 14, 2024',
            'total_syncs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'success_rate' => 0,
            'avg_duration' => 0,
            'total_api_calls' => 0,
            'syncs_by_store' => [],
        ];

        $html = $method->invoke($notifier, $data);

        $this->assertStringContainsString('Total Syncs', $html);
        $this->assertStringNotContainsString('By Store', $html);
    }

    // ── load_template fallback ───────────────────────────────────

    public function test_load_template_falls_back_to_default_when_file_missing(): void
    {
        $notifier = new WC_Multi_Store_Email_Notifications();
        $method = new ReflectionMethod($notifier, 'load_template');

        $data = [
            'store_url' => 'https://store.com',
            'error_message' => 'Test error',
            'timestamp' => '2024-01-15 12:00:00',
        ];

        // Template file doesn't exist, so it falls back to default
        $html = $method->invoke($notifier, 'api-error', $data);

        $this->assertStringContainsString('API Error Alert', $html);
        $this->assertStringContainsString('Test error', $html);
    }

    // ── update_settings ──────────────────────────────────────────

    public function test_update_settings_saves_all_fields(): void
    {
        $saved_value = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved_value) {
            if ($key === 'wc_multi_store_sync_email_settings') {
                $saved_value = $value;
            }
            return true;
        });

        $settings = [
            'enabled' => true,
            'recipient_email' => 'custom@example.com',
            'failed_sync_enabled' => true,
            'daily_summary_enabled' => true,
            'low_stock_enabled' => true,
            'low_stock_threshold' => 5,
            'api_error_enabled' => false,
            'daily_summary_time' => '10:30',
        ];

        $result = WC_Multi_Store_Email_Notifications::update_settings($settings);

        $this->assertTrue($result);
        $this->assertEquals($settings, $saved_value);
    }
}
