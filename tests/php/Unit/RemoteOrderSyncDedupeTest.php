<?php
/**
 * Dedupe, pagination-termination and per-order failure isolation tests for
 * WC_Multi_Store_Remote_Order_Sync, run against an in-memory fake of the remote
 * orders table so that "does this order already exist?" is answered by data
 * rather than by a canned mock return.
 *
 * Also covers query construction in WC_Multi_Store_Remote_Order_Table.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class RemoteOrderSyncFakeWpdb extends wpdb
{
    private const PCT = '{WCMSS_PCT}';

    /** @var array<int, array<string, mixed>> id => row */
    public array $orders = [];
    public int $nextId = 1;
    /** @var string[] */
    public array $queries = [];
    public bool $failInserts = false;
    public bool $failUpdates = false;
    public int $insertCalls = 0;
    public int $updateCalls = 0;

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

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;
        if (preg_match("/SELECT id FROM \\S+ WHERE remote_order_id = (\\d+) AND remote_store_url = '(.*)'\$/s", $query, $m)) {
            $url = stripslashes($m[2]);
            foreach ($this->orders as $id => $row) {
                if ((int) $row['remote_order_id'] === (int) $m[1] && $row['remote_store_url'] === $url) {
                    return (string) $id;
                }
            }
            return null;
        }
        return null;
    }

    public function get_row(string $query, string $output = OBJECT): mixed
    {
        $this->queries[] = $query;
        if (preg_match('/WHERE id = (\d+)/', $query, $m) && isset($this->orders[(int) $m[1]])) {
            $row = $this->orders[(int) $m[1]] + ['id' => (int) $m[1]];
            // The table class json_decode()s these; null columns are a separate (reported) deprecation.
            foreach (['billing_address', 'shipping_address', 'line_items', 'order_meta'] as $col) {
                $row[$col] = $row[$col] ?? 'null';
            }
            return (object) $row;
        }
        return null;
    }

    public function get_results(string $query, string $output = OBJECT): array|object|null
    {
        $this->queries[] = $query;
        return [];
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (!str_contains($table, 'orders') || str_contains($table, 'items')) {
            return 1; // line items
        }
        $this->insertCalls++;
        if ($this->failInserts) {
            $this->last_error = 'Duplicate entry';
            return false;
        }
        foreach ($this->orders as $row) {
            if ((int) $row['remote_order_id'] === (int) $data['remote_order_id'] && $row['remote_store_url'] === $data['remote_store_url']) {
                $this->last_error = 'Duplicate entry (unique key remote_order_store)';
                return false;
            }
        }
        $this->orders[$this->nextId] = $data;
        $this->insert_id = $this->nextId++;
        return 1;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false
    {
        $this->updateCalls++;
        if ($this->failUpdates) {
            return false;
        }
        $this->orders[(int) $where['id']] = array_merge($this->orders[(int) $where['id']] ?? [], $data);
        return 1;
    }

    public function delete(string $table, array $where, ?array $where_format = null): int|false
    {
        if (isset($where['id'])) {
            unset($this->orders[(int) $where['id']]);
        }
        return 1;
    }
}

class RemoteOrderSyncDedupeTest extends WC_Multi_Store_TestCase
{
    private RemoteOrderSyncFakeWpdb $db;

    /** @var int[] pages requested, in order */
    private array $requestedPages = [];

    /** @var callable(int $page): (array|WP_Error) */
    private $pageProvider;

