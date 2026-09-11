<?php
/**
 * Unit tests for WC_Multi_Store_Attribute_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

// WP_Term is already stubbed (plain public properties, no constructor) by
// several other test files in this suite (e.g. CategoryMapperTest); reuse
// that shared shape instead of redeclaring it with a different signature.
if (!class_exists('WP_Term')) {
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

if (!function_exists('wc_mss_test_make_term')) {
    function wc_mss_test_make_term(array $props = []): WP_Term {
        $term = new WP_Term();
        $term->term_id = $props['term_id'] ?? 1;
        $term->name = $props['name'] ?? 'Red';
        $term->slug = $props['slug'] ?? 'red';
        $term->description = $props['description'] ?? '';
        $term->taxonomy = $props['taxonomy'] ?? 'pa_color';
        return $term;
    }
}

/**
 * Same rationale as the API client stubs in CouponSyncTest/ReviewSyncTest:
 * exposes the private get/post/put/delete methods as public, mockable per
 * test without needing a real HTTP client.
 */
if (!class_exists('WC_MSS_Attribute_Test_API_Client_Stub')) {
    class WC_MSS_Attribute_Test_API_Client_Stub extends WC_Multi_Store_API_Client {
        public ?\Closure $get_handler    = null;
        public ?\Closure $post_handler   = null;
        public ?\Closure $put_handler    = null;
        public ?\Closure $delete_handler = null;

        public function __construct() {}

        public function get(string $endpoint, array $params = []): array|\WP_Error {
            return ($this->get_handler)($endpoint, $params);
        }

        public function post(string $endpoint, array $data = []): array|\WP_Error {
            return ($this->post_handler)($endpoint, $data);
        }

        public function put(string $endpoint, array $data = []): array|\WP_Error {
            return ($this->put_handler)($endpoint, $data);
        }

        public function delete(string $endpoint, array $params = []): array|\WP_Error {
            return ($this->delete_handler)($endpoint, $params);
        }
    }
}

class AttributeSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'      => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'        => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'store_url' => 'https://store1.com']],
                'wc_mss_attribute_sync_settings'    => ['enabled' => true],
                default                              => $default,
            };
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('wc_attribute_taxonomy_slug')->alias(fn($tax) => str_starts_with($tax, 'pa_') ? substr($tax, 3) : $tax);
    }

    private function makeSync(): WC_Multi_Store_Attribute_Sync
    {
        return new WC_Multi_Store_Attribute_Sync();
    }

    private function makeClient(): WC_MSS_Attribute_Test_API_Client_Stub
    {
        return new WC_MSS_Attribute_Test_API_Client_Stub();
    }

    private function localAttribute(): object
    {
        return (object) [
            'attribute_id' => 5,
            'attribute_name' => 'color',
            'attribute_label' => 'Color',
            'attribute_type' => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public' => 0,
        ];
    }

    // ─── extract_attribute_data() ──────────────────────────────────────────

    public function test_extract_attribute_data_maps_fields(): void
    {
        $data = $this->makeSync()->extract_attribute_data($this->localAttribute());

        $this->assertSame('Color', $data['name']);
        $this->assertSame('color', $data['slug']);
        $this->assertSame('select', $data['type']);
        $this->assertSame('menu_order', $data['order_by']);
        $this->assertFalse($data['has_archives']);
    }

    // ─── sync_attribute_to_store() ─────────────────────────────────────────

    public function test_sync_attribute_to_store_creates_when_not_found(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => [];
        $postCalled = false;
        $client->post_handler = function ($ep, $data) use (&$postCalled) {
            $postCalled = true;
            $this->assertSame('products/attributes', $ep);
            return ['id' => 10];
        };

        $data = $sync->extract_attribute_data($this->localAttribute());
        $result = $sync->sync_attribute_to_store($client, $data);

        $this->assertTrue($result);
        $this->assertTrue($postCalled);
    }

    public function test_sync_attribute_to_store_updates_existing_when_found(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => [['id' => 20, 'slug' => 'color']];
        $putCalled = false;
        $client->put_handler = function ($ep, $data) use (&$putCalled) {
            $putCalled = true;
            $this->assertSame('products/attributes/20', $ep);
            return ['id' => 20];
        };

        $data = $sync->extract_attribute_data($this->localAttribute());
        $result = $sync->sync_attribute_to_store($client, $data);

        $this->assertTrue($result);
        $this->assertTrue($putCalled);
    }

    public function test_sync_attribute_to_store_returns_false_on_api_error(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler  = fn($ep, $p) => [];
        $client->post_handler = fn($ep, $data) => new WP_Error('api_error', 'Connection refused');

        $data = $sync->extract_attribute_data($this->localAttribute());
        $result = $sync->sync_attribute_to_store($client, $data);

        $this->assertFalse($result);
    }

    // ─── extract_term_data() ───────────────────────────────────────────────

    public function test_extract_term_data_maps_fields(): void
    {
        $term = wc_mss_test_make_term(['name' => 'Red', 'slug' => 'red', 'description' => 'Red color']);
        $data = $this->makeSync()->extract_term_data($term);

        $this->assertSame('Red', $data['name']);
        $this->assertSame('red', $data['slug']);
        $this->assertSame('Red color', $data['description']);
    }

    // ─── sync_term_to_store() ──────────────────────────────────────────────

    public function test_sync_term_to_store_skips_when_attribute_not_found_remotely(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => [];

        $data = $sync->extract_term_data(wc_mss_test_make_term());
        $result = $sync->sync_term_to_store($client, 'color', $data);

        $this->assertFalse($result);
    }

    public function test_sync_term_to_store_creates_when_not_found(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => match (true) {
            $ep === 'products/attributes'              => [['id' => 5, 'slug' => 'color']],
            $ep === 'products/attributes/5/terms'       => [],
            default                                     => [],
        };
        $postCalled = false;
        $client->post_handler = function ($ep, $data) use (&$postCalled) {
            $postCalled = true;
            $this->assertSame('products/attributes/5/terms', $ep);
            return ['id' => 30];
        };

        $data = $sync->extract_term_data(wc_mss_test_make_term());
        $result = $sync->sync_term_to_store($client, 'color', $data);

        $this->assertTrue($result);
        $this->assertTrue($postCalled);
    }

    public function test_sync_term_to_store_updates_existing_when_found(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => match (true) {
            $ep === 'products/attributes'         => [['id' => 5, 'slug' => 'color']],
            $ep === 'products/attributes/5/terms' => [['id' => 40, 'slug' => 'red']],
            default                                => [],
        };
        $putCalled = false;
        $client->put_handler = function ($ep, $data) use (&$putCalled) {
            $putCalled = true;
            $this->assertSame('products/attributes/5/terms/40', $ep);
            return ['id' => 40];
        };

        $data = $sync->extract_term_data(wc_mss_test_make_term());
        $result = $sync->sync_term_to_store($client, 'color', $data);

        $this->assertTrue($result);
        $this->assertTrue($putCalled);
    }

    // ─── on_term_saved()/on_term_deleted(): filter to pa_* taxonomies ──────

    public function test_on_term_saved_ignores_non_attribute_taxonomy(): void
    {
        $sync = $this->makeSync();

        // No API client handlers configured anywhere — reaching this point
        // without a fatal proves the non-pa_* taxonomy was filtered out
        // before scheduling any sync work.
        $sync->on_term_saved(1, 1, 'product_cat');
        $this->assertTrue(true);
    }

    public function test_on_term_deleted_ignores_non_attribute_taxonomy(): void
    {
        $sync = $this->makeSync();
        $term = wc_mss_test_make_term(['taxonomy' => 'product_cat']);

        $sync->on_term_deleted(1, 1, 'product_cat', $term);
        $this->assertTrue(true);
    }

    public function test_on_term_deleted_ignores_non_wp_term_payload(): void
    {
        $sync = $this->makeSync();

        // Older/edge-case callers can pass something other than a WP_Term;
        // must not attempt to schedule work off it.
        $sync->on_term_deleted(1, 1, 'pa_color', null);
        $this->assertTrue(true);
    }

    // ─── ajax_toggle() ──────────────────────────────────────────────────────

    public function test_ajax_toggle_enables_and_persists(): void
    {
        $_POST['enabled'] = '1';
        $saved = null;
        Functions\when('update_option')->alias(function ($opt, $value) use (&$saved) {
            $saved = [$opt, $value];
            return true;
        });
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(function ($data) {
            $this->assertTrue($data['enabled']);
        });

        WC_Multi_Store_Attribute_Sync::ajax_toggle();

        $this->assertSame('wc_mss_attribute_sync_settings', $saved[0]);
        $this->assertTrue($saved[1]['enabled']);

        unset($_POST['enabled']);
    }
}
