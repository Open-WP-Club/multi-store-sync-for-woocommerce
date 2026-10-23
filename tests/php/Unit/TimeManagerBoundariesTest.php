<?php
/**
 * Boundary, timezone and corrupted-option tests for WC_Multi_Store_Time_Manager.
 *
 * TimeManagerTest covers the happy paths; this file pins the exact peak window
 * edges, wall-clock (not UTC) behaviour around DST transitions, and how the
 * manager copes with sloppy option values.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class TimeManagerBoundariesTest extends WC_Multi_Store_TestCase
{
    private function at(string $datetime, string $tz = 'UTC'): void
    {
        Functions\when('current_datetime')->justReturn(new \DateTimeImmutable($datetime, new \DateTimeZone($tz)));
    }

    /**
     * @param mixed $option Value returned by get_option for the scheduled settings.
     */
    private function option(mixed $option): void
    {
        Functions\when('get_option')->justReturn($option);
    }

    public static function peakEdgeProvider(): array
    {
        return [
            'one second before peak start' => ['2026-03-10 05:59:59', true],
            'first second of peak start hour' => ['2026-03-10 06:00:00', false],
            'last second of peak end hour minus one' => ['2026-03-10 22:59:59', false],
            'first second of peak end hour' => ['2026-03-10 23:00:00', true],
            'midnight' => ['2026-03-10 00:00:00', true],
            'last second of the day' => ['2026-03-10 23:59:59', true],
        ];
    }

    #[DataProvider('peakEdgeProvider')]
    public function test_default_window_edges_are_start_inclusive_end_exclusive(string $when, bool $expectedOffPeak): void
    {
        $this->option([]);
        $this->at($when);

        $this->assertSame($expectedOffPeak, WC_Multi_Store_Time_Manager::is_off_peak());
    }

    public static function scalarHourProvider(): array
    {
        return [
            'numeric strings' => ['8', '20'],
            'ints' => [8, 20],
            'floats' => [8.0, 20.0],
            'padded strings' => ['08', '20'],
        ];
    }

    #[DataProvider('scalarHourProvider')]
    public function test_hours_stored_as_strings_or_floats_behave_like_ints(mixed $start, mixed $end): void
    {
        $this->option(['peak_start_hour' => $start, 'peak_end_hour' => $end]);

        $this->at('2026-03-10 07:59:00');
        $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak());

        $this->at('2026-03-10 08:00:00');
        $this->assertFalse(WC_Multi_Store_Time_Manager::is_off_peak());

        $this->at('2026-03-10 19:59:59');
        $this->assertFalse(WC_Multi_Store_Time_Manager::is_off_peak());

        $this->at('2026-03-10 20:00:00');
        $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak());
    }

    public static function corruptedOptionProvider(): array
    {
        return [
            'false (option missing)' => [false],
            'empty string' => [''],
            'null' => [null],
            'empty array' => [[]],
        ];
    }

    #[DataProvider('corruptedOptionProvider')]
    public function test_non_array_option_falls_back_to_default_window(mixed $option): void
    {
        $this->option($option);

        $this->at('2026-03-10 12:00:00');
        $this->assertFalse(WC_Multi_Store_Time_Manager::is_off_peak(), 'noon is inside the default 6-23 peak window');

        $this->at('2026-03-10 03:00:00');
        $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak(), '3am is outside the default window');
    }

    public function test_only_peak_start_overridden_keeps_default_peak_end(): void
    {
        $this->option(['peak_start_hour' => 9]);

        $this->at('2026-03-10 08:00:00');
        $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak());

        $this->at('2026-03-10 22:00:00');
        $this->assertFalse(WC_Multi_Store_Time_Manager::is_off_peak(), 'default end hour 23 still applies');
    }

    public function test_peak_end_of_24_covers_the_whole_evening(): void
    {
        $this->option(['peak_start_hour' => 6, 'peak_end_hour' => 24]);

        $this->at('2026-03-10 23:59:59');
        $this->assertFalse(WC_Multi_Store_Time_Manager::is_off_peak());

        $this->at('2026-03-11 00:00:00');
        $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak());
    }

    public function test_hour_is_taken_from_the_site_wall_clock_not_utc(): void
    {
        $this->option(['peak_start_hour' => 6, 'peak_end_hour' => 23]);

        // Wall-clock hour in the site timezone decides the window, never the UTC hour.
        $this->at('2026-03-10 05:30:00', 'Europe/Sofia');
        $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak());

        $this->at('2026-03-10 08:00:00', 'Pacific/Honolulu');
        $this->assertFalse(WC_Multi_Store_Time_Manager::is_off_peak(), 'local 08:00 is peak even though UTC reads 18:00');

        $this->at('2026-03-10 23:30:00', 'Pacific/Honolulu');
        $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak(), 'local 23:30 is off-peak even though UTC reads 09:30 next day');
    }

    public function test_dst_spring_forward_skipped_hour_resolves_to_a_valid_hour(): void
    {
        // 2026-03-08 02:30 does not exist in New York; PHP rolls it to 03:30.
        $this->option(['peak_start_hour' => 3, 'peak_end_hour' => 4]);
        $this->at('2026-03-08 02:30:00', 'America/New_York');

        $this->assertFalse(WC_Multi_Store_Time_Manager::is_off_peak(), 'rolled-forward 03:30 is inside the 3-4 peak window');
    }

    public function test_dst_fall_back_repeated_hour_gives_same_answer_both_times(): void
    {
        $this->option(['peak_start_hour' => 1, 'peak_end_hour' => 2]);

        $first = new \DateTimeImmutable('2026-11-01 05:30:00', new \DateTimeZone('UTC'));
        $first = $first->setTimezone(new \DateTimeZone('America/New_York')); // 01:30 EDT
        $second = (new \DateTimeImmutable('2026-11-01 06:30:00', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('America/New_York')); // 01:30 EST

        $this->assertSame('01', $first->format('H'));
        $this->assertSame('01', $second->format('H'));

        Functions\when('current_datetime')->justReturn($first);
        $a = WC_Multi_Store_Time_Manager::is_off_peak();
        Functions\when('current_datetime')->justReturn($second);
        $b = WC_Multi_Store_Time_Manager::is_off_peak();

        $this->assertFalse($a);
        $this->assertFalse($b);
    }

    // ── get_batch_size ────────────────────────────────────────────

    public function test_batch_size_uses_defaults_when_option_has_no_batch_keys(): void
    {
        $this->option([]);

        $this->at('2026-03-10 12:00:00');
        $this->assertSame(WC_Multi_Store_Time_Manager::DEFAULT_BATCH_SIZE_PEAK, WC_Multi_Store_Time_Manager::get_batch_size());

        $this->at('2026-03-10 03:00:00');
        $this->assertSame(WC_Multi_Store_Time_Manager::DEFAULT_BATCH_SIZE_OFF_PEAK, WC_Multi_Store_Time_Manager::get_batch_size());
    }

    public function test_batch_size_switches_exactly_on_the_peak_boundary(): void
    {
        $this->option(['batch_size_peak' => 10, 'batch_size_offpeak' => 200]);

        $this->at('2026-03-10 05:59:59');
        $this->assertSame(200, WC_Multi_Store_Time_Manager::get_batch_size());

        $this->at('2026-03-10 06:00:00');
        $this->assertSame(10, WC_Multi_Store_Time_Manager::get_batch_size());

        $this->at('2026-03-10 23:00:00');
        $this->assertSame(200, WC_Multi_Store_Time_Manager::get_batch_size());
    }

    public function test_force_full_sync_uses_offpeak_batch_size_even_at_peak(): void
    {
        $this->option(['batch_size_peak' => 10, 'batch_size_offpeak' => 200, 'force_full_sync' => true]);
        $this->at('2026-03-10 12:00:00');

        $this->assertSame(200, WC_Multi_Store_Time_Manager::get_batch_size());
    }

    public function test_batch_size_strings_are_cast_to_int(): void
    {
        $this->option(['batch_size_peak' => '45', 'batch_size_offpeak' => '90']);

        $this->at('2026-03-10 12:00:00');
        $this->assertSame(45, WC_Multi_Store_Time_Manager::get_batch_size());

        $this->at('2026-03-10 02:00:00');
        $this->assertSame(90, WC_Multi_Store_Time_Manager::get_batch_size());
    }

    public function test_time_period_label_matches_is_off_peak_at_boundaries(): void
    {
        $this->option(['peak_start_hour' => 6, 'peak_end_hour' => 23]);

        $this->at('2026-03-10 06:00:00');
        $this->assertSame('Peak Hours', WC_Multi_Store_Time_Manager::get_time_period());

        $this->at('2026-03-10 23:00:00');
        $this->assertSame('Off-Peak Hours', WC_Multi_Store_Time_Manager::get_time_period());
    }

    // ── regressions: midnight-crossing windows and unsafe batch sizes ──

    public static function overnightProvider(): array
    {
        return [
            '22:00 is the first peak hour' => ['2026-03-10 22:00:00', false],
            '23:59 is peak' => ['2026-03-10 23:59:59', false],
            'midnight is peak' => ['2026-03-11 00:00:00', false],
            '05:59 is still peak' => ['2026-03-11 05:59:59', false],
            '06:00 is the first off-peak hour' => ['2026-03-11 06:00:00', true],
            'noon is off-peak' => ['2026-03-10 12:00:00', true],
            '21:59 is off-peak' => ['2026-03-10 21:59:59', true],
        ];
    }

    #[DataProvider('overnightProvider')]
    public function test_a_peak_window_that_crosses_midnight_is_honoured(string $when, bool $expectedOffPeak): void
    {
        $this->option(['peak_start_hour' => 22, 'peak_end_hour' => 6]);
        $this->at($when);

        $this->assertSame($expectedOffPeak, WC_Multi_Store_Time_Manager::is_off_peak());
    }

    public function test_overnight_window_picks_the_peak_batch_size_after_midnight(): void
    {
        $this->option(['peak_start_hour' => 22, 'peak_end_hour' => 6, 'batch_size_peak' => 5, 'batch_size_offpeak' => 50]);

        $this->at('2026-03-11 02:00:00');
        $this->assertSame(5, WC_Multi_Store_Time_Manager::get_batch_size());

        $this->at('2026-03-11 12:00:00');
        $this->assertSame(50, WC_Multi_Store_Time_Manager::get_batch_size());
    }

    public function test_equal_start_and_end_is_an_empty_window_so_it_is_always_off_peak(): void
    {
        $this->option(['peak_start_hour' => 8, 'peak_end_hour' => 8]);

        foreach (['2026-03-10 00:00:00', '2026-03-10 07:59:59', '2026-03-10 08:00:00', '2026-03-10 12:00:00', '2026-03-10 23:59:59'] as $when) {
            $this->at($when);
            $this->assertTrue(WC_Multi_Store_Time_Manager::is_off_peak(), $when);
        }
    }

    public static function degenerateBatchProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'empty string' => [''],
            'text' => ['lots'],
            'null' => [null],
        ];
    }

    #[DataProvider('degenerateBatchProvider')]
    public function test_batch_size_is_never_below_one(mixed $stored): void
    {
        $this->option(['batch_size_peak' => $stored, 'batch_size_offpeak' => $stored]);

        $this->at('2026-03-10 12:00:00');
        $this->assertGreaterThanOrEqual(1, WC_Multi_Store_Time_Manager::get_batch_size());

        $this->at('2026-03-10 03:00:00');
        $this->assertGreaterThanOrEqual(1, WC_Multi_Store_Time_Manager::get_batch_size());
    }
}
