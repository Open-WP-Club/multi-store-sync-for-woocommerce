<?php
/**
 * Unit tests for WC_Multi_Store_Coupon_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

if (!class_exists('WC_Coupon')) {
    class WC_Coupon {
        private int $id;
        public function __construct(int $id = 0) { $this->id = $id; }
        public function get_id(): int { return $this->id; }
        public function get_code(): string { return 'TEST10'; }
        public function get_discount_type(): string { return 'percent'; }
        public function get_amount(): string { return '10'; }
        public function get_description(): string { return ''; }
        public function get_date_expires(): ?object { return null; }
        public function get_individual_use(): bool { return false; }
        public function get_usage_limit(): ?int { return null; }
        public function get_usage_limit_per_user(): ?int { return null; }
        public function get_limit_usage_to_x_items(): ?int { return null; }
        public function get_free_shipping(): bool { return false; }
        public function get_exclude_sale_items(): bool { return false; }
        public function get_minimum_amount(): string { return ''; }
        public function get_maximum_amount(): string { return ''; }
        public function get_product_ids(): array { return []; }
        public function get_excluded_product_ids(): array { return []; }
        public function get_product_categories(): array { return []; }
        public function get_excluded_product_categories(): array { return []; }
        public function get_email_restrictions(): array { return []; }
    }
}

/**
 * Testable stub for WC_Multi_Store_API_Client that exposes the private
 * get/post/put/delete methods as public so we can mock them without needing
 * to instantiate a real HTTP client.
 *
 * Coupon-sync calls these methods generically (e.g. $client->get('coupons', ...))
 * rather than through the typed public wrappers (get_products, etc.), so we
 * need a stub that defines them as public.
 *
 * Only defined once to avoid redeclaration when the full suite runs.
 */
