<?php
/**
 * Unit tests for WC_Multi_Store_Stock_Verifier
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class StockVerifierTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockVerifierMocks();
    }

    protected function setUpStockVerifierMocks(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('untrailingslashit')->alias(fn($str) => rtrim($str, '/'));
        Functions\when('absint')->alias(fn($val) => abs((int)$val));
        Functions\when('sanitize_sql_orderby')->alias(fn($val) => $val);
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Stock_Verifier'));
    }

    public function test_verify_product_stock_product_not_found(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(999, 'https://store.com', 10);

        $this->assertFalse($result['success']);
        $this->assertEquals('Product not found', $result['error']);
    }

    public function test_verify_product_stock_no_sku(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('');

        Functions\when('wc_get_product')->justReturn($product);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://store.com', 10);

        $this->assertFalse($result['success']);
        $this->assertEquals('Product has no SKU', $result['error']);
    }

    public function test_verify_product_stock_store_not_found(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('TEST-001');

        Functions\when('wc_get_product')->justReturn($product);
        Functions\when('get_option')->justReturn([]);

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(1, 'https://unknown-store.com', 10);

        $this->assertFalse($result['success']);
        $this->assertEquals('Store not found in configuration', $result['error']);
    }

    public function test_get_discrepancies_returns_array(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT * FROM wp_wc_multi_store_stock_discrepancies WHERE 1=1 AND status = \'pending\' ORDER BY detected_at DESC LIMIT 50 OFFSET 0');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies();

        $this->assertIsArray($result);
    }

    public function test_get_discrepancies_with_filters(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => 1, 'product_id' => 42, 'status' => 'pending'],
        ]);

        $result = WC_Multi_Store_Stock_Verifier::get_discrepancies([
            'status' => 'pending',
            'store_url' => 'https://store.com',
            'product_id' => 42,
        ]);

        $this->assertCount(1, $result);
    }

    public function test_get_discrepancy_count_pending(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('5');

        $count = WC_Multi_Store_Stock_Verifier::get_discrepancy_count('pending');

        $this->assertEquals(5, $count);
    }

    public function test_get_discrepancy_count_all(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('12');

        $count = WC_Multi_Store_Stock_Verifier::get_discrepancy_count('all');

        $this->assertEquals(12, $count);
    }

    public function test_mark_resolved(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                return $data['status'] === 'resolved'
                    && isset($data['resolved_at'])
                    && $where['id'] === 42;
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Stock_Verifier::mark_resolved(42);

        $this->assertTrue($result);
    }

    public function test_mark_resolved_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')->andReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::mark_resolved(999);

        $this->assertFalse($result);
    }

    public function test_mark_ignored(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                return $data['status'] === 'ignored' && $where['id'] === 42;
            })
            ->andReturn(1);

        $result = WC_Multi_Store_Stock_Verifier::mark_ignored(42);

        $this->assertTrue($result);
    }

    public function test_cleanup_old_discrepancies(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(7);

        $result = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies(30);

        $this->assertEquals(7, $result);
    }

    public function test_auto_correct_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn(null);

        $result = WC_Multi_Store_Stock_Verifier::auto_correct(999);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    public function test_auto_correct_product_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn([
            'id' => 1,
            'product_id' => 999,
            'sku' => 'TEST-001',
            'store_url' => 'https://store.com',
        ]);

        Functions\when('wc_get_product')->justReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::auto_correct(1);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('product_not_found', $result->get_error_code());
    }

    public function test_schedule_verification_not_available(): void
    {
        // Action Scheduler not available in tests, should return early
        WC_Multi_Store_Stock_Verifier::schedule_verification(1, 'https://store.com', 10);
        $this->assertTrue(true);
    }
}
