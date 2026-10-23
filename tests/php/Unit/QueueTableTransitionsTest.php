<?php
/**
 * State-transition tests for WC_Multi_Store_Queue_Table.
 *
 * Every test records the exact SQL / arguments that reach $wpdb so that a
 * silent change in a WHERE clause or a status value is caught.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class QueueTableTransitionsTest extends WC_Multi_Store_TestCase
{
    /** @var array<int, array<string, mixed>> Ordered log of every wpdb call. */
    private array $calls = [];

    /** @var array<string, callable> Per-method response overrides. */
    private array $responders = [];

    private int $nextInsertId = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        $this->responders = [];
        $this->nextInsertId = 100;

        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
    }

    /**
     * Build a Mockery $wpdb that records every call and substitutes %s / %d.
     */
    private function fakeDb(): \Mockery\MockInterface
    {
        global $wpdb;
        $db = \Mockery::mock('wpdb');
        $db->prefix = 'wp_';
        $db->insert_id = 0;
        $wpdb = $db;

        $db->shouldReceive('prepare')->andReturnUsing(static function (string $q, ...$args): string {
            if (isset($args[0]) && is_array($args[0])) {
                $args = $args[0];
            }
            $i = 0;
            return (string) preg_replace_callback('/%[sd]/', static function (array $m) use (&$i, $args): string {
                $v = $args[$i++] ?? '';
                return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
            }, $q);
        });

        foreach (['get_var', 'get_row', 'get_results', 'query'] as $method) {
            $db->shouldReceive($method)->andReturnUsing(function (string $sql) use ($method) {
                $this->calls[] = ['op' => $method, 'sql' => $sql];
                if (isset($this->responders[$method])) {
                    return ($this->responders[$method])($sql);
                }
                if ($method === 'get_var' && (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK'))) {
                    return 1;
                }
                return match ($method) {
                    'query' => 1,
                    'get_results' => [],
                    default => null,
                };
            });
        }

        $db->shouldReceive('insert')->andReturnUsing(function (string $table, array $data, ?array $format = null) use ($db) {
            $this->calls[] = ['op' => 'insert', 'table' => $table, 'data' => $data, 'format' => $format];
            if (isset($this->responders['insert'])) {
                return ($this->responders['insert'])($table, $data);
            }
            $db->insert_id = $this->nextInsertId++;
            return 1;
        });
        $db->shouldReceive('update')->andReturnUsing(function (string $table, array $data, array $where, ?array $f = null, ?array $wf = null) {
            $this->calls[] = ['op' => 'update', 'table' => $table, 'data' => $data, 'where' => $where, 'format' => $f, 'where_format' => $wf];
            if (isset($this->responders['update'])) {
                return ($this->responders['update'])($table, $data, $where);
            }
            return 1;
        });
        $db->shouldReceive('delete')->andReturnUsing(function (string $table, array $where) {
            $this->calls[] = ['op' => 'delete', 'table' => $table, 'where' => $where];
            return 1;
        });

        return $db;
    }

    /** @return array<int, array<string, mixed>> */
    private function callsOf(string $op): array
    {
        return array_values(array_filter($this->calls, static fn(array $c): bool => $c['op'] === $op));
    }

    /** @return array<int, string> SQL strings of get_var/query calls matching a needle. */
    private function sqlContaining(string $needle): array
    {
        $out = [];
        foreach ($this->calls as $c) {
            if (isset($c['sql']) && str_contains($c['sql'], $needle)) {
                $out[] = $c['sql'];
            }
        }
        return $out;
    }

    // ── add(): advisory lock handling ──────────────────────────────

    public function test_add_uses_lock_name_derived_from_product_and_store(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::add(42, 'https://a.test');

        $expected = 'wc_mss_enqueue_' . md5('42|https://a.test');
        $this->assertSame("SELECT GET_LOCK('{$expected}', 2)", $this->callsOf('get_var')[0]['sql']);
        $this->assertSame("SELECT RELEASE_LOCK('{$expected}')", $this->callsOf('get_var')[1]['sql']);
    }

    public static function lockFailureProvider(): array
    {
        return [
            'lock timed out (0)' => [0],
            'lock error (null)'  => [null],
            'string zero'        => ['0'],
            'empty string'       => [''],
            'two is not one'     => [2],
        ];
    }

    #[DataProvider('lockFailureProvider')]
    public function test_add_returns_false_and_touches_nothing_when_lock_not_acquired(mixed $lockResult): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = static fn(string $sql) => $lockResult;

        $this->assertFalse(WC_Multi_Store_Queue_Table::add(42, 'https://a.test'));

        $this->assertCount(1, $this->callsOf('get_var'), 'Only GET_LOCK may run; RELEASE_LOCK without a lock would free another worker\'s lock.');
        $this->assertSame([], $this->callsOf('get_row'));
        $this->assertSame([], $this->callsOf('insert'));
        $this->assertSame([], $this->callsOf('update'));
    }

    public function test_add_accepts_string_one_as_lock_acquired(): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = static fn(string $sql) => str_contains($sql, 'GET_LOCK') ? '1' : 1;

        $this->assertSame(100, WC_Multi_Store_Queue_Table::add(42, 'https://a.test'));
    }

    public function test_add_releases_lock_when_insert_throws(): void
    {
        $this->fakeDb();
        $this->responders['insert'] = static function (): never {
            throw new \RuntimeException('db gone');
        };

        try {
            WC_Multi_Store_Queue_Table::add(42, 'https://a.test');
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('db gone', $e->getMessage());
        }

        $this->assertCount(1, $this->sqlContaining('RELEASE_LOCK'), 'Lock must be released in finally{}.');
    }

    public function test_add_releases_lock_when_lookup_throws(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static function (): never {
            throw new \RuntimeException('lookup failed');
        };

        $this->expectException(\RuntimeException::class);
        try {
            WC_Multi_Store_Queue_Table::add(42, 'https://a.test');
        } finally {
            $this->assertCount(1, $this->sqlContaining('RELEASE_LOCK'));
        }
    }

    // ── add(): duplicate suppression & identity ────────────────────

    public function test_same_item_added_twice_inserts_once_and_returns_same_id(): void
    {
        $this->fakeDb();
        $stored = null;
        $this->responders['insert'] = function (string $table, array $data) use (&$stored) {
            $stored = ['id' => 55, 'status' => $data['status']];
            global $wpdb;
            $wpdb->insert_id = 55;
            return 1;
        };
        $this->responders['get_row'] = static function () use (&$stored) {
            return $stored;
        };

        $first = WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'full_product', 5, 'hook');
        $second = WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'full_product', 5, 'hook');

        $this->assertSame(55, $first);
        $this->assertSame(55, $second);
        $this->assertCount(1, $this->callsOf('insert'), 'Duplicate enqueue must not create a second row.');
    }

    public function test_duplicate_lookup_only_considers_pending_and_processing_rows(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::add(7, 'https://a.test');

        $sql = $this->callsOf('get_row')[0]['sql'];
        $this->assertStringContainsString("FROM wp_wc_mss_queue", $sql);
        $this->assertStringContainsString('product_id = 7', $sql);
        $this->assertStringContainsString("store_url = 'https://a.test'", $sql);
        $this->assertStringContainsString("status IN ('pending', 'processing')", $sql);
        $this->assertStringNotContainsString("'failed'", $sql, 'A failed row must not block re-enqueue.');
        $this->assertStringNotContainsString("'completed'", $sql, 'A completed row must not block re-enqueue.');
    }

    public static function resolutionTypeProvider(): array
    {
        return [
            'conflict' => ['conflict_resolution', 'conflict_resolution_9'],
            'stock'    => ['stock_correction', 'stock_discrepancy_9'],
        ];
    }

    #[DataProvider('resolutionTypeProvider')]
    public function test_resolution_items_match_on_type_and_source_identity(string $type, string $source): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::add(7, 'https://a.test', $type, 1, $source);

        $sql = $this->callsOf('get_row')[0]['sql'];
        $this->assertStringContainsString("AND sync_type = '{$type}' AND source = '{$source}'", $sql);
        $this->assertStringNotContainsString('NOT IN', $sql);
    }

    #[DataProvider('resolutionTypeProvider')]
    public function test_regular_items_never_dedupe_against_resolution_items(string $type, string $source): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'full_product', 5, 'hook');

        $sql = $this->callsOf('get_row')[0]['sql'];
        $this->assertStringContainsString("sync_type NOT IN ('conflict_resolution', 'stock_correction',", $sql);
    }

    /** @return array<string, array{string}> */
    public static function distinctOperationProvider(): array
    {
        return [
            'delete product' => ['delete_product'],
            'delete variation' => ['delete_variation'],
            'delete orphan' => ['delete_orphan'],
            'restore' => ['restore_product'],
            'status change' => ['status_change'],
        ];
    }

    #[DataProvider('distinctOperationProvider')]
    public function test_deletions_restores_and_status_changes_only_dedupe_against_their_own_type(string $type): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::add(7, 'https://a.test', $type, 2, 'product_delete');

        $sql = $this->callsOf('get_row')[0]['sql'];
        $this->assertStringContainsString("AND sync_type = '{$type}'", $sql);
        $this->assertStringNotContainsString('NOT IN', $sql);
    }

    #[DataProvider('distinctOperationProvider')]
    public function test_regular_syncs_never_swallow_a_deletion_restore_or_status_change(string $type): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'full_product', 5, 'hook');

        $this->assertStringContainsString("'{$type}'", $this->callsOf('get_row')[0]['sql']);
    }

    #[DataProvider('resolutionTypeProvider')]
    public function test_resolution_item_with_existing_processing_row_is_not_duplicated_or_updated(string $type, string $source): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => ['id' => 31, 'status' => 'processing'];

        $id = WC_Multi_Store_Queue_Table::add(7, 'https://a.test', $type, 1, $source, null, 'SKU', ['x' => 1]);

        $this->assertSame(31, $id);
        $this->assertSame([], $this->callsOf('insert'));
        $this->assertSame([], $this->callsOf('update'), 'Resolution identity must not be rewritten.');
    }

    #[DataProvider('distinctOperationProvider')]
    public function test_distinct_operations_dedupe_only_against_the_same_sync_type(string $type): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::add(7, 'https://a.test', $type, 2, 'hook');

        $sql = $this->callsOf('get_row')[0]['sql'];
        $this->assertStringContainsString("AND sync_type = '{$type}'", $sql);
        $this->assertStringNotContainsString('NOT IN', $sql);
        $this->assertStringNotContainsString('source =', $sql, 'Only resolution items also match on source.');
    }

    #[DataProvider('distinctOperationProvider')]
    public function test_pending_full_product_row_does_not_swallow_a_delete_or_restore(string $type): void
    {
        $this->fakeDb();
        // Model the table: a pending full_product row exists; the lookup honours the emitted sync_type filter.
        $this->responders['get_row'] = static function (string $sql) {
            $row = ['id' => 31, 'status' => 'pending', 'sync_type' => 'full_product'];
            if (str_contains($sql, 'NOT IN')) {
                return in_array($row['sync_type'], ['conflict_resolution', 'stock_correction', 'delete_product', 'delete_variation', 'delete_orphan', 'restore_product', 'status_change'], true) ? null : $row;
            }
            if (preg_match("/AND sync_type = '([^']+)'/", $sql, $m)) {
                return $m[1] === $row['sync_type'] ? $row : null;
            }
            return $row;
        };

        $id = WC_Multi_Store_Queue_Table::add(7, 'https://a.test', $type, 2, 'hook', null, 'SKU-7', ['k' => 1]);

        $this->assertSame(100, $id, 'A new row must be inserted for the distinct operation.');
        $insert = $this->callsOf('insert')[0]['data'];
        $this->assertSame($type, $insert['sync_type']);
        $this->assertSame('SKU-7', $insert['product_sku']);
        $this->assertSame([], $this->callsOf('update'), 'The pending full_product row must not be rewritten.');
    }

    public function test_regular_sync_does_not_merge_into_a_pending_delete(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn(string $sql) => str_contains($sql, 'NOT IN') ? null : ['id' => 31, 'status' => 'pending'];

        $this->assertSame(100, WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'full_product'));
        $this->assertCount(1, $this->callsOf('insert'));
    }

    public function test_same_delete_enqueued_twice_is_still_deduped(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn(string $sql) => str_contains($sql, "sync_type = 'delete_product'") ? ['id' => 31, 'status' => 'pending'] : null;

        $this->assertSame(31, WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'delete_product', 2));
        $this->assertSame([], $this->callsOf('insert'));
    }

    public function test_new_operation_while_previous_is_processing_inserts_fresh_pending_row(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => ['id' => 31, 'status' => 'processing'];

        $id = WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'status_change', 2, 'status_change', null, 'SKU-7', ['new_status' => 'draft']);

        $this->assertSame(100, $id, 'The new row id (not the in-flight one) must be returned.');
        $insert = $this->callsOf('insert')[0];
        $this->assertSame('wp_wc_mss_queue', $insert['table']);
        $this->assertSame('pending', $insert['data']['status']);
        $this->assertSame(0, $insert['data']['attempts']);
        $this->assertSame('status_change', $insert['data']['sync_type']);
        $this->assertSame(2, $insert['data']['priority']);
        $this->assertSame('SKU-7', $insert['data']['product_sku']);
        $this->assertSame('{"new_status":"draft"}', $insert['data']['extra_data']);
        $this->assertSame([], $this->callsOf('update'), 'The in-flight row must be left alone.');
    }

    public function test_processing_branch_returns_false_when_insert_fails(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => ['id' => 31, 'status' => 'processing'];
        $this->responders['insert'] = static fn() => false;

        $this->assertFalse(WC_Multi_Store_Queue_Table::add(7, 'https://a.test'));
        $this->assertCount(1, $this->sqlContaining('RELEASE_LOCK'));
    }

    public static function pendingDuplicateUpdateProvider(): array
    {
        return [
            'default priority only: no write'     => [5, null, null, null],
            'low priority never raises row'       => [10, null, null, null],
            'priority 6..9 never raises row'      => [9, null, null, null],
            'priority 4 upgrades priority+source' => [4, null, null, ['priority' => 4, 'source' => 'hook']],
            'priority 1 upgrades priority+source' => [1, null, null, ['priority' => 1, 'source' => 'hook']],
            'sku only'                            => [5, 'SKU-1', null, ['product_sku' => 'SKU-1']],
            'extra only'                          => [5, null, ['a' => 1], ['extra_data' => '{"a":1}']],
            'everything'                          => [2, 'S', ['a' => 1], ['priority' => 2, 'source' => 'hook', 'product_sku' => 'S', 'extra_data' => '{"a":1}']],
            'empty extra array still written'     => [5, null, [], ['extra_data' => '[]']],
        ];
    }

    #[DataProvider('pendingDuplicateUpdateProvider')]
    public function test_pending_duplicate_update_payload(int $priority, ?string $sku, ?array $extra, ?array $expectedUpdate): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => ['id' => 77, 'status' => 'pending'];

        $id = WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'full_product', $priority, 'hook', null, $sku, $extra);

        $this->assertSame(77, $id);
        $this->assertSame([], $this->callsOf('insert'));
        $updates = $this->callsOf('update');
        if ($expectedUpdate === null) {
            $this->assertSame([], $updates);
            return;
        }
        $this->assertCount(1, $updates);
        $this->assertSame('wp_wc_mss_queue', $updates[0]['table']);
        $this->assertSame($expectedUpdate, $updates[0]['data']);
        $this->assertSame(['id' => 77], $updates[0]['where']);
        $this->assertSame(count($expectedUpdate), count($updates[0]['format']), 'One format per updated column.');
        $this->assertSame(['%d'], $updates[0]['where_format']);
    }

    public function test_insert_stores_schedule_and_null_extra_data(): void
    {
        $this->fakeDb();
        $id = WC_Multi_Store_Queue_Table::add(7, 'https://a.test', 'full_product', 10, 'scheduled', '2024-06-15 13:00:00');

        $this->assertSame(100, $id);
        $data = $this->callsOf('insert')[0]['data'];
        $this->assertSame('2024-06-15 13:00:00', $data['scheduled_at']);
        $this->assertNull($data['extra_data']);
        $this->assertNull($data['product_sku']);
        $this->assertSame('2024-06-15 12:00:00', $data['created_at']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame(0, $data['attempts']);
    }

    public function test_insert_failure_returns_false_and_releases_lock(): void
    {
        $this->fakeDb();
        $this->responders['insert'] = static fn() => false;

        $this->assertFalse(WC_Multi_Store_Queue_Table::add(7, 'https://a.test'));
        $this->assertCount(1, $this->sqlContaining('RELEASE_LOCK'));
    }

    // ── get_next_batch() ───────────────────────────────────────────

    public function test_get_next_batch_sql_selects_due_pending_rows_in_priority_order(): void
    {
        $this->fakeDb();
        $this->responders['get_results'] = static fn() => [['id' => 1]];

        $rows = WC_Multi_Store_Queue_Table::get_next_batch(25);

        $this->assertSame([['id' => 1]], $rows);
        $sql = $this->callsOf('get_results')[0]['sql'];
        $this->assertStringContainsString("status = 'pending'", $sql);
        $this->assertStringContainsString("(scheduled_at IS NULL OR scheduled_at <= '2024-06-15 12:00:00')", $sql);
        $this->assertStringContainsString('attempts < 3', $sql);
        $this->assertMatchesRegularExpression('/ORDER BY priority ASC, store_url ASC, created_at ASC\s+LIMIT 25$/', trim($sql));
    }

    public function test_get_next_batch_never_selects_processing_rows(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::get_next_batch();

        $sql = $this->callsOf('get_results')[0]['sql'];
        $this->assertStringNotContainsString("'processing'", $sql);
        $this->assertStringContainsString('LIMIT 10', $sql, 'Default batch size is 10.');
    }

    public function test_get_next_batch_numeric_string_limit_is_coerced(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::get_next_batch('7');
        $this->assertStringContainsString('LIMIT 7', $this->callsOf('get_results')[0]['sql']);
    }

    public static function limitProvider(): array
    {
        return ['zero' => [0, 1], 'negative' => [-5, 1], 'string zero' => ['0', 1], 'one' => [1, 1], 'big' => [500, 500], 'junk' => ['abc', 1]];
    }

    #[DataProvider('limitProvider')]
    public function test_get_next_batch_clamps_limit_to_at_least_one(int|string $limit, int $expected): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::get_next_batch($limit);
        $this->assertMatchesRegularExpression('/LIMIT ' . $expected . '$/', trim($this->callsOf('get_results')[0]['sql']));
    }

    public function test_get_next_batch_returns_empty_array_when_query_fails(): void
    {
        $this->fakeDb();
        $this->responders['get_results'] = static fn() => null;
        $this->assertSame([], WC_Multi_Store_Queue_Table::get_next_batch(5));
    }

    public function test_get_next_batch_on_empty_queue_returns_empty_array(): void
    {
        $this->fakeDb();
        $this->responders['get_results'] = static fn() => [];
        $this->assertSame([], WC_Multi_Store_Queue_Table::get_next_batch(5));
    }

    // ── mark_processing / mark_completed ───────────────────────────

    public function test_mark_processing_args(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::mark_processing(12);

        $u = $this->callsOf('update')[0];
        $this->assertSame('wp_wc_mss_queue', $u['table']);
        $this->assertSame(['status' => 'processing', 'started_at' => '2024-06-15 12:00:00'], $u['data']);
        $this->assertSame(['id' => 12, 'status' => 'pending'], $u['where'], 'Claim must be atomic: only a pending row can be claimed.');
        $this->assertSame(['%s', '%s'], $u['format']);
        $this->assertSame(['%d', '%s'], $u['where_format']);
    }

    public function test_mark_processing_returns_zero_when_row_was_already_claimed(): void
    {
        $this->fakeDb();
        $this->responders['update'] = static fn() => 0;
        $this->assertSame(0, WC_Multi_Store_Queue_Table::mark_processing(12));
    }

    public function test_mark_completed_args(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::mark_completed(12);

        $u = $this->callsOf('update')[0];
        $this->assertSame(['status' => 'completed', 'completed_at' => '2024-06-15 12:00:00'], $u['data']);
        $this->assertSame(['id' => 12], $u['where']);
    }

    public function test_mark_helpers_propagate_db_failure_and_zero_rows(): void
    {
        $this->fakeDb();
        $this->responders['update'] = static fn() => false;
        $this->assertFalse(WC_Multi_Store_Queue_Table::mark_processing(1));
        $this->assertFalse(WC_Multi_Store_Queue_Table::mark_completed(1));

        $this->responders['update'] = static fn() => 0;
        $this->assertSame(0, WC_Multi_Store_Queue_Table::mark_completed(999999), 'Unknown id affects 0 rows.');
    }

    // ── mark_failed(): retry vs dead letter ────────────────────────

    public static function attemptsProvider(): array
    {
        return [
            'first failure retries'    => [1, false],
            'second failure retries'   => [2, false],
            'third failure is final'   => [3, true],
            'beyond limit is final'    => [4, true],
            'string 3 is final'        => ['3', true],
        ];
    }

    #[DataProvider('attemptsProvider')]
    public function test_mark_failed_boundary_at_max_attempts(int|string $attemptsAfterIncrement, bool $final): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = fn(string $sql) => str_contains($sql, 'SELECT attempts') ? $attemptsAfterIncrement : 1;
        $this->responders['get_row'] = fn() => ['id' => 5, 'product_id' => 9, 'store_url' => 'https://a.test', 'sync_type' => 'full_product', 'source' => 'hook', 'attempts' => $attemptsAfterIncrement, 'status' => 'processing'];

        WC_Multi_Store_Queue_Table::mark_failed(5, 'boom');

        $update = $this->callsOf('update')[0];
        $dlq = array_values(array_filter($this->callsOf('insert'), static fn($c) => $c['table'] === 'wp_wc_mss_dead_letter_queue'));

        if ($final) {
            $this->assertSame('failed', $update['data']['status']);
            $this->assertSame('boom', $update['data']['last_error']);
            $this->assertSame('2024-06-15 12:00:00', $update['data']['completed_at']);
            $this->assertArrayNotHasKey('scheduled_at', $update['data']);
            $this->assertCount(1, $dlq, 'Exhausted item must be copied to the DLQ exactly once.');
            $this->assertSame('boom', $dlq[0]['data']['last_error']);
            $this->assertSame(5, $dlq[0]['data']['original_queue_id']);
            $this->assertSame('dead', $dlq[0]['data']['status']);
        } else {
            $this->assertSame('pending', $update['data']['status']);
            $this->assertSame('boom', $update['data']['last_error']);
            $this->assertArrayNotHasKey('completed_at', $update['data']);
            $this->assertArrayHasKey('scheduled_at', $update['data']);
            $this->assertSame([], $dlq, 'A retryable failure must not reach the DLQ.');
        }
        $this->assertSame(['id' => 5], $update['where']);
    }

    public function test_mark_failed_increments_attempts_atomically_in_sql_before_reading_it_back(): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = static fn(string $sql) => 1;

        WC_Multi_Store_Queue_Table::mark_failed(5, 'x');

        $this->assertSame('UPDATE wp_wc_mss_queue SET attempts = attempts + 1 WHERE id = 5', $this->callsOf('query')[0]['sql']);
        $this->assertSame('SELECT attempts FROM wp_wc_mss_queue WHERE id = 5', $this->callsOf('get_var')[0]['sql']);
        $this->assertSame('query', $this->calls[0]['op'], 'The increment must run before the read.');
    }

    public function test_mark_failed_backoff_is_in_the_future_and_grows_with_attempts(): void
    {
        $this->fakeDb();
        $times = [];
        foreach ([1, 2] as $attempts) {
            $this->calls = [];
            $this->responders['get_var'] = static fn(string $sql) => $attempts;
            WC_Multi_Store_Queue_Table::mark_failed(5, 'x');
            $times[$attempts] = strtotime($this->callsOf('update')[0]['data']['scheduled_at']);
        }

        $this->assertGreaterThan(time() + 5, $times[1], 'Retry must be scheduled in the future.');
        $this->assertGreaterThan($times[1], $times[2], 'Back-off must grow.');
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', date('Y-m-d H:i:s', $times[1])));
    }

    public function test_mark_failed_no_retry_goes_dead_on_first_attempt(): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = static fn(string $sql) => 1;
        $this->responders['get_row'] = static fn() => ['id' => 5, 'product_id' => 9, 'store_url' => 'https://a.test', 'sync_type' => 'full_product', 'source' => 'hook', 'attempts' => 1];

        WC_Multi_Store_Queue_Table::mark_failed(5, 'Product has no SKU', true);

        $this->assertSame('failed', $this->callsOf('update')[0]['data']['status']);
        $dlq = $this->callsOf('insert');
        $this->assertCount(1, $dlq);
        $this->assertSame('wp_wc_mss_dead_letter_queue', $dlq[0]['table']);
        $this->assertSame(1, $dlq[0]['data']['attempts']);
    }

    public function test_mark_failed_for_vanished_row_still_marks_failed_without_dlq_entry(): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = static fn(string $sql) => 3;
        $this->responders['get_row'] = static fn() => null;

        WC_Multi_Store_Queue_Table::mark_failed(5, 'late');

        $this->assertSame([], $this->callsOf('insert'));
        $this->assertSame('failed', $this->callsOf('update')[0]['data']['status']);
    }

    public function test_mark_failed_still_updates_queue_row_when_dlq_insert_fails(): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = static fn(string $sql) => 3;
        $this->responders['get_row'] = static fn() => ['id' => 5, 'product_id' => 9, 'store_url' => 'https://a.test', 'sync_type' => 'full_product', 'attempts' => 3];
        $this->responders['insert'] = static fn() => false;

        $result = WC_Multi_Store_Queue_Table::mark_failed(5, 'late');

        $this->assertSame(1, $result);
        $this->assertSame('failed', $this->callsOf('update')[0]['data']['status']);
    }

    public function test_mark_failed_passes_stored_extra_data_and_started_at_into_dlq(): void
    {
        $this->fakeDb();
        $this->responders['get_var'] = static fn(string $sql) => 3;
        $this->responders['get_row'] = static fn() => [
            'id' => 5, 'product_id' => 9, 'product_sku' => 'SKU-9', 'store_url' => 'https://a.test',
            'sync_type' => 'delete_product', 'source' => 'product_delete', 'attempts' => 3,
            'extra_data' => '{"parent_id":4}', 'started_at' => '2024-06-15 11:59:00',
        ];

        WC_Multi_Store_Queue_Table::mark_failed(5, 'API down');

        $data = $this->callsOf('insert')[0]['data'];
        $this->assertSame('delete_product', $data['sync_type']);
        $this->assertSame('SKU-9', $data['product_sku']);
        $this->assertSame('{"parent_id":4}', $data['extra_data'], 'Deletion payload must survive the move to the DLQ.');
        $this->assertSame('2024-06-15 11:59:00', $data['first_failed_at']);
    }

    // ── cleanup / reset_stuck_items ────────────────────────────────

    public function test_reset_stuck_items_sql_only_touches_old_processing_rows(): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => 3;
        $before = time();

        $this->assertSame(3, WC_Multi_Store_Queue_Table::reset_stuck_items(5));

        $sql = $this->callsOf('query')[0]['sql'];
        $this->assertStringContainsString("SET status = IF(attempts + 1 >= 3, 'failed', 'pending'), completed_at = IF(attempts + 1 >= 3, '2024-06-15 12:00:00', completed_at), attempts = attempts + 1, started_at = NULL", preg_replace('/\s+/', ' ', $sql));
        $this->assertStringContainsString("WHERE status = 'processing'", $sql);
        preg_match("/started_at < '([^']+)'/", $sql, $m);
        $this->assertNotEmpty($m, 'Cutoff must be a prepared value.');
        $this->assertEqualsWithDelta($before - 300, strtotime($m[1]), 5);
    }

    public function test_reset_stuck_items_counts_every_reset_as_an_attempt_and_parks_third_strike_as_failed(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::reset_stuck_items(10);
        $sql = $this->callsOf('query')[0]['sql'];
        $this->assertStringContainsString('attempts = attempts + 1', $sql);
        $this->assertStringContainsString("IF(attempts + 1 >= 3, 'failed', 'pending')", $sql);
        $this->assertStringContainsString("WHERE status = 'processing'", $sql);
    }

    public static function negativeMinutesProvider(): array
    {
        return ['negative' => [-5], 'zero' => [0], 'huge negative' => [-100000]];
    }

    #[DataProvider('negativeMinutesProvider')]
    public function test_reset_stuck_items_clamps_non_positive_minutes_to_now(int $minutes): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::reset_stuck_items($minutes);
        preg_match("/started_at < '([^']+)'/", $this->callsOf('query')[0]['sql'], $m);
        $this->assertEqualsWithDelta(time(), strtotime($m[1]), 5);
    }

    public static function negativeDaysProvider(): array
    {
        return ['minus one' => [-1], 'minus thirty' => [-30], 'huge negative' => [-100000]];
    }

    #[DataProvider('negativeDaysProvider')]
    public function test_cleanup_clamps_negative_days_to_now_instead_of_throwing(int $days): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => 2;

        $this->assertSame(2, WC_Multi_Store_Queue_Table::cleanup($days));
        preg_match("/completed_at < '([^']+)'/", $this->callsOf('query')[0]['sql'], $m);
        $this->assertEqualsWithDelta(time(), strtotime($m[1]), 5);
    }

    public function test_reset_stuck_items_assigns_completed_at_before_attempts_so_condition_sees_old_attempts(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::reset_stuck_items(5);

        $sql = preg_replace('/\s+/', ' ', $this->callsOf('query')[0]['sql']);
        $posStatus = strpos($sql, "status = IF(attempts + 1 >= 3");
        $posCompleted = strpos($sql, "completed_at = IF(attempts + 1 >= 3");
        $posAttempts = strpos($sql, "attempts = attempts + 1");
        $this->assertNotFalse($posStatus);
        $this->assertLessThan($posCompleted, $posStatus);
        $this->assertLessThan($posAttempts, $posCompleted, 'MySQL evaluates SET left to right: completed_at must precede the attempts increment.');
        $this->assertStringContainsString("completed_at = IF(attempts + 1 >= 3, '2024-06-15 12:00:00', completed_at)", $sql, 'Non-parked rows keep their completed_at.');
    }

    public function test_parked_item_is_purgeable_by_cleanup(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::reset_stuck_items(5);
        WC_Multi_Store_Queue_Table::cleanup(7);

        $reset = preg_replace('/\s+/', ' ', $this->callsOf('query')[0]['sql']);
        $cleanup = $this->callsOf('query')[1]['sql'];
        // Parked rows are status 'failed' with a non-NULL completed_at: exactly what cleanup selects.
        $this->assertStringContainsString("'failed'", $reset);
        $this->assertMatchesRegularExpression("/completed_at = IF\\(attempts \\+ 1 >= 3, '[^']+', completed_at\\)/", $reset);
        $this->assertStringContainsString("status IN ('completed', 'failed')", $cleanup);
        $this->assertStringContainsString('completed_at <', $cleanup);
    }


    public function test_reset_stuck_items_zero_rows_returns_zero(): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => 0;
        $this->assertSame(0, WC_Multi_Store_Queue_Table::reset_stuck_items());
    }

    public function test_reset_stuck_items_propagates_db_error_without_logging_success(): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => false;
        $this->assertFalse(WC_Multi_Store_Queue_Table::reset_stuck_items(5));
    }

    public function test_cleanup_only_removes_completed_and_failed_rows_older_than_cutoff(): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => 4;
        $before = time();

        $this->assertSame(4, WC_Multi_Store_Queue_Table::cleanup(7));

        $sql = $this->callsOf('query')[0]['sql'];
        $this->assertStringContainsString("DELETE FROM wp_wc_mss_queue", $sql);
        $this->assertStringContainsString("status IN ('completed', 'failed')", $sql);
        $this->assertStringNotContainsString('pending', $sql);
        $this->assertStringNotContainsString('processing', $sql);
        preg_match("/completed_at < '([^']+)'/", $sql, $m);
        $this->assertEqualsWithDelta($before - 7 * 86400, strtotime($m[1]), 5);
    }

    public function test_cleanup_with_zero_days_uses_current_time_as_cutoff(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::cleanup(0);
        preg_match("/completed_at < '([^']+)'/", $this->callsOf('query')[0]['sql'], $m);
        $this->assertEqualsWithDelta(time(), strtotime($m[1]), 5);
    }

    // ── retry / clear statements ───────────────────────────────────

    public function test_retry_item_only_looks_at_failed_rows(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => null;

        $this->assertFalse(WC_Multi_Store_Queue_Table::retry_item(8));
        $this->assertSame("SELECT * FROM wp_wc_mss_queue WHERE id = 8 AND status = 'failed'", $this->callsOf('get_row')[0]['sql']);
        $this->assertSame([], $this->callsOf('update'));
    }

    public function test_retry_item_resets_every_lifecycle_column_and_marks_only_the_matching_dead_entry(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => ['id' => 8, 'product_id' => 3, 'store_url' => 'https://a.test'];

        $this->assertTrue(WC_Multi_Store_Queue_Table::retry_item(8));

        $u = $this->callsOf('update')[0];
        $this->assertSame([
            'status' => 'pending', 'attempts' => 0, 'last_error' => null,
            'started_at' => null, 'completed_at' => null, 'scheduled_at' => null,
        ], $u['data']);
        $this->assertSame(['id' => 8], $u['where']);
        $dlqSql = $this->callsOf('query')[0]['sql'];
        $this->assertStringContainsString("UPDATE wp_wc_mss_dead_letter_queue", $dlqSql);
        $this->assertStringContainsString("original_queue_id = 8 AND status = 'dead'", $dlqSql);
    }

    public function test_retry_failed_items_resets_state_and_dlq_when_rows_changed(): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn(string $sql) => str_contains($sql, 'SET status = \'pending\',') && str_contains($sql, 'attempts = 0') ? 2 : 1;

        $this->assertSame(2, WC_Multi_Store_Queue_Table::retry_failed_items());

        $sqls = array_column($this->callsOf('query'), 'sql');
        $this->assertCount(2, $sqls);
        $this->assertStringContainsString("WHERE status = 'failed'", $sqls[0]);
        $this->assertStringContainsString('attempts = 0', $sqls[0]);
        $this->assertStringContainsString('scheduled_at = NULL', $sqls[0]);
        $this->assertStringContainsString("SET status = 'retried'", $sqls[1]);
        $this->assertStringContainsString("WHERE status = 'dead'", $sqls[1]);
    }

    public function test_retry_failed_items_with_nothing_failed_leaves_dlq_alone(): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => 0;

        $this->assertSame(0, WC_Multi_Store_Queue_Table::retry_failed_items());
        $this->assertCount(1, $this->callsOf('query'));
    }

    public static function clearProvider(): array
    {
        return [
            'failed'    => ['clear_failed', "DELETE FROM wp_wc_mss_queue WHERE status = 'failed'"],
            'completed' => ['clear_completed', "DELETE FROM wp_wc_mss_queue WHERE status = 'completed'"],
            'pending'   => ['clear_pending', "DELETE FROM wp_wc_mss_queue WHERE status = 'pending'"],
        ];
    }

    #[DataProvider('clearProvider')]
    public function test_clear_methods_delete_only_their_own_status(string $method, string $expectedSql): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => 6;

        $this->assertSame(6, WC_Multi_Store_Queue_Table::$method());
        $this->assertSame([$expectedSql], array_column($this->callsOf('query'), 'sql'));
    }

    #[DataProvider('clearProvider')]
    public function test_clear_methods_on_empty_queue_return_zero(string $method, string $expectedSql): void
    {
        $this->fakeDb();
        $this->responders['query'] = static fn() => 0;
        $this->assertSame(0, WC_Multi_Store_Queue_Table::$method());
    }

    public function test_clear_pending_never_touches_processing_rows(): void
    {
        $this->fakeDb();
        WC_Multi_Store_Queue_Table::clear_pending();
        $this->assertStringNotContainsString('processing', $this->callsOf('query')[0]['sql']);
    }

    public function test_get_stats_on_empty_table_is_all_zero(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => null;
        $this->assertSame(
            ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0],
            WC_Multi_Store_Queue_Table::get_stats()
        );
    }

    public function test_get_stats_casts_null_sums_to_zero(): void
    {
        $this->fakeDb();
        $this->responders['get_row'] = static fn() => ['pending' => null, 'processing' => '2', 'completed' => null, 'failed' => '1', 'total' => '3'];
        $this->assertSame(
            ['pending' => 0, 'processing' => 2, 'completed' => 0, 'failed' => 1, 'total' => 3],
            WC_Multi_Store_Queue_Table::get_stats()
        );
    }
}