    private const STORE_A = 'https://store-a.example.com';
    private const STORE_B = 'https://store-b.example.com';
    private const CREDS = ['consumer_key' => 'ck', 'consumer_secret' => 'cs'];

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->db = new RemoteOrderSyncFakeWpdb();
        $wpdb = $this->db;
        $this->requestedPages = [];
        $this->pageProvider = fn(int $page) => [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(fn($o, $d = false) => $o === 'wc_multi_store_sync_settings' ? ['auth_method' => 'query_string'] : $d);
        Functions\when('current_time')->justReturn('2026-03-10 12:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            return count($args) === 2 && is_array($args[0]) ? $args[1] . '?' . http_build_query($args[0]) : ($args[count($args) - 1] ?? '');
        });
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('wp_remote_get')->alias(function ($url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $page = (int) ($q['page'] ?? 1);
            $this->requestedPages[] = $page;
            $payload = ($this->pageProvider)($page);
            if ($payload instanceof \WP_Error) {
                return $payload;
            }
            return ['response' => ['code' => 200], 'body' => json_encode($payload)];
        });
    }

    private function order(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'number' => (string) (1000 + $id),
            'status' => 'processing',
            'currency' => 'EUR',
            'total' => '50.00',
            'date_created' => '2026-03-01T10:00:00',
            'date_modified' => '2026-03-01T10:00:00',
            'billing' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com'],
            'line_items' => [],
        ], $overrides);
    }

    private function sync(string $store = self::STORE_A, array $args = []): array
    {
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        return $sync->sync_store_orders($store, self::CREDS, $args);
    }

    // ── dedupe ────────────────────────────────────────────────────

    public function test_same_remote_id_in_two_stores_creates_two_local_rows(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(5), $this->order(6)] : [];

        $a = $this->sync(self::STORE_A);
        $b = $this->sync(self::STORE_B);

        $this->assertSame(2, $a['synced']);
        $this->assertSame(2, $b['synced']);
        $this->assertCount(4, $this->db->orders);
        $keys = array_map(fn($r) => $r['remote_store_url'] . '#' . $r['remote_order_id'], $this->db->orders);
        $this->assertCount(4, array_unique($keys));
    }

    public function test_second_run_over_unchanged_orders_creates_nothing_and_updates_nothing(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1), $this->order(2), $this->order(3)] : [];

        $first = $this->sync();
        $second = $this->sync();

        $this->assertSame(3, $first['synced']);
        $this->assertSame(0, $second['synced']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, $second['errors']);
        $this->assertSame(3, $this->db->insertCalls);
        $this->assertSame(0, $this->db->updateCalls);
    }

    public function test_changed_status_updates_the_existing_row_in_place(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1)] : [];
        $this->sync();

        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1, ['status' => 'completed', 'date_modified' => '2026-03-02T09:00:00'])] : [];
        $result = $this->sync();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $this->db->orders);
        $this->assertSame('completed', array_values($this->db->orders)[0]['status']);
    }

    public function test_same_order_repeated_within_one_page_is_stored_once(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(9), $this->order(9), $this->order(9)] : [];

        $result = $this->sync();

        $this->assertCount(1, $this->db->orders);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['errors']);
    }

    public function test_store_url_that_looks_like_another_stores_prefix_is_a_different_store(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(5)] : [];

        $this->sync('https://shop.example.com');
        $this->sync('https://shop.example.com/eu');

        $this->assertCount(2, $this->db->orders);
    }

    public function test_store_url_with_quotes_is_matched_literally_not_as_sql(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(5)] : [];

        $this->sync("https://x.example.com/'; DROP TABLE wp_posts; --");
        $this->sync("https://x.example.com/'; DROP TABLE wp_posts; --");

        $this->assertCount(1, $this->db->orders, 'quoted URL must still dedupe against itself');
    }

    // ── pagination termination ────────────────────────────────────

    public function test_endless_full_pages_stop_at_the_ten_page_safety_cap(): void
    {
        $this->pageProvider = fn(int $page) => [$this->order($page * 10), $this->order($page * 10 + 1)];

        $result = $this->sync(self::STORE_A, ['per_page' => 2]);

        $this->assertSame(range(1, 10), $this->requestedPages);
        $this->assertSame(20, $result['synced']);
        $this->assertTrue($result['success']);
    }

    public function test_server_that_ignores_the_page_parameter_cannot_create_duplicates(): void
    {
        $this->pageProvider = fn(int $page) => [$this->order(1), $this->order(2)];

        $result = $this->sync(self::STORE_A, ['per_page' => 2]);

        $this->assertCount(10, $this->requestedPages, 'loop must still terminate');
        $this->assertCount(2, $this->db->orders);
        $this->assertSame(2, $result['synced']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['errors']);
    }

    public function test_zero_per_page_cannot_loop_forever(): void
    {
        $this->pageProvider = fn(int $page) => [$this->order($page)];

        $this->sync(self::STORE_A, ['per_page' => 0]);

        $this->assertLessThanOrEqual(10, count($this->requestedPages));
    }

    public function test_starting_beyond_the_page_cap_fetches_a_single_page(): void
    {
        $this->pageProvider = fn(int $page) => [$this->order($page)];

        $this->sync(self::STORE_A, ['per_page' => 1, 'page' => 11]);

        $this->assertSame([11], $this->requestedPages);
    }

    public function test_exactly_per_page_orders_costs_one_extra_empty_fetch(): void
    {
        $this->pageProvider = fn(int $page) => $page === 1 ? [$this->order(1), $this->order(2)] : [];

        $result = $this->sync(self::STORE_A, ['per_page' => 2]);

        $this->assertSame([1, 2], $this->requestedPages);
        $this->assertSame(2, $result['synced']);
    }

    public function test_api_error_on_a_later_page_keeps_earlier_pages_and_reports_the_error(): void
    {
        $this->pageProvider = fn(int $page) => $page === 1
            ? [$this->order(1), $this->order(2)]
            : new \WP_Error('http_request_failed', 'cURL error 28: timeout');

        $result = $this->sync(self::STORE_A, ['per_page' => 2]);

        $this->assertSame([1, 2], array_values(array_unique($this->requestedPages)), 'the client may retry a failed page but must never go past it');
        $this->assertSame(2, $result['synced']);
        $this->assertSame(1, $result['errors']);
        $this->assertCount(2, $this->db->orders);
    }

    public function test_api_error_on_the_first_page_stores_nothing(): void
    {
        $this->pageProvider = fn(int $page) => new \WP_Error('http_request_failed', 'down');

        $result = $this->sync();

        $this->assertSame([1], array_values(array_unique($this->requestedPages)));
        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame([], $this->db->orders);
    }

    public function test_status_filter_is_sent_only_when_not_any(): void
    {
        $seen = [];
        Functions\when('wp_remote_get')->alias(function ($url) use (&$seen) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $seen[] = $q;
            return ['response' => ['code' => 200], 'body' => '[]'];
        });

        $this->sync(self::STORE_A, ['status' => 'any']);
        $this->sync(self::STORE_A, ['status' => 'completed', 'after' => '2026-03-01T00:00:00']);

        $this->assertArrayNotHasKey('status', $seen[0]);
        $this->assertSame('completed', $seen[1]['status']);
        $this->assertSame('2026-03-01T00:00:00', $seen[1]['after']);
    }

    // ── per-order failure isolation ───────────────────────────────

    public function test_orders_without_an_id_are_counted_as_errors_and_do_not_block_others(): void
    {
        $this->pageProvider = fn($page) => $page === 1
            ? [$this->order(1), ['status' => 'processing'], $this->order(0), $this->order(3)]
            : [];

        $result = $this->sync();

        $this->assertSame(2, $result['synced']);
        $this->assertSame(2, $result['errors']);
        $this->assertCount(2, $this->db->orders);
    }

    public function test_string_ids_from_the_api_dedupe_against_integer_ids(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(7)] : [];
        $this->sync();

        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(7, ['id' => '7'])] : [];
        $result = $this->sync();

        $this->assertCount(1, $this->db->orders);
        $this->assertSame(0, $result['synced']);
    }

    public function test_sync_all_stores_skips_stores_without_credentials_and_isolates_failures(): void
    {
        Functions\when('get_option')->alias(function ($o, $d = false) {
            if ($o === 'wc_multi_store_sync_stores') {
                return [
                    'https://nocreds.example.com' => ['status' => 'active', 'consumer_key' => '', 'consumer_secret' => ''],
                    self::STORE_A => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
                    self::STORE_B => ['status' => 'inactive', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
                ];
            }
            return $o === 'wc_multi_store_sync_settings' ? ['auth_method' => 'query_string'] : $d;
        });
        WC_Multi_Store_Settings::clear_static_cache();
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1)] : [];

        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $results = $sync->sync_all_stores();

        $this->assertSame([self::STORE_A], array_keys($results));
        $this->assertSame(1, $results[self::STORE_A]['synced']);
    }

    // ── prepare_order_data / convert_date ─────────────────────────

    private function prepare(array $order, string $store = self::STORE_A): array
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Remote_Order_Sync::class, 'prepare_order_data');
        return $ref->invoke(new WC_Multi_Store_Remote_Order_Sync(), $order, $store);
    }

    public function test_hash_ignores_fields_that_do_not_change_the_order_but_tracks_those_that_do(): void
    {
        $base = $this->prepare($this->order(1));

        $this->assertSame($base['sync_hash'], $this->prepare($this->order(1))['sync_hash']);
        $this->assertNotSame($base['sync_hash'], $this->prepare($this->order(1, ['status' => 'refunded']))['sync_hash']);
        $this->assertNotSame($base['sync_hash'], $this->prepare($this->order(1, ['total' => '50.01']))['sync_hash']);
        $this->assertNotSame($base['sync_hash'], $this->prepare($this->order(1, ['date_modified' => '2026-03-02T00:00:00']))['sync_hash']);
        $this->assertNotSame($base['sync_hash'], $this->prepare($this->order(1, ['line_items' => [['name' => 'X', 'quantity' => 2, 'total' => '9.00']]]))['sync_hash']);
    }

    public function test_hash_is_scoped_by_content_not_by_store(): void
    {
        $this->assertSame(
            $this->prepare($this->order(1), self::STORE_A)['sync_hash'],
            $this->prepare($this->order(1), self::STORE_B)['sync_hash']
        );
        $this->assertSame(self::STORE_B, $this->prepare($this->order(1), self::STORE_B)['remote_store_url']);
    }

    public static function numericProvider(): array
    {
        return [
            'plain decimal' => ['19.99', 19.99],
            'integer string' => ['20', 20.0],
            'zero' => ['0.00', 0.0],
            'negative refund' => ['-5.50', -5.5],
            'empty string' => ['', 0.0],
            'garbage' => ['n/a', 0.0],
            'null' => [null, 0.0],
            'already float' => [12.345, 12.345],
        ];
    }

    #[DataProvider('numericProvider')]
    public function test_money_fields_become_floats_without_notices(mixed $raw, float $expected): void
    {
        $data = $this->prepare($this->order(1, [
            'total' => $raw, 'total_tax' => $raw, 'shipping_total' => $raw, 'discount_total' => $raw,
        ]));

        foreach (['total', 'tax_total', 'shipping_total', 'discount_total'] as $field) {
            $this->assertEqualsWithDelta($expected, $data[$field], 0.0001, $field);
            $this->assertIsFloat($data[$field], $field);
        }
    }

    public function test_line_item_amounts_are_floats_and_quantity_defaults_to_one(): void
    {
        $data = $this->prepare($this->order(1, ['line_items' => [
            ['product_id' => 4, 'name' => 'A', 'subtotal' => '10.10', 'total' => '9.09', 'total_tax' => '1.01'],
        ]]));

        $item = $data['line_items'][0];
        $this->assertSame(10.10, $item['subtotal']);
        $this->assertSame(9.09, $item['total']);
        $this->assertSame(1.01, $item['tax_total']);
        $this->assertSame(1, $item['quantity']);
    }

    public function test_non_array_line_items_are_ignored(): void
    {
        foreach (['oops', 5, null, true] as $junk) {
            $this->assertSame([], $this->prepare($this->order(1, ['line_items' => $junk]))['line_items']);
        }
    }

    public function test_customer_name_is_trimmed_when_only_one_part_exists(): void
    {
        $this->assertSame('Ann', $this->prepare($this->order(1, ['billing' => ['first_name' => 'Ann', 'last_name' => '']]))['customer_name']);
        $this->assertSame('Lee', $this->prepare($this->order(1, ['billing' => ['first_name' => '', 'last_name' => 'Lee']]))['customer_name']);
        $this->assertSame('', $this->prepare($this->order(1, ['billing' => []]))['customer_name']);
    }

    public function test_order_number_falls_back_to_the_remote_id(): void
    {
        $order = $this->order(77);
        unset($order['number']);

        $this->assertSame(77, $this->prepare($order)['order_number']);
    }

    public static function dateProvider(): array
    {
        return [
            'iso without timezone' => ['2026-03-01T10:00:00', '2026-03-01 10:00:00'],
            'date only' => ['2026-03-01', '2026-03-01 00:00:00'],
            'space separated' => ['2026-03-01 10:00:00', '2026-03-01 10:00:00'],
            'leap day' => ['2024-02-29T23:59:59', '2024-02-29 23:59:59'],
            'end of year' => ['2026-12-31T23:59:59', '2026-12-31 23:59:59'],
        ];
    }

    #[DataProvider('dateProvider')]
    public function test_valid_remote_dates_are_normalised_to_mysql_format(string $given, string $expected): void
    {
        $data = $this->prepare($this->order(1, ['date_created' => $given, 'date_paid' => $given]));

        $this->assertSame($expected, $data['date_created']);
        $this->assertSame($expected, $data['date_paid']);
    }

    public function test_missing_dates_are_null_except_created_which_defaults_to_now(): void
    {
        $order = $this->order(1);
        unset($order['date_created'], $order['date_modified']);
        $order['date_paid'] = null;

        $data = $this->prepare($order);

        $this->assertSame('2026-03-10 12:00:00', $data['date_created']);
        $this->assertNull($data['date_modified']);
        $this->assertNull($data['date_paid']);
        $this->assertNull($data['date_completed']);
    }

    public static function unparsableDateProvider(): array
    {
        return [
            'garbage' => ['not-a-date'],
            'empty' => [''],
            'month 13' => ['2026-13-45T00:00:00'],
        ];
    }

    #[DataProvider('unparsableDateProvider')]
    public function test_unparsable_dates_become_null_instead_of_throwing(string $given): void
    {
        $data = $this->prepare($this->order(1, ['date_paid' => $given, 'date_completed' => $given]));

        $this->assertNull($data['date_paid']);
        $this->assertNull($data['date_completed']);
    }

    // ── Remote_Order_Table query construction ─────────────────────

    private function capturePrepare(): \ArrayObject
    {
        $captured = new \ArrayObject();
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use ($captured) {
            $captured[] = [$sql, isset($args[0]) && is_array($args[0]) ? $args[0] : $args];
            return $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn('0');
        return $captured;
    }

    public function test_filters_reach_prepare_as_bound_values_never_interpolated(): void
    {
        $captured = $this->capturePrepare();
        $evil = "x' OR 1=1 -- ";

        WC_Multi_Store_Remote_Order_Table::get_orders([
            'store_url' => $evil, 'status' => $evil, 'customer_email' => $evil, 'date_from' => $evil, 'date_to' => $evil,
        ]);

        $sqlWithWhere = array_values(array_filter(
            iterator_to_array($captured),
            fn($c) => str_contains($c[0], 'remote_store_url = %s')
        ));
        $this->assertCount(1, $sqlWithWhere);
        $this->assertSame(array_fill(0, 5, $evil), $sqlWithWhere[0][1]);
        $this->assertStringNotContainsString('OR 1=1', $sqlWithWhere[0][0]);
    }

    public static function orderbyProvider(): array
    {
        return [
            'injection' => ['id; DROP TABLE wp_users', 'DESC', 'date_created', 'DESC'],
            'unknown column' => ['billing_address', 'ASC', 'date_created', 'ASC'],
            'valid column' => ['total', 'asc', 'total', 'ASC'],
            'garbage direction' => ['total', 'ASC; DROP TABLE x', 'total', 'DESC'],
            'null orderby' => [null, 'ASC', 'date_created', 'ASC'],
        ];
    }

    #[DataProvider('orderbyProvider')]
    public function test_order_by_is_allowlisted(mixed $orderby, string $order, string $col, string $dir): void
    {
        $queries = [];
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = $sql;
            return [];
        });

        WC_Multi_Store_Remote_Order_Table::get_orders(['orderby' => $orderby ?? '', 'order' => $order]);

        $this->assertStringContainsString("ORDER BY {$col} {$dir}", $queries[0]);
        $this->assertStringNotContainsString('DROP', $queries[0]);
    }

    public function test_limit_and_offset_are_bound_as_values(): void
    {
        $captured = $this->capturePrepare();

        WC_Multi_Store_Remote_Order_Table::get_orders(['limit' => 25, 'offset' => 50]);

        $limit = array_values(array_filter(iterator_to_array($captured), fn($c) => str_contains($c[0], 'LIMIT %d OFFSET %d')));
        $this->assertCount(1, $limit);
        $this->assertSame([25, 50], $limit[0][1]);
    }

    public function test_count_and_orders_share_the_same_filter_semantics(): void
    {
        $captured = $this->capturePrepare();

        WC_Multi_Store_Remote_Order_Table::get_count(['store_url' => self::STORE_A, 'status' => 'completed']);

        $this->assertSame([self::STORE_A, 'completed'], $captured[0][1]);
        $this->assertStringContainsString('remote_store_url = %s AND status = %s', $captured[0][0]);
    }

    public function test_delete_removes_items_before_the_order_and_reports_failure(): void
    {
        $order = [];
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('delete')->andReturnUsing(function ($table, $where) use (&$order) {
            $order[] = $table;
            return str_contains($table, 'items') ? 3 : false;
        });

        $this->assertFalse(WC_Multi_Store_Remote_Order_Table::delete(12));
        $this->assertCount(2, $order);
        $this->assertStringContainsString('items', $order[0]);
        $this->assertStringNotContainsString('items', $order[1]);
    }

    // ── regressions: failures are counted, never silently swallowed ──

    public function test_failed_insert_is_counted_as_an_error_not_a_new_order(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1), $this->order(2)] : [];
        $this->db->failInserts = true;

        $result = $this->sync();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(2, $result['errors']);
        $this->assertSame([], $this->db->orders);
    }

    public function test_failed_update_is_counted_as_an_error_not_an_update(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1)] : [];
        $this->sync();

        $this->db->failUpdates = true;
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1, ['status' => 'completed', 'date_modified' => '2026-04-01T00:00:00'])] : [];
        $result = $this->sync();

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['errors']);
    }

    public function test_an_updated_order_is_skipped_on_the_next_sync(): void
    {
        $this->pageProvider = fn($page) => $page === 1 ? [$this->order(1)] : [];
        $this->sync();

        $changed = $this->order(1, ['status' => 'completed', 'date_modified' => '2026-04-01T00:00:00']);
        $this->pageProvider = fn($page) => $page === 1 ? [$changed] : [];
        $this->assertSame(1, $this->sync()['updated']);

        $this->db->updateCalls = 0;
        $again = $this->sync();

        $this->assertSame(0, $again['updated']);
        $this->assertSame(0, $this->db->updateCalls, 'the stored hash must match the hash computed from the same API payload');
    }

    public function test_malformed_entries_do_not_abort_the_rest_of_the_page(): void
    {
        $this->pageProvider = fn($page) => $page === 1
            ? [null, 'oops', 42, $this->order(2), $this->order(3, ['date_created' => ['not', 'a', 'string']]), $this->order(4)]
            : [];

        $result = $this->sync();

        $this->assertSame(2, $result['synced']);
        $this->assertSame(4, $result['errors']);
        $this->assertCount(2, $this->db->orders);
    }

    public function test_an_object_body_instead_of_a_list_is_reported_not_fatal(): void
    {
        $this->pageProvider = fn($page) => ['code' => 'rest_forbidden', 'message' => 'Sorry'];

        $result = $this->sync();

        $this->assertSame(0, $result['synced']);
        $this->assertGreaterThanOrEqual(1, $result['errors']);
        $this->assertSame([], $this->db->orders);
    }

    public function test_table_update_keeps_a_caller_supplied_hash_and_computes_one_otherwise(): void
    {
        $captured = [];
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')->andReturnUsing(function ($table, $data) use (&$captured) {
            $captured[] = $data;
            return 1;
        });

        WC_Multi_Store_Remote_Order_Table::update(1, ['status' => 'completed', 'total' => 5.0, 'sync_hash' => 'caller-hash']);
        WC_Multi_Store_Remote_Order_Table::update(1, ['status' => 'completed', 'total' => 5.0]);

        $this->assertSame('caller-hash', $captured[0]['sync_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $captured[1]['sync_hash']);
    }

    public static function dateToProvider(): array
    {
        return [
            'date only gets end of day' => ['2026-03-10', '2026-03-10 23:59:59'],
            'full datetime is untouched' => ['2026-03-10 08:30:00', '2026-03-10 08:30:00'],
            'iso datetime is untouched' => ['2026-03-10T08:30:00', '2026-03-10T08:30:00'],
        ];
    }

    #[DataProvider('dateToProvider')]
    public function test_a_date_only_upper_bound_includes_the_whole_day(string $given, string $expected): void
    {
        $captured = $this->capturePrepare();

        WC_Multi_Store_Remote_Order_Table::get_count(['date_from' => '2026-03-01', 'date_to' => $given]);

        $this->assertSame(['2026-03-01', $expected], $captured[0][1]);
    }
}
