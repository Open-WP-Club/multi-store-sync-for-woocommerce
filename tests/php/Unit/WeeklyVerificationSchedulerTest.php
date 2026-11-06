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
        Functions\when('add_option')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        // Scheduling works in the site timezone via current_datetime()/wp_date();
        // default to plain wall-clock so tests that don't assert timezone keep
        // their UTC semantics (PHP's default timezone is UTC in the test env).
        Functions\when('current_datetime')->justReturn(new DateTimeImmutable('now'));
        Functions\when('wp_date')->alias(fn($format, $timestamp = null) => date($format, $timestamp ?? time()));
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

    public function test_run_verification_stops_when_atomic_lock_is_busy(): void
    {
        Functions\when('add_option')->justReturn(false);

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();

        $this->assertSame('Verification already running', $result['error']);
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

        // The weekday is anchored on the TARGET, not on $now: when +1 hour
        // crosses midnight the target's weekday is tomorrow's, and the next
        // occurrence is that moment — not a week later. Anchoring on $now's
        // weekday would describe a time that already passed today and make
        // this test fail for runs after 23:00.
        $result = $this->callCalculateNextRunTime([
            'day_of_week' => (int) $target->format('w'),
            'time_of_day' => $target->format('H:i'),
        ]);

        $this->assertSame($target->setTime((int) $target->format('H'), (int) $target->format('i'), 0)->getTimestamp(), $result);
        $this->assertGreaterThan($now->getTimestamp(), $result);
    }

    public function test_calculate_next_run_time_earlier_today_rolls_to_next_week(): void
    {
        $now = new DateTimeImmutable('now');
        // Anchored on the TARGET's weekday (see the test above): when "-1 hour"
        // crosses midnight into yesterday, the described moment is in the past
        // and the scheduler must still roll forward to its next occurrence.
        // Anchoring on $now's weekday would make the same H:i describe a time
        // later *today* and fail for runs shortly after midnight.
        $target = $now->modify('-1 hour');

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => (int) $target->format('w'),
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

    // ── auto_correct_discrepancies (private) ─────────────────────
    //
    // Regression guard: no test previously exercised this method at all —
    // it's only reachable in production via run_verification()/
    // finalize_async_verification() when auto_correct is enabled.

    private function callAutoCorrectDiscrepancies(array $report): int
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Scheduler::class, 'auto_correct_discrepancies');
        return $method->invoke(null, $report);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();
        parent::tearDown();
    }

    public function test_auto_correct_discrepancies_empty_details_returns_zero(): void
    {
        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')->never();
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $result = $this->callAutoCorrectDiscrepancies(['details' => []]);

        $this->assertSame(0, $result);
    }

    public function test_auto_correct_discrepancies_queues_correctable_products(): void
    {
        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')
            ->twice()
            ->with(\Mockery::anyOf(101, 102), 'weekly_verification_correction', 1, null)
            ->andReturn(1);
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $report = [
            'details' => [
                ['product_id' => 101, 'discrepancies' => [['type' => 'stock']]],
                ['product_id' => 102, 'discrepancies' => [['type' => 'price']]],
            ],
        ];

        $result = $this->callAutoCorrectDiscrepancies($report);

        $this->assertSame(2, $result);
    }

    public function test_auto_correct_discrepancies_skips_products_without_discrepancies(): void
    {
        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')->never();
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $report = [
            'details' => [
                ['product_id' => 101, 'discrepancies' => []],
            ],
        ];

        $result = $this->callAutoCorrectDiscrepancies($report);

        $this->assertSame(0, $result);
    }

    public function test_auto_correct_discrepancies_skips_ghost_products(): void
    {
        // Ghost products (product_id = null) exist only on the remote store —
        // they can't be corrected by re-syncing a local product.
        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')->never();
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $report = [
            'details' => [
                ['product_id' => null, 'discrepancies' => [['type' => 'ghost']]],
            ],
        ];

        $result = $this->callAutoCorrectDiscrepancies($report);

        $this->assertSame(0, $result);
    }

    public function test_auto_correct_discrepancies_respects_configured_limit(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['auto_correct_limit' => 1];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            return $default;
        });

        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')->once()->andReturn(1);
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $report = [
            'details' => [
                ['product_id' => 101, 'discrepancies' => [['type' => 'stock']]],
                ['product_id' => 102, 'discrepancies' => [['type' => 'price']]],
            ],
        ];

        $result = $this->callAutoCorrectDiscrepancies($report);

        $this->assertSame(1, $result, 'only the configured limit should be queued');
    }

    public function test_auto_correct_discrepancies_unlimited_when_limit_zero(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['auto_correct_limit' => 0];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            return $default;
        });

        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')->times(3)->andReturn(1);
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $report = [
            'details' => [
                ['product_id' => 101, 'discrepancies' => [['type' => 'stock']]],
                ['product_id' => 102, 'discrepancies' => [['type' => 'price']]],
                ['product_id' => 103, 'discrepancies' => [['type' => 'stock']]],
            ],
        ];

        $result = $this->callAutoCorrectDiscrepancies($report);

        $this->assertSame(3, $result);
    }

    public function test_auto_correct_discrepancies_applies_weekly_sync_type_override(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['weekly_sync_type' => 'price_quantity'];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            return $default;
        });

        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')
            ->once()
            ->with(101, 'weekly_verification_correction', 1, 'price_quantity')
            ->andReturn(1);
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $report = [
            'details' => [
                ['product_id' => 101, 'discrepancies' => [['type' => 'stock']]],
            ],
        ];

        $this->callAutoCorrectDiscrepancies($report);

        $this->addToAssertionCount(1); // Mockery's shouldReceive()->with() above is the real assertion.
    }

    // ── finalize_async_verification (private) ─────────────────────
    //
    // Regression guard: no test previously exercised this method — only
    // reachable via process_verification_batch() finishing the last batch.

    private function callFinalizeAsyncVerification(): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Verification_Scheduler::class, 'finalize_async_verification');
        $method->invoke(null);
    }

    private function mockGhostScanDependencies(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn([]);
        $wpdb->shouldReceive('insert')->andReturn(1);

        $api_client_mock = \Mockery::mock('WC_Multi_Store_API_Client');
        $api_client_mock->shouldReceive('stream_products')
            ->andReturn(new WP_Error('http_error', 'timeout'));

        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $api_client_mock]);
    }

    public function test_finalize_async_verification_no_progress_returns_early(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')->never();

        $this->callFinalizeAsyncVerification();

        $this->addToAssertionCount(1); // Functions\expect()->never() above is the real assertion.
    }

    public function test_finalize_async_verification_builds_report_and_marks_completed(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();

        $progress = [
            'status' => 'running',
            'started_at' => '2024-01-15 10:00:00',
            'processed_products' => 5,
            'stores_checked' => 1,
            'discrepancies_found' => 2,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 1,
            'price_mismatches' => 1,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [],
        ];

        Functions\when('get_transient')->alias(function ($key) use ($progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:05:00');

        $captured = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$captured) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $captured = $value;
            }
            return true;
        });

        $this->callFinalizeAsyncVerification();

        $this->assertNotNull($captured, 'progress transient should be updated');
        $this->assertSame('completed', $captured['status']);
        $this->assertSame('2024-01-15 10:05:00', $captured['completed_at']);
    }

    public function test_finalize_async_verification_sends_email_when_enabled_with_discrepancies(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['email_enabled' => true, 'auto_correct' => false];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            if ($option === 'admin_email') {
                return 'admin@example.com';
            }
            return $default;
        });

        $progress = [
            'status' => 'running',
            'started_at' => '2024-01-15 10:00:00',
            'processed_products' => 5,
            'stores_checked' => 1,
            'discrepancies_found' => 2,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 1,
            'price_mismatches' => 1,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [],
        ];

        Functions\when('get_transient')->alias(function ($key) use ($progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:05:00');
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('admin_url')->justReturn('http://example.com/wp-admin/admin.php');
        Functions\when('get_site_url')->justReturn('http://example.com');
        Functions\when('esc_html')->alias(fn($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(fn($url) => $url);
        Functions\expect('wp_mail')->once()->andReturn(true);

        $this->callFinalizeAsyncVerification();

        $this->addToAssertionCount(1); // Functions\expect()->once() above is the real assertion.
    }

    public function test_finalize_async_verification_auto_corrects_when_enabled_with_discrepancies(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['email_enabled' => false, 'auto_correct' => true];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            return $default;
        });

        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $qm->shouldReceive('add_product')
            ->once()
            ->with(55, 'weekly_verification_correction', 1, null)
            ->andReturn(1);
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;

        $progress = [
            'status' => 'running',
            'started_at' => '2024-01-15 10:00:00',
            'processed_products' => 1,
            'stores_checked' => 1,
            'discrepancies_found' => 1,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 1,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [
                ['product_id' => 55, 'discrepancies' => [['type' => 'stock']]],
            ],
        ];

        Functions\when('get_transient')->alias(function ($key) use ($progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:05:00');

        $this->callFinalizeAsyncVerification();

        $this->addToAssertionCount(1); // Mockery's shouldReceive()->with() above is the real assertion.
    }

    // ── process_verification_batch ────────────────────────────────
    //
    // Regression guard: no test previously exercised this method at all.

    public function test_process_verification_batch_no_active_progress_does_nothing(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')->never();
        Functions\expect('as_schedule_single_action')->never();

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(0);

        $this->addToAssertionCount(1); // Functions\expect()->never() above is the real assertion.
    }

    public function test_process_verification_batch_ignores_non_running_progress(): void
    {
        Functions\when('get_transient')->justReturn(['status' => 'completed']);
        Functions\expect('set_transient')->never();
        Functions\expect('as_schedule_single_action')->never();

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(0);

        $this->addToAssertionCount(1); // Functions\expect()->never() above is the real assertion.
    }

    public function test_process_verification_batch_schedules_next_batch(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('wc_get_product')->justReturn(false);

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 2,
            'product_ids' => [1, 2, 3, 4],
            'processed_products' => 0,
            'discrepancies_found' => 0,
            'details' => [],
        ];

        Functions\when('get_transient')->alias(function ($key) use ($progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');

        $captured = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$captured) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $captured = $value;
            }
            return true;
        });

        $scheduled_batch = null;
        Functions\when('as_schedule_single_action')->alias(function ($timestamp, $hook, $args) use (&$scheduled_batch) {
            if ($hook === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_BATCH_HOOK) {
                $scheduled_batch = $args[0];
            }
            return 1;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(0);

        $this->assertSame(1, $scheduled_batch, 'next batch (index 1) should be scheduled');
        $this->assertNotNull($captured);
        $this->assertSame(1, $captured['current_batch']);
        // wc_get_product() => false makes verify_product() return null for
        // every product in the batch, so processed_products stays at 0.
        $this->assertSame(0, $captured['processed_products']);
    }

    public function test_process_verification_batch_finalizes_on_last_batch(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();
        Functions\when('wc_get_product')->justReturn(false);

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 2,
            'stores_checked' => 1,
            'started_at' => '2024-01-15 10:00:00',
            'product_ids' => [1, 2, 3, 4],
            'processed_products' => 0,
            'discrepancies_found' => 0,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 0,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [],
        ];

        Functions\when('get_transient')->alias(function ($key) use ($progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');
        Functions\expect('as_schedule_single_action')->never();

        $captured = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$captured) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $captured = $value;
            }
            return true;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(1);

        $this->assertNotNull($captured);
        $this->assertSame('completed', $captured['status'], 'last batch should finalize instead of scheduling another');
    }

    public function test_process_verification_batch_finalizes_when_no_products_left(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 2,
            'stores_checked' => 1,
            'started_at' => '2024-01-15 10:00:00',
            'product_ids' => [1, 2],
            'processed_products' => 0,
            'discrepancies_found' => 0,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 0,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [],
        ];

        Functions\when('get_transient')->alias(function ($key) use ($progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');
        Functions\expect('as_schedule_single_action')->never();

        $captured = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$captured) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $captured = $value;
            }
            return true;
        });

        // batch_number=1 with batch_size=2 and only 2 product_ids → offset 2,
        // array_slice returns [] → finalize immediately.
        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(1);

        $this->assertNotNull($captured);
        $this->assertSame('completed', $captured['status']);
    }

    // ── continue_after_failed_batch ──────────────────────────────
    //
    // A batch that throws must not abandon the rest of a multi-batch run.

    public function test_continue_after_failed_batch_schedules_the_next_batch(): void
    {
        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 3,
            'product_ids' => [1, 2, 3, 4],
            'processed_products' => 2,
            'discrepancies_found' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(function ($key) use (&$progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');

        $scheduled = null;
        Functions\when('as_schedule_single_action')->alias(function ($ts, $hook, $args) use (&$scheduled) {
            if ($hook === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_BATCH_HOOK) {
                $scheduled = $args[0];
            }
            return 1;
        });

        $captured = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$captured, &$progress) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $captured = $value;
                $progress = $value;
            }
            return true;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::continue_after_failed_batch(1);

        $this->assertSame(2, $scheduled, 'the chain must advance past the failed batch');
        $this->assertNotNull($captured);
        $this->assertSame('running', $captured['status'], 'the run must keep going');
        $this->assertSame(2, $captured['current_batch']);
        $this->assertSame(2, $captured['processed_products'], 'the failed batch must not count as processed');
    }

    public function test_continue_after_failed_batch_on_the_last_batch_finalizes_the_run(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 2,
            'stores_checked' => 1,
            'started_at' => '2024-01-15 10:00:00',
            'product_ids' => [1, 2, 3, 4],
            'processed_products' => 2,
            'discrepancies_found' => 0,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 0,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(function ($key) use (&$progress) {
            return $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');
        Functions\expect('as_schedule_single_action')->never();

        $captured = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$captured, &$progress) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $captured = $value;
                $progress = $value;
            }
            return true;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::continue_after_failed_batch(1);

        $this->assertNotNull($captured);
        $this->assertSame('partial', $captured['status'], 'a failed batch must be visible in the final result');
    }

    public function test_continue_after_failed_batch_without_a_running_verification_does_nothing(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('as_schedule_single_action')->never();
        Functions\expect('set_transient')->never();

        WC_Multi_Store_Weekly_Verification_Scheduler::continue_after_failed_batch(0);

        $this->addToAssertionCount(1); // Functions\expect()->never() above is the real assertion.
    }

    // ── async product-ID storage ─────────────────────────────────
    //
    // The progress transient is rewritten after every batch, so the full
    // product-ID list (up to 50 000 entries) must not ride along in it.

    public function test_schedule_async_verification_keeps_product_ids_out_of_the_progress_transient(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        WP_Query::$resultsQueue = [[11, 22, 33]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 3]);

        $transient_payloads = [];
        Functions\when('set_transient')->alias(function ($key, $value) use (&$transient_payloads) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $transient_payloads[] = $value;
            }
            return true;
        });

        $option_writes = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$option_writes) {
            $option_writes[$key][] = $value;
            return true;
        });

        $first_batch = null;
        Functions\when('as_schedule_single_action')->alias(function ($ts, $hook, $args) use (&$first_batch) {
            if ($hook === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_BATCH_HOOK) {
                $first_batch = $args[0];
            }
            return 1;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::schedule_async_verification();
        WP_Query::$resultsQueue = null;

        $this->assertTrue($result['success']);
        $this->assertSame(0, $first_batch);
        $this->assertCount(1, $transient_payloads);
        $this->assertArrayNotHasKey(
            'product_ids',
            $transient_payloads[0],
            'The progress transient is rewritten every batch — the product-ID list must not ride along'
        );
        $this->assertSame(
            [11, 22, 33],
            $option_writes['wc_mss_async_verification_products'][0] ?? null,
            'The product-ID list must be stored once in its own option'
        );
        $this->assertCount(
            1,
            $option_writes['wc_mss_async_verification_products'] ?? [],
            'The product-ID option must be written once, not per batch'
        );
    }

    public function test_process_verification_batch_reads_product_ids_from_the_products_option(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('wc_get_product')->justReturn(false);

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 2,
            'processed_products' => 0,
            'discrepancies_found' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(fn($key) => $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_mss_async_verification_products') {
                return [1, 2, 3, 4];
            }
            return $default;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');

        $scheduled = null;
        Functions\when('as_schedule_single_action')->alias(function ($ts, $hook, $args) use (&$scheduled) {
            if ($hook === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_BATCH_HOOK) {
                $scheduled = $args[0];
            }
            return 1;
        });
        Functions\when('set_transient')->justReturn(true);

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(0);

        $this->assertSame(1, $scheduled, 'products stored in the option must drive the batch slicing');
    }

    public function test_process_verification_batch_progress_updates_do_not_re_serialize_product_ids(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('wc_get_product')->justReturn(false);

        // Legacy in-flight runs carry the IDs in the progress payload; batch
        // updates must drop them either way.
        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 2,
            'product_ids' => [1, 2, 3, 4],
            'processed_products' => 0,
            'discrepancies_found' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(fn($key) => $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false);
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');

        Functions\when('as_schedule_single_action')->justReturn(1);

        $captured = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$captured) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT) {
                $captured = $value;
            }
            return true;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(0);

        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey(
            'product_ids',
            $captured,
            'Per-batch progress writes must not re-serialize the product-ID list'
        );
    }

    public function test_finalize_async_verification_clears_the_products_option(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 1,
            'stores_checked' => 1,
            'started_at' => '2024-01-15 10:00:00',
            'processed_products' => 1,
            'discrepancies_found' => 0,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 0,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(fn($key) => $key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false);
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');
        Functions\when('set_transient')->justReturn(true);

        $deleted = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $this->callFinalizeAsyncVerification();

        $this->assertContains('wc_mss_async_verification_products', $deleted, 'the stored product-ID list must not outlive the run');
    }

    // ── run_verification: lock lifetime ──────────────────────────

    public function test_run_verification_releases_the_lock_when_the_run_throws(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        WP_Query::$resultsQueue = [[123]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 1]);
        Functions\when('wc_get_product')->alias(static function () {
            throw new RuntimeException('product lookup exploded');
        });

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
        $api_client_mock->shouldReceive('stream_products')->andReturn(new WP_Error('http_error', 'timeout'));
        $ref = new ReflectionClass(WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::class);
        $pool = $ref->getProperty('api_client_pool');
        $pool->setValue(null, ['https://store1.com' => $api_client_mock]);

        $deleted = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $thrown = false;
        try {
            WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();
        } catch (RuntimeException $e) {
            $thrown = true;
        }
        WP_Query::$resultsQueue = null;

        $this->assertTrue($thrown, 'the exception must still propagate to the caller');
        $this->assertContains(
            'wc_mss_verification_lock',
            $deleted,
            'the lock must be released even when the run blows up mid-loop'
        );
    }

    public function test_run_verification_refuses_while_a_fresh_async_verification_is_running(): void
    {
        Functions\when('get_transient')->alias(function ($key) {
            return $key === 'wc_mss_async_verification_progress'
                ? ['status' => 'running', 'last_activity' => time()]
                : false;
        });
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 0]);

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();

        $this->assertSame('Verification already running', $result['error'] ?? null);
    }

    public function test_run_verification_ignores_stale_async_progress(): void
    {
        Functions\when('get_transient')->alias(function ($key) {
            return $key === 'wc_mss_async_verification_progress'
                ? ['status' => 'running', 'last_activity' => time() - 40 * MINUTE_IN_SECONDS]
                : false;
        });
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 0]);

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();

        $this->assertSame('No products to verify', $result['error'] ?? null);
    }

    // ── schedule_async_verification: shared lock ─────────────────

    public function test_schedule_async_verification_refuses_when_a_verification_lock_is_held(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        WP_Query::$resultsQueue = [[11, 22]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 2]);
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_mss_verification_lock') {
                return time(); // a sync or async run is mid-flight
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'name' => 'S']];
            }
            return $default;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::schedule_async_verification();
        WP_Query::$resultsQueue = null;

        $this->assertFalse($result['success'] ?? null, 'a held lock must block the async path too');
        $this->assertSame('Verification is already running.', $result['message'] ?? null);
    }

    public function test_schedule_async_verification_does_not_clear_stale_progress_while_the_lock_is_held(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        WP_Query::$resultsQueue = [[11]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 1]);
        Functions\when('as_schedule_single_action')->justReturn(1);

        $deleted = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });
        Functions\when('get_transient')->alias(function ($key) {
            return $key === 'wc_mss_async_verification_progress'
                ? ['status' => 'running', 'last_activity' => time() - 40 * MINUTE_IN_SECONDS]
                : false;
        });
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_mss_verification_lock') {
                return time(); // someone else's live run holds it
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'name' => 'S']];
            }
            return $default;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::schedule_async_verification();
        WP_Query::$resultsQueue = null;

        $this->assertFalse($result['success'] ?? null);
        $this->assertSame('Verification is already running.', $result['message'] ?? null);
        $this->assertNotContains(
            'wc_mss_async_verification_progress',
            $deleted,
            'stale-progress cleanup must not run while another run holds the lock'
        );
    }

    public function test_schedule_async_verification_replaces_a_stale_run_when_nothing_else_holds_the_lock(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        WP_Query::$resultsQueue = [[11]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 1]);
        Functions\when('as_schedule_single_action')->justReturn(1);
        // The stale-progress cleanup path cancels pending batch actions; stub it so
        // this test is order-independent (function_exists() can report true here if an
        // earlier test in the suite mocked it).
        Functions\when('as_unschedule_all_actions')->justReturn(0);

        $deleted = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });
        Functions\when('get_transient')->alias(function ($key) {
            return $key === 'wc_mss_async_verification_progress'
                ? ['status' => 'running', 'last_activity' => time() - 40 * MINUTE_IN_SECONDS]
                : false;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::schedule_async_verification();
        WP_Query::$resultsQueue = null;

        $this->assertTrue($result['success']);
        $this->assertContains('wc_mss_async_verification_progress', $deleted, 'the dead run must be cleaned up');
    }

    public function test_schedule_async_verification_acquires_the_shared_verification_lock(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        WP_Query::$resultsQueue = [[11, 22, 33]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 3]);
        Functions\when('as_schedule_single_action')->justReturn(1);

        $added = [];
        Functions\when('add_option')->alias(function (...$args) use (&$added) {
            $added[] = $args;
            return true;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::schedule_async_verification();
        WP_Query::$resultsQueue = null;

        $this->assertTrue($result['success']);
        $this->assertSame(
            'wc_mss_verification_lock',
            $added[0][0] ?? null,
            'the async path must take the same lock as run_verification()'
        );
    }

    public function test_finalize_async_verification_releases_the_verification_lock(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockGhostScanDependencies();

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 1,
            'stores_checked' => 1,
            'started_at' => '2024-01-15 10:00:00',
            'processed_products' => 1,
            'discrepancies_found' => 0,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 0,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(fn($key) => $key === 'wc_mss_async_verification_progress' ? $progress : false);
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');
        Functions\when('set_transient')->justReturn(true);

        $deleted = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $this->callFinalizeAsyncVerification();

        $this->assertContains('wc_mss_verification_lock', $deleted, 'the shared lock must not outlive the run');
    }

    public function test_cancel_async_verification_releases_the_verification_lock(): void
    {
        Functions\when('get_transient')->alias(fn($key) => $key === 'wc_mss_async_verification_progress'
            ? ['status' => 'running', 'product_ids' => [1]]
            : false);
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');
        Functions\when('set_transient')->justReturn(true);
        Functions\when('as_unschedule_all_actions')->justReturn(0);

        $deleted = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::cancel_async_verification();

        $this->assertTrue($result);
        $this->assertContains('wc_mss_verification_lock', $deleted);
    }

    public function test_process_verification_batch_keeps_the_verification_lock_fresh(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('wc_get_product')->justReturn(false);

        $progress = [
            'status' => 'running',
            'batch_size' => 2,
            'total_batches' => 2,
            'product_ids' => [1, 2, 3, 4],
            'processed_products' => 0,
            'discrepancies_found' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(fn($key) => $key === 'wc_mss_async_verification_progress' ? $progress : false);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_mss_verification_lock') {
                return time() - 10; // the run holds the lock
            }
            return $default;
        });
        Functions\when('current_time')->justReturn('2024-01-15 10:00:00');
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('set_transient')->justReturn(true);

        $updates = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$updates) {
            $updates[$key][] = $value;
            return true;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(0);

        $this->assertArrayHasKey(
            'wc_mss_verification_lock',
            $updates,
            'batches must refresh the lock so its 1-hour staleness recovery cannot steal a live multi-batch run'
        );
        $this->assertGreaterThan(time() - 5, $updates['wc_mss_verification_lock'][0]);
    }

    // ── scheduling: site timezone ────────────────────────────────
    //
    // WordPress pins PHP's default timezone to UTC, so schedule calculations
    // must go through current_datetime()/wp_date() (site timezone) — a plain
    // new DateTimeImmutable('now')/date() pair fires the run at UTC wall time.

    public function test_calculate_next_run_time_uses_the_site_timezone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        // Sunday 20:00 local is already Monday 01:00 UTC: a UTC-based
        // scheduler would fire "Monday 02:00" at 02:00 UTC = 21:00 Sunday
        // local, in the middle of the evening instead of the night window.
        Functions\when('current_datetime')->justReturn(new DateTimeImmutable('2026-11-01 20:00:00', $tz));

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => 1, // Monday
            'time_of_day' => '02:00',
        ]);

        $expected = (new DateTimeImmutable('2026-11-02 02:00:00', $tz))->getTimestamp();
        $this->assertSame($expected, $result, 'The run must fire at 02:00 site time, not 02:00 UTC');
    }

    public function test_calculate_next_run_time_rolls_past_times_to_next_week_in_the_site_timezone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        Functions\when('current_datetime')->justReturn(new DateTimeImmutable('2026-11-02 20:00:00', $tz)); // Monday evening

        $result = $this->callCalculateNextRunTime([
            'day_of_week' => 1, // Monday
            'time_of_day' => '02:00',
        ]);

        $expected = (new DateTimeImmutable('2026-11-09 02:00:00', $tz))->getTimestamp();
        $this->assertSame($expected, $result, 'A past time-of-day must roll to next week in the site timezone');
    }

    public function test_schedule_verification_logs_the_next_run_in_the_site_timezone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        Functions\when('current_datetime')->justReturn(new DateTimeImmutable('2026-11-01 20:00:00', $tz));
        Functions\when('wp_date')->alias(fn($format, $ts) => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($format));

        $logged = [];
        $logger = \Mockery::mock();
        $logger->shouldReceive('log')->andReturnUsing(function ($level, $message) use (&$logged) {
            $logged[] = $message;
        });
        Functions\when('wc_get_logger')->justReturn($logger);

        Functions\when('as_unschedule_all_actions')->justReturn(0);
        Functions\when('as_schedule_recurring_action')->justReturn(1);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true, 'day_of_week' => 1, 'time_of_day' => '02:00'];
            }
            return $default;
        });

        WC_Multi_Store_Weekly_Verification_Scheduler::schedule_verification();

        $this->assertStringContainsString(
            '2026-11-02 02:00:00',
            implode(' | ', $logged),
            'the log must show the run time in site time'
        );
    }

    public function test_async_verification_reclaims_stale_lock(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        $stale_time = time() - (2 * HOUR_IN_SECONDS);

        Functions\when('get_option')->alias(function ($option, $default = false) use ($stale_time) {
            if ($option === WC_Multi_Store_Weekly_Verification_Scheduler::VERIFICATION_LOCK_OPTION) {
                return $stale_time;
            }
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            return $default;
        });

        WP_Query::$resultsQueue = [[101, 102]];
        Functions\when('wp_count_posts')->alias(fn() => (object) ['publish' => 2]);
        Functions\when('as_schedule_single_action')->justReturn(1);

        $released = false;
        Functions\when('delete_option')->alias(function ($key) use (&$released) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::VERIFICATION_LOCK_OPTION) {
                $released = true;
            }
            return true;
        });

        Functions\when('add_option')->alias(function ($key, $val) use (&$released) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::VERIFICATION_LOCK_OPTION) {
                return $released;
            }
            return true;
        });

        $result = WC_Multi_Store_Weekly_Verification_Scheduler::schedule_async_verification();

        $this->assertTrue($result['success']);
        $this->assertTrue($released, 'Stale lock must be cleared so the async run can acquire it');
    }

    public function test_process_verification_batch_copies_legacy_product_ids_to_option(): void
    {
        $saved_option = null;
        Functions\when('get_option')->alias(function ($key, $default = false) use (&$saved_option) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PRODUCTS_OPTION) {
                return $saved_option ?? [];
            }
            return $default;
        });
        Functions\when('update_option')->alias(function ($key, $val) use (&$saved_option) {
            if ($key === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PRODUCTS_OPTION) {
                $saved_option = $val;
            }
            return true;
        });

        $progress = [
            'status' => 'running',
            'product_ids' => [1, 2, 3, 4, 5, 6],
            'batch_size' => 2,
            'current_batch' => 0,
            'total_batches' => 3,
            'processed_products' => 0,
            'discrepancies_found' => 0,
            'details' => [],
        ];
        Functions\when('get_transient')->alias(fn($k) => $k === WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT ? $progress : false);
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('wc_get_product')->justReturn(false);

        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch(0);

        $this->assertSame([1, 2, 3, 4, 5, 6], $saved_option, 'Legacy product_ids from progress transient must be copied to ASYNC_PRODUCTS_OPTION');
    }
}
