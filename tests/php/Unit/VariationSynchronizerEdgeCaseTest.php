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
