<?php
/**
 * Retention, filter-to-SQL and statistics-window tests for WC_Multi_Store_Sync_History,
 * plus the shared CSV formula-injection guard used by the plugin's CSV exports.
 *
 * Uses a recording $wpdb double whose prepare() mimics WordPress closely enough
 * (typed placeholders, quoting, escaping of literal percent signs) that the SQL
 * that would reach MySQL can be asserted on.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class SyncHistoryRetentionRecordingWpdb extends wpdb
{
    private const PCT = '{WCMSS_PCT}';

    /** @var string[] */
    public array $queries = [];
    /** @var array<int, array{0: string, 1: array, 2: ?array}> */
    public array $inserts = [];
    public int|false $queryReturn = 1;
    public mixed $varReturn = null;
    public mixed $rowReturn = null;
    public mixed $resultsReturn = [];
    public int|false $insertReturn = 1;

    public function prepare(string $query, mixed ...$args): string
    {
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return (string) preg_replace_callback('/%[sdf]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            return match ($m[0]) {
                '%d' => (string) (int) $value,
                '%f' => (string) (float) $value,
                default => "'" . str_replace('%', self::PCT, addslashes((string) $value)) . "'",
            };
        }, $query);
    }

    private function restore(string $sql): string
    {
        return str_replace(self::PCT, '%', $sql);
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $this->restore($query);
        return $this->queryReturn;
    }

    public function get_results(string $query, string $output = OBJECT): array|object|null
    {
        $this->queries[] = $this->restore($query);
        return $this->resultsReturn;
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $this->restore($query);
        return $this->varReturn;
    }

    public function get_row(string $query, string $output = OBJECT): mixed
    {
        $this->queries[] = $this->restore($query);
        return $this->rowReturn;
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->inserts[] = [$table, $data, $format];
        $this->insert_id = 321;
        return $this->insertReturn;
    }

    /** @return string[] queries matching a regex */
    public function matching(string $pattern): array
    {
        return array_values(array_filter($this->queries, fn($q) => preg_match($pattern, $q) === 1));
    }
}

class SyncHistoryRetentionTest extends WC_Multi_Store_TestCase
{
    private SyncHistoryRetentionRecordingWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->db = new SyncHistoryRetentionRecordingWpdb();
        $wpdb = $this->db;

