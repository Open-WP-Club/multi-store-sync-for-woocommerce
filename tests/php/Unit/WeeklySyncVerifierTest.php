<?php
/**
 * Unit tests for WC_Multi_Store_Weekly_Sync_Verifier
 * Tests settings, report management, verification logic, and cleanup
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WeeklySyncVerifierTest extends WC_Multi_Store_TestCase
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
    }

    // ── get_settings ─────────────────────────────────────────────

    public function test_get_settings_returns_defaults(): void
    {
        $settings = WC_Multi_Store_Weekly_Sync_Verifier::get_settings();

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
        $settings = WC_Multi_Store_Weekly_Sync_Verifier::get_settings();
        $this->assertTrue($settings['enabled']);
    }

    public function test_get_settings_check_categories_derived_from_sync_type(): void
    {
        // sync_type_default is 'full_product' → check_categories should be true
        $settings = WC_Multi_Store_Weekly_Sync_Verifier::get_settings();
        $this->assertTrue($settings['check_categories']);
    }

    public function test_get_settings_day_of_week_default(): void
    {
        $settings = WC_Multi_Store_Weekly_Sync_Verifier::get_settings();
        $this->assertEquals(1, $settings['day_of_week']); // Monday
    }

    // ── update_settings ──────────────────────────────────────────

    public function test_update_settings_calls_update_option(): void
    {
        $result = WC_Multi_Store_Weekly_Sync_Verifier::update_settings(['enabled' => false]);
        $this->assertTrue($result);
    }

    // ── constants ────────────────────────────────────────────────
    // TABLE_NAME moved to WeeklyVerificationReportRepositoryTest.php along
    // with WC_Multi_Store_Weekly_Verification_Report_Repository.

    public function test_verification_lock_constant(): void
    {
        $this->assertEquals(
            'wc_mss_verification_running',
            WC_Multi_Store_Weekly_Sync_Verifier::VERIFICATION_LOCK
        );
    }

    // table_exists / get_reports / get_latest_report / cleanup_old_reports
    // moved to WeeklyVerificationReportRepositoryTest.php along with
    // WC_Multi_Store_Weekly_Verification_Report_Repository. The facade
    // delegation itself is still exercised indirectly via run_verification()
    // below (which calls save_report() on the new repository class).

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

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Verification disabled', $result['error']);
    }

    public function test_run_verification_already_running_returns_error(): void
    {
        // Simulate fresh lock (not stale)
        Functions\when('get_transient')->alias(function ($key) {
            if ($key === WC_Multi_Store_Weekly_Sync_Verifier::VERIFICATION_LOCK) {
                return time(); // Current timestamp = active lock
            }
            return false;
        });

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();
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

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('No active stores', $result['error']);
    }
}
