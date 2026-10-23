<?php
/**
 * AJAX handler tests for the webhook-log endpoints and verification progress in
 * WC_Multi_Store_Admin_Ajax. The real Webhook_Logger / Weekly_Verification_Scheduler
 * run against a Mockery $wpdb and stubbed transients; only the JSON responses and
 * the SQL that reaches the database are asserted.
 */

use Brain\Monkey\Functions;

class AdminAjaxWebhookLogsTest extends WC_Multi_Store_TestCase
{
    /** @var array{success: ?bool, data: mixed} */
    private array $response = ['success' => null, 'data' => null];

    /** @var list<string> */
    private array $queries = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/class-admin-ajax.php';

        $this->response = ['success' => null, 'data' => null];
        $this->queries = [];
        $this->transients = [];
        $_POST = [];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('get_option')->justReturn(false);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->response = ['success' => true, 'data' => $data];
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            $this->response = ['success' => false, 'data' => $data];
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function ajax(): WC_Multi_Store_Admin_Ajax
    {
        return (new ReflectionClass(WC_Multi_Store_Admin_Ajax::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $rowByQuery  Row returned by get_row().
     * @param list<array<string, mixed>> $rows  Rows returned by get_results().
     */
    private function db(?array $row = null, array $rows = [], int|false $queryResult = 3, int $count = 0): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($q, ...$args) {
            $args = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
            $this->queries[] = vsprintf(str_replace('%s', "'%s'", $q), $args);
            return end($this->queries);
        });
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('get_row')->andReturn($row);
        $wpdb->shouldReceive('get_results')->andReturn($rows);
        $wpdb->shouldReceive('get_var')->andReturn($count);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($q) use ($queryResult) {
            $this->queries[] = $q;
            return $queryResult;
        });
    }

    // ---- get_webhook_log_detail ------------------------------------------

    public function test_detail_rejects_missing_or_invalid_log_id(): void
    {
        $this->db();
        foreach ([[], ['log_id' => '0'], ['log_id' => 'abc'], ['log_id' => ['1']]] as $post) {
            $_POST = $post;
            $this->ajax()->ajax_get_webhook_log_detail();
            $this->assertFalse($this->response['success']);
            $this->assertSame('Invalid log ID', $this->response['data']['message']);
        }
    }

    public function test_detail_reports_not_found(): void
    {
        $this->db(null);
        $_POST = ['log_id' => '9'];

        $this->ajax()->ajax_get_webhook_log_detail();

        $this->assertFalse($this->response['success']);
        $this->assertSame('Log not found', $this->response['data']['message']);
    }

    public function test_detail_returns_decoded_log_with_labels(): void
    {
        $this->db([
            'id' => 9, 'log_type' => 'order_received', 'status' => 'failed',
            'request_data' => '{"a":1}', 'response_data' => '',
        ]);
        $_POST = ['log_id' => '9'];

        $this->ajax()->ajax_get_webhook_log_detail();

        $this->assertTrue($this->response['success']);
        $this->assertSame(['a' => 1], $this->response['data']['request_data']);
        $this->assertArrayHasKey('type_label', $this->response['data']);
        $this->assertArrayHasKey('status_badge', $this->response['data']);
        $this->assertStringContainsString('id = 9', $this->queries[0]);
    }

