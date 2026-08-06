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
     * Get sync type based on time of day
     *
     * @return string Sync type (full_product, price_quantity, or quantity)
     */
    public static function get_sync_type(): string {
        $settings = get_option('wc_multi_store_sync_scheduled', []);
        $force_full_sync = $settings['force_full_sync'] ?? false;

        // If force full sync is enabled, always do full sync
        if ($force_full_sync) {
            return 'full_product';
        }

        // Off-peak hours: full product sync
        if (self::is_off_peak()) {
            return 'full_product';
        }

        // Peak hours: lighter sync (price and quantity only)
        return 'price_quantity';
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

    /**
     * Get settings for display
     *
     * @return array Settings information
     */
    public static function get_settings_info(): array {
        $settings = get_option('wc_multi_store_sync_scheduled', []);

        return [
            'peak_start' => $settings['peak_start_hour'] ?? self::DEFAULT_PEAK_START,
            'peak_end' => $settings['peak_end_hour'] ?? self::DEFAULT_PEAK_END,
            'batch_size_peak' => $settings['batch_size_peak'] ?? self::DEFAULT_BATCH_SIZE_PEAK,
            'batch_size_offpeak' => $settings['batch_size_offpeak'] ?? self::DEFAULT_BATCH_SIZE_OFF_PEAK,
            'current_period' => self::get_time_period(),
            'is_off_peak' => self::is_off_peak(),
            'current_sync_type' => self::get_sync_type(),
            'current_batch_size' => self::get_batch_size(),
        ];
    }

    /**
     * Calculate estimated sync time
     *
     * @param int $product_count Number of products to sync
     * @param int $store_count Number of stores
     * @return array Estimated time information
     */
    public static function estimate_sync_time(int $product_count, int $store_count = 1): array {
        $batch_size = self::get_batch_size();
        $sync_type = self::get_sync_type();

        // Average time per product per store (in seconds)
        $time_per_product = match ($sync_type) {
            'full_product' => 3,        // Full sync is slower
            'price_quantity' => 1.5,
            'quantity' => 1,            // Fastest
            default => 0,
        };

        $total_operations = $product_count * $store_count;
        $estimated_seconds = $total_operations * $time_per_product;
        $batches_needed = ceil($product_count / $batch_size);

        return [
            'total_operations' => $total_operations,
            'estimated_seconds' => $estimated_seconds,
            'estimated_minutes' => round($estimated_seconds / 60, 1),
            'batches_needed' => $batches_needed,
            'sync_type' => $sync_type,
            'batch_size' => $batch_size,
        ];
    }
}
