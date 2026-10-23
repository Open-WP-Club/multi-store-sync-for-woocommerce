<?php
/**
 * Scheduling / unscheduling idempotence for WC_Multi_Store_Action_Scheduler_Manager.
 *
 * Action Scheduler functions are defined on the fly, which makes
 * function_exists() true for the rest of the process, so every test here runs
 * in its own process.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class ActionSchedulerIdempotenceTest extends WC_Multi_Store_TestCase
{
    private const G = 'wc_multi_store_sync';

    /** @var array<int, array{0: string, 1: array}> Ordered log of every as_* call. */
    private array $calls = [];
    /** @var array<string, int|false> hook => next scheduled timestamp */
    private array $scheduled = [];
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var array<string, mixed> */
    private array $transients = [];
    private object $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        $this->scheduled = [];
        $this->options = [];
        $this->transients = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
    }

    /** Define the Action Scheduler API and record every call. */
    private function defineScheduler(bool $withClass = true): void
    {
        Functions\when('as_schedule_recurring_action')->alias(function (...$a) {
            $this->calls[] = ['recurring', $a];
            return 1;
        });
        Functions\when('as_schedule_single_action')->alias(function (...$a) {
            $this->calls[] = ['single', $a];
            return 1;
        });
        Functions\when('as_unschedule_all_actions')->alias(function (...$a) {
            $this->calls[] = ['unschedule', $a];
            return null;
        });
        Functions\when('as_next_scheduled_action')->alias(function (...$a) {
            $this->calls[] = ['next', $a];
            return $this->scheduled[$a[0]] ?? false;
        });

        $this->store = new class {
            /** @var array<string, array<int, int>> */
            public array $pending = [];
            /** @var array<int, int> */
            public array $deleted = [];
            /** @var array<int, int> */
            public array $failOn = [];
            public array $queries = [];

            public function query_actions(array $args, string $mode = 'ids')
            {
                $this->queries[] = $args;
                return $this->pending[$args['hook'] ?? ''] ?? [];
            }

            public function delete_action($id): void
            {
                if (in_array($id, $this->failOn, true)) {
                    throw new \Exception('locked row');
                }
                $this->deleted[] = $id;
            }
        };

        if ($withClass) {
            if (!class_exists('ActionScheduler_Store', false)) {
                eval('class ActionScheduler_Store { const STATUS_PENDING = "pending"; const STATUS_FAILED = "failed"; }');
            }
            if (!class_exists('ActionScheduler', false)) {
                eval('class ActionScheduler { public static $store; public static function store() { return self::$store; } }');
            }
            \ActionScheduler::$store = $this->store;
        }
    }

    /** @return array<int, array> calls of one kind, optionally for one hook */
    private function calls(string $kind, ?string $hook = null): array
    {
        $out = [];
        foreach ($this->calls as $c) {
            if ($c[0] !== $kind) {
                continue;
            }
            if ($hook !== null && ($c[1][0] ?? null) !== $hook && ($c[1][2] ?? null) !== $hook) {
                continue;
            }
            $out[] = $c[1];
        }
        return $out;
    }

    // ── availability ───────────────────────────────────────────────

    public static function availabilityProvider(): array
    {
        return [
            'nothing defined'               => [false, false, false, false],
            'functions but no class'        => [true, true, false, false],
            'class and recurring only'      => [true, false, true, false],
            'class and single only'         => [false, true, true, false],
            'everything defined'            => [true, true, true, true],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('availabilityProvider')]
    public function test_is_available_requires_both_functions_and_the_class(bool $recurring, bool $single, bool $class, bool $expected): void
    {
        if ($recurring) {
            Functions\when('as_schedule_recurring_action')->justReturn(1);
        }
        if ($single) {
            Functions\when('as_schedule_single_action')->justReturn(1);
        }
        if ($class) {
            eval('class ActionScheduler {}');
        }

        $this->assertSame($expected, WC_Multi_Store_Action_Scheduler_Manager::is_available());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_every_scheduler_entry_point_is_a_noop_when_action_scheduler_is_missing(): void
    {
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->schedule_queue_processor();
        $manager->schedule_sync_check();
        $manager->schedule_maintenance();
        $manager->ensure_scheduled();
        WC_Multi_Store_Action_Scheduler_Manager::reschedule_sync_check();
        WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(5);
        WC_Multi_Store_Action_Scheduler_Manager::unschedule_all();
        WC_Multi_Store_Action_Scheduler_Manager::reschedule_all();

        $this->assertSame(['cleaned' => 0, 'error' => 'Action Scheduler not available'], WC_Multi_Store_Action_Scheduler_Manager::cleanup_duplicate_actions());
        $this->assertNull(WC_Multi_Store_Action_Scheduler_Manager::ensure_clean_schedule());
        $this->assertSame(0, WC_Multi_Store_Action_Scheduler_Manager::get_pending_count());
        $this->assertSame(0, WC_Multi_Store_Action_Scheduler_Manager::get_failed_count());
        $this->assertSame([], $this->transients, 'The throttle must not be armed while nothing could be scheduled.');
        $this->assertFalse(function_exists('as_schedule_recurring_action'));
    }

    // ── schedule_queue_processor / maintenance ─────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_queue_processor_is_scheduled_every_minute_in_the_plugin_group(): void
    {
        $this->defineScheduler();
        $before = time();

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_queue_processor();

        $recurring = $this->calls('recurring');
        $this->assertCount(1, $recurring);
        [$start, $interval, $hook, $args, $group] = $recurring[0];
        $this->assertEqualsWithDelta($before, $start, 5);
        $this->assertSame(60, $interval);
        $this->assertSame('wc_multi_store_sync_process_queue', $hook);
        $this->assertSame([], $args);
        $this->assertSame(self::G, $group);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_maintenance_runs_daily_starting_in_the_future(): void
    {
        $this->defineScheduler();

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_maintenance();

        [$start, $interval, $hook, $args, $group] = $this->calls('recurring')[0];
        $this->assertGreaterThan(time(), $start, 'First maintenance run must not be in the past.');
        $this->assertLessThanOrEqual(time() + 86400 + 3600, $start);
        $this->assertSame(86400, $interval);
        $this->assertSame('wc_multi_store_sync_daily_maintenance', $hook);
        $this->assertSame(self::G, $group);
    }

    // ── schedule_sync_check / intervals ────────────────────────────

    public static function intervalProvider(): array
    {
        return [
            '10min'       => ['10min', 600],
            '30min'       => ['30min', 1800],
            'hourly'      => ['hourly', 3600],
            'daily'       => ['daily', 86400],
            'unknown'     => ['every-blue-moon', 600],
            'empty'       => ['', 600],
            'wrong case'  => ['HOURLY', 600],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('intervalProvider')]
    public function test_sync_check_interval_mapping_with_safe_default(string $setting, int $seconds): void
    {
        $this->defineScheduler();
        $this->options['wc_multi_store_sync_scheduled'] = ['scheduled_sync_interval' => $setting];

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $recurring = $this->calls('recurring');
        $this->assertCount(1, $recurring);
        $this->assertSame($seconds, $recurring[0][1]);
        $this->assertSame('wc_multi_store_sync_scheduled_check', $recurring[0][2]);
        $this->assertSame(self::G, $recurring[0][4]);
    }

    public static function brokenSettingsProvider(): array
    {
        return [
            'option missing (false)' => [false],
            'empty string'           => [''],
            'garbage string'         => ['nonsense'],
            'null'                   => [null],
            'empty array'            => [[]],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('brokenSettingsProvider')]
    public function test_corrupt_schedule_settings_fall_back_to_enabled_ten_minutes(mixed $value): void
    {
        $this->defineScheduler();
        $this->options['wc_multi_store_sync_scheduled'] = $value;

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $this->assertCount(1, $this->calls('recurring'));
        $this->assertSame(600, $this->calls('recurring')[0][1]);
        $this->assertSame([], $this->calls('unschedule'));
    }

    public static function disabledValues(): array
    {
        return [
            'false'        => [false],
            'zero'         => [0],
            'string zero'  => ['0'],
            'empty string' => [''],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('disabledValues')]
    public function test_disabled_scheduled_sync_unschedules_and_never_schedules(mixed $flag): void
    {
        $this->defineScheduler();
        $this->options['wc_multi_store_sync_scheduled'] = ['scheduled_sync_enabled' => $flag];

        (new WC_Multi_Store_Action_Scheduler_Manager())->schedule_sync_check();

        $this->assertSame([], $this->calls('recurring'));
        $this->assertSame([['wc_multi_store_sync_scheduled_check', [], self::G]], $this->calls('unschedule'));
    }

    // ── reschedule_sync_check ──────────────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_reschedule_sync_check_unschedules_before_scheduling_with_new_interval(): void
    {
        $this->defineScheduler();
        $this->options['wc_multi_store_sync_scheduled'] = ['scheduled_sync_interval' => 'hourly'];

        WC_Multi_Store_Action_Scheduler_Manager::reschedule_sync_check();

        $this->assertSame(['unschedule', 'recurring'], array_column($this->calls, 0));
        $this->assertSame(['wc_multi_store_sync_scheduled_check', [], self::G], $this->calls('unschedule')[0]);
        $this->assertSame(3600, $this->calls('recurring')[0][1]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_reschedule_sync_check_called_repeatedly_always_clears_before_each_schedule(): void
    {
        $this->defineScheduler();

        for ($i = 0; $i < 3; $i++) {
            WC_Multi_Store_Action_Scheduler_Manager::reschedule_sync_check();
        }

        $kinds = array_column($this->calls, 0);
        $this->assertSame(['unschedule', 'recurring', 'unschedule', 'recurring', 'unschedule', 'recurring'], $kinds, 'Every schedule must be preceded by an unschedule so only one recurring action survives.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_reschedule_sync_check_when_disabled_leaves_nothing_scheduled(): void
    {
        $this->defineScheduler();
        $this->options['wc_multi_store_sync_scheduled'] = ['scheduled_sync_enabled' => false];

        WC_Multi_Store_Action_Scheduler_Manager::reschedule_sync_check();

        $this->assertSame([], $this->calls('recurring'));
        $this->assertNotEmpty($this->calls('unschedule'));
    }

    // ── debounced order ────────────────────────────────────────────

    public static function delayProvider(): array
    {
        return [
            'default' => [null, 60],
            'custom'  => [300, 300],
            'zero'    => [0, 0],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('delayProvider')]
    public function test_debounced_order_replaces_pending_action_for_same_order(?int $delay, int $expectedDelay): void
    {
        $this->defineScheduler();
        $before = time();

        if ($delay === null) {
            WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(77);
        } else {
            WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(77, $delay);
        }

        $this->assertSame(['unschedule', 'single'], array_column($this->calls, 0), 'Cancel first, then schedule - never the reverse.');
        $this->assertSame(['wc_multi_store_sync_process_debounced_order', ['order_id' => 77], self::G], $this->calls('unschedule')[0]);
        [$when, $hook, $args, $group] = $this->calls('single')[0];
        $this->assertEqualsWithDelta($before + $expectedDelay, $when, 5);
        $this->assertSame('wc_multi_store_sync_process_debounced_order', $hook);
        $this->assertSame(['order_id' => 77], $args, 'Unschedule and schedule must use identical args or the old action survives.');
        $this->assertSame(self::G, $group);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_debounced_orders_for_different_orders_do_not_cancel_each_other(): void
    {
        $this->defineScheduler();

        WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(1);
        WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order(2);

        $unscheduled = array_map(static fn(array $c): array => $c[1], $this->calls('unschedule'));
        $this->assertSame([['order_id' => 1], ['order_id' => 2]], $unscheduled);
    }

    // ── unschedule_all / reschedule_all ────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_unschedule_all_clears_every_recurring_hook_but_not_debounced_orders(): void
    {
        $this->defineScheduler();

        WC_Multi_Store_Action_Scheduler_Manager::unschedule_all();
        WC_Multi_Store_Action_Scheduler_Manager::unschedule_all(); // idempotent

        $hooks = array_map(static fn(array $c): string => $c[0], $this->calls('unschedule'));
        $expected = [
            'wc_multi_store_sync_process_queue',
            'wc_multi_store_sync_scheduled_check',
            'wc_multi_store_sync_daily_maintenance',
            'wc_multi_store_sync_remote_orders',
            'wc_multi_store_health_check',
            'wc_mss_send_daily_summary',
        ];
        $this->assertSame(array_merge($expected, $expected), $hooks);
        $this->assertNotContains('wc_multi_store_sync_process_debounced_order', $hooks, 'In-flight debounced orders must survive.');
        $this->assertSame([], $this->calls('recurring'));
        foreach ($this->calls('unschedule') as $c) {
            $this->assertSame([], $c[1]);
            $this->assertSame(self::G, $c[2]);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_reschedule_all_unschedules_everything_before_creating_queue_and_sync_actions(): void
    {
        $this->defineScheduler();

        WC_Multi_Store_Action_Scheduler_Manager::reschedule_all();

        $kinds = array_column($this->calls, 0);
        $firstRecurring = array_search('recurring', $kinds, true);
        $lastUnscheduleBefore = max(array_keys(array_filter(array_slice($kinds, 0, $firstRecurring), static fn($k) => $k === 'unschedule')));
        $this->assertSame($firstRecurring - 1, $lastUnscheduleBefore);
        $hooks = array_map(static fn(array $c): string => $c[2], $this->calls('recurring'));
        $this->assertSame(['wc_multi_store_sync_process_queue', 'wc_multi_store_sync_scheduled_check'], $hooks);
    }

    // ── ensure_scheduled ───────────────────────────────────────────

    /** Mark every hook ensure_scheduled() inspects as already scheduled except those given. */
    private function everythingScheduledExcept(array $missing = []): void
    {
        foreach ([
            'wc_multi_store_sync_process_queue', 'wc_multi_store_sync_scheduled_check', 'wc_multi_store_sync_daily_maintenance',
            'wc_multi_store_weekly_verification', 'wc_multi_store_sync_remote_orders',
        ] as $hook) {
            $this->scheduled[$hook] = in_array($hook, $missing, true) ? false : time() + 100;
        }
        if (class_exists('WC_Multi_Store_Orphan_Cleanup', false)) {
            $this->scheduled[WC_Multi_Store_Orphan_Cleanup::AUTO_TRASH_HOOK] = false;
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_ensure_scheduled_creates_nothing_when_everything_is_already_scheduled(): void
    {
        $this->defineScheduler();
        $this->everythingScheduledExcept();

        (new WC_Multi_Store_Action_Scheduler_Manager())->ensure_scheduled();

        $this->assertSame([], $this->calls('recurring'));
        $this->assertSame([], $this->calls('single'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_ensure_scheduled_creates_only_the_missing_core_actions(): void
    {
        $this->defineScheduler();
        $this->everythingScheduledExcept(['wc_multi_store_sync_process_queue', 'wc_multi_store_sync_daily_maintenance']);

        (new WC_Multi_Store_Action_Scheduler_Manager())->ensure_scheduled();

        $hooks = array_map(static fn(array $c): string => $c[2], $this->calls('recurring'));
        $this->assertSame(['wc_multi_store_sync_process_queue', 'wc_multi_store_sync_daily_maintenance'], $hooks);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_ensure_scheduled_is_throttled_for_five_minutes_and_second_call_does_no_lookups(): void
    {
        $this->defineScheduler();
        $this->everythingScheduledExcept();
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();

        $manager->ensure_scheduled();
        $lookups = count($this->calls('next'));
        $this->assertGreaterThan(0, $lookups);
        $this->assertArrayHasKey('wc_mss_ensure_scheduled', $this->transients);

        $manager->ensure_scheduled();
        $this->assertCount($lookups, $this->calls('next'), 'Throttled call must not hit the Action Scheduler tables.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_ensure_scheduled_repairs_a_deleted_queue_processor_on_the_next_unthrottled_run(): void
    {
        $this->defineScheduler();
        $this->everythingScheduledExcept();
        $manager = new WC_Multi_Store_Action_Scheduler_Manager();
        $manager->ensure_scheduled();
        $this->assertSame([], $this->calls('recurring'));

        // Someone purged the queue-processor action; throttle window expires.
        $this->scheduled['wc_multi_store_sync_process_queue'] = false;
        unset($this->transients['wc_mss_ensure_scheduled']);
        $manager->ensure_scheduled();

        $this->assertCount(1, $this->calls('recurring'));
        $this->assertSame('wc_multi_store_sync_process_queue', $this->calls('recurring')[0][2]);
    }

    // ── cleanup_duplicate_actions / ensure_clean_schedule ──────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cleanup_keeps_earliest_action_and_deletes_the_rest_per_hook(): void
    {
        $this->defineScheduler();
        $this->store->pending['wc_multi_store_sync_process_queue'] = [11, 12, 13];
        $this->store->pending['wc_multi_store_sync_scheduled_check'] = [21, 22];

        $result = WC_Multi_Store_Action_Scheduler_Manager::cleanup_duplicate_actions();

        $this->assertSame(3, $result['cleaned']);
        $this->assertSame([12, 13, 22], $this->store->deleted);
        $this->assertSame([
            'wc_multi_store_sync_process_queue' => 'Removed 2 duplicate(s)',
            'wc_multi_store_sync_scheduled_check' => 'Removed 1 duplicate(s)',
        ], $result['details']);
        $q = $this->store->queries[0];
        $this->assertSame('pending', $q['status'], 'Only pending actions may be pruned; running/complete ones are history.');
        $this->assertSame('ASC', $q['order']);
        $this->assertSame('date', $q['orderby']);
        $this->assertSame(-1, $q['per_page']);
        $this->assertSame(self::G, $q['group']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cleanup_is_idempotent_when_zero_or_one_action_exists(): void
    {
        $this->defineScheduler();
        $this->store->pending['wc_multi_store_sync_process_queue'] = [11];

        $first = WC_Multi_Store_Action_Scheduler_Manager::cleanup_duplicate_actions();
        $second = WC_Multi_Store_Action_Scheduler_Manager::cleanup_duplicate_actions();

        $this->assertSame(['cleaned' => 0, 'details' => []], $first);
        $this->assertSame($first, $second);
        $this->assertSame([], $this->store->deleted);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cleanup_counts_only_successful_deletes_and_continues_after_a_failure(): void
    {
        $this->defineScheduler();
        $this->store->pending['wc_multi_store_sync_process_queue'] = [11, 12, 13, 14];
        $this->store->failOn = [12];

        $result = WC_Multi_Store_Action_Scheduler_Manager::cleanup_duplicate_actions();

        $this->assertSame(2, $result['cleaned']);
        $this->assertSame([13, 14], $this->store->deleted);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_ensure_clean_schedule_prunes_duplicates_then_schedules_missing_actions_only(): void
    {
        $this->defineScheduler();
        $this->store->pending['wc_multi_store_sync_process_queue'] = [1, 2];
        $this->scheduled['wc_multi_store_sync_process_queue'] = time() + 30;
        $this->scheduled['wc_multi_store_sync_scheduled_check'] = false;

        $result = WC_Multi_Store_Action_Scheduler_Manager::ensure_clean_schedule();

        $this->assertSame(1, $result['cleaned']);
        $hooks = array_map(static fn(array $c): string => $c[2], $this->calls('recurring'));
        $this->assertSame(['wc_multi_store_sync_scheduled_check'], $hooks);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pending_and_failed_counts_query_the_plugin_group_only(): void
    {
        $this->defineScheduler();
        $this->store = new class {
            public array $queries = [];
            public function query_actions(array $args, string $mode = 'ids')
            {
                $this->queries[] = [$args, $mode];
                return 7;
            }
        };
        \ActionScheduler::$store = $this->store;

        $this->assertSame(7, WC_Multi_Store_Action_Scheduler_Manager::get_pending_count());
        $this->assertSame(7, WC_Multi_Store_Action_Scheduler_Manager::get_failed_count());

        $this->assertSame(['group' => self::G, 'status' => 'pending', 'per_page' => -1], $this->store->queries[0][0]);
        $this->assertSame(['group' => self::G, 'status' => 'failed', 'per_page' => -1], $this->store->queries[1][0]);
        $this->assertSame('count', $this->store->queries[0][1]);
    }
}
