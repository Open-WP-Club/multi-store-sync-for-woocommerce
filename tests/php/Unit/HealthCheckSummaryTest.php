<?php
/**
 * Unit tests for WC_Multi_Store_Health_Check static summary methods
 *
 * Tests the get_health_summary and get_last_health_check static methods
 * without instantiating the full class (avoids Action Scheduler dependency).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class HealthCheckSummaryTest extends WC_Multi_Store_TestCase
{
    private static bool $classLoaded = false;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');

        // Load the class file after Brain Monkey is set up (it auto-instantiates with add_action)
        if (!self::$classLoaded) {
            require_once dirname(__DIR__, 3) . '/includes/store-health-check.php';
            self::$classLoaded = true;
        }
    }

    // ─── get_last_health_check ─────────────────────

    public function test_get_last_health_check_returns_null_when_no_data(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_mss_last_health_check') {
                return null;
            }
            return $default;
        });

        $result = WC_Multi_Store_Health_Check::get_last_health_check();

        $this->assertNull($result);
    }

    public function test_get_last_health_check_returns_stored_data(): void
    {
        $stored = [
            'timestamp' => '2024-06-15 10:00:00',
            'results' => [
                'https://store1.com' => ['healthy' => true, 'checks' => []],
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($stored) {
            if ($key === 'wc_mss_last_health_check') {
                return $stored;
            }
            return $default;
        });

        $result = WC_Multi_Store_Health_Check::get_last_health_check();

        $this->assertIsArray($result);
        $this->assertEquals('2024-06-15 10:00:00', $result['timestamp']);
    }

    // ─── get_health_summary ────────────────────────

    public function test_get_health_summary_returns_zeros_when_no_check(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_mss_last_health_check') {
                return null;
            }
            return $default;
        });

        $summary = WC_Multi_Store_Health_Check::get_health_summary();

        $this->assertNull($summary['last_check']);
        $this->assertEquals(0, $summary['healthy_count']);
        $this->assertEquals(0, $summary['warning_count']);
        $this->assertEquals(0, $summary['failed_count']);
        $this->assertEquals(0, $summary['total_count']);
    }

    public function test_get_health_summary_counts_healthy_stores(): void
    {
        $stored = [
            'timestamp' => '2024-06-15 10:00:00',
            'results' => [
                'https://store1.com' => [
                    'healthy' => true,
                    'checks' => [
                        'connectivity' => ['status' => 'pass'],
                        'response_time' => ['status' => 'pass'],
                    ],
                ],
                'https://store2.com' => [
                    'healthy' => true,
                    'checks' => [
                        'connectivity' => ['status' => 'pass'],
                        'response_time' => ['status' => 'pass'],
                    ],
                ],
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($stored) {
            if ($key === 'wc_mss_last_health_check') {
                return $stored;
            }
            return $default;
        });

        $summary = WC_Multi_Store_Health_Check::get_health_summary();

        $this->assertEquals(2, $summary['healthy_count']);
        $this->assertEquals(0, $summary['warning_count']);
        $this->assertEquals(0, $summary['failed_count']);
        $this->assertEquals(2, $summary['total_count']);
    }

    public function test_get_health_summary_counts_warnings(): void
    {
        $stored = [
            'timestamp' => '2024-06-15 10:00:00',
            'results' => [
                'https://store1.com' => [
                    'healthy' => true,
                    'checks' => [
                        'connectivity' => ['status' => 'pass'],
                        'ssl' => ['status' => 'warning'],  // HTTP, no SSL
                    ],
                ],
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($stored) {
            if ($key === 'wc_mss_last_health_check') {
                return $stored;
            }
            return $default;
        });

        $summary = WC_Multi_Store_Health_Check::get_health_summary();

        $this->assertEquals(0, $summary['healthy_count']);
        $this->assertEquals(1, $summary['warning_count']);
        $this->assertEquals(0, $summary['failed_count']);
    }

    public function test_get_health_summary_counts_failures(): void
    {
        $stored = [
            'timestamp' => '2024-06-15 10:00:00',
            'results' => [
                'https://store1.com' => [
                    'healthy' => false,
                    'checks' => [
                        'connectivity' => ['status' => 'fail'],
                    ],
                ],
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($stored) {
            if ($key === 'wc_mss_last_health_check') {
                return $stored;
            }
            return $default;
        });

        $summary = WC_Multi_Store_Health_Check::get_health_summary();

        $this->assertEquals(0, $summary['healthy_count']);
        $this->assertEquals(0, $summary['warning_count']);
        $this->assertEquals(1, $summary['failed_count']);
    }

    public function test_get_health_summary_mixed_results(): void
    {
        $stored = [
            'timestamp' => '2024-06-15 10:00:00',
            'results' => [
                'https://healthy.com' => [
                    'healthy' => true,
                    'checks' => [
                        'connectivity' => ['status' => 'pass'],
                        'response_time' => ['status' => 'pass'],
                    ],
                ],
                'https://warning.com' => [
                    'healthy' => true,
                    'checks' => [
                        'connectivity' => ['status' => 'pass'],
                        'ssl' => ['status' => 'warning'],
                    ],
                ],
                'https://failed.com' => [
                    'healthy' => false,
                    'checks' => [
                        'connectivity' => ['status' => 'fail'],
                    ],
                ],
                'https://also-failed.com' => [
                    'healthy' => false,
                    'checks' => [
                        'connectivity' => ['status' => 'fail'],
                        'response_time' => ['status' => 'fail'],
                    ],
                ],
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($stored) {
            if ($key === 'wc_mss_last_health_check') {
                return $stored;
            }
            return $default;
        });

        $summary = WC_Multi_Store_Health_Check::get_health_summary();

        $this->assertEquals(1, $summary['healthy_count']);
        $this->assertEquals(1, $summary['warning_count']);
        $this->assertEquals(2, $summary['failed_count']);
        $this->assertEquals(4, $summary['total_count']);
        $this->assertEquals('2024-06-15 10:00:00', $summary['last_check']);
    }

    public function test_get_health_summary_healthy_store_without_checks(): void
    {
        $stored = [
            'timestamp' => '2024-06-15 10:00:00',
            'results' => [
                'https://store1.com' => [
                    'healthy' => true,
                    // No 'checks' key at all
                ],
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($stored) {
            if ($key === 'wc_mss_last_health_check') {
                return $stored;
            }
            return $default;
        });

        $summary = WC_Multi_Store_Health_Check::get_health_summary();

        // Without checks array, it should be counted as healthy (not warning)
        $this->assertEquals(1, $summary['healthy_count']);
        $this->assertEquals(0, $summary['warning_count']);
    }

    // ─── FAILURE_THRESHOLD constant ────────────────

    public function test_failure_threshold_constant(): void
    {
        $this->assertEquals(3, WC_Multi_Store_Health_Check::FAILURE_THRESHOLD);
    }
}
