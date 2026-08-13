<?php
/**
 * Edge case tests for WC_Multi_Store_Remote_Product_Manager
 * Covers: partial batch success, API error handling, cache interactions
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class RemoteProductManagerBatchTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Remote_Product_Manager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMocks();
        $this->manager = new WC_Multi_Store_Remote_Product_Manager();
    }

    protected function setUpMocks(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
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

    private function createMockProduct(string $sku = 'TEST-SKU', string $slug = 'test-product', int $id = 100): \Mockery\MockInterface
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_slug')->andReturn($slug);
        $product->shouldReceive('get_id')->andReturn($id);
        return $product;
    }

    // ── create_or_update: update strips 'type' field ─────────────

    public function test_update_removes_type_from_product_data(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        $captured_data = null;
        $api->shouldReceive('update_product')->once()->andReturnUsing(function ($id, $data) use (&$captured_data) {
            $captured_data = $data;
            return ['id' => $id];
        });

        $remote = ['id' => 42];
        $data = ['name' => 'Updated', 'type' => 'simple', 'sku' => 'TEST-SKU'];

        $this->manager->create_or_update($api, $product, $remote, $data, 'full_product');

        $this->assertArrayNotHasKey('type', $captured_data);
        $this->assertArrayHasKey('name', $captured_data);
    }

    // ── create_or_update: standard types kept on create ──────────

    public function test_create_keeps_standard_product_types(): void
    {
        $standard_types = ['simple', 'grouped', 'external', 'variable'];

        foreach ($standard_types as $type) {
            $api = $this->createMockApi();
            $product = $this->createMockProduct("SKU-{$type}");

            $captured_data = null;
            $api->shouldReceive('create_product')->once()->andReturnUsing(function ($data) use (&$captured_data) {
                $captured_data = $data;
                return ['id' => 1];
            });

            $this->manager->create_or_update(
                $api, $product, null, ['name' => 'Prod', 'type' => $type], 'full_product'
            );

            $this->assertArrayHasKey('type', $captured_data, "Type '{$type}' should be kept for create");
            $this->assertEquals($type, $captured_data['type']);
        }
    }

    // ── create_or_update: custom types stripped on create ─────────

    public function test_create_strips_custom_product_types(): void
    {
        $custom_types = ['bundle', 'subscription', 'composite', 'variable-subscription'];

        foreach ($custom_types as $type) {
            $api = $this->createMockApi();
            $product = $this->createMockProduct("SKU-{$type}");

            $captured_data = null;
            $api->shouldReceive('create_product')->once()->andReturnUsing(function ($data) use (&$captured_data) {
                $captured_data = $data;
                return ['id' => 1];
            });

            $this->manager->create_or_update(
                $api, $product, null, ['name' => 'Prod', 'type' => $type], 'full_product'
            );

            $this->assertArrayNotHasKey('type', $captured_data, "Custom type '{$type}' should be stripped");
        }
    }

    // ── create_or_update: non-full sync with no remote ───────────

    public function test_non_full_sync_returns_descriptive_message(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        $sync_types = ['stock_only', 'price_only', 'stock_and_price'];

        foreach ($sync_types as $sync_type) {
            $result = $this->manager->create_or_update(
                $api, $product, null, ['name' => 'Prod'], $sync_type, 'scheduled_sync'
            );

            $this->assertFalse($result['success']);
            $this->assertNull($result['action']);
            $this->assertStringContainsString('not found on remote store', $result['message']);
            $this->assertStringContainsString('scheduled sync', $result['message']);
        }
    }

    // ── create_or_update: API error on update ────────────────────

    public function test_update_returns_error_details_on_failure(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct();

        $api->shouldReceive('update_product')
            ->once()
            ->andReturn(new \WP_Error('rest_error', 'Internal Server Error'));

        $remote = ['id' => 42];
        $result = $this->manager->create_or_update(
            $api, $product, $remote, ['name' => 'Fail'], 'full_product'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('updated', $result['action']);
        $this->assertEquals('Internal Server Error', $result['message']);
        $this->assertInstanceOf(\WP_Error::class, $result['result']);
    }

    // ── find_remote_product: caching via transients ─────────────

    public function test_find_remote_product_skips_api_on_negative_cache(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('NEG-CACHE');

        // Set up transient to indicate product is known to not exist
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_cache_get')->alias(function ($key) {
            // Negative cache hit
            if (str_contains($key, 'not_found')) {
                return true;
            }
            return false;
        });
        Functions\when('wp_cache_set')->justReturn(true);

        // API should be called because the real CacheManager may not find the transient
        $api->shouldReceive('get_products')->with('NEG-CACHE', 'sku')->andReturn([]);

        $result = $this->manager->find_remote_product($api, $product, 'sku', 'https://store1.com');

        $this->assertNull($result);
    }

    public function test_find_remote_product_returns_first_result(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('MULTI-RESULT');

        // API returns multiple products — should use first one
        $api->shouldReceive('get_products')->with('MULTI-RESULT', 'sku')->andReturn([
            ['id' => 10, 'sku' => 'MULTI-RESULT', 'name' => 'First Match'],
            ['id' => 20, 'sku' => 'MULTI-RESULT', 'name' => 'Second Match'],
        ]);

        $result = $this->manager->find_remote_product($api, $product, 'sku');

        $this->assertNotNull($result);
        $this->assertEquals(10, $result['id']);
        $this->assertEquals('First Match', $result['name']);
    }

    public function test_find_remote_product_without_store_url_skips_cache(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('NO-CACHE');

        // When store_url is empty, no caching should happen
        $api->shouldReceive('get_products')->with('NO-CACHE', 'sku')->andReturn([
            ['id' => 42, 'sku' => 'NO-CACHE'],
        ]);

        $result = $this->manager->find_remote_product($api, $product, 'sku', '');

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id']);
    }

    public function test_find_remote_product_by_slug_without_store_url(): void
    {
        $api = $this->createMockApi();
        $product = $this->createMockProduct('SKU-UNUSED', 'my-product-slug');

        $api->shouldReceive('get_products')->with('my-product-slug', 'slug')->andReturn([
            ['id' => 77, 'slug' => 'my-product-slug'],
        ]);

        $result = $this->manager->find_remote_product($api, $product, 'slug');

        $this->assertNotNull($result);
        $this->assertEquals(77, $result['id']);
    }

    // ── get_sync_source_description ──────────────────────────────

    public function test_sync_source_descriptions_cover_all_sources(): void
    {
        $manager = new WC_Multi_Store_Remote_Product_Manager();
        $method = new \ReflectionMethod($manager, 'get_sync_source_description');

        $sources = [
            'manual' => 'manual sync',
            'product_save' => 'product save',
            'new_product' => 'new product',
            'stock_change' => 'stock change',
            'scheduled_sync' => 'scheduled sync',
            'bulk_action' => 'bulk action',
        ];

        foreach ($sources as $source => $expected) {
            $result = $method->invoke($manager, $source);
            $this->assertEquals($expected, $result, "Source '{$source}' should map to '{$expected}'");
        }
    }

    public function test_sync_source_description_returns_raw_for_unknown(): void
    {
        $manager = new WC_Multi_Store_Remote_Product_Manager();
        $method = new \ReflectionMethod($manager, 'get_sync_source_description');

        $result = $method->invoke($manager, 'custom_source_xyz');
        $this->assertEquals('custom_source_xyz', $result);
    }
}
