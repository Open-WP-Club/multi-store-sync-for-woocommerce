<?php
/**
 * Tests for WC_Multi_Store_Hooks::on_weekly_verification,
 * ::on_async_verification_batch and the private static load_settings() cache.
 *
 * The real Weekly_Sync_Verifier / Scheduler classes run; only WordPress,
 * Action Scheduler and WooCommerce's logger are stubbed.
 */

use Brain\Monkey\Functions;

class HooksVerificationHandlersTest extends WC_Multi_Store_TestCase
{
    private const PROGRESS = 'wc_mss_async_verification_progress';

    private WC_Multi_Store_Hooks $hooks;

    /** @var list<array{string, string}> [level, message] */
    private array $logs = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var list<string> */
    private array $deletedTransients = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array> */
    private array $recurring = [];

    /** @var list<array> */
    private array $single = [];

    private int $unscheduleCalls = 0;

    private mixed $nextScheduled = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
        WC_Multi_Store_Hooks::clear_settings_cache();

        $this->logs = [];
        $this->transients = [];
        $this->deletedTransients = [];
        $this->recurring = [];
        $this->single = [];
        $this->unscheduleCalls = 0;
        $this->nextScheduled = 1700000000;
        $this->options = [
            'wc_multi_store_sync_settings' => ['enabled' => true, 'sync_type_default' => 'full_product'],
            'wc_multi_store_sync_stores' => [],
            'wc_multi_store_sync_weekly_verification' => [],
        ];
        WP_Query::$resultsQueue = null;