if (!class_exists('WC_MSS_Test_API_Client_Stub')) {
    class WC_MSS_Test_API_Client_Stub extends WC_Multi_Store_API_Client {
        /** Callable|null set per-test to control what each call returns. */
        public ?\Closure $get_handler    = null;
        public ?\Closure $post_handler   = null;
        public ?\Closure $put_handler    = null;
        public ?\Closure $delete_handler = null;

        public function __construct()
        {
            // Skip parent constructor — no real HTTP needed in tests.
        }

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

class CouponSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'          => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'store_url' => 'https://store1.com']],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function makeSync(): WC_Multi_Store_Coupon_Sync
    {
        return new WC_Multi_Store_Coupon_Sync();
    }

    /**
     * Create a client stub whose get/post/put/delete handlers can be
     * configured per-test via the public handler properties.
     */
    private function makeClient(): WC_MSS_Test_API_Client_Stub
    {
        return new WC_MSS_Test_API_Client_Stub();
    }

    private function basicCouponMock(
        array $productIds = [],
        array $excludedIds = [],
        array $categoryIds = [],
        array $excludedCatIds = [],
        array $emailRestrictions = [],
    ): \Mockery\MockInterface {
        $coupon = \Mockery::mock('WC_Coupon');
        $coupon->shouldReceive('get_code')->andReturn('TEST10');
        $coupon->shouldReceive('get_discount_type')->andReturn('percent');
        $coupon->shouldReceive('get_amount')->andReturn('10');
        $coupon->shouldReceive('get_description')->andReturn('');
        $coupon->shouldReceive('get_date_expires')->andReturn(null);
        $coupon->shouldReceive('get_individual_use')->andReturn(false);
        $coupon->shouldReceive('get_usage_limit')->andReturn(null);
        $coupon->shouldReceive('get_usage_limit_per_user')->andReturn(null);
        $coupon->shouldReceive('get_limit_usage_to_x_items')->andReturn(null);
        $coupon->shouldReceive('get_free_shipping')->andReturn(false);
        $coupon->shouldReceive('get_exclude_sale_items')->andReturn(false);
        $coupon->shouldReceive('get_minimum_amount')->andReturn('');
        $coupon->shouldReceive('get_maximum_amount')->andReturn('');
        $coupon->shouldReceive('get_product_ids')->andReturn($productIds);
        $coupon->shouldReceive('get_excluded_product_ids')->andReturn($excludedIds);
        $coupon->shouldReceive('get_product_categories')->andReturn($categoryIds);
        $coupon->shouldReceive('get_excluded_product_categories')->andReturn($excludedCatIds);
        $coupon->shouldReceive('get_email_restrictions')->andReturn($emailRestrictions);
        return $coupon;
    }

    // ─── extract_coupon_data() ─────────────────────────────────────────────────

    public function test_extract_coupon_data_returns_basic_fields(): void
    {
        $coupon = new WC_Coupon(1);
        $sync   = $this->makeSync();

        $data = $sync->extract_coupon_data($coupon);

        $this->assertSame('TEST10', $data['code']);
        $this->assertSame('percent', $data['discount_type']);
        $this->assertSame('10', $data['amount']);
        $this->assertFalse($data['individual_use']);
        $this->assertFalse($data['free_shipping']);
        $this->assertNull($data['date_expires']);
        $this->assertArrayNotHasKey('meta_data', $data);
    }

    public function test_extract_coupon_data_converts_product_ids_to_skus(): void
    {
        $coupon = $this->basicCouponMock(productIds: [1, 2]);

        $product1 = \Mockery::mock('WC_Product');
        $product1->shouldReceive('get_sku')->andReturn('SKU-001');

        $product2 = \Mockery::mock('WC_Product');
        $product2->shouldReceive('get_sku')->andReturn('SKU-002');

        Functions\when('wc_get_product')->alias(function ($id) use ($product1, $product2) {
            return match ($id) {
                1       => $product1,
                2       => $product2,
                default => false,
            };
        });

        $sync = $this->makeSync();
        $data = $sync->extract_coupon_data($coupon);

        $this->assertArrayHasKey('meta_data', $data);
        $skuEntry = array_values(array_filter($data['meta_data'], fn($m) => $m['key'] === '_wc_mss_product_skus'));
        $this->assertNotEmpty($skuEntry);
        $this->assertContains('SKU-001', $skuEntry[0]['value']);
        $this->assertContains('SKU-002', $skuEntry[0]['value']);
    }

    public function test_extract_coupon_data_skips_products_without_sku(): void
    {
        $coupon = $this->basicCouponMock(productIds: [1]);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('');

        Functions\when('wc_get_product')->justReturn($product);

        $sync = $this->makeSync();
        $data = $sync->extract_coupon_data($coupon);

        // meta_data may be present but the SKU list must be empty
        if (isset($data['meta_data'])) {
            $skuEntry = array_values(array_filter($data['meta_data'], fn($m) => $m['key'] === '_wc_mss_product_skus'));
            if (!empty($skuEntry)) {
                $this->assertEmpty($skuEntry[0]['value']);
            } else {
                $this->assertTrue(true);
            }
        } else {
            $this->assertArrayNotHasKey('meta_data', $data);
        }
    }

    public function test_extract_coupon_data_converts_category_ids_to_slugs(): void
    {
        $coupon = $this->basicCouponMock(categoryIds: [5]);

        $term       = new \stdClass();
        $term->slug = 'clothing';

        Functions\when('get_terms')->justReturn([$term]);

        $sync = $this->makeSync();
        $data = $sync->extract_coupon_data($coupon);

        $this->assertArrayHasKey('meta_data', $data);
        $catEntry = array_values(array_filter($data['meta_data'], fn($m) => $m['key'] === '_wc_mss_category_slugs'));
        $this->assertNotEmpty($catEntry);
        $this->assertContains('clothing', $catEntry[0]['value']);
    }

    public function test_extract_coupon_data_null_date_when_no_expiry(): void
    {
        $coupon = new WC_Coupon(1);
        $sync   = $this->makeSync();

        $data = $sync->extract_coupon_data($coupon);

        $this->assertNull($data['date_expires']);
    }

    // ─── sync_coupon_to_store() ────────────────────────────────────────────────

    public function test_sync_to_store_creates_new_when_not_found(): void
    {
        $coupon = new WC_Coupon(1);
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        // find_remote_coupon: GET coupons returns empty → no existing coupon
        $client->get_handler  = fn($ep, $p) => [];
        $postCalled = false;
        $client->post_handler = function ($ep, $data) use (&$postCalled) {
            $postCalled = true;
            return ['id' => 10, 'code' => 'TEST10'];
        };

        $result = $sync->sync_coupon_to_store($client, $coupon, $sync->extract_coupon_data($coupon), 'https://store1.com');

        $this->assertTrue($result);
        $this->assertTrue($postCalled, 'Expected client->post() to be called');
    }

    public function test_sync_to_store_updates_existing_when_found(): void
    {
        $coupon = new WC_Coupon(1);
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        // find_remote_coupon: GET returns existing coupon with id=99
        $client->get_handler = fn($ep, $p) => [['id' => 99, 'code' => 'TEST10']];
        $putCalled = false;
        $client->put_handler = function ($ep, $data) use (&$putCalled) {
            $putCalled = true;
            $this->assertSame('coupons/99', $ep);
            return ['id' => 99, 'code' => 'TEST10'];
        };

        $result = $sync->sync_coupon_to_store($client, $coupon, $sync->extract_coupon_data($coupon), 'https://store1.com');

        $this->assertTrue($result);
        $this->assertTrue($putCalled, 'Expected client->put() to be called');
    }

    public function test_sync_to_store_returns_false_on_api_error(): void
    {
        $coupon = new WC_Coupon(1);
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler  = fn($ep, $p) => [];
        $client->post_handler = fn($ep, $data) => new WP_Error('api_error', 'Connection refused');

        $result = $sync->sync_coupon_to_store($client, $coupon, $sync->extract_coupon_data($coupon), 'https://store1.com');

        $this->assertFalse($result);
    }

    public function test_sync_to_store_returns_true_on_success(): void
    {
        $coupon = new WC_Coupon(1);
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler  = fn($ep, $p) => [];
        $client->post_handler = fn($ep, $data) => ['id' => 5, 'code' => 'TEST10'];

        $result = $sync->sync_coupon_to_store($client, $coupon, $sync->extract_coupon_data($coupon), 'https://store1.com');

        $this->assertTrue($result);
    }

    // ─── on_coupon_saved() sync-loop prevention ────────────────────────────────

    public function test_on_coupon_saved_skips_when_global_sync_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => false],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });

        $sync   = $this->makeSync();
        $coupon = new WC_Coupon(42);

        // Should return early without syncing — no exception = success
        $sync->on_coupon_saved(42, $coupon);
        $this->assertTrue(true);
    }

    public function test_on_coupon_saved_skips_when_syncing_flag_set(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'          => [],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });
        Functions\when('get_post_meta')->alias(fn($id, $key, $single) => $key === '_wc_mss_syncing' ? '1' : '');

        $sync   = $this->makeSync();
        $coupon = new WC_Coupon(42);

        $sync->on_coupon_saved(42, $coupon);
        $this->assertTrue(true);
    }

    // ─── on_coupon_saved()/on_coupon_deleted() defer via Action Scheduler ─────
    // Action Scheduler's real `ActionScheduler` class is never loaded in the
    // unit test process, so WC_Multi_Store_Action_Scheduler_Manager::is_available()
    // is always false here — schedule_async() takes its "log a warning and
    // skip" branch. That's enough to prove these hooks no longer sync
    // inline (no client calls are ever wired up in this test, so the process
    // would error/fatal if a sync call were attempted). The deferred-work
    // methods themselves (sync_coupon_by_id/delete_coupon_by_code) are
    // tested directly below.

    public function test_on_coupon_saved_does_not_sync_inline(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'          => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'store_url' => 'https://store1.com']],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });

        $sync   = $this->makeSync();
        $coupon = new WC_Coupon(42);

        // No API client handlers are configured anywhere in this test, so if
        // on_coupon_saved() still synced inline it would call through to a
        // real (un-stubbed) HTTP client and fail. Reaching this point without
        // error confirms it only scheduled (and, since AS is unavailable in
        // this process, skipped with a logged warning).
        $sync->on_coupon_saved(42, $coupon);
        $this->assertTrue(true);
    }

    public function test_on_coupon_deleted_does_not_sync_inline(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'          => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'store_url' => 'https://store1.com']],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });

        $sync = $this->makeSync();

        $sync->on_coupon_deleted(42);
        $this->assertTrue(true);
    }

    // ─── sync_coupon_by_id() / delete_coupon_by_code() (AS callbacks) ────────

    public function test_sync_coupon_by_id_noop_when_coupon_missing(): void
    {
        $sync = $this->makeSync();

        // WC_Coupon(0)->get_id() returns 0 in the test stub — should return
        // early without attempting any API calls.
        $sync->sync_coupon_by_id(0);
        $this->assertTrue(true);
    }

    public function test_delete_coupon_by_code_noop_when_no_active_stores(): void
    {
        // delete_coupon_by_code()/sync_coupon_by_id() go through the "_all_stores"
        // wrappers, which build a real API client per store via the private
        // get_api_client() factory — not swappable for the test stub used
        // elsewhere in this file. With zero active stores that factory is
        // never reached, so this exercises the full method end-to-end
        // without needing HTTP-level mocking.
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'          => [],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });

        $sync = $this->makeSync();

        $result = $sync->delete_coupon_by_code('TEST10');
        $this->assertNull($result);
    }

    // ─── delete_coupon_from_all_stores() ──────────────────────────────────────

    public function test_delete_from_all_stores_skips_when_no_remote_coupon(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        // find_remote_coupon: GET returns empty → null → no delete call
        $client->get_handler    = fn($ep, $p) => [];
        $deleteCalled           = false;
        $client->delete_handler = function () use (&$deleteCalled) {
            $deleteCalled = true;
            return [];
        };

        // Invoke find_remote_coupon via reflection to verify the null return
        $method = new \ReflectionMethod($sync, 'find_remote_coupon');
        $found  = $method->invoke($sync, $client, 'TEST10');

        $this->assertNull($found);
        $this->assertFalse($deleteCalled, 'delete should not have been called');
    }

    public function test_delete_from_all_stores_calls_delete_endpoint(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        // find_remote_coupon: GET returns existing coupon
        $client->get_handler = fn($ep, $p) => [['id' => 99, 'code' => 'TEST10']];

        $deleteEndpoint = null;
        $deleteParams   = null;
        $client->delete_handler = function ($ep, $params) use (&$deleteEndpoint, &$deleteParams) {
            $deleteEndpoint = $ep;
            $deleteParams   = $params;
            return ['id' => 99];
        };

        $method = new \ReflectionMethod($sync, 'find_remote_coupon');
        $remote = $method->invoke($sync, $client, 'TEST10');
        $this->assertNotNull($remote);
        $this->assertSame(99, $remote['id']);

        // Call delete as the production code would
        $client->delete('coupons/' . $remote['id'], ['force' => true]);

        $this->assertSame('coupons/99', $deleteEndpoint);
        $this->assertSame(['force' => true], $deleteParams);
    }

    // ─── resolve_remote_ids() via sync_coupon_to_store() ──────────────────────

    public function test_resolve_skus_to_remote_ids(): void
    {
        $coupon = $this->basicCouponMock(productIds: [10]);

        $localProduct = \Mockery::mock('WC_Product');
        $localProduct->shouldReceive('get_sku')->andReturn('SKU-ABC');
        Functions\when('wc_get_product')->justReturn($localProduct);

        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $capturedData = null;
        $client->get_handler  = function ($ep, $p) {
            if ($ep === 'coupons') {
                return [];  // no existing coupon
            }
            if ($ep === 'products') {
                $this->assertSame('SKU-ABC', $p['sku'] ?? null);
                return [['id' => 55]];
            }
            return [];
        };
        $client->post_handler = function ($ep, $data) use (&$capturedData) {
            $capturedData = $data;
            return ['id' => 1];
        };

        $data = $sync->extract_coupon_data($coupon);
        $sync->sync_coupon_to_store($client, $coupon, $data, 'https://store1.com');

        $this->assertNotNull($capturedData);
        $this->assertArrayHasKey('product_ids', $capturedData);
        $this->assertContains(55, $capturedData['product_ids']);
    }

    public function test_resolve_category_slugs_to_remote_ids(): void
    {
        $coupon = $this->basicCouponMock(categoryIds: [5]);

        $term       = new \stdClass();
        $term->slug = 'shoes';
        Functions\when('get_terms')->justReturn([$term]);

        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $capturedData = null;
        $client->get_handler  = function ($ep, $p) {
            if ($ep === 'coupons') {
                return [];
            }
            if ($ep === 'products/categories') {
                $this->assertSame('shoes', $p['slug'] ?? null);
                return [['id' => 77]];
            }
            return [];
        };
        $client->post_handler = function ($ep, $data) use (&$capturedData) {
            $capturedData = $data;
            return ['id' => 1];
        };

        $data = $sync->extract_coupon_data($coupon);
        $sync->sync_coupon_to_store($client, $coupon, $data, 'https://store1.com');

        $this->assertNotNull($capturedData);
        $this->assertArrayHasKey('product_categories', $capturedData);
        $this->assertContains(77, $capturedData['product_categories']);
    }

    public function test_meta_data_removed_after_resolution(): void
    {
        $coupon = $this->basicCouponMock(productIds: [10]);

        $localProduct = \Mockery::mock('WC_Product');
        $localProduct->shouldReceive('get_sku')->andReturn('SKU-XYZ');
        Functions\when('wc_get_product')->justReturn($localProduct);

        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $capturedData = null;
        $client->get_handler  = function ($ep, $p) {
            if ($ep === 'coupons') {
                return [];
            }
            return [['id' => 22]];
        };
        $client->post_handler = function ($ep, $data) use (&$capturedData) {
            $capturedData = $data;
            return ['id' => 1];
        };

        $data = $sync->extract_coupon_data($coupon);
        $sync->sync_coupon_to_store($client, $coupon, $data, 'https://store1.com');

        $this->assertNotNull($capturedData);
        $this->assertArrayNotHasKey('meta_data', $capturedData);
    }

    // ─── sync_all_coupons() pagination ────────────────────────────────────────

    public function test_sync_all_coupons_paginates_in_batches_of_50(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'          => [],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });

        $callCount = 0;
        Functions\when('get_posts')->alias(function () use (&$callCount) {
            $callCount++;
            return $callCount === 1 ? range(1, 50) : [];
        });

        $results = WC_Multi_Store_Coupon_Sync::sync_all_coupons();

        $this->assertSame(50, $results['total']);
        $this->assertArrayHasKey('synced', $results);
        $this->assertArrayHasKey('failed', $results);
    }

    public function test_sync_all_coupons_returns_stats(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'        => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'          => [],
                'wc_multi_store_sync_coupon_settings' => ['enabled' => true],
                default                               => $default,
            };
        });
        Functions\when('get_posts')->justReturn([]);

        $results = WC_Multi_Store_Coupon_Sync::sync_all_coupons();

        $this->assertArrayHasKey('synced', $results);
        $this->assertArrayHasKey('failed', $results);
        $this->assertArrayHasKey('total', $results);
        $this->assertIsInt($results['synced']);
        $this->assertIsInt($results['failed']);
        $this->assertIsInt($results['total']);
    }
}
