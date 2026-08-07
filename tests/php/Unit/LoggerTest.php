<?php
/**
 * Unit tests for WC_Multi_Store_Logger
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class LoggerTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the singleton so this test always constructs a fresh Logger instance.
        WC_Multi_Store_Logger::reset_instance();

        // Clean up any leftover log file from a previous test run.
        @unlink(WC_Log_Handler_File::get_log_file_path(WC_Multi_Store_Logger::LOG_HANDLE));
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        @unlink(WC_Log_Handler_File::get_log_file_path(WC_Multi_Store_Logger::LOG_HANDLE));

        parent::tearDown();
    }

    /**
     * Test default constants
     */
    public function test_default_constants(): void
    {
        $this->assertEquals(7, WC_Multi_Store_Logger::DEFAULT_ROTATION_DAYS);
    }

    // ── rotation_days (archival cutoff) ───────────────────────────

    public function test_rotation_days_uses_default(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $logger = new WC_Multi_Store_Logger();
        $prop = new ReflectionProperty($logger, 'rotation_days');

        $this->assertEquals(WC_Multi_Store_Logger::DEFAULT_ROTATION_DAYS, $prop->getValue($logger));
    }

    public function test_rotation_days_uses_custom_value(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array('rotation_days' => 14));

        $logger = new WC_Multi_Store_Logger();
        $prop = new ReflectionProperty($logger, 'rotation_days');

        $this->assertEquals(14, $prop->getValue($logger));
    }

    public function test_rotation_days_enforces_limits(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array('rotation_days' => 100)); // Too high, max is 30

        $logger = new WC_Multi_Store_Logger();
        $prop = new ReflectionProperty($logger, 'rotation_days');

        $this->assertLessThanOrEqual(30, $prop->getValue($logger));
    }

    // ── schedule_archival ──────────────────────────────────────────

    public function test_schedule_archival_returns_early_when_scheduler_unavailable(): void
    {
        // Without real Action Scheduler functions, is_available() returns false
        // and schedule_archival should return early without error.
        WC_Multi_Store_Logger::schedule_archival();

        $this->assertTrue(true);
    }

    // ── clear_log ──────────────────────────────────────────────────

    public function test_clear_log_returns_true_when_no_file(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $logger = new WC_Multi_Store_Logger();

        // "Clear" means "log is now empty" — true whether or not a file
        // existed to begin with (WC_Log_Handler_File::remove() alone would
        // return false when there's nothing to remove, which would wrongly
        // read as failure to the admin-ajax caller).
        $result = $logger->clear_log();

        $this->assertTrue($result);
    }

    // ── get_log ────────────────────────────────────────────────────

    public function test_get_log_returns_empty_when_no_file(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $logger = new WC_Multi_Store_Logger();
        $result = $logger->get_log();

        $this->assertEquals('', $result);
    }

    /**
     * Test archive_database_history returns success for no records
     */
    public function test_archive_database_history_no_records(): void
    {
        global $wpdb;

        // Create mock wpdb
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT * FROM ...');
        $wpdb->shouldReceive('get_results')->andReturn(array());

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $result = WC_Multi_Store_Logger::archive_database_history();

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['archived']);
        $this->assertStringContainsString('No records to archive', $result['message']);
    }
}
