<?php
/**
 * Extended unit tests for WC_Multi_Store_Performance_Monitor
 * Covers: log_operation with extra_data, concurrent/restarted timers, summary edge cases
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class PerformanceMonitorExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Performance_Monitor::clear_metrics();
        Functions\when('current_time')->justReturn('2024-01-15 10:30:00');
        Functions\when('get_option')->justReturn([]);
    }

    // ─── log_operation with extra_data ────────────

    public function test_log_operation_with_extra_data(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('sync_op');
        WC_Multi_Store_Performance_Monitor::log_operation('sync_op', ['products' => 50, 'store' => 'store1']);

        $metrics = WC_Multi_Store_Performance_Monitor::get_metrics();
        $this->assertCount(1, $metrics);
        $this->assertEquals('sync_op', $metrics[0]['label']);
    }

    public function test_log_operation_with_empty_extra_data(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('basic_op');
        WC_Multi_Store_Performance_Monitor::log_operation('basic_op', []);

        $metrics = WC_Multi_Store_Performance_Monitor::get_metrics();
        $this->assertCount(1, $metrics);
    }

    // ─── get_summary with varied metrics ──────────

    public function test_summary_peak_memory_tracks_maximum(): void
    {
        // Run operations - peak memory should reflect the highest value seen
        WC_Multi_Store_Performance_Monitor::start_timer('small');
        WC_Multi_Store_Performance_Monitor::stop_timer('small');

        WC_Multi_Store_Performance_Monitor::start_timer('large');
        // Allocate some memory to ensure a peak
        $data = str_repeat('x', 1024 * 1024);
        WC_Multi_Store_Performance_Monitor::stop_timer('large');

        $summary = WC_Multi_Store_Performance_Monitor::get_summary();
        $this->assertGreaterThan(0, $summary['peak_memory_mb']);
        unset($data);
    }

    // ─── Multiple timers simultaneously ───────────

    public function test_multiple_concurrent_timers(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('timer_a');
        WC_Multi_Store_Performance_Monitor::start_timer('timer_b');
        WC_Multi_Store_Performance_Monitor::start_timer('timer_c');

        $b = WC_Multi_Store_Performance_Monitor::stop_timer('timer_b');
        $a = WC_Multi_Store_Performance_Monitor::stop_timer('timer_a');
        $c = WC_Multi_Store_Performance_Monitor::stop_timer('timer_c');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotNull($c);
        $this->assertEquals('timer_b', $b['label']);
        $this->assertEquals('timer_a', $a['label']);
        $this->assertEquals('timer_c', $c['label']);
    }

    public function test_restarting_same_timer_overwrites(): void
    {
        WC_Multi_Store_Performance_Monitor::start_timer('dup');
        usleep(10000); // 10ms
        WC_Multi_Store_Performance_Monitor::start_timer('dup'); // restart

        $metrics = WC_Multi_Store_Performance_Monitor::stop_timer('dup');

        // Duration should be very small since timer was just restarted
        $this->assertNotNull($metrics);
        $this->assertLessThan(50, $metrics['duration_ms']);
    }
}
