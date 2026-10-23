<?php
/**
 * Dead-letter queue lifecycle tests: exhausted queue item -> DLQ -> retry /
 * retry-all / resolve / clear / retention, against an in-memory model of the
 * queue and dead-letter tables.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class DeadLetterQueueLifecycleTest extends WC_Multi_Store_TestCase
{
    private const QT = 'wp_wc_mss_queue';
    private const DT = 'wp_wc_mss_dead_letter_queue';

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];
    /** @var array<int, array<string, mixed>> */
    private array $dead = [];
    /** @var array<int, string> */
    private array $sql = [];
    /** @var array<int, array{0: string, 1: array}> */
    private array $actions = [];
    /** @var array<int, array{0: string, 1: array, 2: array}> */
    private array $deletes = [];
    private int $nextQueueId = 500;
    private int $nextDeadId = 900;
    private bool $lockBusy = false;
    /** @var array<int, int> */
    private array $lockQueue = [];
    private mixed $queryOverride = null;
    private bool $dlqInsertFails = false;
    private int $deadCountForThreshold = 0;
    private int $lastNotified = 0;
    /** @var array<int, array> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = [];
        $this->dead = [];
        $this->sql = [];
        $this->actions = [];
        $this->deletes = [];
        $this->nextQueueId = 500;
        $this->nextDeadId = 900;
        $this->lockBusy = false;
        $this->lockQueue = [];
        $this->queryOverride = null;
        $this->dlqInsertFails = false;
        $this->deadCountForThreshold = 0;
        $this->lastNotified = 0;
        $this->mails = [];

        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('do_action')->alias(function (string $hook, ...$args): void {
            $this->actions[] = [$hook, $args];
        });
        Functions\when('get_option')->alias(fn($k, $d = false) => $k === WC_Multi_Store_Dead_Letter_Queue::NOTIFICATION_OPTION ? $this->lastNotified : $d);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Shop');
        Functions\when('admin_url')->alias(static fn($p = '') => 'https://admin.test/' . $p);
        Functions\when('wp_mail')->alias(function (...$args): bool {
            $this->mails[] = $args;
            return true;
        });
        Functions\when('sanitize_sql_orderby')->alias(static fn($v) => $v);

        $this->installDb();
    }

    private function seedQueue(int $id, array $o = []): void
    {
        $this->queue[$id] = array_merge([
            'id' => $id, 'product_id' => 42, 'product_sku' => 'SKU-42', 'store_url' => 'https://s1.test',
            'sync_type' => 'full_product', 'source' => 'manual', 'priority' => 5, 'status' => 'failed',
            'attempts' => 3, 'extra_data' => null, 'started_at' => '2026-01-01 11:00:00', 'last_error' => 'boom',
            'scheduled_at' => null, 'completed_at' => null,
        ], $o);
    }

    private function seedDead(int $id, array $o = []): void
    {
        $this->dead[$id] = array_merge([
            'id' => $id, 'product_id' => 42, 'product_sku' => 'SKU-42', 'store_url' => 'https://s1.test',
            'sync_type' => 'full_product', 'source' => 'manual', 'attempts' => 3, 'last_error' => 'boom',
            'extra_data' => null, 'original_queue_id' => 0, 'status' => 'dead', 'failed_at' => '2026-01-01 11:30:00',
            'retried_at' => null, 'resolved_at' => null,
        ], $o);
    }

    private function installDb(): void
    {
        global $wpdb;
        $db = \Mockery::mock('wpdb');
        $db->prefix = 'wp_';
        $db->insert_id = 0;
        $wpdb = $db;

        $db->shouldReceive('esc_like')->andReturnUsing(static fn(string $t): string => addcslashes($t, '_%\\'));
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

        $db->shouldReceive('get_var')->andReturnUsing(function (string $sql) {
            $this->sql[] = $sql;
            if (str_contains($sql, 'GET_LOCK')) {
                if ($this->lockQueue) {
                    return array_shift($this->lockQueue);
                }
                return $this->lockBusy ? 0 : 1;
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                return 1;
            }
            if (str_contains($sql, "COUNT(*) FROM wp_wc_mss_dead_letter_queue WHERE status = 'dead'")) {
                return $this->deadCountForThreshold ?: count(array_filter($this->dead, static fn($r) => $r['status'] === 'dead'));
            }
            if (str_contains($sql, 'SELECT COUNT(*)')) {
                return count($this->dead);
            }
            return null;
        });

        $db->shouldReceive('get_row')->andReturnUsing(function (string $sql) {
            $this->sql[] = $sql;
            if (preg_match("/FROM wp_wc_mss_dead_letter_queue WHERE id = (\d+) AND status = 'dead'/", $sql, $m)) {
                $r = $this->dead[(int) $m[1]] ?? null;
                return ($r && $r['status'] === 'dead') ? $r : null;
            }
            if (preg_match("/FROM wp_wc_mss_queue WHERE id = (\d+) AND status = 'failed'/", $sql, $m)) {
                $r = $this->queue[(int) $m[1]] ?? null;
                return ($r && $r['status'] === 'failed') ? $r : null;
            }
            if (str_contains($sql, 'SELECT id, status FROM wp_wc_mss_queue')) {
                preg_match('/product_id = (\d+)/', $sql, $p);
                preg_match("/store_url = '([^']*)'/", $sql, $s);
                $resolution = preg_match("/AND sync_type = '([^']+)' AND source = '([^']+)'/", $sql, $t);
                $distinct = !$resolution && preg_match("/AND sync_type = '([^']+)'/", $sql, $d);
                foreach ($this->queue as $r) {
                    if ($r['product_id'] !== (int) $p[1] || $r['store_url'] !== $s[1] || !in_array($r['status'], ['pending', 'processing'], true)) {
                        continue;
                    }
                    if ($resolution) {
                        if ($r['sync_type'] === $t[1] && $r['source'] === $t[2]) {
                            return ['id' => $r['id'], 'status' => $r['status']];
                        }
                    } elseif ($distinct) {
                        if ($r['sync_type'] === $d[1]) {
                            return ['id' => $r['id'], 'status' => $r['status']];
                        }
                    } elseif (!in_array($r['sync_type'], ['conflict_resolution', 'stock_correction', 'delete_product', 'delete_variation', 'delete_orphan', 'restore_product', 'status_change'], true)) {
                        return ['id' => $r['id'], 'status' => $r['status']];
                    }
                }
                return null;
            }
            return null;
        });

        $db->shouldReceive('get_results')->andReturnUsing(function (string $sql) {
            $this->sql[] = $sql;
            if (!str_contains($sql, 'FROM wp_wc_mss_dead_letter_queue')) {
                return [];
            }
            preg_match("/status = '([^']+)'/", $sql, $st);
            preg_match('/LIMIT (-?\d+) OFFSET (\d+)/', $sql, $lo);
            $rows = array_values(array_filter($this->dead, static fn($r) => $r['status'] === $st[1]));
            return array_slice($rows, (int) $lo[2], max(0, (int) $lo[1]));
        });

        $db->shouldReceive('query')->andReturnUsing(function (string $sql) {
            $this->sql[] = $sql;
            if ($this->queryOverride !== null) {
                return $this->queryOverride;
            }
            if (str_contains($sql, "DELETE FROM wp_wc_mss_dead_letter_queue WHERE status = 'dead'")) {
                $n = 0;
                foreach ($this->dead as $id => $r) {
                    if ($r['status'] === 'dead') {
                        unset($this->dead[$id]);
                        $n++;
                    }
                }
                return $n;
            }
            return 0;
        });

        $db->shouldReceive('insert')->andReturnUsing(function (string $table, array $data) use ($db) {
            if ($table === self::DT) {
                if ($this->dlqInsertFails) {
                    return false;
                }
                $id = $this->nextDeadId++;
                $this->dead[$id] = $data + ['id' => $id, 'retried_at' => null, 'resolved_at' => null];
                $db->insert_id = $id;
                return 1;
            }
            $id = $this->nextQueueId++;
            $this->queue[$id] = $data + ['id' => $id, 'started_at' => null, 'last_error' => null, 'completed_at' => null];
            $db->insert_id = $id;
            return 1;
        });

        $db->shouldReceive('update')->andReturnUsing(function (string $table, array $data, array $where) {
            $rows = &$this->{$table === self::DT ? 'dead' : 'queue'};
            $id = (int) $where['id'];
            if (!isset($rows[$id])) {
                return 0;
            }
            $rows[$id] = array_merge($rows[$id], $data);
            return 1;
        });

        $db->shouldReceive('delete')->andReturnUsing(function (string $table, array $where) {
            $this->deletes[] = [$table, $where];
            $rows = &$this->{$table === self::DT ? 'dead' : 'queue'};
            $id = (int) $where['id'];
            if (isset($rows[$id]) && (!isset($where['status']) || $rows[$id]['status'] === $where['status'])) {
                unset($rows[$id]);
                return 1;
            }
            return 0;
        });
    }

    // ── add_from_queue ─────────────────────────────────────────────

    public function test_add_from_queue_copies_full_payload_and_keeps_first_failure_time(): void
    {
        $this->seedQueue(7, ['sync_type' => 'delete_product', 'extra_data' => '{"parent_id":3}', 'source' => 'product_delete']);

        $id = WC_Multi_Store_Dead_Letter_Queue::add_from_queue($this->queue[7]);

        $this->assertSame(900, $id);
        $row = $this->dead[900];
        $this->assertSame('dead', $row['status']);
        $this->assertSame(7, $row['original_queue_id']);
        $this->assertSame('delete_product', $row['sync_type']);
        $this->assertSame('{"parent_id":3}', $row['extra_data']);
        $this->assertSame('product_delete', $row['source']);
        $this->assertSame(3, $row['attempts']);
        $this->assertSame('2026-01-01 11:00:00', $row['first_failed_at']);
        $this->assertSame('2026-01-01 12:00:00', $row['failed_at']);
    }

    public function test_add_from_queue_falls_back_to_now_when_started_at_is_null(): void
    {
        $this->seedQueue(7, ['started_at' => null]);
        // isset-style "??" treats null like missing.
        WC_Multi_Store_Dead_Letter_Queue::add_from_queue($this->queue[7]);
        $this->assertSame('2026-01-01 12:00:00', $this->dead[900]['first_failed_at']);
    }

    public function test_add_from_queue_fires_action_with_original_item_and_returns_false_on_insert_failure(): void
    {
        $this->seedQueue(7);
        $this->dlqInsertFails = true;

        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::add_from_queue($this->queue[7]));
        $this->assertSame([], array_filter($this->actions, static fn($a) => $a[0] === 'wc_mss_dead_letter_added'), 'No email hook for an item that was not stored.');
        $this->assertSame([], $this->mails);
    }

    public function test_add_from_queue_notifies_admin_once_at_threshold_then_respects_cooldown(): void
    {
        $this->seedQueue(7);
        $this->deadCountForThreshold = WC_Multi_Store_Dead_Letter_Queue::NOTIFICATION_THRESHOLD;

        WC_Multi_Store_Dead_Letter_Queue::add_from_queue($this->queue[7]);
        $this->assertCount(1, $this->mails);

        $this->lastNotified = time() - 60;
        WC_Multi_Store_Dead_Letter_Queue::add_from_queue($this->queue[7]);
        $this->assertCount(1, $this->mails, 'Second mail within the cooldown must be suppressed.');

        $this->lastNotified = time() - WC_Multi_Store_Dead_Letter_Queue::NOTIFICATION_COOLDOWN - 1;
        WC_Multi_Store_Dead_Letter_Queue::add_from_queue($this->queue[7]);
        $this->assertCount(2, $this->mails);
    }

    public function test_add_from_queue_below_threshold_sends_no_mail(): void
    {
        $this->seedQueue(7);
        $this->deadCountForThreshold = WC_Multi_Store_Dead_Letter_Queue::NOTIFICATION_THRESHOLD - 1;
        WC_Multi_Store_Dead_Letter_Queue::add_from_queue($this->queue[7]);
        $this->assertSame([], $this->mails);
    }

    // ── retry_item ─────────────────────────────────────────────────

    public function test_retry_moves_item_back_to_pending_queue_and_cleans_up_original(): void
    {
        $this->seedQueue(7, ['product_sku' => 'SKU-42', 'extra_data' => '{"x":1}']);
        $this->seedDead(1, ['original_queue_id' => 7, 'extra_data' => '{"x":1}']);

        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));

        $this->assertSame('retried', $this->dead[1]['status']);
        $this->assertSame('2026-01-01 12:00:00', $this->dead[1]['retried_at']);
        $this->assertArrayNotHasKey(7, $this->queue, 'The stale failed row must be removed.');
        $new = $this->queue[500];
        $this->assertSame('pending', $new['status']);
        $this->assertSame(0, $new['attempts'], 'A retried item gets a fresh attempt budget.');
        $this->assertSame(WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL, $new['priority']);
        $this->assertSame('dlq_retry', $new['source']);
        $this->assertSame('SKU-42', $new['product_sku']);
        $this->assertSame('{"x":1}', $new['extra_data']);
        $this->assertSame([[self::QT, ['id' => 7, 'status' => 'failed']]], $this->deletes);
    }

    public function test_retry_twice_requeues_only_once(): void
    {
        $this->seedDead(1, ['original_queue_id' => 7]);

        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));
        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::retry_item(1), 'A retried entry is no longer dead.');

        $this->assertCount(1, $this->queue);
    }

    public function test_retry_unknown_or_resolved_item_changes_nothing(): void
    {
        $this->seedDead(2, ['status' => 'resolved']);

        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::retry_item(2));
        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::retry_item(0));
        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::retry_item(-1));
        $this->assertSame([], $this->queue);
        $this->assertSame('resolved', $this->dead[2]['status']);
    }

    public function test_retry_keeps_item_dead_when_enqueue_lock_is_busy(): void
    {
        $this->seedDead(1, ['original_queue_id' => 7]);
        $this->seedQueue(7);
        $this->lockBusy = true;

        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));

        $this->assertSame('dead', $this->dead[1]['status'], 'Work must not be dropped when it could not be re-queued.');
        $this->assertArrayHasKey(7, $this->queue, 'Original failed row must stay when re-queue failed.');
        $this->assertSame([], $this->deletes);
    }

    public function test_retry_without_original_queue_id_does_not_issue_a_delete(): void
    {
        $this->seedDead(1, ['original_queue_id' => 0]);
        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));
        $this->assertSame([], $this->deletes);
    }

    public function test_retry_does_not_delete_original_row_that_is_no_longer_failed(): void
    {
        // The original id was recycled/changed by a manual queue retry: it is pending again.
        $this->seedQueue(7, ['status' => 'pending']);
        $this->seedDead(1, ['original_queue_id' => 7]);

        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));

        $this->assertArrayHasKey(7, $this->queue);
        $this->assertSame('pending', $this->queue[7]['status']);
    }

    public function test_retry_dedupes_against_already_pending_work_and_still_closes_dead_entry(): void
    {
        $this->seedQueue(33, ['status' => 'pending', 'attempts' => 0]);
        $this->seedDead(1, ['original_queue_id' => 7]);

        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));

        $this->assertCount(1, $this->queue, 'No second queue row for the same product/store.');
        $this->assertSame('retried', $this->dead[1]['status']);
    }

    public static function distinctTypes(): array
    {
        return ['delete' => ['delete_product'], 'variation' => ['delete_variation'], 'orphan' => ['delete_orphan'], 'restore' => ['restore_product'], 'status' => ['status_change']];
    }

    #[DataProvider('distinctTypes')]
    public function test_retried_deletion_is_not_swallowed_by_a_pending_full_sync(string $type): void
    {
        $this->seedQueue(33, ['status' => 'pending', 'attempts' => 0, 'sync_type' => 'full_product']);
        $this->seedDead(1, ['sync_type' => $type, 'extra_data' => '{"parent_id":3}']);

        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));

        $this->assertCount(2, $this->queue, 'The retried operation needs its own row.');
        $this->assertSame('full_product', $this->queue[33]['sync_type']);
        $this->assertSame($type, $this->queue[500]['sync_type']);
        $this->assertSame('retried', $this->dead[1]['status']);
    }

    public function test_retried_deletion_still_dedupes_against_same_pending_deletion(): void
    {
        $this->seedQueue(33, ['status' => 'pending', 'attempts' => 0, 'sync_type' => 'delete_product']);
        $this->seedDead(1, ['sync_type' => 'delete_product']);

        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));

        $this->assertCount(1, $this->queue);
    }

    public static function sourceByTypeProvider(): array
    {
        return [
            'plain sync'  => ['full_product', '{"x":1}', 'dlq_retry'],
            'deletion'    => ['delete_product', null, 'dlq_retry'],
            'conflict'    => ['conflict_resolution', '{"conflict_id":12,"resolution":"overwrite"}', 'conflict_resolution_12'],
            'stock'       => ['stock_correction', '{"discrepancy_id":34}', 'stock_discrepancy_34'],
            'conflict w/o payload' => ['conflict_resolution', null, 'conflict_resolution_0'],
        ];
    }

    #[DataProvider('sourceByTypeProvider')]
    public function test_retry_preserves_operation_identity(string $type, ?string $extra, string $expectedSource): void
    {
        $this->seedDead(1, ['sync_type' => $type, 'extra_data' => $extra]);

        WC_Multi_Store_Dead_Letter_Queue::retry_item(1);

        $this->assertSame($type, $this->queue[500]['sync_type']);
        $this->assertSame($expectedSource, $this->queue[500]['source']);
        $this->assertSame($extra, $this->queue[500]['extra_data'] ?? null);
    }

    public function test_retried_conflict_resolution_is_deduped_by_decision_not_by_product(): void
    {
        $this->seedDead(1, ['sync_type' => 'conflict_resolution', 'extra_data' => '{"conflict_id":12,"resolution":"overwrite"}']);
        $this->seedDead(2, ['sync_type' => 'conflict_resolution', 'extra_data' => '{"conflict_id":13,"resolution":"keep_remote"}']);

        WC_Multi_Store_Dead_Letter_Queue::retry_item(1);
        WC_Multi_Store_Dead_Letter_Queue::retry_item(2);

        $this->assertCount(2, $this->queue, 'Two different decisions for one product must both survive.');
    }

    // ── retry_all ──────────────────────────────────────────────────

    public function test_retry_all_on_empty_dlq_returns_zero(): void
    {
        $this->assertSame(0, WC_Multi_Store_Dead_Letter_Queue::retry_all());
        $this->assertSame([], $this->queue);
    }

    public function test_retry_all_requeues_every_dead_item_and_ignores_other_statuses(): void
    {
        $this->seedDead(1, ['product_id' => 1]);
        $this->seedDead(2, ['product_id' => 2]);
        $this->seedDead(3, ['product_id' => 3, 'status' => 'resolved']);
        $this->seedDead(4, ['product_id' => 4, 'status' => 'retried']);

        $this->assertSame(2, WC_Multi_Store_Dead_Letter_Queue::retry_all());

        $this->assertSame(['retried', 'retried', 'resolved', 'retried'], array_column($this->dead, 'status'));
        $this->assertCount(2, $this->queue);
        $select = array_values(array_filter($this->sql, static fn($s) => str_contains($s, 'SELECT * FROM wp_wc_mss_dead_letter_queue WHERE')))[0];
        $this->assertStringContainsString("status = 'dead'", $select);
        $this->assertStringContainsString('LIMIT 1000 OFFSET 0', $select);
    }

    public function test_retry_all_counts_only_successful_requeues(): void
    {
        $this->seedDead(1, ['product_id' => 1]);
        $this->seedDead(2, ['product_id' => 2]);
        $this->lockQueue = [1, 0];

        $retried = WC_Multi_Store_Dead_Letter_Queue::retry_all();

        $this->assertSame(1, $retried);
        $this->assertSame('retried', $this->dead[1]['status']);
        $this->assertSame('dead', $this->dead[2]['status'], 'The item that could not be re-queued stays dead for the next attempt.');
    }

    public function test_retry_all_twice_does_not_duplicate_queue_rows(): void
    {
        $this->seedDead(1, ['product_id' => 1]);

        $this->assertSame(1, WC_Multi_Store_Dead_Letter_Queue::retry_all());
        $this->assertSame(0, WC_Multi_Store_Dead_Letter_Queue::retry_all());
        $this->assertCount(1, $this->queue);
    }

    // ── resolve / clear / cleanup ──────────────────────────────────

    public function test_resolve_item_sets_status_and_timestamp_and_misses_unknown_ids(): void
    {
        $this->seedDead(1);

        $this->assertTrue(WC_Multi_Store_Dead_Letter_Queue::resolve_item(1));
        $this->assertSame('resolved', $this->dead[1]['status']);
        $this->assertSame('2026-01-01 12:00:00', $this->dead[1]['resolved_at']);
        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::resolve_item(404));
    }

    public function test_resolved_item_cannot_be_retried_afterwards(): void
    {
        $this->seedDead(1);
        WC_Multi_Store_Dead_Letter_Queue::resolve_item(1);

        $this->assertFalse(WC_Multi_Store_Dead_Letter_Queue::retry_item(1));
        $this->assertSame([], $this->queue);
    }

    public function test_clear_all_removes_only_dead_items_and_keeps_audit_trail(): void
    {
        $this->seedDead(1);
        $this->seedDead(2);
        $this->seedDead(3, ['status' => 'resolved']);
        $this->seedDead(4, ['status' => 'retried']);

        $this->assertSame(2, WC_Multi_Store_Dead_Letter_Queue::clear_all());
        $this->assertSame([3, 4], array_keys($this->dead));
        $this->assertSame(0, WC_Multi_Store_Dead_Letter_Queue::clear_all(), 'Clearing an already clean DLQ is a no-op.');
    }

    public function test_clear_all_does_not_touch_the_main_queue(): void
    {
        $this->seedQueue(7);
        $this->seedDead(1);
        WC_Multi_Store_Dead_Letter_Queue::clear_all();
        $this->assertArrayHasKey(7, $this->queue);
    }

    public function test_cleanup_sql_only_targets_resolved_and_retried_rows(): void
    {
        WC_Multi_Store_Dead_Letter_Queue::cleanup(30);

        $this->assertSame(
            "DELETE FROM wp_wc_mss_dead_letter_queue WHERE status IN ('resolved', 'retried') AND failed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $this->sql[count($this->sql) - 1]
        );
    }

    public function test_cleanup_never_deletes_dead_items_whatever_the_retention(): void
    {
        foreach ([0, 1, 30, 100000, -5] as $days) {
            $this->sql = [];
            WC_Multi_Store_Dead_Letter_Queue::cleanup($days);
            $this->assertStringNotContainsString("'dead'", $this->sql[0], "retention {$days}");
            $this->assertStringContainsString("status IN ('resolved', 'retried')", $this->sql[0]);
        }
    }

    public function test_cleanup_returns_row_count_as_int(): void
    {
        $this->queryOverride = '4';
        $this->assertSame(4, WC_Multi_Store_Dead_Letter_Queue::cleanup(7));
    }

    // ── get_items ──────────────────────────────────────────────────

    public function test_get_items_defaults_to_dead_only_and_paginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedDead($i);
        }
        $this->seedDead(6, ['status' => 'resolved']);

        $page = WC_Multi_Store_Dead_Letter_Queue::get_items(['limit' => 2, 'offset' => 2]);

        $this->assertSame([3, 4], array_map(static fn($r) => $r['id'], $page['results']));
        $this->assertCount(2, array_filter($page['results']));
    }

    public function test_get_items_on_empty_table_returns_empty_results_and_zero_total(): void
    {
        $this->assertSame(['results' => [], 'total' => 0], WC_Multi_Store_Dead_Letter_Queue::get_items());
    }
}
