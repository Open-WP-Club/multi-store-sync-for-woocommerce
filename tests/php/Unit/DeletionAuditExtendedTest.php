<?php
/**
 * Extended unit tests for WC_Multi_Store_Deletion_Audit
 * Covers: capture_product_data for variable products, gallery images, main image, WP_Error terms
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class DeletionAuditExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('sanitize_sql_orderby')->alias(fn($val) => $val);
        // Logger infrastructure — needed because log_deletion() calls Logger::write()
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir() . '/wc-mss-tests',
            'baseurl' => 'http://example.com/wp-content/uploads',
        ]);
        Functions\when('wp_mkdir_p')->justReturn(true);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    private function getCaptureMethod(): ReflectionMethod
    {
        $method = new ReflectionMethod(WC_Multi_Store_Deletion_Audit::class, 'capture_product_data');
        return $method;
    }

    // ─── Variable product with variations ─────────

    public function test_capture_variable_product_with_variations(): void
    {
        $variation1 = \Mockery::mock('WC_Product');
        $variation1->shouldReceive('get_sku')->andReturn('VAR-001');
        $variation1->shouldReceive('get_price')->andReturn('19.99');
        $variation1->shouldReceive('get_stock_quantity')->andReturn(5);

        $variation2 = \Mockery::mock('WC_Product');
        $variation2->shouldReceive('get_sku')->andReturn('VAR-002');
        $variation2->shouldReceive('get_price')->andReturn('24.99');
        $variation2->shouldReceive('get_stock_quantity')->andReturn(10);

        Functions\when('wc_get_product')->alias(function ($id) use ($variation1, $variation2) {
            if ($id === 101) return $variation1;
            if ($id === 102) return $variation2;
            return false;
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Variable Shirt');
        $product->shouldReceive('get_sku')->andReturn('SHIRT-VAR');
        $product->shouldReceive('get_type')->andReturn('variable');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('19.99');
        $product->shouldReceive('get_regular_price')->andReturn('29.99');
        $product->shouldReceive('get_sale_price')->andReturn('19.99');
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(false);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_children')->andReturn([101, 102]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        $this->assertEquals('variable', $data['type']);
        $this->assertArrayHasKey('variations', $data);
        $this->assertCount(2, $data['variations']);

        $this->assertEquals(101, $data['variations'][0]['id']);
        $this->assertEquals('VAR-001', $data['variations'][0]['sku']);
        $this->assertEquals('19.99', $data['variations'][0]['price']);
        $this->assertEquals(5, $data['variations'][0]['stock']);

        $this->assertEquals(102, $data['variations'][1]['id']);
        $this->assertEquals('VAR-002', $data['variations'][1]['sku']);
        $this->assertEquals('24.99', $data['variations'][1]['price']);
        $this->assertEquals(10, $data['variations'][1]['stock']);
    }

    public function test_capture_variable_product_with_missing_variation(): void
    {
        // One variation exists, one doesn't
        $variation1 = \Mockery::mock('WC_Product');
        $variation1->shouldReceive('get_sku')->andReturn('VAR-001');
        $variation1->shouldReceive('get_price')->andReturn('15.00');
        $variation1->shouldReceive('get_stock_quantity')->andReturn(3);

        Functions\when('wc_get_product')->alias(function ($id) use ($variation1) {
            if ($id === 101) return $variation1;
            return false; // variation 102 not found
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Partial Variable');
        $product->shouldReceive('get_sku')->andReturn('PART-VAR');
        $product->shouldReceive('get_type')->andReturn('variable');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('15.00');
        $product->shouldReceive('get_regular_price')->andReturn('15.00');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(false);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_children')->andReturn([101, 102]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        // Only the existing variation should be captured
        $this->assertCount(1, $data['variations']);
        $this->assertEquals(101, $data['variations'][0]['id']);
    }

    public function test_capture_variable_product_with_no_variations(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Empty Variable');
        $product->shouldReceive('get_sku')->andReturn('EMPTY-VAR');
        $product->shouldReceive('get_type')->andReturn('variable');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('');
        $product->shouldReceive('get_regular_price')->andReturn('');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('outofstock');
        $product->shouldReceive('get_manage_stock')->andReturn(false);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_children')->andReturn([]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        $this->assertArrayHasKey('variations', $data);
        $this->assertEmpty($data['variations']);
    }

    // ─── Main image ───────────────────────────────

    public function test_capture_product_with_main_image(): void
    {
        Functions\when('wp_get_attachment_url')->alias(function ($id) {
            return "https://example.com/wp-content/uploads/image-{$id}.jpg";
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Image Product');
        $product->shouldReceive('get_sku')->andReturn('IMG-001');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('10.00');
        $product->shouldReceive('get_regular_price')->andReturn('10.00');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(100);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(55);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        $this->assertArrayHasKey('main', $data['images']);
        $this->assertEquals('https://example.com/wp-content/uploads/image-55.jpg', $data['images']['main']);
    }

    public function test_capture_product_without_main_image(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('No Image');
        $product->shouldReceive('get_sku')->andReturn('NOIMG');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('5.00');
        $product->shouldReceive('get_regular_price')->andReturn('5.00');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(0);
        $product->shouldReceive('get_stock_status')->andReturn('outofstock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        $this->assertArrayNotHasKey('main', $data['images']);
    }

    // ─── Gallery images ───────────────────────────

    public function test_capture_product_with_gallery_images(): void
    {
        Functions\when('wp_get_attachment_url')->alias(function ($id) {
            return "https://example.com/uploads/img-{$id}.jpg";
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Gallery Product');
        $product->shouldReceive('get_sku')->andReturn('GAL-001');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('25.00');
        $product->shouldReceive('get_regular_price')->andReturn('25.00');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(20);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(50);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([60, 70, 80]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        $this->assertEquals('https://example.com/uploads/img-50.jpg', $data['images']['main']);
        $this->assertArrayHasKey('gallery', $data['images']);
        $this->assertCount(3, $data['images']['gallery']);
        $this->assertEquals('https://example.com/uploads/img-60.jpg', $data['images']['gallery'][0]);
        $this->assertEquals('https://example.com/uploads/img-70.jpg', $data['images']['gallery'][1]);
        $this->assertEquals('https://example.com/uploads/img-80.jpg', $data['images']['gallery'][2]);
    }

    // ─── WP_Error terms ───────────────────────────

    public function test_capture_product_with_wp_error_category_term(): void
    {
        Functions\when('get_term')->justReturn(new WP_Error('invalid', 'Invalid term'));

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Error Terms Product');
        $product->shouldReceive('get_sku')->andReturn('ERR-001');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('10.00');
        $product->shouldReceive('get_regular_price')->andReturn('10.00');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(5);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([10, 20]);
        $product->shouldReceive('get_tag_ids')->andReturn([30]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        // WP_Error terms should be skipped
        $this->assertEmpty($data['categories']);
        $this->assertEmpty($data['tags']);
    }

    public function test_capture_product_with_null_term(): void
    {
        Functions\when('get_term')->justReturn(null);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Null Terms Product');
        $product->shouldReceive('get_sku')->andReturn('NULL-001');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('10.00');
        $product->shouldReceive('get_regular_price')->andReturn('10.00');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(5);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([10]);
        $product->shouldReceive('get_tag_ids')->andReturn([30]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        $this->assertEmpty($data['categories']);
        $this->assertEmpty($data['tags']);
    }

    public function test_capture_product_mixed_valid_and_invalid_terms(): void
    {
        $valid_cat = (object) ['name' => 'Clothing'];
        $valid_tag = (object) ['name' => 'New'];

        Functions\when('get_term')->alias(function ($id, $taxonomy) use ($valid_cat, $valid_tag) {
            if ($id === 10) return $valid_cat;
            if ($id === 20) return new WP_Error('invalid', 'Deleted');
            if ($id === 30) return $valid_tag;
            if ($id === 40) return null;
            return null;
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Mixed Terms');
        $product->shouldReceive('get_sku')->andReturn('MIX-001');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('30.00');
        $product->shouldReceive('get_regular_price')->andReturn('30.00');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(15);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([10, 20]);
        $product->shouldReceive('get_tag_ids')->andReturn([30, 40]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $data = $this->getCaptureMethod()->invoke(null, $product);

        $this->assertCount(1, $data['categories']);
        $this->assertContains('Clothing', $data['categories']);
        $this->assertCount(1, $data['tags']);
        $this->assertContains('New', $data['tags']);
    }

    // ─── Full log_deletion with variable product ──

    public function test_log_deletion_variable_product_captures_variations(): void
    {
        $variation = \Mockery::mock('WC_Product');
        $variation->shouldReceive('get_sku')->andReturn('VAR-SKU');
        $variation->shouldReceive('get_price')->andReturn('9.99');
        $variation->shouldReceive('get_stock_quantity')->andReturn(2);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Variable Product');
        $product->shouldReceive('get_sku')->andReturn('MAIN-SKU');
        $product->shouldReceive('get_type')->andReturn('variable');
        $product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('9.99');
        $product->shouldReceive('get_regular_price')->andReturn('14.99');
        $product->shouldReceive('get_sale_price')->andReturn('9.99');
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(false);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_children')->andReturn([101]);

        Functions\when('wc_get_product')->alias(function ($id) use ($product, $variation) {
            if ($id === 42) return $product;
            if ($id === 101) return $variation;
            return false;
        });

        $current_user = (object) ['ID' => 1, 'display_name' => 'Admin'];
        Functions\when('wp_get_current_user')->justReturn($current_user);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 15;
        $wpdb->last_error = '';
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $data) {
                $product_data = json_decode($data['product_data_before'], true);
                return $product_data['type'] === 'variable'
                    && !empty($product_data['variations'])
                    && $product_data['variations'][0]['sku'] === 'VAR-SKU';
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Deletion_Audit::log_deletion(42, ['https://store.com'], 'manual');

        $this->assertEquals(15, $result);
    }

    // ─── get_logs with filters ────────────────────

    public function test_get_logs_with_all_filters(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $result = WC_Multi_Store_Deletion_Audit::get_logs([
            'product_id' => 42,
            'user_id' => 1,
            'status' => 'completed',
            'deletion_type' => 'bulk',
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
            'limit' => 10,
            'offset' => 5,
        ]);

        $this->assertIsArray($result);
    }

    public function test_update_status_db_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')->andReturn(false);

        $result = WC_Multi_Store_Deletion_Audit::update_status(999, 'completed');

        $this->assertFalse($result);
    }
}
