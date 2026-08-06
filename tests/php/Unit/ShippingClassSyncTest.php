<?php
/**
 * Tests for WC_Multi_Store_Shipping_Class_Sync
 *
 * Covers: settings management, sync_shipping_class_to_store (POST vs PUT),
 * error handling, transient caching, delete_shipping_class_from_all_stores,
 * get_product_shipping_class_slug, and AJAX capability enforcement.
 */

use Brain\Monkey\Functions;

if (!class_exists('WP_Term')) {
    class WP_Term {
        public int $term_id      = 0;
        public string $name      = '';
        public string $slug      = '';
        public string $description = '';
    }
}

/**
 * Testable stub reusing the same pattern as CouponSyncTest.
 * Shipping-class-sync calls $client->get/post/put/delete() which are private
 * in the real client. This stub exposes them as public handler-driven methods.
 * Only defined once to avoid redeclaration when the full suite runs.
 */
if (!class_exists('WC_MSS_Test_API_Client_Stub')) {
    class WC_MSS_Test_API_Client_Stub extends WC_Multi_Store_API_Client {
        public ?\Closure $get_handler    = null;
        public ?\Closure $post_handler   = null;
        public ?\Closure $put_handler    = null;
        public ?\Closure $delete_handler = null;

        public function __construct() {}

        public function get(string $endpoint, array $params = []): array|\WP_Error
        {
            return ($this->get_handler)($endpoint, $params);
        }

        public function post(string $endpoint, array $data = []): array|\WP_Error
        {
            return ($this->post_handler)($endpoint, $data);
        }

        public function put(string $endpoint, array $data = []): array|\WP_Error
        {
            return ($this->put_handler)($endpoint, $data);
        }

        public function delete(string $endpoint, array $params = []): array|\WP_Error
        {
            return ($this->delete_handler)($endpoint, $params);
        }
    }
}

class ShippingClassSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        // Default stub: constructor calls is_enabled() → get_option(); return false so
        // constructor exits early and does not register hooks. Tests that need specific
        // option values re-register get_option with their own alias.
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    // ─── helpers ──────────────────────────────────────────────────────

    private function makeTerm(string $name = 'Heavy', string $slug = 'heavy', string $desc = ''): WP_Term
    {
        $term              = new WP_Term();
        $term->name        = $name;
        $term->slug        = $slug;
        $term->description = $desc;
        return $term;
    }

    private function makeClient(): WC_MSS_Test_API_Client_Stub
    {
        return new WC_MSS_Test_API_Client_Stub();
    }

    private function mockActiveStores(array $stores = []): void
    {
        if (empty($stores)) {
            $stores = [
                'https://child.example.com' => [
                    'status'          => 'active',
                    'consumer_key'    => 'ck_test',
                    'consumer_secret' => 'cs_test',
                ],
            ];
        }

        Functions\when('get_option')->alias(function ($key, $default = false) use ($stores) {
            return match ($key) {
                'wc_multi_store_sync_settings' => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'   => $stores,
                default                        => $default,
            };
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // Settings
    // ═══════════════════════════════════════════════════════════════

    public function test_is_enabled_returns_false_by_default(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);

        $this->assertFalse(WC_Multi_Store_Shipping_Class_Sync::is_enabled());
    }

    public function test_is_enabled_returns_true_when_option_set(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === WC_Multi_Store_Shipping_Class_Sync::SETTINGS_KEY) {
                return ['enabled' => true];
            }
            return $default;
        });

        $this->assertTrue(WC_Multi_Store_Shipping_Class_Sync::is_enabled());
    }

    public function test_get_settings_returns_defaults_on_fresh_install(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);

        $settings = WC_Multi_Store_Shipping_Class_Sync::get_settings();

        $this->assertFalse($settings['enabled']);
        $this->assertTrue($settings['auto_sync_on_change']);
    }

    public function test_update_settings_merges_with_existing(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === WC_Multi_Store_Shipping_Class_Sync::SETTINGS_KEY) {
                return ['enabled' => false, 'auto_sync_on_change' => true];
            }
            return $default;
        });

        $saved = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved) {
            if ($key === WC_Multi_Store_Shipping_Class_Sync::SETTINGS_KEY) {
                $saved = $value;
            }
            return true;
        });

        WC_Multi_Store_Shipping_Class_Sync::update_settings(['enabled' => true]);

        $this->assertTrue($saved['enabled']);
        $this->assertTrue($saved['auto_sync_on_change'], 'Existing keys must be preserved on merge');
    }

    // ═══════════════════════════════════════════════════════════════
    // sync_shipping_class_to_store
    // ═══════════════════════════════════════════════════════════════

    public function test_sync_to_store_posts_when_no_existing_remote_class(): void
    {
        $term   = $this->makeTerm('Heavy', 'heavy', 'Heavy items');
        $client = $this->makeClient();

        $client->get_handler  = fn($ep, $p) => [];
        $posted_data = null;
        $client->post_handler = function ($ep, $data) use (&$posted_data) {
            $posted_data = ['endpoint' => $ep, 'data' => $data];
            return ['id' => 5, 'slug' => 'heavy'];
        };

        $sync   = new WC_Multi_Store_Shipping_Class_Sync();
        $result = $sync->sync_shipping_class_to_store($client, $term, 'https://child.example.com');

        $this->assertTrue($result);
        $this->assertSame('products/shipping_classes', $posted_data['endpoint']);
        $this->assertSame('heavy', $posted_data['data']['slug']);
        $this->assertSame('Heavy items', $posted_data['data']['description']);
    }

    public function test_sync_to_store_puts_when_class_already_exists_remotely(): void
    {
        $term   = $this->makeTerm('Heavy', 'heavy');
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => [
            ['id' => 9, 'slug' => 'heavy', 'name' => 'Heavy (old)'],
        ];

        $put_called_with = null;
        $client->put_handler = function ($ep, $data) use (&$put_called_with) {
            $put_called_with = $ep;
            return ['id' => 9, 'slug' => 'heavy'];
        };

        $sync   = new WC_Multi_Store_Shipping_Class_Sync();
        $result = $sync->sync_shipping_class_to_store($client, $term, 'https://child.example.com');

        $this->assertTrue($result);
        $this->assertSame('products/shipping_classes/9', $put_called_with);
    }

    public function test_sync_to_store_returns_false_on_api_error(): void
    {
        $term   = $this->makeTerm();
        $client = $this->makeClient();

        $client->get_handler  = fn($ep, $p) => [];
        $client->post_handler = fn($ep, $d) => new WP_Error('http_error', 'Connection timeout');

        $sync   = new WC_Multi_Store_Shipping_Class_Sync();
        $result = $sync->sync_shipping_class_to_store($client, $term, 'https://child.example.com');

        $this->assertFalse($result);
    }

    public function test_sync_to_store_clears_transient_cache_on_success(): void
    {
        $store_url = 'https://child.example.com';
        $cache_key = WC_Multi_Store_Shipping_Class_Sync::CACHE_PREFIX . md5($store_url);
        $term      = $this->makeTerm('Light', 'light');
        $client    = $this->makeClient();

        $client->get_handler  = fn($ep, $p) => [];
        $client->post_handler = fn($ep, $d) => ['id' => 3, 'slug' => 'light'];

        $deleted_keys = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted_keys) {
            $deleted_keys[] = $key;
            return true;
        });

        $sync = new WC_Multi_Store_Shipping_Class_Sync();
        $sync->sync_shipping_class_to_store($client, $term, $store_url);

        $this->assertContains($cache_key, $deleted_keys,
            'Transient cache must be cleared for the store after successful sync');
    }

    public function test_sync_to_store_uses_cached_remote_classes_without_api_call(): void
    {
        $store_url = 'https://child.example.com';
        $cache_key = WC_Multi_Store_Shipping_Class_Sync::CACHE_PREFIX . md5($store_url);
        $term      = $this->makeTerm('Fragile', 'fragile');
        $client    = $this->makeClient();

        Functions\when('get_transient')->alias(function ($key) use ($cache_key) {
            return $key === $cache_key
                ? [['id' => 7, 'slug' => 'fragile', 'name' => 'Fragile']]
                : false;
        });

        // Cache hit: get() must NOT be called
        $get_called = false;
        $client->get_handler = function () use (&$get_called) {
            $get_called = true;
            return [];
        };

        $client->put_handler = fn($ep, $d) => ['id' => 7];

        $sync = new WC_Multi_Store_Shipping_Class_Sync();
        $sync->sync_shipping_class_to_store($client, $term, $store_url);

        $this->assertFalse($get_called, 'API must not be called when transient cache has data');
    }

    // ═══════════════════════════════════════════════════════════════
    // delete_shipping_class_from_all_stores
    // ═══════════════════════════════════════════════════════════════

    public function test_delete_from_all_stores_returns_empty_when_no_active_stores(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        WC_Multi_Store_Settings::clear_static_cache();

        $term   = $this->makeTerm('Heavy', 'heavy');
        $sync   = new WC_Multi_Store_Shipping_Class_Sync();
        $result = $sync->delete_shipping_class_from_all_stores($term);

        $this->assertEmpty($result);
    }

    public function test_sync_to_all_stores_returns_empty_when_no_active_stores(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        WC_Multi_Store_Settings::clear_static_cache();

        $term   = $this->makeTerm();
        $sync   = new WC_Multi_Store_Shipping_Class_Sync();
        $result = $sync->sync_shipping_class_to_all_stores($term);

        $this->assertEmpty($result);
    }

    // ═══════════════════════════════════════════════════════════════
    // on_shipping_class_created/edited/deleted defer via Action Scheduler
    // instead of syncing inline. As in CouponSyncTest, the real
    // `ActionScheduler` class is never loaded in the unit test process, so
    // WC_Multi_Store_Action_Scheduler_Manager::is_available() is always
    // false here and schedule_async() takes its "log + skip" branch — which
    // is enough to prove these hooks no longer call the remote API inline
    // (no client handlers are configured in these tests, so an inline call
    // would error against a real un-stubbed HTTP client).
    // ═══════════════════════════════════════════════════════════════

    private function enableAutoSync(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return match ($key) {
                WC_Multi_Store_Shipping_Class_Sync::SETTINGS_KEY => ['enabled' => true, 'auto_sync_on_change' => true],
                'wc_multi_store_sync_settings'                   => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'                     => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'store_url' => 'https://store1.com']],
                default                                           => $default,
            };
        });
        WC_Multi_Store_Settings::clear_static_cache();
    }

    public function test_on_shipping_class_created_does_not_sync_inline(): void
    {
        $this->enableAutoSync();
        Functions\when('get_term')->justReturn($this->makeTerm());

        $sync = new WC_Multi_Store_Shipping_Class_Sync();
        $sync->on_shipping_class_created(5, 5);

        $this->assertTrue(true);
    }

    public function test_on_shipping_class_edited_does_not_sync_inline(): void
    {
        $this->enableAutoSync();
        Functions\when('get_term')->justReturn($this->makeTerm());

        $sync = new WC_Multi_Store_Shipping_Class_Sync();
        $sync->on_shipping_class_edited(5, 5);

        $this->assertTrue(true);
    }

    public function test_on_shipping_class_deleted_does_not_sync_inline(): void
    {
        $this->enableAutoSync();

        $sync = new WC_Multi_Store_Shipping_Class_Sync();
        $sync->on_shipping_class_deleted(5, 5, $this->makeTerm('Heavy', 'heavy'), [10, 20]);

        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════════════════════════
    // sync_shipping_class_by_term_id() / delete_shipping_class_by_data()
    // (Action Scheduler callbacks)
    // ═══════════════════════════════════════════════════════════════

    public function test_sync_shipping_class_by_term_id_noop_when_term_missing(): void
    {
        $this->enableAutoSync();
        Functions\when('get_term')->justReturn(null);

        $sync = new WC_Multi_Store_Shipping_Class_Sync();
        $sync->sync_shipping_class_by_term_id(999);

        $this->assertTrue(true);
    }

    public function test_delete_shipping_class_by_data_noop_when_no_active_stores(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        WC_Multi_Store_Settings::clear_static_cache();

        $sync   = new WC_Multi_Store_Shipping_Class_Sync();
        $result = $sync->delete_shipping_class_by_data('Heavy', 'heavy');

        $this->assertNull($result);
    }

    public function test_delete_shipping_class_from_all_stores_by_data_matches_by_term_wrapper(): void
    {
        // delete_shipping_class_from_all_stores(WP_Term $term) must now be a
        // thin wrapper around delete_shipping_class_from_all_stores_by_data() —
        // both should behave identically for the same name/slug.
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        WC_Multi_Store_Settings::clear_static_cache();

        $sync = new WC_Multi_Store_Shipping_Class_Sync();
        $term = $this->makeTerm('Heavy', 'heavy');

        $viaTerm = $sync->delete_shipping_class_from_all_stores($term);
        $viaData = $sync->delete_shipping_class_from_all_stores_by_data('Heavy', 'heavy');

        $this->assertSame($viaTerm, $viaData);
    }

    // ═══════════════════════════════════════════════════════════════
    // get_product_shipping_class_slug
    // ═══════════════════════════════════════════════════════════════

    public function test_get_product_shipping_class_slug_returns_empty_when_no_shipping_class(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $slug = WC_Multi_Store_Shipping_Class_Sync::get_product_shipping_class_slug($product);

        $this->assertSame('', $slug);
    }

    public function test_get_product_shipping_class_slug_returns_slug_from_term(): void
    {
        $term          = $this->makeTerm('Heavy', 'heavy');
        $term->term_id = 12;

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_shipping_class_id')->andReturn(12);

        Functions\when('get_term')->alias(function ($id, $taxonomy) use ($term) {
            return ($id === 12 && $taxonomy === 'product_shipping_class') ? $term : null;
        });

        $slug = WC_Multi_Store_Shipping_Class_Sync::get_product_shipping_class_slug($product);

        $this->assertSame('heavy', $slug);
    }

    public function test_get_product_shipping_class_slug_returns_empty_on_wp_error(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_shipping_class_id')->andReturn(99);

        Functions\when('get_term')->justReturn(new WP_Error('invalid', 'Term not found'));

        $slug = WC_Multi_Store_Shipping_Class_Sync::get_product_shipping_class_slug($product);

        $this->assertSame('', $slug);
    }

    // ═══════════════════════════════════════════════════════════════
    // AJAX handlers — capability enforcement
    // ═══════════════════════════════════════════════════════════════

    public function test_ajax_toggle_sends_error_when_user_lacks_capability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('__')->alias(fn($t) => $t);

        $error_sent = false;
        Functions\when('wp_send_json_error')->alias(function () use (&$error_sent) {
            $error_sent = true;
        });

        $_POST = ['nonce' => 'abc', 'enabled' => '1'];
        WC_Multi_Store_Shipping_Class_Sync::ajax_toggle();

        $this->assertTrue($error_sent, 'wp_send_json_error must be called when user lacks capability');
    }

    public function test_ajax_sync_all_sends_error_when_user_lacks_capability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('__')->alias(fn($t) => $t);

        $error_sent = false;
        Functions\when('wp_send_json_error')->alias(function () use (&$error_sent) {
            $error_sent = true;
        });

        $_POST = ['nonce' => 'abc'];
        WC_Multi_Store_Shipping_Class_Sync::ajax_sync_all();

        $this->assertTrue($error_sent, 'wp_send_json_error must be called when user lacks capability');
    }

    public function test_ajax_toggle_saves_enabled_state_and_sends_success(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('__')->alias(fn($t) => $t);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === WC_Multi_Store_Shipping_Class_Sync::SETTINGS_KEY) {
                return ['enabled' => false, 'auto_sync_on_change' => true];
            }
            return $default;
        });

        $saved_settings = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved_settings) {
            if ($key === WC_Multi_Store_Shipping_Class_Sync::SETTINGS_KEY) {
                $saved_settings = $value;
            }
            return true;
        });

        $success_sent = false;
        Functions\when('wp_send_json_success')->alias(function () use (&$success_sent) {
            $success_sent = true;
        });

        $_POST = ['nonce' => 'abc', 'enabled' => '1'];
        WC_Multi_Store_Shipping_Class_Sync::ajax_toggle();

        $this->assertTrue($success_sent);
        $this->assertNotNull($saved_settings);
        $this->assertTrue($saved_settings['enabled']);
    }
}
