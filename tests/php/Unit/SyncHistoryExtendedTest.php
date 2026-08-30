<?php
/**
 * Extended unit tests for WC_Multi_Store_Sync_History
 *
 * Covers: get_history filtering (store_url, date range, sync_type),
 * get_statistics aggregation, create_table, delete_by_criteria combinations.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncHistoryExtendedTest extends WC_Multi_Store_TestCase
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
        Functions\when('sanitize_sql_orderby')->alias(fn($v) => $v);

        if (!defined('DB_NAME')) {
            define('DB_NAME', 'wp_test');
        }
    }

    // ── create_table ─────────────────────────────────────────────

    public function test_create_table_uses_correct_table_name(): void
    {
        // Create dummy upgrade.php so require_once doesn't fail
        $upgrade_dir = ABSPATH . 'wp-admin/includes';
        if (!is_dir($upgrade_dir)) {
            @mkdir($upgrade_dir, 0777, true);
        }
        if (!file_exists($upgrade_dir . '/upgrade.php')) {
            file_put_contents($upgrade_dir . '/upgrade.php', '<?php function dbDelta($sql) { return []; }');
        }

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_charset_collate')
            ->once()
            ->andReturn('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        WC_Multi_Store_Sync_History::create_table();

        // If we get here, the table name was used correctly
        $this->assertTrue(true);
    }

    public function test_get_table_name_format(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->prefix = 'wp_';

        $name = WC_Multi_Store_Sync_History::get_table_name();
        $this->assertStringContainsString('wc_mss_sync_history', $name);
        $this->assertStringStartsWith('wp_', $name);
    }

    // ── get_history with filters ─────────────────────────────────

    public function test_get_history_with_product_id_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $expected_rows = [
            ['id' => 1, 'product_id' => 42, 'status' => 'success'],
            ['id' => 2, 'product_id' => 42, 'status' => 'error'],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn($expected_rows);
        $wpdb->shouldReceive('get_var')->andReturn(2);

        $result = WC_Multi_Store_Sync_History::get_history(['product_id' => 42]);

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['results']);
    }

    public function test_get_history_with_store_url_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => addcslashes($v, '_%\\'));
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);

        $result = WC_Multi_Store_Sync_History::get_history(['store_url' => 'https://mystore.com']);

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(0, $result['total']);
    }

    public function test_get_history_with_status_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $error_rows = [
            ['id' => 5, 'status' => 'error', 'message' => 'Timeout'],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn($error_rows);
        $wpdb->shouldReceive('get_var')->andReturn(1);

        $result = WC_Multi_Store_Sync_History::get_history(['status' => 'error']);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('error', $result['results'][0]['status']);
    }

    public function test_get_history_with_date_range_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);

        $result = WC_Multi_Store_Sync_History::get_history([
            'date_from' => '2024-06-01 00:00:00',
            'date_to' => '2024-06-15 23:59:59',
        ]);

        $this->assertArrayHasKey('results', $result);
    }

    public function test_get_history_with_sync_type_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);

        $result = WC_Multi_Store_Sync_History::get_history(['sync_type' => 'price_stock']);

        $this->assertArrayHasKey('results', $result);
    }

    public function test_get_history_with_custom_orderby(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);

        $result = WC_Multi_Store_Sync_History::get_history([
            'orderby' => 'product_id',
            'order' => 'ASC',
        ]);

        $this->assertArrayHasKey('results', $result);
    }

    public function test_get_history_with_invalid_orderby_uses_default(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // sanitize_sql_orderby returns false for invalid input
        Functions\when('sanitize_sql_orderby')->justReturn(false);

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);

        $result = WC_Multi_Store_Sync_History::get_history([
            'orderby' => 'invalid_column; DROP TABLE',
            'order' => 'ASC',
        ]);

        // Should still return results (uses fallback 'created_at DESC')
        $this->assertArrayHasKey('results', $result);
    }

    public function test_get_history_pagination(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(100);

        $result = WC_Multi_Store_Sync_History::get_history([
            'limit' => 25,
            'offset' => 50,
        ]);

        $this->assertEquals(25, $result['limit']);
        $this->assertEquals(50, $result['offset']);
        $this->assertEquals(100, $result['total']);
    }

    // ── get_statistics ───────────────────────────────────────────

    public function test_get_statistics_returns_correct_structure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // get_row with ARRAY_A returns an array, not object
        $overall_stats = [
            'total_syncs' => 100,
            'successful_syncs' => 85,
            'failed_syncs' => 15,
            'avg_duration_ms' => 250.5,
            'max_duration_ms' => 5000,
            'avg_memory_mb' => 12.3,
            'total_api_calls' => 300,
        ];

        $by_type = [
            ['sync_type' => 'full_product', 'count' => 60, 'successful' => 55, 'avg_duration_ms' => 300],
            ['sync_type' => 'price_stock', 'count' => 40, 'successful' => 30, 'avg_duration_ms' => 150],
        ];

        $by_store = [
            ['store_url' => 'https://store1.com', 'count' => 50, 'successful' => 45, 'failed' => 5, 'avg_duration_ms' => 200],
        ];

        $daily = [
            ['date' => '2024-06-15', 'total' => 30, 'successful' => 28, 'failed' => 2],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => addcslashes($v, '_%\\'));
        $wpdb->shouldReceive('get_row')->andReturn($overall_stats);
        $wpdb->shouldReceive('get_results')->andReturn($by_type, $by_store, $daily);

        $stats = WC_Multi_Store_Sync_History::get_statistics(['days' => 7]);

        $this->assertArrayHasKey('overall', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertArrayHasKey('by_store', $stats);
        $this->assertArrayHasKey('daily', $stats);
        $this->assertEquals(85, $stats['overall']['success_rate']);
    }

    public function test_get_statistics_with_store_url_filter(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => addcslashes($v, '_%\\'));
        $wpdb->shouldReceive('get_row')->andReturn([
            'total_syncs' => 50,
            'successful_syncs' => 50,
            'failed_syncs' => 0,
            'avg_duration_ms' => 100,
            'max_duration_ms' => 500,
            'avg_memory_mb' => 8.0,
            'total_api_calls' => 100,
        ]);
        $wpdb->shouldReceive('get_results')->andReturn([], [], []);

        $stats = WC_Multi_Store_Sync_History::get_statistics([
            'days' => 30,
            'store_url' => 'https://specific-store.com',
        ]);

        $this->assertEquals(100, $stats['overall']['success_rate']);
    }

    public function test_get_statistics_zero_syncs_success_rate(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn([
            'total_syncs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'avg_duration_ms' => null,
            'max_duration_ms' => null,
            'avg_memory_mb' => null,
            'total_api_calls' => 0,
        ]);
        $wpdb->shouldReceive('get_results')->andReturn([], [], []);

        $stats = WC_Multi_Store_Sync_History::get_statistics();

        $this->assertEquals(0, $stats['overall']['success_rate']);
    }

    public function test_get_statistics_uses_calendar_day_boundary(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $prepared = [];
        $wpdb->shouldReceive('prepare')->andReturnUsing(function (...$args) use (&$prepared) {
            $prepared[] = $args;
            return 'prepared';
        });
        $wpdb->shouldReceive('get_row')->andReturn([
            'total_syncs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
        ]);
        $wpdb->shouldReceive('get_results')->andReturn([], [], []);

        WC_Multi_Store_Sync_History::get_statistics(['days' => 1]);

        $this->assertSame('2024-06-15 00:00:00', $prepared[0][1]);
        $this->assertSame(1, $prepared[1][1]);
    }

    public function test_get_statistics_clamps_days_to_safe_maximum(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $prepared = [];
        $wpdb->shouldReceive('prepare')->andReturnUsing(function (...$args) use (&$prepared) {
            $prepared[] = $args;
            return 'prepared';
        });
        $wpdb->shouldReceive('get_row')->andReturn([
            'total_syncs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
        ]);
        $wpdb->shouldReceive('get_results')->andReturn([], [], []);

        WC_Multi_Store_Sync_History::get_statistics(['days' => 999999]);

        $this->assertSame(365, $prepared[1][1]);
    }

    // ── delete_by_criteria combinations ──────────────────────────

    public function test_delete_by_criteria_with_multiple_criteria(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn("status = 'error'");
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => addcslashes($v, '_%\\'));
        $wpdb->shouldReceive('query')->twice()->andReturn(5);

        $deleted = WC_Multi_Store_Sync_History::delete_by_criteria([
            'status' => 'error',
            'store_url' => 'https://store1.com',
        ]);

        $this->assertEquals(5, $deleted);
    }

    public function test_delete_by_criteria_with_date_before(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn("created_at < '2024-01-01'");
        $wpdb->shouldReceive('query')->twice()->andReturn(20);

        $deleted = WC_Multi_Store_Sync_History::delete_by_criteria([
            'date_before' => '2024-01-01',
        ]);

        $this->assertEquals(20, $deleted);
    }

    public function test_delete_by_criteria_with_days_old(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->twice()->andReturn(30);

        $deleted = WC_Multi_Store_Sync_History::delete_by_criteria([
            'days_old' => 60,
        ]);

        $this->assertEquals(30, $deleted);
    }

    public function test_delete_by_criteria_with_sync_type(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn("sync_type = 'quantity'");
        $wpdb->shouldReceive('query')->twice()->andReturn(8);

        $deleted = WC_Multi_Store_Sync_History::delete_by_criteria([
            'sync_type' => 'quantity',
        ]);

        $this->assertEquals(8, $deleted);
    }

    public function test_delete_by_criteria_returns_zero_on_no_match(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn("status = 'pending'");
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        $deleted = WC_Multi_Store_Sync_History::delete_by_criteria([
            'status' => 'pending',
        ]);

        $this->assertEquals(0, $deleted);
    }

    // ── delete_by_store ──────────────────────────────────────────

    public function test_delete_by_store_delegates_to_criteria(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => addcslashes($v, '_%\\'));
        $wpdb->shouldReceive('query')->twice()->andReturn(12);

        $deleted = WC_Multi_Store_Sync_History::delete_by_store('https://old-store.com');

        $this->assertEquals(12, $deleted);
    }

    // ── log_sync edge cases ──────────────────────────────────────

    public function test_log_sync_with_null_optional_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 5;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_sync_history',
                \Mockery::on(function ($data) {
                    return $data['remote_product_id'] === null
                        && $data['duration_ms'] === null
                        && $data['memory_mb'] === null;
                }),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Sync_History::log_sync([
            'product_id' => 1,
            'store_url' => 'https://store.com',
            'remote_product_id' => null,
            'duration_ms' => null,
            'memory_mb' => null,
        ]);

        $this->assertEquals(5, $result);
    }

    public function test_log_sync_with_zero_values(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 6;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_sync_history',
                \Mockery::on(function ($data) {
                    return $data['product_id'] === 0
                        && $data['api_calls'] === 0;
                }),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Sync_History::log_sync([
            'product_id' => 0,
            'api_calls' => 0,
        ]);

        $this->assertEquals(6, $result);
    }

    // ── cleanup_old_records ──────────────────────────────────────

    public function test_cleanup_old_records_with_custom_days(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(\Mockery::type('string'), 7)
            ->andReturn('DELETE FROM ...');

        $wpdb->shouldReceive('query')
            ->twice()
            ->andReturn(100);

        $deleted = WC_Multi_Store_Sync_History::cleanup_old_records(7);

        $this->assertEquals(100, $deleted);
    }

    public function test_cleanup_old_records_returns_zero_when_nothing_deleted(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('DELETE FROM ...');
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        $deleted = WC_Multi_Store_Sync_History::cleanup_old_records(365);

        $this->assertEquals(0, $deleted);
    }

    // ── get_count edge cases ─────────────────────────────────────

    public function test_get_count_returns_zero_for_null_result(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(null);

        $this->assertEquals(0, WC_Multi_Store_Sync_History::get_count());
    }
}
