<?php
/**
 * Unit tests for WC_Multi_Store_Weekly_Verification_Scheduler
 * Tests settings, run_verification, scheduling, and async batch entry points
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WeeklyVerificationSchedulerTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVerifierMocks();

        if (!class_exists('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-remote-data-fetcher.php';
        }
        if (!class_exists('WC_Multi_Store_Weekly_Verification_Scheduler', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-scheduler.php';
        }

        // Reset static API client pool and prefetch batch cache between tests
        $ref = new ReflectionClass('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher');
        $prop = $ref->getProperty('api_client_pool');
        $prop->setValue(null, []);
        $batchCacheProp = $ref->getProperty('batch_cache');
        $batchCacheProp->setValue(null, []);
    }

    protected function setUpVerifierMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'match_products_by' => 'sku',
                    'category_match_by' => 'slug',
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                        'name' => 'Store 1',
                    ],
                ];
            }
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'admin_email') {
                return 'admin@test.com';
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_parse_args')->alias(function ($args, $defaults) {
            return array_merge($defaults, (array) $args);
        });
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('sanitize_sql_orderby')->alias(fn($s) => $s);
        Functions\when('maybe_serialize')->alias(fn($data) => serialize($data));
        Functions\when('maybe_unserialize')->alias(fn($data) => is_string($data) ? @unserialize($data) : $data);
        // No stored remote-ID mapping by default — exercises the pre-existing
        // search-by-SKU/slug path unless a test explicitly stubs a stored ID.
        Functions\when('get_post_meta')->justReturn('');
    }

    // ── get_settings ─────────────────────────────────────────────

    public function test_get_settings_returns_defaults(): void
    {
        $settings = WC_Multi_Store_Weekly_Verification_Scheduler::get_settings();

        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('schedule', $settings);
        $this->assertArrayHasKey('check_stock', $settings);
        $this->assertArrayHasKey('check_prices', $settings);
        $this->assertArrayHasKey('check_categories', $settings);
        $this->assertEquals('weekly', $settings['schedule']);
        $this->assertTrue($settings['check_stock']);
        $this->assertTrue($settings['check_prices']);
    }

    public function test_get_settings_enabled_from_option(): void
    {
        $settings = WC_Multi_Store_Weekly_Verification_Scheduler::get_settings();
        $this->assertTrue($settings['enabled']);
    }

    public function test_get_settings_check_categories_derived_from_sync_type(): void
    {
        // sync_type_default is 'full_product' → check_categories should be true
        $settings = WC_Multi_Store_Weekly_Verification_Scheduler::get_settings();
        $this->assertTrue($settings['check_categories']);
    }

    public function test_get_settings_day_of_week_default(): void
    {
        $settings = WC_Multi_Store_Weekly_Verification_Scheduler::get_settings();
        $this->assertEquals(1, $settings['day_of_week']); // Monday
    }

    // ── update_settings ──────────────────────────────────────────

    public function test_update_settings_calls_update_option(): void
    {
        $result = WC_Multi_Store_Weekly_Verification_Scheduler::update_settings(['enabled' => false]);
        $this->assertTrue($result);
    }

    // ── constants ────────────────────────────────────────────────

    public function test_verification_lock_constant(): void
    {
        $this->assertEquals(
            'wc_mss_verification_running',
            WC_Multi_Store_Weekly_Verification_Scheduler::VERIFICATION_LOCK
        );
    }

    // ── run_verification ─────────────────────────────────────────

    public function test_run_verification_disabled_returns_error(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => false];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            return $default;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Verification disabled', $result['error']);
    }

    public function test_run_verification_already_running_returns_error(): void
    {
        // Simulate fresh lock (not stale)
        Functions\when('get_transient')->alias(function ($key) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::VERIFICATION_LOCK) {
                return time(); // Current timestamp = active lock
            }
            return false;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Verification already running', $result['error']);
    }

    public function test_run_verification_no_active_stores(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [];
            }
            return $default;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('No active stores', $result['error']);
    }

    public function test_run_verification_clears_stale_lock(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Lock was set 2 hours ago → stale
        $stale_time = time() - (2 * HOUR_IN_SECONDS);
        Functions\when('get_transient')->alias(function ($key) use ($stale_time) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::VERIFICATION_LOCK) {
                return $stale_time;
            }
            return false;
        });

        // After clearing stale lock, it hits get_active_stores which returns empty → 'No active stores'
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [];
            }
            return $default;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();

        // Should have cleared the stale lock and proceeded to 'No active stores'
        $this->assertEquals('No active stores', $result['error']);
    }

    public function test_run_verification_no_products(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('wp_count_posts')->alias(function () {
            return (object) ['publish' => 0];
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();

        $this->assertEquals('No products to verify', $result['error']);
    }

    // ── run_verification: happy path (products found) ────────────
    //
    // Regression guard: no other test drives run_verification() past the
    // "no products" early return, so the array_chunk() batch-prefetch loop
    // (which references WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::
    // REMOTE_BATCH_FETCH_SIZE) was never exercised — a prior refactor left a
    // stale `self::REMOTE_BATCH_FETCH_SIZE`/`self::$batch_cache` reference on
    // the facade class that fatal-errored on this exact path.

    public function test_run_verification_happy_path_completes(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        WP_Query::$resultsQueue = [[123]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 1]);
        Functions\when('wc_get_product')->justReturn(false);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn([]);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($query, ...$args) => $query);
        $wpdb->shouldReceive('insert')->andReturn(1);

        $api_client_mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $api_client_mock->shouldReceive('stream_products')
            ->andReturn(new WP_Error('http_error', 'timeout'));

        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $api_client_mock]);

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();

        WP_Query::$resultsQueue = null;

        $this->assertSame('completed', $result['status']);
        $this->assertSame(0, $result['discrepancies_found']);
    }

    // ── schedule_verification / unschedule_verification ──────────

    public function test_schedule_verification_calls_as_schedule(): void
    {
        $called = false;
        Functions\when('as_unschedule_all_actions')->justReturn(0);
        Functions\when('as_schedule_recurring_action')->alias(function () use (&$called) {
            $called = true;
            return 1;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::schedule_verification();

        $this->assertTrue($called, 'as_schedule_recurring_action should have been called');
    }

    public function test_unschedule_verification_calls_as_unschedule(): void
    {
        $called = false;
        Functions\when('as_unschedule_all_actions')->alias(function () use (&$called) {
            $called = true;
            return 0;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::unschedule_verification();

        $this->assertTrue($called, 'as_unschedule_all_actions should have been called');
    }

    // ── calculate_next_run_time ─────────────────────────────────

    private function callCalculateNextRunTime(array $settings): int
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Scheduler::class, 'calculate_next_run_time');
        return $method->invoke(null, $settings);
    }

    public function test_calculate_next_run_time_later_today_stays_today(): void
    {
        $now = new DateTimeImmutable('now');
        $target = $now->modify('+1 hour');

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => (int) $now->format('w'),
            'time_of_day' => $target->format('H:i'),
        ]);

        $this->assertSame($target->setTime((int) $target->format('H'), (int) $target->format('i'), 0)->getTimestamp(), $result);
        $this->assertGreaterThan($now->getTimestamp(), $result);
    }

    public function test_calculate_next_run_time_earlier_today_rolls_to_next_week(): void
    {
        $now = new DateTimeImmutable('now');
        // Guard against the sub-second window where "1 hour ago" could roll past midnight
        // into a different day-of-week than $now — not realistic in a normal test run.
        $target = $now->modify('-1 hour');

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => (int) $now->format('w'),
            'time_of_day' => $target->format('H:i'),
        ]);

        $expected = $target->modify('+7 days')->setTime((int) $target->format('H'), (int) $target->format('i'), 0)->getTimestamp();
        $this->assertSame($expected, $result);
    }

    public function test_calculate_next_run_time_different_weekday(): void
    {
        $now = new DateTimeImmutable('now');
        $target_dow = ((int) $now->format('w') + 3) % 7;

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => $target_dow,
            'time_of_day' => '09:30',
        ]);

        $result_date = (new DateTimeImmutable())->setTimestamp($result);
        $this->assertSame($target_dow, (int) $result_date->format('w'));
        $this->assertSame('09:30', $result_date->format('H:i'));
        $this->assertGreaterThan($now->getTimestamp(), $result);
    }
}
