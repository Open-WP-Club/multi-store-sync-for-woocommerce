<?php
/**
 * Unit tests for WC_Multi_Store_Deletion_Audit
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class DeletionAuditTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeletionAuditMocks();
    }

    protected function setUpDeletionAuditMocks(): void
    {
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('sanitize_sql_orderby')->alias(fn($val) => $val);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Deletion_Audit'));
    }

    public function test_log_deletion_product_not_found(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $result = WC_Multi_Store_Deletion_Audit::log_deletion(999, ['https://store.com']);

        $this->assertFalse($result);
    }

    public function test_log_deletion_success(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_sku')->andReturn('PROD-001');
        $product->shouldReceive('get_name')->andReturn('Test Product');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('29.99');
        $product->shouldReceive('get_regular_price')->andReturn('29.99');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(10);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        Functions\when('wc_get_product')->justReturn($product);

        $current_user = (object) ['ID' => 1, 'display_name' => 'Admin'];
        Functions\when('wp_get_current_user')->justReturn($current_user);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 7;
        $wpdb->last_error = '';
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $data) {
                return $table === 'wp_wc_mss_deletion_audit'
                    && $data['product_id'] === 42
                    && $data['product_sku'] === 'PROD-001'
                    && $data['deletion_type'] === 'manual'
                    && $data['status'] === 'pending';
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Deletion_Audit::log_deletion(42, ['https://store.com'], 'manual');

        $this->assertEquals(7, $result);
    }

    public function test_log_deletion_db_failure(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_sku')->andReturn('PROD-001');
        $product->shouldReceive('get_name')->andReturn('Test Product');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('29.99');
        $product->shouldReceive('get_regular_price')->andReturn('29.99');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(10);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        Functions\when('wc_get_product')->justReturn($product);

        $current_user = (object) ['ID' => 1, 'display_name' => 'Admin'];
        Functions\when('wp_get_current_user')->justReturn($current_user);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = 'Database error';
        $wpdb->shouldReceive('insert')->andReturn(false);

        $result = WC_Multi_Store_Deletion_Audit::log_deletion(42, ['https://store.com']);

        $this->assertFalse($result);
    }

    public function test_update_status_completed(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                return $data['status'] === 'completed'
                    && isset($data['completed_at'])
                    && $where['id'] === 7;
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Deletion_Audit::update_status(7, 'completed');

        $this->assertTrue($result);
    }

    public function test_update_status_failed_with_error_message(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                return $data['status'] === 'failed'
                    && $data['error_message'] === 'API timeout'
                    && isset($data['completed_at'])
                    && $where['id'] === 7;
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Deletion_Audit::update_status(7, 'failed', 'API timeout');

        $this->assertTrue($result);
    }

    public function test_update_status_pending_no_completed_at(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                // 'pending' should NOT set completed_at
                return $data['status'] === 'pending'
                    && !isset($data['completed_at']);
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Deletion_Audit::update_status(7, 'pending');

        $this->assertTrue($result);
    }

    public function test_capture_product_data_simple_product(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Test Product');
        $product->shouldReceive('get_sku')->andReturn('SKU-001');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('19.99');
        $product->shouldReceive('get_regular_price')->andReturn('24.99');
        $product->shouldReceive('get_sale_price')->andReturn('19.99');
        $product->shouldReceive('get_stock_quantity')->andReturn(50);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $method = new ReflectionMethod(WC_Multi_Store_Deletion_Audit::class, 'capture_product_data');

        $data = $method->invoke(null, $product);

        $this->assertEquals(42, $data['id']);
        $this->assertEquals('Test Product', $data['name']);
        $this->assertEquals('SKU-001', $data['sku']);
        $this->assertEquals('simple', $data['type']);
        $this->assertEquals('19.99', $data['price']);
        $this->assertEquals(50, $data['stock_quantity']);
        $this->assertTrue($data['manage_stock']);
        $this->assertEmpty($data['categories']);
        $this->assertEmpty($data['tags']);
    }

    public function test_capture_product_data_with_categories_and_tags(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(42);
        $product->shouldReceive('get_name')->andReturn('Test');
        $product->shouldReceive('get_sku')->andReturn('');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('is_type')->with('variable')->andReturn(false);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_price')->andReturn('0');
        $product->shouldReceive('get_regular_price')->andReturn('0');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_manage_stock')->andReturn(false);
        $product->shouldReceive('get_category_ids')->andReturn([10, 20]);
        $product->shouldReceive('get_tag_ids')->andReturn([30]);
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);

        $cat1 = (object) ['name' => 'Electronics'];
        $cat2 = (object) ['name' => 'Gadgets'];
        $tag1 = (object) ['name' => 'Sale'];

        Functions\when('get_term')->alias(function ($id, $taxonomy) use ($cat1, $cat2, $tag1) {
            if ($id === 10) return $cat1;
            if ($id === 20) return $cat2;
            if ($id === 30) return $tag1;
            return null;
        });

        $method = new ReflectionMethod(WC_Multi_Store_Deletion_Audit::class, 'capture_product_data');

        $data = $method->invoke(null, $product);

        $this->assertCount(2, $data['categories']);
        $this->assertContains('Electronics', $data['categories']);
        $this->assertContains('Gadgets', $data['categories']);
        $this->assertCount(1, $data['tags']);
        $this->assertContains('Sale', $data['tags']);
    }

    public function test_get_logs_default_args(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $result = WC_Multi_Store_Deletion_Audit::get_logs();

        $this->assertIsArray($result);
    }

    public function test_get_logs_decodes_json_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([
            [
                'id' => 1,
                'stores_affected' => '["https://store1.com","https://store2.com"]',
                'product_data_before' => '{"id":42,"name":"Test"}',
                'product_data_after' => 'null',
            ],
        ]);

        $result = WC_Multi_Store_Deletion_Audit::get_logs();

        $this->assertIsArray($result[0]['stores_affected']);
        $this->assertCount(2, $result[0]['stores_affected']);
        $this->assertIsArray($result[0]['product_data_before']);
        $this->assertEquals(42, $result[0]['product_data_before']['id']);
    }

    public function test_get_total_count_no_filters(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('15');

        $count = WC_Multi_Store_Deletion_Audit::get_total_count();

        $this->assertEquals(15, $count);
    }

    public function test_get_total_count_with_filters(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('3');

        $count = WC_Multi_Store_Deletion_Audit::get_total_count([
            'status' => 'completed',
            'deletion_type' => 'bulk',
        ]);

        $this->assertEquals(3, $count);
    }

    public function test_cleanup_old_logs(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('query')->andReturn(12);

        $result = WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90);

        $this->assertEquals(12, $result);
    }

    public function test_cleanup_old_logs_default_days(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('query')->andReturn(5);

        $result = WC_Multi_Store_Deletion_Audit::cleanup_old_logs();

        $this->assertEquals(5, $result);
    }

    public function test_cleanup_old_logs_archives_records_before_deleting(): void
    {
        global $wpdb;

        $temp_dir = sys_get_temp_dir() . '/wc-mss-test-' . uniqid();
        mkdir($temp_dir . '/wc-mss-logs', 0777, true);

        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $temp_dir,
            'baseurl' => 'http://example.com/wp-content/uploads',
        ]);

        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $records = [
            ['id' => 1, 'deleted_at' => '2024-01-01 00:00:00', 'product_id' => 10],
            ['id' => 2, 'deleted_at' => '2024-01-05 00:00:00', 'product_id' => 20],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->once()->andReturn($records);
        $wpdb->shouldReceive('query')->once()->andReturn(2);

        try {
            $result = WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90);

            $this->assertEquals(2, $result);

            $archive_files = glob($temp_dir . '/wc-mss-logs/deletion-audit-archive-*.json');
            $this->assertCount(1, $archive_files);

            $archived = json_decode(file_get_contents($archive_files[0]), true);
            $this->assertEquals(2, $archived['total_records']);
            $this->assertEquals($records, $archived['records']);
        } finally {
            array_map('unlink', glob($temp_dir . '/wc-mss-logs/*') ?: []);
            @rmdir($temp_dir . '/wc-mss-logs');
            @rmdir($temp_dir);
        }
    }
}
