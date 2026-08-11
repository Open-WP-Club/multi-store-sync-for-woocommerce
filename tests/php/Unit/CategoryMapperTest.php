<?php
/**
 * Unit tests for WC_Multi_Store_Category_Mapper
 */

use Brain\Monkey\Functions;

if (!class_exists('WP_Term')) {
    // Kept in sync with the other guarded `WP_Term` stubs in this suite
    // (AdminAjaxForceSyncTest.php, ShippingClassSyncTest.php, CategorySyncTest.php).
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

class CategoryMapperTest extends WC_Multi_Store_TestCase
{
    private string $store_url_a = 'https://store-a.example.com';
    private string $store_url_b = 'https://store-b.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        // Logger::write() calls current_time() when flushing the buffer.
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
    }

    // -------------------------------------------------------------------------
    // is_enabled()
    // -------------------------------------------------------------------------

    public function test_is_enabled_returns_false_by_default(): void
    {
        Functions\when('get_option')->justReturn([]);

        $this->assertFalse(WC_Multi_Store_Category_Mapper::is_enabled());
    }

    public function test_is_enabled_returns_true_when_enabled(): void
    {
        Functions\when('get_option')->justReturn(['category_mapper_enabled' => true]);

        $this->assertTrue(WC_Multi_Store_Category_Mapper::is_enabled());
    }

    // -------------------------------------------------------------------------
    // get_mappings() / set_mappings()
    // -------------------------------------------------------------------------

    public function test_get_mappings_returns_empty_for_unknown_store(): void
    {
        Functions\when('get_option')->justReturn([]);

        $result = WC_Multi_Store_Category_Mapper::get_mappings($this->store_url_a);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_mappings_returns_correct_subset_for_store(): void
    {
        $key_a = md5($this->store_url_a);
        $key_b = md5($this->store_url_b);

        $stored = [
            $key_a => ['clothing' => 'apparel'],
            $key_b => ['books'    => 'literature'],
        ];

        Functions\when('get_option')->justReturn($stored);

        $result_a = WC_Multi_Store_Category_Mapper::get_mappings($this->store_url_a);
        $result_b = WC_Multi_Store_Category_Mapper::get_mappings($this->store_url_b);

        $this->assertSame(['clothing' => 'apparel'], $result_a);
        $this->assertSame(['books' => 'literature'], $result_b);
    }

    public function test_set_mappings_stores_under_md5_key(): void
    {
        $expected_key = md5($this->store_url_a);
        $mappings     = ['clothing' => 'apparel', 'shoes' => 'footwear'];

        Functions\when('get_option')->justReturn([]);

        $captured = null;
        Functions\when('update_option')->alias(function (string $option, mixed $value) use (&$captured): bool {
            $captured = [$option, $value];
            return true;
        });

        WC_Multi_Store_Category_Mapper::set_mappings($this->store_url_a, $mappings);

        $this->assertNotNull($captured);
        $this->assertSame(WC_Multi_Store_Category_Mapper::OPTION_KEY, $captured[0]);
        $this->assertArrayHasKey($expected_key, $captured[1]);
        $this->assertSame($mappings, $captured[1][$expected_key]);
    }

    // -------------------------------------------------------------------------
    // add_mapping() / remove_mapping()
    // -------------------------------------------------------------------------

    public function test_add_mapping_adds_single_entry_to_existing(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === WC_Multi_Store_Category_Mapper::OPTION_KEY) {
                return [$key => ['shoes' => 'footwear']];
            }
            return $default;
        });

        $captured = null;
        Functions\when('update_option')->alias(function (string $option, mixed $value) use (&$captured): bool {
            $captured = $value;
            return true;
        });

        WC_Multi_Store_Category_Mapper::add_mapping($this->store_url_a, 'clothing', 'apparel');

        $this->assertNotNull($captured);
        $this->assertArrayHasKey($key, $captured);
        $this->assertSame('footwear', $captured[$key]['shoes']);
        $this->assertSame('apparel', $captured[$key]['clothing']);
    }

    public function test_remove_mapping_removes_entry(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === WC_Multi_Store_Category_Mapper::OPTION_KEY) {
                return [$key => ['clothing' => 'apparel', 'shoes' => 'footwear']];
            }
            return $default;
        });

        $captured = null;
        Functions\when('update_option')->alias(function (string $option, mixed $value) use (&$captured): bool {
            $captured = $value;
            return true;
        });

        WC_Multi_Store_Category_Mapper::remove_mapping($this->store_url_a, 'clothing');

        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('clothing', $captured[$key]);
        $this->assertArrayHasKey('shoes', $captured[$key]);
        $this->assertSame('footwear', $captured[$key]['shoes']);
    }

    // -------------------------------------------------------------------------
    // apply_mappings()
    // -------------------------------------------------------------------------

    public function test_apply_mappings_returns_unchanged_when_disabled(): void
    {
        Functions\when('get_option')->justReturn(['enabled' => false]);

        $product_data = ['categories' => [['slug' => 'clothing', 'name' => 'Clothing']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertSame($product_data, $result);
    }

    public function test_apply_mappings_returns_unchanged_when_no_categories(): void
    {
        Functions\when('get_option')->alias(function (string $option, mixed $default = []): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            return $default;
        });

        $product_data = ['name' => 'Test Product'];
        $result       = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertSame($product_data, $result);
    }

    public function test_apply_mappings_returns_unchanged_when_no_mappings_for_store(): void
    {
        Functions\when('get_option')->alias(function (string $option, mixed $default = []): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            return $default;
        });

        $product_data = ['categories' => [['slug' => 'clothing', 'name' => 'Clothing']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertSame($product_data, $result);
    }

    public function test_apply_mappings_replaces_category_slug(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === WC_Multi_Store_Category_Mapper::OPTION_KEY) {
                return [$key => ['clothing' => 'apparel']];
            }
            return $default;
        });

        $product_data = ['categories' => [['slug' => 'clothing', 'name' => 'Clothing']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertCount(1, $result['categories']);
        $this->assertSame('apparel', $result['categories'][0]['slug']);
        $this->assertSame('Clothing', $result['categories'][0]['name']);
    }

    public function test_apply_mappings_skips_category_with_skip_value_empty_string(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === WC_Multi_Store_Category_Mapper::OPTION_KEY) {
                return [$key => ['clothing' => '']];
            }
            return $default;
        });

        $product_data = ['categories' => [['slug' => 'clothing', 'name' => 'Clothing']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertEmpty($result['categories']);
    }

    public function test_apply_mappings_skips_category_with_skip_sentinel(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === WC_Multi_Store_Category_Mapper::OPTION_KEY) {
                return [$key => ['clothing' => '__skip__']];
            }
            return $default;
        });

        $product_data = ['categories' => [['slug' => 'clothing', 'name' => 'Clothing']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertEmpty($result['categories']);
    }

    public function test_apply_mappings_passes_through_unmapped_category(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === WC_Multi_Store_Category_Mapper::OPTION_KEY) {
                return [$key => ['clothing' => 'apparel']];
            }
            return $default;
        });

        $books_cat    = ['slug' => 'books', 'name' => 'Books'];
        $product_data = ['categories' => [$books_cat]];
        $result       = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertCount(1, $result['categories']);
        $this->assertSame($books_cat, $result['categories'][0]);
    }

    public function test_apply_mappings_mixed_mapped_and_unmapped(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === WC_Multi_Store_Category_Mapper::OPTION_KEY) {
                return [$key => ['clothing' => 'apparel', 'shoes' => '__skip__']];
            }
            return $default;
        });

        $product_data = [
            'categories' => [
                ['slug' => 'clothing', 'name' => 'Clothing'],
                ['slug' => 'shoes',    'name' => 'Shoes'],
                ['slug' => 'books',    'name' => 'Books'],
            ],
        ];

        $result = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $this->store_url_a);

        $this->assertCount(2, $result['categories']);

        $slugs = array_column($result['categories'], 'slug');
        $this->assertContains('apparel', $slugs);
        $this->assertContains('books', $slugs);
        $this->assertNotContains('shoes', $slugs);
        $this->assertNotContains('clothing', $slugs);
    }

    // -------------------------------------------------------------------------
    // apply_tag_mappings()
    // -------------------------------------------------------------------------

    public function test_apply_tag_mappings_returns_unchanged_when_disabled(): void
    {
        Functions\when('get_option')->justReturn(['enabled' => false]);

        $product_data = ['tags' => [['slug' => 'sale', 'name' => 'Sale']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_tag_mappings($product_data, $this->store_url_a);

        $this->assertSame($product_data, $result);
    }

    public function test_apply_tag_mappings_uses_separate_option_key(): void
    {
        $key = md5($this->store_url_a);

        $options_read = [];
        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key, &$options_read): mixed {
            $options_read[] = $option;
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === 'wc_mss_tag_mappings') {
                return [$key => ['sale' => 'promo']];
            }
            return $default;
        });

        $product_data = ['tags' => [['slug' => 'sale', 'name' => 'Sale']]];
        WC_Multi_Store_Category_Mapper::apply_tag_mappings($product_data, $this->store_url_a);

        $this->assertContains('wc_mss_tag_mappings', $options_read);
        $this->assertNotContains(WC_Multi_Store_Category_Mapper::OPTION_KEY, $options_read);
    }

    public function test_apply_tag_mappings_skips_with_sentinel(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === 'wc_mss_tag_mappings') {
                return [$key => ['sale' => '__skip__']];
            }
            return $default;
        });

        $product_data = ['tags' => [['slug' => 'sale', 'name' => 'Sale']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_tag_mappings($product_data, $this->store_url_a);

        $this->assertEmpty($result['tags']);
    }

    public function test_apply_tag_mappings_remaps_slug(): void
    {
        $key = md5($this->store_url_a);

        Functions\when('get_option')->alias(function (string $option, mixed $default = []) use ($key): mixed {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_mapper_enabled' => true];
            }
            if ($option === 'wc_mss_tag_mappings') {
                return [$key => ['sale' => 'promo']];
            }
            return $default;
        });

        $product_data = ['tags' => [['slug' => 'sale', 'name' => 'Sale']]];
        $result       = WC_Multi_Store_Category_Mapper::apply_tag_mappings($product_data, $this->store_url_a);

        $this->assertCount(1, $result['tags']);
        $this->assertSame('promo', $result['tags'][0]['slug']);
        $this->assertSame('Sale', $result['tags'][0]['name']);
    }

    // -------------------------------------------------------------------------
    // get_tag_mappings() / set_tag_mappings()
    // -------------------------------------------------------------------------

    public function test_get_tag_mappings_returns_empty_for_unknown_store(): void
    {
        Functions\when('get_option')->justReturn([]);

        $result = WC_Multi_Store_Category_Mapper::get_tag_mappings($this->store_url_a);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_set_tag_mappings_saves_under_correct_key(): void
    {
        $expected_store_key = md5($this->store_url_a);
        $mappings           = ['sale' => 'promo'];

        Functions\when('get_option')->justReturn([]);

        $captured = null;
        Functions\when('update_option')->alias(function (string $option, mixed $value) use (&$captured): bool {
            $captured = [$option, $value];
            return true;
        });

        WC_Multi_Store_Category_Mapper::set_tag_mappings($this->store_url_a, $mappings);

        $this->assertNotNull($captured);
        $this->assertSame('wc_mss_tag_mappings', $captured[0]);
        $this->assertArrayHasKey($expected_store_key, $captured[1]);
        $this->assertSame($mappings, $captured[1][$expected_store_key]);
    }

    /**
     * Real API client with wp_remote_get() stubbed to return queued
     * responses in order. get_categories()/get_tags() are real parent-class
     * methods that call the private get() internally, so a subclass override
     * of get() would never be reached (private methods resolve statically to
     * the defining class) — stubbing at the wp_remote_get() boundary is the
     * only way to control their output from a test.
     */
    private function makeClientWithQueuedResponses(array $bodies): WC_Multi_Store_API_Client
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });
        Functions\when('wp_remote_get')->alias(function () use (&$bodies) {
            $body = array_shift($bodies);
            if ($body instanceof \WP_Error) {
                return $body;
            }
            return ['response' => ['code' => 200], 'body' => json_encode($body)];
        });

        return WC_Multi_Store_API_Client::for_store('https://store-a.example.com', [
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
    }

    // -------------------------------------------------------------------------
    // get_remote_categories() — pagination
    // -------------------------------------------------------------------------

    public function test_get_remote_categories_stops_when_fewer_than_100_returned(): void
    {
        $page1  = array_fill(0, 50, ['id' => 1, 'name' => 'Cat', 'slug' => 'cat', 'parent' => 0, 'count' => 0]);
        $client = $this->makeClientWithQueuedResponses([$page1]);

        $result = WC_Multi_Store_Category_Mapper::get_remote_categories($client);

        $this->assertCount(50, $result);
    }

    public function test_get_remote_categories_paginates_when_exactly_100_returned(): void
    {
        $page1  = array_fill(0, 100, ['id' => 1, 'name' => 'Cat', 'slug' => 'cat', 'parent' => 0, 'count' => 0]);
        $page2  = array_fill(0, 50,  ['id' => 2, 'name' => 'Dog', 'slug' => 'dog', 'parent' => 0, 'count' => 0]);
        $client = $this->makeClientWithQueuedResponses([$page1, $page2]);

        $result = WC_Multi_Store_Category_Mapper::get_remote_categories($client);

        $this->assertCount(150, $result);
    }

    public function test_get_remote_categories_handles_api_error(): void
    {
        $client = $this->makeClientWithQueuedResponses([
            new WP_Error('api_error', 'Connection refused'),
        ]);

        $result = WC_Multi_Store_Category_Mapper::get_remote_categories($client);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // get_remote_tags() — pagination
    // -------------------------------------------------------------------------

    public function test_get_remote_tags_stops_when_fewer_than_100_returned(): void
    {
        $page1  = array_fill(0, 30, ['id' => 1, 'name' => 'Sale', 'slug' => 'sale', 'count' => 0]);
        $client = $this->makeClientWithQueuedResponses([$page1]);

        $result = WC_Multi_Store_Category_Mapper::get_remote_tags($client);

        $this->assertCount(30, $result);
    }

    public function test_get_remote_tags_paginates_when_exactly_100_returned(): void
    {
        $page1  = array_fill(0, 100, ['id' => 1, 'name' => 'Sale', 'slug' => 'sale', 'count' => 0]);
        $page2  = array_fill(0, 5,   ['id' => 2, 'name' => 'New', 'slug' => 'new', 'count' => 0]);
        $client = $this->makeClientWithQueuedResponses([$page1, $page2]);

        $result = WC_Multi_Store_Category_Mapper::get_remote_tags($client);

        $this->assertCount(105, $result);
    }

    public function test_get_remote_tags_handles_api_error(): void
    {
        $client = $this->makeClientWithQueuedResponses([
            new WP_Error('api_error', 'Connection refused'),
        ]);

        $result = WC_Multi_Store_Category_Mapper::get_remote_tags($client);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // get_local_tags()
    // -------------------------------------------------------------------------

    public function test_get_local_tags_maps_terms(): void
    {
        $term = new WP_Term();
        $term->term_id = 5;
        $term->name = 'Sale';
        $term->slug = 'sale';
        $term->count = 10;
        Functions\when('get_terms')->justReturn([$term]);

        $result = WC_Multi_Store_Category_Mapper::get_local_tags();

        $this->assertSame([
            ['id' => 5, 'name' => 'Sale', 'slug' => 'sale', 'count' => 10],
        ], $result);
    }

    public function test_get_local_tags_returns_empty_on_wp_error(): void
    {
        Functions\when('get_terms')->justReturn(new WP_Error('bad_taxonomy', 'nope'));

        $this->assertSame([], WC_Multi_Store_Category_Mapper::get_local_tags());
    }

    // -------------------------------------------------------------------------
    // ajax_get_mappings()
    // -------------------------------------------------------------------------

    public function test_ajax_get_mappings_returns_category_and_tag_data_together(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_terms')->justReturn([]);

        $cat_key = md5($this->store_url_a);
        Functions\when('get_option')->alias(function ($option, $default = []) use ($cat_key) {
            return match ($option) {
                'wc_mss_category_mappings' => [$cat_key => ['clothing' => 'apparel']],
                'wc_mss_tag_mappings' => [$cat_key => ['sale' => 'promo']],
                default => $default,
            };
        });

        $_POST['store_url'] = $this->store_url_a;

        $sent = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent) {
            $sent = $data;
        });

        WC_Multi_Store_Category_Mapper::ajax_get_mappings();

        $this->assertSame(['clothing' => 'apparel'], $sent['category_mappings']);
        $this->assertSame(['sale' => 'promo'], $sent['tag_mappings']);
        $this->assertArrayHasKey('local_categories', $sent);
        $this->assertArrayHasKey('local_tags', $sent);

        unset($_POST['store_url']);
    }

    public function test_ajax_get_mappings_requires_store_url(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) {
            $error = $data;
        });

        WC_Multi_Store_Category_Mapper::ajax_get_mappings();

        $this->assertNotNull($error);
    }
}
