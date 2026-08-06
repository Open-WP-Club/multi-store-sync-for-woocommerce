<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

class ConfigManagerTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
    }

    // ─── export ────────────────────────────────────

    public function test_export_returns_required_keys(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $config = WC_Multi_Store_Config_Manager::export(false);

        $this->assertArrayHasKey('plugin_version', $config);
        $this->assertArrayHasKey('exported_at', $config);
        $this->assertArrayHasKey('settings', $config);
        $this->assertArrayHasKey('scheduled_settings', $config);
        $this->assertArrayHasKey('stores', $config);
        $this->assertArrayHasKey('email_settings', $config);
        $this->assertArrayHasKey('webhook_settings', $config);
        $this->assertArrayHasKey('weekly_verification', $config);
        $this->assertArrayHasKey('order_settings', $config);
    }

    public function test_export_redacts_api_keys_by_default(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = []) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    'https://shop2.example.com' => [
                        'consumer_key' => 'ck_secret123',
                        'consumer_secret' => 'cs_secret456',
                        'status' => 'active',
                    ],
                ];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $config = WC_Multi_Store_Config_Manager::export(false);

        $store = $config['stores']['https://shop2.example.com'];
        $this->assertEquals('***REDACTED***', $store['consumer_key']);
        $this->assertEquals('***REDACTED***', $store['consumer_secret']);
    }

    public function test_export_includes_api_keys_when_requested(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = []) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    'https://shop2.example.com' => [
                        'consumer_key' => 'ck_secret123',
                        'consumer_secret' => 'cs_secret456',
                        'status' => 'active',
                    ],
                ];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $config = WC_Multi_Store_Config_Manager::export(true);

        $store = $config['stores']['https://shop2.example.com'];
        $this->assertEquals('ck_secret123', $store['consumer_key']);
        $this->assertEquals('cs_secret456', $store['consumer_secret']);
    }

    public function test_export_redacts_webhook_secret(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = []) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => 'my_secret_key'];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $config = WC_Multi_Store_Config_Manager::export(false);

        $this->assertEquals('***REDACTED***', $config['webhook_settings']['webhook_secret']);
    }

    public function test_export_has_plugin_version(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $config = WC_Multi_Store_Config_Manager::export(false);

        $this->assertEquals(WC_MSS_VERSION, $config['plugin_version']);
    }

    // ─── import ────────────────────────────────────

    public function test_import_requires_plugin_version(): void
    {
        $result = WC_Multi_Store_Config_Manager::import([]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_config', $result->get_error_code());
    }

    public function test_import_updates_general_settings(): void
    {
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $config = [
            'plugin_version' => '3.1.0',
            'settings' => [
                'enabled' => true,
                'sync_type_default' => 'quantity',
            ],
        ];

        $result = WC_Multi_Store_Config_Manager::import($config);

        $this->assertTrue($result);
    }

    public function test_import_skips_redacted_store_without_existing(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $config = [
            'plugin_version' => '3.1.0',
            'stores' => [
                'https://new-store.example.com' => [
                    'consumer_key' => '***REDACTED***',
                    'consumer_secret' => '***REDACTED***',
                    'status' => 'active',
                ],
            ],
        ];

        $result = WC_Multi_Store_Config_Manager::import($config);
        $this->assertTrue($result);
    }

    public function test_import_handles_all_config_sections(): void
    {
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_generate_password')->justReturn('random_secret');

        $config = [
            'plugin_version' => '3.1.0',
            'settings' => ['enabled' => true],
            'scheduled_settings' => ['scheduled_sync_enabled' => true],
            'email_settings' => ['enabled' => false],
            'webhook_settings' => ['enabled' => false, 'webhook_secret' => '***REDACTED***'],
            'weekly_verification' => ['enabled' => false],
            'order_settings' => ['auto_sync_enabled' => false],
        ];

        $result = WC_Multi_Store_Config_Manager::import($config);
        $this->assertTrue($result);
    }

    public function test_import_empty_settings_section_is_skipped(): void
    {
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $config = [
            'plugin_version' => '3.1.0',
            // No settings sections - should still succeed
        ];

        $result = WC_Multi_Store_Config_Manager::import($config);
        $this->assertTrue($result);
    }
}
