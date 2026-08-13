<?php
/**
 * Admin AJAX Handlers
 *
 * Centralises all wp_ajax_wc_mss_* actions that were previously defined as
 * methods on the main WC_Multi_Store_Sync class. Keeping them here makes the
 * main plugin bootstrapper leaner and makes these handlers independently testable.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Admin_Ajax {
    use WC_Multi_Store_Ajax_Auth_Guard;

    public function __construct() {
        // Orphan / history
        add_action('wp_ajax_wc_mss_queue_orphans_for_deletion', $this->ajax_queue_orphans_for_deletion(...));
        add_action('wp_ajax_wc_mss_delete_history',             $this->ajax_delete_history(...));

        // Weekly verification
        add_action('wp_ajax_wc_mss_start_verification',         $this->ajax_start_verification(...));
        add_action('wp_ajax_wc_mss_get_verification_progress',  $this->ajax_get_verification_progress(...));
        add_action('wp_ajax_wc_mss_cancel_verification',        $this->ajax_cancel_verification(...));

        // Webhook logs
        add_action('wp_ajax_wc_mss_get_webhook_logs',           $this->ajax_get_webhook_logs(...));
        add_action('wp_ajax_wc_mss_get_webhook_log_detail',     $this->ajax_get_webhook_log_detail(...));
        add_action('wp_ajax_wc_mss_export_webhook_logs',        $this->ajax_export_webhook_logs(...));
        add_action('wp_ajax_wc_mss_get_webhook_stats',          $this->ajax_get_webhook_stats(...));
        add_action('wp_ajax_wc_mss_delete_webhook_logs',        $this->ajax_delete_webhook_logs(...));

        // Sync logs
        add_action('wp_ajax_wc_mss_clear_sync_log',             $this->ajax_clear_sync_log(...));
        add_action('wp_ajax_wc_mss_clear_warnings_errors',      $this->ajax_clear_warnings_errors(...));
        add_action('wp_ajax_wc_mss_force_sync_by_sku',          $this->ajax_force_sync_by_sku(...));
        add_action('wp_ajax_wc_mss_force_sync_by_category',     $this->ajax_force_sync_by_category(...));

        // Category scan
        add_action('wp_ajax_wc_mss_scan_categories',            $this->ajax_scan_categories(...));

        // Sync control
        add_action('wp_ajax_wc_mss_stop_scheduled_sync',        $this->ajax_stop_scheduled_sync(...));
        add_action('wp_ajax_wc_mss_stop_all_sync',              $this->ajax_stop_all_sync(...));
        add_action('wp_ajax_wc_mss_stop_weekly_verification',   $this->ajax_stop_weekly_verification(...));

        // Dead Letter Queue
        add_action('wp_ajax_wc_mss_dlq_retry_item',    WC_Multi_Store_Dead_Letter_Queue::ajax_retry_item(...));
        add_action('wp_ajax_wc_mss_dlq_retry_all',     WC_Multi_Store_Dead_Letter_Queue::ajax_retry_all(...));
        add_action('wp_ajax_wc_mss_dlq_resolve_item',  WC_Multi_Store_Dead_Letter_Queue::ajax_resolve_item(...));
        add_action('wp_ajax_wc_mss_dlq_clear_all',     WC_Multi_Store_Dead_Letter_Queue::ajax_clear_all(...));
        add_action('wp_ajax_wc_mss_dlq_get_items',     WC_Multi_Store_Dead_Letter_Queue::ajax_get_items(...));

        // Queue per-item retry
        add_action('wp_ajax_wc_mss_queue_retry_item', $this->ajax_queue_retry_item(...));

        // Config export / import
        add_action('wp_ajax_wc_mss_export_config', WC_Multi_Store_Config_Manager::ajax_export(...));
        add_action('wp_ajax_wc_mss_import_config', WC_Multi_Store_Config_Manager::ajax_import(...));

        // Shipping class sync
        add_action('wp_ajax_wc_mss_sync_shipping_classes',      WC_Multi_Store_Shipping_Class_Sync::ajax_sync_all(...));
        add_action('wp_ajax_wc_mss_toggle_shipping_class_sync', WC_Multi_Store_Shipping_Class_Sync::ajax_toggle(...));

        // Coupon sync
        add_action('wp_ajax_wc_mss_sync_coupons',        WC_Multi_Store_Coupon_Sync::ajax_sync_all(...));
        add_action('wp_ajax_wc_mss_toggle_coupon_sync',  WC_Multi_Store_Coupon_Sync::ajax_toggle(...));

        // Downloadable files sync
        add_action('wp_ajax_wc_mss_toggle_downloadable_files_sync', WC_Multi_Store_Downloadable_Files_Sync::ajax_toggle(...));

        // Orphan auto-trash
        add_action('wp_ajax_wc_mss_toggle_orphan_auto_trash', WC_Multi_Store_Orphan_Cleanup::ajax_toggle(...));

        // Category mapper
        add_action('wp_ajax_wc_mss_save_category_mappings', WC_Multi_Store_Category_Mapper::ajax_save_mappings(...));
        add_action('wp_ajax_wc_mss_get_category_mappings',  WC_Multi_Store_Category_Mapper::ajax_get_mappings(...));
        add_action('wp_ajax_wc_mss_toggle_category_mapper', WC_Multi_Store_Category_Mapper::ajax_toggle(...));
        add_action('wp_ajax_wc_mss_get_remote_terms',       $this->ajax_get_remote_terms(...));

        // Attribute remapper
        add_action('wp_ajax_wc_mss_save_attribute_mappings',   WC_Multi_Store_Attribute_Remapper::ajax_save_mappings(...));
        add_action('wp_ajax_wc_mss_get_attribute_mappings',    WC_Multi_Store_Attribute_Remapper::ajax_get_mappings(...));
        add_action('wp_ajax_wc_mss_toggle_attribute_remapping', WC_Multi_Store_Attribute_Remapper::ajax_toggle(...));

        // Sync profiles
        add_action('wp_ajax_wc_mss_profile_save',   WC_Multi_Store_Sync_Profiles::ajax_save(...));
        add_action('wp_ajax_wc_mss_profile_apply',  WC_Multi_Store_Sync_Profiles::ajax_apply(...));
        add_action('wp_ajax_wc_mss_profile_delete', WC_Multi_Store_Sync_Profiles::ajax_delete(...));
        add_action('wp_ajax_wc_mss_profile_list',   WC_Multi_Store_Sync_Profiles::ajax_list(...));

        // Conflict detector
        add_action('wp_ajax_wc_mss_get_conflicts',           WC_Multi_Store_Conflict_Detector::ajax_get_conflicts(...));
        add_action('wp_ajax_wc_mss_resolve_conflict',        WC_Multi_Store_Conflict_Detector::ajax_resolve_conflict(...));
        add_action('wp_ajax_wc_mss_resolve_all_conflicts',   WC_Multi_Store_Conflict_Detector::ajax_resolve_all(...));
        add_action('wp_ajax_wc_mss_toggle_conflict_detection', WC_Multi_Store_Conflict_Detector::ajax_toggle(...));

        // Dashboard stats — registered by WC_Multi_Store_Dashboard_Widget's own
        // constructor (instantiated in init_admin(), which always runs alongside
        // this class since is_admin() is true for admin-ajax.php requests too).

        // Category sync
        add_action('wp_ajax_wc_mss_sync_by_category',    $this->ajax_sync_by_category(...));
        add_action('wp_ajax_wc_mss_force_sync_product',  $this->ajax_force_sync_product(...));

        // Category row action (product_cat taxonomy list)
        add_filter('product_cat_row_actions', $this->add_category_row_action(...), 10, 2);
        add_action('admin_footer-edit-tags.php',    $this->category_row_action_script(...));
    }

    /**
     * Run an AJAX handler body, catching any Throwable once instead of each
     * handler hand-rolling its own identical try/catch → Logger::write() →
     * wp_send_json_error() wrapper. $handler is expected to send its own
     * JSON response on success (or on a handled failure); this only covers
     * the unexpected-exception path.
     *
     * @param string $log_prefix Prefix for the logged error message (e.g. 'delete_history')
     * @param string $error_message User-facing message sent on an uncaught exception
     * @param callable $handler Handler body
     */
    private function run_ajax_handler(string $log_prefix, string $error_message, callable $handler): void {
        try {
            $handler();
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write("AJAX {$log_prefix} error: " . $e->getMessage(), 'error');
            wp_send_json_error([
                'message' => $error_message,
                'error'   => WP_DEBUG ? $e->getMessage() : null,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Orphan / History
    // -------------------------------------------------------------------------

    public function ajax_queue_orphans_for_deletion(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $this->run_ajax_handler(
            'queue_orphans_for_deletion',
            __('An error occurred while processing orphan deletion', 'wc-multi-store-sync'),
            function () {
            $orphans = WC_Multi_Store_Weekly_Sync_Verifier::get_orphan_products_from_report();

            if (empty($orphans)) {
                wp_send_json_error(['message' => __('No orphan products found in the latest report', 'wc-multi-store-sync')]);
                return;
            }

            $queued = 0;
            $failed = 0;

            foreach ($orphans as $orphan) {
                $result = WC_MSS()->queue_manager->add_remote_orphan_deletion(
                    $orphan,
                    'weekly_verification_cleanup',
                    WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
                );

                if ($result) {
                    $queued++;
                } else {
                    $failed++;
                }
            }

            if ($queued > 0) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Queued %d orphan products for deletion from weekly verification report (%d failed)',
                    $queued,
                    $failed
                ));

                wp_send_json_success([
                    'message' => sprintf(
                        __('%d orphan product(s) queued for deletion', 'wc-multi-store-sync'),
                        $queued
                    ),
                    'queued' => $queued,
                    'failed' => $failed,
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('Failed to queue orphan products for deletion', 'wc-multi-store-sync'),
                    'failed'  => $failed,
                ]);
            }
            }
        );
    }

    public function ajax_delete_history(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $this->run_ajax_handler(
            'delete_history',
            __('An error occurred while deleting history', 'wc-multi-store-sync'),
            function () {
            $delete_type = sanitize_text_field($_POST['delete_type'] ?? '');

            if (empty($delete_type)) {
                wp_send_json_error(['message' => __('Invalid deletion type', 'wc-multi-store-sync')]);
                return;
            }

            $days      = absint($_POST['days'] ?? 30);
            $store_url = sanitize_text_field($_POST['store_url'] ?? '');

            $deleted = match ($delete_type) {
                'all'        => WC_Multi_Store_Sync_History::clear_all() ? -1 : 0,
                'errors'     => WC_Multi_Store_Sync_History::delete_errors(),
                'successful' => WC_Multi_Store_Sync_History::delete_successful(),
                'older_than' => WC_Multi_Store_Sync_History::cleanup_old_records($days),
                'by_store'   => !empty($store_url) ? WC_Multi_Store_Sync_History::delete_by_store($store_url) : 0,
                default      => null,
            };

            if ($deleted === null) {
                wp_send_json_error(['message' => __('Unknown deletion type', 'wc-multi-store-sync')]);
                return;
            }

            if ($deleted === -1) {
                wp_send_json_success([
                    'message'   => __('All history records deleted', 'wc-multi-store-sync'),
                    'remaining' => 0,
                ]);
            } elseif ($deleted > 0) {
                wp_send_json_success([
                    'message'   => sprintf(__('%d records deleted', 'wc-multi-store-sync'), $deleted),
                    'deleted'   => $deleted,
                    'remaining' => WC_Multi_Store_Sync_History::get_count(),
                ]);
            } else {
                wp_send_json_error(['message' => __('No records deleted', 'wc-multi-store-sync')]);
            }
            }
        );
    }

    // -------------------------------------------------------------------------
    // Weekly Verification
    // -------------------------------------------------------------------------

    public function ajax_start_verification(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'start_verification',
            __('An error occurred while starting verification', 'wc-multi-store-sync'),
            function () {
                $result = WC_Multi_Store_Weekly_Sync_Verifier::schedule_async_verification();

                if ($result['success']) {
                    wp_send_json_success($result);
                } else {
                    wp_send_json_error($result);
                }
            }
        );
    }

    public function ajax_get_verification_progress(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'get_verification_progress',
            __('An error occurred while getting verification progress', 'wc-multi-store-sync'),
            function () {
            $progress = WC_Multi_Store_Weekly_Sync_Verifier::get_verification_progress();

            if ($progress) {
                $percent = $progress['total_products'] > 0
                    ? round(($progress['processed_products'] / $progress['total_products']) * 100)
                    : 0;

                wp_send_json_success([
                    'status'        => $progress['status'],
                    'processed'     => $progress['processed_products'],
                    'total'         => $progress['total_products'],
                    'percent'       => $percent,
                    'current_batch' => $progress['current_batch'],
                    'total_batches' => $progress['total_batches'],
                    'discrepancies' => $progress['discrepancies_found'],
                    'started_at'    => $progress['started_at'],
                    'completed_at'  => $progress['completed_at'] ?? null,
                ]);
            } else {
                if (function_exists('as_get_scheduled_actions')) {
                    $pending = as_get_scheduled_actions([
                        'hook'     => 'wc_mss_async_verification_batch',
                        'status'   => \ActionScheduler_Store::STATUS_PENDING,
                        'per_page' => 1,
                    ]);
                    if (!empty($pending)) {
                        wp_send_json_success(['status' => 'pending']);
                        return;
                    }
                }
                wp_send_json_success(['status' => 'idle']);
            }
            }
        );
    }

    public function ajax_cancel_verification(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'cancel_verification',
            __('An error occurred while cancelling verification', 'wc-multi-store-sync'),
            function () {
                $result = WC_Multi_Store_Weekly_Sync_Verifier::cancel_async_verification();

                if ($result) {
                    wp_send_json_success(['message' => __('Verification cancelled', 'wc-multi-store-sync')]);
                } else {
                    wp_send_json_error(['message' => __('No running verification to cancel', 'wc-multi-store-sync')]);
                }
            }
        );
    }

    // -------------------------------------------------------------------------
    // Webhook Logs
    // -------------------------------------------------------------------------

    public function ajax_get_webhook_logs(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'get_webhook_logs',
            __('An error occurred while fetching webhook logs', 'wc-multi-store-sync'),
            function () {
            $args = [
                'per_page'    => isset($_POST['per_page'])    ? absint($_POST['per_page'])                        : 50,
                'page'        => isset($_POST['page'])        ? absint($_POST['page'])                            : 1,
                'log_type'    => isset($_POST['log_type'])    ? sanitize_text_field($_POST['log_type'])           : null,
                'store_url'   => isset($_POST['store_url'])   ? sanitize_text_field($_POST['store_url'])          : null,
                'product_sku' => isset($_POST['product_sku']) ? sanitize_text_field($_POST['product_sku'])        : null,
                'status'      => isset($_POST['status'])      ? sanitize_text_field($_POST['status'])             : null,
                'date_from'   => isset($_POST['date_from'])   ? sanitize_text_field($_POST['date_from'])          : null,
                'date_to'     => isset($_POST['date_to'])     ? sanitize_text_field($_POST['date_to'])            : null,
            ];

            $result = WC_Multi_Store_Webhook_Logger::get_logs($args);

            foreach ($result['logs'] as &$log) {
                $log['type_label']   = WC_Multi_Store_Webhook_Logger::get_type_label($log['log_type']);
                $log['status_badge'] = WC_Multi_Store_Webhook_Logger::get_status_badge($log['status']);
            }

            wp_send_json_success($result);
            }
        );
    }

    public function ajax_get_webhook_log_detail(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'get_webhook_log_detail',
            __('An error occurred while fetching log detail', 'wc-multi-store-sync'),
            function () {
            $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;

            if (!$log_id) {
                wp_send_json_error(['message' => __('Invalid log ID', 'wc-multi-store-sync')]);
                return;
            }

            $log = WC_Multi_Store_Webhook_Logger::get_log($log_id);

            if (!$log) {
                wp_send_json_error(['message' => __('Log not found', 'wc-multi-store-sync')]);
                return;
            }

            $log['type_label']   = WC_Multi_Store_Webhook_Logger::get_type_label($log['log_type']);
            $log['status_badge'] = WC_Multi_Store_Webhook_Logger::get_status_badge($log['status']);

            wp_send_json_success($log);
            }
        );
    }

    public function ajax_export_webhook_logs(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'export_webhook_logs',
            __('An error occurred while exporting logs', 'wc-multi-store-sync'),
            function () {
            $args = [
                'log_type'  => isset($_POST['log_type'])  ? sanitize_text_field($_POST['log_type'])  : null,
                'store_url' => isset($_POST['store_url']) ? sanitize_text_field($_POST['store_url']) : null,
                'date_from' => isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : null,
                'date_to'   => isset($_POST['date_to'])   ? sanitize_text_field($_POST['date_to'])   : null,
            ];

            $csv      = WC_Multi_Store_Webhook_Logger::export_csv($args);
            $filename = 'webhook-logs-' . date('Y-m-d-His') . '.csv';

            wp_send_json_success([
                'csv_content' => base64_encode($csv),
                'filename'    => $filename,
            ]);
            }
        );
    }

    public function ajax_get_webhook_stats(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'get_webhook_stats',
            __('An error occurred while fetching stats', 'wc-multi-store-sync'),
            function () {
                $days  = isset($_POST['days']) ? absint($_POST['days']) : 30;
                $stats = WC_Multi_Store_Webhook_Logger::get_stats($days);
                wp_send_json_success($stats);
            }
        );
    }

    public function ajax_delete_webhook_logs(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'delete_webhook_logs',
            __('An error occurred while deleting logs', 'wc-multi-store-sync'),
            function () {
            $delete_type = isset($_POST['delete_type']) ? sanitize_text_field($_POST['delete_type']) : '';

            if (empty($delete_type)) {
                wp_send_json_error(['message' => __('Invalid deletion type', 'wc-multi-store-sync')]);
                return;
            }

            $days     = isset($_POST['days'])     ? absint($_POST['days'])                          : 30;
            $log_type = isset($_POST['log_type']) ? sanitize_text_field($_POST['log_type'])         : '';

            if ($delete_type === 'by_type' && empty($log_type)) {
                wp_send_json_error(['message' => __('Invalid log type', 'wc-multi-store-sync')]);
                return;
            }

            $deleted = match ($delete_type) {
                'all'        => WC_Multi_Store_Webhook_Logger::delete_all(),
                'errors'     => WC_Multi_Store_Webhook_Logger::delete_by_status('failed'),
                'success'    => WC_Multi_Store_Webhook_Logger::delete_by_status('success'),
                'older_than' => WC_Multi_Store_Webhook_Logger::delete_older_than($days),
                'by_type'    => WC_Multi_Store_Webhook_Logger::delete_by_type($log_type),
                default      => null,
            };

            if ($deleted === null) {
                wp_send_json_error(['message' => __('Unknown delete type', 'wc-multi-store-sync')]);
                return;
            }

            $message = match ($delete_type) {
                'all'        => __('All logs deleted', 'wc-multi-store-sync'),
                'errors'     => sprintf(__('%d error records deleted', 'wc-multi-store-sync'), $deleted),
                'success'    => sprintf(__('%d success records deleted', 'wc-multi-store-sync'), $deleted),
                'older_than' => sprintf(__('%d records older than %d days deleted', 'wc-multi-store-sync'), $deleted, $days),
                'by_type'    => sprintf(__('%d records of type "%s" deleted', 'wc-multi-store-sync'), $deleted, $log_type),
            };

            wp_send_json_success([
                'message'   => $message,
                'deleted'   => $deleted,
                'remaining' => WC_Multi_Store_Webhook_Logger::get_count(),
            ]);
            }
        );
    }

    // -------------------------------------------------------------------------
    // Sync Control
    // -------------------------------------------------------------------------

    public function ajax_stop_scheduled_sync(): void {
        if (!self::verify_admin_request('wc_mss_stop_sync')) {
            return;
        }

        $this->run_ajax_handler(
            'stop_scheduled_sync',
            __('An error occurred while stopping scheduled sync', 'wc-multi-store-sync'),
            function () {
            $scheduled_settings                          = get_option('wc_multi_store_sync_scheduled', []);
            $scheduled_settings['scheduled_sync_enabled'] = false;
            update_option('wc_multi_store_sync_scheduled', $scheduled_settings);

            if (class_exists('WC_Multi_Store_Action_Scheduler_Manager') && WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
                as_unschedule_all_actions(
                    WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_SCHEDULED_SYNC,
                    [],
                    WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
                );
            }

            WC_Multi_Store_Logger::write('Scheduled sync stopped by user');

            wp_send_json_success([
                'message' => __('Scheduled sync has been stopped. Re-enable it in settings when ready.', 'wc-multi-store-sync'),
            ]);
            }
        );
    }

    public function ajax_stop_all_sync(): void {
        if (!self::verify_admin_request('wc_mss_stop_sync')) {
            return;
        }

        $this->run_ajax_handler(
            'stop_all_sync',
            __('An error occurred while stopping sync', 'wc-multi-store-sync'),
            function () {
            $results = [];

            // Clear the queue
            if (class_exists('WC_Multi_Store_Queue_Table')) {
                global $wpdb;
                $table_name = $wpdb->prefix . WC_Multi_Store_Queue_Table::TABLE_NAME;
                $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $results['queue_cleared'] = true;
            }

            // Cancel any pending Action Scheduler jobs so nothing fires mid-run
            if (class_exists('WC_Multi_Store_Action_Scheduler_Manager') && WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
                as_unschedule_all_actions(
                    WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_QUEUE,
                    [],
                    WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
                );
                as_unschedule_all_actions(
                    WC_Multi_Store_Action_Scheduler_Manager::ACTION_HOOK_SCHEDULED_SYNC,
                    [],
                    WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
                );
                $results['actions_unscheduled'] = true;
            }

            WC_Multi_Store_Logger::write('Queue cleared and pending sync actions cancelled by user');

            wp_send_json_success([
                'message' => __('Queue cleared and pending sync jobs cancelled. The plugin remains active — new sync events will continue to work normally.', 'wc-multi-store-sync'),
                'details' => $results,
            ]);
            }
        );
    }

    public function ajax_stop_weekly_verification(): void {
        if (!self::verify_admin_request('wc_mss_stop_sync')) {
            return;
        }

        $this->run_ajax_handler(
            'stop_weekly_verification',
            __('An error occurred while stopping weekly verification', 'wc-multi-store-sync'),
            function () {
            $weekly_settings            = get_option('wc_multi_store_sync_weekly_verification', []);
            $weekly_settings['enabled'] = false;
            update_option('wc_multi_store_sync_weekly_verification', $weekly_settings);

            if (class_exists('WC_Multi_Store_Weekly_Sync_Verifier')) {
                WC_Multi_Store_Weekly_Sync_Verifier::cancel_async_verification();
                WC_Multi_Store_Weekly_Sync_Verifier::unschedule_verification();
            }

            WC_Multi_Store_Logger::write('Weekly verification stopped by user');

            wp_send_json_success([
                'message' => __('Weekly verification has been stopped and disabled. Re-enable it in settings when ready.', 'wc-multi-store-sync'),
            ]);
            }
        );
    }

    public function ajax_force_sync_by_sku(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $raw_skus = isset($_POST['skus']) ? (array) $_POST['skus'] : [];

        // fallback: legacy single 'sku' param
        if (empty($raw_skus) && !empty($_POST['sku'])) {
            $raw_skus = [sanitize_text_field(trim($_POST['sku']))];
        }

        $skus = array_values(array_filter(array_map(
            fn($s) => sanitize_text_field(trim($s)),
            $raw_skus
        )));

        if (empty($skus)) {
            wp_send_json_error(['message' => __('At least one SKU is required', 'wc-multi-store-sync')]);
            return;
        }

        $results     = [];
        $total_added = 0;

        foreach ($skus as $sku) {
            $product_id = wc_get_product_id_by_sku($sku);

            if (!$product_id) {
                $active_stores = WC_Multi_Store_Settings::get_active_stores();
                $queued_count  = 0;

                foreach ($active_stores as $store_url => $store_config) {
                    $queued = WC_MSS()->queue_manager->add_remote_orphan_deletion([
                        'product_id'        => 0,
                        'sku'               => $sku,
                        'store_url'         => $store_url,
                        'remote_product_id' => null,
                        'exclusion_reasons' => ['not_found_locally'],
                    ], 'manual_force_sync', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH);

                    if ($queued) {
                        $queued_count++;
                    }
                }

                WC_Multi_Store_Logger::write(sprintf(
                    'Force sync: SKU "%s" not found locally — queued deletion for %d store(s)',
                    $sku,
                    $queued_count
                ));

                $results[] = [
                    'sku'          => $sku,
                    'success'      => true,
                    'deleted'      => true,
                    'queued_count' => $queued_count,
                    'message'      => sprintf(
                        __('SKU %s not found locally — queued for deletion from %d store(s)', 'wc-multi-store-sync'),
                        esc_html($sku),
                        $queued_count
                    ),
                ];
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                $results[] = [
                    'sku'     => $sku,
                    'success' => false,
                    'message' => sprintf(__('Could not load product for SKU: %s', 'wc-multi-store-sync'), esc_html($sku)),
                ];
                continue;
            }

            $added = WC_MSS()->queue_manager->add_product(
                $product_id,
                'manual_test',
                WC_Multi_Store_Queue_Manager::PRIORITY_HIGH,
                'full_product'
            );

            if ($added === 0) {
                $results[] = [
                    'sku'     => $sku,
                    'success' => false,
                    'message' => sprintf(__('SKU %s: no active stores or product is excluded from all stores', 'wc-multi-store-sync'), esc_html($sku)),
                ];
                continue;
            }

            $product_name = $product->get_name();
            $total_added += $added;

            WC_Multi_Store_Logger::write(sprintf(
                'Manual test sync triggered for SKU "%s" (ID: %d, Name: %s) → queued to %d store(s) as full_product',
                $sku,
                $product_id,
                $product_name,
                $added
            ));

            $results[] = [
                'sku'          => $sku,
                'success'      => true,
                'message'      => sprintf(
                    __('"%s" (SKU: %s) queued to %d store(s)', 'wc-multi-store-sync'),
                    esc_html($product_name),
                    esc_html($sku),
                    $added
                ),
                'product_id'   => $product_id,
                'product_name' => $product_name,
                'stores_count' => $added,
            ];
        }

        wp_send_json_success([
            'message' => sprintf(
                _n(
                    '%d SKU queued for full sync.',
                    '%d SKUs queued for full sync.',
                    count(array_filter($results, fn($r) => $r['success'])),
                    'wc-multi-store-sync'
                ),
                count(array_filter($results, fn($r) => $r['success']))
            ),
            'results'     => $results,
            'total_added' => $total_added,
        ]);
    }

    public function ajax_force_sync_by_category(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $this->run_ajax_handler(
            'force_sync_by_category',
            __('An error occurred while queuing the category for sync', 'wc-multi-store-sync'),
            function () {
            $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;

            if (!$category_id) {
                wp_send_json_error(['message' => __('A category is required', 'wc-multi-store-sync')]);
                return;
            }

            $term = get_term($category_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                wp_send_json_error(['message' => __('Invalid category', 'wc-multi-store-sync')]);
                return;
            }

            // Reuses WC_Multi_Store_Category_Sync's paginated fetch (5,000-row
            // chunks) instead of an unbounded posts_per_page => -1 query, which
            // could try to load an entire large category's product IDs in one go.
            $product_ids = WC_Multi_Store_Category_Sync::get_product_ids($category_id, true);

            if (empty($product_ids)) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('No published products found in category "%s"', 'wc-multi-store-sync'),
                        esc_html($term->name)
                    ),
                ]);
                return;
            }

            $total_queued = 0;
            $skipped      = 0;

            foreach ($product_ids as $product_id) {
                $added = WC_MSS()->queue_manager->add_product(
                    $product_id,
                    'manual_test',
                    WC_Multi_Store_Queue_Manager::PRIORITY_HIGH,
                    'full_product'
                );

                if ($added > 0) {
                    $total_queued += $added;
                } else {
                    $skipped++;
                }
            }

            WC_Multi_Store_Logger::write(sprintf(
                'Force sync by category "%s" (ID: %d): %d product(s) queued across stores, %d skipped (excluded or no active stores)',
                $term->name,
                $category_id,
                count($product_ids),
                $skipped
            ));

            wp_send_json_success([
                'message' => sprintf(
                    _n(
                        'Category "%1$s": %2$d product queued for full sync across all active stores.',
                        'Category "%1$s": %2$d products queued for full sync across all active stores.',
                        count($product_ids),
                        'wc-multi-store-sync'
                    ),
                    esc_html($term->name),
                    count($product_ids)
                ),
                'products_found' => count($product_ids),
                'total_queued'   => $total_queued,
                'skipped'        => $skipped,
            ]);
            }
        );
    }

    public function ajax_clear_sync_log(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $logger = WC_Multi_Store_Logger::instance();
        if ($logger->clear_log()) {
            WC_Multi_Store_Logger::write('Sync log cleared by user');
            wp_send_json_success(['message' => __('Log cleared successfully', 'wc-multi-store-sync')]);
        } else {
            wp_send_json_error(['message' => __('Failed to clear log', 'wc-multi-store-sync')]);
        }
    }

    public function ajax_clear_warnings_errors(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $result = WC_Multi_Store_Logger::instance()->clear_warnings_and_errors();

        WC_Multi_Store_Logger::write(sprintf(
            'Warnings & errors cleared by user (%d entries removed, %d info entries kept)',
            $result['removed'],
            $result['kept']
        ));

        wp_send_json_success([
            'message' => sprintf(
                __('%d warning/error entries removed', 'wc-multi-store-sync'),
                $result['removed']
            ),
            'removed' => $result['removed'],
        ]);
    }

    /**
     * AJAX: Scan categories for one store at a time (called per-store from JS).
     *
     * Efficient bulk approach — O(remote/100) API calls instead of O(local) calls:
     *   1. Fetch ALL remote products in paginated bulk → build SKU→categories map.
     *   2. Fetch ALL local products + categories in two SQL queries.
     *   3. Compare in memory — no per-product API call.
     *
     * JS calls this once per store and accumulates results with a progress bar.
     */
    public function ajax_scan_categories(): void {
        if (!self::verify_admin_request('wc_mss_scan_categories', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $store_url = isset($_POST['store_url']) ? sanitize_text_field($_POST['store_url']) : '';

        if (!$store_url) {
            wp_send_json_error(['message' => __('store_url is required for per-store scanning.', 'wc-multi-store-sync')]);
            return;
        }

        try {
            $sync_settings = WC_Multi_Store_Settings::get_settings();
            $match_by      = $sync_settings['category_match_by'] ?? 'slug';

            $config = WC_Multi_Store_Settings::get_store($store_url);
            if (!$config) {
                // Try trailing-slash variant
                $alt = str_ends_with($store_url, '/') ? rtrim($store_url, '/') : $store_url . '/';
                $config = WC_Multi_Store_Settings::get_store($alt);
                if ($config) {
                    $store_url = $alt;
                }
            }
            if (!$config) {
                wp_send_json_error(['message' => sprintf(__('Store not found: %s', 'wc-multi-store-sync'), $store_url)]);
                return;
            }

            $client = WC_Multi_Store_API_Client::for_store($store_url, $config);

            $result = $this->scan_categories_for_store($client, $store_url, $config, $match_by);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }

            wp_send_json_success($result);

        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write('Category scan error: ' . $e->getMessage(), 'error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Fetch a remote store's categories, tags, or attributes, for the
     * category/tag/attribute mapping UIs' "map to" dropdowns.
     */
    public function ajax_get_remote_terms(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $store_url = isset($_POST['store_url']) ? sanitize_text_field($_POST['store_url']) : '';
        $taxonomy  = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : 'category';

        if (!$store_url) {
            wp_send_json_error(['message' => __('Store URL is required', 'wc-multi-store-sync')]);
            return;
        }

        $config = WC_Multi_Store_Settings::get_store($store_url);
        if (!$config) {
            wp_send_json_error(['message' => sprintf(__('Store not found: %s', 'wc-multi-store-sync'), $store_url)]);
            return;
        }

        $client = WC_Multi_Store_API_Client::for_store($store_url, $config);

        $terms = match ($taxonomy) {
            'tag' => WC_Multi_Store_Category_Mapper::get_remote_tags($client),
            'attribute' => WC_Multi_Store_Attribute_Remapper::get_remote_attributes($client),
            default => WC_Multi_Store_Category_Mapper::get_remote_categories($client),
        };

        wp_send_json_success(['terms' => $terms]);
    }

    /**
     * Core category comparison logic for one store.
     * Extracted so it can be tested independently of the AJAX context.
     *
     * @return array|WP_Error Result array on success, WP_Error on API failure.
     */
    protected function scan_categories_for_store(
        WC_Multi_Store_API_Client $client,
        string $store_url,
        array $config,
        string $match_by
    ): array|\WP_Error {
        // ── Step 1: fetch all remote products in bulk ──────────────────────────
        // get_all_products() paginates internally — ~(count/100) API calls total.
        $remote_all = $client->get_all_products(['per_page' => 100, '_fields' => 'id,sku,categories'], 200);
        if (is_wp_error($remote_all)) {
            return $remote_all;
        }

        // Build SKU → [category slugs|ids] map
        $remote_map = [];
        foreach ($remote_all as $rp) {
            $sku = $rp['sku'] ?? '';
            if ($sku === '') {
                continue;
            }
            $cats = [];
            foreach ($rp['categories'] ?? [] as $cat) {
                $cats[] = $match_by === 'slug' ? ($cat['slug'] ?? '') : (int) ($cat['id'] ?? 0);
            }
            $cats = array_values(array_filter($cats));
            sort($cats);
            $remote_map[$sku] = $cats;
        }

        // ── Step 2: fetch all local products + categories in bulk SQL ──────────
        global $wpdb;

        $local_rows = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS sku
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             AND pm.meta_value != ''",
            ARRAY_A
        );

        if (empty($local_rows)) {
            return ['store_url' => $store_url, 'store_name' => $config['name'] ?? $store_url, 'items' => [], 'count' => 0, 'total_local' => 0, 'total_remote' => count($remote_map)];
        }

        $local_ids = array_column($local_rows, 'ID');
        $sku_by_id = array_column($local_rows, 'sku', 'ID');

        $ids_in    = implode(',', array_map('intval', $local_ids));
        $name_rows = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($ids_in)", ARRAY_A);
        $name_by_id = array_column($name_rows, 'post_title', 'ID');

        $allowed_field_cols = ['slug' => 't.slug', 'id' => 'tt.term_id'];
        $field_col = $allowed_field_cols[$match_by] ?? 't.slug';
        $cats_rows = $wpdb->get_results(
            "SELECT tr.object_id, {$field_col} AS cat_value
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tr.object_id IN ($ids_in)",
            ARRAY_A
        );

        $local_cats_by_id = [];
        foreach ($cats_rows as $r) {
            $val = $match_by === 'slug' ? $r['cat_value'] : (int) $r['cat_value'];
            $local_cats_by_id[$r['object_id']][] = $val;
        }
        foreach ($local_cats_by_id as &$arr) {
            sort($arr);
        }
        unset($arr);

        // ── Step 3: compare in memory ──────────────────────────────────────────
        $mismatches = [];

        foreach ($local_ids as $id) {
            $sku = $sku_by_id[$id];
            if (!isset($remote_map[$sku])) {
                continue; // product missing on remote — not a category issue
            }

            $local_cats  = $local_cats_by_id[$id] ?? [];
            $remote_cats = $remote_map[$sku] ?? [];

            $missing = array_values(array_diff($local_cats, $remote_cats));
            $extra   = array_values(array_diff($remote_cats, $local_cats));

            if (!empty($missing) || !empty($extra)) {
                $mismatches[] = [
                    'product_id'   => (int) $id,
                    'product_name' => $name_by_id[$id] ?? '',
                    'sku'          => $sku,
                    'edit_link'    => get_edit_post_link($id),
                    'missing'      => $missing,
                    'extra'        => $extra,
                ];
            }
        }

        return [
            'store_url'    => $store_url,
            'store_name'   => $config['name'] ?? $store_url,
            'items'        => $mismatches,
            'count'        => count($mismatches),
            'total_local'  => count($local_ids),
            'total_remote' => count($remote_map),
            'match_by'     => $match_by,
        ];
    }

    // ── Category sync ─────────────────────────────────────────────────────────

    public function ajax_sync_by_category(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $category_id      = absint($_POST['category_id'] ?? 0);
        $sync_type        = sanitize_text_field($_POST['sync_type'] ?? 'full_product');
        $include_children = !empty($_POST['include_children']);

        if (!$category_id) {
            wp_send_json_error(['message' => __('Invalid category', 'wc-multi-store-sync')]);
            return;
        }

        $result = WC_Multi_Store_Category_Sync::queue_sync($category_id, $sync_type, $include_children);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        // queue_sync() itself reports 0 products as a normal (non-error)
        // result — appropriate for callers like WP-CLI iterating many
        // categories, where an empty one shouldn't halt a batch. But this
        // handler and ajax_force_sync_by_category() are two UI entry points
        // for the same action on the same category taxonomy screen, so they
        // should give the admin the same feedback for "nothing to sync"
        // instead of one silently succeeding and the other erroring.
        if ((int) $result['products'] === 0) {
            wp_send_json_error([
                'message' => sprintf(
                    __('No published products found in category "%s"', 'wc-multi-store-sync'),
                    esc_html($result['category_name'])
                ),
            ]);
            return;
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Queued %d product(s) from "%s" (%d queue items created).', 'wc-multi-store-sync'),
                $result['products'],
                $result['category_name'],
                $result['queued']
            ),
            'products'  => $result['products'],
            'queued'    => $result['queued'],
            'category'  => $result['category_name'],
        ]);
    }

    /**
     * Add "Sync to stores" row action on the product_cat taxonomy list screen.
     */
    public function add_category_row_action(array $actions, WP_Term $term): array {
        $url = add_query_arg([
            'page'    => 'wc-settings',
            'tab'     => 'multi_store_sync',
        ], admin_url('admin.php'));

        $actions['wc_mss_sync'] = sprintf(
            '<a href="#" class="wc-mss-cat-row-sync" data-category-id="%d" data-category-name="%s">%s</a>',
            esc_attr($term->term_id),
            esc_attr($term->name),
            esc_html__('Sync to stores', 'wc-multi-store-sync')
        );

        return $actions;
    }

    // ── Queue per-item retry ──────────────────────────────────────────────────

    public function ajax_queue_retry_item(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;

        if (!$item_id) {
            wp_send_json_error(['message' => __('Invalid item ID', 'wc-multi-store-sync')]);
            return;
        }

        if (WC_Multi_Store_Queue_Table::retry_item($item_id)) {
            wp_send_json_success(['message' => __('Item queued for retry.', 'wc-multi-store-sync')]);
        } else {
            wp_send_json_error(['message' => __('Item not found or is not in failed status.', 'wc-multi-store-sync')]);
        }
    }

    /**
     * AJAX: Queue a single product for a full force-sync to all active stores.
     *
     * Called by the "Re-sync" button in the Category Scan results on the
     * Discrepancies page (discrepancies.php). Uses 'manual_test' as source so
     * the smart-skip for unchanged data is bypassed and all fields are sent.
     */
    public function ajax_force_sync_product(): void {
        if (!self::verify_admin_request()) {
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'wc-multi-store-sync')]);
            return;
        }

        $added = WC_MSS()->queue_manager->add_product(
            $product_id,
            'manual_test',
            WC_Multi_Store_Queue_Manager::PRIORITY_HIGH,
            'full_product'
        );

        if ($added > 0) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Product queued for full sync to %d store(s)', 'wc-multi-store-sync'),
                    $added
                ),
                'queued' => $added,
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Product could not be queued — no active stores or product is excluded from all stores', 'wc-multi-store-sync'),
            ]);
        }
    }

    /**
     * Inline script for the category row action — only on product_cat screen.
     */
    public function category_row_action_script(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== 'product_cat') {
            return;
        }
        ?>
        <script>
        jQuery(function ($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('wc_mss_admin')); ?>';

            $(document).on('click', '.wc-mss-cat-row-sync', function (e) {
                e.preventDefault();
                var $link  = $(this);
                var catId  = $link.data('category-id');
                var catName = $link.data('category-name');

                if (!confirm('<?php echo esc_js(__('Sync all products in "%s" (and children) to all stores?', 'wc-multi-store-sync')); ?>'.replace('%s', catName))) {
                    return;
                }

                $link.text('<?php echo esc_js(__('Queuing…', 'wc-multi-store-sync')); ?>');

                $.post(ajaxurl, {
                    action:           'wc_mss_sync_by_category',
                    nonce:            nonce,
                    category_id:      catId,
                    sync_type:        'full_product',
                    include_children: 1,
                }, function (resp) {
                    if (resp.success) {
                        $link.text('<?php echo esc_js(__('Queued ✓', 'wc-multi-store-sync')); ?>');
                        alert(resp.data.message);
                    } else {
                        $link.text('<?php echo esc_js(__('Sync to stores', 'wc-multi-store-sync')); ?>');
                        alert(resp.data.message || '<?php echo esc_js(__('Error', 'wc-multi-store-sync')); ?>');
                    }
                }).fail(function () {
                    $link.text('<?php echo esc_js(__('Sync to stores', 'wc-multi-store-sync')); ?>');
                    alert('<?php echo esc_js(__('Request failed', 'wc-multi-store-sync')); ?>');
                });
            });
        });
        </script>
        <?php
    }
}
