<?php
/**
 * Unit tests for the field-comparison helpers extracted from
 * WC_Multi_Store_Weekly_Sync_Verifier::check_full_product_fields().
 *
 * Each helper (compare_scalar_fields, compare_description_fields, compare_weight,
 * compare_dimensions, compare_tags, compare_images, compare_attributes) is a pure
 * private static method: WC_Product + remote object in, discrepancy array out.
 * Invoked via ReflectionMethod, mirroring the pattern used throughout
 * WeeklySyncVerifierExtendedTest.php.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WeeklySyncVerifierFieldComparisonTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('WC_Multi_Store_Weekly_Sync_Verifier', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-sync-verifier.php';
        }
    }

    private function invoke(string $method, array $args)
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, $method);
        return $ref->invoke(null, ...$args);
    }

    // ── compare_scalar_fields ────────────────────────────────────

    public function test_compare_scalar_fields_no_discrepancy_when_all_match(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('is_featured')->andReturn(false);
        $product->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $product->shouldReceive('get_tax_status')->andReturn('taxable');
        $product->shouldReceive('get_tax_class')->andReturn('');
        $product->shouldReceive('get_backorders')->andReturn('no');

        $remote = (object) [
            'name' => 'Widget',
            'status' => 'publish',
            'featured' => false,
            'catalog_visibility' => 'visible',
            'tax_status' => 'taxable',
            'tax_class' => '',
            'backorders' => 'no',
        ];

        $result = $this->invoke('compare_scalar_fields', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result);
    }

    public function test_compare_scalar_fields_detects_name_mismatch(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('is_featured')->andReturn(false);
        $product->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $product->shouldReceive('get_tax_status')->andReturn('taxable');
        $product->shouldReceive('get_tax_class')->andReturn('');
        $product->shouldReceive('get_backorders')->andReturn('no');

        $remote = (object) [
            'name' => 'Gadget', // mismatch
            'status' => 'publish',
            'featured' => false,
            'catalog_visibility' => 'visible',
            'tax_status' => 'taxable',
            'tax_class' => '',
            'backorders' => 'no',
        ];

        $result = $this->invoke('compare_scalar_fields', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertCount(1, $result);
        $this->assertSame('name', $result[0]['field']);
        $this->assertSame('Widget', $result[0]['expected']);
        $this->assertSame('Gadget', $result[0]['actual']);
    }

    public function test_compare_scalar_fields_skips_when_remote_field_is_null_not_just_falsy(): void
    {
        // Regression guard: the code checks `$remote_val === null`, not empty()/falsy.
        // A remote status of '' (empty string, not null) MUST still be compared and
        // flagged, whereas a genuinely absent/null field must be skipped.
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('is_featured')->andReturn(false);
        $product->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $product->shouldReceive('get_tax_status')->andReturn('taxable');
        $product->shouldReceive('get_tax_class')->andReturn('some-class');
        $product->shouldReceive('get_backorders')->andReturn('no');

        $remote = (object) [
            'name' => 'Widget',
            'status' => 'publish',
            'featured' => false,
            'catalog_visibility' => 'visible',
            'tax_status' => 'taxable',
            // tax_class deliberately omitted -> null via ?? null -> must be skipped
            'backorders' => 'no',
        ];

        $result = $this->invoke('compare_scalar_fields', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result, 'A null remote field must be skipped, not flagged as a discrepancy');
    }

    // ── compare_description_fields ───────────────────────────────

    public function test_compare_description_fields_no_discrepancy_when_equal(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_description')->andReturn('Full description text.');
        $product->shouldReceive('get_short_description')->andReturn('Short text.');

        $remote = (object) [
            'description' => 'Full description text.',
            'short_description' => 'Short text.',
        ];

        $result = $this->invoke('compare_description_fields', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result);
    }

    public function test_compare_description_fields_normalizes_line_endings_and_whitespace(): void
    {
        // Regression guard: CRLF vs LF and surrounding whitespace must NOT be flagged.
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_description')->andReturn("Line one\r\nLine two");
        $product->shouldReceive('get_short_description')->andReturn('  Short text.  ');

        $remote = (object) [
            'description' => "  Line one\nLine two  ",
            'short_description' => "Short text.",
        ];

        $result = $this->invoke('compare_description_fields', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result, 'Line-ending/whitespace differences must be normalized away');
    }

    public function test_compare_description_fields_detects_real_mismatch(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_description')->andReturn('Original description.');
        $product->shouldReceive('get_short_description')->andReturn('Short.');

        $remote = (object) [
            'description' => 'Completely different description.',
            'short_description' => 'Short.',
        ];

        $result = $this->invoke('compare_description_fields', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertCount(1, $result);
        $this->assertSame('description', $result[0]['field']);
        $this->assertSame('Original description.', $result[0]['expected']);
        $this->assertSame('Completely different description.', $result[0]['actual']);
    }

    // ── compare_weight ────────────────────────────────────────────

    public function test_compare_weight_no_discrepancy_when_equal(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_weight')->andReturn(true);
        $product->shouldReceive('get_weight')->andReturn('2.5');

        $remote = (object) ['weight' => '2.5'];

        $result = $this->invoke('compare_weight', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result);
    }

    public function test_compare_weight_within_float_tolerance_is_not_flagged(): void
    {
        // Regression guard: 0.0001 tolerance must absorb tiny float rounding noise.
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_weight')->andReturn(true);
        $product->shouldReceive('get_weight')->andReturn('2.50000');

        $remote = (object) ['weight' => '2.50005']; // diff = 0.00005 < 0.0001

        $result = $this->invoke('compare_weight', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result, 'Differences within 0.0001 tolerance must not be flagged');
    }

    public function test_compare_weight_detects_mismatch_beyond_tolerance(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_weight')->andReturn(true);
        $product->shouldReceive('get_weight')->andReturn('2.5');

        $remote = (object) ['weight' => '3.0'];

        $result = $this->invoke('compare_weight', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertCount(1, $result);
        $this->assertSame('weight', $result[0]['field']);
        $this->assertSame(2.5, $result[0]['expected']);
        $this->assertSame(3.0, $result[0]['actual']);
    }

    public function test_compare_weight_skips_when_product_has_no_weight(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_weight')->andReturn(false);

        $remote = (object) ['weight' => '99'];

        $result = $this->invoke('compare_weight', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result);
    }

    // ── compare_dimensions ────────────────────────────────────────

    public function test_compare_dimensions_no_discrepancy_when_equal(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_dimensions')->andReturn(true);
        $product->shouldReceive('get_length')->andReturn('10');
        $product->shouldReceive('get_width')->andReturn('5');
        $product->shouldReceive('get_height')->andReturn('2');

        $remote = (object) ['dimensions' => ['length' => '10', 'width' => '5', 'height' => '2']];

        $result = $this->invoke('compare_dimensions', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result);
    }

    public function test_compare_dimensions_within_float_tolerance_is_not_flagged(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_dimensions')->andReturn(true);
        $product->shouldReceive('get_length')->andReturn('10.00000');
        $product->shouldReceive('get_width')->andReturn('5');
        $product->shouldReceive('get_height')->andReturn('2');

        $remote = (object) ['dimensions' => ['length' => '10.00005', 'width' => '5', 'height' => '2']];

        $result = $this->invoke('compare_dimensions', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result, 'Differences within 0.0001 tolerance must not be flagged');
    }

    public function test_compare_dimensions_detects_single_field_mismatch(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_dimensions')->andReturn(true);
        $product->shouldReceive('get_length')->andReturn('10');
        $product->shouldReceive('get_width')->andReturn('5');
        $product->shouldReceive('get_height')->andReturn('2');

        $remote = (object) ['dimensions' => ['length' => '10', 'width' => '99', 'height' => '2']];

        $result = $this->invoke('compare_dimensions', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertCount(1, $result);
        $this->assertSame('dimensions.width', $result[0]['field']);
        $this->assertSame(5.0, $result[0]['expected']);
        $this->assertSame(99.0, $result[0]['actual']);
    }

    public function test_compare_dimensions_skips_when_product_has_no_dimensions(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('has_dimensions')->andReturn(false);

        $remote = (object) ['dimensions' => ['length' => '999', 'width' => '999', 'height' => '999']];

        $result = $this->invoke('compare_dimensions', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result);
    }

    // ── compare_tags ──────────────────────────────────────────────

    public function test_compare_tags_no_discrepancy_when_same_tags_different_order(): void
    {
        // Regression guard: order must not cause a false positive; both sides are sorted.
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_tag_ids')->andReturn([1, 2, 3]);

        Functions\when('get_terms')->justReturn([
            (object) ['slug' => 'zebra'],
            (object) ['slug' => 'apple'],
            (object) ['slug' => 'mango'],
        ]);

        $remote = (object) [
            'tags' => [
                ['slug' => 'mango'],
                ['slug' => 'zebra'],
                ['slug' => 'apple'],
            ],
        ];

        $result = $this->invoke('compare_tags', [$product, $remote, 'https://store1.com', 'Store 1', 'slug']);

        $this->assertSame([], $result, 'Same tags in different order must not be flagged as a discrepancy');
    }

    public function test_compare_tags_detects_missing_and_extra_tags(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_tag_ids')->andReturn([1, 2]);

        Functions\when('get_terms')->justReturn([
            (object) ['slug' => 'apple'],
            (object) ['slug' => 'mango'],
        ]);

        $remote = (object) [
            'tags' => [
                ['slug' => 'apple'],
                ['slug' => 'banana'], // extra on remote
            ],
        ];

        $result = $this->invoke('compare_tags', [$product, $remote, 'https://store1.com', 'Store 1', 'slug']);

        $this->assertCount(1, $result);
        $this->assertSame('tag', $result[0]['type']);
        $this->assertSame(['mango'], $result[0]['missing']);
        $this->assertSame(['banana'], $result[0]['extra']);
    }

    public function test_compare_tags_skips_when_product_has_no_tags(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_tag_ids')->andReturn([]);

        $remote = (object) ['tags' => [['slug' => 'whatever']]];

        $result = $this->invoke('compare_tags', [$product, $remote, 'https://store1.com', 'Store 1', 'slug']);

        $this->assertSame([], $result);
    }

    public function test_compare_tags_matches_by_name_when_configured(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_tag_ids')->andReturn([1]);

        Functions\when('get_terms')->justReturn([
            (object) ['slug' => 'red-tag', 'name' => 'Red Tag'],
        ]);

        $remote = (object) ['tags' => [['slug' => 'different-slug', 'name' => 'Red Tag']]];

        $result = $this->invoke('compare_tags', [$product, $remote, 'https://store1.com', 'Store 1', 'name']);

        $this->assertSame([], $result, 'When match_by=name, slug differences must be irrelevant');
    }

    // ── compare_images ────────────────────────────────────────────

    public function test_compare_images_no_discrepancy_when_same_images_different_order(): void
    {
        // Regression guard: order must not cause a false positive; both sides are sorted.
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([11, 12]);

        Functions\when('wp_get_attachment_url')->alias(function ($id) {
            return match ($id) {
                10 => 'https://example.com/uploads/main.jpg',
                11 => 'https://example.com/uploads/gallery-b.jpg',
                12 => 'https://example.com/uploads/gallery-a.jpg',
                default => false,
            };
        });

        $remote = (object) [
            'images' => [
                ['src' => 'https://cdn.example.com/gallery-a.jpg?ver=2'],
                ['src' => 'https://cdn.example.com/main.jpg'],
                ['src' => 'https://cdn.example.com/gallery-b.jpg'],
            ],
        ];

        $result = $this->invoke('compare_images', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result, 'Same image filenames in different order must not be flagged');
    }

    public function test_compare_images_detects_missing_and_extra(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        Functions\when('wp_get_attachment_url')->alias(function ($id) {
            return $id === 10 ? 'https://example.com/uploads/main.jpg' : false;
        });

        $remote = (object) [
            'images' => [
                ['src' => 'https://cdn.example.com/other.jpg'],
            ],
        ];

        $result = $this->invoke('compare_images', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertCount(1, $result);
        $this->assertSame('image', $result[0]['type']);
        $this->assertSame(['main.jpg'], $result[0]['missing']);
        $this->assertSame(['other.jpg'], $result[0]['extra']);
    }

    public function test_compare_images_strips_query_string_before_comparing(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/uploads/main.jpg?ver=123');

        $remote = (object) ['images' => [['src' => 'https://cdn.example.com/main.jpg?resize=300']]];

        $result = $this->invoke('compare_images', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result, 'Query strings must be stripped before filename comparison');
    }

    // ── compare_attributes ────────────────────────────────────────

    public function test_compare_attributes_no_discrepancy_when_options_match_different_order(): void
    {
        // Regression guard: option order (and attribute key order) must not cause
        // a false positive; both sides are sorted and ksorted.
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_attributes')->andReturn([
            'color' => ['Red', 'Blue', 'Green'],
            'size' => ['Large', 'Small'],
        ]);

        $remote = (object) [
            'attributes' => [
                ['name' => 'size', 'options' => ['Small', 'Large']],
                ['name' => 'color', 'options' => ['Green', 'Red', 'Blue']],
            ],
        ];

        $result = $this->invoke('compare_attributes', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result, 'Same attribute options in different order must not be flagged');
    }

    public function test_compare_attributes_detects_mismatch(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_attributes')->andReturn([
            'color' => ['Red', 'Blue'],
        ]);

        $remote = (object) [
            'attributes' => [
                ['name' => 'color', 'options' => ['Red', 'Green']],
            ],
        ];

        $result = $this->invoke('compare_attributes', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertCount(1, $result);
        $this->assertSame('attribute', $result[0]['type']);
        $this->assertSame(['color' => ['Blue', 'Red']], $result[0]['expected']);
        $this->assertSame(['color' => ['Green', 'Red']], $result[0]['actual']);
    }

    public function test_compare_attributes_no_discrepancy_when_no_attributes_either_side(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_attributes')->andReturn([]);

        $remote = (object) []; // no 'attributes' property at all

        $result = $this->invoke('compare_attributes', [$product, $remote, 'https://store1.com', 'Store 1']);

        $this->assertSame([], $result);
    }
}
