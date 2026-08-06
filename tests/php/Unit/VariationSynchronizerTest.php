<?php
/**
 * Unit tests for WC_Multi_Store_Variation_Synchronizer
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class VariationSynchronizerTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Variation_Synchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');

        $extractor = \Mockery::mock('WC_Multi_Store_Product_Extractor');
        $transformer = \Mockery::mock('WC_Multi_Store_Product_Transformer');

        $this->synchronizer = new WC_Multi_Store_Variation_Synchronizer($extractor, $transformer);
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Variation_Synchronizer'));
    }

    public function test_has_sync_variations_method(): void
    {
        $this->assertTrue(method_exists(WC_Multi_Store_Variation_Synchronizer::class, 'sync_variations'));
    }

    public function test_has_delete_variation_method(): void
    {
        $this->assertTrue(method_exists(WC_Multi_Store_Variation_Synchronizer::class, 'delete_variation'));
    }

    public function test_has_find_remote_parent_id_method(): void
    {
        $this->assertTrue(method_exists(WC_Multi_Store_Variation_Synchronizer::class, 'find_remote_parent_id'));
    }

    // ─── sync_variations: non-variable product ─────

    public function test_sync_variations_returns_zero_for_simple_product(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $result = $this->synchronizer->sync_variations($product, 100, $api);

        $this->assertEquals(0, $result);
    }

    // ─── sync_variations: variable with no children ─

    public function test_sync_variations_returns_zero_when_no_children(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_children')->andReturn([]);

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $result = $this->synchronizer->sync_variations($product, 100, $api);

        $this->assertEquals(0, $result);
    }

    // ─── sync_variations: cached variations ────────

    // ─── sync_variations: API fetch (no cache) ──────

    public function test_sync_variations_fetches_and_batches(): void
    {
        // These are real static methods on loaded classes - mock underlying WP functions
        // CacheManager uses get_transient internally
        Functions\when('get_transient')->justReturn(false); // cache miss
        Functions\when('set_transient')->justReturn(true);

        // ImageProxy::is_enabled uses Settings::get which uses get_option
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') {
                return ['image_proxy_enabled' => false, 'delete_orphan_variations' => false];
            }
            return $default;
        });

        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('NEW-VAR');
        $variation->shouldReceive('get_image_id')->andReturn(0);

        Functions\when('wc_get_product')->alias(fn($id) => $variation);

        $extractor = \Mockery::mock('WC_Multi_Store_Product_Extractor');
        $extractor->shouldReceive('build_variation_data')
            ->andReturn(['sku' => 'NEW-VAR', 'regular_price' => '19.99']);

        $transformer = \Mockery::mock('WC_Multi_Store_Product_Transformer');

        $synchronizer = new WC_Multi_Store_Variation_Synchronizer($extractor, $transformer);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_children')->andReturn([20]);

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        // Remote has no variations
        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn([]);
        // Should batch create
        $api->shouldReceive('batch_product_variations')
            ->once()
            ->with(100, \Mockery::on(fn($data) => isset($data['create']) && count($data['create']) === 1))
            ->andReturn(['create' => [['id' => 600]]]);

        $result = $synchronizer->sync_variations($product, 100, $api, 'https://store.com');

        // 1 for get_product_variations + 1 for batch = 2
        $this->assertEquals(2, $result);
    }

    // ─── sync_variations: light syncs (quantity/price_quantity/price_quantity_categories) ──────

    private function mockLightSyncScenario(array $localOverrides = [], array $remoteOverrides = []): array {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') {
                return ['image_proxy_enabled' => false, 'delete_orphan_variations' => false];
            }
            return $default;
        });

        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('VAR-1');
        $variation->shouldReceive('get_image_id')->andReturn(0);
        Functions\when('wc_get_product')->alias(fn($id) => $variation);

        $local = array_merge([
            'sku' => 'VAR-1',
            'regular_price' => '19.99',
            'sale_price' => '',
            'manage_stock' => true,
            'stock_quantity' => 5,
            'stock_status' => 'instock',
        ], $localOverrides);

        $extractor = \Mockery::mock('WC_Multi_Store_Product_Extractor');
        $extractor->shouldReceive('build_variation_data')->andReturn($local);

        $transformer = \Mockery::mock('WC_Multi_Store_Product_Transformer');
        $synchronizer = new WC_Multi_Store_Variation_Synchronizer($extractor, $transformer);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_children')->andReturn([20]);

        $remote = array_merge([
            'id' => 700,
            'sku' => 'VAR-1',
            'regular_price' => '19.99',
            'sale_price' => '',
            'manage_stock' => true,
            'stock_quantity' => 5,
            'stock_status' => 'instock',
        ], $remoteOverrides);

        return [$synchronizer, $product, $remote];
    }

    public function test_sync_variations_skips_update_when_light_sync_data_unchanged(): void
    {
        [$synchronizer, $product, $remote] = $this->mockLightSyncScenario();

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')->once()->andReturn([$remote]);
        $api->shouldNotReceive('batch_product_variations');

        $result = $synchronizer->sync_variations($product, 100, $api, 'https://store.com', [], 'price_quantity');

        $this->assertEquals(1, $result, 'Only the get_product_variations call — no update sent when nothing changed');
    }

    public function test_sync_variations_updates_when_light_sync_stock_changed(): void
    {
        [$synchronizer, $product, $remote] = $this->mockLightSyncScenario(
            localOverrides: ['stock_quantity' => 8],
            remoteOverrides: ['stock_quantity' => 5]
        );

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')->once()->andReturn([$remote]);
        $api->shouldReceive('batch_product_variations')
            ->once()
            ->with(100, \Mockery::on(fn($data) => isset($data['update']) && count($data['update']) === 1))
            ->andReturn(['update' => [['id' => 700]]]);

        $result = $synchronizer->sync_variations($product, 100, $api, 'https://store.com', [], 'price_quantity');

        $this->assertEquals(2, $result);
    }

    public function test_sync_variations_full_product_always_updates_even_when_unchanged(): void
    {
        [$synchronizer, $product, $remote] = $this->mockLightSyncScenario();

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')->once()->andReturn([$remote]);
        $api->shouldReceive('batch_product_variations')
            ->once()
            ->with(100, \Mockery::on(fn($data) => isset($data['update']) && count($data['update']) === 1))
            ->andReturn(['update' => [['id' => 700]]]);

        // Default sync_type ('full_product') must NOT gain a skip-when-unchanged
        // optimization — that's out of scope and would change existing,
        // already-relied-upon behavior.
        $result = $synchronizer->sync_variations($product, 100, $api, 'https://store.com');

        $this->assertEquals(2, $result);
    }

    // ─── delete_variation ──────────────────────────

    public function test_delete_variation_when_found(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $api->shouldReceive('get_product_variations')
            ->once()
            ->with(100, ['sku' => 'VAR-DELETE'])
            ->andReturn([
                ['id' => 501, 'sku' => 'VAR-DELETE'],
                ['id' => 502, 'sku' => 'VAR-KEEP'],
            ]);

        $api->shouldReceive('delete_product_variation')
            ->once()
            ->with(100, 501, false)
            ->andReturn(['id' => 501, 'status' => 'trash']);

        $result = $this->synchronizer->delete_variation($api, 'VAR-DELETE', 100);

        $this->assertTrue($result['success']);
        $this->assertEquals(501, $result['remote_id']);
        $this->assertEquals('moved to trash', $result['action']);
        $this->assertEquals(2, $result['api_calls']);
    }

    public function test_delete_variation_with_force_delete(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn([
                ['id' => 501, 'sku' => 'VAR-FORCE'],
            ]);

        $api->shouldReceive('delete_product_variation')
            ->once()
            ->with(100, 501, true)
            ->andReturn(['id' => 501]);

        $result = $this->synchronizer->delete_variation($api, 'VAR-FORCE', 100, true);

        $this->assertTrue($result['success']);
        $this->assertEquals('deleted permanently', $result['action']);
    }

    public function test_delete_variation_not_found_on_remote(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn([
                ['id' => 501, 'sku' => 'OTHER-SKU'],
            ]);

        // Should NOT attempt to delete
        $api->shouldNotReceive('delete_product_variation');

        $result = $this->synchronizer->delete_variation($api, 'NONEXISTENT', 100);

        $this->assertTrue($result['success']);
        $this->assertEquals('skipped', $result['action']);
        $this->assertEquals(1, $result['api_calls']);
    }

    public function test_delete_variation_api_error_on_get(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn(new \WP_Error('api_error', 'Connection failed'));

        $result = $this->synchronizer->delete_variation($api, 'SKU', 100);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection failed', $result['message']);
        $this->assertEquals(1, $result['api_calls']);
    }

    public function test_delete_variation_api_error_on_delete(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn([
                ['id' => 501, 'sku' => 'VAR-ERR'],
            ]);

        $api->shouldReceive('delete_product_variation')
            ->once()
            ->andReturn(new \WP_Error('delete_failed', 'Server error'));

        $result = $this->synchronizer->delete_variation($api, 'VAR-ERR', 100);

        $this->assertFalse($result['success']);
        $this->assertEquals(501, $result['remote_id']);
        $this->assertStringContainsString('Server error', $result['message']);
        $this->assertEquals(2, $result['api_calls']);
    }

    // ─── find_remote_parent_id ─────────────────────

    public function test_find_remote_parent_id_by_sku(): void
    {
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_sku')->andReturn('PARENT-SKU');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_products')
            ->once()
            ->with('PARENT-SKU', 'sku')
            ->andReturn([['id' => 999, 'name' => 'Parent Product']]);

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'sku');

        $this->assertEquals(999, $result);
    }

    public function test_find_remote_parent_id_by_slug(): void
    {
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_slug')->andReturn('my-product');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_products')
            ->once()
            ->with('my-product', 'slug')
            ->andReturn([['id' => 500]]);

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'slug');

        $this->assertEquals(500, $result);
    }

    public function test_find_remote_parent_id_returns_null_when_empty_sku(): void
    {
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_sku')->andReturn('');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'sku');

        $this->assertNull($result);
    }

    public function test_find_remote_parent_id_returns_null_when_not_found(): void
    {
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_sku')->andReturn('MISSING');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_products')
            ->once()
            ->andReturn([]);

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'sku');

        $this->assertNull($result);
    }

    public function test_find_remote_parent_id_returns_null_on_api_error(): void
    {
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_sku')->andReturn('ERR-SKU');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_products')
            ->once()
            ->andReturn(new \WP_Error('api_error', 'Timeout'));

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'sku');

        $this->assertNull($result);
    }

    // ─── Constructor ───────────────────────────────

    public function test_constructor_accepts_null_dependencies(): void
    {
        // Should not throw - creates default instances
        $sync = new WC_Multi_Store_Variation_Synchronizer();

        $this->assertInstanceOf(WC_Multi_Store_Variation_Synchronizer::class, $sync);
    }

    public function test_constructor_accepts_custom_dependencies(): void
    {
        $extractor = \Mockery::mock('WC_Multi_Store_Product_Extractor');
        $transformer = \Mockery::mock('WC_Multi_Store_Product_Transformer');

        $sync = new WC_Multi_Store_Variation_Synchronizer($extractor, $transformer);

        $this->assertInstanceOf(WC_Multi_Store_Variation_Synchronizer::class, $sync);
    }
}
