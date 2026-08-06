<?php
/**
 * Edge case tests for WC_Multi_Store_Product_Extractor
 *
 * Covers: WP_Error from get_terms(), null/false image URLs,
 * empty category/tag arrays, corrupted product data.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductExtractorEdgeCaseTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Product_Extractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'category_match_mode' => 'full_path',
                    'category_match_by' => 'slug',
                ];
            }
            return $default;
        });

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_get_attachment_url')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');

        $this->extractor = new WC_Multi_Store_Product_Extractor();
    }

    // ── format_categories edge cases ─────────────────────────────

    public function test_format_categories_returns_empty_on_wp_error(): void
    {
        Functions\when('get_terms')->justReturn(new WP_Error('db_error', 'Database error'));

        $result = $this->extractor->format_categories([1, 2, 3]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_format_categories_returns_empty_for_empty_ids(): void
    {
        $result = $this->extractor->format_categories([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_format_categories_handles_terms_with_parents(): void
    {
        $parent = (object) ['term_id' => 1, 'name' => 'Clothing', 'slug' => 'clothing', 'parent' => 0];
        $child = (object) ['term_id' => 2, 'name' => 'T-Shirts', 'slug' => 't-shirts', 'parent' => 1];

        Functions\when('get_terms')->justReturn([$parent, $child]);

        $result = $this->extractor->format_categories([1, 2]);

        $this->assertCount(2, $result);
        $this->assertEquals('Clothing', $result[0]['name']);
        $this->assertEquals('T-Shirts', $result[1]['name']);
    }

    public function test_format_categories_leaf_only_filters_parents(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'category_match_mode' => 'leaf_only',
                    'category_match_by' => 'slug',
                ];
            }
            return $default;
        });

        $parent = (object) ['term_id' => 1, 'name' => 'Clothing', 'slug' => 'clothing', 'parent' => 0];
        $child = (object) ['term_id' => 2, 'name' => 'T-Shirts', 'slug' => 't-shirts', 'parent' => 1];

        Functions\when('get_terms')->justReturn([$parent, $child]);

        $extractor = new WC_Multi_Store_Product_Extractor();
        $result = $extractor->format_categories([1, 2]);

        // Only leaf (child) should remain
        $this->assertCount(1, $result);
        $this->assertEquals('T-Shirts', $result[0]['name']);
    }

    // ── format_tags edge cases ───────────────────────────────────

    public function test_format_tags_returns_empty_on_wp_error(): void
    {
        Functions\when('get_terms')->justReturn(new WP_Error('db_error', 'Database error'));

        $result = $this->extractor->format_tags([1, 2]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_format_tags_returns_empty_for_empty_ids(): void
    {
        $result = $this->extractor->format_tags([]);
        $this->assertEmpty($result);
    }

    public function test_format_tags_includes_name_and_slug(): void
    {
        $tag = (object) ['term_id' => 10, 'name' => 'Sale', 'slug' => 'sale', 'parent' => 0];
        Functions\when('get_terms')->justReturn([$tag]);

        $result = $this->extractor->format_tags([10]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayHasKey('slug', $result[0]);
    }

    public function test_format_tags_name_only_mode(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['category_match_by' => 'name'];
            }
            return $default;
        });

        $tag = (object) ['term_id' => 10, 'name' => 'Промоция', 'slug' => 'promo', 'parent' => 0];
        Functions\when('get_terms')->justReturn([$tag]);

        $extractor = new WC_Multi_Store_Product_Extractor();
        $result = $extractor->format_tags([10]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayNotHasKey('slug', $result[0]);
        $this->assertEquals('Промоция', $result[0]['name']);
    }

    // ── format_images edge cases ─────────────────────────────────

    public function test_format_images_skips_null_urls(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(1);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([2, 3]);

        // All URLs return false (deleted attachments)
        Functions\when('wp_get_attachment_url')->justReturn(false);

        $result = $this->extractor->format_images($product);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_format_images_with_no_image(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $result = $this->extractor->format_images($product);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_format_images_with_mixed_valid_invalid_urls(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(1);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([2, 3]);

        $callCount = 0;
        Functions\when('wp_get_attachment_url')->alias(function ($id) use (&$callCount) {
            $callCount++;
            if ($id === 2) return false; // Deleted attachment
            return "https://example.com/image-$id.jpg";
        });

        $result = $this->extractor->format_images($product);

        // Main image (id=1) + gallery image (id=3) — id=2 skipped
        $this->assertCount(2, $result);
        $this->assertEquals(0, $result[0]['position']); // Main image
        $this->assertEquals(1, $result[1]['position']); // Gallery
    }

    // ── get_stock_data edge cases ────────────────────────────────

    public function test_get_stock_data_with_null_stock_quantity(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('managing_stock')->andReturn(true);
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');

        $result = $this->extractor->get_stock_data($product);

        $this->assertTrue($result['manage_stock']);
        $this->assertNull($result['stock_quantity']);
    }

    // ── get_price_data edge cases ────────────────────────────────

    public function test_get_price_data_with_empty_prices(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_regular_price')->andReturn('');
        $product->shouldReceive('is_on_sale')->andReturn(false);

        $result = $this->extractor->get_price_data($product);

        $this->assertEquals('', $result['regular_price']);
        $this->assertEquals('', $result['sale_price']);
    }

    public function test_get_price_data_with_zero_price(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_regular_price')->andReturn('0');
        $product->shouldReceive('is_on_sale')->andReturn(false);

        $result = $this->extractor->get_price_data($product);

        $this->assertEquals('0', $result['regular_price']);
    }

    // ── build_product_data edge cases ────────────────────────────

    public function test_build_product_data_quantity_only_type(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('managing_stock')->andReturn(true);
        $product->shouldReceive('get_stock_quantity')->andReturn(50);
        $product->shouldReceive('get_stock_status')->andReturn('instock');

        $result = $this->extractor->build_product_data($product, 'quantity');

        $this->assertArrayHasKey('manage_stock', $result);
        $this->assertArrayHasKey('stock_quantity', $result);
        $this->assertArrayNotHasKey('name', $result);
        $this->assertArrayNotHasKey('regular_price', $result);
    }

    // ── filter_to_leaf_categories ────────────────────────────────

    public function test_filter_to_leaf_categories_with_empty_terms(): void
    {
        $ref = new \ReflectionClass($this->extractor);
        $method = $ref->getMethod('filter_to_leaf_categories');

        $result = $method->invoke($this->extractor, [], []);

        $this->assertEmpty($result);
    }

    public function test_filter_to_leaf_categories_all_roots(): void
    {
        $ref = new \ReflectionClass($this->extractor);
        $method = $ref->getMethod('filter_to_leaf_categories');

        $terms = [
            (object) ['term_id' => 1, 'parent' => 0],
            (object) ['term_id' => 2, 'parent' => 0],
        ];

        $result = $method->invoke($this->extractor, $terms, [1, 2]);

        // All are leaves since none are parents of each other
        $this->assertCount(2, $result);
    }

    public function test_filter_to_leaf_categories_deep_chain(): void
    {
        $ref = new \ReflectionClass($this->extractor);
        $method = $ref->getMethod('filter_to_leaf_categories');

        // A → B → C chain
        $terms = [
            (object) ['term_id' => 1, 'parent' => 0],
            (object) ['term_id' => 2, 'parent' => 1],
            (object) ['term_id' => 3, 'parent' => 2],
        ];

        $result = $method->invoke($this->extractor, $terms, [1, 2, 3]);

        // Only term 3 is a leaf (2 is parent of 3, 1 is parent of 2)
        $this->assertCount(1, $result);
        $this->assertEquals(3, $result[0]->term_id);
    }

    // ── format_variation_attributes edge cases ───────────────────

    public function test_format_variation_attributes_term_not_found_falls_back_to_slug(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')
            ->andReturn(['attribute_pa_color' => 'red']);

        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'red', 'pa_color')->andReturn(false);

        $result = $this->extractor->format_variation_attributes($variation);

        $this->assertCount(1, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertEquals('red', $result[0]['option']); // slug kept as-is
    }

    public function test_format_variation_attributes_empty_value_skips_get_term_by(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')
            ->andReturn(['attribute_pa_color' => '']);

        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        // get_term_by must NOT be called — Brain Monkey will fail if it is

        $result = $this->extractor->format_variation_attributes($variation);

        $this->assertCount(1, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertEquals('', $result[0]['option']);
    }

    public function test_format_variation_attributes_mixed_taxonomy_and_custom(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')
            ->andReturn([
                'attribute_pa_color'  => 'red',
                'attribute_material'  => 'Cotton',
            ]);

        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('taxonomy_exists')->once()->with('material')->andReturn(false);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'red', 'pa_color')
            ->andReturn((object) ['name' => 'Red', 'slug' => 'red']);

        $result = $this->extractor->format_variation_attributes($variation);

        $this->assertCount(2, $result);
        $this->assertEquals(['name' => 'Color',    'option' => 'Red'],    $result[0]);
        $this->assertEquals(['name' => 'material', 'option' => 'Cotton'], $result[1]);
    }

    public function test_format_variation_attributes_term_wp_error_falls_back_to_slug(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')
            ->andReturn(['attribute_pa_color' => 'red']);

        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'red', 'pa_color')
            ->andReturn(new WP_Error('invalid_taxonomy', 'Taxonomy does not exist'));

        $result = $this->extractor->format_variation_attributes($variation);

        $this->assertCount(1, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertEquals('red', $result[0]['option']); // slug kept as-is
    }

    // ── format_default_attributes edge cases ─────────────────────

    public function test_format_default_attributes_term_not_found_falls_back_to_slug(): void
    {
        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'blue', 'pa_color')->andReturn(false);

        $result = $this->extractor->format_default_attributes(['pa_color' => 'blue']);

        $this->assertCount(1, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertEquals('blue', $result[0]['option']); // slug kept as-is
    }

    public function test_format_default_attributes_empty_value_skips_get_term_by(): void
    {
        Functions\expect('taxonomy_exists')->once()->with('pa_size')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_size')->andReturn('Size');
        // get_term_by must NOT be called — Brain Monkey will fail if it is

        $result = $this->extractor->format_default_attributes(['pa_size' => '']);

        $this->assertCount(1, $result);
        $this->assertEquals('Size', $result[0]['name']);
        $this->assertEquals('', $result[0]['option']);
    }
}
