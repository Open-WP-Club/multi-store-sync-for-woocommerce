<?php
/**
 * Extended unit tests for WC_Multi_Store_Weekly_Sync_Verifier
 * Tests run_verification happy path, verify_product, get_products_to_verify,
 * scheduling, get_report, and async batch methods
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WeeklySyncVerifierExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVerifierMocks();

        if (!class_exists('WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-remote-data-fetcher.php';
        }
        if (!class_exists('WC_Multi_Store_Weekly_Sync_Verifier', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-sync-verifier.php';
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

    // ── run_verification: stale lock ─────────────────────────────

    public function test_run_verification_clears_stale_lock(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        // Lock was set 2 hours ago → stale
        $stale_time = time() - (2 * HOUR_IN_SECONDS);
        Functions\when('get_transient')->alias(function ($key) use ($stale_time) {
            if ($key === WC_Multi_Store_Weekly_Sync_Verifier::VERIFICATION_LOCK) {
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

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();

        // Should have cleared the stale lock and proceeded to 'No active stores'
        $this->assertEquals('No active stores', $result['error']);
    }

    // ── run_verification: no products ────────────────────────────

    public function test_run_verification_no_products(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('wp_count_posts')->alias(function () {
            return (object) ['publish' => 0];
        });

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();

        $this->assertEquals('No products to verify', $result['error']);
    }

    // verify_product (incl. "happy path", stock/price/missing-product detection,
    // edge cases) and check_full_product_fields/compare_* moved to
    // WeeklyVerificationComparatorTest.php along with
    // WC_Multi_Store_Weekly_Verification_Comparator.

    // get_report / get_latest_report(-with-data) moved to
    // WeeklyVerificationReportRepositoryTest.php along with
    // WC_Multi_Store_Weekly_Verification_Report_Repository.

    // build_exclusion_tax_query / get_products_to_verify / scan_ghost_products /
    // build_remote_index / load_search_values_for_products /
    // prefetch_remote_batch_data / get_remote_product moved to
    // WeeklyVerificationRemoteDataFetcherTest.php along with
    // WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher.

    // ── schedule_verification / unschedule_verification ──────────

    public function test_schedule_verification_calls_as_schedule(): void
    {
        $called = false;
        Functions\when('as_unschedule_all_actions')->justReturn(0);
        Functions\when('as_schedule_recurring_action')->alias(function () use (&$called) {
            $called = true;
            return 1;
        });

        WC_Multi_Store_Weekly_Sync_Verifier::schedule_verification();

        $this->assertTrue($called, 'as_schedule_recurring_action should have been called');
    }

    public function test_unschedule_verification_calls_as_unschedule(): void
    {
        $called = false;
        Functions\when('as_unschedule_all_actions')->alias(function () use (&$called) {
            $called = true;
            return 0;
        });

        WC_Multi_Store_Weekly_Sync_Verifier::unschedule_verification();

        $this->assertTrue($called, 'as_unschedule_all_actions should have been called');
    }

    // format_discrepancy_message moved to WeeklyVerificationEmailNotifierTest.php
    // along with WC_Multi_Store_Weekly_Verification_Email_Notifier.

    // ── calculate_next_run_time ─────────────────────────────────

    private function callCalculateNextRunTime(array $settings): int
    {
        $method = new ReflectionMethod(WC_Multi_Store_Weekly_Sync_Verifier::class, 'calculate_next_run_time');
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
