<?php
/**
 * Extended unit tests for WC_Multi_Store_Product_Exclusion_Filter
 * Covers: should_exclude() with WC_Product + wp_get_post_terms, get_exclusion_reasons() with term data
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductExclusionFilterExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Mock $wpdb->get_results to return product_cat / product_tag rows for the
     * single SQL query that the filter now uses instead of two wp_get_post_terms calls.
     *
     * @param int[] $categories category term IDs to return
     * @param int[] $tags       tag term IDs to return
     */
    private function mockTermQuery(array $categories, array $tags): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix             = 'wp_';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy      = 'wp_term_taxonomy';

        $rows = [];
        foreach ($categories as $id) {
            $rows[] = (object) ['term_id' => (int) $id, 'taxonomy' => 'product_cat'];
        }
        foreach ($tags as $id) {
            $rows[] = (object) ['term_id' => (int) $id, 'taxonomy' => 'product_tag'];
        }

        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn($rows);
    }

    // ─── should_exclude() with WC_Product ─────────

    public function test_should_exclude_with_product_object_category_match(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([10, 20, 30], []);

        $store_config = ['exclude_categories' => [20]];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config);
        $this->assertTrue($result);
    }

    public function test_should_exclude_with_product_object_tag_match(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([], [100, 200]);

        $store_config = ['exclude_tags' => [200]];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config);
        $this->assertTrue($result);
    }

    public function test_should_exclude_with_product_object_no_match(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([10, 20], [100]);

        $store_config = [
            'exclude_categories' => [50],
            'exclude_tags' => [500],
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config);
        $this->assertFalse($result);
    }

    public function test_should_exclude_with_product_no_terms(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([], []);

        $store_config = [
            'exclude_categories' => [10],
            'exclude_tags' => [100],
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config);
        $this->assertFalse($result);
    }

    public function test_should_exclude_with_product_empty_config(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        // Empty config fast-exits before any DB query — no mock needed.
        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, []);
        $this->assertFalse($result);
    }

    // ─── get_exclusion_reasons() ──────────────────

    public function test_get_exclusion_reasons_category_match(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([10, 20], []);

        $cat_term = (object) ['name' => 'Electronics'];
        Functions\when('get_term')->alias(function ($id, $taxonomy) use ($cat_term) {
            if ($id === 10 && $taxonomy === 'product_cat') {
                return $cat_term;
            }
            return null;
        });

        $store_config = ['exclude_categories' => [10]];
        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store_config);

        $this->assertCount(1, $reasons);
        $this->assertEquals('Category: Electronics', $reasons[0]);
    }

    public function test_get_exclusion_reasons_tag_match(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([], [100, 200]);

        $tag_term = (object) ['name' => 'Sale'];
        Functions\when('get_term')->alias(function ($id, $taxonomy) use ($tag_term) {
            if ($id === 200 && $taxonomy === 'product_tag') {
                return $tag_term;
            }
            return null;
        });

        $store_config = ['exclude_tags' => [200]];
        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store_config);

        $this->assertCount(1, $reasons);
        $this->assertEquals('Tag: Sale', $reasons[0]);
    }

    public function test_get_exclusion_reasons_multiple_reasons(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([10, 20], [100, 200]);

        Functions\when('get_term')->alias(function ($id, $taxonomy) {
            $terms = [
                '10_product_cat' => (object) ['name' => 'Electronics'],
                '20_product_cat' => (object) ['name' => 'Gadgets'],
                '200_product_tag' => (object) ['name' => 'Clearance'],
            ];
            return $terms["{$id}_{$taxonomy}"] ?? null;
        });

        $store_config = [
            'exclude_categories' => [10, 20],
            'exclude_tags' => [200],
        ];

        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store_config);

        $this->assertCount(3, $reasons);
        $this->assertContains('Category: Electronics', $reasons);
        $this->assertContains('Category: Gadgets', $reasons);
        $this->assertContains('Tag: Clearance', $reasons);
    }

    public function test_get_exclusion_reasons_no_match(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        Functions\when('wp_get_post_terms')->justReturn([]);

        $store_config = [
            'exclude_categories' => [10],
            'exclude_tags' => [100],
        ];

        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store_config);
        $this->assertEmpty($reasons);
    }

    public function test_get_exclusion_reasons_empty_config(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, []);
        $this->assertEmpty($reasons);
    }

    public function test_get_exclusion_reasons_wp_error_term_skipped(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([10], []);

        // get_term returns WP_Error
        Functions\when('get_term')->justReturn(new WP_Error('invalid_term', 'Term not found'));

        $store_config = ['exclude_categories' => [10]];
        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store_config);

        $this->assertEmpty($reasons);
    }

    public function test_get_exclusion_reasons_null_term_skipped(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);

        $this->mockTermQuery([], [100]);

        Functions\when('get_term')->justReturn(null);

        $store_config = ['exclude_tags' => [100]];
        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store_config);

        $this->assertEmpty($reasons);
    }

    // ─── should_exclude_by_ids edge cases ─────────

    public function test_should_exclude_by_ids_both_category_and_tag_match(): void
    {
        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            [10, 20],
            [100, 200],
            [
                'exclude_categories' => [10],
                'exclude_tags' => [200],
            ]
        );

        // Returns true on first category match (short-circuits)
        $this->assertTrue($result);
    }

    public function test_should_exclude_by_ids_large_exclusion_list(): void
    {
        $large_exclusion = range(1, 1000);
        $product_cats = [500]; // one match in the middle

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $product_cats,
            [],
            ['exclude_categories' => $large_exclusion]
        );

        $this->assertTrue($result);
    }

    public function test_should_exclude_by_ids_large_exclusion_no_match(): void
    {
        $large_exclusion = range(1, 1000);
        $product_cats = [1500]; // no match

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $product_cats,
            [],
            ['exclude_categories' => $large_exclusion]
        );

        $this->assertFalse($result);
    }
}
