<?php
/**
 * Extended unit tests for WC_Multi_Store_Custom_Field_Mapper
 * Tests get_product_custom_fields
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class CustomFieldMapperExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('maybe_unserialize')->alias(function ($data) {
            if (is_string($data)) {
                $result = @unserialize($data);
                return $result !== false ? $result : $data;
            }
            return $data;
        });
    }

    // ── get_product_custom_fields ────────────────────────────────

    public function test_get_product_custom_fields_returns_fields(): void
    {
        // get_post_meta() already unserializes each value (unlike raw SQL,
        // which needs an explicit maybe_unserialize() call).
        Functions\when('get_post_meta')->alias(function ($product_id) {
            return [
                '_sku' => ['ABC-123'], // underscore-prefixed WC-internal field, must be excluded
                'custom_color' => ['red'],
                'custom_size' => ['L'],
                'custom_data' => [['key' => 'value']],
            ];
        });

        $result = WC_Multi_Store_Custom_Field_Mapper::get_product_custom_fields(42);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('_sku', $result);
        $this->assertEquals('red', $result['custom_color']);
        $this->assertEquals('L', $result['custom_size']);
        $this->assertIsArray($result['custom_data']);
        $this->assertEquals('value', $result['custom_data']['key']);
    }

    public function test_get_product_custom_fields_empty(): void
    {
        Functions\when('get_post_meta')->justReturn([]);

        $result = WC_Multi_Store_Custom_Field_Mapper::get_product_custom_fields(42);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_product_custom_fields_respects_excluded_meta_keys_filter(): void
    {
        Functions\when('get_post_meta')->justReturn([
            'custom_color' => ['red'],
            'excluded_by_filter' => ['hidden'],
        ]);
        Functions\when('apply_filters')->alias(function ($tag, $value) {
            return $tag === 'wc_mss_excluded_meta_keys' ? [...$value, 'excluded_by_filter'] : $value;
        });

        $result = WC_Multi_Store_Custom_Field_Mapper::get_product_custom_fields(42);

        $this->assertArrayHasKey('custom_color', $result);
        $this->assertArrayNotHasKey('excluded_by_filter', $result);
    }

    // ── sync_custom_fields edge cases ────────────────────────────

    public function test_sync_custom_fields_with_no_matching_fields(): void
    {
        // Product has no matching custom fields
        Functions\when('get_post_meta')->justReturn(['unrelated_field' => ['value']]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        // Should NOT call update_product since no fields match mapping
        $mock_api->shouldNotReceive('update_product');

        $result = WC_Multi_Store_Custom_Field_Mapper::sync_custom_fields(
            42,
            ['nonexistent_field' => 'remote_field'],
            $mock_api,
            100
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['fields_synced']);
    }

    public function test_sync_custom_fields_api_error(): void
    {
        Functions\when('get_post_meta')->justReturn(['my_color' => ['blue']]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('update_product')
            ->andReturn(new \WP_Error('api_error', 'Unauthorized'));

        $result = WC_Multi_Store_Custom_Field_Mapper::sync_custom_fields(
            42,
            ['my_color' => 'remote_color'],
            $mock_api,
            100
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unauthorized', $result['message']);
    }
}
