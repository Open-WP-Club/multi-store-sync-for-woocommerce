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

/** API client with per-test handlers instead of HTTP requests. */
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
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
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

        Functions\when('wc_get_products')->justReturn([$product1, $product2]);

        $sync = $this->makeSync();
        $data = $sync->extract_coupon_data($coupon);

        $this->assertArrayHasKey('meta_data', $data);
        $skuEntry = array_values(array_filter($data['meta_data'], fn($m) => $m['key'] === '_wc_mss_product_skus'));
        $this->assertNotEmpty($skuEntry);
        $this->assertContains('SKU-001', $skuEntry[0]['value']);
        $this->assertContains('SKU-002', $skuEntry[0]['value']);
    }

    public function test_extract_coupon_data_rejects_products_without_sku(): void
    {
        $coupon = $this->basicCouponMock(productIds: [1]);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('');

        Functions\when('wc_get_products')->justReturn([$product]);

        $sync = $this->makeSync();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve every coupon product restriction');
        $sync->extract_coupon_data($coupon);
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

    // ─── sync_coupon_by_id() / delete_coupon_by_code() (AS callbacks) ────────

    public function test_sync_coupon_by_id_noop_when_coupon_missing(): void
    {
        $sync = $this->makeSync();

        // WC_Coupon(0)->get_id() returns 0 in the test stub — should return
        // early without attempting any API calls.
        Functions\expect('wp_remote_get')->never();
        Functions\expect('as_schedule_single_action')->never();
        $this->assertNull($sync->sync_coupon_by_id(0));
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

    // ─── delete_coupon_from_all_stores_by_code() ────────────────────────────────

    public function test_delete_from_all_stores_skips_when_no_remote_coupon(): void
    {
        Functions\expect('wp_remote_get')->once()->andReturn(['response' => ['code' => 200], 'body' => '[]']);
        Functions\expect('wp_remote_request')->never();
        $this->assertSame([], $this->makeSync()->delete_coupon_from_all_stores_by_code('TEST10'));
    }

    public function test_delete_from_all_stores_calls_delete_endpoint(): void
    {
        Functions\expect('wp_remote_get')->once()->with('https://store1.com/wp-json/wc/v3/coupons?code=TEST10', \Mockery::type('array'))
            ->andReturn(['response' => ['code' => 200], 'body' => '[{"id":99}]']);
        Functions\expect('wp_remote_request')->once()->with('https://store1.com/wp-json/wc/v3/coupons/99?force=1', \Mockery::on(fn($args) => $args['method'] === 'DELETE'))
            ->andReturn(['response' => ['code' => 200], 'body' => '{"id":99}']);
        $this->assertSame(['https://store1.com' => true], $this->makeSync()->delete_coupon_from_all_stores_by_code('TEST10'));
    }

    // ─── resolve_remote_ids() via sync_coupon_to_store() ──────────────────────

    public function test_resolve_skus_to_remote_ids(): void
    {
        $coupon = $this->basicCouponMock(productIds: [10]);

        $localProduct = \Mockery::mock('WC_Product');
        $localProduct->shouldReceive('get_sku')->andReturn('SKU-ABC');
        Functions\when('wc_get_products')->justReturn([$localProduct]);

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
        Functions\when('wc_get_products')->justReturn([$localProduct]);

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
    public function test_empty_restrictions_are_sent_to_clear_remote_values(): void
    {
        $data = $this->makeSync()->extract_coupon_data(new WC_Coupon(1));
        foreach (['product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'email_restrictions'] as $key) {
            $this->assertSame([], $data[$key]);
        }
    }

    public function test_unresolved_local_categories_fail_every_store(): void
    {
        Functions\when('get_terms')->justReturn([]);
        $this->assertSame(['https://store1.com' => false], $this->makeSync()->sync_coupon_to_all_stores($this->basicCouponMock(categoryIds: [7])));
    }

    public function test_missing_remote_restriction_aborts_without_writing_or_caching_failure(): void
    {
        $client = $this->makeClient();
        $client->get_handler = fn() => [];
        Functions\expect('set_transient')->never();
        $data = ['meta_data' => [['key' => '_wc_mss_excluded_product_skus', 'value' => ['MISSING']]]];
        $this->assertFalse($this->makeSync()->sync_coupon_to_store($client, new WC_Coupon(1), $data, 'https://store1.com'));
    }

    public function test_old_negative_cache_does_not_block_recovered_restriction(): void
    {
        Functions\when('get_transient')->justReturn(['SKU-1' => null]);
        $client = $this->makeClient();
        $client->get_handler = fn($endpoint) => $endpoint === 'products' ? [['id' => 55]] : [];
        $client->post_handler = function ($endpoint, $data) {
            $this->assertSame([55], $data['product_ids']);
            return ['id' => 2];
        };
        $data = ['meta_data' => [['key' => '_wc_mss_product_skus', 'value' => ['SKU-1']]]];
        $this->assertTrue($this->makeSync()->sync_coupon_to_store($client, new WC_Coupon(1), $data, 'https://store1.com'));
    }

}
