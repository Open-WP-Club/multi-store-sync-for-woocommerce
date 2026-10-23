<?php
/**
 * Resolution/lookup tests for WC_Multi_Store_Coupon_Sync private helpers:
 * get_coupon_settings, get_skus_for_product_ids, get_slugs_for_category_ids,
 * resolve_skus_to_remote_ids, resolve_category_slugs_to_remote_ids and
 * resolve_via_cached_map.
 */

use Brain\Monkey\Functions;

class CouponSyncResolveTest extends WC_Multi_Store_TestCase
{
    private const STORE = 'https://store-a.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
    }

    private function sync(): WC_Multi_Store_Coupon_Sync
    {
        // Skip the constructor: it reads settings and registers hooks.
        return (new ReflectionClass(WC_Multi_Store_Coupon_Sync::class))->newInstanceWithoutConstructor();
    }

    /** Invoke a private/static method regardless of visibility. */
    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Coupon_Sync::class, $method);
        return $ref->invoke($ref->isStatic() ? null : $this->sync(), ...$args);
    }

    private function client(): \Mockery\MockInterface
    {
        return \Mockery::mock(WC_Multi_Store_API_Client::class);
    }

    private function product(string $sku): object
    {
        return new class ($sku) {
            public function __construct(private string $sku) {}
            public function get_sku(): string { return $this->sku; }
        };
    }

    // ─── get_coupon_settings() ──────────────────────────────────────────────

    public function test_get_coupon_settings_defaults_to_disabled(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_coupon_settings', ['enabled' => false])
            ->andReturnUsing(fn($key, $default) => $default);

        $this->assertSame(['enabled' => false], $this->call('get_coupon_settings'));
    }

    public function test_get_coupon_settings_returns_stored_value(): void
    {
        Functions\when('get_option')->justReturn(['enabled' => true]);

        $this->assertSame(['enabled' => true], $this->call('get_coupon_settings'));
    }

    // ─── get_skus_for_product_ids() ─────────────────────────────────────────

    public function test_get_skus_returns_empty_without_querying_for_no_ids(): void
    {
        Functions\expect('wc_get_products')->never();

        $this->assertSame([], $this->call('get_skus_for_product_ids', []));
    }

    public function test_get_skus_queries_all_ids_in_one_batch_and_returns_skus(): void
    {
        Functions\expect('wc_get_products')
            ->once()
            ->with(['include' => [5, 6], 'limit' => -1, 'return' => 'objects'])
            ->andReturn([$this->product('SKU-5'), $this->product('SKU-6')]);

        $this->assertSame(['SKU-5', 'SKU-6'], $this->call('get_skus_for_product_ids', [5, 6]));
    }

    public function test_get_skus_throws_when_a_product_has_no_sku(): void
    {
        Functions\when('wc_get_products')->justReturn([$this->product('SKU-5'), $this->product('')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve every coupon product restriction to a SKU.');
        $this->call('get_skus_for_product_ids', [5, 6]);
    }

    public function test_get_skus_throws_when_a_product_is_missing_locally(): void
    {
        Functions\when('wc_get_products')->justReturn([$this->product('SKU-5')]);

        $this->expectException(\RuntimeException::class);
        $this->call('get_skus_for_product_ids', [5, 999]);
    }

    public function test_get_skus_duplicate_ids_count_once(): void
    {
        Functions\when('wc_get_products')->justReturn([$this->product('SKU-5')]);

        $this->assertSame(['SKU-5'], $this->call('get_skus_for_product_ids', [5, 5, 5]));
    }

    public function test_get_skus_throws_when_nothing_found(): void
    {
        Functions\when('wc_get_products')->justReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->call('get_skus_for_product_ids', [1]);
    }

    // ─── get_slugs_for_category_ids() ───────────────────────────────────────

    public function test_get_slugs_queries_product_cat_with_include_and_returns_slugs(): void
    {
        Functions\expect('get_terms')
            ->once()
            ->with(['taxonomy' => 'product_cat', 'include' => [3, 4], 'hide_empty' => false])
            ->andReturn([(object) ['slug' => 'shirts'], (object) ['slug' => 'hats']]);

        $this->assertSame(['shirts', 'hats'], $this->call('get_slugs_for_category_ids', [3, 4]));
    }

    public function test_get_slugs_throws_on_wp_error(): void
    {
        Functions\when('get_terms')->justReturn(new WP_Error('bad_tax', 'Invalid taxonomy'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve every coupon category restriction to a slug.');
        $this->call('get_slugs_for_category_ids', [3]);
    }

    public function test_get_slugs_throws_when_a_category_is_missing(): void
    {
        Functions\when('get_terms')->justReturn([(object) ['slug' => 'shirts']]);

        $this->expectException(\RuntimeException::class);
        $this->call('get_slugs_for_category_ids', [3, 4]);
    }

    public function test_get_slugs_duplicate_ids_count_once(): void
    {
        Functions\when('get_terms')->justReturn([(object) ['slug' => 'shirts']]);

        $this->assertSame(['shirts'], $this->call('get_slugs_for_category_ids', [3, 3]));
    }

    // ─── resolve_via_cached_map() ───────────────────────────────────────────

    public function test_cached_map_full_hit_skips_fetch_and_does_not_rewrite_cache(): void
    {
        Functions\when('get_transient')->justReturn(['a' => 11, 'b' => 22]);
        Functions\expect('set_transient')->never();
        $fetches = 0;

        $ids = $this->call('resolve_via_cached_map', $this->client(), ['b', 'a'], 'k', function () use (&$fetches) {
            $fetches++;
            return [];
        });

        $this->assertSame([22, 11], $ids, 'Result order follows the requested keys');
        $this->assertSame(0, $fetches);
    }

    public function test_cached_map_partial_hit_fetches_only_missing_and_stores_merged_map(): void
    {
        Functions\when('get_transient')->justReturn(['a' => 11]);
        Functions\expect('set_transient')
            ->once()
            ->with('cache-key', ['a' => 11, 'b' => 22], HOUR_IN_SECONDS)
            ->andReturn(true);
        $fetched = [];

        $ids = $this->call('resolve_via_cached_map', $this->client(), ['a', 'b'], 'cache-key', function ($key) use (&$fetched) {
            $fetched[] = $key;
            return [['id' => 22]];
        });

        $this->assertSame([11, 22], $ids);
        $this->assertSame(['b'], $fetched);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonArrayCacheValues')]
    public function test_cached_map_ignores_non_array_transient(mixed $cached): void
    {
        Functions\when('get_transient')->justReturn($cached);
        Functions\expect('set_transient')->once()->with('k', ['a' => 7], HOUR_IN_SECONDS);

        $ids = $this->call('resolve_via_cached_map', $this->client(), ['a'], 'k', fn() => [['id' => 7]]);

        $this->assertSame([7], $ids);
    }

    public static function nonArrayCacheValues(): array
    {
        return [
            'miss (false)' => [false],
            'empty string' => [''],
            'corrupt string' => ['garbage'],
            'null' => [null],
            'integer' => [5],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedLookupResponses')]
    public function test_cached_map_throws_for_unresolvable_key(mixed $response): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')->never();

        try {
            $this->call('resolve_via_cached_map', $this->client(), ['missing-sku'], 'k', fn() => $response);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cannot resolve coupon restriction: missing-sku', $e->getMessage());
        }
    }

    public static function failedLookupResponses(): array
    {
        return [
            'wp_error' => [new WP_Error('http', 'timeout')],
            'empty list' => [[]],
            'first row without id' => [[['sku' => 'x']]],
            'id zero' => [[['id' => 0]]],
            'id null' => [[['id' => null]]],
        ];
    }

    public function test_cached_map_failure_after_success_does_not_cache_partial_results(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')->never();

        try {
            $this->call('resolve_via_cached_map', $this->client(), ['ok', 'bad'], 'k', fn($key) => $key === 'ok' ? [['id' => 1]] : new WP_Error('x', 'y'));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bad', $e->getMessage());
        }
    }

    public function test_cached_map_empty_cached_id_is_refetched(): void
    {
        Functions\when('get_transient')->justReturn(['a' => 0, 'b' => '']);
        Functions\expect('set_transient')->once()->with('k', ['a' => 5, 'b' => 6], HOUR_IN_SECONDS);

        $ids = $this->call('resolve_via_cached_map', $this->client(), ['a', 'b'], 'k', fn($key) => [['id' => $key === 'a' ? 5 : 6]]);

        $this->assertSame([5, 6], $ids);
    }

    public function test_cached_map_duplicate_keys_are_fetched_once(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        $fetches = 0;

        $ids = $this->call('resolve_via_cached_map', $this->client(), ['a', 'a'], 'k', function () use (&$fetches) {
            $fetches++;
            return [['id' => 9]];
        });

        $this->assertSame([9, 9], $ids);
        $this->assertSame(1, $fetches);
    }

    public function test_cached_map_with_no_keys_returns_empty_and_touches_no_cache_write(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')->never();

        $this->assertSame([], $this->call('resolve_via_cached_map', $this->client(), [], 'k', fn() => []));
    }

    // ─── resolve_skus_to_remote_ids() / resolve_category_slugs_to_remote_ids() ──

    public function test_resolve_skus_uses_per_store_cache_key_and_sku_query(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products', ['sku' => 'ABC', 'per_page' => 1])->andReturn([['id' => 42]]);
        Functions\expect('get_transient')->once()->with('wc_mss_coupon_sku_map_' . md5(self::STORE))->andReturn(false);
        Functions\expect('set_transient')->once()->with('wc_mss_coupon_sku_map_' . md5(self::STORE), ['ABC' => 42], HOUR_IN_SECONDS);

        $this->assertSame([42], $this->call('resolve_skus_to_remote_ids', $client, ['ABC'], self::STORE));
    }

    public function test_resolve_skus_cache_is_isolated_per_store(): void
    {
        $other = 'https://store-b.example.com';
        Functions\when('get_transient')->alias(
            fn($key) => $key === 'wc_mss_coupon_sku_map_' . md5(self::STORE) ? ['ABC' => 1] : false
        );
        Functions\when('set_transient')->justReturn(true);
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products', ['sku' => 'ABC', 'per_page' => 1])->andReturn([['id' => 2]]);

        $this->assertSame([1], $this->call('resolve_skus_to_remote_ids', $this->client(), ['ABC'], self::STORE));
        $this->assertSame([2], $this->call('resolve_skus_to_remote_ids', $client, ['ABC'], $other));
    }

    public function test_resolve_skus_throws_when_remote_lookup_errors(): void
    {
        Functions\when('get_transient')->justReturn(false);
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn(new WP_Error('http', 'down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve coupon restriction: ABC');
        $this->call('resolve_skus_to_remote_ids', $client, ['ABC'], self::STORE);
    }

    public function test_resolve_category_slugs_uses_category_endpoint_and_separate_cache_key(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products/categories', ['slug' => 'shirts', 'per_page' => 1])->andReturn([['id' => 8]]);
        Functions\expect('get_transient')->once()->with('wc_mss_coupon_category_map_' . md5(self::STORE))->andReturn(false);
        Functions\expect('set_transient')->once()->with('wc_mss_coupon_category_map_' . md5(self::STORE), ['shirts' => 8], HOUR_IN_SECONDS);

        $this->assertSame([8], $this->call('resolve_category_slugs_to_remote_ids', $client, ['shirts'], self::STORE));
    }

    public function test_resolve_category_slugs_resolves_each_slug_and_fails_on_missing_one(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')->never();
        $client = $this->client();
        $client->shouldReceive('get')->with('products/categories', ['slug' => 'shirts', 'per_page' => 1])->andReturn([['id' => 8]]);
        $client->shouldReceive('get')->with('products/categories', ['slug' => 'ghost', 'per_page' => 1])->andReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ghost');
        $this->call('resolve_category_slugs_to_remote_ids', $client, ['shirts', 'ghost'], self::STORE);
    }
}
