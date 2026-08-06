<?php
/**
 * Unit tests for WC_Multi_Store_Time_Manager
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class TimeManagerTest extends WC_Multi_Store_TestCase
{
    /**
     * Test default constants
     */
    public function test_default_constants(): void
    {
        $this->assertEquals(6, WC_Multi_Store_Time_Manager::DEFAULT_PEAK_START);
        $this->assertEquals(23, WC_Multi_Store_Time_Manager::DEFAULT_PEAK_END);
        $this->assertEquals(30, WC_Multi_Store_Time_Manager::DEFAULT_BATCH_SIZE_PEAK);
        $this->assertEquals(30, WC_Multi_Store_Time_Manager::DEFAULT_BATCH_SIZE_OFF_PEAK);
    }

    /**
     * Test is_off_peak during off-peak hours (before peak start)
     */
    public function test_is_off_peak_before_peak_hours(): void
    {
        Functions\when('get_option')->justReturn(array('peak_start_hour' => 6, 'peak_end_hour' => 23));

        // Mock current_datetime to return 3:00 AM (off-peak)
        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 03:00:00'));

        $result = WC_Multi_Store_Time_Manager::is_off_peak();

        $this->assertTrue($result);
    }

    /**
     * Test is_off_peak during peak hours
     */
    public function test_is_off_peak_during_peak_hours(): void
    {
        Functions\when('get_option')->justReturn(array('peak_start_hour' => 6, 'peak_end_hour' => 23));

        // Mock current_datetime to return 12:00 PM (peak)
        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 12:00:00'));

        $result = WC_Multi_Store_Time_Manager::is_off_peak();

        $this->assertFalse($result);
    }

    /**
     * Test is_off_peak after peak hours
     */
    public function test_is_off_peak_after_peak_hours(): void
    {
        Functions\when('get_option')->justReturn(array('peak_start_hour' => 6, 'peak_end_hour' => 23));

        // Mock current_datetime to return 11:30 PM (off-peak, after 23:00)
        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 23:30:00'));

        $result = WC_Multi_Store_Time_Manager::is_off_peak();

        $this->assertTrue($result);
    }

    /**
     * Test get_sync_type returns full_product when force_full_sync is enabled
     */
    public function test_get_sync_type_force_full_sync(): void
    {
        Functions\when('get_option')->justReturn(array('force_full_sync' => true));

        $result = WC_Multi_Store_Time_Manager::get_sync_type();

        $this->assertEquals('full_product', $result);
    }

    /**
     * Test get_sync_type returns full_product during off-peak
     */
    public function test_get_sync_type_off_peak(): void
    {
        Functions\when('get_option')->justReturn(array('peak_start_hour' => 6, 'peak_end_hour' => 23));

        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 03:00:00'));

        $result = WC_Multi_Store_Time_Manager::get_sync_type();

        $this->assertEquals('full_product', $result);
    }

    /**
     * Test get_sync_type returns price_quantity during peak hours
     */
    public function test_get_sync_type_peak_hours(): void
    {
        Functions\when('get_option')->justReturn(array('peak_start_hour' => 6, 'peak_end_hour' => 23));

        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 12:00:00'));

        $result = WC_Multi_Store_Time_Manager::get_sync_type();

        $this->assertEquals('price_quantity', $result);
    }

    /**
     * Test get_batch_size returns off-peak size during off-peak hours
     */
    public function test_get_batch_size_off_peak(): void
    {
        Functions\when('get_option')->justReturn(array(
            'peak_start_hour' => 6,
            'peak_end_hour' => 23,
            'batch_size_offpeak' => 50,
        ));

        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 03:00:00'));

        $result = WC_Multi_Store_Time_Manager::get_batch_size();

        $this->assertEquals(50, $result);
    }

    /**
     * Test get_batch_size returns peak size during peak hours
     */
    public function test_get_batch_size_peak(): void
    {
        Functions\when('get_option')->justReturn(array(
            'peak_start_hour' => 6,
            'peak_end_hour' => 23,
            'batch_size_peak' => 10,
        ));

        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 12:00:00'));

        $result = WC_Multi_Store_Time_Manager::get_batch_size();

        $this->assertEquals(10, $result);
    }

    /**
     * Test get_batch_size returns off-peak when force_full_sync
     */
    public function test_get_batch_size_force_full_sync(): void
    {
        Functions\when('get_option')->justReturn(array(
            'force_full_sync' => true,
            'batch_size_offpeak' => 100,
        ));

        $result = WC_Multi_Store_Time_Manager::get_batch_size();

        $this->assertEquals(100, $result);
    }

    /**
     * Test get_time_period returns correct string for off-peak
     */
    public function test_get_time_period_off_peak(): void
    {
        Functions\when('get_option')->justReturn(array('peak_start_hour' => 6, 'peak_end_hour' => 23));

        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 03:00:00'));

        $result = WC_Multi_Store_Time_Manager::get_time_period();

        $this->assertEquals('Off-Peak Hours', $result);
    }

    /**
     * Test get_time_period returns correct string for peak
     */
    public function test_get_time_period_peak(): void
    {
        Functions\when('get_option')->justReturn(array('peak_start_hour' => 6, 'peak_end_hour' => 23));

        Functions\expect('current_datetime')
            ->once()
            ->andReturn(new \DateTimeImmutable('2025-01-26 12:00:00'));

        $result = WC_Multi_Store_Time_Manager::get_time_period();

        $this->assertEquals('Peak Hours', $result);
    }
}
