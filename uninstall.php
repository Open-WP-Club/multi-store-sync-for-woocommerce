<?php
/**
 * Uninstall WooCommerce Multi-Store Sync
 *
 * Runs when the plugin is deleted (not just deactivated).
 * Only cleans up DB tables and options when the user has explicitly
 * enabled "Delete data on uninstall" in the plugin settings.
 *
 * @package WC_Multi_Store_Sync
 */

// WordPress uninstall guard — bail if not called by WP core.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$settings = get_option('wc_multi_store_sync_settings', []);

if (empty($settings['cleanup_on_uninstall'])) {
    return;
}

// -------------------------------------------------------------------------
// 1. Cancel all scheduled actions (Action Scheduler).
// -------------------------------------------------------------------------
if (function_exists('as_unschedule_all_actions')) {
    $hooks = [
        'wc_mss_process_queue',
        'wc_mss_scheduled_sync',
        'wc_mss_sync_check',
        'wc_mss_weekly_verification',
        'wc_mss_archive_history',
        'wc_mss_dead_letter_retry',
        'wc_mss_cleanup_webhook_logs',
        'wc_mss_run_health_check',
    ];
    foreach ($hooks as $hook) {
        as_unschedule_all_actions($hook);
    }
}

// -------------------------------------------------------------------------
// 2. Drop all custom DB tables.
// -------------------------------------------------------------------------
$tables = [
    $wpdb->prefix . 'wc_mss_sync_history',
    $wpdb->prefix . 'wc_mss_queue',
    $wpdb->prefix . 'wc_mss_api_usage',
    $wpdb->prefix . 'wc_multi_store_stock_discrepancies',
    $wpdb->prefix . 'wc_mss_deletion_audit',
    $wpdb->prefix . 'wc_mss_remote_orders',
    $wpdb->prefix . 'wc_mss_remote_order_items',
    $wpdb->prefix . 'wc_multi_store_weekly_verifications',
    $wpdb->prefix . 'wc_mss_webhook_logs',
    $wpdb->prefix . 'wc_mss_dead_letter_queue',
    $wpdb->prefix . 'wc_mss_conflict_hashes',
    $wpdb->prefix . 'wc_mss_conflict_log',
];

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// -------------------------------------------------------------------------
// 3. Delete all plugin options.
// -------------------------------------------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE 'wc\_mss\_%'
        OR option_name LIKE 'wc\_multi\_store\_sync\_%'"
);

// -------------------------------------------------------------------------
// 4. Delete all plugin transients.
// -------------------------------------------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_wc\_mss\_%'
        OR option_name LIKE '\_transient\_timeout\_wc\_mss\_%'"
);
