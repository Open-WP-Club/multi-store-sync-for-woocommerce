<?php
/**
 * Unit tests for WC_Multi_Store_API_Usage_Tracker
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ApiUsageTrackerTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('get_option')->justReturn(0);
        Functions\when('update_option')->justReturn(true);

        // Pretend register_shutdown_function has already fired this process so
        // log_api_request does not try to actually register one during tests
        // (Brain Monkey / Patchwork cannot redefine the PHP internal).
        WC_Multi_Store_API_Usage_Tracker::reset_buffer_for_testing();
        $prop = (new \ReflectionClass(WC_Multi_Store_API_Usage_Tracker::class))
            ->getProperty('shutdown_registered');
        $prop->setValue(null, true);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_API_Usage_Tracker::reset_buffer_for_testing();
        parent::tearDown();
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_API_Usage_Tracker'));
    }

    public function test_has_required_static_methods(): void
    {
        $methods = [
            'create_table', 'get_statistics', 'get_usage_by_store',
            'get_usage_by_endpoint', 'get_daily_trend', 'get_recent_errors',
            'get_cost_estimates', 'export_to_csv', 'cleanup_old_data',
            'clear_all_data', 'track_request',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_API_Usage_Tracker', $method),
                "Missing static method: {$method}"
            );
        }
    }

    // ─── log_api_request ───────────────────────────

    public function test_log_api_request_buffers_and_flush_writes_single_row(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $captured_sql = null;
        $captured_args = null;
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql, $args) use (&$captured_sql, &$captured_args) {
                $captured_sql = $sql;
                $captured_args = $args;
                return $sql; // good enough for assertion
            });
        $wpdb->shouldReceive('query')->once()->andReturn(1);

        $tracker = new WC_Multi_Store_API_Usage_Tracker();
        $tracker->log_api_request(
            'https://store.example.com',
            '/products',
            'get',
            [
                'status_code'   => 200,
                'response_time' => 150,
                'request_size'  => 256,
                'response_size' => 4096,
                'success'       => true,
            ]
        );

        WC_Multi_Store_API_Usage_Tracker::flush_pending_inserts();

        $this->assertStringContainsString('INSERT INTO wp_wc_mss_api_usage', $captured_sql);
        $this->assertContains('https://store.example.com', $captured_args);
        $this->assertContains('/products', $captured_args);
        $this->assertContains('GET', $captured_args);
        $this->assertContains(200, $captured_args);
        $this->assertContains(150, $captured_args);
        $this->assertContains(1, $captured_args); // success
    }

    public function test_log_api_request_batches_multiple_rows_into_one_insert(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $captured_sql = null;
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql) use (&$captured_sql) {
                $captured_sql = $sql;
                return $sql;
            });
        $wpdb->shouldReceive('query')->once()->andReturn(3);

        $tracker = new WC_Multi_Store_API_Usage_Tracker();
        $tracker->log_api_request('https://a.com', '/products', 'get', ['status_code' => 200]);
        $tracker->log_api_request('https://b.com', '/orders',   'post', ['status_code' => 201]);
        $tracker->log_api_request('https://c.com', '/users',    'put',  ['status_code' => 200]);

        WC_Multi_Store_API_Usage_Tracker::flush_pending_inserts();

        // One INSERT statement with three VALUES groups: 2 inter-group separators "), ("
        $this->assertEquals(2, substr_count($captured_sql, '), ('));
        $this->assertStringContainsString('INSERT INTO wp_wc_mss_api_usage', $captured_sql);
    }

    public function test_log_api_request_uppercases_method(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $captured_args = null;
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql, $args) use (&$captured_args) {
                $captured_args = $args;
                return $sql;
            });
        $wpdb->shouldReceive('query')->once()->andReturn(1);

        $tracker = new WC_Multi_Store_API_Usage_Tracker();
        $tracker->log_api_request('https://store.com', '/products', 'post', []);

        WC_Multi_Store_API_Usage_Tracker::flush_pending_inserts();

        $this->assertContains('POST', $captured_args);
    }

    public function test_log_api_request_handles_failed_request(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $captured_args = null;
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql, $args) use (&$captured_args) {
                $captured_args = $args;
                return $sql;
            });
        $wpdb->shouldReceive('query')->once()->andReturn(1);

        $tracker = new WC_Multi_Store_API_Usage_Tracker();
        $tracker->log_api_request(
            'https://store.com',
            '/products',
            'PUT',
            [
                'success'       => false,
                'status_code'   => 500,
                'error_message' => 'Connection timeout',
            ]
        );

        WC_Multi_Store_API_Usage_Tracker::flush_pending_inserts();

        $this->assertContains(0, $captured_args, 'success column should be 0');
        $this->assertContains(500, $captured_args);
        $this->assertContains('Connection timeout', $captured_args);
    }

    public function test_flush_pending_inserts_is_noop_when_buffer_empty(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // Nothing buffered → no query() / prepare() calls
        $wpdb->shouldNotReceive('query');
        $wpdb->shouldNotReceive('prepare');

        WC_Multi_Store_API_Usage_Tracker::flush_pending_inserts();

        $this->addToAssertionCount(1);
    }

    public function test_log_api_request_emits_sql_null_for_missing_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $captured_sql = null;
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql) use (&$captured_sql) {
                $captured_sql = $sql;
                return $sql;
            });
        $wpdb->shouldReceive('query')->once()->andReturn(1);

        $tracker = new WC_Multi_Store_API_Usage_Tracker();
        // result array missing status_code, response_time, etc. — they should become SQL NULL,
        // not get coerced to 0 by %d.
        $tracker->log_api_request('https://a.com', '/x', 'get', []);

        WC_Multi_Store_API_Usage_Tracker::flush_pending_inserts();

        $this->assertStringContainsString('NULL', $captured_sql);
    }

    // ─── get_statistics ────────────────────────────

    public function test_get_statistics_returns_expected_structure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $stats_row = (object) [
            'total_requests' => '100',
            'successful_requests' => '95',
            'failed_requests' => '5',
            'avg_response_time' => '250.5',
            'total_data_sent' => '51200',
            'total_data_received' => '1048576',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('WHERE ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn($stats_row);

        $stats = WC_Multi_Store_API_Usage_Tracker::get_statistics();

        $this->assertEquals(100, $stats['total_requests']);
        $this->assertEquals(95, $stats['successful_requests']);
        $this->assertEquals(5, $stats['failed_requests']);
        $this->assertEquals(95.0, $stats['success_rate']);
        $this->assertEquals(251, $stats['avg_response_time']);
        $this->assertEquals(51200, $stats['total_data_sent']);
        $this->assertEquals(1048576, $stats['total_data_received']);
        $this->assertEquals(51200 + 1048576, $stats['total_data_transferred']);
    }

    public function test_get_statistics_handles_zero_requests(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $stats_row = (object) [
            'total_requests' => '0',
            'successful_requests' => '0',
            'failed_requests' => '0',
            'avg_response_time' => null,
            'total_data_sent' => '0',
            'total_data_received' => '0',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('WHERE ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn($stats_row);

        $stats = WC_Multi_Store_API_Usage_Tracker::get_statistics();

        $this->assertEquals(0, $stats['total_requests']);
        $this->assertEquals(0, $stats['success_rate']);
        $this->assertEquals(0, $stats['avg_response_time']);
    }

    public function test_get_statistics_handles_null_row(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('WHERE ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $stats = WC_Multi_Store_API_Usage_Tracker::get_statistics();

        $this->assertEquals(0, $stats['total_requests']);
        $this->assertEquals(0, $stats['success_rate']);
    }

    // ─── get_cost_estimates ────────────────────────

    public function test_get_cost_estimates_with_zero_rates(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // Statistics returns zero data
        $stats_row = (object) [
            'total_requests' => '100',
            'successful_requests' => '100',
            'failed_requests' => '0',
            'avg_response_time' => '200',
            'total_data_sent' => '1000',
            'total_data_received' => '5000',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('WHERE ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn($stats_row);

        // Rates are 0 by default
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return match ($key) {
                'wc_mss_api_cost_per_1000' => 0,
                'wc_mss_api_cost_per_gb' => 0,
                default => $default,
            };
        });

        $costs = WC_Multi_Store_API_Usage_Tracker::get_cost_estimates();

        $this->assertEquals(0, $costs['request_cost']);
        $this->assertEquals(0, $costs['data_transfer_cost']);
        $this->assertEquals(0, $costs['total_estimated_cost']);
        $this->assertEquals(0, $costs['cost_per_1000_requests']);
        $this->assertEquals(0, $costs['cost_per_gb_transferred']);
    }

    public function test_get_cost_estimates_with_configured_rates(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $stats_row = (object) [
            'total_requests' => '2000',
            'successful_requests' => '2000',
            'failed_requests' => '0',
            'avg_response_time' => '100',
            'total_data_sent' => '0',
            'total_data_received' => '0',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('WHERE ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn($stats_row);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return match ($key) {
                'wc_mss_api_cost_per_1000' => 5.0,
                'wc_mss_api_cost_per_gb' => 0.10,
                default => $default,
            };
        });

        $costs = WC_Multi_Store_API_Usage_Tracker::get_cost_estimates();

        // 2000 / 1000 * 5.0 = 10.0
        $this->assertEquals(10.0, $costs['request_cost']);
        $this->assertEquals(5.0, $costs['cost_per_1000_requests']);
        $this->assertEquals(0.10, $costs['cost_per_gb_transferred']);
    }

    // ─── export_to_csv ─────────────────────────────

    public function test_export_to_csv_header_row(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $csv = WC_Multi_Store_API_Usage_Tracker::export_to_csv();

        // fputcsv() quotes any field containing a space (e.g. "Store URL"),
        // so check for each header name individually rather than an exact
        // comma-joined substring.
        foreach (['ID', 'Store URL', 'Endpoint', 'Method', 'Status Code', 'Response Time (ms)', 'Success', 'Error Message', 'Created At'] as $header) {
            $this->assertStringContainsString($header, $csv);
        }
    }

    public function test_export_to_csv_with_data(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            [
                'id' => 1,
                'store_url' => 'https://store.example.com',
                'endpoint' => '/products',
                'method' => 'GET',
                'status_code' => 200,
                'response_time' => 150,
                'success' => 1,
                'error_message' => null,
                'created_at' => '2024-01-15 10:00:00',
            ],
        ]);

        $csv = WC_Multi_Store_API_Usage_Tracker::export_to_csv();

        $this->assertStringContainsString('store.example.com', $csv);
        $this->assertStringContainsString('/products', $csv);
        $this->assertStringContainsString('Yes', $csv);
    }

    public function test_export_to_csv_escapes_quotes_in_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            [
                'id' => 2,
                'store_url' => 'https://store.example.com',
                'endpoint' => '/products?name="test"',
                'method' => 'GET',
                'status_code' => 500,
                'response_time' => 5000,
                'success' => 0,
                'error_message' => 'Error with "quotes"',
                'created_at' => '2024-01-15 10:00:00',
            ],
        ]);

        $csv = WC_Multi_Store_API_Usage_Tracker::export_to_csv();

        // Quotes should be escaped as ""
        $this->assertStringContainsString('""quotes""', $csv);
        $this->assertStringContainsString('No', $csv);
    }

    // ─── track_request ─────────────────────────────

    public function test_track_request_fires_action(): void
    {
        Monkey\Actions\expectDone('wc_mss_api_request')
            ->once()
            ->with(
                'https://store.com',
                '/products',
                'POST',
                ['success' => true]
            );

        WC_Multi_Store_API_Usage_Tracker::track_request(
            'https://store.com',
            '/products',
            'POST',
            ['success' => true]
        );

        $this->addToAssertionCount(1);
    }

    // ─── get_usage_by_store ────────────────────────

    public function test_get_usage_by_store_calculates_success_rate(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            [
                'store_url' => 'https://store1.com',
                'total_requests' => '100',
                'successful_requests' => '80',
                'failed_requests' => '20',
                'avg_response_time' => '350.7',
                'total_data_sent' => '1024',
                'total_data_received' => '2048',
            ],
            [
                'store_url' => 'https://store2.com',
                'total_requests' => '0',
                'successful_requests' => '0',
                'failed_requests' => '0',
                'avg_response_time' => null,
                'total_data_sent' => '0',
                'total_data_received' => '0',
            ],
        ]);

        $results = WC_Multi_Store_API_Usage_Tracker::get_usage_by_store();

        $this->assertCount(2, $results);
        $this->assertEquals(80.0, $results[0]['success_rate']);
        $this->assertEquals(351, $results[0]['avg_response_time']);
        $this->assertEquals(0, $results[1]['success_rate']);
        $this->assertEquals(0, $results[1]['avg_response_time']);
    }

    // ─── cleanup_old_data ──────────────────────────

    public function test_cleanup_old_data_default_days(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->with(\Mockery::type('string'), 90)
            ->andReturn('DELETE ...');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(25);

        $deleted = WC_Multi_Store_API_Usage_Tracker::cleanup_old_data();

        $this->assertEquals(25, $deleted);
    }

    public function test_cleanup_old_data_custom_days(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->with(\Mockery::type('string'), 30)
            ->andReturn('DELETE ...');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(5);

        $deleted = WC_Multi_Store_API_Usage_Tracker::cleanup_old_data(30);

        $this->assertEquals(5, $deleted);
    }

    public function test_cleanup_old_data_archives_records_before_deleting(): void
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
            ['id' => 1, 'created_at' => '2024-01-01 00:00:00', 'store_url' => 'https://store1.com'],
            ['id' => 2, 'created_at' => '2024-01-05 00:00:00', 'store_url' => 'https://store2.com'],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->once()->andReturn($records);
        $wpdb->shouldReceive('query')->once()->andReturn(2);

        try {
            $deleted = WC_Multi_Store_API_Usage_Tracker::cleanup_old_data(90);

            $this->assertEquals(2, $deleted);

            $archive_files = glob($temp_dir . '/wc-mss-logs/api-usage-archive-*.json');
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
