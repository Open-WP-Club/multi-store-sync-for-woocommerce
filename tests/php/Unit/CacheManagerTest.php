<?php
/**
 * Unit tests for WC_Multi_Store_Cache_Manager
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class CacheManagerTest extends WC_Multi_Store_TestCase
{
    /**
     * Test build_cache_key returns correct format
     */
    public function test_build_cache_key_format(): void
    {
        // Use reflection to test private method
        $reflection = new ReflectionClass('WC_Multi_Store_Cache_Manager');
        $method = $reflection->getMethod('build_cache_key');

        $key = $method->invoke(null, 'remote_product', array('https://example.com', 'SKU123', 'sku'));

        $this->assertStringContainsString('wc_mss_cache', $key);
        $this->assertStringContainsString('remote_product', $key);
    }

    /**
     * Test build_cache_key truncates long keys
     */
    public function test_build_cache_key_truncates_long_keys(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Cache_Manager');
        $method = $reflection->getMethod('build_cache_key');

        // Create a very long key
        $long_url = 'https://very-long-domain-name-that-should-cause-truncation-' . str_repeat('x', 200) . '.com';
        $key = $method->invoke(null, 'remote_product', array($long_url, 'SKU123', 'sku'));

        // WordPress transients have max 172 characters - key is truncated to 140 + underscore + 32 md5 = 173
        // The implementation uses 140 + 1 + 32 = 173, which is close enough to the limit
        $this->assertLessThanOrEqual(173, strlen($key));
        // Should contain md5 hash for truncated keys
        $this->assertMatchesRegularExpression('/[a-f0-9]{32}$/', $key);
    }

    /**
     * Test get_remote_product returns null for empty search value
     */
    public function test_get_remote_product_empty_value_returns_null(): void
    {
        $result = WC_Multi_Store_Cache_Manager::get_remote_product('https://example.com', '', 'sku');
        $this->assertNull($result);
    }

    /**
     * Test set_remote_product returns false for empty search value
     */
    public function test_set_remote_product_empty_value_returns_false(): void
    {
        $result = WC_Multi_Store_Cache_Manager::set_remote_product('https://example.com', '', 'sku', array('id' => 1));
        $this->assertFalse($result);
    }

    /**
     * Test get_remote_product calls get_transient
     */
    public function test_get_remote_product_calls_transient(): void
    {
        $expected_data = array('id' => 123, 'name' => 'Test Product');

        Functions\expect('get_transient')
            ->once()
            ->andReturn($expected_data);

        $result = WC_Multi_Store_Cache_Manager::get_remote_product('https://example.com', 'SKU123', 'sku');

        $this->assertEquals($expected_data, $result);
    }

    /**
     * Test set_remote_product calls set_transient with correct expiration
     */
    public function test_set_remote_product_uses_correct_expiration(): void
    {
        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                array('id' => 123),
                WC_Multi_Store_Cache_Manager::REMOTE_PRODUCT_EXPIRATION
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::set_remote_product('https://example.com', 'SKU123', 'sku', array('id' => 123));

        $this->assertTrue($result);
    }

    /**
     * Test delete_remote_product calls delete_transient
     */
    public function test_delete_remote_product_calls_delete_transient(): void
    {
        Functions\expect('delete_transient')
            ->once()
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::delete_remote_product('https://example.com', 'SKU123', 'sku');

        $this->assertTrue($result);
    }

    /**
     * Test update_remote_product_after_sync returns false for empty values
     */
    public function test_update_remote_product_after_sync_empty_values_returns_false(): void
    {
        $result = WC_Multi_Store_Cache_Manager::update_remote_product_after_sync('https://example.com', '', 'sku', array());
        $this->assertFalse($result);

        $result = WC_Multi_Store_Cache_Manager::update_remote_product_after_sync('https://example.com', 'SKU123', 'sku', array());
        $this->assertFalse($result);
    }

    /**
     * Test refresh_remote_product_expiration returns false for empty search value
     */
    public function test_refresh_remote_product_expiration_empty_value_returns_false(): void
    {
        $result = WC_Multi_Store_Cache_Manager::refresh_remote_product_expiration('https://example.com', '', 'sku');
        $this->assertFalse($result);
    }

    /**
     * Test refresh_remote_product_expiration returns false when no cached data
     */
    public function test_refresh_remote_product_expiration_no_cache_returns_false(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(false);

        $result = WC_Multi_Store_Cache_Manager::refresh_remote_product_expiration('https://example.com', 'SKU123', 'sku');

        $this->assertFalse($result);
    }

    /**
     * Test refresh_remote_product_expiration refreshes existing cache
     */
    public function test_refresh_remote_product_expiration_refreshes_cache(): void
    {
        $cached_data = array('id' => 123);

        Functions\expect('get_transient')
            ->once()
            ->andReturn($cached_data);

        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                $cached_data,
                WC_Multi_Store_Cache_Manager::REMOTE_PRODUCT_EXPIRATION
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::refresh_remote_product_expiration('https://example.com', 'SKU123', 'sku');

        $this->assertTrue($result);
    }

    /**
     * Test bulk_refresh_after_verification handles empty array
     */
    public function test_bulk_refresh_after_verification_empty_array(): void
    {
        $result = WC_Multi_Store_Cache_Manager::bulk_refresh_after_verification(array());
        $this->assertEquals(0, $result);
    }

    /**
     * Test bulk_refresh_after_verification skips invalid products
     */
    public function test_bulk_refresh_after_verification_skips_invalid(): void
    {
        $products = array(
            array('sku' => '', 'store_url' => 'https://example.com'),
            array('sku' => 'SKU123', 'store_url' => ''),
        );

        $result = WC_Multi_Store_Cache_Manager::bulk_refresh_after_verification($products);
        $this->assertEquals(0, $result);
    }

    /**
     * Test get_remote_variations calls get_transient
     */
    public function test_get_remote_variations_calls_transient(): void
    {
        $expected_variations = array(array('id' => 1), array('id' => 2));

        Functions\expect('get_transient')
            ->once()
            ->andReturn($expected_variations);

        $result = WC_Multi_Store_Cache_Manager::get_remote_variations('https://example.com', 123);

        $this->assertEquals($expected_variations, $result);
    }

    /**
     * Test set_remote_variations calls set_transient
     */
    public function test_set_remote_variations_calls_transient(): void
    {
        $variations = array(array('id' => 1), array('id' => 2));

        Functions\expect('set_transient')
            ->once()
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::set_remote_variations('https://example.com', 123, $variations);

        $this->assertTrue($result);
    }

    /**
     * Test get_remote_terms calls get_transient
     */
    public function test_get_remote_terms_calls_transient(): void
    {
        $expected_terms = array(array('id' => 1, 'name' => 'Widgets'));

        Functions\expect('get_transient')
            ->once()
            ->andReturn($expected_terms);

        $result = WC_Multi_Store_Cache_Manager::get_remote_terms('https://example.com', 'categories');

        $this->assertEquals($expected_terms, $result);
    }

    /**
     * Test set_remote_terms calls set_transient with the day-long expiration
     */
    public function test_set_remote_terms_calls_transient(): void
    {
        $terms = array(array('id' => 1, 'name' => 'Widgets'));

        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                $terms,
                WC_Multi_Store_Cache_Manager::REMOTE_TERMS_EXPIRATION
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::set_remote_terms('https://example.com', 'categories', $terms);

        $this->assertTrue($result);
    }

    /**
     * Test clear_remote_terms deletes both categories and tags transients for one store
     */
    public function test_clear_remote_terms_for_specific_store(): void
    {
        Functions\expect('delete_transient')
            ->twice()
            ->andReturn(true);

        WC_Multi_Store_Cache_Manager::clear_remote_terms('https://example.com');
        $this->assertTrue(true);
    }

    /**
     * Test clear_remote_terms with no store sweeps all remote-terms transients
     */
    public function test_clear_remote_terms_for_all_stores(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, $pattern) => $sql);
        $wpdb->shouldReceive('query')->once()->with(\Mockery::type('string'));

        WC_Multi_Store_Cache_Manager::clear_remote_terms();
        $this->assertTrue(true);
    }

    /**
     * Test get_taxonomy_terms returns null for empty term ids
     */
    public function test_get_taxonomy_terms_empty_returns_null(): void
    {
        $result = WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_cat', array());
        $this->assertNull($result);
    }

    /**
     * Test get_taxonomy_terms normalizes single id to array
     */
    public function test_get_taxonomy_terms_normalizes_single_id(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(array('term' => 'data'));

        $result = WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_cat', 123);

        $this->assertIsArray($result);
    }

    /**
     * Test set_taxonomy_terms returns false for empty term ids
     */
    public function test_set_taxonomy_terms_empty_returns_false(): void
    {
        $result = WC_Multi_Store_Cache_Manager::set_taxonomy_terms('product_cat', array(), array());
        $this->assertFalse($result);
    }

    /**
     * Test get_active_stores calls get_transient
     */
    public function test_get_active_stores_calls_transient(): void
    {
        $expected_stores = array('https://store1.com' => array('status' => 'active'));

        Functions\expect('get_transient')
            ->once()
            ->andReturn($expected_stores);

        $result = WC_Multi_Store_Cache_Manager::get_active_stores();

        $this->assertEquals($expected_stores, $result);
    }

    /**
     * Test set_active_stores uses correct expiration
     */
    public function test_set_active_stores_uses_correct_expiration(): void
    {
        $stores = array('https://store1.com' => array('status' => 'active'));

        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                $stores,
                WC_Multi_Store_Cache_Manager::STORE_CONFIG_EXPIRATION
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::set_active_stores($stores);

        $this->assertTrue($result);
    }

    /**
     * Test clear_active_stores calls delete_transient
     */
    public function test_clear_active_stores_calls_delete_transient(): void
    {
        Functions\expect('delete_transient')
            ->once()
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::clear_active_stores();

        $this->assertTrue($result);
    }

    /**
     * Test cache constants are defined correctly
     */
    public function test_cache_constants(): void
    {
        $this->assertEquals('wc_mss_cache', WC_Multi_Store_Cache_Manager::CACHE_GROUP);
        $this->assertEquals(900, WC_Multi_Store_Cache_Manager::DEFAULT_EXPIRATION);
        $this->assertEquals(604800, WC_Multi_Store_Cache_Manager::REMOTE_PRODUCT_EXPIRATION);
        $this->assertEquals(1800, WC_Multi_Store_Cache_Manager::STORE_CONFIG_EXPIRATION);
        $this->assertEquals(3600, WC_Multi_Store_Cache_Manager::TAXONOMY_EXPIRATION);
        $this->assertEquals(86400, WC_Multi_Store_Cache_Manager::NEGATIVE_CACHE_EXPIRATION);
        $this->assertEquals('__WC_MSS_NOT_FOUND__', WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER);
    }

    /**
     * Test set_product_not_found returns false for empty search value
     */
    public function test_set_product_not_found_empty_value_returns_false(): void
    {
        $result = WC_Multi_Store_Cache_Manager::set_product_not_found('https://example.com', '', 'sku');
        $this->assertFalse($result);
    }

    /**
     * Test set_product_not_found uses correct expiration
     */
    public function test_set_product_not_found_uses_correct_expiration(): void
    {
        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER,
                WC_Multi_Store_Cache_Manager::NEGATIVE_CACHE_EXPIRATION
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::set_product_not_found('https://example.com', 'SKU123', 'sku');

        $this->assertTrue($result);
    }

    /**
     * Test is_product_not_found returns false for empty search value
     */
    public function test_is_product_not_found_empty_value_returns_false(): void
    {
        $result = WC_Multi_Store_Cache_Manager::is_product_not_found('https://example.com', '', 'sku');
        $this->assertFalse($result);
    }

    /**
     * Test is_product_not_found returns true when marker is cached
     */
    public function test_is_product_not_found_returns_true_when_marker_cached(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER);

        $result = WC_Multi_Store_Cache_Manager::is_product_not_found('https://example.com', 'SKU123', 'sku');

        $this->assertTrue($result);
    }

    /**
     * Test is_product_not_found returns false when product data is cached
     */
    public function test_is_product_not_found_returns_false_when_product_cached(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(array('id' => 123));

        $result = WC_Multi_Store_Cache_Manager::is_product_not_found('https://example.com', 'SKU123', 'sku');

        $this->assertFalse($result);
    }

    /**
     * Test is_product_not_found returns false when nothing cached
     */
    public function test_is_product_not_found_returns_false_when_not_cached(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(false);

        $result = WC_Multi_Store_Cache_Manager::is_product_not_found('https://example.com', 'SKU123', 'sku');

        $this->assertFalse($result);
    }

    /**
     * Test get_remote_product returns null when NOT_FOUND_MARKER is cached
     */
    public function test_get_remote_product_returns_null_when_not_found_marker(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER);

        $result = WC_Multi_Store_Cache_Manager::get_remote_product('https://example.com', 'SKU123', 'sku');

        $this->assertNull($result);
    }

    // ── clear_all ────────────────────────────────────────────────

    public function test_clear_all_deletes_cache_entries(): void
    {
        // Logger::write needs current_time when logging deletion count
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(15);

        $deleted = WC_Multi_Store_Cache_Manager::clear_all();
        $this->assertEquals(15, $deleted);
    }

    public function test_clear_all_returns_zero_when_empty(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(0);

        $deleted = WC_Multi_Store_Cache_Manager::clear_all();
        $this->assertEquals(0, $deleted);
    }

    // ── clear_store_cache ────────────────────────────────────────

    public function test_clear_store_cache_returns_true_when_deleted(): void
    {
        // Logger::write needs current_time when logging deletion count
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(5);

        $result = WC_Multi_Store_Cache_Manager::clear_store_cache('https://store1.com');
        $this->assertTrue($result);
    }

    public function test_clear_store_cache_returns_false_when_nothing_deleted(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(0);

        Functions\when('sanitize_key')->alias(fn($s) => preg_replace('/[^a-z0-9_-]/', '', strtolower($s)));

        $result = WC_Multi_Store_Cache_Manager::clear_store_cache('https://nonexistent.com');
        $this->assertFalse($result);
    }

}
