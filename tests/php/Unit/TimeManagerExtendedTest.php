<?php
/**
 * Extended unit tests for WC_Multi_Store_Time_Manager
 * Tests get_settings_info, estimate_sync_time
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class TimeManagerExtendedTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
    }

    // ── get_settings_info ────────────────────────────────────────

    public function test_get_settings_info_returns_all_keys(): void
    {
        Functions\when('get_option')->justReturn([]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('14'); // 2 PM = peak
        Functions\when('current_datetime')->justReturn($mock_dt);

        $info = WC_Multi_Store_Time_Manager::get_settings_info();

        $this->assertArrayHasKey('peak_start', $info);
        $this->assertArrayHasKey('peak_end', $info);
        $this->assertArrayHasKey('batch_size_peak', $info);
        $this->assertArrayHasKey('batch_size_offpeak', $info);
        $this->assertArrayHasKey('current_period', $info);
        $this->assertArrayHasKey('is_off_peak', $info);
        $this->assertArrayHasKey('current_sync_type', $info);
        $this->assertArrayHasKey('current_batch_size', $info);
    }

    public function test_get_settings_info_defaults(): void
    {
        Functions\when('get_option')->justReturn([]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('14');
        Functions\when('current_datetime')->justReturn($mock_dt);

        $info = WC_Multi_Store_Time_Manager::get_settings_info();

        $this->assertEquals(6, $info['peak_start']);
        $this->assertEquals(23, $info['peak_end']);
        $this->assertEquals(30, $info['batch_size_peak']);
        $this->assertEquals(30, $info['batch_size_offpeak']);
    }

    public function test_get_settings_info_custom_values(): void
    {
        Functions\when('get_option')->justReturn([
            'peak_start_hour' => 8,
            'peak_end_hour' => 20,
            'batch_size_peak' => 15,
            'batch_size_offpeak' => 50,
        ]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('10'); // Within custom peak
        Functions\when('current_datetime')->justReturn($mock_dt);

        $info = WC_Multi_Store_Time_Manager::get_settings_info();

        $this->assertEquals(8, $info['peak_start']);
        $this->assertEquals(20, $info['peak_end']);
        $this->assertEquals(15, $info['batch_size_peak']);
        $this->assertEquals(50, $info['batch_size_offpeak']);
        $this->assertFalse($info['is_off_peak']);
        $this->assertEquals('Peak Hours', $info['current_period']);
    }

    public function test_get_settings_info_off_peak(): void
    {
        Functions\when('get_option')->justReturn([]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('3'); // 3 AM = off-peak
        Functions\when('current_datetime')->justReturn($mock_dt);

        $info = WC_Multi_Store_Time_Manager::get_settings_info();

        $this->assertTrue($info['is_off_peak']);
        $this->assertEquals('Off-Peak Hours', $info['current_period']);
        $this->assertEquals('full_product', $info['current_sync_type']);
    }

    // ── estimate_sync_time ───────────────────────────────────────

    public function test_estimate_sync_time_full_product(): void
    {
        Functions\when('get_option')->justReturn([]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('3'); // Off-peak → full_product
        Functions\when('current_datetime')->justReturn($mock_dt);

        $result = WC_Multi_Store_Time_Manager::estimate_sync_time(100, 2);

        $this->assertArrayHasKey('total_operations', $result);
        $this->assertArrayHasKey('estimated_seconds', $result);
        $this->assertArrayHasKey('estimated_minutes', $result);
        $this->assertArrayHasKey('batches_needed', $result);
        $this->assertArrayHasKey('sync_type', $result);
        $this->assertArrayHasKey('batch_size', $result);

        $this->assertEquals(200, $result['total_operations']); // 100 * 2
        $this->assertEquals('full_product', $result['sync_type']);
        // 200 operations * 3 seconds = 600 seconds
        $this->assertEquals(600, $result['estimated_seconds']);
        $this->assertEquals(10.0, $result['estimated_minutes']);
    }

    public function test_estimate_sync_time_price_quantity(): void
    {
        Functions\when('get_option')->justReturn([]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('14'); // Peak → price_quantity
        Functions\when('current_datetime')->justReturn($mock_dt);

        $result = WC_Multi_Store_Time_Manager::estimate_sync_time(50, 1);

        $this->assertEquals(50, $result['total_operations']);
        $this->assertEquals('price_quantity', $result['sync_type']);
        // 50 * 1.5 = 75 seconds
        $this->assertEquals(75, $result['estimated_seconds']);
    }

    public function test_estimate_sync_time_batches_calculation(): void
    {
        Functions\when('get_option')->justReturn(['batch_size_offpeak' => 25]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('3');
        Functions\when('current_datetime')->justReturn($mock_dt);

        $result = WC_Multi_Store_Time_Manager::estimate_sync_time(100, 1);

        $this->assertEquals(25, $result['batch_size']);
        $this->assertEquals(4, $result['batches_needed']); // 100 / 25 = 4
    }

    public function test_estimate_sync_time_single_product(): void
    {
        Functions\when('get_option')->justReturn([]);

        $mock_dt = \Mockery::mock('DateTimeImmutable');
        $mock_dt->shouldReceive('format')->with('G')->andReturn('14');
        Functions\when('current_datetime')->justReturn($mock_dt);

        $result = WC_Multi_Store_Time_Manager::estimate_sync_time(1, 1);

        $this->assertEquals(1, $result['total_operations']);
        $this->assertEquals(1, $result['batches_needed']);
    }
}
