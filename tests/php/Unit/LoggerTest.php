<?php
/**
 * Unit tests for WC_Multi_Store_Logger
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class LoggerTest extends WC_Multi_Store_TestCase
{
    private string $temp_log_dir;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset the singleton so this test always constructs a fresh Logger instance.
        WC_Multi_Store_Logger::reset_instance();

        // Create a real temp directory so the Logger constructor doesn't
        // hit file_put_contents on a non-existent path.
        $this->temp_log_dir = sys_get_temp_dir() . '/wc-mss-test-' . uniqid();
        mkdir($this->temp_log_dir . '/wc-mss-logs', 0777, true);

        $temp_dir = $this->temp_log_dir;
        // Use expect() because when() is already defined in the base class
        Functions\expect('wp_upload_dir')->andReturn([
            'basedir' => $temp_dir,
            'baseurl' => 'http://example.com/wp-content/uploads',
        ]);

        Functions\expect('wp_mkdir_p')->andReturn(true);
    }

    protected function tearDown(): void
    {
        // Reset Logger singleton so each test gets a fresh instance
        WC_Multi_Store_Logger::reset_instance();

        $logs_dir = $this->temp_log_dir . '/wc-mss-logs';
        if (is_dir($logs_dir)) {
            array_map('unlink', glob($logs_dir . '/*') ?: []);
            @rmdir($logs_dir);
        }
        @rmdir($this->temp_log_dir);

        parent::tearDown();
    }

    /**
     * Test default constants
     */
    public function test_default_constants(): void
    {
        $this->assertEquals(10485760, WC_Multi_Store_Logger::DEFAULT_MAX_LOG_SIZE); // 10MB
        $this->assertEquals(10, WC_Multi_Store_Logger::DEFAULT_MAX_BACKUP_FILES);
        $this->assertEquals(7, WC_Multi_Store_Logger::DEFAULT_ROTATION_DAYS);
    }

    /**
     * Test get_rotation_settings returns all keys
     */
    public function test_get_rotation_settings_returns_all_keys(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $settings = WC_Multi_Store_Logger::get_rotation_settings();

        $this->assertArrayHasKey('max_log_size', $settings);
        $this->assertArrayHasKey('max_log_size_mb', $settings);
        $this->assertArrayHasKey('max_backup_files', $settings);
        $this->assertArrayHasKey('rotation_days', $settings);
    }

    /**
     * Test get_rotation_settings uses default values
     */
    public function test_get_rotation_settings_uses_defaults(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $settings = WC_Multi_Store_Logger::get_rotation_settings();

        $this->assertEquals(WC_Multi_Store_Logger::DEFAULT_MAX_LOG_SIZE, $settings['max_log_size']);
        $this->assertEquals(10, $settings['max_log_size_mb']); // 10MB
        $this->assertEquals(WC_Multi_Store_Logger::DEFAULT_MAX_BACKUP_FILES, $settings['max_backup_files']);
        $this->assertEquals(WC_Multi_Store_Logger::DEFAULT_ROTATION_DAYS, $settings['rotation_days']);
    }

    /**
     * Test get_rotation_settings uses custom values
     */
    public function test_get_rotation_settings_uses_custom_values(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array(
                'max_log_size' => 5242880, // 5MB
                'max_backup_files' => 5,
                'rotation_days' => 14,
            ));

        $settings = WC_Multi_Store_Logger::get_rotation_settings();

        $this->assertEquals(5242880, $settings['max_log_size']);
        $this->assertEquals(5, $settings['max_log_size_mb']);
        $this->assertEquals(5, $settings['max_backup_files']);
        $this->assertEquals(14, $settings['rotation_days']);
    }

    /**
     * Test get_rotation_settings enforces min/max limits
     */
    public function test_get_rotation_settings_enforces_limits(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array(
                'max_log_size' => 100, // Too small, min is 1MB
                'max_backup_files' => 100, // Too high, max is 20
                'rotation_days' => 100, // Too high, max is 30
            ));

        $settings = WC_Multi_Store_Logger::get_rotation_settings();

        // Should be clamped to limits
        $this->assertGreaterThanOrEqual(1048576, $settings['max_log_size']); // Min 1MB
        $this->assertLessThanOrEqual(20, $settings['max_backup_files']); // Max 20
        $this->assertLessThanOrEqual(30, $settings['rotation_days']); // Max 30
    }

    /**
     * Test should_rotate_by_time returns false on first check
     */
    public function test_should_rotate_by_time_first_check(): void
    {
        Functions\expect('get_option')
            ->times(2)
            ->andReturnValues(array(
                array(), // Settings
                0, // Last rotation (not set)
            ));

        Functions\expect('update_option')
            ->once()
            ->andReturn(true);

        $logger = new WC_Multi_Store_Logger();

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('should_rotate_by_time');

        $result = $method->invoke($logger);

        $this->assertFalse($result);
    }

    /**
     * Test should_rotate_by_time returns true when days exceeded
     */
    public function test_should_rotate_by_time_days_exceeded(): void
    {
        $eight_days_ago = time() - (8 * DAY_IN_SECONDS);

        Functions\expect('get_option')
            ->times(2)
            ->andReturnValues(array(
                array('rotation_days' => 7), // Settings
                $eight_days_ago, // Last rotation
            ));

        $logger = new WC_Multi_Store_Logger();

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('should_rotate_by_time');

        $result = $method->invoke($logger);

        $this->assertTrue($result);
    }

    /**
     * Test should_rotate_by_time returns false when within rotation period
     */
    public function test_should_rotate_by_time_within_period(): void
    {
        $two_days_ago = time() - (2 * DAY_IN_SECONDS);

        Functions\expect('get_option')
            ->times(2)
            ->andReturnValues(array(
                array('rotation_days' => 7), // Settings
                $two_days_ago, // Last rotation
            ));

        $logger = new WC_Multi_Store_Logger();

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('should_rotate_by_time');

        $result = $method->invoke($logger);

        $this->assertFalse($result);
    }

    /**
     * Test flush_buffer handles empty buffer
     */
    public function test_flush_buffer_handles_empty(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $logger = new WC_Multi_Store_Logger();
        $logger->flush_buffer();

        // Should not throw any errors
        $this->assertTrue(true);
    }

    /**
     * Test clear_log removes log file
     */
    public function test_clear_log_returns_true_when_no_file(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        $logger = new WC_Multi_Store_Logger();

        // When file doesn't exist, should return true
        $result = $logger->clear_log();

        $this->assertTrue($result);
    }

    /**
     * Test get_log returns empty string when no file
     */
    public function test_get_log_returns_empty_when_no_file(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_settings', array())
            ->andReturn(array());

        // Remove any leftover log file from previous test runs
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/wc-mss-logs/sync.log';
        @unlink($log_file);

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