    // ---- delete_webhook_logs ---------------------------------------------

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function deleteCases(): array
    {
        return [
            'errors' => [['delete_type' => 'errors'], "status = 'failed'", '3 error records deleted'],
            'success' => [['delete_type' => 'success'], "status = 'success'", '3 success records deleted'],
            'by type' => [['delete_type' => 'by_type', 'log_type' => 'order_received'], "log_type = 'order_received'", '3 records of type "order_received" deleted'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deleteCases')]
    public function test_delete_runs_scoped_query_and_reports_remaining(array $post, string $sqlFragment, string $message): void
    {
        $this->db(null, [], 3, 12);
        $_POST = $post;

        $this->ajax()->ajax_delete_webhook_logs();

        $this->assertTrue($this->response['success']);
        $this->assertSame($message, $this->response['data']['message']);
        $this->assertSame(3, $this->response['data']['deleted']);
        $this->assertSame(12, $this->response['data']['remaining']);
        $this->assertStringContainsString($sqlFragment, $this->queries[0]);
    }

    public function test_delete_older_than_uses_bounded_days(): void
    {
        $this->db(null, [], 4, 0);
        $_POST = ['delete_type' => 'older_than', 'days' => '99999'];

        $this->ajax()->ajax_delete_webhook_logs();

        $this->assertSame('4 records older than 365 days deleted', $this->response['data']['message']);
        $cutoff = date('Y-m-d', strtotime('-365 days'));
        $this->assertStringContainsString("created_at < '$cutoff", $this->queries[0]);
    }

    public function test_delete_all_truncates_table(): void
    {
        $this->db(null, [], 0, 0);
        $_POST = ['delete_type' => 'all'];

        $this->ajax()->ajax_delete_webhook_logs();

        $this->assertTrue($this->response['success']);
        $this->assertSame('All logs deleted', $this->response['data']['message']);
        $this->assertSame('TRUNCATE TABLE wp_wc_mss_webhook_logs', $this->queries[0]);
    }

    public function test_delete_rejects_unknown_type_without_touching_database(): void
    {
        $this->db();
        foreach ([[], ['delete_type' => 'everything'], ['delete_type' => ['all']]] as $post) {
            $_POST = $post;
            $this->ajax()->ajax_delete_webhook_logs();
            $this->assertFalse($this->response['success']);
            $this->assertSame('Invalid deletion type', $this->response['data']['message']);
        }
        $this->assertSame([], $this->queries);
    }

    public function test_delete_by_type_rejects_unknown_log_type(): void
    {
        $this->db();
        $_POST = ['delete_type' => 'by_type', 'log_type' => "x'; DROP TABLE y;--"];

        $this->ajax()->ajax_delete_webhook_logs();

        $this->assertFalse($this->response['success']);
        $this->assertSame('Invalid log type', $this->response['data']['message']);
        $this->assertSame([], $this->queries);
    }

    // ---- stats / export / list -------------------------------------------

    public function test_stats_defaults_to_thirty_days_and_returns_logger_stats(): void
    {
        $this->db(null, []);

        $this->ajax()->ajax_get_webhook_stats();

        $this->assertTrue($this->response['success']);
        $this->assertIsArray($this->response['data']);
        $cutoff = date('Y-m-d', strtotime('-30 days'));
        $this->assertStringContainsString("created_at >= '$cutoff", $this->queries[0]);
    }

    public function test_export_returns_base64_csv_with_header_row(): void
    {
        $this->db(null, [['id' => 1, 'created_at' => '2024-01-01 00:00:00', 'log_type' => 'order_received',
            'store_url' => 'https://s.com', 'order_id' => 5, 'product_id' => 6, 'product_sku' => '=CMD()',
            'old_stock' => 3, 'new_stock' => 2, 'quantity_changed' => -1, 'change_reason' => '', 'ip_address' => '1.2.3.4',
            'status' => 'success', 'error_message' => '']], 3, 1);
        $_POST = ['status' => 'failed', 'product_sku' => 'ignored'];

        $this->ajax()->ajax_export_webhook_logs();

        $this->assertTrue($this->response['success']);
        $this->assertMatchesRegularExpression('/^webhook-logs-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/', $this->response['data']['filename']);
        $csv = base64_decode($this->response['data']['csv_content'], true);
        $this->assertIsString($csv);
        $this->assertStringContainsString('ID,Date/Time,Type,Store', $csv);
        $this->assertStringContainsString("'=CMD()", $csv, 'Formula cells must be defused');
        $sql = implode(' ', $this->queries);
        $this->assertStringContainsString("status = 'failed'", $sql, 'Export must honour the status filter the list shows');
        $this->assertStringContainsString("product_sku LIKE '%ignored%'", $sql, 'Export must honour the SKU filter the list shows');
    }

    public function test_list_returns_logs_with_labels_and_rendered_table(): void
    {
        require_once WC_MSS_PLUGIN_DIR . 'includes/admin-list-table.php';
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        Functions\when('esc_html_e')->alias(function ($v): void { echo htmlspecialchars((string) $v, ENT_QUOTES); });
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->alias(fn($p) => 'https://primary.test/wp-admin/' . $p);

        $this->db(null, [['id' => 1, 'created_at' => '2024-01-01', 'log_type' => 'order_received', 'status' => 'success',
            'product_sku' => 'ABC', 'store_url' => 'https://s.com']], 3, 1);
        $_POST = ['per_page' => '10', 'page' => '1'];

        $this->ajax()->ajax_get_webhook_logs();

        $this->assertTrue($this->response['success']);
        $data = $this->response['data'];
        $this->assertSame(1, $data['total']);
        $this->assertSame(10, $data['per_page']);
        $this->assertArrayHasKey('type_label', $data['logs'][0]);
        // WP_List_Table::display() is a no-op in the unit-test stub, so only the key is checked here.
        $this->assertIsString($data['table_html']);
    }

    // ---- authorization + error wrapping ----------------------------------

    public function test_handlers_stop_before_database_when_user_lacks_capability(): void
    {
        $this->db();
        Functions\when('current_user_can')->justReturn(false);
        $_POST = ['delete_type' => 'all', 'log_id' => '1'];

        $this->ajax()->ajax_delete_webhook_logs();
        $this->assertFalse($this->response['success']);
        $this->ajax()->ajax_get_webhook_log_detail();
        $this->assertFalse($this->response['success']);
        $this->assertSame([], $this->queries);
    }

    public function test_exceptions_are_logged_and_returned_as_generic_error(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andThrow(new RuntimeException('db exploded'));
        $_POST = ['log_id' => '1'];
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', false);
        }

        $this->ajax()->ajax_get_webhook_log_detail();

        $this->assertFalse($this->response['success']);
        $this->assertSame('An error occurred while fetching log detail', $this->response['data']['message']);
    }

    // ---- get_verification_progress ---------------------------------------

    public function test_progress_reports_percent_and_fields(): void
    {
        $this->transients['wc_mss_async_verification_progress'] = [
            'status' => 'running', 'processed_products' => 25, 'total_products' => 200,
            'current_batch' => 2, 'total_batches' => 8, 'discrepancies_found' => 4,
            'started_at' => '2024-01-15 11:00:00',
        ];
        $this->progressKey();

        $this->ajax()->ajax_get_verification_progress();

        $this->assertTrue($this->response['success']);
        $this->assertSame(13, (int) $this->response['data']['percent']);
        $this->assertSame('running', $this->response['data']['status']);
        $this->assertSame(4, $this->response['data']['discrepancies']);
        $this->assertNull($this->response['data']['completed_at']);
    }

    public function test_progress_with_zero_total_does_not_divide_by_zero(): void
    {
        $this->transients['wc_mss_async_verification_progress'] = [
            'status' => 'running', 'processed_products' => 0, 'total_products' => 0,
            'current_batch' => 0, 'total_batches' => 0, 'discrepancies_found' => 0, 'started_at' => 'x',
        ];
        $this->progressKey();

        $this->ajax()->ajax_get_verification_progress();

        $this->assertSame(0, $this->response['data']['percent']);
    }

    public function test_progress_is_idle_without_transient_and_scheduled_actions(): void
    {
        $this->ajax()->ajax_get_verification_progress();

        $this->assertTrue($this->response['success']);
        $this->assertSame(['status' => 'idle'], $this->response['data']);
    }

    /** Align the stubbed transient with the scheduler's real key. */
    private function progressKey(): void
    {
        $key = (new ReflectionClassConstant(WC_Multi_Store_Weekly_Verification_Scheduler::class, 'ASYNC_PROGRESS_TRANSIENT'))->getValue();
        $this->transients[$key] = $this->transients['wc_mss_async_verification_progress'];
    }
}
