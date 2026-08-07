<?php
/**
 * Unit tests for WC_Multi_Store_Weekly_Verification_Report_Repository
 *
 * Extracted from WeeklySyncVerifierTest.php / WeeklySyncVerifierExtendedTest.php
 * as part of splitting WC_Multi_Store_Weekly_Sync_Verifier — see
 * docs/superpowers/specs/2026-08-07-weekly-verifier-report-repository-design.md
 */

use Brain\Monkey\Functions;

class WeeklyVerificationReportRepositoryTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('WC_Multi_Store_Weekly_Verification_Report_Repository', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-report-repository.php';
        }

        Functions\when('wp_parse_args')->alias(fn($args, $defaults) => array_merge($defaults, (array) $args));
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('sanitize_sql_orderby')->alias(fn($s) => $s);
        Functions\when('maybe_serialize')->alias(fn($data) => serialize($data));
        Functions\when('maybe_unserialize')->alias(fn($data) => is_string($data) ? @unserialize($data) : $data);
    }

    // ── constants ────────────────────────────────────────────────

    public function test_table_name_constant(): void
    {
        $this->assertEquals(
            'wc_multi_store_weekly_verifications',
            WC_Multi_Store_Weekly_Verification_Report_Repository::TABLE_NAME
        );
    }

    // ── table_exists ─────────────────────────────────────────────

    public function test_table_exists_returns_true(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('get_var')
            ->andReturn('wp_wc_multi_store_weekly_verifications');

        $this->assertTrue(WC_Multi_Store_Weekly_Verification_Report_Repository::table_exists());
    }

    public function test_table_exists_returns_false(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('get_var')->andReturn(null);

        $this->assertFalse(WC_Multi_Store_Weekly_Verification_Report_Repository::table_exists());
    }

    // ── save_report / get_reports / cleanup ──────────────────────

    public function test_get_reports_queries_database(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $results = WC_Multi_Store_Weekly_Verification_Report_Repository::get_reports();
        $this->assertIsArray($results);
    }

    public function test_get_latest_report_returns_null_when_no_table(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('get_var')->andReturn(null); // table_exists → false

        $result = WC_Multi_Store_Weekly_Verification_Report_Repository::get_latest_report();
        $this->assertNull($result);
    }

    public function test_get_latest_report_returns_report_when_exists(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')
            ->andReturn('wp_wc_multi_store_weekly_verifications'); // table_exists
        $wpdb->shouldReceive('get_row')
            ->andReturn([
                'id' => 5,
                'started_at' => '2024-01-14 03:00:00',
                'products_checked' => 100,
                'report_data' => serialize(['discrepancies_found' => 3]),
            ]);

        $result = WC_Multi_Store_Weekly_Verification_Report_Repository::get_latest_report();

        $this->assertIsArray($result);
        $this->assertEquals(5, $result['id']);
        $this->assertEquals(3, $result['report_data']['discrepancies_found']);
    }

    public function test_cleanup_old_reports_default_days(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(5);

        $deleted = WC_Multi_Store_Weekly_Verification_Report_Repository::cleanup_old_reports();
        $this->assertEquals(5, $deleted);
    }

    public function test_cleanup_old_reports_custom_days(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(2);

        $deleted = WC_Multi_Store_Weekly_Verification_Report_Repository::cleanup_old_reports(30);
        $this->assertEquals(2, $deleted);
    }

    // ── get_report ───────────────────────────────────────────────

    public function test_get_report_returns_report(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->andReturn([
            'id' => 1,
            'started_at' => '2024-01-15 12:00:00',
            'report_data' => serialize(['test' => true]),
        ]);

        $result = WC_Multi_Store_Weekly_Verification_Report_Repository::get_report(1);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
        $this->assertIsArray($result['report_data']);
        $this->assertTrue($result['report_data']['test']);
    }

    public function test_get_report_returns_null_when_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->andReturn(null);

        $result = WC_Multi_Store_Weekly_Verification_Report_Repository::get_report(999);

        $this->assertNull($result);
    }
}
