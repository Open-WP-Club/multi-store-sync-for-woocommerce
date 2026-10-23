<?php
/**
 * End-to-end queue pipeline tests: WC_Multi_Store_Queue_Manager::process_queue()
 * driven against a small in-memory model of the queue and dead-letter tables.
 *
 * The fake $wpdb applies the SQL that the real Queue_Table methods emit
 * (attempt increments, pending/due selection, stuck-row reset), so retries,
 * back-off, dead-lettering and crash recovery are exercised through the real
 * production code paths rather than being asserted call by call.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

class QueueManagerRetryAndDlqTest extends WC_Multi_Store_TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /** @var array<int, array<string, mixed>> */
    private array $dlq = [];

    /** @var array<int, string> Raw SQL / op log, in order. */
    private array $log = [];

    /** @var array<int, array<string, mixed>> */
    private array $updates = [];

    private int $lockResult = 1;
    private int $clockOffset = 0;
    private int $releaseCount = 0;
    private bool $statsThrows = false;
    private bool $selectFails = false;
    /** @var callable|null */
    private $beforeClaim = null;
    /** @var array<int, array{0: string, 1: array}> */
    private array $actions = [];
    /** @var array<string, array<string, mixed>> */
    private array $stores = [];
    /** @var callable|null */
    private $discrepancyRow = null;
    private object $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rows = [];
        $this->dlq = [];
        $this->log = [];
        $this->updates = [];
        $this->lockResult = 1;
        $this->clockOffset = 0;
        $this->releaseCount = 0;
        $this->statsThrows = false;
        $this->selectFails = false;
        $this->beforeClaim = null;
        $this->actions = [];
        $this->discrepancyRow = null;
        $this->stores = [
            'https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'https://store2.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
        ];

        WC_Multi_Store_Settings::clear_static_cache();
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            return match ($option) {
                'wc_multi_store_sync_settings' => ['enabled' => true],
                'wc_multi_store_sync_stores' => $this->stores,
                'wc_multi_store_sync_scheduled' => ['force_full_sync' => true, 'batch_size_offpeak' => 17, 'batch_size_peak' => 17],
                default => $default,
            };
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('current_time')->alias(fn() => date('Y-m-d H:i:s', time() + $this->clockOffset));
        Functions\when('current_datetime')->alias(fn() => new \DateTimeImmutable('@' . (time() + $this->clockOffset)));
        Functions\when('wp_json_encode')->alias(static fn($d) => json_encode($d));
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('sanitize_sql_orderby')->alias(static fn($v) => $v);
        Functions\when('untrailingslashit')->alias(static fn($v) => rtrim((string) $v, '/'));
        Functions\when('do_action')->alias(function (string $hook, ...$args): void {
            $this->actions[] = [$hook, $args];
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_sku')->andReturn('SKU');
        $product->shouldReceive('is_type')->andReturn(false);
        $product->shouldReceive('get_id')->andReturn(1);
        Functions\when('wc_get_product')->justReturn($product);

        $this->engine = \Mockery::mock('WC_Multi_Store_Sync_Engine');
        WC_Multi_Store_Sync::instance()->sync_engine = $this->engine;
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();

        $this->installDb();
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
        parent::tearDown();
    }

    private function seed(int $id, array $overrides = []): void
    {
        $this->rows[$id] = array_merge([
            'id' => $id,
            'product_id' => 100 + $id,
            'product_sku' => 'SKU-' . $id,
            'store_url' => 'https://store1.com',
            'sync_type' => 'full_product',
            'source' => 'manual',
            'priority' => 5,
            'status' => 'pending',
            'attempts' => 0,
            'extra_data' => null,
            'started_at' => null,
            'scheduled_at' => null,
            'last_error' => null,
            'completed_at' => null,
        ], $overrides);
    }

    private function statuses(): array
    {
        return array_map(static fn(array $r): string => $r['status'], $this->rows);
    }

    private function installDb(): void
    {
        global $wpdb;
        $db = \Mockery::mock('wpdb');
        $db->prefix = 'wp_';
        $db->postmeta = 'wp_postmeta';
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

        $db->shouldReceive('get_var')->andReturnUsing(function (string $sql) {
            $this->log[] = $sql;
            if (str_contains($sql, 'GET_LOCK')) {
                return $this->lockResult;
            }
            if (str_contains($sql, "RELEASE_LOCK('wc_mss_queue_processor')")) {
                $this->releaseCount++;
                return 1;
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                return 1;
            }
            if (preg_match('/SELECT attempts FROM wp_wc_mss_queue WHERE id = (\d+)/', $sql, $m)) {
                return $this->rows[(int) $m[1]]['attempts'] ?? null;
            }
            return 0;
        });

        $db->shouldReceive('get_row')->andReturnUsing(function (string $sql) {
            $this->log[] = $sql;
            if (str_contains($sql, 'SUM(CASE')) {
                if ($this->statsThrows) {
                    throw new \RuntimeException('stats down');
                }
                $counts = array_count_values($this->statuses());
                return [
                    'pending' => (string) ($counts['pending'] ?? 0),
                    'processing' => (string) ($counts['processing'] ?? 0),
                    'completed' => (string) ($counts['completed'] ?? 0),
                    'failed' => (string) ($counts['failed'] ?? 0),
                    'total' => (string) count($this->rows),
                ];
            }
            if (preg_match('/FROM wp_wc_mss_queue WHERE id = (\d+)/', $sql, $m)) {
                return $this->rows[(int) $m[1]] ?? null;
            }
            if (str_contains($sql, 'stock_discrepancies')) {
                return $this->discrepancyRow ? ($this->discrepancyRow)($sql) : null;
            }
            return null;
        });

        $db->shouldReceive('get_results')->andReturnUsing(function (string $sql) {
            $this->log[] = $sql;
            if (!str_contains($sql, 'ORDER BY priority ASC, store_url ASC, created_at ASC')) {
                return [];
            }
            if ($this->selectFails) {
                return null;
            }
            preg_match('/LIMIT (-?\d+)/', $sql, $lim);
            preg_match("/scheduled_at <= '([^']+)'/", $sql, $now);
            $due = array_filter($this->rows, static fn(array $r): bool =>
                $r['status'] === 'pending'
                && ($r['scheduled_at'] === null || $r['scheduled_at'] <= $now[1])
                && (int) $r['attempts'] < 3);
            uasort($due, static fn(array $a, array $b): int =>
                [$a['priority'], $a['store_url'], $a['id']] <=> [$b['priority'], $b['store_url'], $b['id']]);
            return array_slice(array_values($due), 0, max(0, (int) $lim[1]));
        });

        $db->shouldReceive('query')->andReturnUsing(function (string $sql) {
            $this->log[] = $sql;
            if (str_contains($sql, "RELEASE_LOCK('wc_mss_queue_processor')")) {
                $this->releaseCount++;
                return 1;
            }
            if (preg_match('/SET attempts = attempts \+ 1 WHERE id = (\d+)/', $sql, $m)) {
                if (isset($this->rows[(int) $m[1]])) {
                    $this->rows[(int) $m[1]]['attempts']++;
                    return 1;
                }
                return 0;
            }
            if (str_contains((string) preg_replace('/\s+/', ' ', $sql), "SET status = IF(attempts + 1 >= 3, 'failed', 'pending'), completed_at = IF(attempts + 1 >= 3, '")) {
                preg_match("/started_at < '([^']+)'/", $sql, $m);
                $n = 0;
                foreach ($this->rows as &$r) {
                    if ($r['status'] === 'processing' && $r['started_at'] < $m[1]) {
                        if ($r['attempts'] + 1 >= 3) {
                            $r['completed_at'] = date('Y-m-d H:i:s', time() + $this->clockOffset);
                        }
                        $r['status'] = $r['attempts'] + 1 >= 3 ? 'failed' : 'pending';
                        $r['attempts']++;
                        $r['started_at'] = null;
                        $n++;
                    }
                }
                return $n;
            }
            return 1;
        });

        $db->shouldReceive('insert')->andReturnUsing(function (string $table, array $data) {
            if ($table === 'wp_wc_mss_dead_letter_queue') {
                $this->dlq[] = $data;
            }
            return 1;
        });

        $db->shouldReceive('update')->andReturnUsing(function (string $table, array $data, array $where) {
            $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
            if ($table === 'wp_wc_mss_queue') {
                $id = (int) $where['id'];
                if (($data['status'] ?? '') === 'processing' && $this->beforeClaim) {
                    ($this->beforeClaim)($id);
                }
                if (!isset($this->rows[$id]) || (isset($where['status']) && $this->rows[$id]['status'] !== $where['status'])) {
                    return 0;
                }
                $this->rows[$id] = array_merge($this->rows[$id], $data);
            }
            return 1;
        });
        $db->shouldReceive('delete')->andReturn(1);
    }

    /** Helper: engine responds with the same result to every call. */
    private function engineAlwaysReturns(array|null $result, int|null $times = null): void
    {
        $exp = $this->engine->shouldReceive('sync_product_to_store')->andReturn($result);
        if ($times !== null) {
            $exp->times($times);
        }
    }

    // ── retries, back-off, dead-lettering ──────────────────────────

    public function test_full_retry_lifecycle_ends_in_dlq_after_exactly_three_attempts(): void
    {
        $this->seed(1);
        $this->engineAlwaysReturns(['success' => false, 'message' => 'Remote 503'], 3);

        $first = WC_MSS()->queue_manager->process_queue(10);
        $this->assertSame(1, $first['errors']);
        $this->assertSame('pending', $this->rows[1]['status'], 'First failure must keep the item retryable.');
        $this->assertSame(1, $this->rows[1]['attempts']);
        $this->assertSame('Remote 503', $this->rows[1]['last_error']);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $this->rows[1]['scheduled_at']);
        $this->assertSame([], $this->dlq);

        // Not due yet: nothing is picked up and the engine is not called again.
        $idle = WC_MSS()->queue_manager->process_queue(10);
        $this->assertSame('empty', $idle['status']);

        $this->clockOffset = 3 * 3600;
        WC_MSS()->queue_manager->process_queue(10);
        $this->assertSame('pending', $this->rows[1]['status']);
        $this->assertSame(2, $this->rows[1]['attempts']);
        $this->assertSame([], $this->dlq, 'Second failure must not dead-letter.');

        $this->clockOffset = 6 * 3600;
        $third = WC_MSS()->queue_manager->process_queue(10);
        $this->assertSame(1, $third['errors']);
        $this->assertSame('failed', $this->rows[1]['status']);
        $this->assertSame(3, $this->rows[1]['attempts']);
        $this->assertCount(1, $this->dlq, 'Exactly one DLQ entry for an exhausted item.');
        $this->assertSame(1, $this->dlq[0]['original_queue_id']);
        $this->assertSame('Remote 503', $this->dlq[0]['last_error']);
        $this->assertSame(3, $this->dlq[0]['attempts']);
        $this->assertSame('dead', $this->dlq[0]['status']);
        $this->assertSame('SKU-1', $this->dlq[0]['product_sku']);

        // A fourth pass finds nothing: the engine mock enforces times(3).
        $this->clockOffset = 12 * 3600;
        $this->assertSame('empty', WC_MSS()->queue_manager->process_queue(10)['status']);
        $this->assertCount(1, $this->dlq);
    }

    public function test_item_with_two_prior_attempts_is_dead_lettered_on_next_failure(): void
    {
        $this->seed(1, ['attempts' => 2]);
        $this->engineAlwaysReturns(['success' => false, 'message' => 'Still broken']);

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('failed', $this->rows[1]['status']);
        $this->assertCount(1, $this->dlq);
    }

    public function test_item_already_at_attempt_limit_is_never_picked_up(): void
    {
        $this->seed(1, ['attempts' => 3, 'status' => 'pending']);
        $this->engine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('empty', $result['status']);
        $this->assertSame('pending', $this->rows[1]['status'], 'Orphaned row stays untouched (not silently dropped).');
    }

    public static function noRetryMessages(): array
    {
        return [
            'missing on remote' => ['Product not found on remote store'],
            'no sku'            => ['Product has no SKU'],
            'embedded phrase'   => ['Error: Product has no SKU (id 4)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noRetryMessages')]
    public function test_non_retryable_error_is_dead_lettered_on_first_attempt_without_tripping_circuit(string $message): void
    {
        $this->seed(1);
        $this->engineAlwaysReturns(['success' => false, 'message' => $message], 1);

        $circuitWrites = 0;
        Functions\when('set_transient')->alias(function ($key) use (&$circuitWrites) {
            if (str_starts_with((string) $key, 'wc_mss_cb_')) {
                $circuitWrites++;
            }
            return true;
        });

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('failed', $this->rows[1]['status']);
        $this->assertSame(1, $this->rows[1]['attempts']);
        $this->assertSame($message, $this->rows[1]['last_error']);
        $this->assertCount(1, $this->dlq);
        $this->assertSame(0, $circuitWrites, 'Permanent errors must not count toward the circuit breaker.');
    }

    public function test_retryable_failure_records_circuit_failure_and_sends_notification(): void
    {
        $this->seed(1);
        $this->engineAlwaysReturns(['success' => false, 'message' => 'timeout']);
        $circuitWrites = [];
        Functions\when('set_transient')->alias(function ($key, $value) use (&$circuitWrites) {
            if (str_starts_with((string) $key, 'wc_mss_cb_')) {
                $circuitWrites[] = $value;
            }
            return true;
        });

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertCount(1, $circuitWrites);
        $this->assertSame(1, $circuitWrites[0]['consecutive_errors']);
        $failed = array_values(array_filter($this->actions, static fn($a) => $a[0] === 'wc_mss_sync_failed'));
        $this->assertCount(1, $failed);
        $this->assertSame([101, 'https://store1.com', 'timeout'], $failed[0][1]);
    }

    public function test_success_resets_circuit_and_completes_item(): void
    {
        $this->seed(1);
        $this->engineAlwaysReturns(['success' => true]);
        $circuitWrites = [];
        Functions\when('set_transient')->alias(function ($key, $value) use (&$circuitWrites) {
            if (str_starts_with((string) $key, 'wc_mss_cb_')) {
                $circuitWrites[] = $value;
            }
            return true;
        });

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('completed', $this->rows[1]['status']);
        $this->assertSame(0, $this->rows[1]['attempts'], 'A successful first run must not consume an attempt.');
        $this->assertSame(1, $result['success']);
        $this->assertSame(0, $circuitWrites[0]['consecutive_errors']);
        $this->assertSame([], $this->dlq);
    }

    public static function malformedResults(): array
    {
        return [
            'empty array'           => [[]],
            'success false'         => [['success' => false]],
            'success null'          => [['success' => null]],
            'success zero'          => [['success' => 0]],
            'message only'          => [['message' => 'oops']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedResults')]
    public function test_anything_but_explicit_success_counts_as_failure_never_as_completed(array $engineResult): void
    {
        $this->seed(1);
        $this->engineAlwaysReturns($engineResult);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, $result['success']);
        $this->assertNotSame('completed', $this->rows[1]['status']);
        $this->assertSame('pending', $this->rows[1]['status']);
        $this->assertSame($engineResult['message'] ?? 'Unknown error', $this->rows[1]['last_error']);
    }

    // ── crash / exception handling mid-batch ───────────────────────

    public function test_exception_in_middle_of_batch_fails_only_that_item_and_batch_continues(): void
    {
        $this->seed(1);
        $this->seed(2);
        $this->seed(3);
        $calls = 0;
        $this->engine->shouldReceive('sync_product_to_store')->times(3)->andReturnUsing(function () use (&$calls) {
            $calls++;
            if ($calls === 2) {
                throw new \RuntimeException('cURL error 28');
            }
            return ['success' => true];
        });

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(3, $result['processed']);
        $this->assertSame(2, $result['success']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame('completed', $this->rows[1]['status']);
        $this->assertSame('pending', $this->rows[2]['status'], 'Thrown item is retried later, not lost.');
        $this->assertSame('cURL error 28', $this->rows[2]['last_error']);
        $this->assertSame(1, $this->rows[2]['attempts']);
        $this->assertSame('completed', $this->rows[3]['status']);
    }

    public function test_php_error_is_caught_like_an_exception(): void
    {
        $this->seed(1);
        $this->engine->shouldReceive('sync_product_to_store')->once()->andReturnUsing(static function () {
            throw new \TypeError('Argument #1 must be of type array');
        });

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['errors']);
        $this->assertSame('pending', $this->rows[1]['status']);
        $this->assertStringContainsString('must be of type array', $this->rows[1]['last_error']);
    }

    public function test_exception_marks_processing_before_failing_and_always_releases_lock(): void
    {
        $this->seed(1);
        $this->engine->shouldReceive('sync_product_to_store')->once()->andThrow(new \RuntimeException('x'));

        WC_MSS()->queue_manager->process_queue(10);

        $statusWrites = array_values(array_filter(
            array_column($this->updates, 'data'),
            static fn(array $d): bool => isset($d['status'])
        ));
        $this->assertSame('processing', $statusWrites[0]['status']);
        $this->assertSame('pending', $statusWrites[1]['status']);
        $this->assertSame(1, $this->releaseCount, 'Lock must be released exactly once.');
    }

    public function test_lock_is_released_when_post_loop_stats_query_throws(): void
    {
        $this->seed(1);
        $this->engineAlwaysReturns(['success' => true]);
        $this->statsThrows = true;

        try {
            WC_MSS()->queue_manager->process_queue(10);
            $this->fail('Expected the stats failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('stats down', $e->getMessage());
        }

        $this->assertSame(1, $this->releaseCount, 'finally{} must release the advisory lock.');
        $this->assertSame('completed', $this->rows[1]['status'], 'Work already done must stay recorded.');
    }

    public function test_crashed_worker_rows_are_recovered_and_processed_not_lost(): void
    {
        // Worker died after mark_processing: the row has been 'processing' for an hour.
        $this->seed(1, ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->engineAlwaysReturns(['success' => true], 1);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['success']);
        $this->assertSame('completed', $this->rows[1]['status']);
        $this->assertSame(1, $this->rows[1]['attempts'], 'The recovery counted as one attempt.');
    }

    public function test_item_that_keeps_crashing_the_worker_is_parked_as_failed_after_three_resets(): void
    {
        // Each run: the worker claims the row and dies (simulated by leaving it processing and old).
        $this->seed(1, ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s', time() - 3600), 'attempts' => 0]);
        $this->engine->shouldNotReceive('sync_product_to_store');
        $this->lockResult = 0; // another runner holds the lock; only the stuck reset runs

        for ($i = 1; $i <= 3; $i++) {
            WC_MSS()->queue_manager->process_queue(10);
            $this->assertSame($i, $this->rows[1]['attempts']);
            if ($i < 3) {
                $this->assertSame('pending', $this->rows[1]['status']);
                $this->rows[1]['status'] = 'processing';
                $this->rows[1]['started_at'] = date('Y-m-d H:i:s', time() - 3600);
            }
        }

        $this->assertSame('failed', $this->rows[1]['status'], 'Third strike must stop the crash loop.');
        $this->assertNotNull($this->rows[1]['completed_at'], 'Parked rows need completed_at so cleanup() can purge them.');
        $this->lockResult = 1;
        $this->assertSame('empty', WC_MSS()->queue_manager->process_queue(10)['status']);
    }

    public function test_recently_started_processing_row_is_not_stolen_or_double_processed(): void
    {
        // A different worker started this 30 seconds ago and is still working on it.
        $this->seed(1, ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s', time() - 30)]);
        $this->engine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('empty', $result['status']);
        $this->assertSame('processing', $this->rows[1]['status']);
    }

    public function test_stuck_reset_runs_with_five_and_ten_minute_thresholds_before_selecting(): void
    {
        $this->seed(1, ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s', time() - 7 * 60)]);
        $this->engineAlwaysReturns(['success' => true], 1);

        WC_MSS()->queue_manager->process_queue(10);

        $resets = array_values(array_filter($this->log, static fn(string $s): bool => str_contains($s, "started_at = NULL")));
        $this->assertCount(2, $resets);
        preg_match("/started_at < '([^']+)'/", $resets[0], $a);
        preg_match("/started_at < '([^']+)'/", $resets[1], $b);
        $this->assertEqualsWithDelta(300, time() - strtotime($a[1]), 5);
        $this->assertEqualsWithDelta(600, time() - strtotime($b[1]), 5);

        $firstSelect = array_search(true, array_map(static fn(string $s): bool => str_contains($s, 'ORDER BY priority'), $this->log), true);
        $lastReset = array_keys(array_filter($this->log, static fn(string $s): bool => str_contains($s, 'started_at = NULL')));
        $this->assertLessThan($firstSelect, max($lastReset));
    }

    // ── lock / empty queue / skip conditions ───────────────────────

    public function test_lock_held_elsewhere_skips_without_selecting_or_releasing_foreign_lock(): void
    {
        $this->seed(1);
        $this->lockResult = 0;
        $this->engine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(0, $this->releaseCount, 'Must not RELEASE_LOCK a lock this connection does not own.');
        $this->assertSame([], array_filter($this->log, static fn(string $s): bool => str_contains($s, 'ORDER BY priority')));
        $this->assertSame('pending', $this->rows[1]['status']);
    }

    public function test_lock_request_is_non_blocking(): void
    {
        $this->lockResult = 0;
        WC_MSS()->queue_manager->process_queue(10);
        $this->assertSame("SELECT GET_LOCK('wc_mss_queue_processor', 0)", array_values(array_filter(
            $this->log,
            static fn(string $s): bool => str_contains($s, 'GET_LOCK')
        ))[0]);
    }

    public function test_stuck_rows_are_reset_even_when_lock_is_held_by_someone_else(): void
    {
        $this->seed(1, ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->lockResult = 0;

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('pending', $this->rows[1]['status']);
        $this->assertSame(1, $this->rows[1]['attempts']);
    }

    public function test_empty_queue_returns_empty_and_still_releases_lock(): void
    {
        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(['status' => 'empty', 'message' => 'Queue is empty'], $result);
        $this->assertSame(1, $this->releaseCount);
    }

    public function test_circuit_open_store_items_stay_pending_untouched_and_other_stores_proceed(): void
    {
        $this->seed(1, ['store_url' => 'https://store1.com']);
        $this->seed(2, ['store_url' => 'https://store2.com']);
        Functions\when('get_transient')->alias(static function ($key) {
            return $key === 'wc_mss_cb_' . md5('https://store1.com')
                ? ['consecutive_errors' => 10, 'open_until' => time() + 600, 'opened_at' => time()]
                : false;
        });
        $this->engine->shouldReceive('sync_product_to_store')->once()
            ->with(\Mockery::any(), 'https://store2.com', \Mockery::type('array'), 'full_product', 'manual')
            ->andReturn(['success' => true]);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['processed'], 'Skipped items are not counted as processed.');
        $this->assertSame('pending', $this->rows[1]['status']);
        $this->assertSame(0, $this->rows[1]['attempts'], 'A circuit skip must not burn an attempt.');
        $this->assertNull($this->rows[1]['started_at']);
        $this->assertSame('completed', $this->rows[2]['status']);
        $this->assertSame(1, $result['remaining']);
    }

    public function test_item_for_removed_store_is_failed_with_clear_reason_and_eventually_dead_lettered(): void
    {
        $this->seed(1, ['store_url' => 'https://gone.com']);
        $this->engine->shouldNotReceive('sync_product_to_store');

        for ($i = 0; $i < 3; $i++) {
            $this->clockOffset = $i * 3 * 3600;
            WC_MSS()->queue_manager->process_queue(10);
        }

        $this->assertSame('failed', $this->rows[1]['status']);
        $this->assertSame('Store configuration not found', $this->rows[1]['last_error']);
        $this->assertCount(1, $this->dlq);
    }

    // ── batch size, ordering, limits ───────────────────────────────

    public function test_explicit_batch_size_limits_items_and_reports_remaining(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seed($i);
        }
        $this->engineAlwaysReturns(['success' => true], 2);

        $result = WC_MSS()->queue_manager->process_queue(2);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(3, $result['remaining']);
        $this->assertSame(['completed', 'completed', 'pending', 'pending', 'pending'], array_values($this->statuses()));
        $this->assertStringContainsString('LIMIT 2', implode("\n", array_filter($this->log, static fn($s) => str_contains($s, 'ORDER BY'))));
    }

    public function test_batch_size_zero_uses_time_manager_configured_size(): void
    {
        $this->seed(1);
        WC_MSS()->queue_manager->process_queue(0);

        $select = array_values(array_filter($this->log, static fn(string $s): bool => str_contains($s, 'ORDER BY priority')))[0];
        $this->assertStringContainsString('LIMIT 17', $select);
    }

    public function test_items_are_processed_in_priority_then_store_then_age_order(): void
    {
        $this->seed(1, ['priority' => 10]);
        $this->seed(2, ['priority' => 1, 'store_url' => 'https://store2.com']);
        $this->seed(3, ['priority' => 1, 'store_url' => 'https://store1.com']);
        $this->seed(4, ['priority' => 2]);
        $order = [];
        $this->engine->shouldReceive('sync_product_to_store')->times(4)->andReturnUsing(function ($p, $store) use (&$order) {
            $order[] = $store;
            return ['success' => true];
        });
        $ids = [];
        Functions\when('wc_get_product')->alias(function ($id) use (&$ids) {
            $ids[] = $id;
            $p = \Mockery::mock('WC_Product');
            $p->shouldReceive('get_status')->andReturn('publish');
            $p->shouldReceive('get_sku')->andReturn('S');
            $p->shouldReceive('is_type')->andReturn(false);
            return $p;
        });

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame([103, 102, 104, 101], array_values(array_unique($ids)));
        $this->assertSame(['https://store1.com', 'https://store2.com', 'https://store1.com', 'https://store1.com'], $order);
    }

    public function test_high_priority_item_is_not_starved_by_many_low_priority_ones(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->seed($i, ['priority' => 10]);
        }
        $this->seed(99, ['priority' => 1]);
        $this->engineAlwaysReturns(['success' => true]);

        WC_MSS()->queue_manager->process_queue(1);

        $this->assertSame('completed', $this->rows[99]['status']);
    }

    public function test_memory_pressure_stops_batch_before_touching_any_item(): void
    {
        $this->seed(1);
        $this->seed(2);
        $this->engine->shouldNotReceive('sync_product_to_store');

        $original = ini_get('memory_limit');
        $usage = memory_get_usage(true);
        // 0.85 * limit < usage  =>  loop must bail out at the first item.
        ini_set('memory_limit', (string) ($usage + 1024 * 1024));
        try {
            $result = WC_MSS()->queue_manager->process_queue(10);
        } finally {
            ini_set('memory_limit', (string) $original);
        }

        $this->assertSame('completed', $result['status']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(['pending', 'pending'], array_values($this->statuses()), 'Unprocessed items must stay pending, not processing.');
        $this->assertSame(2, $result['remaining']);
        $this->assertSame(1, $this->releaseCount);
    }

    // ── non-published / vanished products ──────────────────────────

    public function test_unpublished_product_is_completed_without_calling_the_engine(): void
    {
        $this->seed(1);
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('get_status')->andReturn('draft');
        $p->shouldReceive('get_sku')->andReturn('S');
        Functions\when('wc_get_product')->justReturn($p);
        $this->engine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('completed', $this->rows[1]['status']);
        $this->assertSame(1, $result['success']);
        $this->assertSame([], $this->dlq);
    }

    public function test_vanished_product_fails_and_retries_instead_of_vanishing_silently(): void
    {
        $this->seed(1);
        Functions\when('wc_get_product')->justReturn(false);
        $this->engine->shouldNotReceive('sync_product_to_store');

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('pending', $this->rows[1]['status']);
        $this->assertSame('Product not found', $this->rows[1]['last_error']);
    }

    // ── deletion / status / restore items ──────────────────────────

    public function test_deletion_item_missing_sku_never_calls_engine_and_keeps_failure_reason(): void
    {
        $this->seed(1, ['sync_type' => 'delete_product', 'product_sku' => null]);
        $this->engine->shouldNotReceive('delete_product_from_store');

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('Product SKU not found for deletion', $this->rows[1]['last_error']);
        $this->assertSame('pending', $this->rows[1]['status']);
    }

    public function test_deletion_failure_keeps_transient_for_retry_and_success_deletes_it(): void
    {
        $this->seed(1, ['sync_type' => 'delete_product']);
        $deleted = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });
        $results = [['success' => false, 'message' => 'timeout'], ['success' => true]];
        $this->engine->shouldReceive('delete_product_from_store')->twice()->andReturnUsing(function () use (&$results) {
            return array_shift($results);
        });

        WC_MSS()->queue_manager->process_queue(10);
        $this->assertSame([], $deleted, 'Transient needed for retry must survive a failure.');

        $this->clockOffset = 3 * 3600;
        WC_MSS()->queue_manager->process_queue(10);
        $this->assertSame(['wc_mss_delete_101_' . md5('https://store1.com')], $deleted);
        $this->assertSame('completed', $this->rows[1]['status']);
    }

    public function test_status_change_item_passes_new_status_from_extra_data_and_source(): void
    {
        $this->seed(1, ['sync_type' => 'status_change', 'source' => 'status_change', 'extra_data' => '{"new_status":"draft","old_status":"publish"}']);
        $this->engine->shouldReceive('update_product_status_on_store')->once()
            ->with(101, 'SKU-1', 'draft', 'https://store1.com', \Mockery::type('array'), 'status_change')
            ->andReturn(['success' => true]);

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('completed', $this->rows[1]['status']);
    }

    public function test_status_change_with_corrupt_extra_data_fails_instead_of_syncing_wrong_status(): void
    {
        $this->seed(1, ['sync_type' => 'status_change', 'extra_data' => '{not json']);
        $this->engine->shouldNotReceive('update_product_status_on_store');

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('pending', $this->rows[1]['status']);
        $this->assertStringContainsString('missing new_status', $this->rows[1]['last_error']);
    }

    public function test_delete_orphan_accepts_remote_id_without_sku(): void
    {
        $this->seed(1, ['sync_type' => 'delete_orphan', 'product_sku' => null, 'extra_data' => '{"remote_product_id":555}']);
        $this->engine->shouldReceive('delete_orphan_product_from_store')->once()
            ->with(101, '', 555, 'https://store1.com', \Mockery::type('array'), 'manual')
            ->andReturn(['success' => true]);

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('completed', $this->rows[1]['status']);
    }

    public function test_delete_variation_requires_parent_in_extra_data(): void
    {
        $this->seed(1, ['sync_type' => 'delete_variation', 'extra_data' => '{"parent_id":0}']);
        $this->engine->shouldNotReceive('delete_variation_from_store');

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('Variation data not found for deletion', $this->rows[1]['last_error']);
    }

    // ── stock_correction / conflict_resolution handling ────────────

    public function test_failed_stock_correction_is_reset_to_pending_for_retry(): void
    {
        $this->seed(1, ['sync_type' => 'stock_correction', 'source' => 'stock_discrepancy_9', 'extra_data' => '{"discrepancy_id":9}']);
        // Discrepancy row does not exist -> process_correction fails.

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['errors']);
        $reset = array_values(array_filter($this->updates, static fn(array $u): bool => $u['table'] === 'wp_wc_multi_store_stock_discrepancies'));
        $this->assertCount(1, $reset);
        $this->assertSame(['status' => 'pending'], $reset[0]['data']);
        $this->assertSame(['id' => 9, 'status' => 'resolving'], $reset[0]['where']);
        $this->assertSame('Stock discrepancy does not match the queue target', $this->rows[1]['last_error']);
    }

    public function test_stock_correction_exception_still_resets_the_discrepancy(): void
    {
        $this->seed(1, ['sync_type' => 'stock_correction', 'extra_data' => '{"discrepancy_id":9}']);
        $this->discrepancyRow = static function (): never {
            throw new \RuntimeException('db dropped');
        };

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['errors']);
        $this->assertSame('db dropped', $this->rows[1]['last_error']);
        $reset = array_filter($this->updates, static fn(array $u): bool => $u['table'] === 'wp_wc_multi_store_stock_discrepancies');
        $this->assertCount(1, $reset, 'finally{} must release the discrepancy even on exceptions.');
    }

    public function test_stock_correction_for_missing_store_resets_discrepancy(): void
    {
        $this->seed(1, ['sync_type' => 'stock_correction', 'store_url' => 'https://gone.com', 'extra_data' => '{"discrepancy_id":9}']);

        WC_MSS()->queue_manager->process_queue(10);

        $reset = array_filter($this->updates, static fn(array $u): bool => $u['table'] === 'wp_wc_multi_store_stock_discrepancies');
        $this->assertCount(1, $reset);
    }

    public function test_successful_stock_correction_does_not_reset_discrepancy(): void
    {
        $this->seed(1, ['sync_type' => 'stock_correction', 'extra_data' => '{"discrepancy_id":9}']);
        $this->discrepancyRow = static fn() => ['id' => 9, 'product_id' => 101, 'store_url' => 'https://store1.com', 'status' => 'resolved'];

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['success']);
        $this->assertSame('completed', $this->rows[1]['status']);
        $this->assertSame([], array_filter($this->updates, static fn(array $u): bool => $u['table'] === 'wp_wc_multi_store_stock_discrepancies'));
    }

    public function test_stock_correction_without_extra_data_looks_up_discrepancy_zero_and_fails_safely(): void
    {
        $this->seed(1, ['sync_type' => 'stock_correction', 'extra_data' => null]);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['errors']);
        $lookups = array_filter($this->log, static fn(string $s): bool => str_contains($s, 'stock_discrepancies WHERE id = 0'));
        $this->assertNotEmpty($lookups);
    }

    public function test_conflict_resolution_with_invalid_decision_never_reaches_sync_engine(): void
    {
        $this->seed(1, ['sync_type' => 'conflict_resolution', 'extra_data' => '{"conflict_id":4,"resolution":"drop_table"}']);
        $this->engine->shouldNotReceive('sync_product_to_store');

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('Invalid conflict resolution', $this->rows[1]['last_error']);
        $this->assertNotSame('completed', $this->rows[1]['status']);
    }

    public function test_conflict_resolution_with_missing_extra_data_is_not_completed(): void
    {
        $this->seed(1, ['sync_type' => 'conflict_resolution', 'extra_data' => null]);
        $this->engine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['errors']);
        $this->assertSame('pending', $this->rows[1]['status']);
    }

    // ── inactive store / atomic claim ──────────────────────────────

    public static function inactiveStatuses(): array
    {
        return ['inactive' => ['inactive'], 'paused' => ['paused'], 'empty' => [''], 'uppercase' => ['ACTIVE']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inactiveStatuses')]
    public function test_item_for_non_active_store_is_failed_without_retry_and_dead_lettered(string $status): void
    {
        $this->stores['https://store1.com']['status'] = $status;
        $this->seed(1);
        $this->engine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(1, $result['errors']);
        $this->assertSame('failed', $this->rows[1]['status'], 'No retry: the store will not become active by waiting.');
        $this->assertSame('Store is not active', $this->rows[1]['last_error']);
        $this->assertSame(1, $this->rows[1]['attempts']);
        $this->assertCount(1, $this->dlq);
    }

    public function test_store_without_status_key_is_treated_as_inactive(): void
    {
        unset($this->stores['https://store1.com']['status']);
        $this->seed(1);
        $this->engine->shouldNotReceive('sync_product_to_store');

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame('Store is not active', $this->rows[1]['last_error']);
    }

    public function test_inactive_store_does_not_block_active_store_items_in_same_batch(): void
    {
        $this->stores['https://store1.com']['status'] = 'inactive';
        $this->seed(1);
        $this->seed(2, ['store_url' => 'https://store2.com']);
        $this->engine->shouldReceive('sync_product_to_store')->once()->andReturn(['success' => true]);
        $events = [];

        $result = WC_MSS()->queue_manager->process_queue(10, static function (array $e) use (&$events): void {
            $events[$e['item_id']] = [$e['status'], $e['message']];
        });

        $this->assertSame(['error', 'Store is not active'], $events[1]);
        $this->assertSame(['success', ''], $events[2]);
        $this->assertSame(2, $result['processed']);
        $this->assertSame('completed', $this->rows[2]['status']);
    }

    public function test_inactive_store_does_not_count_against_circuit_breaker(): void
    {
        $this->stores['https://store1.com']['status'] = 'inactive';
        $this->seed(1);
        $writes = 0;
        Functions\when('set_transient')->alias(function ($key) use (&$writes) {
            $writes += str_starts_with((string) $key, 'wc_mss_cb_') ? 1 : 0;
            return true;
        });

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame(0, $writes);
    }

    public function test_item_claimed_by_another_worker_is_skipped_not_processed_twice(): void
    {
        $this->seed(1);
        $this->seed(2);
        // Another worker flips item 1 to processing between our SELECT and our claim.
        $stolen = false;
        $this->beforeClaim = function (int $id) use (&$stolen): void {
            if ($id === 1 && !$stolen) {
                $stolen = true;
                $this->rows[1]['status'] = 'processing';
                $this->rows[1]['started_at'] = date('Y-m-d H:i:s');
            }
        };
        $this->engine->shouldReceive('sync_product_to_store')->once()->andReturn(['success' => true]);
        $events = [];

        $result = WC_MSS()->queue_manager->process_queue(10, static function (array $e) use (&$events): void {
            $events[] = [$e['item_id'], $e['status'], $e['message']];
        });

        $this->assertSame([[1, 'skipped', 'Item already claimed by another worker'], [2, 'success', '']], $events);
        $this->assertSame(1, $result['processed'], 'A lost claim is not counted as processed.');
        $this->assertSame('processing', $this->rows[1]['status'], 'The other worker keeps its item.');
        $this->assertSame(0, $this->rows[1]['attempts']);
        $this->assertSame('completed', $this->rows[2]['status']);
    }

    public function test_lost_claim_does_not_trigger_stock_correction_reset_or_notifications(): void
    {
        $this->seed(1, ['sync_type' => 'stock_correction', 'extra_data' => '{"discrepancy_id":9}']);
        $this->beforeClaim = function (int $id): void {
            $this->rows[$id]['status'] = 'processing';
        };

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame([], array_filter($this->updates, static fn(array $u): bool => $u['table'] === 'wp_wc_multi_store_stock_discrepancies'));
        $this->assertSame([], array_filter($this->actions, static fn($a) => $a[0] === 'wc_mss_sync_failed'));
    }

    public function test_negative_batch_size_is_clamped_and_never_produces_invalid_sql(): void
    {
        $this->seed(1);
        $this->seed(2);
        $this->engineAlwaysReturns(['success' => true], 1);

        $result = WC_MSS()->queue_manager->process_queue(-5);

        $this->assertSame(1, $result['processed']);
        $select = array_values(array_filter($this->log, static fn(string $s): bool => str_contains($s, 'ORDER BY priority')))[0];
        $this->assertStringContainsString('LIMIT 1', $select);
    }

    public function test_failing_select_is_treated_as_empty_queue_and_releases_lock(): void
    {
        $this->seed(1);
        $this->selectFails = true;
        $this->engine->shouldNotReceive('sync_product_to_store');

        $this->assertSame('empty', WC_MSS()->queue_manager->process_queue(10)['status']);
        $this->assertSame(1, $this->releaseCount);
        $this->assertSame('pending', $this->rows[1]['status']);
    }

    // ── progress callback ──────────────────────────────────────────

    public function test_item_callback_reports_every_outcome_with_index_and_total(): void
    {
        $this->seed(1);
        $this->seed(2, ['store_url' => 'https://gone.com']);
        $this->seed(3);
        $this->engineAlwaysReturns(['success' => true]);
        $events = [];

        WC_MSS()->queue_manager->process_queue(10, static function (array $e) use (&$events): void {
            $events[] = [$e['item_id'], $e['status'], $e['index'], $e['total']];
        });

        $this->assertSame([[2, 'error', 1, 3], [1, 'success', 2, 3], [3, 'success', 3, 3]], $events);
    }
}
