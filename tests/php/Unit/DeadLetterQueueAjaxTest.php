<?php
/**
 * AJAX handler tests for WC_Multi_Store_Dead_Letter_Queue (listing, retry, resolve, clear).
 * DeadLetterQueueTest covers the underlying static methods.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class DeadLetterQueueAjaxTest extends WC_Multi_Store_TestCase
{
    /** @var array{success: ?bool, data: mixed} */
    private array $response = ['success' => null, 'data' => null];

    /** @var list<string> */
    private array $prepared = [];

    /** @var list<string> */
    private array $queries = [];

    /** @var list<array{0: string, 1: array, 2: array}> */
    private array $updates = [];

    /** @var list<array{0: string, 1: string}> check_ajax_referer() calls. */
    private array $nonceChecks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->response = ['success' => null, 'data' => null];
        $this->prepared = [];
        $this->queries = [];
        $this->updates = [];
        $this->nonceChecks = [];
        $_POST = [];

        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('sanitize_sql_orderby')->alias(fn($v) => preg_match('/^[a-z_]+( (ASC|DESC))?$/i', $v) ? $v : false);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->alias(function ($action, $field) {
            $this->nonceChecks[] = [$action, $field];
            return 1;
        });
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
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function db(array $rows = [], int $total = 0, int|false $updateResult = 1, int $deleteResult = 0): void
    {
        global $wpdb;
        $wpdb = new class ($this, $rows, $total, $updateResult, $deleteResult) extends wpdb {
            public function __construct(
                private readonly DeadLetterQueueAjaxTest $test,
                private readonly array $rows,
                private readonly int $total,
                private readonly int|false $updateResult,
                private readonly int $deleteResult,
            ) {}

            public function prepare(string $query, mixed ...$args): string
            {
                $flat = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
                $query = vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $flat);
                $this->test->recordPrepared($query);
                return $query;
            }

            public function get_results(string $query, string $output = OBJECT): array|object|null
            {
                $this->test->recordQuery($query);
                return str_contains($query, 'GROUP BY') ? [] : $this->rows;
            }

            public function get_var(string $query): mixed
            {
                $this->test->recordQuery($query);
                return str_contains($query, 'COUNT(*)') && str_contains($query, "status = 'dead'") && !str_contains($query, 'AND ')
                    ? 7
                    : (string) $this->total;
            }

            public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false
            {
                $this->test->recordUpdate($table, $data, $where);
                return $this->updateResult;
            }

            public function query(string $query): int|false
            {
                $this->test->recordQuery($query);
                return $this->deleteResult;
            }
        };
    }

    /** @internal */
    public function recordPrepared(string $q): void
    {
        $this->prepared[] = $q;
    }

    /** @internal */
    public function recordQuery(string $q): void
    {
        $this->queries[] = $q;
    }

    /** @internal */
    public function recordUpdate(string $table, array $data, array $where): void
    {
        $this->updates[] = [$table, $data, $where];
    }

    public static function handler_provider(): array
    {
        return [
            'get items'    => ['ajax_get_items'],
            'retry item'   => ['ajax_retry_item'],
            'retry all'    => ['ajax_retry_all'],
            'resolve item' => ['ajax_resolve_item'],
            'clear all'    => ['ajax_clear_all'],
        ];
    }

    #[DataProvider('handler_provider')]
    public function test_every_handler_verifies_nonce_then_denies_users_without_capability(string $handler): void
    {
        Functions\when('current_user_can')->alias(function ($cap) {
            $this->assertSame('manage_woocommerce', $cap);
            return false;
        });
        $this->db();
        $_POST = ['item_id' => '3'];

        WC_Multi_Store_Dead_Letter_Queue::$handler();

        $this->assertSame([['wc_mss_admin', 'nonce']], $this->nonceChecks);
        $this->assertFalse($this->response['success']);
        $this->assertSame('Unauthorized', $this->response['data']['message']);
        $this->assertSame([], $this->updates, 'No state may change for an unauthorised caller');
        $this->assertSame([], $this->queries);
    }

    // ─── ajax_get_items ────────────────────────────

    public function test_get_items_defaults_to_dead_items_limit_50_offset_0_and_returns_stats(): void
    {
        $rows = [['id' => 1, 'last_error' => 'boom']];
        $this->db($rows, 12);

        WC_Multi_Store_Dead_Letter_Queue::ajax_get_items();

        $this->assertTrue($this->response['success']);
        $this->assertSame($rows, $this->response['data']['items']);
        $this->assertSame(12, $this->response['data']['total']);
        $this->assertArrayHasKey('total_dead', $this->response['data']['stats']);
        $this->assertArrayHasKey('by_error', $this->response['data']['stats']);
        $this->assertContains("status = 'dead'", $this->prepared);
        $listing = array_values(array_filter($this->prepared, fn($q) => str_contains($q, 'LIMIT')));
        $this->assertStringEndsWith('LIMIT 50 OFFSET 0', $listing[0]);
    }

    public static function pagination_provider(): array
    {
        return [
            'limit above cap'      => [['limit' => '5000', 'offset' => '20'], 100, 20],
            'limit zero'           => [['limit' => '0', 'offset' => '0'], 1, 0],
            'limit garbage'        => [['limit' => 'abc'], 1, 0],
            'negative offset'      => [['limit' => '10', 'offset' => '-30'], 10, 30],
            'array limit ignored'  => [['limit' => ['9'], 'offset' => ['4']], 50, 0],
            'valid'                => [['limit' => '25', 'offset' => '75'], 25, 75],
        ];
    }

    #[DataProvider('pagination_provider')]
    public function test_get_items_bounds_pagination_parameters(array $post, int $limit, int $offset): void
    {
        $this->db();
        $_POST = $post;

        WC_Multi_Store_Dead_Letter_Queue::ajax_get_items();

        $listing = array_values(array_filter($this->prepared, fn($q) => str_contains($q, 'LIMIT')));
        $this->assertStringEndsWith("LIMIT $limit OFFSET $offset", $listing[0]);
    }

    public static function status_provider(): array
    {
        return [
            'dead'       => ['dead', 'dead'],
            'resolved'   => ['resolved', 'resolved'],
            'retried'    => ['retried', 'retried'],
            'uppercase'  => ['RESOLVED', 'resolved'],
            'unknown'    => ['pending', 'dead'],
            'injection'  => ["dead' OR '1'='1", 'dead'],
            'array'      => [['resolved'], 'dead'],
        ];
    }

    #[DataProvider('status_provider')]
    public function test_get_items_only_accepts_whitelisted_statuses(mixed $submitted, string $expected): void
    {
        $this->db();
        $_POST = ['status' => $submitted];

        WC_Multi_Store_Dead_Letter_Queue::ajax_get_items();

        $this->assertContains("status = '$expected'", $this->prepared);
        foreach ($this->prepared as $query) {
            $this->assertStringNotContainsString("'1'='1", $query);
        }
    }

    // ─── ajax_retry_item / ajax_resolve_item ───────

    public static function invalid_id_provider(): array
    {
        return [
            'missing' => [[]],
            'zero'    => [['item_id' => '0']],
            'garbage' => [['item_id' => 'abc']],
            'array'   => [['item_id' => ['5']]],
        ];
    }

    #[DataProvider('invalid_id_provider')]
    public function test_retry_and_resolve_reject_invalid_item_ids(array $post): void
    {
        $this->db();
        $_POST = $post;

        WC_Multi_Store_Dead_Letter_Queue::ajax_retry_item();
        $this->assertFalse($this->response['success']);
        $this->assertSame('Invalid item ID.', $this->response['data']['message']);

        $this->response = ['success' => null, 'data' => null];
        WC_Multi_Store_Dead_Letter_Queue::ajax_resolve_item();
        $this->assertFalse($this->response['success']);
        $this->assertSame('Invalid item ID.', $this->response['data']['message']);

        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->queries);
    }

    public function test_resolve_item_marks_the_given_row_resolved(): void
    {
        $this->db([], 0, 1);
        $_POST = ['item_id' => '15'];

        WC_Multi_Store_Dead_Letter_Queue::ajax_resolve_item();

        $this->assertTrue($this->response['success']);
        $this->assertSame('Item marked as resolved.', $this->response['data']['message']);
        $this->assertCount(1, $this->updates);
        $this->assertSame('wp_wc_mss_dead_letter_queue', $this->updates[0][0]);
        $this->assertSame('resolved', $this->updates[0][1]['status']);
        $this->assertSame(['id' => 15], $this->updates[0][2]);
    }

    public function test_resolve_item_reports_failure_when_row_was_not_updated(): void
    {
        $this->db([], 0, 0);
        $_POST = ['item_id' => '15'];

        WC_Multi_Store_Dead_Letter_Queue::ajax_resolve_item();

        $this->assertFalse($this->response['success']);
        $this->assertSame('Failed to resolve item.', $this->response['data']['message']);
    }

    public function test_retry_item_reports_failure_when_dead_letter_row_does_not_exist(): void
    {
        $this->db();
        $_POST = ['item_id' => '404'];

        WC_Multi_Store_Dead_Letter_Queue::ajax_retry_item();

        $this->assertFalse($this->response['success']);
        $this->assertSame('Failed to retry item.', $this->response['data']['message']);
        $this->assertSame([], $this->updates, 'A missing item must not be marked as retried');
    }

    // ─── ajax_retry_all / ajax_clear_all ───────────

    public function test_retry_all_with_nothing_dead_reports_zero(): void
    {
        $this->db([], 0);

        WC_Multi_Store_Dead_Letter_Queue::ajax_retry_all();

        $this->assertTrue($this->response['success']);
        $this->assertSame(0, $this->response['data']['count']);
        $this->assertSame('0 item(s) re-queued for processing.', $this->response['data']['message']);
    }

    public function test_clear_all_reports_number_of_deleted_rows(): void
    {
        $this->db([], 0, 1, 4);

        WC_Multi_Store_Dead_Letter_Queue::ajax_clear_all();

        $this->assertTrue($this->response['success']);
        $this->assertSame(4, $this->response['data']['count']);
        $this->assertSame('Cleared 4 item(s) from dead letter queue.', $this->response['data']['message']);
        $this->assertContains("DELETE FROM wp_wc_mss_dead_letter_queue WHERE status = 'dead'", $this->queries);
    }

    public function test_clear_all_with_empty_queue_reports_zero(): void
    {
        $this->db([], 0, 1, 0);

        WC_Multi_Store_Dead_Letter_Queue::ajax_clear_all();

        $this->assertTrue($this->response['success']);
        $this->assertSame(0, $this->response['data']['count']);
    }
}
