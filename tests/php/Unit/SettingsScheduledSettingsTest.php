<?php
/**
 * Tests for WC_Multi_Store_Settings::get_scheduled_settings().
 */

use Brain\Monkey\Functions;

class SettingsScheduledSettingsTest extends WC_Multi_Store_TestCase
{
    public function test_returns_documented_defaults_when_option_is_unset(): void
    {
        $seen = [];
        Functions\when('get_option')->alias(function ($key, $default = false) use (&$seen) {
            $seen = [$key, $default];
            return $default;
        });

        $settings = WC_Multi_Store_Settings::get_scheduled_settings();

        $this->assertSame('wc_multi_store_sync_scheduled', $seen[0]);
        $this->assertSame([
            'scheduled_sync_enabled'         => true,
            'scheduled_sync_interval'        => '10min',
            'sync_all_products'              => true,
            'sync_modified_hours'            => 24,
            'batch_size_peak'                => 5,
            'batch_size_offpeak'             => 20,
            'scheduled_sync_type'            => 'use_default',
            'scheduled_stock_sync'           => 'use_default',
            'scheduled_category_auto_create' => 'use_default',
        ], $settings);
    }

    public function test_returns_stored_option_verbatim_without_merging_defaults(): void
    {
        $stored = ['scheduled_sync_enabled' => false, 'batch_size_peak' => 50];
        Functions\when('get_option')->justReturn($stored);

        $this->assertSame($stored, WC_Multi_Store_Settings::get_scheduled_settings());
    }

    public function test_reflects_option_changes_between_calls_with_no_internal_caching(): void
    {
        $value = ['scheduled_sync_interval' => 'hourly'];
        Functions\when('get_option')->alias(function () use (&$value) {
            return $value;
        });

        $this->assertSame('hourly', WC_Multi_Store_Settings::get_scheduled_settings()['scheduled_sync_interval']);

        $value = ['scheduled_sync_interval' => 'daily'];
        $this->assertSame('daily', WC_Multi_Store_Settings::get_scheduled_settings()['scheduled_sync_interval']);
    }
}
