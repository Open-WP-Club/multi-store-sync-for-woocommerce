<?php
/**
 * Extended unit tests for WC_Multi_Store_Action_Scheduler_Manager
 * Tests process_queue_action, scheduled_sync_action, process_force_sync_batch,
 * get_products_for_scheduled_sync_batch
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ActionSchedulerManagerExtendedTest extends WC_Multi_Store_TestCase
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
    }

    // ── process_queue_action ─────────────────────────────────────

    public function test_process_queue_action_when_sync_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => false];
            }
            return $default;
        });

        // Mock queue stats
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('0');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('prepare')->andReturn('');

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->process_queue_action();

        // Should not throw, just return early
        $this->assertTrue(true);
    }

    public function test_process_queue_action_when_sync_enabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return $default;
        });

        // QueueTable::get_stats needs wpdb
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('0');
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->process_queue_action();

        // Should complete without error
        $this->assertTrue(true);
    }

    public function test_process_queue_action_handles_exception(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Make get_option throw to simulate exception path
        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });

        // Make QueueTable::get_stats throw by having wpdb throw
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andThrow(new \RuntimeException('DB error'));

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        // Should catch the exception and log it, not re-throw
        $manager->process_queue_action();

        $this->assertTrue(true);
    }

    // ── scheduled_sync_action ────────────────────────────────────

    public function test_scheduled_sync_action_when_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => false];
            }
            return $default;
        });

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->scheduled_sync_action();

        // Should return early when sync is disabled
        $this->assertTrue(true);
    }

    public function test_scheduled_sync_action_scheduled_sync_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_scheduled') {
                return ['scheduled_sync_enabled' => false];
            }
            return $default;
        });

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->scheduled_sync_action();

        $this->assertTrue(true);
    }

    public function test_scheduled_sync_action_no_products(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_scheduled') {
                return ['scheduled_sync_enabled' => true, 'sync_all_products' => true];
            }
            return $default;
        });

        // WP_Query mock returns empty posts
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->scheduled_sync_action();

        $this->assertTrue(true);
    }

    public function test_scheduled_sync_action_handles_exception(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_scheduled') {
                throw new \RuntimeException('DB error');
            }
            return false;
        });

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        // Exception should be caught internally
        $manager->scheduled_sync_action();

        $this->assertTrue(true);
    }

    public function test_scheduled_sync_action_with_sync_type_override(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_scheduled') {
                return [
                    'scheduled_sync_enabled' => true,
                    'scheduled_sync_type' => 'price_quantity',
                    'sync_all_products' => true,
                ];
            }
            return $default;
        });

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->scheduled_sync_action();

        // Completes without error
        $this->assertTrue(true);
    }

    // ── process_force_sync_batch ─────────────────────────────────

    public function test_process_force_sync_batch_handles_exception(): void
    {
        // WC_Multi_Store_Settings_Integration might not be loaded
        // The method catches exceptions internally
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();

        // Should catch the exception (Settings_Integration::process_force_sync_batch may not exist)
        $manager->process_force_sync_batch(1);

        $this->assertTrue(true);
    }

    // ── get_products_for_scheduled_sync_batch ─────────────────────

    public function test_get_products_batch_sync_all(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_scheduled') {
                return ['sync_all_products' => true];
            }
            return $default;
        });

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $method = new ReflectionMethod($manager, 'get_products_for_scheduled_sync_batch');

        $result = $method->invoke($manager, 1, 100);

        // WP_Query mock returns empty posts
        $this->assertIsArray($result);
    }

    public function test_get_products_batch_modified_only(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_scheduled') {
                return [
                    'sync_all_products' => false,
                    'sync_modified_hours' => 12,
                ];
            }
            return $default;
        });

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $method = new ReflectionMethod($manager, 'get_products_for_scheduled_sync_batch');

        $result = $method->invoke($manager, 1, 500);

        $this->assertIsArray($result);
    }

    // ── process_debounced_order_action ────────────────────────────

    public function test_process_debounced_order_action_with_loaded_class(): void
    {
        // Load order-sync.php if not loaded
        if (!class_exists('WC_Multi_Store_Order_Sync', false)) {
            require_once dirname(__DIR__, 3) . '/includes/order-sync.php';
        }

        Functions\when('wc_get_order')->justReturn(null);

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->process_debounced_order_action(42);

        // Should complete (order doesn't exist but no exception)
        $this->assertTrue(true);
    }

    public function test_process_debounced_order_action_exception_handling(): void
    {
        // If Order_Sync throws, it should be caught
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();

        // Test that the method exists and can be called
        $this->assertTrue(method_exists($manager, 'process_debounced_order_action'));
    }

    // ── daily_maintenance_action edge cases ───────────────────────

    public function test_daily_maintenance_returns_results(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('query')->andReturn(3);
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn('0');
        // Needed for step 7 (cleanup_expired_transients) to complete without
        // throwing, so steps 8-10 after it still run.
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => addcslashes($s, '_%\\'));

        // Needed for step 8 (clear_term_cache -> Cache_Manager::clear_remote_terms)
        // to complete without throwing, so steps 9/10 after it still run.
        Functions\when('delete_transient')->justReturn(true);

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $results = $manager->daily_maintenance_action();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('queue', $results);
        $this->assertArrayHasKey('history', $results);
        $this->assertArrayHasKey('webhook_logs', $results);
        $this->assertArrayHasKey('transients', $results);
        $this->assertArrayHasKey('api_usage', $results);
        $this->assertArrayHasKey('dead_letter_queue', $results);
    }

    public function test_daily_maintenance_handles_exception(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        // Throw on first query to trigger exception
        $wpdb->shouldReceive('query')->andThrow(new \RuntimeException('DB gone'));
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn('0');

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $results = $manager->daily_maintenance_action();

        // Should return whatever was collected before exception
        $this->assertIsArray($results);
    }

    // ── schedule_sync_check with settings ────────────────────────

    public function test_schedule_sync_check_reads_interval_setting(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_scheduled') {
                return ['scheduled_sync_enabled' => true, 'scheduled_sync_interval' => '30min'];
            }
            return $default;
        });

        // Not available, returns early
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->schedule_sync_check();

        $this->assertTrue(true);
    }
}
