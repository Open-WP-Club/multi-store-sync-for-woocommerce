<?php
/**
 * Unit tests for WC_Multi_Store_Settings
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SettingsTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear static cache before each test
        WC_Multi_Store_Settings::clear_static_cache();
    }

    /**
     * Test get_settings returns defaults when no settings saved
     */
    public function test_get_settings_returns_defaults(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $settings = WC_Multi_Store_Settings::get_settings();

        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('sync_type_default', $settings);
        $this->assertArrayHasKey('auth_method', $settings);
        $this->assertArrayHasKey('match_products_by', $settings);

        // Check default values
        $this->assertFalse($settings['enabled']);
        $this->assertEquals('full_product', $settings['sync_type_default']);
        $this->assertEquals('basic_auth', $settings['auth_method']);
        $this->assertEquals('sku', $settings['match_products_by']);
    }

    /**
     * Test get_settings merges saved settings with defaults
     */
    public function test_get_settings_merges_with_defaults(): void
    {
        $saved_settings = array(
            'enabled' => true,
            'custom_setting' => 'custom_value',
        );

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn($saved_settings);

        $settings = WC_Multi_Store_Settings::get_settings();

        $this->assertTrue($settings['enabled']);
        $this->assertEquals('custom_value', $settings['custom_setting']);
        // Should still have default values for unset keys
        $this->assertEquals('full_product', $settings['sync_type_default']);
    }

    /**
     * Test get_settings uses the in-memory static cache on repeat calls
     * within the same request — there's no transient/object-cache layer on
     * top of get_option() (see settings.php::get_settings() docblock).
     */
    public function test_get_settings_uses_cache(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array('enabled' => true, 'cached' => true));

        $first = WC_Multi_Store_Settings::get_settings();
        $second = WC_Multi_Store_Settings::get_settings();

        $this->assertTrue($first['cached']);
        $this->assertEquals($first, $second);
    }

    /**
     * Test get returns specific setting value
     */
    public function test_get_returns_specific_setting(): void
    {
        Functions\when('get_option')->justReturn(array('enabled' => true));

        $result = WC_Multi_Store_Settings::get('enabled');

        $this->assertTrue($result);
    }

    /**
     * Test get returns default when setting not found
     */
    public function test_get_returns_default_when_not_found(): void
    {
        Functions\when('get_option')->justReturn(array());

        $result = WC_Multi_Store_Settings::get('non_existent', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    /**
     * Test update modifies specific setting
     */
    public function test_update_modifies_setting(): void
    {
        $existing_settings = array('enabled' => false, 'other' => 'value');

        Functions\when('get_option')->justReturn($existing_settings);

        Functions\expect('update_option')
            ->once()
            ->with('wc_multi_store_sync_settings', \Mockery::on(function ($settings) {
                return $settings['enabled'] === true && $settings['other'] === 'value';
            }))
            ->andReturn(true);

        $result = WC_Multi_Store_Settings::update('enabled', true);

        $this->assertTrue($result);
    }

    /**
     * Test update_all replaces all settings
     */
    public function test_update_all_replaces_settings(): void
    {
        $new_settings = array('enabled' => true, 'new_setting' => 'new_value');

        Functions\expect('update_option')
            ->once()
            ->with('wc_multi_store_sync_settings', $new_settings)
            ->andReturn(true);

        $result = WC_Multi_Store_Settings::update_all($new_settings);

        $this->assertTrue($result);
    }

    /**
     * Test get_stores returns stored stores
     */
    public function test_get_stores_returns_stores(): void
    {
        $stores = array(
            'https://store1.com' => array('status' => 'active'),
            'https://store2.com' => array('status' => 'inactive'),
        );

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', array())
            ->andReturn($stores);

        $result = WC_Multi_Store_Settings::get_stores();

        $this->assertEquals($stores, $result);
    }

    /**
     * Test get_store returns specific store config
     */
    public function test_get_store_returns_specific_store(): void
    {
        $stores = array(
            'https://store1.com' => array('status' => 'active', 'name' => 'Store 1'),
            'https://store2.com' => array('status' => 'inactive', 'name' => 'Store 2'),
        );

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', array())
            ->andReturn($stores);

        $result = WC_Multi_Store_Settings::get_store('https://store1.com');

        $this->assertEquals('Store 1', $result['name']);
        $this->assertEquals('active', $result['status']);
    }

    /**
     * Test get_store returns null for non-existent store
     */
    public function test_get_store_returns_null_for_missing(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', array())
            ->andReturn(array());

        $result = WC_Multi_Store_Settings::get_store('https://nonexistent.com');

        $this->assertNull($result);
    }

    /**
     * Test validate_store_url returns error for invalid URL
     */
    public function test_validate_store_url_invalid_url(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->with('not-a-url')
            ->andReturn('');

        $result = WC_Multi_Store_Settings::validate_store_url('not-a-url');

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_url', $result->get_error_code());
    }

    /**
     * Test validate_store_url returns error for HTTP URL on non-local
     */
    public function test_validate_store_url_insecure_non_local(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->with('http://example.com')
            ->andReturn('http://example.com');

        Functions\expect('wp_parse_url')
            ->once()
            ->with('http://example.com')
            ->andReturn(array('scheme' => 'http', 'host' => 'example.com'));

        $result = WC_Multi_Store_Settings::validate_store_url('http://example.com');

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('insecure_url', $result->get_error_code());
    }

    /**
     * Test validate_store_url allows HTTP for localhost
     */
    public function test_validate_store_url_allows_localhost_http(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->with('http://localhost/shop')
            ->andReturn('http://localhost/shop');

        Functions\expect('wp_parse_url')
            ->once()
            ->with('http://localhost/shop')
            ->andReturn(array('scheme' => 'http', 'host' => 'localhost'));

        $result = WC_Multi_Store_Settings::validate_store_url('http://localhost/shop');

        $this->assertFalse(is_wp_error($result));
        $this->assertEquals('http://localhost/shop', $result);
    }

    /**
     * Test validate_store_url allows HTTP for .local domains
     */
    public function test_validate_store_url_allows_local_domain(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->with('http://myshop.local')
            ->andReturn('http://myshop.local');

        Functions\expect('wp_parse_url')
            ->once()
            ->with('http://myshop.local')
            ->andReturn(array('scheme' => 'http', 'host' => 'myshop.local'));

        $result = WC_Multi_Store_Settings::validate_store_url('http://myshop.local');

        $this->assertFalse(is_wp_error($result));
    }

    /**
     * Test validate_store_url removes trailing slash
     */
    public function test_validate_store_url_removes_trailing_slash(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->with('https://example.com/')
            ->andReturn('https://example.com/');

        Functions\expect('wp_parse_url')
            ->once()
            ->with('https://example.com/')
            ->andReturn(array('scheme' => 'https', 'host' => 'example.com'));

        $result = WC_Multi_Store_Settings::validate_store_url('https://example.com/');

        $this->assertEquals('https://example.com', $result);
    }

    /**
     * Test get_active_stores returns only active stores
     */
    public function test_get_active_stores_filters_active(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $stores = array(
            'https://store1.com' => array('status' => 'active'),
            'https://store2.com' => array('status' => 'inactive'),
            'https://store3.com' => array('status' => 'active'),
        );

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', array())
            ->andReturn($stores);

        $result = WC_Multi_Store_Settings::get_active_stores();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('https://store1.com', $result);
        $this->assertArrayHasKey('https://store3.com', $result);
        $this->assertArrayNotHasKey('https://store2.com', $result);
    }

    /**
     * Test get_active_stores uses the in-memory static cache on repeat calls
     */
    public function test_get_active_stores_uses_cache(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $stores = array('https://cached.com' => array('status' => 'active'));

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', array())
            ->andReturn($stores);

        $first = WC_Multi_Store_Settings::get_active_stores();
        $second = WC_Multi_Store_Settings::get_active_stores();

        $this->assertEquals($first, $second);
        $this->assertArrayHasKey('https://cached.com', $second);
    }

    /**
     * Test get_effective_settings returns base settings for unknown source
     */
    public function test_get_effective_settings_unknown_source(): void
    {
        $base_settings = array('enabled' => true, 'category_auto_create' => true);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn($base_settings);

        $result = WC_Multi_Store_Settings::get_effective_settings('unknown_source');

        $this->assertEquals(true, $result['category_auto_create']);
    }

    /**
     * Test get_effective_settings applies scheduled sync overrides
     */
    public function test_get_effective_settings_scheduled_sync(): void
    {
        $base_settings = array('category_auto_create' => true, 'stock_sync_enabled' => true);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn($base_settings);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_scheduled', array())
            ->andReturn(array(
                'scheduled_category_auto_create' => 'disabled',
                'scheduled_stock_sync' => 'disabled',
            ));

        $result = WC_Multi_Store_Settings::get_effective_settings('scheduled_sync');

        $this->assertFalse($result['category_auto_create']);
        $this->assertFalse($result['stock_sync_enabled']);
    }

    /**
     * Test get_effective_settings scheduled sync uses default when 'use_default'
     */
    public function test_get_effective_settings_scheduled_uses_default(): void
    {
        $base_settings = array('category_auto_create' => true, 'stock_sync_enabled' => false);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn($base_settings);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_scheduled', array())
            ->andReturn(array(
                'scheduled_category_auto_create' => 'use_default',
                'scheduled_stock_sync' => 'use_default',
            ));

        $result = WC_Multi_Store_Settings::get_effective_settings('scheduled_sync');

        // Should keep base settings
        $this->assertTrue($result['category_auto_create']);
        $this->assertFalse($result['stock_sync_enabled']);
    }

    /**
     * Test get_effective_settings applies weekly verification overrides
     */
    public function test_get_effective_settings_weekly_verification(): void
    {
        $base_settings = array('category_auto_create' => false, 'stock_sync_enabled' => false);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn($base_settings);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_weekly_verification', array())
            ->andReturn(array(
                'weekly_category_auto_create' => 'enabled',
                'weekly_stock_sync' => 'enabled',
            ));

        $result = WC_Multi_Store_Settings::get_effective_settings('weekly_verification_correction');

        $this->assertTrue($result['category_auto_create']);
        $this->assertTrue($result['stock_sync_enabled']);
    }

    /**
     * Test delete_store returns false for non-existent store
     */
    public function test_delete_store_non_existent(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', array())
            ->andReturn(array());

        $result = WC_Multi_Store_Settings::delete_store('https://nonexistent.com');

        $this->assertFalse($result);
    }

    /**
     * Test that update_store invalidates the static active_stores cache.
     *
     * Regression: previously update_store cleared the transient but left
     * $active_stores_cache populated, so get_active_stores() within the same
     * request would return stale credentials after a key rotation.
     */
    public function test_update_store_invalidates_static_cache(): void
    {
        $old_stores = [
            'https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck_old'],
        ];
        $new_stores = [
            'https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck_new'],
        ];

        // Prime the static cache with old data
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn($old_stores);

        $before = WC_Multi_Store_Settings::get_active_stores();
        $this->assertEquals('ck_old', $before['https://store1.com']['consumer_key']);

        // Now update the store credentials
        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn('https://store1.com');
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'store1.com']);
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn($old_stores);
        Functions\expect('update_option')
            ->once()
            ->with('wc_multi_store_sync_stores', \Mockery::type('array'))
            ->andReturn(true);
        Functions\expect('delete_transient')->zeroOrMoreTimes()->andReturn(true);
        Functions\expect('current_time')->zeroOrMoreTimes()->andReturn('2024-01-01 00:00:00');

        WC_Multi_Store_Settings::update_store('https://store1.com', ['status' => 'active', 'consumer_key' => 'ck_new']);

        // After update, get_active_stores must NOT return the stale cached value
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn($new_stores);

        $after = WC_Multi_Store_Settings::get_active_stores();
        $this->assertEquals('ck_new', $after['https://store1.com']['consumer_key']);
    }

    /**
     * Test that delete_store invalidates the static active_stores cache.
     */
    public function test_delete_store_invalidates_static_cache(): void
    {
        $stores = [
            'https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck_one'],
        ];

        // Prime the static cache
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn($stores);

        $before = WC_Multi_Store_Settings::get_active_stores();
        $this->assertArrayHasKey('https://store1.com', $before);

        // Delete the store
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn($stores);
        Functions\expect('update_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn(true);
        Functions\expect('delete_transient')->zeroOrMoreTimes()->andReturn(true);
        Functions\expect('current_time')->zeroOrMoreTimes()->andReturn('2024-01-01 00:00:00');

        WC_Multi_Store_Settings::delete_store('https://store1.com');

        // Static cache must be cleared — next call should hit DB
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn([]);

        $after = WC_Multi_Store_Settings::get_active_stores();
        $this->assertArrayNotHasKey('https://store1.com', $after);
    }
}
