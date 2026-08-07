<?php
/**
 * Unit tests for WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher
 *
 * Extracted from WeeklySyncVerifierExtendedTest.php as part of splitting
 * WC_Multi_Store_Weekly_Sync_Verifier — see
 * docs/superpowers/specs/2026-08-07-weekly-verifier-report-repository-design.md
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WeeklyVerificationRemoteDataFetcherTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-remote-data-fetcher.php';
        }

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'match_products_by' => 'sku',
                    'category_match_by' => 'slug',
                ];
            }
            return $default;
        });
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');

        // Reset static API client pool and prefetch batch cache between tests
        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
        $prop = $ref->getProperty('api_client_pool');
        $prop->setValue(null, []);
        $batchCacheProp = $ref->getProperty('batch_cache');
        $batchCacheProp->setValue(null, []);
    }

    // ── build_exclusion_tax_query ────────────────────────────────

    public function test_build_exclusion_tax_query_empty_stores(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'build_exclusion_tax_query');

        $result = $method->invoke(null, []);

        $this->assertEmpty($result);
    }

    public function test_build_exclusion_tax_query_common_exclusions(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'build_exclusion_tax_query');

        $stores = [
            'https://store1.com' => ['exclude_categories' => [5, 10], 'exclude_tags' => [1]],
            'https://store2.com' => ['exclude_categories' => [10, 15], 'exclude_tags' => [1, 2]],
        ];

        $result = $method->invoke(null, $stores);

        // Only category 10 is excluded from ALL stores
        $this->assertNotEmpty($result);
        $found_cat = false;
        foreach ($result as $item) {
            if (is_array($item) && ($item['taxonomy'] ?? '') === 'product_cat') {
                $this->assertContains(10, $item['terms']);
                $this->assertNotContains(5, $item['terms']);
                $this->assertNotContains(15, $item['terms']);
                $found_cat = true;
            }
        }
        $this->assertTrue($found_cat);
    }

    // ── get_products_to_verify sample modes ──────────────────────

    public function test_get_products_to_verify_defaults(): void
    {
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 5]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'get_products_to_verify');

        $settings = [
            'product_limit' => 100,
            'sample_mode' => 'all',
        ];

        $result = $method->invoke(null, $settings);

        // WP_Query mock returns empty posts
        $this->assertIsArray($result);
    }

    // ── scan_ghost_products: streaming + batch local index ────────

    /**
     * Helper: build $wpdb mock that returns the given SKU list as the local SKU set.
     */
    private function mockLocalSkuLookup(array $skus, ?array $slugs = null): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';

        $wpdb->shouldReceive('get_col')
            ->andReturnUsing(function ($sql) use ($skus, $slugs) {
                if (strpos($sql, 'post_name') !== false) {
                    return $slugs ?? [];
                }
                return $skus;
            });
    }

    /**
     * Helper: install a Mockery stub of WC_Multi_Store_API_Client into the fetcher's
     * static pool so the scan uses our stub instead of constructing a real client.
     */
    private function installFakeApiClient(string $store_url, callable $stream): void
    {
        $mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock->shouldReceive('stream_products')->andReturnUsing($stream);

        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $pool = $ref->getProperty('api_client_pool');
        $current = $pool->getValue(null) ?? [];
        $current[$store_url] = $mock;
        $pool->setValue(null, $current);
    }

    public function test_scan_ghost_products_uses_batch_local_sku_query(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';

        // Single get_col() must serve the local SKU set. wc_get_product_id_by_sku
        // (which the OLD code would call per remote product) must never be invoked.
        $wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn(['LOCAL-1', 'LOCAL-2']);

        Functions\expect('wc_get_product_id_by_sku')->never();
        Functions\expect('get_page_by_path')->never();

        $this->installFakeApiClient('https://store1.com', function () {
            return null; // empty stream — we only assert the local lookup batching here
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        $this->assertSame([], $result);
    }

    public function test_scan_ghost_products_streams_pages_and_detects_ghosts(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Local catalogue has LOCAL-A only. Remote returns LOCAL-A, GHOST-B across two pages.
        $this->mockLocalSkuLookup(['LOCAL-A']);

        $pages_consumed = [];
        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) use (&$pages_consumed) {
            // Page 1
            $page1 = [
                ['id' => 1, 'sku' => 'LOCAL-A', 'name' => 'Local A',  'slug' => 'local-a'],
                ['id' => 2, 'sku' => 'GHOST-B', 'name' => 'Ghost B',  'slug' => 'ghost-b'],
            ];
            $pages_consumed[] = 1;
            if ($cb($page1, 1) === false) {
                return null;
            }
            // Page 2 — no new ghosts
            $page2 = [
                ['id' => 3, 'sku' => 'LOCAL-A', 'name' => 'Variant of A', 'slug' => 'variant-a'],
            ];
            $pages_consumed[] = 2;
            $cb($page2, 2);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        $this->assertCount(1, $result);
        $this->assertSame('GHOST-B', $result[0]['remote_sku']);
        $this->assertSame(2, $result[0]['remote_id']);
        $this->assertSame([1, 2], $pages_consumed, 'both pages should have been streamed');
    }

    public function test_scan_ghost_products_truncates_at_cap_and_stops_streaming(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $this->mockLocalSkuLookup([]); // no local products → every remote is a ghost

        $pages_emitted = 0;
        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) use (&$pages_emitted) {
            // Build a page large enough to hit the cap mid-callback. The fake honours the
            // callback's false return — the real paginate_each does the same.
            $page = [];
            for ($i = 0; $i < WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::GHOST_SCAN_MAX_RESULTS + 100; $i++) {
                $page[] = ['id' => $i, 'sku' => "GHOST-$i", 'name' => "Ghost $i", 'slug' => "g-$i"];
            }
            $pages_emitted++;
            if ($cb($page, 1) === false) {
                return null;
            }
            $pages_emitted++;
            $cb([['id' => 99999, 'sku' => 'EXTRA', 'name' => 'Extra', 'slug' => 'extra']], 2);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        // Result must be capped at GHOST_SCAN_MAX_RESULTS regardless of extra pages emitted.
        $this->assertCount(
            WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::GHOST_SCAN_MAX_RESULTS,
            $result
        );
    }

    public function test_scan_ghost_products_uses_slug_set_when_match_by_slug(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Override the setUp's get_option so match_products_by = 'slug'
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['match_products_by' => 'slug'];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';

        // Only the slug query should fire — never the SKU one.
        $wpdb->shouldReceive('get_col')
            ->once()
            ->with(\Mockery::on(fn($sql) => strpos($sql, 'post_name') !== false))
            ->andReturn(['local-slug-a']);

        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) {
            $cb([
                ['id' => 1, 'sku' => 'X', 'slug' => 'local-slug-a', 'name' => 'A'],
                ['id' => 2, 'sku' => 'Y', 'slug' => 'ghost-slug',   'name' => 'Ghost'],
            ], 1);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'scan_ghost_products');

        $result = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            []
        );

        $this->assertCount(1, $result);
        $this->assertSame('Ghost', $result[0]['remote_name']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Batch prefetch: build_remote_index() / load_search_values_for_products()
    // / prefetch_remote_batch_data() — the N×M → batched API call fix.
    // These reuse the same installFakeApiClient()/mockLocalSkuLookup()-style
    // helpers as the ghost-scan tests above, since both features stream a
    // store's catalog via the same stream_products() contract.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Like installFakeApiClient() but also stubs get_products() for the
     * batch-fetch (include=) phase.
     */
    private function installFakeApiClientWithBatchFetch(string $store_url, callable $stream, callable $getProducts): void
    {
        $mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock->shouldReceive('stream_products')->andReturnUsing($stream);
        $mock->shouldReceive('get_products')->andReturnUsing($getProducts);

        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $pool = $ref->getProperty('api_client_pool');
        $current = $pool->getValue(null) ?? [];
        $current[$store_url] = $mock;
        $pool->setValue(null, $current);
    }

    private function resetBatchCache(): void
    {
        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $prop = $ref->getProperty('batch_cache');
        $prop->setValue(null, []);
    }

    public function test_build_remote_index_streams_catalog_into_sku_map(): void
    {
        $this->installFakeApiClient('https://store1.com', function (array $params, callable $cb) {
            $cb([
                ['id' => 10, 'sku' => 'SKU-A', 'slug' => 'a'],
                ['id' => 20, 'sku' => 'SKU-B', 'slug' => 'b'],
            ], 1);
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'build_remote_index');
        $index = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            'sku'
        );

        $this->assertSame(['SKU-A' => 10, 'SKU-B' => 20], $index['https://store1.com']);
    }

    public function test_build_remote_index_omits_store_on_stream_error(): void
    {
        $this->installFakeApiClient('https://store1.com', function () {
            return new WP_Error('http_error', 'timeout');
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'build_remote_index');
        $index = $method->invoke(
            null,
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            'sku'
        );

        // Store must be entirely absent (not an empty array) so
        // prefetch_remote_batch_data()/get_remote_product() fall back to
        // live per-item calls for it, rather than treating every product as
        // confirmed-missing.
        $this->assertArrayNotHasKey('https://store1.com', $index);
    }

    public function test_load_search_values_for_products_returns_empty_for_empty_input(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'load_search_values_for_products');
        $result = $method->invoke(null, [], 'sku');

        $this->assertSame([], $result);
    }

    public function test_load_search_values_for_products_bulk_sku_query(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';

        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                (object) ['post_id' => 42, 'meta_value' => 'SKU-42'],
                (object) ['post_id' => 43, 'meta_value' => 'SKU-43'],
            ]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'load_search_values_for_products');
        $result = $method->invoke(null, [42, 43], 'sku');

        $this->assertSame([42 => 'SKU-42', 43 => 'SKU-43'], $result);
    }

    public function test_prefetch_remote_batch_data_batches_via_include_param(): void
    {
        $this->resetBatchCache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['post_id' => 1, 'meta_value' => 'SKU-1'],
            (object) ['post_id' => 2, 'meta_value' => 'SKU-2'],
        ]);

        $capturedParams = null;
        $this->installFakeApiClientWithBatchFetch(
            'https://store1.com',
            function () { return null; }, // stream_products not used by this method directly
            function ($search, $searchType, $params) use (&$capturedParams) {
                $capturedParams = $params;
                return [
                    ['id' => 100, 'sku' => 'SKU-1', 'stock_quantity' => 5],
                    ['id' => 200, 'sku' => 'SKU-2', 'stock_quantity' => 7],
                ];
            }
        );

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'prefetch_remote_batch_data');
        $method->invoke(
            null,
            [1, 2],
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            ['https://store1.com' => ['SKU-1' => 100, 'SKU-2' => 200]],
            'sku'
        );

        // A single batched call with both remote IDs — not one call per SKU.
        $this->assertSame('100,200', $capturedParams['include']);

        $ref   = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $cache = $ref->getProperty('batch_cache')->getValue(null);

        $this->assertSame(5, $cache['https://store1.com']['SKU-1']['stock_quantity']);
        $this->assertSame(7, $cache['https://store1.com']['SKU-2']['stock_quantity']);
    }

    public function test_prefetch_remote_batch_data_skips_api_call_when_nothing_in_index(): void
    {
        $this->resetBatchCache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['post_id' => 1, 'meta_value' => 'SKU-NOT-REMOTE'],
        ]);

        $mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock->shouldNotReceive('get_products');
        $ref  = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'prefetch_remote_batch_data');
        $method->invoke(
            null,
            [1],
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            ['https://store1.com' => []], // empty index — SKU-NOT-REMOTE isn't on this store
            'sku'
        );

        $cache = $ref->getProperty('batch_cache')->getValue(null);

        // Confirmed-missing without ever calling the API.
        $this->assertNull($cache['https://store1.com']['SKU-NOT-REMOTE']);
    }

    // ── SKU/slug drift: stored remote-ID fallback ────────────────

    public function test_prefetch_remote_batch_data_resolves_via_stored_remote_id_when_search_value_drifted(): void
    {
        $this->resetBatchCache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        // Local product #1's CURRENT sku is NEW-SKU (renamed since the last sync).
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['post_id' => 1, 'meta_value' => 'NEW-SKU'],
        ]);

        // Product #1 has a stored remote ID from its last successful sync.
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => ($post_id === 1 && str_contains($key, '_mss_remote_id_')) ? '999' : ''
        );

        $capturedParams = null;
        $this->installFakeApiClientWithBatchFetch(
            'https://store1.com',
            function () { return null; },
            function ($search, $searchType, $params) use (&$capturedParams) {
                $capturedParams = $params;
                return [
                    ['id' => 999, 'sku' => 'OLD-SKU', 'stock_quantity' => 5],
                ];
            }
        );

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'prefetch_remote_batch_data');
        $method->invoke(
            null,
            [1],
            ['https://store1.com' => ['name' => 'Store 1', 'consumer_key' => 'k', 'consumer_secret' => 's']],
            // Remote index is still keyed by the OLD sku — remote hasn't been
            // renamed (or re-synced) yet either.
            ['https://store1.com' => ['OLD-SKU' => 999]],
            'sku'
        );

        // Must fetch by the stored ID (999) instead of treating it as confirmed-missing.
        $this->assertSame('999', $capturedParams['include']);

        $ref   = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $cache = $ref->getProperty('batch_cache')->getValue(null);

        // Cached under the product's CURRENT search value (NEW-SKU), not the
        // old one, so get_remote_product() (which looks up by current SKU)
        // resolves it instead of reporting the product as missing.
        $this->assertSame(999, $cache['https://store1.com']['NEW-SKU']['id']);
    }

    public function test_get_remote_product_resolves_via_stored_remote_id_when_live_search_would_miss(): void
    {
        $this->resetBatchCache();

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(1);
        $product->shouldReceive('get_sku')->andReturn('NEW-SKU');
        $product->shouldReceive('get_slug')->andReturn('new-slug');

        Functions\when('get_option')->alias(function ($option, $default = false) {
            return $option === 'wc_multi_store_sync_settings' ? ['match_products_by' => 'sku'] : $default;
        });
        Functions\when('get_post_meta')->alias(
            fn($post_id, $key, $single = false) => ($post_id === 1 && str_contains($key, '_mss_remote_id_')) ? '999' : ''
        );

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_product')->with(999)->once()->andReturn([
            'id' => 999, 'sku' => 'OLD-SKU', 'stock_quantity' => 5,
        ]);
        // Must not fall back to search-by-value once the stored ID resolves.
        $mock_api->shouldNotReceive('get_products');

        $ref  = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'get_remote_product');
        $result = $method->invoke(null, $product, 'https://store1.com', ['consumer_key' => 'k', 'consumer_secret' => 's']);

        $this->assertSame(999, $result->id);
    }

    public function test_get_remote_product_uses_prefetched_cache_without_live_call(): void
    {
        $this->resetBatchCache();

        $ref   = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $cache = $ref->getProperty('batch_cache');
        $cache->setValue(null, [
            'https://store1.com' => [
                'SKU-CACHED' => ['id' => 55, 'sku' => 'SKU-CACHED', 'stock_quantity' => 9],
            ],
        ]);

        // If get_remote_product() fell through to a live call instead of the
        // cache, this mock (never installed in the pool) would be missing
        // entirely and the real client constructor would run against fake
        // credentials — so reaching a clean return here proves the cache hit.
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, []);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU-CACHED');
        $product->shouldReceive('get_slug')->andReturn('sku-cached');

        Functions\when('get_option')->alias(function ($option, $default = false) {
            return $option === 'wc_multi_store_sync_settings' ? ['match_products_by' => 'sku'] : $default;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class, 'get_remote_product');
        $result = $method->invoke(null, $product, 'https://store1.com', ['consumer_key' => 'k', 'consumer_secret' => 's']);

        $this->assertSame(55, $result->id);
        $this->assertSame(9, $result->stock_quantity);
    }
}
