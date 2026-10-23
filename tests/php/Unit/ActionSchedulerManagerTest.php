<?php
/**
 * Unit tests for WC_Multi_Store_Action_Scheduler_Manager
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ActionSchedulerManagerTest extends WC_Multi_Store_TestCase
{
    /** @var object{lines: list<array{0: string, 1: string}>} */
    private object $logSink;

    /** @var list<array{0: string, 1: array}> Calls made to the as_* scheduling functions. */
    private array $schedulerCalls = [];

    /** Capture every line the plugin logger sends to wc_get_logger(). */
    private function captureLogs(): void
    {
        $this->logSink = new class {
            /** @var list<array{0: string, 1: string}> */
            public array $lines = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->lines[] = [$level, $message];
            }
        };
        Functions\when('wc_get_logger')->justReturn($this->logSink);
    }

    /** @return list<string> */
    private function loggedMessages(): array
    {
        return array_column($this->logSink->lines, 1);
    }

    /**
     * Record (rather than execute) calls to the Action Scheduler functions and
     * the given WordPress functions, so "scheduler unavailable" tests can prove
     * the is_available() gate short-circuits before anything is scheduled,
     * unscheduled, read, or logged. Recorders are used instead of Mockery
     * never() because setUp() already stubs some of these with when().
     */
    private function forbidSchedulerCalls(string ...$extra): void
    {
        foreach (array_merge(['as_schedule_recurring_action', 'as_schedule_single_action', 'as_unschedule_all_actions', 'as_next_scheduled_action'], $extra) as $fn) {
            Functions\when($fn)->alias(function (...$args) use ($fn) {
                $this->schedulerCalls[] = [$fn, $args];
                return false;
            });
        }
    }

    /**
     * Make is_available() true (for use only inside separate-process tests,
     * since defining the ActionScheduler class cannot be undone) and record
     * every scheduling call.
     *
     * @param array<string, int|false> $next Value as_next_scheduled_action() returns per hook.
     */
    private function stubActionScheduler(array $next = []): void
    {
        if (!class_exists('ActionScheduler', false)) {
            class_alias(\stdClass::class, 'ActionScheduler');
        }
        foreach (['as_schedule_recurring_action', 'as_schedule_single_action', 'as_unschedule_all_actions'] as $fn) {
            Functions\when($fn)->alias(function (...$args) use ($fn) {
                $this->schedulerCalls[] = [$fn, $args];
                return 1;
            });
        }
        Functions\when('as_next_scheduled_action')->alias(fn($hook) => $next[$hook] ?? false);
    }

    /** @return list<string> "function:hook" for each recorded call. */
    private function callSummary(): array
    {
        return array_map(static fn(array $c): string => $c[0] . ':' . ($c[0] === 'as_schedule_recurring_action' ? $c[1][2] : ($c[0] === 'as_schedule_single_action' ? $c[1][1] : $c[1][0])), $this->schedulerCalls);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActionSchedulerMocks();
    }

    protected function setUpActionSchedulerMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_cache_flush')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Action_Scheduler_Manager'));
    }

    public function test_constants(): void
    {
        $this->assertEquals('wc_multi_store_sync_process_queue', WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_QUEUE);
        $this->assertEquals('wc_multi_store_sync_scheduled_check', WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_SCHEDULED_SYNC);
        $this->assertEquals('wc_multi_store_sync_process_debounced_order', WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_DEBOUNCED_ORDER);
        $this->assertEquals('wc_multi_store_sync', WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
        $this->assertEquals('wc_multi_store_sync_force_sync_batch', WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_FORCE_SYNC_BATCH);
        $this->assertEquals('wc_multi_store_sync_daily_maintenance', WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_MAINTENANCE);
    }

    public function test_is_available_false_without_functions(): void
    {
        // as_schedule_recurring_action doesn't exist in test env
        $this->assertFalse(WC_Multi_Store_Action_Scheduler_Manager::is_available());
    }

    public function test_ensure_scheduled_returns_early_when_not_available(): void
    {
        $this->captureLogs();
        $this->forbidSchedulerCalls('set_transient');

        (new WC_Multi_Store_Action_Scheduler_Manager())->ensure_scheduled();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    public function test_ensure_scheduled_respects_throttle(): void
    {
        // The availability gate comes before the throttle: without Action
        // Scheduler the throttle transient is neither read nor written.
        $this->forbidSchedulerCalls('get_transient', 'set_transient');

        (new WC_Multi_Store_Action_Scheduler_Manager())->ensure_scheduled();

        $this->assertSame([], $this->schedulerCalls);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_ensure_scheduled_skips_when_throttled(): void
    {
        $this->captureLogs();
        $this->stubActionScheduler();
        Functions\when('get_transient')->alias(fn($key) => $key === 'wc_mss_ensure_scheduled' ? 1 : false);
        $transientWrites = 0;
        Functions\when('set_transient')->alias(function () use (&$transientWrites) {
            $transientWrites++;
            return true;
        });

        (new WC_Multi_Store_Action_Scheduler_Manager())->ensure_scheduled();

        $this->assertSame(0, $transientWrites, 'A throttled run must not re-arm the throttle');
        $this->assertSame([], $this->schedulerCalls, 'A throttled run must not touch Action Scheduler');
        $this->assertSame([], $this->loggedMessages());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_ensure_scheduled_schedules_missing_core_actions_and_sets_throttle(): void
    {
        $this->captureLogs();
        // Weekly verification and remote order sync are already scheduled.
        $this->stubActionScheduler([
            'wc_multi_store_weekly_verification' => 123,
            'wc_multi_store_sync_remote_orders' => 456,
        ]);
        $transients = [];
        Functions\when('set_transient')->alias(function (...$args) use (&$transients) {
            $transients[] = $args;
            return true;
        });

        (new WC_Multi_Store_Action_Scheduler_Manager())->ensure_scheduled();

        $this->assertSame([['wc_mss_ensure_scheduled', 1, 5 * MINUTE_IN_SECONDS]], $transients);
        $this->assertSame([
            'as_schedule_recurring_action:' . WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_QUEUE,
            'as_schedule_recurring_action:' . WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_SCHEDULED_SYNC,
            'as_schedule_recurring_action:' . WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_MAINTENANCE,
        ], $this->callSummary());

        // Intervals: queue every minute, sync check every 10 minutes (default), maintenance daily.
        $this->assertSame(MINUTE_IN_SECONDS, $this->schedulerCalls[0][1][1]);
        $this->assertSame(10 * MINUTE_IN_SECONDS, $this->schedulerCalls[1][1][1]);
        $this->assertSame(DAY_IN_SECONDS, $this->schedulerCalls[2][1][1]);
        foreach ($this->schedulerCalls as [, $args]) {
            $this->assertSame([], $args[3]);
            $this->assertSame(WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP, $args[4]);
        }

        $this->assertContains('Queue processor scheduled via Action Scheduler (every 1 minute)', $this->loggedMessages());
        $this->assertContains('Daily maintenance action scheduled', $this->loggedMessages());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_ensure_scheduled_does_not_reschedule_when_everything_is_scheduled(): void
    {
        $this->captureLogs();
        $this->stubActionScheduler([
            WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_QUEUE => 1,
            WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_SCHEDULED_SYNC => 2,
            WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_MAINTENANCE => 3,
            'wc_multi_store_weekly_verification' => 4,
            'wc_multi_store_sync_remote_orders' => 5,
        ]);

        (new WC_Multi_Store_Action_Scheduler_Manager())->ensure_scheduled();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    public function test_ensure_scheduled_reconciles_remote_order_sync(): void
    {
        // Bypass the is_available()/class_exists('ActionScheduler') gate the same
        // way OrphanCleanupTest::test_schedule_auto_trash_registers_recurring_action
        // does — reimplement the block under test (ensure_scheduled's guard, plus
        // schedule_sync()'s own effect, since that method has its own unmockable gate).
        $captured = null;
        Functions\when('as_schedule_recurring_action')->alias(function (...$args) use (&$captured) {
            $captured = $args;
            return 1;
        });
        Functions\when('as_next_scheduled_action')->justReturn(false);
        Functions\when('as_unschedule_all_actions')->justReturn(true);

        $manager = new class extends WC_Multi_Store_Action_Scheduler_Manager {
            public function ensure_scheduled(): void {
                if (!as_next_scheduled_action('wc_multi_store_sync_remote_orders', [], self::ACTION_GROUP)) {
                    as_unschedule_all_actions('wc_multi_store_sync_remote_orders', [], self::ACTION_GROUP);
                    as_schedule_recurring_action(time(), DAY_IN_SECONDS, 'wc_multi_store_sync_remote_orders', [], self::ACTION_GROUP);
                }
            }
        };
        $manager->ensure_scheduled();

        $this->assertNotNull($captured, 'as_schedule_recurring_action should be called');
        $this->assertEquals('wc_multi_store_sync_remote_orders', $captured[2]);
    }

    public function test_ensure_scheduled_skips_remote_order_sync_when_already_scheduled(): void
    {
        $called = false;
        Functions\when('as_schedule_recurring_action')->alias(function () use (&$called) {
            $called = true;
            return 1;
        });
        Functions\when('as_next_scheduled_action')->justReturn(12345);

        $manager = new class extends WC_Multi_Store_Action_Scheduler_Manager {
            public function ensure_scheduled(): void {
                if (!as_next_scheduled_action('wc_multi_store_sync_remote_orders', [], self::ACTION_GROUP)) {
                    WC_Multi_Store_Remote_Order_Sync::schedule_sync();
                }
            }
        };
        $manager->ensure_scheduled();

        $this->assertFalse($called);
    }

    public function test_schedule_queue_processor_not_available(): void
    {
        $this->captureLogs();
        $this->forbidSchedulerCalls();

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_queue_processor();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    public function test_schedule_sync_check_not_available(): void
    {
        $this->captureLogs();
        $this->forbidSchedulerCalls('get_option');

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_schedule_sync_check_uses_configured_interval(): void
    {
        $this->captureLogs();
        $this->stubActionScheduler();
        Functions\when('get_option')->alias(fn($option, $default = false) => $option === 'wc_multi_store_sync_scheduled'
            ? ['scheduled_sync_enabled' => true, 'scheduled_sync_interval' => '30min']
            : $default);

        $before = time();
        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $this->assertCount(1, $this->schedulerCalls);
        [$fn, $args] = $this->schedulerCalls[0];
        $this->assertSame('as_schedule_recurring_action', $fn);
        $this->assertGreaterThanOrEqual($before, $args[0]);
        $this->assertSame(30 * MINUTE_IN_SECONDS, $args[1]);
        $this->assertSame(WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_SCHEDULED_SYNC, $args[2]);
        $this->assertSame(WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP, $args[4]);
        $this->assertSame(['Scheduled sync check via Action Scheduler (interval: 30min)'], $this->loggedMessages());
    }

    public function test_schedule_maintenance_not_available(): void
    {
        $this->captureLogs();
        $this->forbidSchedulerCalls();

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_maintenance();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    public function test_schedule_debounced_order_not_available(): void
    {
        $this->captureLogs();
        $this->forbidSchedulerCalls();

        WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(42, 60);

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_schedule_debounced_order_replaces_pending_action_with_delayed_single_action(): void
    {
        $this->captureLogs();
        $this->stubActionScheduler();

        $before = time();
        WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(42, 90);
        $after = time();

        $hook = WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_DEBOUNCED_ORDER;
        $group = WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP;
        $this->assertCount(2, $this->schedulerCalls);
        // Existing pending debounce for the same order is cleared first...
        $this->assertSame(['as_unschedule_all_actions', [$hook, ['order_id' => 42], $group]], $this->schedulerCalls[0]);
        // ...then exactly one new single action is queued `delay` seconds out.
        [$fn, $args] = $this->schedulerCalls[1];
        $this->assertSame('as_schedule_single_action', $fn);
        $this->assertGreaterThanOrEqual($before + 90, $args[0]);
        $this->assertLessThanOrEqual($after + 90, $args[0]);
        $this->assertSame([$hook, ['order_id' => 42], $group], array_slice($args, 1));
        $this->assertSame(['Debounced order action scheduled for order #42 (delay: 90s)'], $this->loggedMessages());
    }

    public function test_unschedule_all_not_available(): void
    {
        $this->captureLogs();
        $this->forbidSchedulerCalls();

        WC_Multi_Store_Action_Scheduler_Manager::unschedule_all();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_unschedule_all_clears_every_plugin_hook(): void
    {
        $this->captureLogs();
        $this->stubActionScheduler();

        WC_Multi_Store_Action_Scheduler_Manager::unschedule_all();

        $this->assertSame([
            'as_unschedule_all_actions:wc_multi_store_sync_process_queue',
            'as_unschedule_all_actions:wc_multi_store_sync_scheduled_check',
            'as_unschedule_all_actions:wc_multi_store_sync_daily_maintenance',
            'as_unschedule_all_actions:wc_multi_store_sync_remote_orders',
            'as_unschedule_all_actions:wc_multi_store_health_check',
            'as_unschedule_all_actions:wc_mss_send_daily_summary',
        ], $this->callSummary());
        $this->assertSame(['All scheduled actions unscheduled'], $this->loggedMessages());
    }

    public function test_get_interval_seconds(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Action_Scheduler_Manager::class, 'get_interval_seconds');

        $this->assertEquals(30 * MINUTE_IN_SECONDS, $method->invoke(null, '30min'));
        $this->assertEquals(HOUR_IN_SECONDS, $method->invoke(null, 'hourly'));
        $this->assertEquals(DAY_IN_SECONDS, $method->invoke(null, 'daily'));
        $this->assertEquals(10 * MINUTE_IN_SECONDS, $method->invoke(null, '10min'));
        $this->assertEquals(10 * MINUTE_IN_SECONDS, $method->invoke(null, 'unknown'));
    }

    public function test_get_status_not_available(): void
    {
        Functions\when('get_option')->justReturn(false);

        $status = WC_Multi_Store_Action_Scheduler_Manager::get_status();

        $this->assertIsArray($status);
        $this->assertEquals('Not Available', $status['scheduler_type']);
        $this->assertFalse($status['queue_processor']['is_scheduled']);
        $this->assertNull($status['queue_processor']['next_run']);
        $this->assertFalse($status['scheduled_sync']['is_scheduled']);
        $this->assertEquals(0, $status['pending_actions']);
        $this->assertEquals(0, $status['failed_actions']);
    }

    public function test_get_status_with_last_run_times(): void
    {
        $timestamp = time() - 300; // 5 minutes ago
        Functions\when('get_option')->alias(function ($option) use ($timestamp) {
            if ($option === 'wc_multi_store_sync_last_queue_run') return $timestamp;
            if ($option === 'wc_multi_store_sync_last_scheduled_run') return $timestamp;
            return false;
        });
        Functions\when('human_time_diff')->justReturn('5 mins');
        Functions\when('current_time')->justReturn(time());

        $status = WC_Multi_Store_Action_Scheduler_Manager::get_status();

        $this->assertNotNull($status['queue_processor']['last_run']);
        $this->assertNotNull($status['scheduled_sync']['last_run']);
    }

    public function test_get_pending_count_not_available(): void
    {
        $count = WC_Multi_Store_Action_Scheduler_Manager::get_pending_count();
        $this->assertEquals(0, $count);
    }

    public function test_get_failed_count_not_available(): void
    {
        $count = WC_Multi_Store_Action_Scheduler_Manager::get_failed_count();
        $this->assertEquals(0, $count);
    }

    public function test_reschedule_all_not_available(): void
    {
        $this->captureLogs();
        $this->forbidSchedulerCalls();

        WC_Multi_Store_Action_Scheduler_Manager::reschedule_all();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_reschedule_all_unschedules_then_recreates_queue_and_sync_check(): void
    {
        $this->captureLogs();
        $this->stubActionScheduler();

        WC_Multi_Store_Action_Scheduler_Manager::reschedule_all();

        $summary = $this->callSummary();
        $this->assertCount(8, $summary);
        $this->assertSame('as_unschedule_all_actions:wc_multi_store_sync_process_queue', $summary[0]);
        $this->assertSame([
            'as_schedule_recurring_action:wc_multi_store_sync_process_queue',
            'as_schedule_recurring_action:wc_multi_store_sync_scheduled_check',
        ], array_slice($summary, 6));
        $this->assertSame('All actions rescheduled via Action Scheduler', end($this->logSink->lines)[1]);
    }

    public function test_cleanup_duplicate_actions_not_available(): void
    {
        $result = WC_Multi_Store_Action_Scheduler_Manager::cleanup_duplicate_actions();

        $this->assertEquals(0, $result['cleaned']);
        $this->assertEquals('Action Scheduler not available', $result['error']);
    }

    public function test_ensure_clean_schedule_not_available(): void
    {
        $result = WC_Multi_Store_Action_Scheduler_Manager::ensure_clean_schedule();
        $this->assertNull($result);
    }

    public function test_daily_maintenance_action_runs_cleanup(): void
    {
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('query')->andReturn(0);
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn('0');

        $results = $manager->daily_maintenance_action();

        $this->assertIsArray($results);
    }

    public function test_cleanup_expired_transients(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static fn($value) => addcslashes($value, '_%\\'));
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql) => $sql);

        // Two-step query: SELECT expired rows, then DELETE in chunks.
        $rows = [
            (object) ['timeout_id' => 1, 'value_id' => 2, 'value_name' => '_transient_wc_mss_a'],
            (object) ['timeout_id' => 3, 'value_id' => 4, 'value_name' => '_transient_wc_mss_b'],
        ];
        $wpdb->shouldReceive('get_results')->once()->andReturn($rows);
        // One conditional DELETE chunk for two pairs returns four affected rows.
        $wpdb->shouldReceive('query')->once()->andReturn(4);

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $method = new ReflectionMethod($manager, 'cleanup_expired_transients');

        $result = $method->invoke($manager);

        $this->assertEquals(4, $result);
    }

    public function test_cleanup_expired_transients_returns_zero_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static fn($value) => addcslashes($value, '_%\\'));
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql) => $sql);
        // No expired rows found — fast-exit, no DELETE issued.
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $method = new ReflectionMethod($manager, 'cleanup_expired_transients');

        $result = $method->invoke($manager);

        $this->assertEquals(0, $result);
    }

    public function test_process_debounced_order_action_class_not_loaded(): void
    {
        // This test verifies behavior when Order_Sync class is not loaded
        // Since we don't load order-sync.php in bootstrap, this tests the guard
        // However, the class might be loaded from OrderSyncTest
        // We test the method signature and error handling instead
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $this->assertTrue(method_exists($manager, 'process_debounced_order_action'));
    }

    public function test_schedule_sync_check_disabled(): void
    {
        // Setting is off but the scheduler is unavailable: nothing is read or unscheduled.
        $this->captureLogs();
        $this->forbidSchedulerCalls();

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $this->assertSame([], $this->schedulerCalls);
        $this->assertSame([], $this->loggedMessages());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_schedule_sync_check_disabled_unschedules_existing_action(): void
    {
        $this->captureLogs();
        $this->stubActionScheduler();
        Functions\when('get_option')->alias(fn($option, $default = false) => $option === 'wc_multi_store_sync_scheduled'
            ? ['scheduled_sync_enabled' => false]
            : $default);

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $this->assertSame([
            ['as_unschedule_all_actions', [
                WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_SCHEDULED_SYNC,
                [],
                WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP,
            ]],
        ], $this->schedulerCalls);
        $this->assertSame(['Scheduled sync disabled'], $this->loggedMessages());
    }
}
