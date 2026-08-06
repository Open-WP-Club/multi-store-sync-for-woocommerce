<?php
/**
 * Unit tests for WC_Multi_Store_Performance_Monitor
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class PerformanceMonitorTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear all metrics/timers between tests
        WC_Multi_Store_Performance_Monitor::clear_metrics();

        Functions\when('current_time')->justReturn('2024-01-15 10:30:00');
        Functions\when('get_option')->justReturn([]);
    }

    // ─── Timer lifecycle ───────────────────────────

    public function test_start_timer_creates_timer(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('test_op');

        // Stopping the timer should return metrics (proving it was started)
        $metrics = WC_Multi_Store_Performance_Monitor::stop_timer('test_op');

        $this->assertNotNull($metrics);
        $this->assertEquals('test_op', $metrics['label']);
    }

    public function test_stop_timer_returns_null_for_unknown_label(): void
    {
        $result = WC_Multi_Store_Performance_Monitor::stop_timer('nonexistent');

        $this->assertNull($result);
    }

    public function test_stop_timer_returns_duration_and_memory(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('duration_test');
        // Small allocation to ensure measurable memory delta
        $data = str_repeat('x', 1024);
        $metrics = WC_Multi_Store_Performance_Monitor::stop_timer('duration_test');

        $this->assertArrayHasKey('duration_ms', $metrics);
        $this->assertArrayHasKey('memory_used_mb', $metrics);
        $this->assertArrayHasKey('peak_memory_mb', $metrics);
        $this->assertArrayHasKey('timestamp', $metrics);
        $this->assertIsFloat($metrics['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $metrics['duration_ms']);
    }

    public function test_stop_timer_removes_timer(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('once');
        WC_Multi_Store_Performance_Monitor::stop_timer('once');

        // Second stop should return null - timer was removed
        $this->assertNull(WC_Multi_Store_Performance_Monitor::stop_timer('once'));
    }

    // ─── Metrics storage ───────────────────────────

    public function test_get_metrics_returns_empty_initially(): void
    {
        $this->assertSame([], WC_Multi_Store_Performance_Monitor::get_metrics());
    }

    public function test_stopped_timers_accumulate_in_metrics(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('op1');
        WC_Multi_Store_Performance_Monitor::stop_timer('op1');

        WC_Multi_Store_Performance_Monitor::start_timer('op2');
        WC_Multi_Store_Performance_Monitor::stop_timer('op2');

        $metrics = WC_Multi_Store_Performance_Monitor::get_metrics();
        $this->assertCount(2, $metrics);
        $this->assertEquals('op1', $metrics[0]['label']);
        $this->assertEquals('op2', $metrics[1]['label']);
    }

    // ─── clear_metrics ─────────────────────────────

    public function test_clear_metrics_resets_everything(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('a');
        WC_Multi_Store_Performance_Monitor::stop_timer('a');

        WC_Multi_Store_Performance_Monitor::start_timer('b');
        // Timer 'b' is still active

        WC_Multi_Store_Performance_Monitor::clear_metrics();

        $this->assertSame([], WC_Multi_Store_Performance_Monitor::get_metrics());
        // Active timer should also be cleared
        $this->assertNull(WC_Multi_Store_Performance_Monitor::stop_timer('b'));
    }

    // ─── Summary ───────────────────────────────────

    public function test_get_summary_with_no_metrics(): void
    {
        $summary = WC_Multi_Store_Performance_Monitor::get_summary();

        $this->assertEquals(0, $summary['total_operations']);
        $this->assertEquals(0, $summary['total_duration_ms']);
        $this->assertEquals(0, $summary['avg_duration_ms']);
        $this->assertEquals(0, $summary['total_memory_mb']);
        $this->assertEquals(0, $summary['avg_memory_mb']);
        $this->assertEquals(0, $summary['peak_memory_mb']);
    }

    public function test_get_summary_aggregates_metrics(): void
    {
        // Run two timed operations
        WC_Multi_Store_Performance_Monitor::start_timer('op1');
        WC_Multi_Store_Performance_Monitor::stop_timer('op1');

        WC_Multi_Store_Performance_Monitor::start_timer('op2');
        WC_Multi_Store_Performance_Monitor::stop_timer('op2');

        $summary = WC_Multi_Store_Performance_Monitor::get_summary();

        $this->assertEquals(2, $summary['total_operations']);
        $this->assertGreaterThanOrEqual(0, $summary['total_duration_ms']);
        $this->assertGreaterThanOrEqual(0, $summary['avg_duration_ms']);
        $this->assertArrayHasKey('peak_memory_mb', $summary);
    }

    // ─── System stats ──────────────────────────────

    public function test_get_system_stats_returns_expected_keys(): void
    {
        $stats = WC_Multi_Store_Performance_Monitor::get_system_stats();

        $this->assertArrayHasKey('memory_current_mb', $stats);
        $this->assertArrayHasKey('memory_peak_mb', $stats);
        $this->assertArrayHasKey('memory_limit', $stats);
        $this->assertArrayHasKey('time_limit', $stats);
        $this->assertArrayHasKey('php_version', $stats);
        $this->assertEquals(PHP_VERSION, $stats['php_version']);
    }

    // ─── log_operation ─────────────────────────────

    public function test_log_operation_stops_timer_and_records_metric(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('logged_op');
        WC_Multi_Store_Performance_Monitor::log_operation('logged_op');

        // Timer should be removed after log_operation
        $this->assertNull(WC_Multi_Store_Performance_Monitor::stop_timer('logged_op'));

        // Metric should have been recorded
        $metrics = WC_Multi_Store_Performance_Monitor::get_metrics();
        $this->assertNotEmpty($metrics);
    }

    public function test_log_operation_does_nothing_for_unknown_timer(): void
    {
        $count_before = count(WC_Multi_Store_Performance_Monitor::get_metrics());

        WC_Multi_Store_Performance_Monitor::log_operation('nonexistent');

        $count_after = count(WC_Multi_Store_Performance_Monitor::get_metrics());
        $this->assertEquals($count_before, $count_after);
    }
}
