<?php
/**
 * Edge case tests for WC_Multi_Store_Variation_Synchronizer
 *
 * Covers: orphan variation deletion, missing parent on remote,
 * empty SKU variations, batch delete failures, find_remote_parent_id edge cases.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class VariationSynchronizerEdgeCaseTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Variation_Synchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('do_action')->justReturn(null);

        $this->synchronizer = new WC_Multi_Store_Variation_Synchronizer();
    }

    // ── delete_variation ─────────────────────────────────────────

    public function test_delete_variation_returns_error_when_api_fails(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn(new WP_Error('api_error', 'Connection timeout'));

        $result = $this->synchronizer->delete_variation($api, 'VAR-SKU', 50);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to get variations', $result['message']);
        $this->assertEquals(1, $result['api_calls']);
    }

    public function test_delete_variation_skips_when_not_found_on_remote(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn([
                ['id' => 1, 'sku' => 'OTHER-SKU'],
                ['id' => 2, 'sku' => 'ANOTHER-SKU'],
            ]);

        $result = $this->synchronizer->delete_variation($api, 'VAR-SKU', 50);

        // Not found = success with 'skipped' action
        $this->assertTrue($result['success']);
        $this->assertEquals('skipped', $result['action']);
    }

    public function test_delete_variation_finds_and_deletes_by_sku(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')
            ->once()
            ->andReturn([
                ['id' => 10, 'sku' => 'VAR-A'],
                ['id' => 20, 'sku' => 'TARGET-SKU'],
                ['id' => 30, 'sku' => 'VAR-C'],
            ]);

        $api->shouldReceive('delete_product_variation')
            ->once()
            ->with(50, 20, false) // parent_id, variation_id, force
            ->andReturn(['id' => 20]);

        $result = $this->synchronizer->delete_variation($api, 'TARGET-SKU', 50);

        $this->assertTrue($result['success']);
        $this->assertEquals(20, $result['remote_id']);
        $this->assertEquals(2, $result['api_calls']);
    }

    public function test_delete_variation_api_delete_fails(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')
            ->andReturn([['id' => 20, 'sku' => 'VAR-SKU']]);

        $api->shouldReceive('delete_product_variation')
            ->andReturn(new WP_Error('delete_failed', 'Server error'));

        $result = $this->synchronizer->delete_variation($api, 'VAR-SKU', 50);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to delete', $result['message']);
        $this->assertEquals(20, $result['remote_id']);
    }

    public function test_delete_variation_with_empty_sku_variations(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')
            ->andReturn([
                ['id' => 10, 'sku' => ''],
                ['id' => 20, 'sku' => null],
                ['id' => 30, 'sku' => 'VAR-SKU'],
            ]);

        $api->shouldReceive('delete_product_variation')
            ->with(50, 30, false)
            ->andReturn(['id' => 30]);

        $result = $this->synchronizer->delete_variation($api, 'VAR-SKU', 50);

        $this->assertTrue($result['success']);
        $this->assertEquals(30, $result['remote_id']);
    }

    public function test_delete_variation_empty_remote_variations(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_product_variations')
            ->andReturn([]);

        $result = $this->synchronizer->delete_variation($api, 'VAR-SKU', 50);

        $this->assertTrue($result['success']);
        $this->assertEquals('skipped', $result['action']);
    }

    // ── find_remote_parent_id ────────────────────────────────────

    public function test_find_remote_parent_id_returns_null_for_empty_sku(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_sku')->andReturn('');

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'sku');

        $this->assertNull($result);
    }

    public function test_find_remote_parent_id_returns_null_for_empty_slug(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_slug')->andReturn('');

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'slug');

        $this->assertNull($result);
    }

    public function test_find_remote_parent_id_returns_null_on_empty_results(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_sku')->andReturn('PARENT-SKU');

        $api->shouldReceive('get_products')
            ->andReturn([]);

        $result = $this->synchronizer->find_remote_parent_id($api, $parent);

        $this->assertNull($result);
    }

    public function test_find_remote_parent_id_returns_first_match_id(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_sku')->andReturn('PARENT-SKU');

        $api->shouldReceive('get_products')
            ->andReturn([
                ['id' => 500, 'sku' => 'PARENT-SKU'],
                ['id' => 600, 'sku' => 'PARENT-SKU'], // Duplicate - should not be returned
            ]);

        $result = $this->synchronizer->find_remote_parent_id($api, $parent);

        $this->assertEquals(500, $result);
    }

    public function test_find_remote_parent_id_uses_slug_match(): void
    {
        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $parent = \Mockery::mock('WC_Product');
        $parent->shouldReceive('get_slug')->andReturn('parent-product');

        $api->shouldReceive('get_products')
            ->with('parent-product', 'slug')
            ->andReturn([['id' => 700]]);

        $result = $this->synchronizer->find_remote_parent_id($api, $parent, 'slug');

        $this->assertEquals(700, $result);
    }
}