        Functions\when('current_time')->justReturn('2026-03-10 14:30:00');
        Functions\when('get_option')->alias(fn($k, $d = false) => $d);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('esc_url_raw')->alias(function ($url) {
            $url = trim((string) $url);
            return preg_match('#^https?://#i', $url) === 1 ? $url : '';
        });
        Functions\when('sanitize_textarea_field')->alias(fn($s) => trim(strip_tags((string) $s)));
        Functions\when('sanitize_sql_orderby')->alias(
            fn($o) => preg_match('/^[a-zA-Z0-9_]+(\s+(ASC|DESC))?$/i', trim((string) $o)) === 1 ? $o : false
        );
    }

    // ── cleanup_old_records ───────────────────────────────────────

    public function test_cleanup_binds_the_day_count_as_an_integer(): void
    {
        $this->db->queryReturn = 4;

        $this->assertSame(4, WC_Multi_Store_Sync_History::cleanup_old_records(30));

        $delete = $this->db->matching('/^DELETE FROM/');
        $this->assertCount(1, $delete);
        $this->assertStringContainsString('INTERVAL 30 DAY', $delete[0]);
        $this->assertStringContainsString('wp_wc_mss_sync_history', $delete[0]);
    }

    public function test_cleanup_optimizes_only_when_rows_were_deleted(): void
    {
        $this->db->queryReturn = 0;
        $this->assertSame(0, WC_Multi_Store_Sync_History::cleanup_old_records(30));
        $this->assertSame([], $this->db->matching('/OPTIMIZE/'));

        $this->db->queryReturn = 3;
        $this->assertSame(3, WC_Multi_Store_Sync_History::cleanup_old_records(30));
        $this->assertCount(1, $this->db->matching('/OPTIMIZE TABLE wp_wc_mss_sync_history/'));
    }

    public function test_cleanup_reports_zero_when_the_delete_fails(): void
    {
        $this->db->queryReturn = false;

        $this->assertSame(0, WC_Multi_Store_Sync_History::cleanup_old_records(30));
        $this->assertSame([], $this->db->matching('/OPTIMIZE/'));
    }

    public function test_cleanup_only_ever_targets_the_history_table(): void
    {
        $this->db->queryReturn = 2;
        WC_Multi_Store_Sync_History::cleanup_old_records(1);

        foreach ($this->db->queries as $q) {
            $this->assertStringContainsString('wp_wc_mss_sync_history', $q);
            $this->assertStringNotContainsString('wp_posts', $q);
        }
    }

    // ── delete_by_criteria ────────────────────────────────────────

    public static function emptyCriteriaProvider(): array
    {
        return [
            'no keys' => [[]],
            'empty strings' => [['status' => '', 'store_url' => '', 'sync_type' => '', 'date_before' => '']],
            'nulls' => [['status' => null, 'store_url' => null, 'days_old' => null]],
            'zeros' => [['days_old' => 0, 'status' => '0']],
            'unknown keys only' => [['product_id' => 5, 'everything' => true]],
        ];
    }

    #[DataProvider('emptyCriteriaProvider')]
    public function test_delete_without_real_criteria_never_issues_a_delete(array $criteria): void
    {
        $this->assertSame(0, WC_Multi_Store_Sync_History::delete_by_criteria($criteria));
        $this->assertSame([], $this->db->queries, 'an unscoped DELETE would wipe the whole history');
    }

    public function test_delete_status_value_is_quoted_and_escaped(): void
    {
        Functions\when('sanitize_text_field')->alias(fn($s) => trim(strip_tags((string) $s)));

        WC_Multi_Store_Sync_History::delete_by_criteria(['status' => "error' OR '1'='1"]);

        $delete = $this->db->matching('/^DELETE FROM/');
        $this->assertCount(1, $delete);
        $this->assertStringContainsString("status = 'error\\' OR \\'1\\'=\\'1'", $delete[0]);
        $this->assertStringNotContainsString("status = 'error' OR", $delete[0]);
    }

    public function test_delete_with_several_criteria_combines_them_with_and(): void
    {
        Functions\when('sanitize_text_field')->alias(fn($s) => (string) $s);

        WC_Multi_Store_Sync_History::delete_by_criteria([
            'status' => 'error',
            'sync_type' => 'stock',
            'date_before' => '2026-01-01',
            'days_old' => 30,
        ]);

        $sql = $this->db->matching('/^DELETE FROM/')[0];
        $this->assertSame(3, substr_count($sql, ' AND '));
        $this->assertStringNotContainsString(' OR ', $sql);
        $this->assertStringContainsString("status = 'error'", $sql);
        $this->assertStringContainsString("sync_type = 'stock'", $sql);
        $this->assertStringContainsString("created_at < '2026-01-01'", $sql);
        $this->assertStringContainsString('INTERVAL 30 DAY', $sql);
    }

    public static function daysOldProvider(): array
    {
        return [
            'int' => [14, 14],
            'numeric string' => ['14', 14],
            'string with suffix' => ['14abc', 14],
            'negative is made positive' => [-14, 14],
        ];
    }

    #[DataProvider('daysOldProvider')]
    public function test_days_old_is_always_a_plain_positive_integer_in_the_sql(mixed $input, int $expected): void
    {
        WC_Multi_Store_Sync_History::delete_by_criteria(['days_old' => $input]);

        $this->assertStringContainsString("INTERVAL {$expected} DAY", $this->db->matching('/^DELETE FROM/')[0]);
    }

    public function test_days_old_injection_attempt_is_neutralised(): void
    {
        WC_Multi_Store_Sync_History::delete_by_criteria(['days_old' => '1 DAY) OR 1=1 -- ']);

        $sql = $this->db->matching('/^DELETE FROM/')[0];
        $this->assertStringNotContainsString('OR 1=1', $sql);
        $this->assertStringContainsString('INTERVAL 1 DAY', $sql);
    }

    public function test_store_delete_matches_the_exact_scheme_less_host_for_every_url_spelling(): void
    {
        $sql = [];
        foreach (['https://shop.example.com', 'http://shop.example.com', 'https://shop.example.com/', 'shop.example.com'] as $url) {
            $this->db->queries = [];
            WC_Multi_Store_Sync_History::delete_by_criteria(['store_url' => $url]);
            $sql[] = $this->db->matching('/^DELETE FROM/')[0];
        }

        $this->assertCount(1, array_unique($sql));
        $this->assertStringContainsString("= 'shop.example.com'", $sql[0]);
        $this->assertStringNotContainsString('LIKE', $sql[0], 'a substring match would also delete my-shop.example.com');
    }

    public function test_store_delete_is_not_a_wildcard_match_even_with_percent_and_underscore(): void
    {
        WC_Multi_Store_Sync_History::delete_by_criteria(['store_url' => 'https://100%_off.example.com']);

        $sql = $this->db->matching('/^DELETE FROM/')[0];
        $this->assertStringNotContainsString('LIKE', $sql);
        $this->assertStringContainsString("= '100", $sql);
    }

    public function test_store_delete_with_no_host_deletes_nothing(): void
    {
        foreach (['https://', 'http://', 'https:///', '/'] as $url) {
            $this->assertSame(0, WC_Multi_Store_Sync_History::delete_by_criteria(['store_url' => $url]), $url);
        }
        $this->assertSame([], $this->db->matching('/^DELETE FROM/'));
    }

    public function test_fractional_or_zero_days_old_deletes_nothing(): void
    {
        foreach (['0.5', '0', 0, '0abc'] as $days) {
            $this->assertSame(0, WC_Multi_Store_Sync_History::delete_by_criteria(['days_old' => $days]));
        }
        $this->assertSame([], $this->db->matching('/^DELETE FROM/'));
    }

    public function test_days_old_below_one_does_not_widen_other_criteria(): void
    {
        Functions\when('sanitize_text_field')->alias(fn($s) => (string) $s);

        WC_Multi_Store_Sync_History::delete_by_criteria(['status' => 'error', 'days_old' => '0.5']);

        $this->assertSame([], $this->db->matching('/^DELETE FROM/'), 'an invalid age must abort, not silently drop the age filter');
    }

    public static function invalidRetentionProvider(): array
    {
        return [[0], [-1], [-9999]];
    }

    #[DataProvider('invalidRetentionProvider')]
    public function test_cleanup_with_a_non_positive_retention_falls_back_to_ninety_days(int $days): void
    {
        WC_Multi_Store_Sync_History::cleanup_old_records($days);

        $this->assertStringContainsString('INTERVAL 90 DAY', $this->db->matching('/^DELETE FROM/')[0]);
    }

    public function test_history_reads_still_use_the_flexible_like_match(): void
    {
        WC_Multi_Store_Sync_History::get_history(['store_url' => 'https://100%_off.example.com/']);

        $this->assertStringContainsString("store_url LIKE '%100\\\\%\\\\_off.example.com%'", $this->db->queries[0]);
    }

    public function test_delete_reports_the_row_count_and_optimizes_on_success_only(): void
    {
        $this->db->queryReturn = 0;
        $this->assertSame(0, WC_Multi_Store_Sync_History::delete_by_criteria(['days_old' => 5]));
        $this->assertSame([], $this->db->matching('/OPTIMIZE/'));

        $this->db->queryReturn = 8;
        $this->assertSame(8, WC_Multi_Store_Sync_History::delete_by_criteria(['days_old' => 5]));
        $this->assertCount(1, $this->db->matching('/OPTIMIZE/'));

        $this->db->queryReturn = false;
        $this->assertSame(0, WC_Multi_Store_Sync_History::delete_by_criteria(['days_old' => 5]));
    }

    public function test_clear_all_truncates_only_the_history_table_and_reports_failure(): void
    {
        $this->assertTrue(WC_Multi_Store_Sync_History::clear_all());
        $this->assertSame(['TRUNCATE TABLE wp_wc_mss_sync_history'], $this->db->queries);

        $this->db->queryReturn = false;
        $this->assertFalse(WC_Multi_Store_Sync_History::clear_all());
    }

    // ── get_history filters reaching prepare() ────────────────────

    public function test_every_filter_value_is_escaped_in_the_select_and_count_queries(): void
    {
        $evil = "x' OR '1'='1";
        WC_Multi_Store_Sync_History::get_history([
            'product_id' => '12 OR 1=1',
            'status' => $evil,
            'sync_type' => $evil,
            'date_from' => $evil,
            'date_to' => $evil,
            'store_url' => "https://a.com' OR '1'='1",
        ]);

        $this->assertCount(2, $this->db->queries);
        foreach ($this->db->queries as $sql) {
            $this->assertStringContainsString('product_id = 12', $sql);
            $this->assertStringNotContainsString('OR 1=1', $sql);
            $this->assertStringNotContainsString("x' OR", $sql);
            $this->assertStringNotContainsString("a.com' OR", $sql);
            $this->assertSame(0, preg_match("/(?<!\\\\)'\\s*OR\\s*'1'/", $sql), 'unescaped quote breakout in: ' . $sql);
        }
    }

    public function test_falsy_filters_do_not_add_conditions(): void
    {
        WC_Multi_Store_Sync_History::get_history([
            'product_id' => 0, 'status' => '', 'sync_type' => null, 'date_from' => '0', 'date_to' => false, 'store_url' => '',
        ]);

        foreach ($this->db->queries as $sql) {
            $this->assertStringContainsString('WHERE 1=1', $sql);
            $this->assertStringNotContainsString(' AND ', $sql);
        }
    }

    public function test_select_and_count_share_identical_where_clauses(): void
    {
        WC_Multi_Store_Sync_History::get_history(['status' => 'error', 'sync_type' => 'stock', 'product_id' => 9]);

        [$select, $count] = $this->db->queries;
        preg_match('/WHERE (.*?)\s+ORDER BY/s', $select, $a);
        preg_match('/WHERE (.*)$/s', $count, $b);

        $this->assertSame(trim($a[1]), trim($b[1]));
        $this->assertStringStartsWith('SELECT COUNT(*)', $count);
        $this->assertStringNotContainsString('LIMIT', $count);
    }

    public static function orderbyProvider(): array
    {
        return [
            'injection in column' => ['id; DROP TABLE wp_users', 'DESC', 'created_at DESC'],
            'unknown column' => ['password', 'DESC', 'created_at DESC'],
            'subquery' => ['(SELECT 1)', 'ASC', 'created_at ASC'],
            'case-different column is not whitelisted' => ['STATUS', 'ASC', 'created_at ASC'],
            'injection in direction' => ['status', 'DESC; DROP TABLE x', 'created_at DESC'],
            'bogus direction' => ['status', 'sideways', 'created_at DESC'],
            'valid asc' => ['status', 'ASC', 'status ASC'],
            'valid lowercase direction' => ['duration_ms', 'asc', 'duration_ms asc'],
        ];
    }

    #[DataProvider('orderbyProvider')]
    public function test_order_by_is_whitelisted_and_direction_sanitised(string $orderby, string $order, string $expected): void
    {
        WC_Multi_Store_Sync_History::get_history(['orderby' => $orderby, 'order' => $order]);

        $this->assertStringContainsString("ORDER BY {$expected}\n", $this->db->queries[0] . "\n");
        $this->assertStringNotContainsString('DROP', $this->db->queries[0]);
    }

    public function test_limit_and_offset_are_bound_as_integers_and_echoed_back(): void
    {
        $this->db->varReturn = '137';

        $result = WC_Multi_Store_Sync_History::get_history(['limit' => '25abc', 'offset' => '50']);

        $this->assertStringContainsString('LIMIT 25 OFFSET 50', $this->db->queries[0]);
        $this->assertSame(137, $result['total']);
    }

    public function test_total_is_zero_when_the_count_query_fails(): void
    {
        $this->db->varReturn = null;

        $result = WC_Multi_Store_Sync_History::get_history();

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['results']);
    }

    // ── get_statistics window ─────────────────────────────────────

    public static function statisticsWindowProvider(): array
    {
        return [
            'default 7 days' => ['2026-03-10 14:30:00', 7, '2026-03-04 00:00:00'],
            'one day is today only' => ['2026-03-10 14:30:00', 1, '2026-03-10 00:00:00'],
            'zero is clamped to one' => ['2026-03-10 14:30:00', 0, '2026-03-10 00:00:00'],
            'negative is clamped to one' => ['2026-03-10 14:30:00', -9, '2026-03-10 00:00:00'],
            'non numeric falls to one' => ['2026-03-10 14:30:00', 'abc', '2026-03-10 00:00:00'],
            'month boundary' => ['2026-03-02 00:00:01', 7, '2026-02-24 00:00:00'],
            'leap day is counted' => ['2024-03-01 23:59:59', 2, '2024-02-29 00:00:00'],
            'non leap february' => ['2026-03-01 23:59:59', 2, '2026-02-28 00:00:00'],
            'year boundary' => ['2026-01-02 12:00:00', 5, '2025-12-29 00:00:00'],
            'max 365' => ['2026-03-10 14:30:00', 365, '2025-03-11 00:00:00'],
            'over max is clamped' => ['2026-03-10 14:30:00', 100000, '2025-03-11 00:00:00'],
        ];
    }

    #[DataProvider('statisticsWindowProvider')]
    public function test_statistics_window_starts_at_midnight_of_the_first_included_day(string $now, mixed $days, string $expectedStart): void
    {
        Functions\when('current_time')->justReturn($now);

        WC_Multi_Store_Sync_History::get_statistics(['days' => $days]);

        $this->assertNotEmpty($this->db->queries);
        foreach ($this->db->queries as $sql) {
            $this->assertStringContainsString("created_at >= '{$expectedStart}'", $sql);
        }
    }

    public function test_store_filter_is_applied_to_all_four_statistics_queries(): void
    {
        WC_Multi_Store_Sync_History::get_statistics(['days' => 3, 'store_url' => 'https://shop.example.com']);

        $this->assertCount(4, $this->db->queries);
        foreach ($this->db->queries as $sql) {
            $this->assertStringContainsString("store_url LIKE '%shop.example.com%'", $sql);
        }
    }

    public function test_daily_query_is_limited_to_the_requested_number_of_days(): void
    {
        WC_Multi_Store_Sync_History::get_statistics(['days' => 9]);

        $daily = $this->db->matching('/GROUP BY DATE\(created_at\)/');
        $this->assertCount(1, $daily);
        $this->assertMatchesRegularExpression('/LIMIT 9\s*$/', trim($daily[0]));
    }

    public static function successRateProvider(): array
    {
        return [
            'no syncs' => [['total_syncs' => '0', 'successful_syncs' => null], 0],
            'all ok' => [['total_syncs' => '10', 'successful_syncs' => '10'], 100.0],
            'none ok' => [['total_syncs' => '10', 'successful_syncs' => '0'], 0.0],
            'repeating decimal rounds to 2dp' => [['total_syncs' => '3', 'successful_syncs' => '1'], 33.33],
            'rounds half up' => [['total_syncs' => '8', 'successful_syncs' => '5'], 62.5],
            'null successful with rows' => [['total_syncs' => '4', 'successful_syncs' => null], 0.0],
        ];
    }

    #[DataProvider('successRateProvider')]
    public function test_success_rate_is_computed_from_string_aggregates(array $row, int|float $expected): void
    {
        $this->db->rowReturn = $row;

        $stats = WC_Multi_Store_Sync_History::get_statistics();

        $this->assertEquals($expected, $stats['overall']['success_rate']);
    }

    public function test_statistics_fall_back_to_empty_structure_when_every_query_fails(): void
    {
        $this->db->rowReturn = null;
        $this->db->resultsReturn = null;

        $stats = WC_Multi_Store_Sync_History::get_statistics();

        $this->assertSame(0, $stats['overall']['total_syncs']);
        $this->assertSame(0, $stats['overall']['success_rate']);
        $this->assertSame([], $stats['by_type']);
        $this->assertSame([], $stats['by_store']);
        $this->assertSame([], $stats['daily']);
    }

    // ── log_sync ──────────────────────────────────────────────────

    public function test_log_sync_strips_markup_and_neutralises_non_http_store_urls(): void
    {
        Functions\when('sanitize_text_field')->alias(fn($s) => trim(strip_tags((string) $s)));

        WC_Multi_Store_Sync_History::log_sync([
            'product_id' => '15',
            'product_name' => '<script>alert(1)</script>Widget',
            'product_sku' => "<b>SKU</b>\n",
            'store_url' => 'javascript:alert(1)',
            'message' => "<img src=x onerror=alert(1)>failed",
        ]);

        [, $data] = $this->db->inserts[0];
        $this->assertSame(15, $data['product_id']);
        $this->assertSame('alert(1)Widget', $data['product_name']);
        $this->assertSame('SKU', $data['product_sku']);
        $this->assertSame('', $data['store_url']);
        $this->assertSame('failed', $data['message']);
    }

    public function test_log_sync_insert_columns_and_formats_stay_aligned(): void
    {
        Functions\when('sanitize_text_field')->alias(fn($s) => (string) $s);

        WC_Multi_Store_Sync_History::log_sync(['product_id' => 1, 'store_url' => 'https://a.example.com']);

        [, $data, $format] = $this->db->inserts[0];
        $this->assertCount(count($data), $format);
        $this->assertSame('%d', $format[array_search('product_id', array_keys($data), true)]);
        $this->assertSame('%f', $format[array_search('memory_mb', array_keys($data), true)]);
        $this->assertSame('%d', $format[array_search('api_calls', array_keys($data), true)]);
        $this->assertSame('2026-03-10 14:30:00', $data['created_at']);
    }

    public function test_log_sync_returns_insert_id_or_false(): void
    {
        Functions\when('sanitize_text_field')->alias(fn($s) => (string) $s);

        $this->assertSame(321, WC_Multi_Store_Sync_History::log_sync(['product_id' => 1]));

        $this->db->insertReturn = false;
        $this->assertFalse(WC_Multi_Store_Sync_History::log_sync(['product_id' => 1]));
    }

    public function test_log_sync_negative_numeric_metrics_cannot_go_negative(): void
    {
        Functions\when('sanitize_text_field')->alias(fn($s) => (string) $s);

        WC_Multi_Store_Sync_History::log_sync(['product_id' => 1, 'api_calls' => -4, 'duration_ms' => -250, 'remote_product_id' => -9]);

        [, $data] = $this->db->inserts[0];
        $this->assertGreaterThanOrEqual(0, $data['api_calls']);
        $this->assertGreaterThanOrEqual(0, $data['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $data['remote_product_id']);
    }

    // ── CSV formula-injection guard ───────────────────────────────

    private function csv(string $value): string
    {
        $probe = new class {
            use WC_Multi_Store_Csv_Sanitizer;

            public function run(string $v): string
            {
                return self::csv_cell_sanitize($v);
            }
        };

        return $probe->run($value);
    }

    public static function dangerousCellProvider(): array
    {
        return [
            'equals formula' => ['=1+1'],
            'dde command' => ['=cmd|\' /C calc\'!A0'],
            'hyperlink formula' => ['=HYPERLINK("http://evil.example","click")'],
            'plus formula' => ['+1+1'],
            'minus formula' => ['-2+3'],
            'at function' => ['@SUM(A1:A9)'],
            'tab prefixed' => ["\t=1+1"],
            'carriage return prefixed' => ["\r=1+1"],
            'lone equals' => ['='],
        ];
    }

    #[DataProvider('dangerousCellProvider')]
    public function test_cells_that_start_a_formula_are_defused_without_losing_content(string $value): void
    {
        $safe = $this->csv($value);

        $this->assertSame("'" . $value, $safe);
        $this->assertContains($safe[0], ["'"]);
    }

    public static function harmlessCellProvider(): array
    {
        return [
            'empty' => [''],
            'plain text' => ['Widget'],
            'number' => ['12.50'],
            'zero' => ['0'],
            'formula char in the middle' => ['a=b'],
            'url' => ['https://shop.example.com/?a=1'],
            'leading space then equals' => [' =1'],
            'already quoted' => ["'=1+1"],
            'unicode' => ['Ünïcödé =1'],
        ];
    }

    #[DataProvider('harmlessCellProvider')]
    public function test_harmless_cells_pass_through_unchanged(string $value): void
    {
        $this->assertSame($value, $this->csv($value));
    }

    public function test_sanitising_twice_does_not_stack_prefixes_beyond_one_per_pass(): void
    {
        $once = $this->csv('=1+1');

        $this->assertSame($once, $this->csv($once), 'a defused cell starts with a quote and must be stable');
    }
}
