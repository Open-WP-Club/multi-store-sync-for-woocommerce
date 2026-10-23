<?php
/**
 * Additional edge-case tests for WC_Multi_Store_Remote_Product_Manager:
 * stored remote-ID helpers, find_remote_product cache/ID interplay and the
 * create-vs-update decision in create_or_update.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class RemoteProductManagerCreateUpdateTest extends WC_Multi_Store_TestCase
{
    private const STORE = 'https://store1.com';

    private WC_Multi_Store_Remote_Product_Manager $manager;

    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var list<array{string, mixed}> */
    private array $transientWrites = [];

    /** @var list<string> */
    private array $transientDeletes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->meta = [];
        $this->transientWrites = [];
        $this->transientDeletes = [];

        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transientWrites[] = [$k, $v];
            return true;
        });
        Functions\when('delete_transient')->alias(function ($k) {
            $this->transientDeletes[] = $k;
            return true;
        });
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_post_meta')->alias(fn($id, $key) => $this->meta[$id][$key] ?? '');
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->meta[$id][$key]);
            return true;
        });

        $this->manager = new WC_Multi_Store_Remote_Product_Manager();
    }

    private function api(): \Mockery\MockInterface
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn(self::STORE);
        return $api;
    }

    private function product(string $sku = 'SKU-1', string $slug = 'slug-1', int $id = 100): \Mockery\MockInterface
    {
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('get_sku')->andReturn($sku);
        $p->shouldReceive('get_slug')->andReturn($slug);
        $p->shouldReceive('get_id')->andReturn($id);
        return $p;
    }

    private function metaKey(string $store = self::STORE): string
    {
        return '_mss_remote_id_' . md5($store);
    }

    // ---- remote_id_meta_key / stored id helpers --------------------------

    public function test_meta_key_is_private_static_and_unique_per_store(): void
    {
        $m = new ReflectionMethod(WC_Multi_Store_Remote_Product_Manager::class, 'remote_id_meta_key');
        $this->assertTrue($m->isPrivate());
        $this->assertTrue($m->isStatic());

        $a = $m->invoke(null, 'https://a.com');
        $b = $m->invoke(null, 'https://b.com');
        $this->assertSame('_mss_remote_id_' . md5('https://a.com'), $a);
        $this->assertNotSame($a, $b);
        $this->assertSame($a, $m->invoke(null, 'https://a.com'), 'Key must be deterministic');
    }

    public function test_stored_id_round_trip_and_clear(): void
    {
        $this->assertNull(WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(100, self::STORE));

        WC_Multi_Store_Remote_Product_Manager::save_remote_product_id(100, self::STORE, 777);
        $this->assertSame(777, WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(100, self::STORE));

        // Another store and another product are unaffected.
        $this->assertNull(WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(100, 'https://other.com'));
        $this->assertNull(WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(101, self::STORE));

        WC_Multi_Store_Remote_Product_Manager::clear_stored_remote_id(100, self::STORE);
        $this->assertNull(WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(100, self::STORE));
    }

    public function test_clear_only_removes_the_given_store_mapping(): void
    {
        WC_Multi_Store_Remote_Product_Manager::save_remote_product_id(100, 'https://a.com', 1);
        WC_Multi_Store_Remote_Product_Manager::save_remote_product_id(100, 'https://b.com', 2);

        WC_Multi_Store_Remote_Product_Manager::clear_stored_remote_id(100, 'https://a.com');

        $this->assertNull(WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(100, 'https://a.com'));
        $this->assertSame(2, WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(100, 'https://b.com'));
    }

    /**
     * @return array<string, array{mixed, ?int}>
     */
    public static function storedValues(): array
    {
        return [
            'empty string' => ['', null],
            'false' => [false, null],
            'numeric string' => ['123', 123],
            'int' => [456, 456],
            'zero string is a real (if odd) value' => ['0', 0],
        ];
    }

    #[DataProvider('storedValues')]
    public function test_get_stored_remote_id_normalizes_meta_values(mixed $raw, ?int $expected): void
    {
        $this->meta[100][$this->metaKey()] = $raw;

        $this->assertSame($expected, WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id(100, self::STORE));
    }

    // ---- find_remote_product ---------------------------------------------

    public function test_stored_id_hit_refreshes_search_cache_under_current_sku(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(['id' => 999, 'sku' => 'OLD']);
        $api->shouldNotReceive('get_products');

        $result = $this->manager->find_remote_product($api, $this->product('NEW-SKU'), 'sku', self::STORE);

        $this->assertSame(999, $result['id']);
        $this->assertCount(1, $this->transientWrites, 'Resolved remote product must be cached once');
        $this->assertSame(['id' => 999, 'sku' => 'OLD'], $this->transientWrites[0][1]);
    }

    public function test_stored_id_hit_with_empty_search_value_skips_caching(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(['id' => 999]);

        $result = $this->manager->find_remote_product($api, $this->product('', 'slug'), 'sku', self::STORE);

        $this->assertSame(999, $result['id']);
        $this->assertSame([], $this->transientWrites);
    }

    public function test_stored_id_hit_accepts_string_id_in_response(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(['id' => '999']);

        $result = $this->manager->find_remote_product($api, $this->product(), 'sku', self::STORE);

        $this->assertSame('999', $result['id']);
    }

    public function test_stored_id_returning_a_different_product_is_an_error_and_keeps_mapping(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(['id' => 5]);
        $api->shouldNotReceive('get_products');

        $result = $this->manager->find_remote_product($api, $this->product(), 'sku', self::STORE);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_product_response', $result->get_error_code());
        $this->assertSame('999', $this->meta[100][$this->metaKey()], 'Mapping must survive a bad response');
    }

    public function test_server_error_on_id_lookup_is_returned_unchanged_and_mapping_kept(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $error = new WP_Error('http_500', 'Server error', ['status' => 500]);
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn($error);
        $api->shouldNotReceive('get_products');

        $result = $this->manager->find_remote_product($api, $this->product(), 'sku', self::STORE);

        $this->assertSame($error, $result);
        $this->assertSame('999', $this->meta[100][$this->metaKey()]);
        $this->assertSame([], $this->transientDeletes);
    }

    public function test_wp_error_without_array_data_is_not_treated_as_404(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $error = new WP_Error('weird', 'No data');
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn($error);
        $api->shouldNotReceive('get_products');

        $this->assertSame($error, $this->manager->find_remote_product($api, $this->product(), 'sku', self::STORE));
        $this->assertArrayHasKey($this->metaKey(), $this->meta[100]);
    }

    public function test_404_clears_mapping_deletes_cache_and_searches_when_not_found_caches_negative(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(new WP_Error('nf', 'gone', ['status' => 404]));
        $api->shouldReceive('get_products')->with('SKU-1', 'sku')->once()->andReturn([]);

        $result = $this->manager->find_remote_product($api, $this->product(), 'sku', self::STORE);

        $this->assertNull($result);
        $this->assertArrayNotHasKey($this->metaKey(), $this->meta[100]);
        $this->assertNotEmpty($this->transientDeletes, 'Stale cache entry must be removed after a live 404');
        $this->assertCount(1, $this->transientWrites, 'Negative result must be cached');
    }

    public function test_404_then_search_hit_returns_new_product_and_caches_it(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $api = $this->api();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(new WP_Error('nf', 'gone', ['status' => 404]));
        $api->shouldReceive('get_products')->with('slug-1', 'slug')->once()->andReturn([['id' => 12, 'slug' => 'slug-1'], ['id' => 13]]);

        $result = $this->manager->find_remote_product($api, $this->product(), 'slug', self::STORE);

        $this->assertSame(12, $result['id'], 'First match wins');
        $this->assertSame(['id' => 12, 'slug' => 'slug-1'], $this->transientWrites[0][1]);
    }

    public function test_cached_product_is_returned_without_api_call(): void
    {
        Functions\when('get_transient')->justReturn(['id' => 31, 'sku' => 'SKU-1']);
        $api = $this->api();
        $api->shouldNotReceive('get_products');
        $api->shouldNotReceive('get_product');

        $result = $this->manager->find_remote_product($api, $this->product(), 'sku', self::STORE);

        $this->assertSame(31, $result['id']);
    }

    public function test_search_wp_error_is_returned_and_nothing_cached(): void
    {
        $error = new WP_Error('http_request_failed', 'cURL error 28');
        $api = $this->api();
        $api->shouldReceive('get_products')->once()->andReturn($error);

        $result = $this->manager->find_remote_product($api, $this->product(), 'sku', self::STORE);

        $this->assertSame($error, $result);
        $this->assertSame([], $this->transientWrites);
    }

    public function test_empty_search_value_returns_null_without_any_api_call(): void
    {
        $api = $this->api();
        $api->shouldNotReceive('get_products');
        $api->shouldNotReceive('get_product');

        $this->assertNull($this->manager->find_remote_product($api, $this->product('', ''), 'slug', self::STORE));
        $this->assertNull($this->manager->find_remote_product($api, $this->product('', 'x'), 'sku', self::STORE));
    }

    public function test_slug_match_mode_searches_by_slug_not_sku(): void
    {
        $api = $this->api();
        $api->shouldReceive('get_products')->once()->with('slug-1', 'slug')->andReturn([['id' => 8]]);

        $result = $this->manager->find_remote_product($api, $this->product('SKU-1', 'slug-1'), 'slug', self::STORE);

        $this->assertSame(8, $result['id']);
    }

    public function test_without_store_url_stored_id_is_ignored(): void
    {
        $this->meta[100][$this->metaKey()] = '999';
        $api = $this->api();
        $api->shouldNotReceive('get_product');
        $api->shouldReceive('get_products')->once()->andReturn([['id' => 3]]);

        $result = $this->manager->find_remote_product($api, $this->product(), 'sku');

        $this->assertSame(3, $result['id']);
        $this->assertSame([], $this->transientWrites, 'No store url means no caching');
    }

    // ---- create_or_update ------------------------------------------------

    public function test_update_path_calls_update_product_with_remote_id_and_reports_updated(): void
    {
        $api = $this->api();
        $api->shouldReceive('update_product')->once()->with(42, ['name' => 'N'])->andReturn(['id' => 42, 'name' => 'N']);
        $api->shouldNotReceive('create_product');

        $r = $this->manager->create_or_update($api, $this->product(), ['id' => 42], ['name' => 'N', 'type' => 'simple'], 'full_product');

        $this->assertTrue($r['success']);
        $this->assertSame('updated', $r['action']);
        $this->assertSame('Product updated successfully', $r['message']);
        $this->assertSame(42, $r['result']['id']);
    }

    public function test_create_path_reports_created(): void
    {
        $api = $this->api();
        $api->shouldReceive('create_product')->once()->with(['name' => 'N', 'type' => 'variable'])->andReturn(['id' => 7, 'name' => 'N']);
        $api->shouldNotReceive('update_product');

        $r = $this->manager->create_or_update($api, $this->product(), null, ['name' => 'N', 'type' => 'variable'], 'full_product');

        $this->assertTrue($r['success']);
        $this->assertSame('created', $r['action']);
        $this->assertSame('Product created successfully', $r['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonFullSyncTypes(): array
    {
        return ['price_quantity' => ['price_quantity'], 'quantity' => ['quantity'], 'unknown' => ['whatever']];
    }

    #[DataProvider('nonFullSyncTypes')]
    public function test_non_full_sync_never_creates(string $syncType): void
    {
        $api = $this->api();
        $api->shouldNotReceive('create_product');
        $api->shouldNotReceive('update_product');

        $r = $this->manager->create_or_update($api, $this->product(), null, ['stock_quantity' => 1], $syncType, 'price_change');

        $this->assertFalse($r['success']);
        $this->assertNull($r['action']);
        $this->assertNull($r['result']);
        $this->assertStringContainsString('non-full sync', $r['message']);
        $this->assertStringContainsString('price change (price_change)', $r['message']);
    }

    public function test_empty_array_remote_product_is_treated_as_missing(): void
    {
        $api = $this->api();
        $api->shouldReceive('create_product')->once()->andReturn(['id' => 9]);

        $r = $this->manager->create_or_update($api, $this->product(), [], ['name' => 'N'], 'full_product');

        $this->assertSame('created', $r['action']);
    }

    public function test_wp_error_on_create_is_reported_with_created_action(): void
    {
        $err = new WP_Error('woocommerce_rest_product_sku_unique', 'SKU already exists');
        $api = $this->api();
        $api->shouldReceive('create_product')->once()->andReturn($err);

        $r = $this->manager->create_or_update($api, $this->product(), null, ['name' => 'N'], 'full_product');

        $this->assertFalse($r['success']);
        $this->assertSame('created', $r['action']);
        $this->assertSame($err, $r['result']);
        $this->assertSame('SKU already exists', $r['message']);
    }

    public function test_wp_error_on_update_is_reported_with_updated_action(): void
    {
        $api = $this->api();
        $api->shouldReceive('update_product')->once()->andReturn(new WP_Error('e', 'Boom'));

        $r = $this->manager->create_or_update($api, $this->product(), ['id' => 1], ['name' => 'N'], 'full_product');

        $this->assertFalse($r['success']);
        $this->assertSame('updated', $r['action']);
        $this->assertSame('Boom', $r['message']);
    }

    public function test_update_response_id_mismatch_reports_expected_and_received(): void
    {
        $api = $this->api();
        $api->shouldReceive('update_product')->once()->andReturn(['id' => 99]);

        $r = $this->manager->create_or_update($api, $this->product(), ['id' => 42], ['name' => 'N'], 'full_product');

        $this->assertFalse($r['success']);
        $this->assertSame('Remote product response ID mismatch: expected 42, received 99', $r['message']);
        $this->assertSame(['id' => 99], $r['result']);
    }

    public function test_string_remote_id_in_response_matches_integer_remote_id(): void
    {
        $api = $this->api();
        $api->shouldReceive('update_product')->once()->andReturn(['id' => '42']);

        $r = $this->manager->create_or_update($api, $this->product(), ['id' => 42], ['name' => 'N'], 'full_product');

        $this->assertTrue($r['success']);
    }

    public function test_trash_response_is_accepted_only_when_trash_was_requested(): void
    {
        $api = $this->api();
        $api->shouldReceive('update_product')->twice()->andReturn(['id' => 42, 'status' => 'trash']);

        $ok = $this->manager->create_or_update($api, $this->product(), ['id' => 42], ['status' => 'trash'], 'full_product');
        $bad = $this->manager->create_or_update($api, $this->product(), ['id' => 42], ['status' => 'publish'], 'full_product');

        $this->assertTrue($ok['success']);
        $this->assertFalse($bad['success']);
        $this->assertSame('Remote product 42 is in trash; sync did not restore it', $bad['message']);
    }

    public function test_status_mismatch_message_includes_both_statuses_and_missing_status(): void
    {
        $api = $this->api();
        $api->shouldReceive('create_product')->twice()->andReturnValues([['id' => 5, 'status' => 'pending'], ['id' => 5]]);

        $a = $this->manager->create_or_update($api, $this->product(), null, ['status' => 'publish'], 'full_product');
        $b = $this->manager->create_or_update($api, $this->product(), null, ['status' => 'publish'], 'full_product');

        $this->assertSame('Remote product 5 status mismatch: expected publish, received pending', $a['message']);
        $this->assertSame('Remote product 5 status mismatch: expected publish, received missing', $b['message']);
    }

    public function test_no_status_in_payload_skips_status_validation(): void
    {
        $api = $this->api();
        $api->shouldReceive('update_product')->once()->andReturn(['id' => 42, 'status' => 'draft']);

        $r = $this->manager->create_or_update($api, $this->product(), ['id' => 42], ['regular_price' => '5'], 'price_quantity');

        $this->assertTrue($r['success']);
    }

    public function test_create_keeps_type_when_other_fields_untouched_and_strips_custom_type_only(): void
    {
        $sent = [];
        $api = $this->api();
        $api->shouldReceive('create_product')->twice()->andReturnUsing(function ($data) use (&$sent) {
            $sent[] = $data;
            return ['id' => 1];
        });

        $this->manager->create_or_update($api, $this->product(), null, ['type' => 'subscription', 'name' => 'A'], 'full_product');
        $this->manager->create_or_update($api, $this->product(), null, ['type' => 'grouped', 'name' => 'B'], 'full_product');

        $this->assertSame(['name' => 'A'], $sent[0]);
        $this->assertSame(['type' => 'grouped', 'name' => 'B'], $sent[1]);
    }
}
