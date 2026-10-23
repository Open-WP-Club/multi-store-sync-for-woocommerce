<?php
/**
 * Tests for the remote term helpers of WC_Multi_Store_Sync_Engine:
 * filter_categories_if_no_auto_create, filter_existing_terms,
 * get_cached_remote_terms and ensure_terms_exist (name-match / tags / cache paths).
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class SyncEngineTermFilteringTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Sync_Engine $engine;

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var list<string> */
    private array $setKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
        $this->transients = [];
        $this->setKeys = [];

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true, 'auth_method' => 'query_string'];
            }
            return $default;
        });
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            $this->setKeys[] = $k;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });
        Functions\when('sanitize_title')->alias(fn($s) => strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-')));

        $this->engine = new WC_Multi_Store_Sync_Engine();
    }

    private function invoke(string $method, array $args): mixed
    {
        return (new ReflectionMethod($this->engine, $method))->invokeArgs($this->engine, $args);
    }

    private function api(): \Mockery\MockInterface
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        return $api;
    }

    private function remoteCats(): array
    {
        return [
            ['id' => 10, 'name' => 'Shoes', 'slug' => 'shoes'],
            ['id' => 20, 'name' => 'Bags & Co', 'slug' => 'bags-co'],
        ];
    }

    // ---- filter_existing_terms -------------------------------------------

    public function test_filter_existing_terms_returns_empty_when_remote_has_no_terms(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->once()->andReturn([]);

        $result = $this->invoke('filter_existing_terms', [[['name' => 'Shoes', 'slug' => 'shoes']], $api, 'categories', []]);

        $this->assertSame([], $result);
    }

    public function test_filter_existing_terms_matches_by_slug_case_insensitively_and_drops_unknown(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn($this->remoteCats());

        $terms = [
            ['name' => 'Shoes', 'slug' => 'SHOES'],
            ['name' => 'Unknown', 'slug' => 'unknown'],
            ['name' => 'Bags', 'slug' => 'bags-co'],
        ];
        $result = $this->invoke('filter_existing_terms', [$terms, $api, 'categories', ['category_match_by' => 'slug']]);

        $this->assertSame([['id' => 10], ['id' => 20]], $result);
    }

    public function test_filter_existing_terms_matches_by_name_multibyte_case_insensitive(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_tags')->andReturn([
            ['id' => 1, 'name' => 'Балони', 'slug' => 'baloni'],
            ['id' => 2, 'name' => 'Sale', 'slug' => 'sale'],
        ]);

        $terms = [['name' => 'БАЛОНИ', 'slug' => 'different'], ['name' => 'sale', 'slug' => 'zzz']];
        $result = $this->invoke('filter_existing_terms', [$terms, $api, 'tags', ['category_match_by' => 'name']]);

        $this->assertSame([['id' => 1], ['id' => 2]], $result);
    }

    public function test_filter_existing_terms_skips_terms_without_search_value(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn($this->remoteCats());

        $terms = [['name' => '', 'slug' => ''], [], ['slug' => 'shoes']];
        $result = $this->invoke('filter_existing_terms', [$terms, $api, 'categories', []]);

        $this->assertSame([['id' => 10]], $result);
    }

    public function test_filter_existing_terms_slug_mode_falls_back_to_name_value_when_slug_missing(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn([['id' => 5, 'name' => 'Hats', 'slug' => 'hats']]);

        // No slug key: $term['slug'] ?? $term['name'] -> "hats" matched against slug map.
        $result = $this->invoke('filter_existing_terms', [[['name' => 'hats']], $api, 'categories', []]);

        $this->assertSame([['id' => 5]], $result);
    }

    // ---- get_cached_remote_terms -----------------------------------------

    public function test_get_cached_remote_terms_returns_cache_without_api_call(): void
    {
        WC_Multi_Store_Cache_Manager::set_remote_terms('https://store1.com', 'categories', $this->remoteCats());
        $api = $this->api();
        $api->shouldNotReceive('get_categories');

        $result = $this->invoke('get_cached_remote_terms', [$api, 'categories']);

        $this->assertSame($this->remoteCats(), $result);
    }

    public function test_get_cached_remote_terms_fetches_and_stores_on_miss(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_tags')->once()->with('', ['per_page' => 100, 'page' => 1])->andReturn([
            ['id' => 3, 'name' => 'New', 'slug' => 'new'],
        ]);

        $result = $this->invoke('get_cached_remote_terms', [$api, 'tags']);

        $this->assertSame([['id' => 3, 'name' => 'New', 'slug' => 'new']], $result);
        $this->assertSame($result, WC_Multi_Store_Cache_Manager::get_remote_terms('https://store1.com', 'tags'));

        // Second call is served from the cache (get_tags expected only once).
        $this->assertSame($result, $this->invoke('get_cached_remote_terms', [$api, 'tags']));
    }

    public function test_get_cached_remote_terms_caches_separately_per_type(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->once()->andReturn([['id' => 1, 'name' => 'C', 'slug' => 'c']]);
        $api->shouldReceive('get_tags')->once()->andReturn([['id' => 2, 'name' => 'T', 'slug' => 't']]);

        $cats = $this->invoke('get_cached_remote_terms', [$api, 'categories']);
        $tags = $this->invoke('get_cached_remote_terms', [$api, 'tags']);

        $this->assertSame(1, $cats[0]['id']);
        $this->assertSame(2, $tags[0]['id']);
    }

    public function test_get_cached_remote_terms_caches_empty_result_from_api_error(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->once()->andReturn(new WP_Error('boom', 'down'));

        $this->assertSame([], $this->invoke('get_cached_remote_terms', [$api, 'categories']));
        $this->assertSame([], WC_Multi_Store_Cache_Manager::get_remote_terms('https://store1.com', 'categories'));
    }

    // ---- filter_categories_if_no_auto_create -----------------------------

    public function test_auto_create_default_creates_missing_categories_and_tags(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn([]);
        $api->shouldReceive('get_tags')->andReturn([]);
        $api->shouldReceive('batch_categories')->once()
            ->with(['create' => [['name' => 'Shoes', 'slug' => 'shoes']]])
            ->andReturn(['create' => [['id' => 11, 'name' => 'Shoes', 'slug' => 'shoes']]]);
        $api->shouldReceive('batch_tags')->once()
            ->with(['create' => [['name' => 'Hot', 'slug' => 'hot']]])
            ->andReturn(['create' => [['id' => 12, 'name' => 'Hot', 'slug' => 'hot']]]);

        $data = ['name' => 'P', 'categories' => [['name' => 'Shoes', 'slug' => 'shoes']], 'tags' => [['name' => 'Hot', 'slug' => 'hot']]];
        $result = $this->invoke('filter_categories_if_no_auto_create', [$data, $api, []]);

        $this->assertSame([['id' => 11]], $result['categories']);
        $this->assertSame([['id' => 12]], $result['tags']);
        $this->assertSame('P', $result['name']);
    }

    public function test_auto_create_disabled_never_creates_and_filters_to_existing(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn($this->remoteCats());
        $api->shouldReceive('get_tags')->andReturn([['id' => 7, 'name' => 'Hot', 'slug' => 'hot']]);
        $api->shouldNotReceive('batch_categories');
        $api->shouldNotReceive('batch_tags');
        $api->shouldNotReceive('create_category');
        $api->shouldNotReceive('create_tag');

        $data = [
            'categories' => [['name' => 'Shoes', 'slug' => 'shoes'], ['name' => 'New', 'slug' => 'new']],
            'tags' => [['name' => 'Cold', 'slug' => 'cold'], ['name' => 'Hot', 'slug' => 'hot']],
        ];
        $result = $this->invoke('filter_categories_if_no_auto_create', [$data, $api, ['category_auto_create' => false]]);

        $this->assertSame([['id' => 10]], $result['categories']);
        $this->assertSame([['id' => 7]], $result['tags']);
    }

    public function test_empty_or_missing_terms_make_no_api_calls_and_are_left_untouched(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldNotReceive('get_categories');
        $api->shouldNotReceive('get_tags');
        $api->shouldNotReceive('get_store_url');

        $data = ['name' => 'P', 'categories' => [], 'price' => '9'];
        $result = $this->invoke('filter_categories_if_no_auto_create', [$data, $api, ['category_auto_create' => false]]);

        $this->assertSame($data, $result);
    }

    public function test_auto_create_disabled_with_empty_remote_yields_empty_term_list(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn([]);

        $result = $this->invoke('filter_categories_if_no_auto_create', [
            ['categories' => [['name' => 'X', 'slug' => 'x']]], $api, ['category_auto_create' => false],
        ]);

        $this->assertSame([], $result['categories']);
        $this->assertArrayNotHasKey('tags', $result);
    }

    // ---- ensure_terms_exist (paths not covered elsewhere) ----------------

    public function test_ensure_terms_exist_name_mode_matches_name_case_insensitively(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn($this->remoteCats());
        $api->shouldNotReceive('batch_categories');

        $result = $this->invoke('ensure_terms_exist', [
            [['name' => 'SHOES', 'slug' => 'shoes']], $api, 'categories', ['category_match_by' => 'name'],
        ]);

        $this->assertSame([['id' => 10]], $result);
    }

    public function test_ensure_terms_exist_derives_slug_from_name_when_missing(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_tags')->andReturn([]);
        $api->shouldReceive('batch_tags')->once()
            ->with(['create' => [['name' => 'Big Sale', 'slug' => 'big-sale']]])
            ->andReturn(['create' => [['id' => 90, 'name' => 'Big Sale', 'slug' => 'big-sale']]]);

        $result = $this->invoke('ensure_terms_exist', [[['name' => 'Big Sale']], $api, 'tags', []]);

        $this->assertSame([['id' => 90]], $result);
    }

    public function test_ensure_terms_exist_skips_nameless_terms_and_preserves_input_order(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn($this->remoteCats());
        $api->shouldReceive('batch_categories')->once()
            ->andReturn(['create' => [['id' => 30, 'name' => 'Hats', 'slug' => 'hats']]]);

        $terms = [
            ['name' => 'Hats', 'slug' => 'hats'],
            ['name' => '', 'slug' => 'ghost'],
            ['name' => 'Bags & Co', 'slug' => 'bags-co'],
            ['name' => 'Shoes', 'slug' => 'shoes'],
        ];
        $result = $this->invoke('ensure_terms_exist', [$terms, $api, 'categories', []]);

        // Order follows the input: Hats(new), Bags, Shoes.
        $this->assertSame([['id' => 30], ['id' => 20], ['id' => 10]], $result);
    }

    public function test_ensure_terms_exist_does_not_rename_when_names_already_match(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn($this->remoteCats());
        $api->shouldNotReceive('batch_categories');

        $result = $this->invoke('ensure_terms_exist', [
            [['name' => 'Shoes', 'slug' => 'shoes']], $api, 'categories', ['category_match_by' => 'slug'],
        ]);

        $this->assertSame([['id' => 10]], $result);
    }

    public function test_ensure_terms_exist_rename_failure_still_returns_ids(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->andReturn($this->remoteCats());
        $api->shouldReceive('batch_categories')->once()
            ->with(['update' => [['id' => 10, 'name' => 'Boots']]])
            ->andReturn(new WP_Error('x', 'rename failed'));

        $result = $this->invoke('ensure_terms_exist', [
            [['name' => 'Boots', 'slug' => 'shoes']], $api, 'categories', ['category_match_by' => 'slug'],
        ]);

        $this->assertSame([['id' => 10]], $result);
    }

    public function test_created_terms_are_appended_to_the_cache_the_next_lookup_reads(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->once()->andReturn($this->remoteCats());
        $api->shouldReceive('batch_categories')->once()
            ->andReturn(['create' => [['id' => 99, 'name' => 'Hats', 'slug' => 'hats']]]);

        $this->invoke('ensure_terms_exist', [
            [['name' => 'Hats', 'slug' => 'hats']], $api, 'categories', ['category_match_by' => 'slug'],
        ]);

        // get_cached_remote_terms() reads through the Cache Manager; it must now see the new term
        // without another API round-trip (get_categories is expected only once above).
        $cached = $this->invoke('get_cached_remote_terms', [$api, 'categories']);
        $this->assertContains(99, array_column($cached, 'id'));
    }

    public function test_renamed_terms_are_patched_in_the_cache_the_next_lookup_reads(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_categories')->once()->andReturn($this->remoteCats());
        $api->shouldReceive('batch_categories')->once()->andReturn(['update' => [['id' => 10]]]);

        $this->invoke('ensure_terms_exist', [
            [['name' => 'Boots', 'slug' => 'shoes']], $api, 'categories', ['category_match_by' => 'slug'],
        ]);

        $cached = $this->invoke('get_cached_remote_terms', [$api, 'categories']);
        $byId = array_column($cached, null, 'id');
        $this->assertSame('Boots', $byId[10]['name']);
    }

    #[DataProvider('typeProvider')]
    public function test_ensure_terms_exist_uses_type_specific_endpoints(string $type, string $getter, string $batch): void
    {
        $api = $this->api();
        $api->shouldReceive($getter)->andReturn([]);
        $api->shouldReceive($batch)->once()->andReturn(['create' => [['id' => 1, 'name' => 'A', 'slug' => 'a']]]);

        $result = $this->invoke('ensure_terms_exist', [[['name' => 'A', 'slug' => 'a']], $api, $type, []]);

        $this->assertSame([['id' => 1]], $result);
    }

    /** @return array<string, array{string, string, string}> */
    public static function typeProvider(): array
    {
        return [
            'categories' => ['categories', 'get_categories', 'batch_categories'],
            'tags' => ['tags', 'get_tags', 'batch_tags'],
        ];
    }
}
