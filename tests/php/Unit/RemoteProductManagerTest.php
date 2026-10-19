<?php
/**
 * Unit tests for WC_Multi_Store_Remote_Product_Manager
 * Tests remote CRUD operations with mocked API client
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class RemoteProductManagerTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Remote_Product_Manager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteProductMocks();
        $this->manager = new WC_Multi_Store_Remote_Product_Manager();
    }

    protected function setUpRemoteProductMocks(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        // No stored remote-ID mapping by default — exercises the pre-existing
        // search-by-SKU/slug path unless a test explicitly stubs a stored ID.
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
    }

    private function createMockApi(): \Mockery\MockInterface
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        return $api;
    }

    private function createMockProduct(string $sku = 'TEST-SKU', string $slug = 'test-product'): \Mockery\MockInterface
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_slug')->andReturn($slug);
        $product->shouldReceive('get_id')->andReturn(100);
        return $product;
    }

    // ── find_remote_product ──────────────────────────────────────

    public function test_find_remote_product_by_sku(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('FIND-SKU');

        $remote = ['id' => 42, 'sku' => 'FIND-SKU', 'name' => 'Remote Product'];
        $api->shouldReceive('get_products')->with('FIND-SKU', 'sku')->andReturn([$remote]);

        $result = $this->manager->find_remote_product($api, $product, 'sku', 'https://store1.com');

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id']);
        $this->assertEquals('FIND-SKU', $result['sku']);
    }

    public function test_find_remote_product_by_slug(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('SKU', 'my-slug');

        $remote = ['id' => 55, 'slug' => 'my-slug'];
        $api->shouldReceive('get_products')->with('my-slug', 'slug')->andReturn([$remote]);

        $result = $this->manager->find_remote_product($api, $product, 'slug', 'https://store1.com');

        $this->assertNotNull($result);
        $this->assertEquals(55, $result['id']);
    }

    public function test_find_remote_product_returns_null_when_not_found(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('MISSING-SKU');

        $api->shouldReceive('get_products')->with('MISSING-SKU', 'sku')->andReturn([]);

        $result = $this->manager->find_remote_product($api, $product, 'sku', 'https://store1.com');

        $this->assertNull($result);
    }

    public function test_find_remote_product_returns_null_on_empty_sku(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('');

        $result = $this->manager->find_remote_product($api, $product, 'sku');

        $this->assertNull($result);
    }

    public function test_find_remote_product_returns_api_error_without_negative_caching(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('ERR-SKU');

        $api->shouldReceive('get_products')->andReturn(new \WP_Error('api_error', 'Connection failed'));
        Functions\expect('set_transient')->never();

        $result = $this->manager->find_remote_product($api, $product, 'sku', 'https://store1.com');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('Connection failed', $result->get_error_message());
    }

    // ── stable local↔remote ID mapping ───────────────────────────
    //
    // SKU/slug can legitimately change after the first sync (rename,
    // permalink edit). Re-searching remote by the NEW value would miss the
    // existing remote record and risk creating a duplicate. A persisted
    // ID mapping is immune to that drift.

    public function test_save_remote_product_id_persists_via_update_post_meta(): void
    {
        $captured = null;
        Functions\when('update_post_meta')->alias(function ($post_id, $key, $value) use (&$captured) {
            $captured = [$post_id, $key, $value];
            return true;
        });

        $this->manager->save_remote_product_id(100, 'https://store1.com', 555);

        $this->assertSame([100, '_mss_remote_id_' . md5('https://store1.com'), 555], $captured);
    }

    public function test_find_remote_product_prefers_stored_remote_id_over_search(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('NEW-SKU'); // local SKU already renamed

        Functions\when('get_post_meta')->justReturn('999');
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(['id' => 999, 'sku' => 'OLD-SKU']);
        // Must not fall back to search-by-value once the stored ID resolves.
        $api->shouldNotReceive('get_products');

        $result = $this->manager->find_remote_product($api, $product, 'sku', 'https://store1.com');

        $this->assertNotNull($result);
        $this->assertEquals(999, $result['id']);
    }

    public function test_find_remote_product_clears_stale_id_and_falls_back_when_id_lookup_fails(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('FIND-SKU');

        Functions\when('get_post_meta')->justReturn('999');
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(
            new \WP_Error('rest_not_found', 'Not found', ['status' => 404])
        );
        $api->shouldReceive('get_products')->with('FIND-SKU', 'sku')->once()->andReturn([
            ['id' => 42, 'sku' => 'FIND-SKU'],
        ]);
        $deleted_meta = null;
        Functions\when('delete_post_meta')->alias(function ($post_id, $key) use (&$deleted_meta) {
            $deleted_meta = [$post_id, $key];
            return true;
        });

        $result = $this->manager->find_remote_product($api, $product, 'sku', 'https://store1.com');

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id']);
        $this->assertSame([100, '_mss_remote_id_' . md5('https://store1.com')], $deleted_meta);
    }

    public function test_stored_id_is_preserved_on_lookup_outage_or_malformed_response(): void
    {
        Functions\when('get_post_meta')->justReturn('999');
        Functions\expect('delete_post_meta')->never();
        Functions\expect('set_transient')->never();
        foreach ([new \WP_Error('timeout', 'Timed out'), new \WP_Error('auth_error', 'Forbidden', ['status' => 403]), [], ['id' => 42]] as $response) {
            $api = $this->createMockApi();
            $api->shouldReceive('get_product')->with(999)->once()->andReturn($response);
            $api->shouldNotReceive('get_products');

            $result = $this->manager->find_remote_product($api, $this->createMockProduct(), 'sku', 'https://store1.com');

            $this->assertInstanceOf(\WP_Error::class, $result);
        }
    }

    public function test_live_not_found_bypasses_stale_search_cache(): void
    {
        Functions\when('get_post_meta')->justReturn('999');
        Functions\when('get_transient')->justReturn(['id' => 999, 'sku' => 'TEST-SKU']);
        $api = $this->createMockApi();
        $api->shouldReceive('get_product')->with(999)->once()->andReturn(new \WP_Error('not_found', 'Deleted', ['status' => 404]));
        $api->shouldReceive('get_products')->with('TEST-SKU', 'sku')->once()->andReturn([]);

        $this->assertNull($this->manager->find_remote_product($api, $this->createMockProduct(), 'sku', 'https://store1.com'));
    }

    public function test_malformed_search_results_are_errors_and_are_not_cached(): void
    {
        Functions\expect('set_transient')->never();
        foreach ([['message' => 'Error'], [null], ['wrong'], [['id' => 0]], [['id' => '42oops']]] as $response) {
            $api = $this->createMockApi();
            $api->shouldReceive('get_products')->once()->andReturn($response);
            $result = $this->manager->find_remote_product($api, $this->createMockProduct(), 'sku', 'https://store1.com');
            $this->assertInstanceOf(\WP_Error::class, $result);
        }
    }

    // ── create_or_update ─────────────────────────────────────────

    public function test_create_or_update_creates_new_product(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        $api->shouldReceive('create_product')->once()->andReturn(['id' => 77, 'name' => 'New Product']);

        $result = $this->manager->create_or_update(
            $api, $product, null, ['name' => 'New Product', 'type' => 'simple'], 'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('created', $result['action']);
    }

    public function test_create_or_update_updates_existing_product(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        $remote = ['id' => 42, 'name' => 'Old Name'];
        $api->shouldReceive('update_product')->with(42, \Mockery::any())->once()->andReturn(['id' => 42, 'name' => 'Updated']);

        $result = $this->manager->create_or_update(
            $api, $product, $remote, ['name' => 'Updated', 'type' => 'simple'], 'full_product'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('updated', $result['action']);
    }

    public function test_create_or_update_fails_for_non_full_sync_when_no_remote(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        $result = $this->manager->create_or_update(
            $api, $product, null, ['name' => 'Product'], 'stock_only'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found on remote store', $result['message']);
    }

    public function test_create_or_update_handles_api_error(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        $api->shouldReceive('create_product')->once()->andReturn(
            new \WP_Error('api_error', 'Server error')
        );

        $result = $this->manager->create_or_update(
            $api, $product, null, ['name' => 'Fail Product'], 'full_product'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('Server error', $result['message']);
    }

    public function test_create_or_update_strips_custom_product_type(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        // When creating, custom types (bundle, subscription) should be removed
        $captured_data = null;
        $api->shouldReceive('create_product')->once()->andReturnUsing(function ($data) use (&$captured_data) {
            $captured_data = $data;
            return ['id' => 88];
        });

        $this->manager->create_or_update(
            $api, $product, null, ['name' => 'Bundle', 'type' => 'bundle'], 'full_product'
        );

        $this->assertArrayNotHasKey('type', $captured_data);
    }

    public function test_update_rejects_invalid_success_payloads(): void
    {
        foreach ([[], ['message' => 'OK'], ['id' => 0], ['id' => -1], ['id' => '42oops'], ['id' => 99]] as $response) {
            $api = $this->createMockApi();
            $api->shouldReceive('update_product')->with(42, ['stock_quantity' => 58])->once()->andReturn($response);

            $result = $this->manager->create_or_update(
                $api, $this->createMockProduct('170519'), ['id' => 42], ['stock_quantity' => 58], 'quantity', 'stock_change'
            );

            $this->assertFalse($result['success']);
            $this->assertStringContainsString('ID', $result['message']);
        }
    }

    public function test_create_rejects_empty_success_payload(): void
    {
        $api = $this->createMockApi();
        $api->shouldReceive('create_product')->once()->andReturn([]);

        $result = $this->manager->create_or_update($api, $this->createMockProduct(), null, ['name' => 'Product'], 'full_product');

        $this->assertFalse($result['success']);
    }

    public function test_stock_update_to_trashed_product_is_not_reported_as_success(): void
    {
        $api = $this->createMockApi();
        $api->shouldReceive('update_product')->once()->andReturn(['id' => 42, 'status' => 'trash']);

        $result = $this->manager->create_or_update(
            $api, $this->createMockProduct('170519'), ['id' => 42], ['stock_quantity' => 58], 'quantity', 'stock_change'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('in trash', $result['message']);
    }

    public function test_update_rejects_ignored_publish_status(): void
    {
        $api = $this->createMockApi();
        $api->shouldReceive('update_product')->once()->andReturn(['id' => 42, 'status' => 'draft']);

        $result = $this->manager->create_or_update(
            $api, $this->createMockProduct(), ['id' => 42], ['status' => 'publish'], 'full_product'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('status mismatch', $result['message']);
    }

    public function test_readback_requires_a_real_product_with_the_written_id(): void
    {
        foreach ([[], ['id' => 99], ['id' => 42, 'status' => 'trash'], new \WP_Error('http_404', 'Not found'), new \WP_Error('timeout', 'Timed out')] as $response) {
            $api = $this->createMockApi();
            $api->shouldReceive('get_product')->with(42)->once()->andReturn($response);
            $result = $this->manager->verify_remote_product($api, 42, []);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertStringContainsString('verification failed', $result->get_error_message());
        }
    }

    public function test_readback_checks_every_sent_core_field_and_rejects_missing_fields(): void
    {
        $expected = [
            'sku' => '170519', 'status' => 'publish', 'catalog_visibility' => 'visible',
            'manage_stock' => true, 'stock_quantity' => 58, 'stock_status' => 'instock',
            'regular_price' => '19.99', 'sale_price' => '',
        ];
        $wrong = ['sku' => 'other', 'status' => 'draft', 'catalog_visibility' => 'hidden',
            'manage_stock' => false, 'stock_quantity' => 59, 'stock_status' => 'outofstock',
            'regular_price' => '20', 'sale_price' => '10'];
        foreach ($wrong as $field => $value) {
            foreach ([array_merge($expected, [$field => $value]), array_diff_key($expected, [$field => true])] as $actual) {
                $api = $this->createMockApi();
                $api->shouldReceive('get_product')->with(42)->once()->andReturn(['id' => 42] + $actual);
                $result = $this->manager->verify_remote_product($api, 42, $expected);
                $this->assertInstanceOf(\WP_Error::class, $result);
                $this->assertStringContainsString($field, $result->get_error_message());
            }
        }
    }

    public function test_readback_accepts_normalized_numbers_and_checks_only_expected_fields(): void
    {
        $api = $this->createMockApi();
        $actual = ['id' => 42, 'sku' => '170519', 'status' => 'draft', 'stock_quantity' => 58.0, 'regular_price' => '19.9900', 'sale_price' => ''];
        $api->shouldReceive('get_product')->with(42)->once()->andReturn($actual);

        $result = $this->manager->verify_remote_product($api, 42, ['stock_quantity' => 58, 'regular_price' => '19.99', 'sale_price' => '']);

        $this->assertSame($actual, $result);
    }

    public function test_readback_checks_gallery_including_removed_and_replaced_images(): void
    {
        foreach ([
            [[['id' => 10]], null, false],
            [[['id' => 10]], [], false],
            [[['id' => 10]], [['id' => 20]], false],
            [[], [['id' => 20]], false],
            [[['src' => 'https://source.test/a.jpg']], [['id' => 20]], true],
            [[['id' => 10]], [['id' => 10]], true],
            [[], [], true],
        ] as [$expected, $images, $success]) {
            $api = $this->createMockApi();
            $api->shouldReceive('get_product')->with(42)->once()->andReturn(['id' => 42, 'images' => $images]);
            $result = $this->manager->verify_remote_product($api, 42, ['images' => $expected]);
            $this->assertSame($success, !is_wp_error($result));
        }
    }

    public function test_stock_sync_preserves_draft_status_and_explicit_trash_sync_is_allowed(): void
    {
        foreach ([['stock_quantity' => 58], ['status' => 'trash']] as $data) {
            $api = $this->createMockApi();
            $api->shouldReceive('update_product')->with(42, $data)->once()->andReturn([
                'id' => 42, 'status' => $data['status'] ?? 'draft',
            ]);

            $result = $this->manager->create_or_update($api, $this->createMockProduct(), ['id' => 42], $data, 'quantity');

            $this->assertTrue($result['success']);
        }
    }

}
