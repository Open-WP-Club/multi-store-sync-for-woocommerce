<?php
/**
 * Tests for WC_Multi_Store_Shipping_Class_Sync lookup helpers
 * (find_remote_shipping_class, get_remote_shipping_classes),
 * sync_all_shipping_classes() aggregation, and feature settings bits.
 */

use Brain\Monkey\Functions;

if (!class_exists('WP_Term')) {
    // Kept in sync with the other guarded `WP_Term` stubs in this suite
    // (CategoryMapperTest.php, ShippingClassSyncTest.php, ...).
    class WP_Term {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $taxonomy = 'product_cat';
        public string $description = '';
        public int $parent = 0;
        public int $count = 0;
    }
}

class ShippingClassSyncResolveTest extends WC_Multi_Store_TestCase
{
    private const STORE = 'https://store-a.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
    }

    private function sync(): WC_Multi_Store_Shipping_Class_Sync
    {
        return (new ReflectionClass(WC_Multi_Store_Shipping_Class_Sync::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(WC_Multi_Store_Shipping_Class_Sync::class, $method))->invoke($this->sync(), ...$args);
    }

    private function client(): \Mockery\MockInterface
    {
        return \Mockery::mock(WC_Multi_Store_API_Client::class);
    }

    private function term(string $name, string $slug): WP_Term
    {
        $t = new WP_Term();
        $t->name = $name;
        $t->slug = $slug;
        return $t;
    }

    /** @return array<int, array{id:int, slug:string}> */
    private function classes(int $count, int $firstId = 1): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $firstId + $i, 'slug' => 'class-' . ($firstId + $i)];
        }
        return $rows;
    }

    // ─── get_remote_shipping_classes() ──────────────────────────────────────

    public function test_get_remote_classes_returns_cached_list_without_api_call(): void
    {
        $cached = [['id' => 1, 'slug' => 'heavy']];
        $keys = [];
        Functions\when('get_transient')->alias(function ($key) use (&$keys, $cached) {
            $keys[] = $key;
            return $cached;
        });
        $writes = 0;
        Functions\when('set_transient')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });
        $client = $this->client();
        $client->shouldReceive('get')->never();

        $this->assertSame($cached, $this->call('get_remote_shipping_classes', $client, self::STORE));
        $this->assertSame(['wc_mss_shipping_class_' . md5(self::STORE)], $keys);
        $this->assertSame(0, $writes);
    }

    public function test_get_remote_classes_ignores_a_corrupt_non_array_cache_entry(): void
    {
        Functions\when('get_transient')->justReturn('corrupt');
        $rows = $this->classes(2);
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn($rows);
        Functions\when('set_transient')->justReturn(true);

        $this->assertSame($rows, $this->call('get_remote_shipping_classes', $client, self::STORE));
    }

    public function test_get_remote_classes_treats_cached_empty_list_as_a_hit(): void
    {
        Functions\when('get_transient')->justReturn([]);
        $client = $this->client();
        $client->shouldReceive('get')->never();

        $this->assertSame([], $this->call('get_remote_shipping_classes', $client, self::STORE));
    }

    public function test_get_remote_classes_fetches_and_caches_for_one_hour_per_store(): void
    {
        $rows = $this->classes(3);
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products/shipping_classes', ['per_page' => 100, 'page' => 1])->andReturn($rows);
        $writes = [];
        Functions\when('set_transient')->alias(function (...$args) use (&$writes) {
            $writes[] = $args;
            return true;
        });

        $this->assertSame($rows, $this->call('get_remote_shipping_classes', $client, self::STORE));
        $this->assertSame([['wc_mss_shipping_class_' . md5(self::STORE), $rows, HOUR_IN_SECONDS]], $writes);
    }

    public function test_get_remote_classes_caches_an_empty_remote_list(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn([]);
        $writes = [];
        Functions\when('set_transient')->alias(function (...$args) use (&$writes) {
            $writes[] = $args;
            return true;
        });

        $this->assertSame([], $this->call('get_remote_shipping_classes', $client, self::STORE));
        $this->assertSame([['wc_mss_shipping_class_' . md5(self::STORE), [], HOUR_IN_SECONDS]], $writes);
    }

    public function test_get_remote_classes_does_not_paginate_below_page_size(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn($this->classes(99));

        $this->assertCount(99, $this->call('get_remote_shipping_classes', $client, self::STORE));
    }

    public function test_get_remote_classes_merges_all_pages_until_a_short_page(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 1])->once()->andReturn($this->classes(100, 1));
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 2])->once()->andReturn($this->classes(100, 101));
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 3])->once()->andReturn($this->classes(5, 201));

        $result = $this->call('get_remote_shipping_classes', $client, self::STORE);

        $this->assertCount(205, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(205, $result[204]['id']);
    }

    public function test_get_remote_classes_requests_an_extra_empty_page_after_a_full_final_page(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 1])->once()->andReturn($this->classes(100));
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 2])->once()->andReturn([]);

        $this->assertCount(100, $this->call('get_remote_shipping_classes', $client, self::STORE));
    }

    public function test_get_remote_classes_returns_wp_error_from_first_page_and_does_not_cache(): void
    {
        $error = new WP_Error('http_500', 'boom');
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn($error);
        $writes = 0;
        Functions\when('set_transient')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });

        $this->assertSame($error, $this->call('get_remote_shipping_classes', $client, self::STORE));
        $this->assertSame(0, $writes);
    }

    public function test_get_remote_classes_returns_wp_error_when_a_later_page_fails(): void
    {
        $error = new WP_Error('http_503', 'later');
        $client = $this->client();
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 1])->andReturn($this->classes(100));
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 2])->andReturn($error);
        $writes = 0;
        Functions\when('set_transient')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });

        $this->assertSame($error, $this->call('get_remote_shipping_classes', $client, self::STORE));
        $this->assertSame(0, $writes);
    }

    public function test_get_remote_classes_cache_key_differs_per_store(): void
    {
        $seen = [];
        Functions\when('get_transient')->alias(function ($key) use (&$seen) {
            $seen[] = $key;
            return [];
        });

        $this->call('get_remote_shipping_classes', $this->client(), 'https://one.example');
        $this->call('get_remote_shipping_classes', $this->client(), 'https://two.example');

        $this->assertCount(2, array_unique($seen));
    }

    // ─── find_remote_shipping_class() ───────────────────────────────────────

    public function test_find_remote_class_returns_matching_record_from_cache(): void
    {
        Functions\when('get_transient')->justReturn([['id' => 1, 'slug' => 'light'], ['id' => 2, 'slug' => 'heavy', 'name' => 'Heavy']]);

        $this->assertSame(
            ['id' => 2, 'slug' => 'heavy', 'name' => 'Heavy'],
            $this->call('find_remote_shipping_class', $this->client(), 'heavy', self::STORE)
        );
    }

    public function test_find_remote_class_returns_null_when_not_found(): void
    {
        Functions\when('get_transient')->justReturn([['id' => 1, 'slug' => 'light']]);

        $this->assertNull($this->call('find_remote_shipping_class', $this->client(), 'heavy', self::STORE));
    }

    public function test_find_remote_class_returns_null_for_empty_remote_list(): void
    {
        Functions\when('get_transient')->justReturn([]);

        $this->assertNull($this->call('find_remote_shipping_class', $this->client(), 'heavy', self::STORE));
    }

    public function test_find_remote_class_slug_comparison_is_exact_and_case_sensitive(): void
    {
        Functions\when('get_transient')->justReturn([['id' => 1, 'slug' => 'heavy'], ['id' => 2, 'slug' => 'heavy-2']]);

        $this->assertNull($this->call('find_remote_shipping_class', $this->client(), 'Heavy', self::STORE));
        $this->assertNull($this->call('find_remote_shipping_class', $this->client(), 'hea', self::STORE));
        $this->assertSame(2, $this->call('find_remote_shipping_class', $this->client(), 'heavy-2', self::STORE)['id']);
    }

    public function test_find_remote_class_row_without_slug_only_matches_an_empty_search(): void
    {
        Functions\when('get_transient')->justReturn([['id' => 9], ['id' => 1, 'slug' => 'heavy']]);

        // A row lacking a slug normalises to '' and therefore matches an empty slug search.
        $this->assertSame(['id' => 9], $this->call('find_remote_shipping_class', $this->client(), '', self::STORE));
        $this->assertSame(1, $this->call('find_remote_shipping_class', $this->client(), 'heavy', self::STORE)['id']);
    }

    public function test_find_remote_class_propagates_wp_error(): void
    {
        $error = new WP_Error('http_500', 'boom');
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn($error);

        $this->assertSame($error, $this->call('find_remote_shipping_class', $client, 'heavy', self::STORE));
    }

    public function test_find_remote_class_finds_entry_on_later_page(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 1])->andReturn($this->classes(100));
        $client->shouldReceive('get')->with('products/shipping_classes', ['per_page' => 100, 'page' => 2])->andReturn([['id' => 500, 'slug' => 'heavy']]);

        $this->assertSame(500, $this->call('find_remote_shipping_class', $client, 'heavy', self::STORE)['id']);
    }

    // ─── sync_all_shipping_classes() ────────────────────────────────────────

    public function test_sync_all_returns_zeroes_when_terms_query_errors(): void
    {
        Functions\when('get_terms')->justReturn(new WP_Error('invalid_taxonomy', 'nope'));

        $this->assertSame(['synced' => 0, 'failed' => 0, 'total' => 0], WC_Multi_Store_Shipping_Class_Sync::sync_all_shipping_classes());
    }

    public function test_sync_all_returns_zeroes_when_no_classes_exist(): void
    {
        $args = null;
        Functions\when('get_terms')->alias(function ($a) use (&$args) {
            $args = $a;
            return [];
        });

        $this->assertSame(['synced' => 0, 'failed' => 0, 'total' => 0], WC_Multi_Store_Shipping_Class_Sync::sync_all_shipping_classes());
        $this->assertSame(['taxonomy' => 'product_shipping_class', 'hide_empty' => false], $args);
    }

    public function test_sync_all_counts_total_but_no_results_when_no_active_stores(): void
    {
        Functions\when('get_terms')->justReturn([$this->term('Heavy', 'heavy'), $this->term('Light', 'light')]);

        $this->assertSame(['synced' => 0, 'failed' => 0, 'total' => 2], WC_Multi_Store_Shipping_Class_Sync::sync_all_shipping_classes());
    }

    public function test_sync_all_reports_partial_failures_per_store_and_class(): void
    {
        $stores = [
            'https://ok.example.com' => ['status' => 'active', 'store_url' => 'https://ok.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'https://bad.example.com' => ['status' => 'active', 'store_url' => 'https://bad.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'https://off.example.com' => ['status' => 'inactive', 'store_url' => 'https://off.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
        ];
        Functions\when('get_option')->alias(fn($key, $default = false) => match ($key) {
            'wc_multi_store_sync_settings' => ['enabled' => true, 'auth_method' => 'basic_auth'],
            'wc_multi_store_sync_stores' => $stores,
            default => $default,
        });
        Functions\when('get_terms')->justReturn([$this->term('Heavy', 'heavy'), $this->term('Light', 'light')]);
        // Remote class lists are already cached (empty), so only the create call hits HTTP.
        Functions\when('get_transient')->alias(fn($key) => str_starts_with($key, 'wc_mss_shipping_class_') ? [] : false);

        $posted = [];
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('do_action')->justReturn(null);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$posted) {
            $posted[] = $url;
            return str_contains($url, 'ok.example.com')
                ? ['response' => ['code' => 201], 'body' => json_encode(['id' => 10])]
                : ['response' => ['code' => 400], 'body' => json_encode(['message' => 'rejected'])];
        });

        $result = WC_Multi_Store_Shipping_Class_Sync::sync_all_shipping_classes();

        $this->assertSame(['synced' => 2, 'failed' => 2, 'total' => 2], $result);
        $this->assertCount(4, $posted, 'One create call per (active store x class); inactive stores are skipped');
        $this->assertEmpty(array_filter($posted, fn($u) => str_contains($u, 'off.example.com')));
    }

    // ─── Toggleable trait bits ──────────────────────────────────────────────

    public function test_feature_metadata(): void
    {
        $this->assertSame(['enabled' => false, 'auto_sync_on_change' => true], WC_Multi_Store_Shipping_Class_Sync::default_settings());
        $this->assertSame('Shipping class sync', WC_Multi_Store_Shipping_Class_Sync::feature_label());
        $this->assertSame('shipping_class_sync', WC_Multi_Store_Shipping_Class_Sync::central_settings_prefix());
    }

    public function test_get_settings_reads_prefixed_central_keys_per_default_key(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $key === 'wc_multi_store_sync_settings'
            ? ['shipping_class_sync_enabled' => true, 'shipping_class_sync_auto_sync_on_change' => false]
            : $default);

        $this->assertSame(['enabled' => true, 'auto_sync_on_change' => false], WC_Multi_Store_Shipping_Class_Sync::get_settings());
        $this->assertTrue(WC_Multi_Store_Shipping_Class_Sync::is_enabled());
    }

    public function test_ajax_toggle_off_reports_disabled_message_and_persists_false(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $saved = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved) {
            $saved = $value;
            return true;
        });
        $payload = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$payload) {
            $payload = $data;
        });

        $_POST = ['enabled' => '0'];
        try {
            WC_Multi_Store_Shipping_Class_Sync::ajax_toggle();
        } finally {
            $_POST = [];
        }

        $this->assertFalse($saved['shipping_class_sync_enabled']);
        $this->assertSame(['message' => 'Shipping class sync disabled', 'enabled' => false], $payload);
    }
}
