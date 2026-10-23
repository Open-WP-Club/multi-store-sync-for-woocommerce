<?php
/**
 * Retention, archive-before-delete and query-building tests for
 * WC_Multi_Store_Deletion_Audit (the audit trail of product deletions).
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class DeletionAuditRetentionRecordingWpdb extends wpdb
{
    /** @var string[] */
    public array $queries = [];
    public array $rows = [];
    public int|false $queryReturn = 0;
    public array $updates = [];
    public array $inserts = [];
    public int|false $updateReturn = 1;

    public function prepare(string $query, mixed ...$args): string
    {
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return (string) preg_replace_callback('/%[sdf]/', function (array $m) use (&$i, $args): string {
            $v = $args[$i++] ?? '';
            return match ($m[0]) {
                '%d' => (string) (int) $v,
                '%f' => (string) (float) $v,
                default => "'" . addslashes((string) $v) . "'",
            };
        }, $query);
    }

    public function get_results(string $query, string $output = OBJECT): array|object|null
    {
        $this->queries[] = $query;
        return $this->rows;
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;
        return '5';
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        return $this->queryReturn;
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->inserts[] = [$data, $format];
        $this->insert_id = 99;
        return 1;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false
    {
        $this->updates[] = [$data, $where, $format];
        return $this->updateReturn;
    }
}

class DeletionAuditRetentionTest extends WC_Multi_Store_TestCase
{
    private DeletionAuditRetentionRecordingWpdb $db;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->db = new DeletionAuditRetentionRecordingWpdb();
        $wpdb = $this->db;

