<?php
/**
 * Unit tests for the field-comparison helpers on
 * WC_Multi_Store_Weekly_Verification_Comparator::check_full_product_fields().
 *
 * Each helper (compare_scalar_fields, compare_description_fields, compare_weight,
 * compare_dimensions, compare_tags, compare_images, compare_attributes) is a pure
 * private static method: WC_Product + remote object in, discrepancy array out.
 * Invoked via ReflectionMethod, mirroring the pattern used throughout
 * WeeklyVerificationRemoteDataFetcherTest.php.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WeeklyVerificationComparatorTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-remote-data-fetcher.php';
        }
        if (!class_exists('WC_Multi_Store_Weekly_Verification_Comparator', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-comparator.php';
        }

        // verify_product() calls WC_Multi_Store_Settings::get_settings() and, via
        // the RemoteDataFetcher, get_option()/get_transient()/get_post_meta().
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'match_products_by' => 'sku',
                    'category_match_by' => 'slug',
                ];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
    }

    private function invoke(string $method, array $args)
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, $method);
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
    // ── verify_product ────────────────────────────────────────────

    public function test_run_verification_happy_path_no_discrepancies(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 1]);
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('TEST-SKU');
            $product->shouldReceive('get_slug')->andReturn('test-product');
            $product->shouldReceive('get_name')->andReturn('Test Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('19.99');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        // Mock API client for get_remote_product
        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn([
                [
                    'id' => 100,
                    'sku' => 'TEST-SKU',
                    'stock_quantity' => 10,
                    'regular_price' => '19.99',
                    'sale_price' => '',
                    'categories' => [],
                ],
            ]);

        // Inject mock API client into the pool
        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        // Override WP_Query constructor to inject test posts
        // We use the get_products_to_verify which creates WP_Query
        // Since WP_Query is a stub, we need to make it return products
        // by patching the class temporarily
        $origClass = true;

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->andReturn(1);

        // Use reflection to call verify_product directly instead
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, 'verify_product');

        $stores = [
            'https://store1.com' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ],
        ];

        $settings = [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ];

        $result = $method->invoke(null, 42, $stores, $settings);

        $this->assertNotNull($result);
        $this->assertEmpty($result['discrepancies']);
    }

    public function test_verify_product_detects_stock_mismatch(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('SKU-STOCK');
            $product->shouldReceive('get_slug')->andReturn('stock-product');
            $product->shouldReceive('get_name')->andReturn('Stock Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(50);
            $product->shouldReceive('get_regular_price')->andReturn('10.00');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn([
                [
                    'id' => 100,
                    'sku' => 'SKU-STOCK',
                    'stock_quantity' => 30, // Mismatch: local=50, remote=30
                    'regular_price' => '10.00',
                    'sale_price' => '',
                    'categories' => [],
                ],
            ]);

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, 'verify_product');

        $stores = ['https://store1.com' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']];
        $settings = ['check_stock' => true, 'check_prices' => false, 'check_categories' => false];

        $result = $method->invoke(null, 42, $stores, $settings);

        $this->assertNotEmpty($result['discrepancies']);
        $stock_disc = array_filter($result['discrepancies'], fn($d) => $d['type'] === 'stock');
        $this->assertCount(1, $stock_disc);
        $disc = array_values($stock_disc)[0];
        $this->assertEquals(50, $disc['expected']);
        $this->assertEquals(30, $disc['actual']);
    }

    public function test_verify_product_detects_missing_product(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('MISSING-SKU');
            $product->shouldReceive('get_slug')->andReturn('missing-product');
            $product->shouldReceive('get_name')->andReturn('Missing Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('10.00');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')->andReturn([]); // Not found

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, 'verify_product');

        $stores = ['https://store1.com' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']];
        $settings = ['check_stock' => true, 'check_prices' => true, 'check_categories' => false];

        $result = $method->invoke(null, 42, $stores, $settings);

        $missing = array_filter($result['discrepancies'], fn($d) => $d['type'] === 'missing');
        $this->assertCount(1, $missing);
    }

    public function test_verify_product_detects_price_mismatch(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('PRICE-SKU');
            $product->shouldReceive('get_slug')->andReturn('price-product');
            $product->shouldReceive('get_name')->andReturn('Price Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('29.99');
            $product->shouldReceive('get_sale_price')->andReturn('19.99');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn([
                [
                    'id' => 100,
                    'sku' => 'PRICE-SKU',
                    'stock_quantity' => 10,
                    'regular_price' => '39.99', // Mismatch
                    'sale_price' => '29.99',    // Mismatch
                    'categories' => [],
                ],
            ]);

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, 'verify_product');

        $stores = ['https://store1.com' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']];
        $settings = ['check_stock' => false, 'check_prices' => true, 'check_categories' => false];

        $result = $method->invoke(null, 42, $stores, $settings);

        $price_disc = array_filter($result['discrepancies'], fn($d) => $d['type'] === 'price');
        $this->assertCount(2, $price_disc); // regular + sale price
    }

    // ── verify_product: edge cases ───────────────────────────────

    public function test_verify_product_skips_product_without_sku(): void
    {
        Functions\when('wc_get_product')->alias(function () {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_sku')->andReturn('');
            return $product;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, 'verify_product');

        $result = $method->invoke(null, 42, ['https://store1.com' => []], [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ]);

        $this->assertNull($result);
    }

    public function test_verify_product_returns_null_for_invalid_product(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, 'verify_product');

        $result = $method->invoke(null, 9999, ['https://store1.com' => []], [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ]);

        $this->assertNull($result);
    }

    public function test_verify_product_handles_api_error(): void
    {
        Functions\when('wc_get_product')->alias(function ($id) {
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_sku')->andReturn('ERROR-SKU');
            $product->shouldReceive('get_slug')->andReturn('error-product');
            $product->shouldReceive('get_name')->andReturn('Error Product');
            $product->shouldReceive('get_stock_quantity')->andReturn(10);
            $product->shouldReceive('get_regular_price')->andReturn('10.00');
            $product->shouldReceive('get_sale_price')->andReturn('');
            $product->shouldReceive('get_category_ids')->andReturn([]);
            $product->shouldReceive('get_tag_ids')->andReturn([]);
            return $product;
        });
        Functions\when('wp_get_post_terms')->justReturn([]);

        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('get_products')
            ->andReturn(new \WP_Error('api_error', 'Connection timeout'));

        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $mock_api]);

        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Comparator::class, 'verify_product');

        $store_config = [
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ];

        $result = $method->invoke(null, 42, ['https://store1.com' => $store_config], [
            'check_stock' => true,
            'check_prices' => true,
            'check_categories' => false,
        ]);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['discrepancies']);
        $this->assertEquals('error', $result['discrepancies'][0]['type']);
        $this->assertStringContainsString('Connection timeout', $result['discrepancies'][0]['message']);
    }
}
