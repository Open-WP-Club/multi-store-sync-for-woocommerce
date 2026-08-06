<?php
/**
 * WooCommerce Multi-Store Performance Monitor
 *
 * Tracks and reports performance metrics for sync operations
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performance Monitor Class
 */
class WC_Multi_Store_Performance_Monitor {

    /**
     * Active timers
     *
     * @var array
     */
    private static array $timers = [];

    /**
     * Performance metrics
     *
     * @var array
     */
    private static array $metrics = [];

    /**
     * Start a performance timer
     *
     * @param string $label Timer label
     * @return void
     */
    public static function start_timer(string $label): void {
        self::$timers[$label] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
        ];
    }

    /**
     * Stop a performance timer and record metrics
     *
     * @param string $label Timer label
     * @return array|null Metrics or null if timer not found
     */
    public static function stop_timer(string $label): ?array {
        if (!isset(self::$timers[$label])) {
            return null;
        }

        $timer = self::$timers[$label];
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);

        $metrics = [
            'label' => $label,
            'duration_ms' => round(($end_time - $timer['start_time']) * 1000, 2),
            'memory_used_mb' => round(($end_memory - $timer['start_memory']) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'timestamp' => current_time('mysql'),
        ];

        // Store metrics
        self::$metrics[] = $metrics;

        // Clean up timer
        unset(self::$timers[$label]);

        return $metrics;
    }

    /**
     * Get all recorded metrics
     *
     * @return array Metrics array
     */
    public static function get_metrics(): array {
        return self::$metrics;
    }

    /**
     * Clear all metrics
     *
     * @return void
     */
    public static function clear_metrics(): void {
        self::$metrics = [];
        self::$timers = [];
    }

    /**
     * Get performance summary
     *
     * @return array Summary statistics
     */
    public static function get_summary(): array {
        if (empty(self::$metrics)) {
            return [
                'total_operations' => 0,
                'total_duration_ms' => 0,
                'avg_duration_ms' => 0,
                'total_memory_mb' => 0,
                'avg_memory_mb' => 0,
                'peak_memory_mb' => 0,
            ];
        }

        $total_duration = 0;
        $total_memory = 0;
        $peak_memory = 0;

        foreach (self::$metrics as $metric) {
            $total_duration += $metric['duration_ms'];
            $total_memory += $metric['memory_used_mb'];
            $peak_memory = max($peak_memory, $metric['peak_memory_mb']);
        }

        $count = count(self::$metrics);

        return [
            'total_operations' => $count,
            'total_duration_ms' => round($total_duration, 2),
            'avg_duration_ms' => round($total_duration / $count, 2),
            'total_memory_mb' => round($total_memory, 2),
            'avg_memory_mb' => round($total_memory / $count, 2),
            'peak_memory_mb' => $peak_memory,
        ];
    }

    /**
     * Log performance metrics
     *
     * @param string $label Operation label
     * @param array $extra_data Additional data to log
     * @return void
     */
    public static function log_operation(string $label, array $extra_data = []): void {
        $metrics = self::stop_timer($label);

        if ($metrics) {
            $log_message = sprintf(
                'Performance: %s - Duration: %sms, Memory: %sMB',
                $label,
                $metrics['duration_ms'],
                $metrics['memory_used_mb']
            );

            if (!empty($extra_data)) {
                $log_message .= ' - ' . json_encode($extra_data);
            }

            WC_Multi_Store_Logger::write($log_message, 'info');
        }
    }

    /**
     * Get system resource usage
     *
     * @return array System stats
     */
    public static function get_system_stats(): array {
        return [
            'memory_current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memory_limit' => ini_get('memory_limit'),
            'time_limit' => ini_get('max_execution_time'),
            'php_version' => PHP_VERSION,
        ];
    }

}