        $this->tmp = sys_get_temp_dir() . '/wc-mss-audit-' . uniqid();
        mkdir($this->tmp . '/wc-mss-logs', 0777, true);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->tmp, 'baseurl' => 'http://x/uploads']);
        Functions\when('current_time')->justReturn('2026-03-10 12:00:00');
        Functions\when('get_option')->alias(fn($k, $d = false) => $d);
        Functions\when('sanitize_sql_orderby')->alias(
            fn($o) => preg_match('/^[a-zA-Z0-9_]+(\s+(ASC|DESC))?$/i', trim((string) $o)) === 1 ? $o : false
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/wc-mss-logs/*') ?: []);
        @unlink($this->tmp . '/wc-mss-logs/.htaccess');
        @rmdir($this->tmp . '/wc-mss-logs');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function archives(): array
    {
        return glob($this->tmp . '/wc-mss-logs/deletion-audit-archive-*.json') ?: [];
    }

    // ── cleanup_old_logs ──────────────────────────────────────────

    public function test_expired_rows_are_archived_before_the_delete_runs_and_archive_matches(): void
    {
        $this->db->rows = [
            ['id' => 1, 'deleted_at' => '2025-01-01 00:00:00', 'product_id' => 10, 'stores_affected' => '[]'],
            ['id' => 2, 'deleted_at' => '2025-01-09 10:00:00', 'product_id' => 11, 'stores_affected' => '[]'],
        ];
        $this->db->queryReturn = 2;

        $this->assertSame(2, WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90));

        $this->assertCount(1, $this->archives());
        $archive = json_decode(file_get_contents($this->archives()[0]), true);
        $this->assertSame($this->db->rows, $archive['records']);
        $this->assertSame(2, $archive['total_records']);
        $this->assertSame('2025-01-01', $archive['date_range']['from']);
        $this->assertSame('2025-01-09', $archive['date_range']['to']);
    }

    public function test_nothing_is_archived_when_no_rows_expired(): void
    {
        $this->db->rows = [];
        $this->db->queryReturn = 0;

        $this->assertSame(0, WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90));
        $this->assertSame([], $this->archives());
    }

    public function test_select_and_delete_use_the_same_cutoff_and_only_the_audit_table(): void
    {
        $this->db->queryReturn = 0;
        WC_Multi_Store_Deletion_Audit::cleanup_old_logs(30);

        [$select, $delete] = $this->db->queries;
        preg_match("/deleted_at < '([^']+)'/", $select, $a);
        preg_match("/deleted_at < '([^']+)'/", $delete, $b);

        $this->assertSame($a[1], $b[1]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $a[1]);
        $this->assertStringStartsWith('DELETE FROM wp_wc_mss_deletion_audit', $delete);
    }

    public function test_cutoff_is_roughly_n_days_ago(): void
    {
        WC_Multi_Store_Deletion_Audit::cleanup_old_logs(30);

        preg_match("/deleted_at < '([^']+)'/", $this->db->queries[0], $m);
        $age = time() - strtotime($m[1]);
        $this->assertEqualsWithDelta(30 * 86400, $age, 120);
    }

    public function test_failed_delete_is_reported_as_zero(): void
    {
        $this->db->queryReturn = false;

        $this->assertSame(0, WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90));
    }

    // ── get_logs / get_total_count ────────────────────────────────

    public function test_filter_values_are_escaped_in_both_queries(): void
    {
        $evil = "x' OR '1'='1";
        $args = ['product_id' => '5 OR 1=1', 'user_id' => '2; DROP TABLE x', 'status' => $evil, 'deletion_type' => $evil, 'date_from' => $evil, 'date_to' => $evil];

        WC_Multi_Store_Deletion_Audit::get_logs($args);
        WC_Multi_Store_Deletion_Audit::get_total_count($args);

        $this->assertCount(2, $this->db->queries);
        foreach ($this->db->queries as $sql) {
            $this->assertStringContainsString('product_id = 5', $sql);
            $this->assertStringContainsString('user_id = 2', $sql);
            $this->assertStringNotContainsString('DROP', $sql);
            $this->assertSame(0, preg_match("/(?<!\\\\)'\\s*OR\\s*'1'/", $sql), $sql);
        }
    }

    public static function orderbyProvider(): array
    {
        return [
            'injection' => ['id; DROP TABLE x', 'DESC', 'deleted_at DESC'],
            'unlisted column' => ['product_data_before', 'DESC', 'deleted_at DESC'],
            'valid' => ['status', 'ASC', 'status ASC'],
            'bad direction' => ['status', 'ASC, (SELECT 1)', 'deleted_at DESC'],
        ];
    }

    #[DataProvider('orderbyProvider')]
    public function test_get_logs_order_by_is_whitelisted(string $orderby, string $order, string $expected): void
    {
        WC_Multi_Store_Deletion_Audit::get_logs(['orderby' => $orderby, 'order' => $order]);

        $this->assertStringContainsString("ORDER BY {$expected} LIMIT", $this->db->queries[0]);
    }

    public function test_get_logs_binds_limit_and_offset(): void
    {
        WC_Multi_Store_Deletion_Audit::get_logs(['limit' => '10abc', 'offset' => '30']);

        $this->assertStringContainsString('LIMIT 10 OFFSET 30', $this->db->queries[0]);
    }

    public function test_get_logs_decodes_json_columns_and_tolerates_corrupt_json(): void
    {
        $this->db->rows = [[
            'id' => 1,
            'stores_affected' => '["https://a.example.com"]',
            'product_data_before' => '{broken',
            'product_data_after' => 'null',
        ]];

        $logs = WC_Multi_Store_Deletion_Audit::get_logs();

        $this->assertSame(['https://a.example.com'], $logs[0]['stores_affected']);
        $this->assertNull($logs[0]['product_data_before']);
        $this->assertNull($logs[0]['product_data_after']);
    }

    public function test_total_count_is_an_int(): void
    {
        $this->assertSame(5, WC_Multi_Store_Deletion_Audit::get_total_count());
    }

    // ── update_status ─────────────────────────────────────────────

    public static function statusProvider(): array
    {
        return [
            'pending has no completion time' => ['pending', null, false],
            'completed gets a completion time' => ['completed', null, true],
            'failed gets a completion time' => ['failed', 'API 500', true],
            'unknown status has no completion time' => ['weird', null, false],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_completion_timestamp_only_for_terminal_statuses(string $status, ?string $error, bool $expectTime): void
    {
        $this->assertTrue(WC_Multi_Store_Deletion_Audit::update_status(8, $status, $error));

        [$data, $where, $format] = $this->db->updates[0];
        $this->assertSame(['id' => 8], $where);
        $this->assertSame($expectTime, array_key_exists('completed_at', $data));
        $this->assertSame($error, $data['error_message'] ?? null);
        $this->assertCount(count($data), $format);
    }

    public function test_update_status_reports_database_failure(): void
    {
        $this->db->updateReturn = false;

        $this->assertFalse(WC_Multi_Store_Deletion_Audit::update_status(8, 'completed'));
    }

    // ── log_deletion ──────────────────────────────────────────────

    private function product(string $name = 'Widget'): \Mockery\MockInterface
    {
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('get_id')->andReturn(42);
        $p->shouldReceive('get_sku')->andReturn('SKU-1');
        $p->shouldReceive('get_name')->andReturn($name);
        $p->shouldReceive('get_type')->andReturn('simple');
        $p->shouldReceive('get_status')->andReturn('publish');
        $p->shouldReceive('get_price')->andReturn('9.99');
        $p->shouldReceive('get_regular_price')->andReturn('9.99');
        $p->shouldReceive('get_sale_price')->andReturn('');
        $p->shouldReceive('get_stock_quantity')->andReturn(3);
        $p->shouldReceive('get_stock_status')->andReturn('instock');
        $p->shouldReceive('get_manage_stock')->andReturn(true);
        $p->shouldReceive('get_category_ids')->andReturn([]);
        $p->shouldReceive('get_tag_ids')->andReturn([]);
        $p->shouldReceive('get_image_id')->andReturn(0);
        $p->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $p->shouldReceive('is_type')->andReturn(false);
        return $p;
    }

    public function test_log_deletion_records_who_what_and_a_pending_status(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 3, 'display_name' => 'Admin']);

        $id = WC_Multi_Store_Deletion_Audit::log_deletion(42, ['https://a.example.com'], 'bulk');

        $this->assertSame(99, $id);
        [$data] = $this->db->inserts[0];
        $this->assertSame(3, $data['user_id']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame('bulk', $data['deletion_type']);
        $this->assertSame('["https:\/\/a.example.com"]', $data['stores_affected']);
        $this->assertSame('SKU-1', json_decode($data['product_data_before'], true)['sku']);
    }

    public function test_log_deletion_returns_false_for_a_missing_product_and_writes_nothing(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $this->assertFalse(WC_Multi_Store_Deletion_Audit::log_deletion(404));
        $this->assertSame([], $this->db->inserts);
    }

    // ── regression: never delete audit rows that were not archived ──

    public function test_nothing_is_deleted_when_the_archive_cannot_be_written(): void
    {
        $this->db->rows = [['id' => 1, 'deleted_at' => '2025-01-01 00:00:00', 'product_id' => 10]];
        $this->db->queryReturn = 1;
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/nonexistent-dir-for-audit-test', 'baseurl' => 'http://x']);

        set_error_handler(static fn() => true);
        try {
            $result = WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, $result);
        $this->assertSame([], array_values(array_filter($this->db->queries, fn($q) => str_starts_with($q, 'DELETE'))));
    }

    public function test_rows_are_deleted_once_the_archive_exists(): void
    {
        $this->db->rows = [['id' => 1, 'deleted_at' => '2025-01-01 00:00:00', 'product_id' => 10]];
        $this->db->queryReturn = 1;

        $this->assertSame(1, WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90));
        $this->assertCount(1, $this->archives());
    }
}
