<?php
/**
 * Extended unit tests for WC_Multi_Store_Logger
 * Tests write/do_log delegation to wc_get_logger(), log_product_sync,
 * and file-based get_log/clear_log against WooCommerce's own log file.
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
        @unlink($this->getActualLogFile());
        parent::tearDown();
    }

    private function createLogger(): WC_Multi_Store_Logger
    {
        return new WC_Multi_Store_Logger();
    }

    /**
     * Mock wc_get_logger() to return a Mockery double, so tests can assert
     * exactly what WC_Multi_Store_Logger delegates to it.
     */
    private function mockWcLogger(): \Mockery\MockInterface
    {
        $wcLogger = \Mockery::mock();
        Functions\when('wc_get_logger')->justReturn($wcLogger);
        return $wcLogger;
    }

    /**
     * The Logger's actual log_file path (resolved by the WC_Log_Handler_File stub)
     */
    private function getActualLogFile(): string
    {
        return WC_Log_Handler_File::get_log_file_path(WC_Multi_Store_Logger::LOG_HANDLE);
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
    }

    // ── write / do_log delegate to wc_get_logger() ────────────────

    public function test_write_delegates_to_wc_logger(): void
    {
        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('warning', 'Static message', ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        WC_Multi_Store_Logger::write('Static message', 'warning');

        $this->assertTrue(true); // Mockery::close() in tearDown() verifies the ->with() expectation above
    }

    public function test_write_with_context_string_passes_through(): void
    {
        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('info', 'Message', ['context' => 'extra info', 'source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        WC_Multi_Store_Logger::write('Message', 'info', 'extra info');

        $this->assertTrue(true);
    }

    public function test_log_delegates_to_wc_logger_with_context(): void
    {
        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('error', 'Something failed', ['sku' => 'ABC-123', 'source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = $this->createLogger();
        $logger->log('error', 'Something failed', ['sku' => 'ABC-123']);

        $this->assertTrue(true);
    }

    public function test_log_without_context(): void
    {
        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('info', 'Test message', ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = $this->createLogger();
        $logger->log('info', 'Test message');

        $this->assertTrue(true);
    }

    // ── PSR-3 convenience methods ────────────────────────────────

    public function test_psr3_error_method(): void
    {
        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('error', 'Critical failure', ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = $this->createLogger();
        $logger->error('Critical failure');

        $this->assertTrue(true);
    }

    public function test_psr3_warning_method(): void
    {
        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('warning', 'Something concerning', ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = $this->createLogger();
        $logger->warning('Something concerning');

        $this->assertTrue(true);
    }

    public function test_psr3_debug_method(): void
    {
        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('debug', 'Debug info', ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = $this->createLogger();
        $logger->debug('Debug info');

        $this->assertTrue(true);
    }

    // ── log_product_sync ─────────────────────────────────────────

    public function test_log_product_sync_success(): void
    {
        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_sku')->andReturn('SYNC-SKU');
        Functions\when('wc_get_product')->justReturn($mock_product);

        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('info', \Mockery::on(function (string $message): bool {
                return str_contains($message, 'SYNC-SKU')
                    && str_contains($message, 'created')
                    && str_contains($message, 'SUCCESS');
            }), ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = WC_Multi_Store_Logger::instance();
        $logger->log_product_sync(42, 'created', 'https://store1.com', true);

        $this->assertTrue(true);
    }

    public function test_log_product_sync_failure(): void
    {
        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_sku')->andReturn('FAIL-SKU');
        Functions\when('wc_get_product')->justReturn($mock_product);

        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('error', \Mockery::on(function (string $message): bool {
                return str_contains($message, 'FAILED')
                    && str_contains($message, 'API timeout');
            }), ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = WC_Multi_Store_Logger::instance();
        $logger->log_product_sync(42, 'updated', 'https://store1.com', false, 'API timeout');

        $this->assertTrue(true);
    }

    public function test_log_product_sync_product_not_found(): void
    {
        Functions\when('wc_get_product')->justReturn(null);

        $wcLogger = $this->mockWcLogger();
        $wcLogger->shouldReceive('log')
            ->once()
            ->with('info', \Mockery::on(fn(string $message): bool => str_contains($message, 'N/A')), ['source' => WC_Multi_Store_Logger::LOG_HANDLE]);

        $logger = WC_Multi_Store_Logger::instance();
        $logger->log_product_sync(999, 'deleted', 'https://store1.com', true);

        $this->assertTrue(true);
    }

    // ── get_log with actual file ─────────────────────────────────

    public function test_get_log_reads_file_content(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile();
        $this->prepareLogDir($log_file);
        file_put_contents($log_file, "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\n");

        $content = $logger->get_log(3);

        $this->assertStringContainsString('Line 3', $content);
        $this->assertStringContainsString('Line 5', $content);
    }

    public function test_get_log_all_lines(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile();
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
        $log_file = $this->getActualLogFile();
        $this->prepareLogDir($log_file);
        file_put_contents($log_file, "Some log content\n");
        $this->assertFileExists($log_file);

        $result = $logger->clear_log();

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($log_file);
    }

    // ── clear_warnings_and_errors ──────────────────────────────────

    public function test_clear_warnings_and_errors_matches_wc_log_format(): void
    {
        $logger = $this->createLogger();
        $log_file = $this->getActualLogFile();
        $this->prepareLogDir($log_file);
        file_put_contents(
            $log_file,
            "2024-01-15T12:00:00+00:00 INFO All good\n"
            . "2024-01-15T12:01:00+00:00 WARNING Something concerning\n"
            . "2024-01-15T12:02:00+00:00 ERROR It broke\n"
        );

        $result = $logger->clear_warnings_and_errors();

        $this->assertEquals(2, $result['removed']);
        $this->assertEquals(1, $result['kept']);
        $this->assertStringContainsString('All good', file_get_contents($log_file));
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
