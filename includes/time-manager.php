<?php
/**
 * Time Manager Class
 * Handles peak/off-peak time detection and batch size calculation
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Time_Manager {

    /**
     * Default peak hours start (24-hour format)
     */
    const int DEFAULT_PEAK_START = 6;

    /**
     * Default peak hours end (24-hour format)
     */
    const int DEFAULT_PEAK_END = 23;

    /**
     * Default batch size for peak hours
     */
    const int DEFAULT_BATCH_SIZE_PEAK = 30;

    /**
     * Default batch size for off-peak hours
     */
    const int DEFAULT_BATCH_SIZE_OFF_PEAK = 30;

    /**
     * Check if current time is off-peak hours
     *
     * @return bool True if off-peak, false if peak hours
     */
    public static function is_off_peak(): bool {
        $settings = get_option('wc_multi_store_sync_scheduled', []);

        $peak_start = (int) ($settings['peak_start_hour'] ?? self::DEFAULT_PEAK_START);
        $peak_end = (int) ($settings['peak_end_hour'] ?? self::DEFAULT_PEAK_END);

        $current_hour = (int) current_datetime()->format('G');

        // Off-peak is outside the peak range
        return $current_hour < $peak_start || $current_hour >= $peak_end;
    }

    /**
     * Get batch size based on time
     *
     * @return int Number of products to sync per batch
     */
    public static function get_batch_size(): int {
        $settings = get_option('wc_multi_store_sync_scheduled', []);
        $force_full_sync = $settings['force_full_sync'] ?? false;

        // If force full sync or off-peak, use larger batch size
        if ($force_full_sync || self::is_off_peak()) {
            return isset($settings['batch_size_offpeak'])
                ? (int) $settings['batch_size_offpeak']
                : self::DEFAULT_BATCH_SIZE_OFF_PEAK;
        }

        // Peak hours: smaller batch size
        return isset($settings['batch_size_peak'])
            ? (int) $settings['batch_size_peak']
            : self::DEFAULT_BATCH_SIZE_PEAK;
    }

    /**
     * Get time period description
     *
     * @return string Human-readable time period
     */
    public static function get_time_period(): string {
        return self::is_off_peak() ? 'Off-Peak Hours' : 'Peak Hours';
    }

}
