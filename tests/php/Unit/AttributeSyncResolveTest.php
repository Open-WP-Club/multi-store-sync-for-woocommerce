<?php
/**
 * Tests for WC_Multi_Store_Attribute_Sync lookup helpers
 * (find_remote_attribute, find_remote_term, get_local_attribute,
 * is_attribute_taxonomy) and the fan-out methods
 * (sync_attribute_to_all_stores, sync_term_to_all_stores).
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

class AttributeSyncResolveTest extends WC_Multi_Store_TestCase
{
    private const OK = 'https://ok.example.com';
    private const BAD = 'https://bad.example.com';

    /** @var array<int, array{0:string,1:string,2:mixed}> recorded HTTP calls: method, url, decoded body */
    private array $http = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = [];
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        // Mirrors wc_attribute_taxonomy_slug(): sanitises, lower-cases and drops the pa_ prefix.
        Functions\when('wc_attribute_taxonomy_slug')->alias(function ($name) {
            $name = preg_replace('/[^a-z0-9_\-]/', '', str_replace(' ', '-', strtolower((string) $name)));
            return str_starts_with($name, 'pa_') ? substr($name, 3) : $name;
        });
    }

    private function sync(): WC_Multi_Store_Attribute_Sync
    {
        return (new ReflectionClass(WC_Multi_Store_Attribute_Sync::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(WC_Multi_Store_Attribute_Sync::class, $method))->invoke($this->sync(), ...$args);
    }

    private function client(): \Mockery\MockInterface
    {
        return \Mockery::mock(WC_Multi_Store_API_Client::class);
    }

    private function term(string $name = 'Red', string $slug = 'red'): WP_Term
    {
        $t = new WP_Term();
        $t->term_id = 1;
        $t->name = $name;
        $t->slug = $slug;
        $t->description = 'desc';
        $t->taxonomy = 'pa_color';
        return $t;
    }

    private function localAttribute(): object
    {
        return (object) [
            'attribute_id' => 5,
            'attribute_name' => 'color',
            'attribute_label' => 'Color',
            'attribute_type' => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public' => 1,
        ];
    }

    private function configureStores(array $statuses): void
    {
        $stores = [];
        foreach ($statuses as $url => $status) {
            $stores[$url] = ['status' => $status, 'store_url' => $url, 'consumer_key' => 'ck', 'consumer_secret' => 'cs'];
        }
        Functions\when('get_option')->alias(fn($key, $default = false) => match ($key) {
            'wc_multi_store_sync_settings' => ['enabled' => true, 'auth_method' => 'basic_auth'],
            'wc_multi_store_sync_stores' => $stores,
            default => $default,
        });
        WC_Multi_Store_Settings::clear_static_cache();
    }

    /**
     * Fake the remote WooCommerce REST API through the HTTP layer.
     *
     * @param callable(string $method, string $url, mixed $body): array{0:int,1:mixed} $router Returns [status, payload]
     */
    private function fakeHttp(callable $router): void
    {
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);

        $handle = function (string $method) use ($router) {
            return function ($url, $args = []) use ($method, $router) {
                $method = $args['method'] ?? $method;
                $body = isset($args['body']) ? json_decode($args['body'], true) : null;
                $this->http[] = [$method, $url, $body];
                [$status, $payload] = $router($method, $url, $body);
                return ['response' => ['code' => $status], 'body' => json_encode($payload)];
            };
        };
        Functions\when('wp_remote_get')->alias($handle('GET'));
        Functions\when('wp_remote_post')->alias($handle('POST'));
        Functions\when('wp_remote_request')->alias($handle('PUT'));
    }

    /** @return string[] "METHOD path" for each recorded call, with host and query removed */
    private function calls(?string $host = null): array
    {
        $out = [];
        foreach ($this->http as [$method, $url]) {
            if ($host !== null && !str_starts_with($url, $host)) {
                continue;
            }
            $path = parse_url($url, PHP_URL_PATH);
            $out[] = $method . ' ' . preg_replace('#^/wp-json/[^/]+/v3/#', '', $path);
        }
        return $out;
    }

    // ─── is_attribute_taxonomy() ────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('taxonomies')]
    public function test_is_attribute_taxonomy(string $taxonomy, bool $expected): void
    {
        $this->assertSame($expected, $this->call('is_attribute_taxonomy', $taxonomy));
    }

    public static function taxonomies(): array
    {
        return [
            'attribute taxonomy' => ['pa_color', true],
            'bare prefix' => ['pa_', true],
            'product category' => ['product_cat', false],
            'product tag' => ['product_tag', false],
            'upper-case prefix' => ['PA_color', false],
            'prefix not at start' => ['xpa_color', false],
            'no underscore' => ['pa', false],
            'empty' => ['', false],
        ];
    }

    // ─── get_local_attribute() ──────────────────────────────────────────────

    public function test_get_local_attribute_finds_by_id(): void
    {
        $a = (object) ['attribute_id' => 3, 'attribute_name' => 'size'];
        $b = $this->localAttribute();
        Functions\when('wc_get_attribute_taxonomies')->justReturn([$a, $b]);

        $this->assertSame($b, $this->call('get_local_attribute', 5));
        $this->assertSame($a, $this->call('get_local_attribute', 3));
    }

    public function test_get_local_attribute_matches_string_ids_from_the_database(): void
    {
        $row = (object) ['attribute_id' => '5', 'attribute_name' => 'color'];
        Functions\when('wc_get_attribute_taxonomies')->justReturn([$row]);

        $this->assertSame($row, $this->call('get_local_attribute', 5));
    }

    public function test_get_local_attribute_returns_null_when_missing_or_none_registered(): void
    {
        Functions\when('wc_get_attribute_taxonomies')->justReturn([$this->localAttribute()]);
        $this->assertNull($this->call('get_local_attribute', 99));

        Functions\when('wc_get_attribute_taxonomies')->justReturn([]);
        $this->assertNull($this->call('get_local_attribute', 5));
    }

    // ─── find_remote_attribute() ────────────────────────────────────────────

    public function test_find_remote_attribute_requests_the_full_list_with_per_page_100(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products/attributes', ['per_page' => 100, 'page' => 1])->andReturn([]);

        $this->assertNull($this->call('find_remote_attribute', $client, 'color'));
    }

    public function test_find_remote_attribute_keeps_paging_until_it_finds_the_attribute(): void
    {
        $firstPage = array_map(fn($i) => ['id' => $i, 'slug' => 'pa_filler' . $i], range(1, 100));
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products/attributes', ['per_page' => 100, 'page' => 1])->andReturn($firstPage);
        $client->shouldReceive('get')->once()->with('products/attributes', ['per_page' => 100, 'page' => 2])
            ->andReturn([['id' => 101, 'slug' => 'pa_color']]);

        $this->assertSame(['id' => 101, 'slug' => 'pa_color'], $this->call('find_remote_attribute', $client, 'color'));
    }

    public function test_find_remote_attribute_stops_after_a_short_page(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn([['id' => 1, 'slug' => 'pa_other']]);

        $this->assertNull($this->call('find_remote_attribute', $client, 'color'));
    }

    public function test_find_remote_attribute_returns_a_failure_from_a_later_page(): void
    {
        $firstPage = array_map(fn($i) => ['id' => $i, 'slug' => 'pa_filler' . $i], range(1, 100));
        $client = $this->client();
        $client->shouldReceive('get')->with('products/attributes', ['per_page' => 100, 'page' => 1])->andReturn($firstPage);
        $client->shouldReceive('get')->with('products/attributes', ['per_page' => 100, 'page' => 2])->andReturn(new WP_Error('x', 'boom'));

        $this->assertInstanceOf(WP_Error::class, $this->call('find_remote_attribute', $client, 'color'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attributeSlugEquivalents')]
    public function test_find_remote_attribute_normalises_slugs_on_both_sides(string $remote, string $local): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->andReturn([['id' => 1, 'slug' => 'other'], ['id' => 2, 'slug' => $remote]]);

        $this->assertSame(['id' => 2, 'slug' => $remote], $this->call('find_remote_attribute', $client, $local));
    }

    public static function attributeSlugEquivalents(): array
    {
        return [
            'remote pa_ prefix, local bare' => ['pa_color', 'color'],
            'remote bare, local pa_ prefix' => ['color', 'pa_color'],
            'both prefixed' => ['pa_color', 'pa_color'],
            'both bare' => ['color', 'color'],
            'case differs' => ['Color', 'pa_COLOR'],
            'space vs hyphen' => ['pa_shoe size', 'shoe-size'],
        ];
    }

    public function test_find_remote_attribute_returns_null_when_absent(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->andReturn([['id' => 1, 'slug' => 'pa_size']]);

        $this->assertNull($this->call('find_remote_attribute', $client, 'color'));
    }

    public function test_find_remote_attribute_returns_first_of_duplicate_matches(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->andReturn([['id' => 7, 'slug' => 'pa_color'], ['id' => 8, 'slug' => 'color']]);

        $this->assertSame(7, $this->call('find_remote_attribute', $client, 'color')['id']);
    }

    public function test_find_remote_attribute_skips_rows_without_slug(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->andReturn([['id' => 1], ['id' => 2, 'slug' => 'pa_color']]);

        $this->assertSame(2, $this->call('find_remote_attribute', $client, 'color')['id']);
    }

    public function test_find_remote_attribute_propagates_wp_error(): void
    {
        $error = new WP_Error('rest_forbidden', 'No');
        $client = $this->client();
        $client->shouldReceive('get')->andReturn($error);

        $this->assertSame($error, $this->call('find_remote_attribute', $client, 'color'));
    }

    // ─── find_remote_term() ─────────────────────────────────────────────────

    public function test_find_remote_term_queries_by_slug_under_the_remote_attribute_id(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()
            ->with('products/attributes/12/terms', ['slug' => 'red', 'per_page' => 1])
            ->andReturn([['id' => 99, 'slug' => 'red']]);

        $this->assertSame(['id' => 99, 'slug' => 'red'], $this->call('find_remote_term', $client, 12, 'red'));
    }

    public function test_find_remote_term_returns_null_for_empty_result(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->andReturn([]);

        $this->assertNull($this->call('find_remote_term', $client, 12, 'red'));
    }

    public function test_find_remote_term_propagates_wp_error(): void
    {
        $error = new WP_Error('http_500', 'boom');
        $client = $this->client();
        $client->shouldReceive('get')->andReturn($error);

        $this->assertSame($error, $this->call('find_remote_term', $client, 12, 'red'));
    }

    // ─── sync_attribute_to_all_stores() ─────────────────────────────────────

    public function test_sync_attribute_to_all_stores_with_no_active_stores_returns_empty(): void
    {
        $this->configureStores([self::OK => 'inactive']);
        $this->fakeHttp(fn() => [500, []]);

        $this->assertSame([], $this->sync()->sync_attribute_to_all_stores($this->localAttribute()));
        $this->assertSame([], $this->http);
    }

    public function test_sync_attribute_to_all_stores_reports_partial_failure_per_store(): void
    {
        $this->configureStores([self::OK => 'active', self::BAD => 'active']);
        $this->fakeHttp(function ($method, $url) {
            if (str_starts_with($url, self::BAD)) {
                return $method === 'GET' ? [200, []] : [400, ['message' => 'rejected']];
            }
            return match ($method) {
                'GET' => [200, [['id' => 4, 'slug' => 'pa_color']]],
                default => [200, ['id' => 4]],
            };
        });

        $results = $this->sync()->sync_attribute_to_all_stores($this->localAttribute());

        $this->assertSame([self::OK => true, self::BAD => false], $results);
        $this->assertSame(['GET products/attributes', 'PUT products/attributes/4'], $this->calls(self::OK));
        $this->assertSame(['GET products/attributes', 'POST products/attributes'], $this->calls(self::BAD));
    }

    public function test_sync_attribute_to_all_stores_sends_the_extracted_attribute_payload(): void
    {
        $this->configureStores([self::OK => 'active']);
        $this->fakeHttp(fn($method) => $method === 'GET' ? [200, []] : [201, ['id' => 1]]);

        $this->sync()->sync_attribute_to_all_stores($this->localAttribute());

        $posts = array_values(array_filter($this->http, fn($c) => $c[0] === 'POST'));
        $this->assertCount(1, $posts);
        $this->assertSame([
            'name' => 'Color',
            'slug' => 'color',
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => true,
        ], $posts[0][2]);
    }

    public function test_sync_attribute_to_all_stores_lookup_failure_marks_store_failed_without_writing(): void
    {
        $this->configureStores([self::OK => 'active']);
        $this->fakeHttp(fn($method) => $method === 'GET' ? [400, ['message' => 'nope']] : [200, ['id' => 1]]);

        $this->assertSame([self::OK => false], $this->sync()->sync_attribute_to_all_stores($this->localAttribute()));
        $this->assertSame(['GET products/attributes'], $this->calls());
    }

    public function test_sync_attribute_to_all_stores_restricts_to_requested_active_stores(): void
    {
        $this->configureStores([self::OK => 'active', self::BAD => 'active']);
        $this->fakeHttp(fn($method) => $method === 'GET' ? [200, []] : [201, ['id' => 1]]);

        $results = $this->sync()->sync_attribute_to_all_stores($this->localAttribute(), [self::BAD, 'https://unknown.example.com']);

        $this->assertSame([self::BAD => true], $results);
        $this->assertSame([], $this->calls(self::OK));
    }

    // ─── sync_term_to_all_stores() ──────────────────────────────────────────

    public function test_sync_term_to_all_stores_handles_missing_remote_attribute_and_success(): void
    {
        $this->configureStores([self::OK => 'active', self::BAD => 'active']);
        $this->fakeHttp(function ($method, $url) {
            if (str_starts_with($url, self::BAD)) {
                return [200, []]; // attribute list is empty remotely
            }
            if (str_contains($url, '/products/attributes/4/terms')) {
                return $method === 'GET' ? [200, []] : [201, ['id' => 77]];
            }
            return [200, [['id' => 4, 'slug' => 'pa_color']]];
        });

        $results = $this->sync()->sync_term_to_all_stores($this->term(), 'color');

        $this->assertSame([self::OK => true, self::BAD => false], $results);
        $this->assertSame(
            ['GET products/attributes', 'GET products/attributes/4/terms', 'POST products/attributes/4/terms'],
            $this->calls(self::OK)
        );
        $this->assertSame(['GET products/attributes'], $this->calls(self::BAD), 'No term call when the parent attribute is missing');
    }

    public function test_sync_term_to_all_stores_updates_existing_remote_term_and_sends_term_payload(): void
    {
        $this->configureStores([self::OK => 'active']);
        $this->fakeHttp(function ($method, $url) {
            if (str_contains($url, '/products/attributes/4/terms')) {
                return $method === 'GET' ? [200, [['id' => 90, 'slug' => 'red']]] : [200, ['id' => 90]];
            }
            return [200, [['id' => 4, 'slug' => 'pa_color']]];
        });

        $results = $this->sync()->sync_term_to_all_stores($this->term('Crimson', 'red'), 'pa_color');

        $this->assertSame([self::OK => true], $results);
        $puts = array_values(array_filter($this->http, fn($c) => $c[0] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertStringContainsString('/products/attributes/4/terms/90', $puts[0][1]);
        $this->assertSame(['name' => 'Crimson', 'slug' => 'red', 'description' => 'desc'], $puts[0][2]);
    }

    public function test_sync_term_to_all_stores_write_failure_is_isolated_to_that_store(): void
    {
        $this->configureStores([self::OK => 'active', self::BAD => 'active']);
        $this->fakeHttp(function ($method, $url) {
            if (str_contains($url, '/terms')) {
                if ($method === 'GET') {
                    return [200, []];
                }
                return str_starts_with($url, self::BAD) ? [500, ['message' => 'server error']] : [201, ['id' => 1]];
            }
            return [200, [['id' => 4, 'slug' => 'pa_color']]];
        });

        $this->assertSame(
            [self::OK => true, self::BAD => false],
            $this->sync()->sync_term_to_all_stores($this->term(), 'color')
        );
    }

    public function test_sync_term_to_all_stores_respects_store_url_filter(): void
    {
        $this->configureStores([self::OK => 'active', self::BAD => 'active']);
        $this->fakeHttp(fn($method, $url) => str_contains($url, '/terms')
            ? ($method === 'GET' ? [200, []] : [201, ['id' => 1]])
            : [200, [['id' => 4, 'slug' => 'pa_color']]]);

        $results = $this->sync()->sync_term_to_all_stores($this->term(), 'color', [self::OK]);

        $this->assertSame([self::OK => true], $results);
        $this->assertSame([], $this->calls(self::BAD));
    }
}
