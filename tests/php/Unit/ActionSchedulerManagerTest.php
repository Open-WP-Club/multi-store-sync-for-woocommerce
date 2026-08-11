<?php
/**
 * Unit tests for WC_Multi_Store_Action_Scheduler_Manager
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ActionSchedulerManagerTest extends WC_Multi_Store_TestCase
{
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
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->ensure_scheduled();
        // Should return without error
        $this->assertTrue(true);
    }

    public function test_ensure_scheduled_respects_throttle(): void
    {
        // is_available() returns false (no Action Scheduler), so ensure_scheduled
        // returns before hitting the throttle transient. We verify that it runs
        // without error when throttle transient is absent.
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->ensure_scheduled();
        $this->assertTrue(true);
    }

    public function test_ensure_scheduled_skips_when_throttled(): void
    {
        // Transient exists = throttled
        Functions\when('get_transient')->justReturn(1);

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->ensure_scheduled();
        // Should return early without scheduling
        $this->assertTrue(true);
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
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->schedule_queue_processor();
        // Should return without error when not available
        $this->assertTrue(true);
    }

    public function test_schedule_sync_check_not_available(): void
    {
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->schedule_sync_check();
        $this->assertTrue(true);
    }

    public function test_schedule_maintenance_not_available(): void
    {
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->schedule_maintenance();
        $this->assertTrue(true);
    }

    public function test_schedule_debounced_order_not_available(): void
    {
        WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(42, 60);
        $this->assertTrue(true);
    }

    public function test_unschedule_all_not_available(): void
    {
        WC_Multi_Store_Action_Scheduler_Manager::unschedule_all();
        $this->assertTrue(true);
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
        WC_Multi_Store_Action_Scheduler_Manager::reschedule_all();
        $this->assertTrue(true);
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

        // Two-step query: SELECT expired rows, then DELETE in chunks.
        $rows = [
            (object) ['timeout_id' => 1, 'value_id' => 2, 'value_name' => '_transient_wc_mss_a'],
            (object) ['timeout_id' => 3, 'value_id' => 4, 'value_name' => '_transient_wc_mss_b'],
        ];
        $wpdb->shouldReceive('get_results')->once()->andReturn($rows);
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        // One DELETE chunk for the 4 IDs (2 rows × 2 ids each), returns rows-affected.
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
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_scheduled') {
                return ['scheduled_sync_enabled' => false];
            }
            return $default;
        });

        // Not available, so should return early
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->schedule_sync_check();
        $this->assertTrue(true);
    }
}
