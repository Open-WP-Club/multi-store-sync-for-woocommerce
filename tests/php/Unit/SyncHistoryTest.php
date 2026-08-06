<?php
/**
 * Unit tests for WC_Multi_Store_Sync_History
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncHistoryTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('absint')->alias(fn($v) => abs(intval($v)));
        Functions\when('sanitize_textarea_field')->alias(fn($v) => trim(strip_tags($v)));
        Functions\when('esc_url_raw')->alias(fn($v) => filter_var($v, FILTER_SANITIZE_URL));
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        if (!defined('DB_NAME')) {
            define('DB_NAME', 'wp_test');
        }
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Sync_History'));
    }

    public function test_has_required_methods(): void
    {
        $methods = [
            'create_table', 'log_sync', 'get_history', 'get_statistics',
            'get_recent', 'cleanup_old_records', 'get_product_history',
            'clear_all', 'delete_by_criteria', 'delete_by_status',
            'delete_by_store', 'delete_errors', 'delete_successful',
            'get_count', 'get_table_size', 'get_table_name',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_Sync_History', $method),
                "Missing method: {$method}"
            );
        }
    }

    // ─── get_table_name ────────────────────────────

    public function test_get_table_name_includes_prefix(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->prefix = 'wp_';

        $this->assertEquals('wp_wc_mss_sync_history', WC_Multi_Store_Sync_History::get_table_name());
    }

    public function test_get_table_name_with_custom_prefix(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->prefix = 'mysite_';

        $this->assertEquals('mysite_wc_mss_sync_history', WC_Multi_Store_Sync_History::get_table_name());
    }

    // ─── log_sync ──────────────────────────────────

    public function test_log_sync_with_minimal_data(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_sync_history',
                \Mockery::on(function ($data) {
                    // Defaults should be applied
                    return $data['product_id'] === 0
                        && $data['product_sku'] === ''
                        && $data['sync_type'] === 'full_product'
                        && $data['sync_source'] === 'manual'
                        && $data['status'] === 'success'
                        && $data['api_calls'] === 0;
                }),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Sync_History::log_sync([]);

        $this->assertEquals(1, $result);
    }

    public function test_log_sync_with_full_data(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 42;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_sync_history',
                \Mockery::on(function ($data) {
                    return $data['product_id'] === 101
                        && $data['product_sku'] === 'SKU-001'
                        && $data['product_name'] === 'Test Product'
                        && $data['store_url'] !== ''
                        && $data['sync_type'] === 'price_stock'
                        && $data['sync_source'] === 'cron'
                        && $data['status'] === 'error'
                        && $data['api_calls'] === 3
                        && $data['duration_ms'] === 1500
                        && $data['remote_product_id'] === 5001;
                }),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Sync_History::log_sync([
            'product_id' => 101,
            'product_sku' => 'SKU-001',
            'product_name' => 'Test Product',
            'store_url' => 'https://remote.store/shop',
            'sync_type' => 'price_stock',
            'sync_source' => 'cron',
            'status' => 'error',
            'message' => 'API timeout',
            'remote_product_id' => 5001,
            'duration_ms' => 1500,
            'memory_mb' => 12.5,
            'api_calls' => 3,
        ]);

        $this->assertEquals(42, $result);
    }

    public function test_log_sync_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 0;

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);

        $result = WC_Multi_Store_Sync_History::log_sync(['product_id' => 1]);

        $this->assertFalse($result);
    }

    public function test_log_sync_sanitizes_text_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_sync_history',
                \Mockery::on(function ($data) {
                    // sanitize_text_field strips tags
                    return $data['product_name'] === 'Clean Name'
                        && $data['product_sku'] === 'SKU';
                }),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Sync_History::log_sync([
            'product_name' => '<script>Clean Name</script>',
            'product_sku' => '<b>SKU</b>',
        ]);

        $this->assertEquals(1, $result);
    }

    // ─── get_recent ────────────────────────────────

    public function test_get_recent_uses_default_limit(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);
        Functions\when('sanitize_sql_orderby')->alias(fn($v) => $v);

        $result = WC_Multi_Store_Sync_History::get_recent();

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(10, $result['limit']);
    }

    public function test_get_recent_with_custom_limit(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);
        Functions\when('sanitize_sql_orderby')->alias(fn($v) => $v);

        $result = WC_Multi_Store_Sync_History::get_recent(5);

        $this->assertEquals(5, $result['limit']);
    }

    // ─── get_product_history ───────────────────────

    public function test_get_product_history_delegates_to_get_history(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);
        Functions\when('sanitize_sql_orderby')->alias(fn($v) => $v);

        $result = WC_Multi_Store_Sync_History::get_product_history(42, 15);

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(15, $result['limit']);
    }

    // ─── delete_by_criteria ────────────────────────

    public function test_delete_by_criteria_aborts_without_criteria(): void
    {
        $deleted = WC_Multi_Store_Sync_History::delete_by_criteria([]);

        $this->assertEquals(0, $deleted);
    }

    public function test_delete_by_criteria_with_status(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->andReturn("status = 'error'");

        $wpdb->shouldReceive('query')
            ->twice()
            ->andReturn(7);

        $wpdb->shouldReceive('esc_like')
            ->andReturnUsing(fn($v) => addcslashes($v, '_%\\'));

        $deleted = WC_Multi_Store_Sync_History::delete_by_criteria(['status' => 'error']);

        $this->assertEquals(7, $deleted);
    }

    // ─── Convenience delete methods ────────────────

    public function test_delete_errors_delegates_correctly(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->andReturn("status = 'error'");

        $wpdb->shouldReceive('query')
            ->twice()
            ->andReturn(3);

        $deleted = WC_Multi_Store_Sync_History::delete_errors();

        $this->assertEquals(3, $deleted);
    }

    public function test_delete_successful_delegates_correctly(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->andReturn("status = 'success'");

        $wpdb->shouldReceive('query')
            ->twice()
            ->andReturn(10);

        $deleted = WC_Multi_Store_Sync_History::delete_successful();

        $this->assertEquals(10, $deleted);
    }

    // ─── get_count ─────────────────────────────────

    public function test_get_count_without_status_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(\Mockery::pattern('/SELECT COUNT/'))
            ->andReturn('250');

        $this->assertEquals(250, WC_Multi_Store_Sync_History::get_count());
    }

    public function test_get_count_with_status_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn("SELECT COUNT(*) FROM wp_wc_mss_sync_history WHERE status = 'error'");

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('15');

        $this->assertEquals(15, WC_Multi_Store_Sync_History::get_count('error'));
    }

    // ─── cleanup_old_records ───────────────────────

    public function test_cleanup_old_records_returns_deleted_count(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('DELETE FROM ...');

        $wpdb->shouldReceive('query')
            ->twice()
            ->andReturn(50);

        $deleted = WC_Multi_Store_Sync_History::cleanup_old_records(30);

        $this->assertEquals(50, $deleted);
    }

    public function test_cleanup_old_records_default_is_90_days(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(\Mockery::type('string'), 90)
            ->andReturn('DELETE FROM ...');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(0);

        $result = WC_Multi_Store_Sync_History::cleanup_old_records();

        $this->assertEquals(0, $result);
    }

    // ─── clear_all ─────────────────────────────────

    public function test_clear_all_returns_true_on_success(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->with(\Mockery::pattern('/TRUNCATE/'))
            ->andReturn(true);

        $this->assertTrue(WC_Multi_Store_Sync_History::clear_all());
    }

    public function test_clear_all_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(false);

        $this->assertFalse(WC_Multi_Store_Sync_History::clear_all());
    }

    // ─── get_table_size ────────────────────────────

    public function test_get_table_size_returns_integer(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('SELECT ...');

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('1048576');

        $this->assertEquals(1048576, WC_Multi_Store_Sync_History::get_table_size());
    }

    public function test_get_table_size_returns_zero_when_null(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('SELECT ...');

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(null);

        $this->assertEquals(0, WC_Multi_Store_Sync_History::get_table_size());
    }
}
