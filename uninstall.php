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
    // Every hook the plugin can leave in the Action Scheduler queue. Recurring
    // jobs would keep firing after the plugin is gone, and pending single
    // actions would run callbacks against deleted code.
    $hooks = [
        // Recurring jobs.
        'wc_multi_store_sync_process_queue',
        'wc_multi_store_sync_scheduled_check',
        'wc_multi_store_sync_daily_maintenance',
        'wc_multi_store_weekly_verification',
        'wc_multi_store_sync_remote_orders',
        'wc_multi_store_health_check',
        'wc_mss_send_daily_summary',
        'wc_mss_archive_history',
        'wc_mss_orphan_auto_trash',
        // Single-run jobs: queued work and background batches.
        'wc_multi_store_sync_process_debounced_order',
        'wc_multi_store_sync_force_sync_batch',
        'wc_mss_async_verification_batch',
        'wc_multi_store_health_check_single',
        'wc_multi_store_verify_stock',
        'wc_mss_orphan_scan_background',
        'wc_mss_orphan_cleanup_background',
        // Deferred feature syncs.
        'wc_mss_sync_attribute_async',
        'wc_mss_delete_attribute_async',
        'wc_mss_sync_attribute_term_async',
        'wc_mss_delete_attribute_term_async',
        'wc_mss_sync_review_async',
        'wc_mss_delete_review_async',
        'wc_mss_sync_coupon_async',
        'wc_mss_delete_coupon_async',
        'wc_mss_sync_shipping_class_async',
        'wc_mss_delete_shipping_class_async',
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
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit plugin uninstall removes only the fixed plugin-owned tables; schema removal cannot use a cached result.
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
