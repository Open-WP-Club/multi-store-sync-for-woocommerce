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
    /** @var object{lines: list<array{0: string, 1: string}>} */
    private object $logSink;

    /** @var list<array{0: string, 1: array}> update_option() writes. */
    private array $optionWrites = [];

    private mixed $originalQueueManager;

    protected function tearDown(): void
    {
        WC_MSS()->queue_manager = $this->originalQueueManager;
        WP_Query::$resultsQueue = null;
        parent::tearDown();
    }

    /** Capture logger output and update_option() writes. */
    private function captureSideEffects(): void
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
        $this->optionWrites = [];
        Functions\when('update_option')->alias(function (...$args) {
            $this->optionWrites[] = $args;
            return true;
        });
    }

    /** @return list<string> */
    private function loggedMessages(): array
    {
        return array_column($this->logSink->lines, 1);
    }

    /** Swap WC_MSS()->queue_manager for a Mockery double (restored in tearDown). */
    private function mockQueueManager(): \Mockery\MockInterface
    {
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        WC_MSS()->queue_manager = $queue;
        return $queue;
    }

    /** Make Queue_Table::get_stats() return the given counts. */
    private function mockQueueStats(int $pending, int $processing): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_row')->andReturn(['pending' => $pending, 'processing' => $processing]);
    }

    /** @param array<string, mixed> $scheduled wc_multi_store_sync_scheduled option (null = unset) */
    private function stubSettings(bool $enabled, ?array $scheduled = null): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) use ($enabled, $scheduled) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => $enabled];
            }
            if ($option === 'wc_multi_store_sync_scheduled' && $scheduled !== null) {
                return $scheduled;
            }
            return $default;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalQueueManager = WC_MSS()->queue_manager;
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
        $this->captureSideEffects();
        $this->stubSettings(false);
        $this->mockQueueStats(3, 1);
        $this->mockQueueManager()->shouldNotReceive('process_queue');

        (new WC_Multi_Store_Action_Scheduler_Manager())->process_queue_action();

        $this->assertSame([
            'Queue processor triggered: 3 pending, 1 processing',
            'Queue processor: Sync is disabled, skipping',
        ], $this->loggedMessages());
        $this->assertSame([], $this->optionWrites, 'A disabled run must not record a last-run timestamp');
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_process_queue_action_when_sync_enabled(): void
    {
        $this->captureSideEffects();
        $this->stubSettings(true);
        $this->mockQueueStats(0, 0);
        $this->mockQueueManager()
            ->shouldReceive('process_queue')
            ->once()
            ->andReturn(['status' => 'completed', 'processed' => 5, 'success' => 4, 'failed' => 1]);

        $before = time();
        (new WC_Multi_Store_Action_Scheduler_Manager())->process_queue_action();

        $this->assertSame(['Queue processor completed: 5 processed, 4 successful, 1 failed'], $this->loggedMessages());
        $this->assertCount(1, $this->optionWrites);
        $this->assertSame('wc_multi_store_sync_last_queue_run', $this->optionWrites[0][0]);
        $this->assertGreaterThanOrEqual($before, $this->optionWrites[0][1]);
        $this->assertFalse($this->optionWrites[0][2], 'Last-run timestamp must not be autoloaded');
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_process_queue_action_handles_exception(): void
    {
        $this->captureSideEffects();
        $this->stubSettings(true);
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_row')->andThrow(new \RuntimeException('DB error'));
        $this->mockQueueManager()->shouldNotReceive('process_queue');

        (new WC_Multi_Store_Action_Scheduler_Manager())->process_queue_action();

        $this->assertCount(1, $this->logSink->lines);
        $this->assertSame('error', $this->logSink->lines[0][0]);
        $this->assertStringStartsWith('Queue processor failed with exception: DB error in ', $this->logSink->lines[0][1]);
        $this->assertSame([], $this->optionWrites, 'A failed run must not record a last-run timestamp');
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    // ── scheduled_sync_action ────────────────────────────────────

    public function test_scheduled_sync_action_when_disabled(): void
    {
        $this->captureSideEffects();
        $this->stubSettings(false, ['scheduled_sync_enabled' => true]);
        WP_Query::$resultsQueue = [[1, 2, 3]];
        $this->mockQueueManager()->shouldNotReceive('add_products');

        (new WC_Multi_Store_Action_Scheduler_Manager())->scheduled_sync_action();

        $this->assertSame([], $this->loggedMessages());
        $this->assertSame([], $this->optionWrites);
        $this->assertCount(1, WP_Query::$resultsQueue, 'Products must not even be queried when sync is disabled');
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_scheduled_sync_action_scheduled_sync_disabled(): void
    {
        $this->captureSideEffects();
        $this->stubSettings(true, ['scheduled_sync_enabled' => false]);
        WP_Query::$resultsQueue = [[1, 2, 3]];
        $this->mockQueueManager()->shouldNotReceive('add_products');

        (new WC_Multi_Store_Action_Scheduler_Manager())->scheduled_sync_action();

        $this->assertSame([], $this->loggedMessages());
        $this->assertSame([], $this->optionWrites);
        $this->assertCount(1, WP_Query::$resultsQueue);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_scheduled_sync_action_no_products(): void
    {
        $this->captureSideEffects();
        $this->stubSettings(true, ['scheduled_sync_enabled' => true, 'sync_all_products' => true]);
        WP_Query::$resultsQueue = [[]];
        $this->mockQueueManager()->shouldNotReceive('add_products');

        (new WC_Multi_Store_Action_Scheduler_Manager())->scheduled_sync_action();

        $this->assertSame([
            'Scheduled sync check started (Action Scheduler) - Sync type: default from settings',
            'Scheduled sync: No products to sync',
        ], $this->loggedMessages());
        $this->assertCount(1, $this->optionWrites);
        $this->assertSame('wc_multi_store_sync_last_scheduled_run', $this->optionWrites[0][0]);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_scheduled_sync_action_handles_exception(): void
    {
        $this->captureSideEffects();
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
        $this->mockQueueManager()->shouldNotReceive('add_products');

        (new WC_Multi_Store_Action_Scheduler_Manager())->scheduled_sync_action();

        $this->assertCount(1, $this->logSink->lines);
        $this->assertSame('error', $this->logSink->lines[0][0]);
        $this->assertStringStartsWith('Scheduled sync failed with exception: DB error in ', $this->logSink->lines[0][1]);
        $this->assertSame([], $this->optionWrites);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_scheduled_sync_action_with_sync_type_override(): void
    {
        $this->captureSideEffects();
        $this->stubSettings(true, [
            'scheduled_sync_enabled' => true,
            'scheduled_sync_type' => 'price_quantity',
            'sync_all_products' => true,
        ]);
        WP_Query::$resultsQueue = [[11, 12]];
        $this->mockQueueManager()
            ->shouldReceive('add_products')
            ->once()
            ->with([11, 12], 'scheduled_sync', 5, 'price_quantity')
            ->andReturn(2);

        (new WC_Multi_Store_Action_Scheduler_Manager())->scheduled_sync_action();

        $this->assertSame([
            'Scheduled sync check started (Action Scheduler) - Sync type: price_quantity',
            'Scheduled sync: 2 product(s) added to queue (Type: price_quantity)',
        ], $this->loggedMessages());
        $this->assertSame('wc_multi_store_sync_last_scheduled_run', $this->optionWrites[0][0]);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_scheduled_sync_action_default_sync_type_passes_no_override(): void
    {
        $this->captureSideEffects();
        $this->stubSettings(true, ['scheduled_sync_enabled' => true, 'scheduled_sync_type' => 'use_default']);
        WP_Query::$resultsQueue = [[7]];
        $this->mockQueueManager()
            ->shouldReceive('add_products')
            ->once()
            ->with([7], 'scheduled_sync', 5, null)
            ->andReturn(1);

        (new WC_Multi_Store_Action_Scheduler_Manager())->scheduled_sync_action();

        $this->assertContains('Scheduled sync: 1 product(s) added to queue (Type: default)', $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    // ── process_force_sync_batch ─────────────────────────────────

    public function test_process_force_sync_batch_handles_exception(): void
    {
        // A throw while queueing must be contained and logged: an uncaught
        // exception would make Action Scheduler mark the action failed and
        // stop the force-sync batch chain.
        $this->captureSideEffects();
        WP_Query::$resultsQueue = [[21]];
        $this->mockQueueManager()->shouldReceive('add_product')->once()->andThrow(new \RuntimeException('queue down'));

        (new WC_Multi_Store_Action_Scheduler_Manager())->process_force_sync_batch(4);

        $this->assertCount(1, $this->logSink->lines);
        $this->assertSame('error', $this->logSink->lines[0][0]);
        $this->assertStringStartsWith('Force sync batch failed for page 4: queue down in ', $this->logSink->lines[0][1]);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_process_force_sync_batch_delegates_and_queues_products(): void
    {
        $this->captureSideEffects();
        WP_Query::$resultsQueue = [[21, 22]];
        $this->mockQueueManager()
            ->shouldReceive('add_product')->once()->with(21, 'force_sync', 1)
            ->shouldReceive('add_product')->once()->with(22, 'force_sync', 1);

        (new WC_Multi_Store_Action_Scheduler_Manager())->process_force_sync_batch(1);

        $this->assertContains('Force sync complete: 2 products queued total', $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
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
        WP_Query::$resultsQueue = [[3, 4, 5]];

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $method = new ReflectionMethod($manager, 'get_products_for_scheduled_sync_batch');

        $this->assertSame([3, 4, 5], $method->invoke($manager, 1, 100));
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
        // The stub WP_Query does not record its args, so only the returned IDs
        // (the query result passed straight through) can be verified here.
        WP_Query::$resultsQueue = [[8, 9]];

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $method = new ReflectionMethod($manager, 'get_products_for_scheduled_sync_batch');

        $this->assertSame([8, 9], $method->invoke($manager, 1, 500));
    }

    // ── process_debounced_order_action ────────────────────────────

    public function test_process_debounced_order_action_with_loaded_class(): void
    {
        if (!class_exists('WC_Multi_Store_Order_Sync', false)) {
            require_once dirname(__DIR__, 3) . '/includes/order-sync.php';
        }
        $this->captureSideEffects();
        $key = WC_Multi_Store_Order_Sync::DEBOUNCE_PREFIX . '42';
        Functions\when('get_transient')->alias(fn($k) => $k === $key ? [5, 6] : false);
        $deleted = [];
        Functions\when('delete_transient')->alias(function ($k) use (&$deleted) {
            $deleted[] = $k;
            return true;
        });
        $this->mockQueueManager()
            ->shouldReceive('add_products')
            ->once()
            ->with([5, 6], 'order_42_debounced', 10);

        (new WC_Multi_Store_Action_Scheduler_Manager())->process_debounced_order_action(42);

        $this->assertSame([$key], $deleted, 'The debounce transient is cleared once products are queued');
        $this->assertSame(
            ['Order #42: Debouncing complete, 2 product(s) queued for sync (will be processed by next cron run)'],
            $this->loggedMessages()
        );
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_process_debounced_order_action_exception_handling(): void
    {
        if (!class_exists('WC_Multi_Store_Order_Sync', false)) {
            require_once dirname(__DIR__, 3) . '/includes/order-sync.php';
        }
        $this->captureSideEffects();
        Functions\when('get_transient')->justReturn([5]);
        $deleted = 0;
        Functions\when('delete_transient')->alias(function () use (&$deleted) {
            $deleted++;
            return true;
        });
        $this->mockQueueManager()->shouldReceive('add_products')->andThrow(new \RuntimeException('boom'));

        (new WC_Multi_Store_Action_Scheduler_Manager())->process_debounced_order_action(42);

        $this->assertCount(1, $this->logSink->lines);
        $this->assertSame('error', $this->logSink->lines[0][0]);
        $this->assertStringStartsWith('Debounced order sync failed for order #42: boom in ', $this->logSink->lines[0][1]);
        $this->assertSame(0, $deleted, 'The debounce transient must survive a failed queueing so it can retry');
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
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

        $this->captureSideEffects();

        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $results = $manager->daily_maintenance_action();

        // The first cleanup step throws, so nothing was collected and the failure is logged.
        $this->assertSame([], $results);
        $this->assertSame('error', end($this->logSink->lines)[0]);
        $this->assertStringStartsWith('Daily maintenance failed with exception: DB gone in ', end($this->logSink->lines)[1]);
    }

    // ── schedule_sync_check with settings ────────────────────────

    public function test_schedule_sync_check_reads_interval_setting(): void
    {
        // Scheduler unavailable in this process: the interval setting is not
        // even read and nothing is scheduled. (Available-path interval handling
        // is covered by ActionSchedulerManagerTest::test_schedule_sync_check_uses_configured_interval.)
        $this->captureSideEffects();
        $reads = [];
        Functions\when('get_option')->alias(function ($option, $default = false) use (&$reads) {
            $reads[] = $option;
            return $default;
        });
        $scheduled = [];
        foreach (['as_schedule_recurring_action', 'as_unschedule_all_actions'] as $fn) {
            Functions\when($fn)->alias(function () use (&$scheduled, $fn) {
                $scheduled[] = $fn;
            });
        }

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $this->assertSame([], $scheduled);
        $this->assertNotContains('wc_multi_store_sync_scheduled', $reads);
        $this->assertSame([], $this->loggedMessages());
    }
}
