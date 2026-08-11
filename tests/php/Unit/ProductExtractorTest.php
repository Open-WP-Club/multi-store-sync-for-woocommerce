<?php
/**
 * Unit tests for WC_Multi_Store_Product_Extractor
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductExtractorTest extends WC_Multi_Store_TestCase
{
    /**
     * @var WC_Multi_Store_Product_Extractor
     */
    private $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new WC_Multi_Store_Product_Extractor();
    }

    /**
     * Create a mock WC_Product
     */
    private function create_mock_product(array $options = array()): \Mockery\MockInterface
    {
        $defaults = array(
            'id' => 123,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'type' => 'simple',
            'status' => 'publish',
            'featured' => false,
            'catalog_visibility' => 'visible',
            'description' => 'Product description',
            'short_description' => 'Short description',
            'sku' => 'TEST-SKU-123',
            'regular_price' => '100.00',
            'sale_price' => '',
            'is_on_sale' => false,
            'managing_stock' => true,
            'stock_quantity' => 50,
            'stock_status' => 'instock',
            'backorders' => 'no',
            'has_weight' => true,
            'weight' => '1.5',
            'has_dimensions' => true,
            'length' => '10',
            'width' => '5',
            'height' => '2',
            'tax_status' => 'taxable',
            'tax_class' => '',
            'category_ids' => array(),
            'tag_ids' => array(),
            'image_id' => 0,
            'gallery_image_ids' => array(),
            'attributes' => array(),
        );

        $options = array_merge($defaults, $options);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn($options['id']);
        $product->shouldReceive('get_name')->andReturn($options['name']);
        $product->shouldReceive('get_slug')->andReturn($options['slug']);
        $product->shouldReceive('get_type')->andReturn($options['type']);
        $product->shouldReceive('get_status')->andReturn($options['status']);
        $product->shouldReceive('is_featured')->andReturn($options['featured']);
        $product->shouldReceive('get_catalog_visibility')->andReturn($options['catalog_visibility']);
        $product->shouldReceive('get_description')->andReturn($options['description']);
        $product->shouldReceive('get_short_description')->andReturn($options['short_description']);
        $product->shouldReceive('get_sku')->andReturn($options['sku']);
        $product->shouldReceive('get_regular_price')->andReturn($options['regular_price']);
        $product->shouldReceive('get_sale_price')->andReturn($options['sale_price']);
        $product->shouldReceive('is_on_sale')->andReturn($options['is_on_sale']);
        $product->shouldReceive('get_date_on_sale_from')->andReturn(null);
        $product->shouldReceive('get_date_on_sale_to')->andReturn(null);
        $product->shouldReceive('managing_stock')->andReturn($options['managing_stock']);
        $product->shouldReceive('get_stock_quantity')->andReturn($options['stock_quantity']);
        $product->shouldReceive('get_stock_status')->andReturn($options['stock_status']);
        $product->shouldReceive('get_backorders')->andReturn($options['backorders']);
        $product->shouldReceive('has_weight')->andReturn($options['has_weight']);
        $product->shouldReceive('get_weight')->andReturn($options['weight']);
        $product->shouldReceive('has_dimensions')->andReturn($options['has_dimensions']);
        $product->shouldReceive('get_length')->andReturn($options['length']);
        $product->shouldReceive('get_width')->andReturn($options['width']);
        $product->shouldReceive('get_height')->andReturn($options['height']);
        $product->shouldReceive('get_tax_status')->andReturn($options['tax_status']);
        $product->shouldReceive('get_tax_class')->andReturn($options['tax_class']);
        $product->shouldReceive('get_category_ids')->andReturn($options['category_ids']);
        $product->shouldReceive('get_tag_ids')->andReturn($options['tag_ids']);
        $product->shouldReceive('get_image_id')->andReturn($options['image_id']);
        $product->shouldReceive('get_gallery_image_ids')->andReturn($options['gallery_image_ids']);
        $product->shouldReceive('get_attributes')->andReturn($options['attributes']);
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('is_downloadable')->andReturn(false);
        $product->shouldReceive('is_virtual')->andReturn(false);

        return $product;
    }

    /**
     * Test build_product_data for quantity sync type
     */
    public function test_build_product_data_quantity_type(): void
    {
        $product = $this->create_mock_product();

        $data = $this->extractor->build_product_data($product, 'quantity');

        $this->assertArrayHasKey('manage_stock', $data);
        $this->assertArrayHasKey('stock_quantity', $data);
        $this->assertArrayHasKey('stock_status', $data);
        $this->assertArrayNotHasKey('regular_price', $data);
        $this->assertArrayNotHasKey('name', $data);

        $this->assertTrue($data['manage_stock']);
        $this->assertEquals(50, $data['stock_quantity']);
        $this->assertEquals('instock', $data['stock_status']);
    }

    /**
     * Test build_product_data for price_quantity sync type
     */
    public function test_build_product_data_price_quantity_type(): void
    {
        $product = $this->create_mock_product();

        $data = $this->extractor->build_product_data($product, 'price_quantity');

        // Should have price data
        $this->assertArrayHasKey('regular_price', $data);
        $this->assertEquals('100.00', $data['regular_price']);

        // Should have stock data
        $this->assertArrayHasKey('manage_stock', $data);
        $this->assertArrayHasKey('stock_quantity', $data);

        // Should NOT have full product data
        $this->assertArrayNotHasKey('name', $data);
        $this->assertArrayNotHasKey('description', $data);
    }

    /**
     * Test build_product_data for full_product sync type
     */
    public function test_build_product_data_full_product_type(): void
    {
        $product = $this->create_mock_product();

        // Mock settings for category formatting
        Functions\expect('get_transient')
            ->andReturn(array());

        $data = $this->extractor->build_product_data($product, 'full_product');

        // Should have basic info
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('slug', $data);
        $this->assertArrayHasKey('type', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('sku', $data);

        // Should have price data
        $this->assertArrayHasKey('regular_price', $data);

        // Should have stock data
        $this->assertArrayHasKey('manage_stock', $data);
        $this->assertArrayHasKey('stock_quantity', $data);

        // Should have dimensions
        $this->assertArrayHasKey('weight', $data);
        $this->assertArrayHasKey('dimensions', $data);

        // Should have tax data
        $this->assertArrayHasKey('tax_status', $data);
        $this->assertArrayHasKey('tax_class', $data);

        $this->assertEquals('Test Product', $data['name']);
        $this->assertEquals('TEST-SKU-123', $data['sku']);
    }

    /**
     * Test get_stock_data when managing stock
     */
    public function test_get_stock_data_managing_stock(): void
    {
        $product = $this->create_mock_product(array(
            'managing_stock' => true,
            'stock_quantity' => 100,
            'stock_status' => 'instock',
        ));

        $data = $this->extractor->get_stock_data($product);

        $this->assertTrue($data['manage_stock']);
        $this->assertEquals(100, $data['stock_quantity']);
        $this->assertEquals('instock', $data['stock_status']);
    }

    /**
     * Test get_stock_data when not managing stock
     */
    public function test_get_stock_data_not_managing_stock(): void
    {
        $product = $this->create_mock_product(array(
            'managing_stock' => false,
            'stock_status' => 'outofstock',
        ));

        $data = $this->extractor->get_stock_data($product);

        $this->assertFalse($data['manage_stock']);
        $this->assertEquals('outofstock', $data['stock_status']);
        $this->assertArrayNotHasKey('stock_quantity', $data);
    }

    /**
     * Test get_price_data without sale
     */
    public function test_get_price_data_no_sale(): void
    {
        $product = $this->create_mock_product(array(
            'regular_price' => '150.00',
            'is_on_sale' => false,
        ));

        $data = $this->extractor->get_price_data($product);

        $this->assertArrayHasKey('regular_price', $data);
        $this->assertEquals('150.00', $data['regular_price']);
        // sale_price is always sent (empty string clears stale sales on remote)
        $this->assertArrayHasKey('sale_price', $data);
        $this->assertSame('', $data['sale_price']);
    }

    /**
     * Test get_price_data with sale
     */
    public function test_get_price_data_with_sale(): void
    {
        $product = $this->create_mock_product(array(
            'regular_price' => '100.00',
            'sale_price' => '80.00',
            'is_on_sale' => true,
        ));

        $data = $this->extractor->get_price_data($product);

        $this->assertEquals('100.00', $data['regular_price']);
        $this->assertEquals('80.00', $data['sale_price']);
    }

    /**
     * Test get_full_product_data includes all fields
     */
    public function test_get_full_product_data_includes_all_fields(): void
    {
        $product = $this->create_mock_product();

        Functions\expect('get_transient')
            ->andReturn(array());

        $data = $this->extractor->get_full_product_data($product);

        $required_fields = array(
            'name', 'slug', 'type', 'status', 'featured',
            'catalog_visibility', 'description', 'short_description',
            'sku', 'regular_price', 'manage_stock', 'stock_quantity',
            'stock_status', 'weight', 'dimensions', 'tax_status', 'tax_class',
        );

        foreach ($required_fields as $field) {
            $this->assertArrayHasKey($field, $data, "Missing field: {$field}");
        }
    }

    /**
     * Test get_full_product_data without weight
     */
    public function test_get_full_product_data_no_weight(): void
    {
        $product = $this->create_mock_product(array(
            'has_weight' => false,
        ));

        Functions\expect('get_transient')
            ->andReturn(array());

        $data = $this->extractor->get_full_product_data($product);

        $this->assertArrayNotHasKey('weight', $data);
    }

    /**
     * Test get_full_product_data without dimensions
     */
    public function test_get_full_product_data_no_dimensions(): void
    {
        $product = $this->create_mock_product(array(
            'has_dimensions' => false,
        ));

        Functions\expect('get_transient')
            ->andReturn(array());

        $data = $this->extractor->get_full_product_data($product);

        $this->assertArrayNotHasKey('dimensions', $data);
    }

    /**
     * Test format_categories returns empty array for empty input
     */
    public function test_format_categories_empty(): void
    {
        $result = $this->extractor->format_categories(array());

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test format_tags returns empty array for empty input
     */
    public function test_format_tags_empty(): void
    {
        $result = $this->extractor->format_tags(array());

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test format_images with no image
     */
    public function test_format_images_no_image(): void
    {
        $product = $this->create_mock_product(array(
            'image_id' => 0,
            'gallery_image_ids' => array(),
        ));

        Functions\expect('get_transient')
            ->andReturn(false);

        Functions\expect('get_option')
            ->andReturn(array());

        Functions\expect('set_transient')
            ->andReturn(true);

        $result = $this->extractor->format_images($product);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test format_images with main image
     */
    public function test_format_images_with_main_image(): void
    {
        $product = $this->create_mock_product(array(
            'image_id' => 123,
            'gallery_image_ids' => array(),
        ));

        Functions\expect('get_transient')
            ->andReturn(false);

        Functions\expect('get_option')
            ->andReturn(array());

        Functions\expect('set_transient')
            ->andReturn(true);

        Functions\expect('wp_get_attachment_url')
            ->once()
            ->with(123)
            ->andReturn('https://example.com/image.jpg');

        $result = $this->extractor->format_images($product);

        $this->assertCount(1, $result);
        $this->assertEquals('https://example.com/image.jpg', $result[0]['src']);
        $this->assertEquals(0, $result[0]['position']);
    }

    /**
     * Test format_images with gallery images
     */
    public function test_format_images_with_gallery(): void
    {
        $product = $this->create_mock_product(array(
            'image_id' => 123,
            'gallery_image_ids' => array(124, 125),
        ));

        Functions\expect('get_transient')
            ->andReturn(false);

        Functions\expect('get_option')
            ->andReturn(array());

        Functions\expect('set_transient')
            ->andReturn(true);

        Functions\expect('wp_get_attachment_url')
            ->times(3)
            ->andReturnValues(array(
                'https://example.com/image1.jpg',
                'https://example.com/image2.jpg',
                'https://example.com/image3.jpg',
            ));

        $result = $this->extractor->format_images($product);

        $this->assertCount(3, $result);
        $this->assertEquals(0, $result[0]['position']);
        $this->assertEquals(1, $result[1]['position']);
        $this->assertEquals(2, $result[2]['position']);
    }

    /**
     * Test get_image_filenames
     */
    public function test_get_image_filenames(): void
    {
        $product = $this->create_mock_product(array(
            'image_id' => 123,
            'gallery_image_ids' => array(124),
        ));

        Functions\expect('wp_get_attachment_url')
            ->times(2)
            ->andReturnValues(array(
                'https://example.com/uploads/main-image.jpg',
                'https://example.com/uploads/gallery-image.jpg',
            ));

        $filenames = $this->extractor->get_image_filenames($product);

        $this->assertCount(2, $filenames);
        $this->assertEquals('main-image.jpg', $filenames[0]);
        $this->assertEquals('gallery-image.jpg', $filenames[1]);
    }

    /**
     * Test have_images_changed returns true when no previous hash
     */
    public function test_have_images_changed_no_previous_hash(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'image_id' => 456,
            'gallery_image_ids' => array(),
        ));

        Functions\expect('wp_get_attachment_url')
            ->once()
            ->andReturn('https://example.com/image.jpg');

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn(''); // No previous hash

        $result = $this->extractor->have_images_changed($product, 'https://store.com');

        $this->assertTrue($result);
    }

    /**
     * Test have_images_changed returns false when hash matches
     */
    public function test_have_images_changed_hash_matches(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'image_id' => 456,
            'gallery_image_ids' => array(),
        ));

        Functions\expect('wp_get_attachment_url')
            ->once()
            ->andReturn('https://example.com/image.jpg');

        $expected_hash = md5(serialize(array('image.jpg')));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn($expected_hash);

        $result = $this->extractor->have_images_changed($product, 'https://store.com');

        $this->assertFalse($result);
    }

    /**
     * Test save_synced_images_hash
     */
    public function test_save_synced_images_hash(): void
    {
        $filenames = array('image1.jpg', 'image2.jpg');
        $expected_hash = md5(serialize($filenames));

        Functions\expect('update_post_meta')
            ->once()
            ->with(123, \Mockery::type('string'), $expected_hash)
            ->andReturn(true);

        $this->extractor->save_synced_images_hash(123, 'https://store.com', $filenames);

        // Method is void, verify that expectation was met
        $this->assertTrue(true);
    }

    /**
     * Test have_categories_changed
     */
    public function test_have_categories_changed(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'category_ids' => array(1, 2, 3),
        ));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn('different_hash');

        $result = $this->extractor->have_categories_changed($product, 'https://store.com');

        $this->assertTrue($result);
    }

    /**
     * Test have_tags_changed
     */
    public function test_have_tags_changed(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'tag_ids' => array(10, 20),
        ));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn('different_hash');

        $result = $this->extractor->have_tags_changed($product, 'https://store.com');

        $this->assertTrue($result);
    }

    /**
     * Test save_all_sync_hashes
     */
    public function test_save_all_sync_hashes(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'image_id' => 456,
            'gallery_image_ids' => array(),
            'category_ids' => array(1, 2),
            'tag_ids' => array(10),
        ));

        Functions\expect('wp_get_attachment_url')
            ->once()
            ->andReturn('https://example.com/image.jpg');

        Functions\expect('update_post_meta')
            ->times(5)
            ->andReturn(true);

        $this->extractor->save_all_sync_hashes($product, 'https://store.com');

        // Method is void, verify that expectations were met
        $this->assertTrue(true);
    }

    /**
     * Test have_description_changed returns true when no previous hash
     */
    public function test_have_description_changed_no_previous_hash(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'description' => 'Full description',
            'short_description' => 'Short',
        ));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn(''); // No previous hash

        $result = $this->extractor->have_description_changed($product, 'https://store.com');

        $this->assertTrue($result);
    }

    /**
     * Test have_description_changed returns false when hash matches
     */
    public function test_have_description_changed_hash_matches(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'description' => 'Full description',
            'short_description' => 'Short',
        ));

        $expected_hash = md5(serialize(array('Full description', 'Short')));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn($expected_hash);

        $result = $this->extractor->have_description_changed($product, 'https://store.com');

        $this->assertFalse($result);
    }

    /**
     * Test have_description_changed returns true when only short_description differs
     */
    public function test_have_description_changed_detects_short_description_change(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'description' => 'Full description',
            'short_description' => 'New short',
        ));

        $stale_hash = md5(serialize(array('Full description', 'Old short')));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn($stale_hash);

        $result = $this->extractor->have_description_changed($product, 'https://store.com');

        $this->assertTrue($result);
    }

    /**
     * Test save_synced_description_hash
     */
    public function test_save_synced_description_hash(): void
    {
        $expected_hash = md5(serialize(array('Full description', 'Short')));

        Functions\expect('update_post_meta')
            ->once()
            ->with(123, \Mockery::type('string'), $expected_hash)
            ->andReturn(true);

        $this->extractor->save_synced_description_hash(123, 'https://store.com', 'Full description', 'Short');

        // Method is void, verify that expectation was met
        $this->assertTrue(true);
    }

    /**
     * Test have_attributes_changed returns true when no previous hash (simple product, no attributes)
     */
    public function test_have_attributes_changed_no_previous_hash(): void
    {
        $product = $this->create_mock_product(array('id' => 123));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn(''); // No previous hash

        $result = $this->extractor->have_attributes_changed($product, 'https://store.com');

        $this->assertTrue($result);
    }

    /**
     * Test have_attributes_changed returns false when hash matches for a custom (non-taxonomy) attribute
     */
    public function test_have_attributes_changed_hash_matches_custom_attribute(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'attributes' => array('Color' => array('Red', 'Blue')),
        ));

        $expected_hash = md5(serialize(array(
            array('name' => 'Color', 'options' => array('Red', 'Blue')),
        )));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn($expected_hash);

        $result = $this->extractor->have_attributes_changed($product, 'https://store.com');

        $this->assertFalse($result);
    }

    /**
     * Test have_attributes_changed detects a change in a taxonomy attribute's options,
     * without triggering the expensive term-label lookups format_attributes() does
     * (no taxonomy_exists()/get_term_by() expectations set — if the signature builder
     * accidentally called those, this test would fail with an unexpected-call error).
     */
    public function test_have_attributes_changed_detects_taxonomy_attribute_change(): void
    {
        $product = $this->create_mock_product(array('id' => 123));

        $attribute = \Mockery::mock('WC_Product_Attribute');
        $attribute->shouldReceive('get_name')->andReturn('pa_color');
        $attribute->shouldReceive('get_options')->andReturn(array(10, 20));
        $attribute->shouldReceive('get_visible')->andReturn(true);
        $attribute->shouldReceive('get_variation')->andReturn(false);

        $product->shouldReceive('get_attributes')->andReturn(array('pa_color' => $attribute));

        // Stale hash was saved when the taxonomy attribute only had option term ID 10.
        $stale_hash = md5(serialize(array(
            array('name' => 'pa_color', 'options' => array(10), 'visible' => true, 'variation' => false),
        )));

        Functions\expect('get_post_meta')
            ->once()
            ->andReturn($stale_hash);

        $result = $this->extractor->have_attributes_changed($product, 'https://store.com');

        $this->assertTrue($result);
    }

    /**
     * Test save_synced_attributes_hash
     */
    public function test_save_synced_attributes_hash(): void
    {
        $product = $this->create_mock_product(array(
            'id' => 123,
            'attributes' => array('Color' => array('Red')),
        ));

        $expected_hash = md5(serialize(array(
            array('name' => 'Color', 'options' => array('Red')),
        )));

        Functions\expect('update_post_meta')
            ->once()
            ->with(123, \Mockery::type('string'), $expected_hash)
            ->andReturn(true);

        $this->extractor->save_synced_attributes_hash($product, 'https://store.com');

        // Method is void, verify that expectation was met
        $this->assertTrue(true);
    }

    /**
     * Create a mock WC_Product representing a variable product
     */
    private function create_mock_variable_product(array $options = []): \Mockery\MockInterface
    {
        $defaults = [
            'id'                 => 200,
            'name'               => 'Variable Product',
            'slug'               => 'variable-product',
            'status'             => 'publish',
            'featured'           => false,
            'catalog_visibility' => 'visible',
            'description'        => 'Variable description',
            'short_description'  => 'Short',
            'sku'                => 'VAR-PARENT',
            'regular_price'      => '50.00',
            'sale_price'         => '',
            'is_on_sale'         => false,
            'managing_stock'     => false,
            'stock_status'       => 'instock',
            'backorders'         => 'no',
            'has_weight'         => false,
            'has_dimensions'     => false,
            'tax_status'         => 'taxable',
            'tax_class'          => '',
            'category_ids'       => [],
            'tag_ids'            => [],
            'image_id'           => 0,
            'gallery_image_ids'  => [],
            'attributes'         => [],
            'default_attributes' => [],
        ];

        $options = array_merge($defaults, $options);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn($options['id']);
        $product->shouldReceive('get_name')->andReturn($options['name']);
        $product->shouldReceive('get_slug')->andReturn($options['slug']);
        $product->shouldReceive('get_type')->andReturn('variable');
        $product->shouldReceive('get_status')->andReturn($options['status']);
        $product->shouldReceive('is_featured')->andReturn($options['featured']);
        $product->shouldReceive('get_catalog_visibility')->andReturn($options['catalog_visibility']);
        $product->shouldReceive('get_description')->andReturn($options['description']);
        $product->shouldReceive('get_short_description')->andReturn($options['short_description']);
        $product->shouldReceive('get_sku')->andReturn($options['sku']);
        $product->shouldReceive('get_regular_price')->andReturn($options['regular_price']);
        $product->shouldReceive('get_sale_price')->andReturn($options['sale_price']);
        $product->shouldReceive('is_on_sale')->andReturn($options['is_on_sale']);
        $product->shouldReceive('get_date_on_sale_from')->andReturn(null);
        $product->shouldReceive('get_date_on_sale_to')->andReturn(null);
        $product->shouldReceive('managing_stock')->andReturn($options['managing_stock']);
        $product->shouldReceive('get_stock_status')->andReturn($options['stock_status']);
        $product->shouldReceive('has_weight')->andReturn($options['has_weight']);
        $product->shouldReceive('has_dimensions')->andReturn($options['has_dimensions']);
        $product->shouldReceive('get_tax_status')->andReturn($options['tax_status']);
        $product->shouldReceive('get_tax_class')->andReturn($options['tax_class']);
        $product->shouldReceive('get_category_ids')->andReturn($options['category_ids']);
        $product->shouldReceive('get_tag_ids')->andReturn($options['tag_ids']);
        $product->shouldReceive('get_image_id')->andReturn($options['image_id']);
        $product->shouldReceive('get_gallery_image_ids')->andReturn($options['gallery_image_ids']);
        $product->shouldReceive('get_attributes')->andReturn($options['attributes']);
        $product->shouldReceive('get_default_attributes')->andReturn($options['default_attributes']);
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('is_downloadable')->andReturn(false);
        $product->shouldReceive('is_virtual')->andReturn(false);

        return $product;
    }

    /**
     * Test build_product_data defaults to full_product for unknown type
     */
    public function test_build_product_data_unknown_type_defaults_to_full(): void
    {
        $product = $this->create_mock_product();

        Functions\expect('get_transient')
            ->andReturn(array());

        $data = $this->extractor->build_product_data($product, 'unknown_type');

        // Should have full product fields
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('sku', $data);
    }

    /**
     * Test format_variation_attributes for taxonomy attributes
     * Name resolves to human-readable label; value resolves to term name.
     */
    public function test_format_variation_attributes(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn(array(
            'attribute_pa_color' => 'red',
            'attribute_pa_size'  => 'large',
        ));

        Functions\expect('taxonomy_exists')
            ->twice()
            ->andReturn(true);

        Functions\expect('wc_attribute_label')
            ->with('pa_color')->once()->andReturn('Color');
        Functions\expect('wc_attribute_label')
            ->with('pa_size')->once()->andReturn('Size');

        $colorTerm = (object) ['name' => 'Red', 'slug' => 'red'];
        $sizeTerm  = (object) ['name' => 'Large', 'slug' => 'large'];

        Functions\expect('get_term_by')
            ->with('slug', 'red', 'pa_color')->once()->andReturn($colorTerm);
        Functions\expect('get_term_by')
            ->with('slug', 'large', 'pa_size')->once()->andReturn($sizeTerm);

        $result = $this->extractor->format_variation_attributes($variation);

        $this->assertCount(2, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertEquals('Red', $result[0]['option']);
        $this->assertEquals('Size', $result[1]['name']);
        $this->assertEquals('Large', $result[1]['option']);
    }

    /**
     * Test format_variation_attributes for non-taxonomy (custom) attributes
     * Name stays as-is; value stays as-is.
     */
    public function test_format_variation_attributes_custom(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn(array(
            'attribute_material' => 'Cotton',
        ));

        Functions\expect('taxonomy_exists')
            ->once()
            ->with('material')
            ->andReturn(false);

        $result = $this->extractor->format_variation_attributes($variation);

        $this->assertCount(1, $result);
        $this->assertEquals('material', $result[0]['name']);
        $this->assertEquals('Cotton', $result[0]['option']);
    }

    /**
     * Test build_variation_data
     */
    public function test_build_variation_data(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('VAR-SKU');
        $variation->shouldReceive('get_regular_price')->andReturn('50.00');
        $variation->shouldReceive('is_on_sale')->andReturn(false);
        $variation->shouldReceive('managing_stock')->andReturn(true);
        $variation->shouldReceive('get_stock_quantity')->andReturn(10);
        $variation->shouldReceive('get_stock_status')->andReturn('instock');
        $variation->shouldReceive('get_variation_attributes')->andReturn(array(
            'attribute_pa_color' => 'blue',
        ));
        $variation->shouldReceive('get_image_id')->andReturn(0);

        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'blue', 'pa_color')
            ->andReturn((object) ['name' => 'Blue', 'slug' => 'blue']);

        $result = $this->extractor->build_variation_data($variation);

        $this->assertEquals('VAR-SKU', $result['sku']);
        $this->assertEquals('50.00', $result['regular_price']);
        $this->assertTrue($result['manage_stock']);
        $this->assertEquals(10, $result['stock_quantity']);
        $this->assertCount(1, $result['attributes']);
        $this->assertEquals('Color', $result['attributes'][0]['name']);
        $this->assertEquals('Blue', $result['attributes'][0]['option']);
    }

    /**
     * Test build_variation_data with sale price
     */
    public function test_build_variation_data_with_sale(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('VAR-SKU');
        $variation->shouldReceive('get_regular_price')->andReturn('100.00');
        $variation->shouldReceive('is_on_sale')->andReturn(true);
        $variation->shouldReceive('get_sale_price')->andReturn('80.00');
        $variation->shouldReceive('managing_stock')->andReturn(false);
        $variation->shouldReceive('get_stock_status')->andReturn('instock');
        $variation->shouldReceive('get_variation_attributes')->andReturn(array());
        $variation->shouldReceive('get_image_id')->andReturn(0);

        $result = $this->extractor->build_variation_data($variation);

        $this->assertEquals('80.00', $result['sale_price']);
        $this->assertFalse($result['manage_stock']);
    }

    // =========================================
    // format_default_attributes — happy path
    // =========================================

    /**
     * Empty input returns empty array (no WP calls needed)
     */
    public function test_format_default_attributes_empty_input_returns_empty_array(): void
    {
        $result = $this->extractor->format_default_attributes([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Non-taxonomy attribute: key and value pass through unchanged
     */
    public function test_format_default_attributes_custom_attribute(): void
    {
        Functions\expect('taxonomy_exists')->once()->with('material')->andReturn(false);

        $result = $this->extractor->format_default_attributes(['material' => 'Cotton']);

        $this->assertCount(1, $result);
        $this->assertEquals('material', $result[0]['name']);
        $this->assertEquals('Cotton', $result[0]['option']);
    }

    /**
     * Taxonomy attribute: resolves to human-readable label and term name
     */
    public function test_format_default_attributes_taxonomy_with_term_resolution(): void
    {
        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'red', 'pa_color')
            ->andReturn((object) ['name' => 'Red', 'slug' => 'red']);

        $result = $this->extractor->format_default_attributes(['pa_color' => 'red']);

        $this->assertCount(1, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertEquals('Red', $result[0]['option']);
    }

    /**
     * Multiple attributes (one taxonomy, one custom) are both formatted correctly
     */
    public function test_format_default_attributes_multiple_attributes(): void
    {
        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('taxonomy_exists')->once()->with('finish')->andReturn(false);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'blue', 'pa_color')
            ->andReturn((object) ['name' => 'Blue', 'slug' => 'blue']);

        $result = $this->extractor->format_default_attributes([
            'pa_color' => 'blue',
            'finish'   => 'Matte',
        ]);

        $this->assertCount(2, $result);
        $this->assertEquals(['name' => 'Color', 'option' => 'Blue'], $result[0]);
        $this->assertEquals(['name' => 'finish', 'option' => 'Matte'], $result[1]);
    }

    // =========================================
    // get_full_product_data — variable product
    // =========================================

    /**
     * Simple product never includes default_attributes (is_type guard)
     */
    public function test_get_full_product_data_simple_product_has_no_default_attributes(): void
    {
        $product = $this->create_mock_product();

        Functions\expect('get_transient')->andReturn([]);

        $data = $this->extractor->get_full_product_data($product);

        $this->assertArrayNotHasKey('default_attributes', $data);
    }

    /**
     * Variable product with empty default_attributes does not include the key
     */
    public function test_get_full_product_data_variable_product_empty_default_attributes(): void
    {
        $product = $this->create_mock_variable_product(['default_attributes' => []]);

        Functions\expect('get_transient')->andReturn([]);

        $data = $this->extractor->get_full_product_data($product);

        $this->assertArrayNotHasKey('default_attributes', $data);
    }

    /**
     * Variable product with default_attributes includes the key with formatted data
     */
    public function test_get_full_product_data_variable_product_with_default_attributes(): void
    {
        $product = $this->create_mock_variable_product([
            'default_attributes' => ['pa_color' => 'red'],
        ]);

        Functions\expect('get_transient')->andReturn([]);
        Functions\expect('taxonomy_exists')->once()->with('pa_color')->andReturn(true);
        Functions\expect('wc_attribute_label')->once()->with('pa_color')->andReturn('Color');
        Functions\expect('get_term_by')
            ->once()->with('slug', 'red', 'pa_color')
            ->andReturn((object) ['name' => 'Red', 'slug' => 'red']);

        $data = $this->extractor->get_full_product_data($product);

        $this->assertArrayHasKey('default_attributes', $data);
        $this->assertCount(1, $data['default_attributes']);
        $this->assertEquals(['name' => 'Color', 'option' => 'Red'], $data['default_attributes'][0]);
    }

    /**
     * Test build_variation_data with image
     */
    public function test_build_variation_data_with_image(): void
    {
        $variation = \Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_sku')->andReturn('VAR-SKU');
        $variation->shouldReceive('get_regular_price')->andReturn('50.00');
        $variation->shouldReceive('is_on_sale')->andReturn(false);
        $variation->shouldReceive('managing_stock')->andReturn(false);
        $variation->shouldReceive('get_stock_status')->andReturn('instock');
        $variation->shouldReceive('get_variation_attributes')->andReturn(array());
        $variation->shouldReceive('get_image_id')->andReturn(789);

        Functions\expect('get_transient')
            ->andReturn(false);

        Functions\expect('get_option')
            ->andReturn(array());

        Functions\expect('set_transient')
            ->andReturn(true);

        Functions\expect('wp_get_attachment_url')
            ->once()
            ->with(789)
            ->andReturn('https://example.com/variation-image.jpg');

        $result = $this->extractor->build_variation_data($variation);

        $this->assertArrayHasKey('image', $result);
        $this->assertEquals('https://example.com/variation-image.jpg', $result['image']['src']);
    }
}
