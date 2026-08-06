<?php
/**
 * Unit tests for WC_Multi_Store_Custom_Field_Mapper
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class CustomFieldMapperTest extends WC_Multi_Store_TestCase
{
    /**
     * Test get_excluded_meta_keys returns expected keys
     */
    public function test_get_excluded_meta_keys_returns_expected_keys(): void
    {
        // Use reflection to test private method
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('get_excluded_meta_keys');

        $excluded_keys = $method->invoke(null);

        // Check that WooCommerce internal keys are excluded
        $expected_excluded = array(
            '_sku',
            '_regular_price',
            '_sale_price',
            '_price',
            '_stock',
            '_stock_status',
            '_manage_stock',
            '_weight',
            '_length',
            '_width',
            '_height',
            '_edit_lock',
            '_edit_last',
            '_thumbnail_id',
        );

        foreach ($expected_excluded as $key) {
            $this->assertContains($key, $excluded_keys, "Expected {$key} to be in excluded keys");
        }
    }

    /**
     * Test format_field_label removes common prefixes
     */
    public function test_format_field_label_removes_prefixes(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_field_label');

        $this->assertEquals('Field Name', $method->invoke(null, 'wc_field_name'));
        $this->assertEquals('Field Name', $method->invoke(null, 'product_field_name'));
        $this->assertEquals('Field Name', $method->invoke(null, 'custom_field_name'));
    }

    /**
     * Test format_field_label converts underscores and hyphens to spaces
     */
    public function test_format_field_label_converts_separators(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_field_label');

        $this->assertEquals('My Field Name', $method->invoke(null, 'my_field_name'));
        $this->assertEquals('My Field Name', $method->invoke(null, 'my-field-name'));
        $this->assertEquals('Test Field', $method->invoke(null, 'test_field'));
    }

    /**
     * Test format_field_label capitalizes words
     */
    public function test_format_field_label_capitalizes(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_field_label');

        $this->assertEquals('Some Field', $method->invoke(null, 'some_field'));
        $this->assertEquals('Another Test Field', $method->invoke(null, 'another_test_field'));
    }

    /**
     * Test sync_custom_fields returns success for empty mapping
     */
    public function test_sync_custom_fields_empty_mapping_returns_success(): void
    {
        $result = WC_Multi_Store_Custom_Field_Mapper::sync_custom_fields(123, array(), null, 456);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['fields_synced']);
        $this->assertStringContainsString('No custom fields configured', $result['message']);
    }

    /**
     * Test sync_custom_fields returns success for empty array mapping
     */
    public function test_sync_custom_fields_empty_array_mapping_returns_success(): void
    {
        $result = WC_Multi_Store_Custom_Field_Mapper::sync_custom_fields(123, [], null, 456);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['fields_synced']);
    }

    /**
     * Test format_acf_value returns value unchanged for unknown types
     */
    public function test_format_acf_value_unknown_type(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $value = 'test_value';
        $field_object = array('type' => 'text');

        $result = $method->invoke(null, $value, $field_object);

        $this->assertEquals($value, $result);
    }

    /**
     * Test format_acf_value handles repeater type
     */
    public function test_format_acf_value_repeater(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $value = array(array('row1' => 'data1'), array('row2' => 'data2'));
        $field_object = array('type' => 'repeater');

        $result = $method->invoke(null, $value, $field_object);

        $this->assertEquals($value, $result);
    }

    /**
     * Test format_acf_value handles group type
     */
    public function test_format_acf_value_group(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $value = array('field1' => 'value1', 'field2' => 'value2');
        $field_object = array('type' => 'group');

        $result = $method->invoke(null, $value, $field_object);

        $this->assertEquals($value, $result);
    }

    /**
     * Test format_acf_value handles date_picker type
     */
    public function test_format_acf_value_date_picker(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $value = '2025-01-26';
        $field_object = array('type' => 'date_picker');

        $result = $method->invoke(null, $value, $field_object);

        $this->assertEquals($value, $result);
    }

    /**
     * Test format_acf_value handles image type with numeric value
     */
    public function test_format_acf_value_image_numeric(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $attachment_id = 123;
        $field_object = array('type' => 'image');

        Functions\expect('wp_get_attachment_url')
            ->once()
            ->with($attachment_id)
            ->andReturn('https://example.com/image.jpg');

        $result = $method->invoke(null, $attachment_id, $field_object);

        $this->assertEquals('https://example.com/image.jpg', $result);
    }

    /**
     * Test format_acf_value handles gallery type with array of IDs
     */
    public function test_format_acf_value_gallery(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $attachment_ids = array(1, 2, 3);
        $field_object = array('type' => 'gallery');

        Functions\expect('wp_get_attachment_url')
            ->times(3)
            ->andReturnValues(array(
                'https://example.com/image1.jpg',
                'https://example.com/image2.jpg',
                'https://example.com/image3.jpg',
            ));

        $result = $method->invoke(null, $attachment_ids, $field_object);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals('https://example.com/image1.jpg', $result[0]);
    }

    /**
     * Test format_acf_value handles empty field type
     */
    public function test_format_acf_value_empty_type(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $value = 'test_value';
        $field_object = array();

        $result = $method->invoke(null, $value, $field_object);

        $this->assertEquals($value, $result);
    }

    /**
     * Test format_acf_value handles post_object type with single numeric value
     */
    public function test_format_acf_value_post_object_single(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $post_id = 456;
        $field_object = array('type' => 'post_object');

        $mock_post = new stdClass();
        $mock_post->post_title = 'Test Post';

        Functions\expect('get_post')
            ->once()
            ->with($post_id)
            ->andReturn($mock_post);

        $result = $method->invoke(null, $post_id, $field_object);

        $this->assertEquals('Test Post', $result);
    }

    /**
     * Test format_acf_value handles taxonomy type with single term ID
     */
    public function test_format_acf_value_taxonomy_single(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $term_id = 789;
        $field_object = array('type' => 'taxonomy');

        $mock_term = new stdClass();
        $mock_term->name = 'Test Category';

        // get_term returns a valid term object, is_wp_error will correctly return false
        Functions\expect('get_term')
            ->once()
            ->with($term_id)
            ->andReturn($mock_term);

        $result = $method->invoke(null, $term_id, $field_object);

        $this->assertEquals('Test Category', $result);
    }

    /**
     * Test format_acf_value handles taxonomy type with array of term IDs
     */
    public function test_format_acf_value_taxonomy_array(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Custom_Field_Mapper');
        $method = $reflection->getMethod('format_acf_value');

        $term_ids = array(1, 2);
        $field_object = array('type' => 'taxonomy');

        $term1 = new stdClass();
        $term1->name = 'Category 1';
        $term2 = new stdClass();
        $term2->name = 'Category 2';

        // get_term returns valid term objects, is_wp_error will correctly return false
        Functions\expect('get_term')
            ->times(2)
            ->andReturnValues(array($term1, $term2));

        $result = $method->invoke(null, $term_ids, $field_object);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains('Category 1', $result);
        $this->assertContains('Category 2', $result);
    }
}