        $logger = \Mockery::mock();
        $logger->shouldReceive('log')->andReturnUsing(function ($level, $message) {
            $this->logs[] = [$level, $message];
        });
        Functions\when('wc_get_logger')->justReturn($logger);

        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($k) {
            $this->deletedTransients[] = $k;
            unset($this->transients[$k]);
            return true;
        });
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_parse_args')->alias(fn($a, $d) => array_merge($d, (array) $a));
        Functions\when('as_next_scheduled_action')->alias(fn() => $this->nextScheduled);
        Functions\when('as_unschedule_all_actions')->alias(function () {
            $this->unscheduleCalls++;
        });
        Functions\when('as_schedule_recurring_action')->alias(function (...$args) {
            $this->recurring[] = $args;
            return 11;
        });
        Functions\when('as_schedule_single_action')->alias(function (...$args) {
            $this->single[] = $args;
            return 77;
        });

        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 5]);

        $this->hooks = new WC_Multi_Store_Hooks();
        WC_Multi_Store_Hooks::clear_settings_cache();
    }

    protected function tearDown(): void
    {
        WP_Query::$resultsQueue = null;
        parent::tearDown();
    }

    /** @return list<string> */
    private function messages(?string $level = null): array
    {
        $out = [];
        foreach ($this->logs as [$l, $m]) {
            if ($level === null || $l === $level) {
                $out[] = $m;
            }
        }
        return $out;
    }

    private function assertLogged(string $needle, ?string $level = null): void
    {
        foreach ($this->messages($level) as $m) {
            if (str_contains($m, $needle)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail(sprintf('Expected a %s log containing "%s"; got: %s', $level ?? 'any', $needle, implode(' | ', $this->messages($level))));
    }

    private function assertNotLogged(string $needle): void
    {
        foreach ($this->messages() as $m) {
            $this->assertStringNotContainsString($needle, $m);
        }
    }

    // ---- on_weekly_verification ------------------------------------------

    public function test_weekly_verification_schedules_async_run_and_logs_success(): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://a.com' => ['status' => 'active']];
        WP_Query::$resultsQueue = [[1, 2, 3, 4, 5]];

        $this->hooks->on_weekly_verification();

        $this->assertLogged('Weekly verification triggered via Action Scheduler');
        $this->assertLogged('Weekly verification scheduled: Verification started: 5 products will be checked in 1 batches.');
        $this->assertNotLogged('could not start');
        $this->assertCount(1, $this->single, 'First batch action must be scheduled');
        $this->assertSame('wc_multi_store_sync', $this->single[0][3]);
        $this->assertSame([0], $this->single[0][2]);
        $this->assertSame('running', $this->transients[self::PROGRESS]['status']);
        $this->assertSame([1, 2, 3, 4, 5], $this->transients[self::PROGRESS]['product_ids']);
    }

    public function test_weekly_verification_logs_warning_when_no_active_stores(): void
    {
        $this->hooks->on_weekly_verification();

        $this->assertLogged('Weekly verification could not start: No active stores found.', 'warning');
        $this->assertSame([], $this->single);
        $this->assertArrayNotHasKey(self::PROGRESS, $this->transients);
    }

    public function test_weekly_verification_warns_when_already_running(): void
    {
        $this->transients[self::PROGRESS] = ['status' => 'running', 'last_activity' => time(), 'started_at' => '2024-01-15 11:59:00'];

        $this->hooks->on_weekly_verification();

        $this->assertLogged('Weekly verification could not start: Verification is already running.', 'warning');
        $this->assertSame([], $this->single);
    }

    public function test_weekly_verification_does_not_reschedule_when_next_run_exists(): void
    {
        $this->hooks->on_weekly_verification();

        $this->assertSame([], $this->recurring);
        $this->assertSame(0, $this->unscheduleCalls);
        $this->assertNotLogged('rescheduling');
    }

    public function test_weekly_verification_reschedules_when_next_run_missing_and_enabled(): void
    {
        $this->nextScheduled = false;
        $this->options['wc_multi_store_sync_weekly_verification'] = ['enabled' => true, 'day_of_week' => 3, 'time_of_day' => '04:30'];

        $before = time();
        $this->hooks->on_weekly_verification();

        $this->assertLogged('No next verification scheduled, rescheduling...', 'warning');
        $this->assertSame(1, $this->unscheduleCalls, 'Existing actions are cleared before recreating');
        $this->assertCount(1, $this->recurring);
        [$when, $interval, $hook, $args, $group] = $this->recurring[0];
        $this->assertGreaterThan($before, $when);
        $this->assertSame('04:30', date('H:i', $when));
        $this->assertSame('3', date('w', $when));
        $this->assertSame(WEEK_IN_SECONDS, $interval);
        $this->assertSame('wc_multi_store_weekly_verification', $hook);
        $this->assertSame([], $args);
        $this->assertSame('wc_multi_store_sync', $group);
    }

    public function test_weekly_verification_with_missing_next_run_and_disabled_only_unschedules(): void
    {
        $this->nextScheduled = false;
        $this->options['wc_multi_store_sync_weekly_verification'] = ['enabled' => false];

        $this->hooks->on_weekly_verification();

        $this->assertLogged('No next verification scheduled, rescheduling...', 'warning');
        $this->assertSame([], $this->recurring);
        $this->assertSame(1, $this->unscheduleCalls);
        $this->assertLogged('Weekly verification unscheduled');
    }

    public function test_weekly_verification_swallows_exceptions_logs_error_and_still_checks_schedule(): void
    {
        $this->nextScheduled = false;
        $this->options['wc_multi_store_sync_weekly_verification'] = ['enabled' => true];
        Functions\when('get_transient')->alias(function () {
            throw new RuntimeException('transient backend down');
        });

        $this->hooks->on_weekly_verification();

        $this->assertLogged('Weekly verification failed with exception: transient backend down in ', 'error');
        $this->assertLogged('No next verification scheduled, rescheduling...', 'warning');
        $this->assertCount(1, $this->recurring);
    }

    // ---- on_async_verification_batch -------------------------------------

    public function test_async_batch_without_active_verification_is_skipped_and_state_untouched(): void
    {
        $this->hooks->on_async_verification_batch(0);

        $this->assertLogged('Async verification batch hook triggered with batch: 0 (type: integer)');
        $this->assertLogged('Async verification batch skipped: no active verification (progress: null, status: N/A)');
        $this->assertSame([], $this->deletedTransients);
    }

    public function test_async_batch_casts_string_argument_to_int_for_the_verifier(): void
    {
        $this->hooks->on_async_verification_batch('3');

        $this->assertLogged('(type: string)');
        $this->assertLogged('process_verification_batch called with batch_number: 3');
    }

    public function test_async_batch_ignores_non_running_progress(): void
    {
        $this->transients[self::PROGRESS] = ['status' => 'completed'];

        $this->hooks->on_async_verification_batch(1);

        $this->assertLogged('progress: exists, status: completed');
        $this->assertSame([], $this->deletedTransients);
        $this->assertArrayHasKey(self::PROGRESS, $this->transients);
    }

    public function test_async_batch_exception_is_logged_and_progress_lock_is_cleared(): void
    {
        $this->transients[self::PROGRESS] = ['status' => 'running', 'product_ids' => [1], 'batch_size' => 10, 'total_batches' => 1];
        // get_active_stores() runs after the progress check; make it blow up.
        Functions\when('get_option')->alias(function ($k, $d = false) {
            if ($k === 'wc_multi_store_sync_stores') {
                throw new RuntimeException('options table unavailable');
            }
            return $this->options[$k] ?? $d;
        });

        $this->hooks->on_async_verification_batch('2');

        $this->assertLogged('Async verification batch 2 failed with exception: options table unavailable in ', 'error');
        $this->assertSame([self::PROGRESS], $this->deletedTransients, 'Stale progress lock must be removed so a retry can start');
        $this->assertArrayNotHasKey(self::PROGRESS, $this->transients);
    }

    public function test_async_batch_success_does_not_delete_progress(): void
    {
        $this->transients[self::PROGRESS] = ['status' => 'completed'];

        $this->hooks->on_async_verification_batch(0);

        $this->assertSame([], $this->deletedTransients);
        $this->assertSame([], $this->messages('error'));
    }

    // ---- load_settings ---------------------------------------------------

    private function loadSettings(): void
    {
        (new ReflectionMethod(WC_Multi_Store_Hooks::class, 'load_settings'))->invoke(null);
    }

    private function cached(): ?array
    {
        return (new ReflectionProperty(WC_Multi_Store_Hooks::class, 'cached_settings'))->getValue();
    }

    public function test_load_settings_populates_cache_from_settings_class(): void
    {
        $this->assertNull($this->cached());

        $this->loadSettings();

        $cached = $this->cached();
        $this->assertIsArray($cached);
        $this->assertTrue($cached['enabled']);
        $this->assertSame('full_product', $cached['sync_type_default']);
        $this->assertArrayHasKey('auth_method', $cached, 'Defaults are merged in');
    }

    public function test_load_settings_reads_once_until_cache_is_cleared(): void
    {
        $this->loadSettings();
        $first = $this->cached();

        // Change the stored option and drop the Settings-level cache: Hooks must still serve its own copy.
        $this->options['wc_multi_store_sync_settings'] = ['enabled' => false];
        WC_Multi_Store_Settings::clear_static_cache();
        $this->loadSettings();
        $this->assertSame($first, $this->cached());
        $this->assertTrue($this->cached()['enabled']);

        WC_Multi_Store_Hooks::clear_settings_cache();
        $this->assertNull($this->cached());
        $this->loadSettings();
        $this->assertFalse($this->cached()['enabled']);
    }

    public function test_get_setting_uses_loaded_cache_and_default_for_unknown_key(): void
    {
        $m = new ReflectionMethod(WC_Multi_Store_Hooks::class, 'get_setting');

        $this->assertSame('full_product', $m->invoke(null, 'sync_type_default'));
        $this->assertSame('fallback', $m->invoke(null, 'no_such_key', 'fallback'));
        $this->assertFalse($m->invoke(null, 'no_such_key'));
    }
}
