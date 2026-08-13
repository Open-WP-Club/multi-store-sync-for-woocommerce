<?php
/**
 * Orphan Product Cleanup Class
 * Finds and removes products on remote stores that shouldn't exist
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Orphan_Cleanup {

    use WC_Multi_Store_Email_Shell;
    use WC_Multi_Store_Toggleable_Feature;

    const string BACKGROUND_HOOK   = 'wc_mss_orphan_scan_background';
    const string STATUS_OPTION     = 'wc_mss_orphan_scan_status';
    const string RESULTS_OPTION    = 'wc_mss_orphan_scan_results';

    /**
     * Recurring hook that scans all active stores and trashes any orphans
     * found, when auto-trash is enabled (see WC_Multi_Store_Toggleable_Feature).
     */
    const string AUTO_TRASH_HOOK   = 'wc_mss_orphan_auto_trash';

    /**
     * How often the auto-trash job runs. Not a standard WP interval — chosen
     * to give a product plenty of time to reappear (e.g. re-published after
     * an accidental trash) before it's swept off remote stores.
     */
    const int AUTO_TRASH_INTERVAL  = 2 * WEEK_IN_SECONDS;

    const string AUTO_TRASH_STATUS_OPTION = 'wc_mss_orphan_auto_trash_status';

    /**
     * @return array
     */
    public static function default_settings(): array {
        return ['enabled' => false];
    }

    /**
     * @return string
     */
    public static function feature_label(): string {
        return __('Orphan auto-trash', 'wc-multi-store-sync');
    }

    /**
     * @return string
     */
    public static function central_settings_prefix(): string {
        return 'orphan_auto_trash';
    }

    /**
     * Initialize orphan cleanup
     */
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_wc_mss_scan_orphans',        $this->ajax_scan_orphans(...));
        add_action('wp_ajax_wc_mss_cleanup_orphans',     $this->ajax_cleanup_orphans(...));
        add_action('wp_ajax_wc_mss_schedule_orphan_scan', $this->ajax_schedule_scan(...));
        add_action('wp_ajax_wc_mss_orphan_scan_status',  $this->ajax_get_scan_status(...));

        // Action Scheduler background hooks
        add_action(self::BACKGROUND_HOOK, $this->run_background_scan(...));
        add_action(self::AUTO_TRASH_HOOK, $this->run_auto_trash(...));
    }

    /**
     * Schedule the recurring auto-trash job (idempotent — call freely).
     * Reconciled automatically against the enabled setting by
     * WC_Multi_Store_Action_Scheduler_Manager::ensure_scheduled().
     */
    public static function schedule_auto_trash(): void {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return;
        }

        as_schedule_recurring_action(
            time() + self::AUTO_TRASH_INTERVAL,
            self::AUTO_TRASH_INTERVAL,
            self::AUTO_TRASH_HOOK,
            [],
            WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
        );

        WC_Multi_Store_Logger::write('Orphan auto-trash scheduled (every 2 weeks)');
    }

    /**
     * Unschedule the recurring auto-trash job.
     */
    public static function unschedule_auto_trash(): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::AUTO_TRASH_HOOK, [], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
        }
    }

    /**
     * Action Scheduler callback: scan every active store for orphans and
     * trash (not permanently delete) whatever is found, then email a
     * summary. Runs every 2 weeks while auto-trash is enabled.
     */
    public function run_auto_trash(): void {
        @set_time_limit(0);

        if (!self::is_enabled()) {
            return;
        }

        WC_Multi_Store_Logger::write('Orphan auto-trash run starting');

        $scan = $this->scan_store_for_orphans();

        if (empty($scan['success'])) {
            WC_Multi_Store_Logger::write('Orphan auto-trash: scan failed, skipping this run', 'warning');
            return;
        }

        $orphans = [];
        foreach ($scan['results'] as $store) {
            foreach ($store['orphans'] as $orphan) {
                $orphans[] = ['store_url' => $store['store_url'], 'product_id' => $orphan['id']];
            }
        }

        $cleanup = empty($orphans)
            ? ['success' => true, 'deleted' => 0, 'failed' => 0, 'errors' => []]
            : $this->cleanup_orphans($orphans, force: false);

        update_option(self::AUTO_TRASH_STATUS_OPTION, [
            'last_run_at' => current_time('mysql'),
            'trashed'     => $cleanup['deleted'],
            'failed'      => $cleanup['failed'],
        ], false);

        WC_Multi_Store_Logger::write(sprintf(
            'Orphan auto-trash run complete: %d trashed, %d failed',
            $cleanup['deleted'],
            $cleanup['failed']
        ));

        $this->send_auto_trash_email($scan, $cleanup);
    }

    /**
     * Send an email summary after an auto-trash run — only sent when the
     * run actually found (and trashed, or failed to trash) something, to
     * avoid a "nothing happened" email every 2 weeks.
     */
    private function send_auto_trash_email(array $scan, array $cleanup): void {
        if ($cleanup['deleted'] === 0 && $cleanup['failed'] === 0) {
            return;
        }

        $email_settings = get_option('wc_multi_store_sync_email_settings', []);
        $recipient      = $email_settings['recipient_email'] ?? get_option('admin_email');

        if (empty($recipient)) {
            return;
        }

        $site = get_bloginfo('name');

        $store_rows = '';
        foreach ($scan['results'] as $store) {
            $count = (int) ($store['total_orphans'] ?? 0);
            if ($count === 0) {
                continue;
            }
            $store_rows .= '<tr>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #dcdcde;font-size:13px;color:#3c434a;">'
                . esc_html($store['store_name'] ?? $store['store_url']) . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #dcdcde;font-size:13px;color:#3c434a;">'
                . $count . '</td>'
                . '</tr>';
        }

        $accent = $cleanup['failed'] > 0 ? '#b32d2e' : '#00a32a';
        $badge  = __('Orphan Auto-Trash', 'wc-multi-store-sync');
        $title  = sprintf(
            __('%d Product(s) Moved to Trash', 'wc-multi-store-sync'),
            $cleanup['deleted']
        );

        $lead = '<p style="margin:0 0 20px;font-size:14px;color:#3c434a;line-height:1.6;">'
            . __('Products found on a remote store but no longer on the main site were moved to trash there (not permanently deleted).', 'wc-multi-store-sync')
            . '</p>';

        $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;margin-bottom:24px;">'
            . '<thead><tr>'
            . '<th style="padding:10px 12px;background:#f6f7f7;font-size:12px;font-weight:600;color:#646970;text-align:left;border-bottom:1px solid #dcdcde;">'
            . __('Store', 'wc-multi-store-sync') . '</th>'
            . '<th style="padding:10px 12px;background:#f6f7f7;font-size:12px;font-weight:600;color:#646970;text-align:left;border-bottom:1px solid #dcdcde;">'
            . __('Orphans Found', 'wc-multi-store-sync') . '</th>'
            . '</tr></thead><tbody>'
            . $store_rows
            . '</tbody></table>';

        $failed_note = $cleanup['failed'] > 0
            ? '<p style="margin:0 0 20px;font-size:13px;color:#b32d2e;">'
                . sprintf(__('%d product(s) failed to trash — check the logs.', 'wc-multi-store-sync'), $cleanup['failed'])
                . '</p>'
            : '';

        $body = $this->wrap_email($title, $badge, $accent, $lead . $table . $failed_note);

        $subject = sprintf(
            __('[%s] Orphan Auto-Trash — %d trashed, %d failed', 'wc-multi-store-sync'),
            $site,
            $cleanup['deleted'],
            $cleanup['failed']
        );

        wp_mail($recipient, $subject, $body, [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site . ' <' . get_option('admin_email') . '>',
        ]);
    }

    /**
     * Scan remote stores for orphan products
     *
     * @param string|null $store_url Store URL to scan (optional, scans all if not provided)
     * @return array Results with orphan products found
     */
    public function scan_store_for_orphans(?string $store_url = null): array {
        if ($store_url) {
            $config = WC_Multi_Store_Settings::get_store($store_url);

            // Try with/without trailing slash if not found
            if ($config === null) {
                $alt_url = str_ends_with($store_url, '/')
                    ? rtrim($store_url, '/')
                    : $store_url . '/';
                $config = WC_Multi_Store_Settings::get_store($alt_url);
                if ($config !== null) {
                    $store_url = $alt_url;
                }
            }

            if ($config === null) {
                return [
                    'success' => false,
                    'message' => sprintf(__('Store not found: %s', 'wc-multi-store-sync'), $store_url),
                ];
            }

            $stores = [$store_url => $config];
        } else {
            $stores = WC_Multi_Store_Settings::get_active_stores();
        }

        if (empty($stores)) {
            return [
                'success' => false,
                'message' => __('No active stores configured.', 'wc-multi-store-sync'),
            ];
        }

        // PERFORMANCE FIX: Get local products ONCE before loop instead of inside
        $local_products = $this->get_local_product_identifiers();
        // Convert to set for O(1) lookup instead of O(n) in_array
        $local_skus_set = array_flip($local_products['skus']);

        $results = [];

        foreach ($stores as $url => $config) {
            WC_Multi_Store_Logger::write(sprintf('Scanning store for orphans: %s', $url));

            $store_result = [
                'store_url' => $url,
                'store_name' => $config['name'] ?? $url,
                'orphans' => [],
                'total_remote' => 0,
                'total_orphans' => 0,
                'error' => null,
            ];

            $api_client = WC_Multi_Store_API_Client::for_store($url, $config);

            // Get all products from remote store
            $remote_products = $this->get_all_remote_products($api_client, $url, $config);

            if (is_wp_error($remote_products)) {
                $store_result['error'] = $remote_products->get_error_message();
                $results[] = $store_result;
                continue;
            }

            $store_result['total_remote'] = count($remote_products);

            // Find orphans using the pre-fetched local products
            foreach ($remote_products as $remote_product) {
                // Match by SKU if available
                if (!empty($remote_product['sku'])) {
                    // PERFORMANCE FIX: Use isset() for O(1) lookup instead of in_array() O(n)
                    if (!isset($local_skus_set[$remote_product['sku']])) {
                        $store_result['orphans'][] = [
                            'id' => $remote_product['id'],
                            'name' => $remote_product['name'],
                            'sku' => $remote_product['sku'],
                            'price' => $remote_product['price'] ?? '',
                            'stock' => $remote_product['stock_quantity'] ?? '',
                        ];
                    }
                }
                // Skip products without SKU to avoid false positives
            }

            $store_result['total_orphans'] = count($store_result['orphans']);

            WC_Multi_Store_Logger::write(sprintf(
                'Orphan scan complete for %s: %d orphans found out of %d products',
                $url,
                $store_result['total_orphans'],
                $store_result['total_remote']
            ));

            $results[] = $store_result;
        }

        return [
            'success' => true,
            'results' => $results,
            'total_stores' => count($stores),
        ];
    }

    /**
     * Get all products from a remote store
     *
     * @param WC_Multi_Store_API_Client $api_client API client instance
     * @param string $store_url Store URL (unused, kept for signature compat)
     * @param array $config Store configuration (unused, client already configured)
     * @return array|WP_Error Array of products or WP_Error on failure
     */
    private function get_all_remote_products(WC_Multi_Store_API_Client $api_client, string $store_url, array $config): array|WP_Error {
        return $api_client->get_all_products(['per_page' => 100], 100);
    }

    /**
     * Get local product identifiers (SKUs and IDs)
     * Uses direct database query to avoid loading product objects into memory
     *
     * @return array Array with 'skus' and 'ids' keys
     */
    private function get_local_product_identifiers(): array {
        global $wpdb;

        // Get all product IDs directly from database
        $ids = $wpdb->get_col("
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type IN ('product', 'product_variation')
            AND post_status != 'trash'
        ");

        // Get all SKUs directly from postmeta - much more memory efficient
        // than loading each product object
        $skus = $wpdb->get_col("
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value != ''
            AND p.post_type IN ('product', 'product_variation')
            AND p.post_status != 'trash'
        ");

        return [
            'skus' => $skus,
            'ids' => $ids,
        ];
    }

    /**
     * Cleanup orphan products from remote stores
     *
     * @param array $orphans Array of orphan products to delete
     * @param bool $force Permanently delete (true, the manual "Delete Selected" behavior) or move to trash (false, used by the auto-trash job)
     * @return array Results
     */
    public function cleanup_orphans(array $orphans, bool $force = true): array {
        if (empty($orphans)) {
            return [
                'success' => false,
                'message' => __('No orphan products specified.', 'wc-multi-store-sync'),
            ];
        }

        $results = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($orphans as $orphan) {
            $store_url = $orphan['store_url'];
            $product_id = $orphan['product_id'];

            $config = WC_Multi_Store_Settings::get_store($store_url);

            if (!$config) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    __('Store not found: %s', 'wc-multi-store-sync'),
                    $store_url
                );
                continue;
            }

            // Delete product from remote store
            $api_client = WC_Multi_Store_API_Client::for_store($store_url, $config);
            $response = $api_client->delete_product($product_id, $force);

            if (is_wp_error($response)) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    __('Failed to delete product ID %d from %s: %s', 'wc-multi-store-sync'),
                    $product_id,
                    $store_url,
                    $response->get_error_message()
                );

                WC_Multi_Store_Logger::write(sprintf(
                    'Failed to delete orphan product ID %d from %s: %s',
                    $product_id,
                    $store_url,
                    $response->get_error_message()
                ));
            } else {
                $results['deleted']++;

                WC_Multi_Store_Logger::write(sprintf(
                    'Successfully deleted orphan product ID %d from %s',
                    $product_id,
                    $store_url
                ));
            }
        }

        return $results;
    }

    /**
     * Schedule a background orphan scan via Action Scheduler.
     */
    public function schedule_background_scan(string $store_url = ''): bool {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return false;
        }

        // Cancel any already-pending scan so we don't queue duplicates.
        as_unschedule_all_actions(self::BACKGROUND_HOOK, [], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);

        update_option(self::STATUS_OPTION, [
            'status'     => 'scheduled',
            'store_url'  => $store_url,
            'started_at' => null,
            'finished_at'=> null,
            'error'      => null,
        ], false);

        as_schedule_single_action(
            time() + 5,
            self::BACKGROUND_HOOK,
            [$store_url],
            WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
        );

        return true;
    }

    /**
     * Action Scheduler callback — runs the scan, stores results, sends email.
     */
    public function run_background_scan(string $store_url = ''): void {
        @set_time_limit(0);

        $status = get_option(self::STATUS_OPTION, []);
        $status['status']     = 'running';
        $status['started_at'] = current_time('mysql');
        update_option(self::STATUS_OPTION, $status, false);

        WC_Multi_Store_Logger::write(sprintf(
            'Background orphan scan started%s',
            $store_url ? " for store: {$store_url}" : ' for all stores'
        ));

        try {
            $results = $this->scan_store_for_orphans($store_url ?: null);

            update_option(self::RESULTS_OPTION, $results, false);

            $status['status']      = 'done';
            $status['finished_at'] = current_time('mysql');
            update_option(self::STATUS_OPTION, $status, false);

            WC_Multi_Store_Logger::write('Background orphan scan finished.');

            $this->send_scan_complete_email($results, $store_url);
        } catch (\Throwable $e) {
            $status['status']      = 'failed';
            $status['finished_at'] = current_time('mysql');
            $status['error']       = $e->getMessage();
            update_option(self::STATUS_OPTION, $status, false);

            WC_Multi_Store_Logger::write(
                'Background orphan scan failed: ' . $e->getMessage(), 'error'
            );
        }
    }

    /**
     * Send email summary after a background scan completes.
     */
    private function send_scan_complete_email(array $results, string $store_url = ''): void {
        $email_settings = get_option('wc_multi_store_sync_email_settings', []);
        $recipient      = $email_settings['recipient_email'] ?? get_option('admin_email');

        if (empty($recipient)) {
            return;
        }

        $site = get_bloginfo('name');

        $total_orphans = 0;
        $store_rows    = '';
        if (!empty($results['results'])) {
            foreach ($results['results'] as $store) {
                $count          = (int) ($store['total_orphans'] ?? 0);
                $total_orphans += $count;
                $color          = $count > 0 ? '#d63638' : '#00a32a';
                $store_rows    .= '<tr>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #dcdcde;font-size:13px;color:#3c434a;">'
                    . esc_html($store['store_name'] ?? $store['store_url']) . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #dcdcde;font-size:13px;color:#3c434a;">'
                    . (int) ($store['total_remote'] ?? 0) . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #dcdcde;font-size:13px;font-weight:600;color:' . $color . ';">'
                    . $count . '</td>'
                    . '</tr>';
            }
        }

        $accent   = $total_orphans > 0 ? '#b32d2e' : '#00a32a';
        $badge    = __('Orphan Cleanup', 'wc-multi-store-sync');
        $title    = $total_orphans > 0
            ? sprintf(__('Found %d Orphan Product(s)', 'wc-multi-store-sync'), $total_orphans)
            : __('No Orphan Products Found', 'wc-multi-store-sync');

        $lead = '<p style="margin:0 0 20px;font-size:14px;color:#3c434a;line-height:1.6;">'
            . ($store_url
                ? sprintf(__('Background scan of <strong>%s</strong> completed.', 'wc-multi-store-sync'), esc_html($store_url))
                : __('Background scan of all active stores completed.', 'wc-multi-store-sync'))
            . '</p>';

        $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;margin-bottom:24px;">'
            . '<thead><tr>'
            . '<th style="padding:10px 12px;background:#f6f7f7;font-size:12px;font-weight:600;color:#646970;text-align:left;border-bottom:1px solid #dcdcde;">'
            . __('Store', 'wc-multi-store-sync') . '</th>'
            . '<th style="padding:10px 12px;background:#f6f7f7;font-size:12px;font-weight:600;color:#646970;text-align:left;border-bottom:1px solid #dcdcde;">'
            . __('Remote Products', 'wc-multi-store-sync') . '</th>'
            . '<th style="padding:10px 12px;background:#f6f7f7;font-size:12px;font-weight:600;color:#646970;text-align:left;border-bottom:1px solid #dcdcde;">'
            . __('Orphans', 'wc-multi-store-sync') . '</th>'
            . '</tr></thead><tbody>'
            . $store_rows
            . '</tbody></table>';

        $cleanup_url = admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=orphan-cleanup');
        $button = '<p style="margin:0;">'
            . '<a href="' . esc_url($cleanup_url) . '" style="display:inline-block;padding:10px 20px;font-size:13px;font-weight:600;color:#ffffff;text-decoration:none;line-height:1;border-radius:5px;background:#0070a7;">'
            . __('View Results &amp; Clean Up', 'wc-multi-store-sync')
            . '</a></p>';

        $body = $this->wrap_email($title, $badge, $accent, $lead . $table . $button);

        $subject = sprintf(
            __('[%s] Orphan Scan Complete — %d orphan(s) found', 'wc-multi-store-sync'),
            $site,
            $total_orphans
        );

        wp_mail($recipient, $subject, $body, [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site . ' <' . get_option('admin_email') . '>',
        ]);
    }

    /**
     * AJAX: Schedule a background orphan scan.
     */
    public function ajax_schedule_scan(): void {
        check_ajax_referer('wc_mss_orphan_cleanup', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-multi-store-sync')]);
            return;
        }

        $store_url = sanitize_text_field($_POST['store_url'] ?? '');

        if (!$this->schedule_background_scan($store_url)) {
            wp_send_json_error(['message' => __('Action Scheduler is not available. Please ensure WooCommerce is active.', 'wc-multi-store-sync')]);
            return;
        }

        $recipient = get_option('wc_multi_store_sync_email_settings', [])['recipient_email']
            ?? get_option('admin_email');

        wp_send_json_success([
            'message'   => __('Scan scheduled. You will receive an email when it completes.', 'wc-multi-store-sync'),
            'recipient' => $recipient,
        ]);
    }

    /**
     * AJAX: Return current scan status and last results.
     */
    public function ajax_get_scan_status(): void {
        check_ajax_referer('wc_mss_orphan_cleanup', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-multi-store-sync')]);
            return;
        }

        $status  = get_option(self::STATUS_OPTION, ['status' => 'idle']);
        $results = get_option(self::RESULTS_OPTION, null);

        wp_send_json_success([
            'status'  => $status,
            'results' => $results,
        ]);
    }

    /**
     * AJAX handler for scanning orphans
     */
    public function ajax_scan_orphans(): void {
        check_ajax_referer('wc_mss_orphan_cleanup', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'wc-multi-store-sync'),
            ]);
        }

        @set_time_limit(0);

        try {
            $store_url = isset($_POST['store_url']) ? sanitize_text_field($_POST['store_url']) : null;

            WC_Multi_Store_Logger::write('Starting orphan scan via AJAX');

            $results = $this->scan_store_for_orphans($store_url);

            if ($results['success']) {
                wp_send_json_success($results);
            } else {
                wp_send_json_error($results);
            }
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'AJAX scan_orphans error: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');
            wp_send_json_error([
                'message' => __('An error occurred while scanning for orphans', 'wc-multi-store-sync'),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for cleaning up orphans
     */
    public function ajax_cleanup_orphans(): void {
        check_ajax_referer('wc_mss_orphan_cleanup', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'wc-multi-store-sync'),
            ]);
        }

        try {
            $orphans = [];
            if (!empty($_POST['orphans'])) {
                $orphans = json_decode(wp_unslash($_POST['orphans']), true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($orphans)) {
                    wp_send_json_error(['message' => __('Invalid orphan data format.', 'wc-multi-store-sync')]);
                    return;
                }
                $orphans = array_values(array_filter($orphans, fn($o) => is_array($o) && !empty($o['store_url']) && !empty($o['product_id'])));
            }

            if (empty($orphans)) {
                wp_send_json_error([
                    'message' => __('No orphan products specified.', 'wc-multi-store-sync'),
                ]);
            }

            WC_Multi_Store_Logger::write(sprintf('Starting orphan cleanup via AJAX: %d products', count($orphans)));

            $results = $this->cleanup_orphans($orphans);

            if ($results['success']) {
                wp_send_json_success([
                    'message' => sprintf(
                        __('Cleanup complete: %d deleted, %d failed.', 'wc-multi-store-sync'),
                        $results['deleted'],
                        $results['failed']
                    ),
                    'results' => $results,
                ]);
            } else {
                wp_send_json_error($results);
            }
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write('AJAX cleanup_orphans error: ' . $e->getMessage(), 'error');
            wp_send_json_error([
                'message' => __('An error occurred while cleaning up orphans', 'wc-multi-store-sync'),
                'error' => WP_DEBUG ? $e->getMessage() : null,
            ]);
        }
    }

    /**
     * Render admin page for orphan cleanup
     */
    public static function render_admin_page(): void {
        $stores            = WC_Multi_Store_Settings::get_active_stores();
        $scan_status       = get_option(self::STATUS_OPTION, ['status' => 'idle']);
        $last_results      = get_option(self::RESULTS_OPTION, null);
        $as_available      = WC_Multi_Store_Action_Scheduler_Manager::is_available();
        $email_settings    = get_option('wc_multi_store_sync_email_settings', []);
        $recipient         = $email_settings['recipient_email'] ?? get_option('admin_email');
        $nonce             = wp_create_nonce('wc_mss_orphan_cleanup');
        $auto_trash_status = get_option(self::AUTO_TRASH_STATUS_OPTION, null);

        ?>
        <div class="wrap wc-mss-orphan-cleanup">
            <h1><?php _e('Orphan Product Cleanup', 'wc-multi-store-sync'); ?></h1>

            <p class="description">
                <?php _e('Find and remove products on remote stores that don\'t exist on your main site.', 'wc-multi-store-sync'); ?>
            </p>

            <p>
                <label class="wc-mss-toggle">
                    <input type="checkbox" class="wc-mss-feature-toggle"
                           data-action="wc_mss_toggle_orphan_auto_trash"
                           <?php checked(self::is_enabled()); ?>>
                    <?php _e('Automatically move orphans to trash every 2 weeks', 'wc-multi-store-sync'); ?>
                </label>
                <p class="description">
                    <?php _e('Moves to trash only — never permanently deletes. Products with no SKU are always skipped.', 'wc-multi-store-sync'); ?>
                    <?php if ($auto_trash_status): ?>
                        <br>
                        <?php printf(
                            /* translators: 1: date, 2: trashed count, 3: failed count */
                            __('Last run: %1$s — %2$d trashed, %3$d failed.', 'wc-multi-store-sync'),
                            esc_html($auto_trash_status['last_run_at'] ?? ''),
                            (int) ($auto_trash_status['trashed'] ?? 0),
                            (int) ($auto_trash_status['failed'] ?? 0)
                        ); ?>
                    <?php endif; ?>
                </p>
            </p>

            <?php if (empty($stores)): ?>
                <div class="notice notice-warning">
                    <p><?php _e('No active stores configured. Please configure stores first.', 'wc-multi-store-sync'); ?></p>
                </div>
            <?php else: ?>
                <div class="wc-mss-orphan-scan-section">
                    <h2><?php _e('Scan for Orphan Products', 'wc-multi-store-sync'); ?></h2>

                    <p>
                        <label for="wc-mss-store-select">
                            <?php _e('Select Store:', 'wc-multi-store-sync'); ?>
                        </label>
                        <select id="wc-mss-store-select">
                            <option value=""><?php _e('All Stores', 'wc-multi-store-sync'); ?></option>
                            <?php foreach ($stores as $store_url => $config): ?>
                                <option value="<?php echo esc_attr($store_url); ?>">
                                    <?php echo esc_html($config['name'] ?? $store_url); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p class="wc-mss-scan-actions">
                        <button type="button" class="button button-primary" id="wc-mss-scan-orphans">
                            <?php _e('Scan Now (synchronous)', 'wc-multi-store-sync'); ?>
                        </button>

                        <?php if ($as_available): ?>
                            <button type="button" class="button" id="wc-mss-schedule-scan"
                                <?php echo in_array($scan_status['status'] ?? 'idle', ['scheduled', 'running'], true) ? 'disabled' : ''; ?>>
                                <?php _e('Schedule Background Scan', 'wc-multi-store-sync'); ?>
                            </button>
                        <?php else: ?>
                            <span class="description" style="margin-left:8px;">
                                <?php _e('(Background scan requires Action Scheduler / WooCommerce)', 'wc-multi-store-sync'); ?>
                            </span>
                        <?php endif; ?>

                        <span class="spinner" id="wc-mss-scan-spinner" style="float:none;margin:0 10px;"></span>
                    </p>

                    <!-- Background scan status banner -->
                    <div id="wc-mss-bg-status" style="margin-bottom:16px;<?php echo ($scan_status['status'] ?? 'idle') === 'idle' ? 'display:none;' : ''; ?>">
                        <?php
                        $status_val = $scan_status['status'] ?? 'idle';
                        $status_class = match($status_val) {
                            'scheduled', 'running' => 'notice-info',
                            'done'                  => 'notice-success',
                            'failed'                => 'notice-error',
                            default                 => 'notice-info',
                        };
                        $status_label = match($status_val) {
                            'scheduled' => __('Background scan scheduled — waiting for Action Scheduler to pick it up…', 'wc-multi-store-sync'),
                            'running'   => __('Background scan is running…', 'wc-multi-store-sync'),
                            'done'      => sprintf(__('Background scan completed at %s.', 'wc-multi-store-sync'), esc_html($scan_status['finished_at'] ?? '')),
                            'failed'    => sprintf(__('Background scan failed: %s', 'wc-multi-store-sync'), esc_html($scan_status['error'] ?? '')),
                            default     => '',
                        };
                        if ($status_label):
                        ?>
                        <div class="notice <?php echo $status_class; ?> inline" id="wc-mss-bg-status-notice">
                            <p id="wc-mss-bg-status-text"><?php echo $status_label; ?></p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="wc-mss-orphan-results" style="margin-top:20px;">
                        <?php if ($last_results && ($scan_status['status'] ?? '') === 'done'): ?>
                            <div class="notice notice-info inline" style="margin-bottom:12px;">
                                <p><?php
                                    $finished = $scan_status['finished_at'] ?? '';
                                    printf(
                                        __('Showing results from last background scan (%s).', 'wc-multi-store-sync'),
                                        esc_html($finished)
                                    );
                                ?></p>
                            </div>
                            <div id="wc-mss-last-results-placeholder" data-results="<?php echo esc_attr(wp_json_encode($last_results)); ?>"></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .wc-mss-orphan-cleanup .wc-mss-scan-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
            .wc-mss-orphan-cleanup .orphan-store-section { margin-bottom:1.875rem; border:1px solid #ddd; padding:0.9375rem; background:#fff; }
            .wc-mss-orphan-cleanup .orphan-store-section h3 { margin-top:0; }
            .wc-mss-orphan-cleanup .no-orphans { color:#46b450; font-weight:bold; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var nonce      = '<?php echo esc_js($nonce); ?>';
            var scanBtn    = $('#wc-mss-scan-orphans');
            var scheduleBtn= $('#wc-mss-schedule-scan');
            var spinner    = $('#wc-mss-scan-spinner');
            var resultsDiv = $('#wc-mss-orphan-results');
            var bgStatus   = $('#wc-mss-bg-status');
            var bgText     = $('#wc-mss-bg-status-text');
            var pollTimer  = null;

            // Render stored last-results on page load if present
            var lastResultsEl = $('#wc-mss-last-results-placeholder');
            if (lastResultsEl.length) {
                try {
                    var stored = JSON.parse(lastResultsEl.attr('data-results'));
                    if (stored && stored.results) {
                        lastResultsEl.replaceWith(buildResultsHtml(stored));
                        bindResultsEvents();
                    }
                } catch(e) {}
            }

            // Auto-start polling if scan is in-progress when page loads
            var initialStatus = '<?php echo esc_js($scan_status['status'] ?? 'idle'); ?>';
            if (initialStatus === 'scheduled' || initialStatus === 'running') {
                startPolling();
            }

            // Sync scan
            scanBtn.on('click', function() {
                var storeUrl = $('#wc-mss-store-select').val();
                scanBtn.prop('disabled', true);
                spinner.addClass('is-active');
                resultsDiv.html('<p><?php echo esc_js(__('Scanning stores…', 'wc-multi-store-sync')); ?></p>');

                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'wc_mss_scan_orphans', nonce: nonce, store_url: storeUrl },
                    success: function(res) {
                        if (res.success) {
                            resultsDiv.html(buildResultsHtml(res.data));
                            bindResultsEvents();
                        } else {
                            var msg    = (res.data && res.data.message) || '<?php echo esc_js(__('An error occurred while scanning for orphans', 'wc-multi-store-sync')); ?>';
                            var detail = (res.data && res.data.error) ? '<br><small>' + res.data.error + '</small>' : '';
                            resultsDiv.html('<div class="notice notice-error"><p>' + msg + detail + '</p></div>');
                        }
                    },
                    error: function() {
                        resultsDiv.html('<div class="notice notice-error"><p><?php echo esc_js(__('An error occurred while scanning.', 'wc-multi-store-sync')); ?></p></div>');
                    },
                    complete: function() { scanBtn.prop('disabled', false); spinner.removeClass('is-active'); }
                });
            });

            // Background scan scheduling
            scheduleBtn.on('click', function() {
                var storeUrl = $('#wc-mss-store-select').val();
                scheduleBtn.prop('disabled', true);
                spinner.addClass('is-active');

                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'wc_mss_schedule_orphan_scan', nonce: nonce, store_url: storeUrl },
                    success: function(res) {
                        if (res.success) {
                            var recipient = (res.data && res.data.recipient) ? res.data.recipient : '';
                            var msg = res.data.message;
                            if (recipient) msg += ' (' + recipient + ')';
                            showBgStatus('notice-info', msg);
                            startPolling();
                        } else {
                            var errMsg = (res.data && res.data.message) || '<?php echo esc_js(__('Failed to schedule scan.', 'wc-multi-store-sync')); ?>';
                            showBgStatus('notice-error', errMsg);
                            scheduleBtn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        showBgStatus('notice-error', '<?php echo esc_js(__('An error occurred.', 'wc-multi-store-sync')); ?>');
                        scheduleBtn.prop('disabled', false);
                    },
                    complete: function() { spinner.removeClass('is-active'); }
                });
            });

            function startPolling() {
                if (pollTimer) return;
                pollTimer = setInterval(pollStatus, 3000);
            }

            function stopPolling() {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            function pollStatus() {
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'wc_mss_orphan_scan_status', nonce: nonce },
                    success: function(res) {
                        if (!res.success) return;
                        var s = res.data.status || {};
                        var st = s.status || 'idle';

                        if (st === 'scheduled') {
                            showBgStatus('notice-info', '<?php echo esc_js(__('Background scan scheduled — waiting for Action Scheduler…', 'wc-multi-store-sync')); ?>');
                        } else if (st === 'running') {
                            showBgStatus('notice-info', '<?php echo esc_js(__('Background scan is running…', 'wc-multi-store-sync')); ?>');
                        } else if (st === 'done') {
                            stopPolling();
                            scheduleBtn.prop('disabled', false);
                            var finMsg = '<?php echo esc_js(__('Background scan completed at', 'wc-multi-store-sync')); ?> ' + (s.finished_at || '');
                            showBgStatus('notice-success', finMsg);
                            if (res.data.results && res.data.results.results) {
                                resultsDiv.html(buildResultsHtml(res.data.results));
                                bindResultsEvents();
                            }
                        } else if (st === 'failed') {
                            stopPolling();
                            scheduleBtn.prop('disabled', false);
                            showBgStatus('notice-error', '<?php echo esc_js(__('Background scan failed:', 'wc-multi-store-sync')); ?> ' + (s.error || ''));
                        } else {
                            stopPolling();
                        }
                    }
                });
            }

            function showBgStatus(cssClass, message) {
                var notice = bgStatus.find('.notice');
                if (!notice.length) {
                    bgStatus.html('<div class="notice inline"><p id="wc-mss-bg-status-text"></p></div>');
                    notice = bgStatus.find('.notice');
                }
                notice.attr('class', 'notice ' + cssClass + ' inline');
                notice.find('p').text(message);
                bgStatus.show();
            }

            function buildResultsHtml(data) {
                var html = '<h2><?php echo esc_js(__('Scan Results', 'wc-multi-store-sync')); ?></h2>';

                $.each(data.results, function(index, store) {
                    html += '<div class="orphan-store-section">';
                    html += '<h3>' + store.store_name + '</h3>';

                    if (store.error) {
                        html += '<p class="error"><?php echo esc_js(__('Error:', 'wc-multi-store-sync')); ?> ' + store.error + '</p>';
                    } else if (store.total_orphans === 0) {
                        html += '<p class="no-orphans"><?php echo esc_js(__('No orphan products found!', 'wc-multi-store-sync')); ?></p>';
                        html += '<p><?php echo esc_js(__('Scanned', 'wc-multi-store-sync')); ?> <strong>' + store.total_remote + '</strong> <?php echo esc_js(__('remote products.', 'wc-multi-store-sync')); ?></p>';
                    } else {
                        html += '<p><?php echo esc_js(__('Found', 'wc-multi-store-sync')); ?> <strong>' + store.total_orphans + '</strong> <?php echo esc_js(__('orphan product(s) out of', 'wc-multi-store-sync')); ?> ' + store.total_remote + ' <?php echo esc_js(__('total products.', 'wc-multi-store-sync')); ?></p>';
                        html += '<table class="wp-list-table widefat fixed striped">';
                        html += '<thead><tr>';
                        html += '<th style="width:32px"><input type="checkbox" class="select-all-orphans" data-store="' + index + '"></th>';
                        html += '<th><?php echo esc_js(__('ID', 'wc-multi-store-sync')); ?></th>';
                        html += '<th><?php echo esc_js(__('Name', 'wc-multi-store-sync')); ?></th>';
                        html += '<th><?php echo esc_js(__('SKU', 'wc-multi-store-sync')); ?></th>';
                        html += '<th><?php echo esc_js(__('Price', 'wc-multi-store-sync')); ?></th>';
                        html += '<th><?php echo esc_js(__('Stock', 'wc-multi-store-sync')); ?></th>';
                        html += '</tr></thead><tbody>';
                        $.each(store.orphans, function(i, orphan) {
                            html += '<tr>';
                            html += '<td><input type="checkbox" class="orphan-checkbox" data-store-index="' + index + '" data-store-url="' + store.store_url + '" data-product-id="' + orphan.id + '" data-product-name="' + orphan.name + '"></td>';
                            html += '<td>' + orphan.id + '</td>';
                            html += '<td>' + orphan.name + '</td>';
                            html += '<td>' + orphan.sku + '</td>';
                            html += '<td>' + orphan.price + '</td>';
                            html += '<td>' + orphan.stock + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                        html += '<p style="margin-top:10px;">';
                        html += '<button type="button" class="button button-link-delete cleanup-selected" data-store="' + index + '"><?php echo esc_js(__('Delete Selected', 'wc-multi-store-sync')); ?></button>';
                        html += '</p>';
                    }

                    html += '</div>';
                });

                return html;
            }

            function bindResultsEvents() {
                // Select all per store
                resultsDiv.off('change', '.select-all-orphans').on('change', '.select-all-orphans', function() {
                    var storeIndex = $(this).data('store');
                    var checked = $(this).is(':checked');
                    resultsDiv.find('.orphan-checkbox[data-store-index="' + storeIndex + '"]').prop('checked', checked);
                });

                // Delete selected
                resultsDiv.off('click', '.cleanup-selected').on('click', '.cleanup-selected', function() {
                    if (!confirm('<?php echo esc_js(__('Are you sure you want to delete the selected orphan products? This action cannot be undone.', 'wc-multi-store-sync')); ?>')) {
                        return;
                    }
                    var orphans = [];
                    resultsDiv.find('.orphan-checkbox:checked').each(function() {
                        orphans.push({
                            store_url: $(this).data('store-url'),
                            product_id: $(this).data('product-id'),
                            product_name: $(this).data('product-name')
                        });
                    });
                    if (orphans.length === 0) {
                        alert('<?php echo esc_js(__('Please select at least one product to delete.', 'wc-multi-store-sync')); ?>');
                        return;
                    }

                    var button = $(this);
                    button.prop('disabled', true).text('<?php echo esc_js(__('Deleting…', 'wc-multi-store-sync')); ?>');

                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: { action: 'wc_mss_cleanup_orphans', nonce: nonce, orphans: JSON.stringify(orphans) },
                        success: function(res) {
                            if (res.success) {
                                alert(res.data.message);
                                scanBtn.trigger('click');
                            } else {
                                alert((res.data && res.data.message) || '<?php echo esc_js(__('Failed.', 'wc-multi-store-sync')); ?>');
                                button.prop('disabled', false).text('<?php echo esc_js(__('Delete Selected', 'wc-multi-store-sync')); ?>');
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('An error occurred while cleaning up orphans.', 'wc-multi-store-sync')); ?>');
                            button.prop('disabled', false).text('<?php echo esc_js(__('Delete Selected', 'wc-multi-store-sync')); ?>');
                        }
                    });
                });
            }
        });
        </script>
        <?php
    }
}
