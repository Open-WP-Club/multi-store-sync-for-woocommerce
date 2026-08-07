<?php
/**
 * Weekly Sync Verifier
 * Performs comprehensive weekly audits of product synchronization across all stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Weekly_Sync_Verifier {

    /**
     * Transient key for async verification progress.
     *
     * Kept as a facade alias (not a full move, unlike VERIFICATION_LOCK) because
     * includes/hooks.php reads it directly in an exception handler.
     */
    const string ASYNC_PROGRESS_TRANSIENT = WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT;

    /**
     * Run a complete sync verification audit
     *
     * @return array Report data
     */
    public static function run_verification(): array {
        return WC_Multi_Store_Weekly_Verification_Scheduler::run_verification();
    }

    /**
     * Get verification reports
     *
     * @param array $args Query arguments
     * @return array Reports
     */
    public static function get_reports(array $args = []): array {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_reports($args);
    }

    /**
     * Get a single report by ID
     *
     * @param int $report_id Report ID
     * @return array|null Report data
     */
    public static function get_report(int $report_id): ?array {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_report($report_id);
    }

    /**
     * Get latest report
     *
     * @return array|null Latest report
     */
    public static function get_latest_report(): ?array {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_latest_report();
    }

    /**
     * Check if the verification reports table exists
     *
     * @return bool True if table exists
     */
    public static function table_exists(): bool {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::table_exists();
    }

    /**
     * Delete old reports
     *
     * @param int $days Keep reports from last X days (default: 90)
     * @return int|false Number of rows deleted or false on failure
     */
    public static function cleanup_old_reports(int $days = 90): int|false {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::cleanup_old_reports($days);
    }

    /**
     * Format a single discrepancy entry into a display string (pre-escaped HTML).
     *
     * @param array $disc Discrepancy entry
     * @return string Formatted HTML for one discrepancy line
     */
    public static function format_discrepancy_message(array $disc): string {
        return WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message($disc);
    }

    /**
     * Get verification settings
     *
     * @return array Settings
     */
    public static function get_settings(): array {
        return WC_Multi_Store_Weekly_Verification_Scheduler::get_settings();
    }

    /**
     * Update verification settings
     *
     * @param array $settings Settings to update
     * @return bool Success
     */
    public static function update_settings(array $settings): bool {
        return WC_Multi_Store_Weekly_Verification_Scheduler::update_settings($settings);
    }

    /**
     * Create database table for verification reports
     *
     * @return void
     */
    public static function create_table(): void {
        WC_Multi_Store_Weekly_Verification_Report_Repository::create_table();
    }

    /**
     * Schedule the weekly verification
     *
     * @return void
     */
    public static function schedule_verification(): void {
        WC_Multi_Store_Weekly_Verification_Scheduler::schedule_verification();
    }

    /**
     * Unschedule the weekly verification
     *
     * @return void
     */
    public static function unschedule_verification(): void {
        WC_Multi_Store_Weekly_Verification_Scheduler::unschedule_verification();
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false Timestamp or false if not scheduled
     */
    public static function get_next_scheduled_time(): int|false {
        return WC_Multi_Store_Weekly_Verification_Scheduler::get_next_scheduled_time();
    }

    /**
     * Extract orphan products from a verification report
     *
     * @param array|null $report Optional report data. If null, uses latest report.
     * @param bool $require_remote_id If true, only return orphans with remote_product_id
     * @return array Array of orphan products with store_url, remote_product_id, product_id, sku
     */
    public static function get_orphan_products_from_report(?array $report = null, bool $require_remote_id = false): array {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_orphan_products_from_report($report, $require_remote_id);
    }

    /**
     * Schedule async verification (called from admin UI)
     *
     * @return array Result with status
     */
    public static function schedule_async_verification(): array {
        return WC_Multi_Store_Weekly_Verification_Scheduler::schedule_async_verification();
    }

    /**
     * Process a single batch of verification (called by Action Scheduler)
     *
     * @param int $batch_number Current batch number (0-indexed)
     */
    public static function process_verification_batch(int $batch_number): void {
        WC_Multi_Store_Weekly_Verification_Scheduler::process_verification_batch($batch_number);
    }

    /**
     * Get current verification progress
     *
     * @return array|null Progress data or null if no verification running
     */
    public static function get_verification_progress(): ?array {
        return WC_Multi_Store_Weekly_Verification_Scheduler::get_verification_progress();
    }

    /**
     * Cancel running async verification
     *
     * @return bool Success
     */
    public static function cancel_async_verification(): bool {
        return WC_Multi_Store_Weekly_Verification_Scheduler::cancel_async_verification();
    }
}
