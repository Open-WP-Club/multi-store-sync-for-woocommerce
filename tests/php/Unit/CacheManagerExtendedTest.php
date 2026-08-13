<?php
/**
 * Extended unit tests for WC_Multi_Store_Cache_Manager
 *
 * Covers: taxonomy term sorting, update_remote_product_after_sync,
 * cache key consistency.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class CacheManagerExtendedTest extends WC_Multi_Store_TestCase
{
    // ── update_remote_product_after_sync ──────────────────────────

    public function test_update_remote_product_after_sync_success(): void
    {
        $product_data = ['id' => 123, 'name' => 'Updated Product', 'price' => '19.99'];

        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                $product_data,
                WC_Multi_Store_Cache_Manager::REMOTE_PRODUCT_EXPIRATION
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::update_remote_product_after_sync(
            'https://store.com',
            'SKU-001',
            'sku',
            $product_data
        );

        $this->assertTrue($result);
    }

    public function test_update_remote_product_after_sync_empty_search_value(): void
    {
        $result = WC_Multi_Store_Cache_Manager::update_remote_product_after_sync(
            'https://store.com',
            '',
            'sku',
            ['id' => 123]
        );

        $this->assertFalse($result);
    }

    public function test_update_remote_product_after_sync_empty_product_data(): void
    {
        $result = WC_Multi_Store_Cache_Manager::update_remote_product_after_sync(
            'https://store.com',
            'SKU-001',
            'sku',
            []
        );

        $this->assertFalse($result);
    }

    // ── set_taxonomy_terms sorting ───────────────────────────────

    public function test_set_taxonomy_terms_sorts_ids_for_consistent_key(): void
    {
        $terms_data = [['term_id' => 1, 'name' => 'Cat A'], ['term_id' => 2, 'name' => 'Cat B']];

        // Both calls should produce the same cache key regardless of ID order
        $keys = [];
        Functions\when('set_transient')->alias(function ($key, $data, $exp) use (&$keys) {
            $keys[] = $key;
            return true;
        });

        WC_Multi_Store_Cache_Manager::set_taxonomy_terms('product_cat', [3, 1, 2], $terms_data);
        WC_Multi_Store_Cache_Manager::set_taxonomy_terms('product_cat', [2, 3, 1], $terms_data);

        $this->assertEquals($keys[0], $keys[1], 'Cache keys should be identical regardless of term ID order');
    }

    public function test_get_taxonomy_terms_sorts_ids_for_consistent_lookup(): void
    {
        $cached_terms = [['term_id' => 5, 'name' => 'Tag']];

        $keys = [];
        Functions\when('get_transient')->alias(function ($key) use (&$keys, $cached_terms) {
            $keys[] = $key;
            return $cached_terms;
        });

        WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_tag', [5, 3, 1]);
        WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_tag', [1, 3, 5]);

        $this->assertEquals($keys[0], $keys[1], 'Lookup keys should match regardless of term ID order');
    }

    // ── cache key consistency ────────────────────────────────────

    public function test_same_inputs_produce_same_cache_key(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Cache_Manager');
        $method = $reflection->getMethod('build_cache_key');

        $key1 = $method->invoke(null, 'remote_product', ['https://store.com', 'SKU-001', 'sku']);
        $key2 = $method->invoke(null, 'remote_product', ['https://store.com', 'SKU-001', 'sku']);

        $this->assertEquals($key1, $key2);
    }

    public function test_different_match_types_produce_different_keys(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Cache_Manager');
        $method = $reflection->getMethod('build_cache_key');

        $key_sku = $method->invoke(null, 'remote_product', ['https://store.com', 'test-product', 'sku']);
        $key_slug = $method->invoke(null, 'remote_product', ['https://store.com', 'test-product', 'slug']);

        $this->assertNotEquals($key_sku, $key_slug);
    }

    public function test_different_stores_produce_different_keys(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Cache_Manager');
        $method = $reflection->getMethod('build_cache_key');

        $key1 = $method->invoke(null, 'remote_product', ['https://store1.com', 'SKU-001', 'sku']);
        $key2 = $method->invoke(null, 'remote_product', ['https://store2.com', 'SKU-001', 'sku']);

        $this->assertNotEquals($key1, $key2);
    }

    // ── get_remote_product cache miss ────────────────────────────

    public function test_get_remote_product_returns_false_when_not_cached(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(false);

        $result = WC_Multi_Store_Cache_Manager::get_remote_product('https://store.com', 'SKU-999', 'sku');

        $this->assertFalse($result);
    }

    // ── clear_all with wpdb ──────────────────────────────────────

    public function test_clear_all_builds_correct_pattern(): void
    {
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => addcslashes($s, '_%\\'));

        $prepared_query = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($q, $p) use (&$prepared_query) {
            $prepared_query = $q;
            return $q;
        });
        $wpdb->shouldReceive('query')->andReturn(10);

        WC_Multi_Store_Cache_Manager::clear_all();

        // Verify the pattern searches for transient entries with our cache group
        $this->assertStringContainsString('DELETE FROM', $prepared_query);
        $this->assertStringContainsString('LIKE', $prepared_query);
    }

    // ── clear_store_cache with sanitize_key ──────────────────────

    public function test_clear_store_cache_sanitizes_url(): void
    {
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => addcslashes($s, '_%\\'));
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(3);

        $result = WC_Multi_Store_Cache_Manager::clear_store_cache('https://Store-With-CAPS.com');

        $this->assertTrue($result);
    }

    // ── set_product_not_found and negative cache flow ────────────

    public function test_negative_cache_full_flow(): void
    {
        // First: set product as not found
        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER,
                WC_Multi_Store_Cache_Manager::NEGATIVE_CACHE_EXPIRATION
            )
            ->andReturn(true);

        $set_result = WC_Multi_Store_Cache_Manager::set_product_not_found('https://store.com', 'MISSING-SKU', 'sku');
        $this->assertTrue($set_result);

        // Then: check that get_remote_product returns null for NOT_FOUND_MARKER
        Functions\expect('get_transient')
            ->once()
            ->andReturn(WC_Multi_Store_Cache_Manager::NOT_FOUND_MARKER);

        $get_result = WC_Multi_Store_Cache_Manager::get_remote_product('https://store.com', 'MISSING-SKU', 'sku');
        $this->assertNull($get_result);
    }

    // ── remote variations ────────────────────────────────────────

    public function test_set_remote_variations_uses_product_expiration(): void
    {
        $variations = [
            ['id' => 10, 'sku' => 'VAR-S'],
            ['id' => 11, 'sku' => 'VAR-M'],
            ['id' => 12, 'sku' => 'VAR-L'],
        ];

        Functions\expect('set_transient')
            ->once()
            ->with(
                \Mockery::type('string'),
                $variations,
                WC_Multi_Store_Cache_Manager::REMOTE_PRODUCT_EXPIRATION
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Cache_Manager::set_remote_variations('https://store.com', 500, $variations);

        $this->assertTrue($result);
    }

    public function test_get_remote_variations_returns_false_when_not_cached(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(false);

        $result = WC_Multi_Store_Cache_Manager::get_remote_variations('https://store.com', 999);

        $this->assertFalse($result);
    }

    public function test_get_remote_variations_coerces_null_to_false(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(null);

        $result = WC_Multi_Store_Cache_Manager::get_remote_variations('https://store.com', 999);

        $this->assertFalse($result);
    }
}
