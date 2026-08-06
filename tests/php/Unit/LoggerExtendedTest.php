<?php
/**
 * Extended unit tests for WC_Multi_Store_Logger
 * Tests write/do_log, log_product_sync, write_to_file buffering, flush+rotate
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class LoggerExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    private function createLogger(): WC_Multi_Store_Logger
    {
        return new WC_Multi_Store_Logger();
    }

    private function getBuffer(WC_Multi_Store_Logger $logger): array
    {
        $ref = new ReflectionClass($logger);
        $bufProp = $ref->getProperty('buffer');
        return $bufProp->getValue($logger);
    }

    /**
     * Get the Logger's actual log_file path (resolved by bootstrap's wp_upload_dir mock)
     */
    private function getActualLogFile(WC_Multi_Store_Logger $logger): string
    {
        $ref = new ReflectionClass($logger);
        $prop = $ref->getProperty('log_file');
        return $prop->getValue($logger);
    }

    /**
     * Ensure the Logger's log directory exists and clean any stale files
     */
    private function prepareLogDir(string $log_file): void
    {
        $dir = dirname($log_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        @unlink($log_file);
        array_map('unlink', glob($dir . '/*.bak') ?: []);
    }

    // ── write / do_log ───────────────────────────────────────────

    public function test_write_adds_to_buffer(): void
    {
        $logger = $this->createLogger();
        $logger->log('info', 'Test message');

        $buffer = $this->getBuffer($logger);
        $this->assertCount(1, $buffer);
        $this->assertStringContainsString('Test message', $buffer[0]);
        $this->assertStringContainsString('[INFO]', $buffer[0]);
    }

    public function test_write_with_context(): void
    {
        $logger = $this->createLogger();
        $logger->log('error', 'Something failed', ['sku' => 'ABC-123']);

        $buffer = $this->getBuffer($logger);
        $this->assertStringContainsString('[ERROR]', $buffer[0]);
        $this->assertStringContainsString('Context:', $buffer[0]);
        $this->assertStringContainsString('ABC-123', $buffer[0]);
    }

    public function test_static_write_method(): void
    {
        WC_Multi_Store_Logger::write('Static message', 'warning');

        $instance = WC_Multi_Store_Logger::instance();
        $buffer = $this->getBuffer($instance);

        $this->assertNotEmpty($buffer);
        $this->assertStringContainsString('[WARNING]', $buffer[0]);
        $this->assertStringContainsString('Static message', $buffer[0]);
    }

    // ── write_to_file buffering & auto-flush ─────────────────────

    public function test_buffer_auto_flushes_at_limit(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile($logger);
        $this->prepareLogDir($log_file);

        // Buffer size is 10, so write 10 messages to trigger auto-flush
        for ($i = 1; $i <= 10; $i++) {
            $logger->log('info', "Message $i");
        }

        // After 10 writes, buffer should have auto-flushed
        $this->assertFileExists($log_file);
        $content = file_get_contents($log_file);
        $this->assertStringContainsString('Message 1', $content);
        $this->assertStringContainsString('Message 10', $content);
    }

    public function test_buffer_does_not_flush_before_limit(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile($logger);
        $this->prepareLogDir($log_file);

        // Write fewer than buffer_size (10) messages
        for ($i = 1; $i <= 5; $i++) {
            $logger->log('info', "Buffered $i");
        }

        // File should NOT exist yet (not flushed)
        $this->assertFileDoesNotExist($log_file);

        // Now manually flush
        $logger->flush_buffer();
        $this->assertFileExists($log_file);
        $this->assertStringContainsString('Buffered 5', file_get_contents($log_file));
    }

    // ── flush_buffer edge cases ──────────────────────────────────

    public function test_flush_buffer_handles_empty_buffer(): void
    {
        $logger = $this->createLogger();
        $logger->flush_buffer(); // Empty buffer
        $this->assertTrue(true); // No error
    }

    // ── PSR-3 convenience methods ────────────────────────────────

    public function test_psr3_error_method(): void
    {
        $logger = $this->createLogger();
        $logger->error('Critical failure');

        $buffer = $this->getBuffer($logger);
        $this->assertStringContainsString('[ERROR]', $buffer[0]);
    }

    public function test_psr3_warning_method(): void
    {
        $logger = $this->createLogger();
        $logger->warning('Something concerning');

        $buffer = $this->getBuffer($logger);
        $this->assertStringContainsString('[WARNING]', $buffer[0]);
    }

    public function test_psr3_debug_method(): void
    {
        $logger = $this->createLogger();
        $logger->debug('Debug info');

        $buffer = $this->getBuffer($logger);
        $this->assertStringContainsString('[DEBUG]', $buffer[0]);
    }

    // ── log_product_sync ─────────────────────────────────────────

    public function test_log_product_sync_success(): void
    {
        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_sku')->andReturn('SYNC-SKU');
        Functions\when('wc_get_product')->justReturn($mock_product);

        // log_product_sync calls self::write() which uses the singleton
        $logger = WC_Multi_Store_Logger::instance();
        $logger->log_product_sync(42, 'created', 'https://store1.com', true);

        $buffer = $this->getBuffer($logger);
        $this->assertNotEmpty($buffer);
        $this->assertStringContainsString('SYNC-SKU', $buffer[0]);
        $this->assertStringContainsString('created', $buffer[0]);
        $this->assertStringContainsString('SUCCESS', $buffer[0]);
        $this->assertStringContainsString('[INFO]', $buffer[0]);
    }

    public function test_log_product_sync_failure(): void
    {
        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_sku')->andReturn('FAIL-SKU');
        Functions\when('wc_get_product')->justReturn($mock_product);

        $logger = WC_Multi_Store_Logger::instance();
        $logger->log_product_sync(42, 'updated', 'https://store1.com', false, 'API timeout');

        $buffer = $this->getBuffer($logger);
        $this->assertNotEmpty($buffer);
        $this->assertStringContainsString('FAILED', $buffer[0]);
        $this->assertStringContainsString('[ERROR]', $buffer[0]);
        $this->assertStringContainsString('API timeout', $buffer[0]);
    }

    public function test_log_product_sync_product_not_found(): void
    {
        Functions\when('wc_get_product')->justReturn(null);

        $logger = WC_Multi_Store_Logger::instance();
        $logger->log_product_sync(999, 'deleted', 'https://store1.com', true);

        $buffer = $this->getBuffer($logger);
        $this->assertNotEmpty($buffer);
        $this->assertStringContainsString('N/A', $buffer[0]);
    }

    // ── get_log with actual file ─────────────────────────────────

    public function test_get_log_reads_file_content(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile($logger);
        $this->prepareLogDir($log_file);
        file_put_contents($log_file, "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\n");

        $content = $logger->get_log(3);

        $this->assertStringContainsString('Line 3', $content);
        $this->assertStringContainsString('Line 5', $content);
    }

    public function test_get_log_all_lines(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile($logger);
        $this->prepareLogDir($log_file);
        file_put_contents($log_file, "Line 1\nLine 2\n");

        $content = $logger->get_log(0); // 0 = all lines

        $this->assertStringContainsString('Line 1', $content);
        $this->assertStringContainsString('Line 2', $content);
    }

    // ── clear_log ────────────────────────────────────────────────

    public function test_clear_log_removes_existing_file(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile($logger);
        $this->prepareLogDir($log_file);
        file_put_contents($log_file, "Some log content\n");
        $this->assertFileExists($log_file);

        $result = $logger->clear_log();

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($log_file);
    }

    // ── rotate_log via flush ─────────────────────────────────────

    public function test_flush_triggers_size_rotation(): void
    {
        $logger = $this->createLogger();
        $ref = new ReflectionClass($logger);

        $log_file = $this->getActualLogFile($logger);
        $logs_dir = dirname($log_file);
        $this->prepareLogDir($log_file);

        // Set a very small max_log_size so we don't need a huge file
        $maxProp = $ref->getProperty('max_log_size');
        $maxProp->setValue($logger, 100); // 100 bytes

        // Create a file larger than 100 bytes
        file_put_contents($log_file, str_repeat('A', 200));
        clearstatcache(true, $log_file);

        // Write enough to trigger buffer flush (buffer_size = 10)
        for ($i = 0; $i < 10; $i++) {
            $logger->log('info', 'Trigger flush');
        }

        // After flush, old file should be rotated (renamed to .bak)
        $bak_files = glob($logs_dir . '/*.bak');
        $this->assertNotEmpty($bak_files, 'Log file should have been rotated to .bak');

        // Cleanup
        array_map('unlink', glob($logs_dir . '/*.bak') ?: []);
        @unlink($log_file);
    }

    // ── singleton ────────────────────────────────────────────────

    public function test_singleton_returns_same_instance(): void
    {
        $instance1 = WC_Multi_Store_Logger::instance();
        $instance2 = WC_Multi_Store_Logger::instance();

        $this->assertSame($instance1, $instance2);
    }

    public function test_reset_instance_clears_singleton(): void
    {
        $instance1 = WC_Multi_Store_Logger::instance();
        WC_Multi_Store_Logger::reset_instance();
        $instance2 = WC_Multi_Store_Logger::instance();

        $this->assertNotSame($instance1, $instance2);
    }
}
