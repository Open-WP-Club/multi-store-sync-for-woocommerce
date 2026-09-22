<?php
/**
 * WooCommerce Settings Integration
 *
 * Integrates Multi-Store Sync into WooCommerce > Settings
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Settings Integration Class
 */
class WC_Multi_Store_Settings_Integration extends WC_Settings_Page {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id    = 'multi_store_sync';
        $this->label = __('Multi-Store Sync', 'multi-store-sync-for-woocommerce');

        // Parent constructor registers section/settings filters automatically
        parent::__construct();

        // Handle AJAX requests
        add_action('wp_ajax_wc_mss_test_connection', $this->ajax_test_connection(...));
        // Note: wc_mss_sync_product is handled by WC_Multi_Store_Product_Edit class
        add_action('wp_ajax_wc_mss_force_sync_all', $this->ajax_force_sync_all(...));
        // History deletion is registered centrally by WC_Multi_Store_Admin_Ajax.
        // Note: wc_mss_queue_orphans_for_deletion is registered in wc-multi-store-sync.php
        // because this file is only loaded via the settings filter, not during AJAX requests

        // Enqueue admin scripts on WooCommerce settings pages
        add_action('admin_enqueue_scripts', $this->enqueue_admin_scripts(...));
    }

    /**
     * Get sections
     *
     * @return array
     */
    #[\Override]
    public function get_sections(): array {
        // Return sections directly - parent class handles the filter application.
        // Category/attribute mapping and conflict detection are optional features
        // (see WC_Multi_Store_Toggleable_Feature / WC_Multi_Store_Conflict_Detector)
        // — their tabs only show up once turned on, so a store that never enabled
        // them doesn't have to look at empty, irrelevant screens.
        $sections = [
            ''                    => __('Dashboard', 'multi-store-sync-for-woocommerce'),
            'stores'              => __('Stores', 'multi-store-sync-for-woocommerce'),
        ];

        if (class_exists('WC_Multi_Store_Category_Mapper') && WC_Multi_Store_Category_Mapper::is_enabled()) {
            $sections['category-mapping'] = __('Category Mapping', 'multi-store-sync-for-woocommerce');
        }

        if (class_exists('WC_Multi_Store_Attribute_Remapper') && WC_Multi_Store_Attribute_Remapper::is_enabled()) {
            $sections['attribute-mapping'] = __('Attribute Mapping', 'multi-store-sync-for-woocommerce');
        }

        $sections['settings']            = __('Settings', 'multi-store-sync-for-woocommerce');
        $sections['queue']               = __('Queue', 'multi-store-sync-for-woocommerce');
        $sections['weekly-verification'] = __('Weekly Verification', 'multi-store-sync-for-woocommerce');
        $sections['history']             = __('History', 'multi-store-sync-for-woocommerce');
        $sections['api-usage']           = __('API Usage', 'multi-store-sync-for-woocommerce');
        $sections['discrepancies']       = __('Discrepancies', 'multi-store-sync-for-woocommerce');

        if (class_exists('WC_Multi_Store_Conflict_Detector') && !empty(WC_Multi_Store_Conflict_Detector::get_settings()['enabled'])) {
            $sections['conflicts'] = __('Conflicts', 'multi-store-sync-for-woocommerce');
        }

        $sections['deletion-audit']    = __('Deletion Audit', 'multi-store-sync-for-woocommerce');
        $sections['orphan-cleanup']    = __('Orphan Cleanup', 'multi-store-sync-for-woocommerce');
        $sections['dead-letter-queue'] = __('Dead Letters', 'multi-store-sync-for-woocommerce');
        $sections['sync-profiles']     = __('Sync Profiles', 'multi-store-sync-for-woocommerce');
        $sections['config']            = __('Export/Import', 'multi-store-sync-for-woocommerce');
        $sections['logs']              = __('Logs', 'multi-store-sync-for-woocommerce');

        return $sections;
    }

    /**
     * Output the settings
     */
    #[\Override]
    public function output(): void {
        global $current_section;

        // Handle form submissions. check_admin_referer() dies via wp_nonce_ays()
        // on an invalid nonce (outside DOING_AJAX), so at most one arm's handler
        // ever runs per request in practice — match(true) short-circuiting on
        // the first true arm is behaviorally the same as the previous sequential
        // if-blocks for every real request (each form posts only its own key).
        match (true) {
            $current_section === 'stores' && isset($_POST['wc_mss_add_store']) && check_admin_referer('wc_mss_add_store')
                => $this->handle_add_store(),
            $current_section === 'stores' && isset($_POST['wc_mss_update_store']) && check_admin_referer('wc_mss_update_store')
                => $this->handle_update_store(),
            $current_section === 'stores' && isset($_POST['wc_mss_delete_store']) && check_admin_referer('wc_mss_delete_store')
                => $this->handle_delete_store(),
            $current_section === 'settings' && isset($_POST['wc_mss_save_settings']) && check_admin_referer('wc_mss_save_settings')
                => $this->handle_save_settings(),
            $current_section === 'weekly-verification' && isset($_POST['wc_mss_save_weekly_verification']) && check_admin_referer('wc_mss_save_weekly_verification')
                => $this->handle_save_weekly_verification(),
            $current_section === 'weekly-verification' && isset($_POST['wc_mss_run_verification_now']) && check_admin_referer('wc_mss_run_verification_now')
                => $this->handle_run_verification_now(),
            $current_section === '' && isset($_POST['wc_mss_reschedule_actions']) && check_admin_referer('wc_mss_reschedule_actions')
                => $this->handle_reschedule_actions(),
            $current_section === '' && isset($_POST['wc_mss_run_verification_now']) && check_admin_referer('wc_mss_run_verification_now')
                => $this->handle_run_verification_now(),
            $current_section === 'queue' && isset($_POST['wc_mss_clear_pending_queue']) && check_admin_referer('wc_mss_clear_pending_queue')
                => $this->handle_clear_queue('pending'),
            $current_section === 'queue' && isset($_POST['wc_mss_clear_completed_queue']) && check_admin_referer('wc_mss_clear_completed_queue')
                => $this->handle_clear_queue('completed'),
            $current_section === 'queue' && isset($_POST['wc_mss_clear_failed_queue']) && check_admin_referer('wc_mss_clear_failed_queue')
                => $this->handle_clear_queue('failed'),
            $current_section === 'queue' && isset($_POST['wc_mss_reset_stuck_queue']) && check_admin_referer('wc_mss_reset_stuck_queue')
                => $this->handle_reset_stuck_queue(),
            $current_section === 'queue' && isset($_POST['wc_mss_retry_failed_queue']) && check_admin_referer('wc_mss_retry_failed_queue')
                => $this->handle_retry_failed_queue(),
            default => null,
        };

        // Output sections
        match ($current_section) {
            ''                    => $this->output_dashboard(),
            'stores'              => $this->output_stores(),
            'category-mapping'    => $this->output_category_mapping(),
            'attribute-mapping'   => $this->output_attribute_mapping(),
            'settings'            => $this->output_settings(),
            'queue'               => $this->output_queue(),
            'weekly-verification' => $this->output_weekly_verification(),
            'history'             => $this->output_history(),
            'api-usage'           => $this->output_api_usage(),
            'discrepancies'       => $this->output_discrepancies(),
            'conflicts'           => $this->output_conflicts(),
            'deletion-audit'      => $this->output_deletion_audit(),
            'orphan-cleanup'      => $this->output_orphan_cleanup(),
            'dead-letter-queue'   => $this->output_dead_letter_queue(),
            'sync-profiles'       => $this->output_sync_profiles(),
            'config'              => $this->output_config(),
            'logs'                => $this->output_logs(),
            default               => $this->output_dashboard(),
        };
    }

    /**
     * Suppress the WooCommerce "Save changes" button — each section has its own submit button.
     */
    public function output_buttons(): void {}

    /**
     * Save settings (required override)
     */
    #[\Override]
    public function save(): void {
        // Custom save logic is handled in specific sections
    }

    /**
     * Get settings array (required override)
     */
    #[\Override]
    public function get_settings($current_section = ''): array {
        return [];
    }

    /**
     * Output dashboard section
     */
    private function output_dashboard(): void {
        $stores = WC_Multi_Store_Settings::get_stores();
        $settings = WC_Multi_Store_Settings::get_settings();
        $scheduler_status = WC_Multi_Store_Action_Scheduler_Manager::get_status();

        include WC_MSS_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Output queue section
     */
    private function output_queue(): void {
        $queue_status_filter = sanitize_text_field($_GET['queue_status'] ?? 'all');
        $queue_stats = WC_Multi_Store_Queue_Table::get_stats();
        $queue_items = WC_Multi_Store_Queue_Table::get_recent_items(50, $queue_status_filter);

        include WC_MSS_PLUGIN_DIR . 'admin/views/queue.php';
    }

    /**
     * Output stores section
     */
    private function output_stores(): void {
        $stores = WC_Multi_Store_Settings::get_stores();
        include WC_MSS_PLUGIN_DIR . 'admin/views/stores.php';
    }

    /**
     * Output category/tag mapping section
     */
    private function output_category_mapping(): void {
        include WC_MSS_PLUGIN_DIR . 'admin/views/category-mapping.php';
    }

    /**
     * Output attribute name/value mapping section
     */
    private function output_attribute_mapping(): void {
        include WC_MSS_PLUGIN_DIR . 'admin/views/attribute-mapping.php';
    }

    /**
     * Output settings section
     */
    private function output_settings(): void {
        $settings = WC_Multi_Store_Settings::get_settings();
        include WC_MSS_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Output history section
     */
    private function output_history(): void {
        include WC_MSS_PLUGIN_DIR . 'admin/views/history.php';
    }

    /**
     * Output API usage section
     */
    private function output_api_usage(): void {
        // Handle export CSV
        if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
            check_admin_referer('wc_mss_export_api_usage');
            $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
            $args = [
                'start_date' => date('Y-m-d', strtotime("-{$days} days")),
                'end_date' => date('Y-m-d'),
            ];

            $csv = WC_Multi_Store_API_Usage_Tracker::export_to_csv($args);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="api-usage-' . date('Y-m-d') . '.csv"');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download response must not be HTML escaped.
            echo $csv;
            exit;
        }

        // Handle clear API usage data
        if (isset($_POST['wc_mss_clear_api_usage']) && check_admin_referer('wc_mss_clear_api_usage')) {
            $result = WC_Multi_Store_API_Usage_Tracker::clear_all_data();
            if ($result !== false) {
                WC_Admin_Settings::add_message(__('API usage data cleared successfully.', 'multi-store-sync-for-woocommerce'));
            } else {
                WC_Admin_Settings::add_error(__('Failed to clear API usage data.', 'multi-store-sync-for-woocommerce'));
            }
        }

        include WC_MSS_PLUGIN_DIR . 'admin/views/api-usage.php';
    }

    /**
     * Output discrepancies section
     */
    private function output_discrepancies(): void {
        include WC_MSS_PLUGIN_DIR . 'admin/views/discrepancies.php';
    }

    /**
     * Output conflicts section
     */
    private function output_conflicts(): void {
        $stores = WC_Multi_Store_Settings::get_stores();
        include WC_MSS_PLUGIN_DIR . 'admin/views/conflicts.php';
    }

    /**
     * Output logs section
     */
    private function output_logs(): void {
        $logger = new WC_Multi_Store_Logger();
        $logs = $logger->get_log(500);
        include WC_MSS_PLUGIN_DIR . 'admin/views/logs.php';
    }

    /**
     * Output deletion audit section
     */
    private function output_deletion_audit(): void {
        include WC_MSS_PLUGIN_DIR . 'admin/views/deletion-audit.php';
    }

    /**
     * Output orphan cleanup section
     */
    private function output_orphan_cleanup(): void {
        WC_Multi_Store_Orphan_Cleanup::render_admin_page();
    }

    /**
     * Output dead letter queue section
     */
    private function output_dead_letter_queue(): void {
        include WC_MSS_PLUGIN_DIR . 'admin/views/dead-letter-queue.php';
    }

    /**
     * Output sync profiles section
     */
    private function output_sync_profiles(): void {
        $profiles = WC_Multi_Store_Sync_Profiles::get_all();
        $presets  = WC_Multi_Store_Sync_Profiles::get_presets();
        include WC_MSS_PLUGIN_DIR . 'admin/views/sync-profiles.php';
    }

    /**
     * Output config export/import section
     */
    private function output_config(): void {
        include WC_MSS_PLUGIN_DIR . 'admin/views/config.php';
    }

    /**
     * Output weekly verification section
     */
    private function output_weekly_verification(): void {
        $settings = WC_Multi_Store_Weekly_Sync_Verifier::get_settings();
        $reports = WC_Multi_Store_Weekly_Sync_Verifier::get_reports(['limit' => 10]);
        $latest_report = WC_Multi_Store_Weekly_Sync_Verifier::get_latest_report();
        $next_run = WC_Multi_Store_Weekly_Sync_Verifier::get_next_scheduled_time();

        include WC_MSS_PLUGIN_DIR . 'admin/views/weekly-verification.php';
    }

    /**
     * Defense-in-depth: every form handler must verify the capability itself,
     * not rely solely on WooCommerce menu-level gating of `wc-settings`.
     */
    private function require_manage_capability(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('You do not have permission to perform this action.', 'multi-store-sync-for-woocommerce'),
                esc_html__('Insufficient permissions', 'multi-store-sync-for-woocommerce'),
                ['response' => 403]
            );
        }
    }

    /**
     * Handle add store
     */
    private function handle_add_store(): void {
        $this->require_manage_capability();
        $store_url = esc_url_raw(wp_unslash($_POST['store_url'] ?? ''));
        $consumer_key = sanitize_text_field($_POST['consumer_key']);
        $consumer_secret = sanitize_text_field($_POST['consumer_secret']);
        $status = sanitize_text_field($_POST['status']);
        $wp_username = sanitize_text_field($_POST['wp_username'] ?? '');
        $wp_app_password = sanitize_text_field($_POST['wp_app_password'] ?? '');

        // Get exclusion filters
        $exclude_categories = array_map('absint', $_POST['exclude_categories'] ?? []);
        $exclude_tags = array_map('absint', $_POST['exclude_tags'] ?? []);

        // Cache purge
        $cache_purge_url    = esc_url_raw(wp_unslash($_POST['cache_purge_url'] ?? ''));
        $cache_purge_method = in_array($_POST['cache_purge_method'] ?? 'GET', ['GET', 'POST'], true)
            ? ($_POST['cache_purge_method'] ?? 'GET')
            : 'GET';

        if (empty($store_url) || empty($consumer_key) || empty($consumer_secret)) {
            WC_Admin_Settings::add_error(__('Please fill in all required fields.', 'multi-store-sync-for-woocommerce'));
            return;
        }

        if (!filter_var($store_url, FILTER_VALIDATE_URL)) {
            WC_Admin_Settings::add_error(__('Invalid store URL. Please enter a valid URL including https://.', 'multi-store-sync-for-woocommerce'));
            return;
        }

        $store_config = [
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'status' => $status,
            'exclude_categories' => $exclude_categories,
            'exclude_tags' => $exclude_tags,
            'wp_username' => $wp_username,
            'wp_app_password' => $wp_app_password,
            'cache_purge_url'    => $cache_purge_url,
            'cache_purge_method' => $cache_purge_method,
            'added_date' => current_time('mysql'),
        ];

        if (WC_Multi_Store_Settings::update_store($store_url, $store_config)) {
            WC_Admin_Settings::add_message(__('Store added successfully.', 'multi-store-sync-for-woocommerce'));
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time(), 'wc_multi_store_health_check_single', [$store_url], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
            }
        }
    }

    /**
     * Handle delete store
     */
    private function handle_delete_store(): void {
        $this->require_manage_capability();
        $store_url = sanitize_text_field($_POST['store_url']);

        if (WC_Multi_Store_Settings::delete_store($store_url)) {
            WC_Admin_Settings::add_message(__('Store deleted successfully.', 'multi-store-sync-for-woocommerce'));
        }
    }

    /**
     * Handle update store
     */
    private function handle_update_store(): void {
        $this->require_manage_capability();
        $original_url = esc_url_raw(wp_unslash($_POST['original_store_url'] ?? ''));
        $store_url = esc_url_raw(wp_unslash($_POST['store_url'] ?? ''));
        $consumer_key = sanitize_text_field($_POST['consumer_key']);
        $consumer_secret = sanitize_text_field($_POST['consumer_secret']);
        $status = sanitize_text_field($_POST['status']);
        $wp_username = sanitize_text_field($_POST['wp_username'] ?? '');
        $wp_app_password = sanitize_text_field($_POST['wp_app_password'] ?? '');

        // Get exclusion filters
        $exclude_categories = array_map('absint', $_POST['exclude_categories'] ?? []);
        $exclude_tags = array_map('absint', $_POST['exclude_tags'] ?? []);

        // Cache purge
        $cache_purge_url    = esc_url_raw(wp_unslash($_POST['cache_purge_url'] ?? ''));
        $cache_purge_method = in_array($_POST['cache_purge_method'] ?? 'GET', ['GET', 'POST'], true)
            ? ($_POST['cache_purge_method'] ?? 'GET')
            : 'GET';

        // Get existing store data to preserve other fields (and the saved credentials).
        $stores = WC_Multi_Store_Settings::get_stores();
        $existing_store = $stores[$original_url] ?? [];

        // Blank key/secret on edit means "keep the saved one" — the view uses the
        // placeholder pattern so credentials are never embedded in the DOM.
        if (empty($consumer_key)) {
            $consumer_key = $existing_store['consumer_key'] ?? '';
        }
        if (empty($consumer_secret)) {
            $consumer_secret = $existing_store['consumer_secret'] ?? '';
        }

        if (empty($store_url) || empty($consumer_key) || empty($consumer_secret)) {
            WC_Admin_Settings::add_error(__('Please fill in all required fields.', 'multi-store-sync-for-woocommerce'));
            return;
        }

        if (!filter_var($store_url, FILTER_VALIDATE_URL)) {
            WC_Admin_Settings::add_error(__('Invalid store URL. Please enter a valid URL including https://.', 'multi-store-sync-for-woocommerce'));
            return;
        }

        $store_config = array_merge($existing_store, [
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'status' => $status,
            'exclude_categories' => $exclude_categories,
            'exclude_tags' => $exclude_tags,
            'wp_username' => $wp_username,
            'wp_app_password' => !empty($wp_app_password) ? $wp_app_password : ($existing_store['wp_app_password'] ?? ''),
            'cache_purge_url'    => $cache_purge_url,
            'cache_purge_method' => $cache_purge_method,
            'updated_date' => current_time('mysql'),
        ]);

        // If URL changed, delete old entry first
        if ($original_url !== $store_url) {
            WC_Multi_Store_Settings::delete_store($original_url);
        }

        if (WC_Multi_Store_Settings::update_store($store_url, $store_config)) {
            WC_Admin_Settings::add_message(__('Store updated successfully.', 'multi-store-sync-for-woocommerce'));
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time(), 'wc_multi_store_health_check_single', [$store_url], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
            }
            // Redirect to remove edit_store param
            wp_safe_redirect(remove_query_arg('edit_store'));
            exit;
        }
    }

    /**
     * Handle save settings
     */
    private function handle_save_settings(): void {
        $this->require_manage_capability();

        // Captured before update_all() overwrites it — used below to warn
        // when category/tag matching rules change under an already-synced store.
        $previous_settings = WC_Multi_Store_Settings::get_settings(false);

        $settings = [
            'enabled' => isset($_POST['enabled']),
            'sync_type_default' => sanitize_text_field($_POST['sync_type_default']),
            'auth_method' => sanitize_text_field($_POST['auth_method']),
            'match_products_by' => sanitize_text_field($_POST['match_products_by']),
            'category_match_mode' => sanitize_text_field($_POST['category_match_mode'] ?? 'full_path'),
            'category_match_by' => sanitize_text_field($_POST['category_match_by'] ?? 'slug'),
            'category_auto_create' => isset($_POST['category_auto_create']),
            'stock_sync_enabled' => isset($_POST['stock_sync_enabled']),
            'auto_create_missing_products' => isset($_POST['auto_create_missing_products']),
            'auto_sync_new_products' => isset($_POST['auto_sync_new_products']),
            'auto_sync_deletions' => isset($_POST['auto_sync_deletions']),
            'deletion_mode' => sanitize_text_field($_POST['deletion_mode'] ?? 'trash'),
            'auto_sync_restorations' => isset($_POST['auto_sync_restorations']),
            'auto_sync_status' => isset($_POST['auto_sync_status']),
            'image_proxy_enabled' => isset($_POST['image_proxy_enabled']),
            'delete_orphan_variations' => isset($_POST['delete_orphan_variations']),
            'cleanup_on_uninstall' => isset($_POST['cleanup_on_uninstall']),
            'circuit_breaker_threshold' => max(1, min(100, intval($_POST['circuit_breaker_threshold'] ?? 10))),
            'circuit_breaker_duration'  => max(60, min(86400, intval($_POST['circuit_breaker_duration'] ?? 1800))),
        ];

        // Save webhook settings
        $existing_webhook_settings = get_option('wc_multi_store_sync_webhook_settings', []);
        $existing_webhook_settings = is_array($existing_webhook_settings) ? $existing_webhook_settings : [];
        $submitted_webhook_secret = sanitize_text_field(wp_unslash($_POST['webhook_secret'] ?? ''));
        $webhook_secret = $submitted_webhook_secret !== ''
            ? $submitted_webhook_secret
            : ($existing_webhook_settings['webhook_secret'] ?? '');
        $webhook_settings = [
            'enabled' => isset($_POST['webhook_enabled']),
            'webhook_secret' => $webhook_secret,
            'trigger_statuses' => array_map('sanitize_text_field', $_POST['trigger_statuses'] ?? []),
            'allow_negative_stock' => isset($_POST['allow_negative_stock']),
            'auto_verify' => isset($_POST['auto_verify']),
            'webhook_log_retention_days' => max(30, min(180, intval($_POST['webhook_log_retention_days'] ?? 90))),
        ];

        update_option('wc_multi_store_sync_webhook_settings', $webhook_settings);

        // Save batch size and scheduled sync settings
        $scheduled_settings = get_option('wc_multi_store_sync_scheduled', []);
        if (isset($_POST['batch_size_peak'])) {
            $scheduled_settings['batch_size_peak'] = max(1, min(500, intval($_POST['batch_size_peak'])));
        }
        if (isset($_POST['batch_size_offpeak'])) {
            $scheduled_settings['batch_size_offpeak'] = max(1, min(500, intval($_POST['batch_size_offpeak'])));
        }
        // Scheduled sync settings
        $scheduled_settings['scheduled_sync_enabled'] = isset($_POST['scheduled_sync_enabled']) && $_POST['scheduled_sync_enabled'] === '1';
        if (isset($_POST['scheduled_sync_interval'])) {
            $valid_intervals = ['10min', '30min', 'hourly', 'daily'];
            $scheduled_settings['scheduled_sync_interval'] = in_array($_POST['scheduled_sync_interval'], $valid_intervals)
                ? $_POST['scheduled_sync_interval']
                : '10min';
        }
        if (isset($_POST['sync_all_products'])) {
            $scheduled_settings['sync_all_products'] = ($_POST['sync_all_products'] === '1');
        }
        if (isset($_POST['sync_modified_hours'])) {
            $scheduled_settings['sync_modified_hours'] = max(1, min(168, intval($_POST['sync_modified_hours'])));
        }

        // Scheduled sync specific settings
        if (isset($_POST['scheduled_sync_type'])) {
            $valid_types = ['use_default', 'full_product', 'price_quantity_categories', 'price_quantity', 'quantity'];
            $scheduled_settings['scheduled_sync_type'] = in_array($_POST['scheduled_sync_type'], $valid_types)
                ? sanitize_text_field($_POST['scheduled_sync_type'])
                : 'use_default';
        }
        if (isset($_POST['scheduled_stock_sync'])) {
            $valid_options = ['use_default', 'enabled', 'disabled'];
            $scheduled_settings['scheduled_stock_sync'] = in_array($_POST['scheduled_stock_sync'], $valid_options)
                ? sanitize_text_field($_POST['scheduled_stock_sync'])
                : 'use_default';
        }
        if (isset($_POST['scheduled_category_auto_create'])) {
            $valid_options = ['use_default', 'enabled', 'disabled'];
            $scheduled_settings['scheduled_category_auto_create'] = in_array($_POST['scheduled_category_auto_create'], $valid_options)
                ? sanitize_text_field($_POST['scheduled_category_auto_create'])
                : 'use_default';
        }

        update_option('wc_multi_store_sync_scheduled', $scheduled_settings);

        // Save email notification settings
        $current_email_settings = WC_Multi_Store_Email_Notifications::get_settings();
        $email_settings = [
            'enabled'               => isset($_POST['email_notifications_enabled']),
            'recipient_email'       => sanitize_email($_POST['email_recipient'] ?? $current_email_settings['recipient_email']),
            'failed_sync_enabled'   => isset($_POST['email_failed_sync_enabled']),
            'api_error_enabled'     => isset($_POST['email_api_error_enabled']),
            'low_stock_enabled'     => isset($_POST['email_low_stock_enabled']),
            'daily_summary_enabled' => isset($_POST['email_daily_summary_enabled']),
            'low_stock_threshold'   => max(0, intval($_POST['email_low_stock_threshold'] ?? $current_email_settings['low_stock_threshold'])),
            'daily_summary_time'    => sanitize_text_field($_POST['email_daily_summary_time'] ?? $current_email_settings['daily_summary_time']),
        ];
        if (empty($email_settings['recipient_email'])) {
            $email_settings['recipient_email'] = $current_email_settings['recipient_email'];
        }
        WC_Multi_Store_Email_Notifications::update_settings($email_settings);

        // Reschedule daily summary if settings changed
        if (class_exists('WC_Multi_Store_Email_Notifications')) {
            (new WC_Multi_Store_Email_Notifications())->schedule_daily_summary();
        }

        // Reschedule sync check with new interval
        if (class_exists('WC_Multi_Store_Action_Scheduler_Manager')) {
            WC_Multi_Store_Action_Scheduler_Manager::reschedule_sync_check();
        }

        if (WC_Multi_Store_Settings::update_all($settings)) {
            WC_Admin_Settings::add_message(__('Settings saved successfully.', 'multi-store-sync-for-woocommerce'));

            $category_match_changed = ($previous_settings['category_match_by'] ?? 'slug') !== $settings['category_match_by']
                || ($previous_settings['category_match_mode'] ?? 'full_path') !== $settings['category_match_mode'];

            // Only worth the warning if there's already a store that may have
            // synced categories/tags under the OLD matching rule — a fresh
            // install has nothing to reconcile yet.
            if ($category_match_changed && !empty(WC_Multi_Store_Settings::get_stores())) {
                WC_Admin_Settings::add_message(__(
                    'Category/tag matching method changed: remote stores already have categories/tags created under the previous rule. Products synced under the new rule may create duplicate categories/tags on remote stores instead of reusing the existing ones. Review remote categories/tags after the next sync.',
                    'multi-store-sync-for-woocommerce'
                ));
            }
        }
    }

    /**
     * Handle save weekly verification settings
     */
    private function handle_save_weekly_verification(): void {
        $this->require_manage_capability();
        $valid_sync_types = ['use_default', 'full_product', 'price_quantity_categories', 'price_quantity', 'quantity'];
        $valid_options = ['use_default', 'enabled', 'disabled'];

        $settings = [
            'enabled' => isset($_POST['verification_enabled']),
            'schedule' => sanitize_text_field($_POST['verification_schedule']),
            'day_of_week' => intval($_POST['verification_day'] ?? 1),
            'time_of_day' => sanitize_text_field($_POST['verification_time']),
            'check_stock' => isset($_POST['check_stock']),
            'check_prices' => isset($_POST['check_prices']),
            'product_limit' => intval($_POST['product_limit'] ?? 0),
            'batch_size' => max(1, min(100, intval($_POST['batch_size'] ?? 20))),
            'sample_mode' => sanitize_text_field($_POST['sample_mode']),
            'auto_correct' => isset($_POST['auto_correct']),
            'auto_correct_limit' => max(0, min(10000, intval($_POST['auto_correct_limit'] ?? 500))),
            'email_enabled' => isset($_POST['email_enabled']),
            'email_recipients' => sanitize_text_field($_POST['email_recipients']),
            // Auto-correct sync settings
            'weekly_sync_type' => isset($_POST['weekly_sync_type']) && in_array($_POST['weekly_sync_type'], $valid_sync_types)
                ? sanitize_text_field($_POST['weekly_sync_type'])
                : 'use_default',
            'weekly_stock_sync' => isset($_POST['weekly_stock_sync']) && in_array($_POST['weekly_stock_sync'], $valid_options)
                ? sanitize_text_field($_POST['weekly_stock_sync'])
                : 'use_default',
            'weekly_category_auto_create' => isset($_POST['weekly_category_auto_create']) && in_array($_POST['weekly_category_auto_create'], $valid_options)
                ? sanitize_text_field($_POST['weekly_category_auto_create'])
                : 'use_default',
        ];

        if (WC_Multi_Store_Weekly_Sync_Verifier::update_settings($settings)) {
            // Reschedule if settings changed
            WC_Multi_Store_Weekly_Sync_Verifier::schedule_verification();

            WC_Admin_Settings::add_message(__('Weekly verification settings saved successfully.', 'multi-store-sync-for-woocommerce'));
        }
    }

    /**
     * Handle reschedule actions
     */
    private function handle_reschedule_actions(): void {
        $this->require_manage_capability();
        if (!class_exists('WC_Multi_Store_Action_Scheduler_Manager')) {
            WC_Admin_Settings::add_error(__('Action Scheduler Manager not available.', 'multi-store-sync-for-woocommerce'));
            return;
        }

        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            WC_Admin_Settings::add_error(__('Action Scheduler is not available. Make sure WooCommerce is active.', 'multi-store-sync-for-woocommerce'));
            return;
        }

        WC_Multi_Store_Action_Scheduler_Manager::reschedule_all();

        WC_Admin_Settings::add_message(sprintf(
            /* translators: %s: time when actions were rescheduled. */
            __('Actions rescheduled successfully at %s. Refresh the page to see updated times.', 'multi-store-sync-for-woocommerce'),
            date('H:i:s', time())
        ));
    }

    /**
     * Handle clear queue items by status
     */
    private function handle_clear_queue(string $status): void {
        $this->require_manage_capability();

        $deleted = match ($status) {
            'pending' => WC_Multi_Store_Queue_Table::clear_pending(),
            'failed' => WC_Multi_Store_Queue_Table::clear_failed(),
            'completed' => WC_Multi_Store_Queue_Table::clear_completed(),
            default => 0,
        };

        WC_Admin_Settings::add_message(sprintf(
            /* translators: 1: number of cleared queue items, 2: queue status. */
            __('Cleared %1$d %2$s queue items.', 'multi-store-sync-for-woocommerce'),
            $deleted ?: 0,
            $status
        ));
    }

    /**
     * Handle reset stuck queue items
     */
    private function handle_reset_stuck_queue(): void {
        $this->require_manage_capability();
        $reset_count = WC_Multi_Store_Queue_Table::reset_stuck_items(10);

        if ($reset_count > 0) {
            WC_Admin_Settings::add_message(sprintf(
                /* translators: %d: number of queue items reset. */
                __('Reset %d stuck items back to pending status.', 'multi-store-sync-for-woocommerce'),
                $reset_count
            ));
        } else {
            WC_Admin_Settings::add_message(__('No stuck items found.', 'multi-store-sync-for-woocommerce'));
        }
    }

    /**
     * Handle retry failed queue items
     */
    private function handle_retry_failed_queue(): void {
        $this->require_manage_capability();
        $retry_count = WC_Multi_Store_Queue_Table::retry_failed_items();

        if ($retry_count > 0) {
            WC_Admin_Settings::add_message(sprintf(
                /* translators: %d: number of failed queue items queued for retry. */
                __('Queued %d failed items for retry.', 'multi-store-sync-for-woocommerce'),
                $retry_count
            ));
        } else {
            WC_Admin_Settings::add_message(__('No failed items to retry.', 'multi-store-sync-for-woocommerce'));
        }
    }

    /**
     * Handle run verification now
     */
    private function handle_run_verification_now(): void {
        $this->require_manage_capability();
        WC_Multi_Store_Logger::write('Manual weekly verification triggered from admin');

        // Check if WC_Multi_Store_Weekly_Sync_Verifier exists
        if (!class_exists('WC_Multi_Store_Weekly_Sync_Verifier')) {
            WC_Admin_Settings::add_error(__('Weekly Sync Verifier class not found.', 'multi-store-sync-for-woocommerce'));
            return;
        }

        // Run verification immediately (synchronously)
        try {
            $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification();

            // Handle error responses
            if (isset($result['error'])) {
                $error_messages = [
                    'No stores configured' => __('No stores are configured. Please add stores in the Stores tab first.', 'multi-store-sync-for-woocommerce'),
                    'No active stores' => __('No active stores found. Please ensure at least one store has "Active" status.', 'multi-store-sync-for-woocommerce'),
                    'No products to verify' => __('No products found to verify. Check your verification settings.', 'multi-store-sync-for-woocommerce'),
                ];
                $message = $error_messages[$result['error']] ?? $result['error'];
                WC_Admin_Settings::add_error($message);
                return;
            }

            if ($result && isset($result['discrepancies_found'])) {
                if ($result['discrepancies_found'] > 0) {
                    WC_Admin_Settings::add_message(sprintf(
                        /* translators: 1: products checked, 2: stores checked, 3: discrepancies found. */
                        __('Verification completed! Checked %1$d products across %2$d stores. Found %3$d discrepancies. Scroll down to see the report.', 'multi-store-sync-for-woocommerce'),
                        $result['products_checked'] ?? 0,
                        $result['stores_checked'] ?? 0,
                        $result['discrepancies_found']
                    ));
                } else {
                    WC_Admin_Settings::add_message(sprintf(
                        /* translators: 1: products checked, 2: stores checked. */
                        __('Verification completed! All %1$d products are in sync across %2$d stores.', 'multi-store-sync-for-woocommerce'),
                        $result['products_checked'] ?? 0,
                        $result['stores_checked'] ?? 0
                    ));
                }
            } else {
                WC_Admin_Settings::add_message(__('Verification completed. Refresh the page to see results.', 'multi-store-sync-for-woocommerce'));
            }
        } catch (Exception $e) {
            WC_Multi_Store_Logger::write('Verification error: ' . $e->getMessage(), 'error');
            WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: verification error message. */
                __('Verification failed: %s', 'multi-store-sync-for-woocommerce'),
                $e->getMessage()
            ));
        }
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_scripts(string $hook): void {
        // Only load on WooCommerce settings page with our tab
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        // Check if we're on our tab
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'multi_store_sync') {
            return;
        }

        wp_enqueue_style(
            'wc-mss-admin',
            WC_MSS_PLUGIN_URL . 'admin/css/admin-styles.css',
            [],
            WC_MSS_VERSION
        );

        wp_enqueue_script(
            'wc-mss-conflict-utils',
            WC_MSS_PLUGIN_URL . 'admin/js/conflict-utils.js',
            [],
            WC_MSS_VERSION,
            true
        );

        $script_deps = ['wc-mss-conflict-utils'];

        $chart_section = sanitize_text_field($_GET['section'] ?? '');
        if (in_array($chart_section, ['api-usage', ''], true)) {
            wp_enqueue_script(
                'wc-mss-chartjs',
                WC_MSS_PLUGIN_URL . 'admin/js/vendor/chart.min.js',
                [],
                WC_MSS_VERSION,
                true
            );
            $script_deps[] = 'wc-mss-chartjs';
        }

        wp_enqueue_script(
            'wc-mss-admin',
            WC_MSS_PLUGIN_URL . 'admin/js/admin-scripts.js',
            $script_deps,
            WC_MSS_VERSION,
            true
        );

        wp_localize_script('wc-mss-admin', 'wcMssAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_mss_admin'),
            'force_sync_nonce' => wp_create_nonce('wc_mss_force_sync_all'),
            'health_check_nonce' => wp_create_nonce('wc_mss_run_health_check'),
            'single_store_nonce' => wp_create_nonce('wc_mss_check_single_store'),
            'test_app_password_nonce' => wp_create_nonce('wc_mss_test_app_password'),
            'scan_categories_nonce' => wp_create_nonce('wc_mss_scan_categories'),
            'i18n' => [
                'confirm_sync_all' => __('Are you sure you want to force sync ALL products? This will queue all products for immediate sync.', 'multi-store-sync-for-woocommerce'),
                'syncing' => __('Syncing...', 'multi-store-sync-for-woocommerce'),
                'request_failed' => __('Request failed', 'multi-store-sync-for-woocommerce'),
                'copied' => __('Copied!', 'multi-store-sync-for-woocommerce'),
                'copy_failed' => __('Failed to copy. Please copy manually.', 'multi-store-sync-for-woocommerce'),
                'view_details' => __('View Details', 'multi-store-sync-for-woocommerce'),
                'hide_details' => __('Hide Details', 'multi-store-sync-for-woocommerce'),
                'total_requests' => __('Total Requests', 'multi-store-sync-for-woocommerce'),
                'successful' => __('Successful', 'multi-store-sync-for-woocommerce'),
                'failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
                'failed_to_update' => __('Failed to update setting', 'multi-store-sync-for-woocommerce'),
            ],
        ]);

        // Add section-specific data
        $section = sanitize_text_field($_GET['section'] ?? '');

        // Chart data for API usage page
        if ($section === 'api-usage' && class_exists('WC_Multi_Store_API_Usage_Tracker')) {
            $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
            $args = [
                'start_date' => date('Y-m-d', strtotime("-{$days} days")),
                'end_date' => date('Y-m-d'),
                'days' => $days,
            ];
            $daily_trend = WC_Multi_Store_API_Usage_Tracker::get_daily_trend($args);
            wp_localize_script('wc-mss-admin', 'wcMssChartData', $daily_trend);
        }

        // Store data for stores page (category/tag product counts)
        if ($section === 'stores' || $section === '') {
            $categories = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            ]);
            $tags = get_terms([
                'taxonomy' => 'product_tag',
                'hide_empty' => false,
            ]);

            $cat_counts = [];
            if (!is_wp_error($categories)) {
                foreach ($categories as $cat) {
                    $cat_counts[$cat->term_id] = intval($cat->count);
                }
            }

            $tag_counts = [];
            if (!is_wp_error($tags)) {
                foreach ($tags as $tag) {
                    $tag_counts[$tag->term_id] = intval($tag->count);
                }
            }

            $product_count = wp_count_posts('product');
            $total_products = intval($product_count->publish ?? 0);

            wp_localize_script('wc-mss-admin', 'wcMssStoreData', [
                'totalProducts' => $total_products,
                'categoryProducts' => $cat_counts,
                'tagProducts' => $tag_counts,
            ]);
        }

        // Strings for the logs page
        if ($section === 'logs') {
            wp_localize_script('wc-mss-admin', 'wcMssLogsData', [
                'i18n' => [
                    'confirm_clear_log' => __('Are you sure you want to clear all sync logs? This cannot be undone.', 'multi-store-sync-for-woocommerce'),
                    'no_log_entries' => __('No log entries found.', 'multi-store-sync-for-woocommerce'),
                    'failed_to_clear_logs' => __('Failed to clear logs', 'multi-store-sync-for-woocommerce'),
                    'confirm_clear_warnings_errors' => __('Remove all [WARNING] and [ERROR] entries from the log? INFO entries will be kept.', 'multi-store-sync-for-woocommerce'),
                    'no_warnings_errors' => __('No warnings or errors found — everything looks good!', 'multi-store-sync-for-woocommerce'),
                    'failed_to_clear' => __('Failed to clear', 'multi-store-sync-for-woocommerce'),
                    'please_enter_sku' => __('Please enter at least one SKU', 'multi-store-sync-for-woocommerce'),
                    'please_select_category' => __('Please select a category', 'multi-store-sync-for-woocommerce'),
                    'queuing' => __('Queuing...', 'multi-store-sync-for-woocommerce'),
                    'force_full_sync' => __('Force Full Sync', 'multi-store-sync-for-woocommerce'),
                    'refresh_to_see_logs' => __('Refresh the page in a moment to see the sync log entries appear below.', 'multi-store-sync-for-woocommerce'),
                    'an_error_occurred' => __('An error occurred', 'multi-store-sync-for-woocommerce'),
                ],
            ]);
        }

        // Strings for the discrepancies page's category-scan feature
        if ($section === 'discrepancies') {
            wp_localize_script('wc-mss-admin', 'wcMssDiscrepanciesData', [
                'i18n' => [
                    'no_active_stores' => __('No active stores found.', 'multi-store-sync-for-woocommerce'),
                    'starting_scan' => __('Starting scan…', 'multi-store-sync-for-woocommerce'),
                    'scanning' => __('Scanning:', 'multi-store-sync-for-woocommerce'),
                    'scan_complete' => __('Scan complete.', 'multi-store-sync-for-woocommerce'),
                    'unknown_error' => __('Unknown error', 'multi-store-sync-for-woocommerce'),
                    'request_failed' => __('Request failed.', 'multi-store-sync-for-woocommerce'),
                    'all_match' => __('All categories match across all stores!', 'multi-store-sync-for-woocommerce'),
                    'mismatch_count_label' => __('product(s) with category mismatch', 'multi-store-sync-for-woocommerce'),
                    'local' => __('local', 'multi-store-sync-for-woocommerce'),
                    'remote' => __('remote', 'multi-store-sync-for-woocommerce'),
                    'th_product' => __('Product', 'multi-store-sync-for-woocommerce'),
                    'th_sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
                    'th_missing' => __('Missing on remote', 'multi-store-sync-for-woocommerce'),
                    'th_extra' => __('Extra on remote', 'multi-store-sync-for-woocommerce'),
                    'th_action' => __('Action', 'multi-store-sync-for-woocommerce'),
                    'resync' => __('Re-sync', 'multi-store-sync-for-woocommerce'),
                    'queuing' => __('Queuing…', 'multi-store-sync-for-woocommerce'),
                    'queued' => __('Queued ✓', 'multi-store-sync-for-woocommerce'),
                    'failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
                ],
            ]);
        }

        // Strings for the conflicts page
        if ($section === 'conflicts') {
            wp_localize_script('wc-mss-admin', 'wcMssConflictsData', [
                'i18n' => [
                    'loading' => __('Loading…', 'multi-store-sync-for-woocommerce'),
                    'load_failed' => __('Failed to load conflicts.', 'multi-store-sync-for-woocommerce'),
                    'no_conflicts' => __('No conflicts found.', 'multi-store-sync-for-woocommerce'),
                    'th_product' => __('Product', 'multi-store-sync-for-woocommerce'),
                    'th_store' => __('Store', 'multi-store-sync-for-woocommerce'),
                    'th_changed_fields' => __('Changed Fields', 'multi-store-sync-for-woocommerce'),
                    'th_detected' => __('Detected', 'multi-store-sync-for-woocommerce'),
                    'th_status' => __('Status', 'multi-store-sync-for-woocommerce'),
                    'th_actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
                    'resolved' => __('Resolved', 'multi-store-sync-for-woocommerce'),
                    'unresolved' => __('Unresolved', 'multi-store-sync-for-woocommerce'),
                    'overwrite' => __('Overwrite', 'multi-store-sync-for-woocommerce'),
                    'keep_remote' => __('Keep Remote', 'multi-store-sync-for-woocommerce'),
                    'merge' => __('Merge', 'multi-store-sync-for-woocommerce'),
                    'resolving' => __('Resolving…', 'multi-store-sync-for-woocommerce'),
                    'resolve_failed' => __('Failed to resolve conflict.', 'multi-store-sync-for-woocommerce'),
                    /* translators: %s: selected conflict resolution. */
                    'confirm_resolve_all' => __('Resolve all unresolved conflicts shown below as "%s"?', 'multi-store-sync-for-woocommerce'),
                    'resolve_all' => __('Resolve All', 'multi-store-sync-for-woocommerce'),
                    'product_not_found' => __('(Product not found)', 'multi-store-sync-for-woocommerce'),
                ],
            ]);
        }

        // Strings for the sync-profiles page
        if ($section === 'sync-profiles') {
            wp_localize_script('wc-mss-admin', 'wcMssSyncProfilesData', [
                'i18n' => [
                    'enter_name' => __('Please enter a profile name.', 'multi-store-sync-for-woocommerce'),
                    'saving' => __('Saving…', 'multi-store-sync-for-woocommerce'),
                    'save_profile' => __('Save Profile', 'multi-store-sync-for-woocommerce'),
                    'error_saving' => __('Error saving profile.', 'multi-store-sync-for-woocommerce'),
                    /* translators: %s: preset name. */
                    'confirm_apply_preset' => __('Apply preset "%s"? This will overwrite your current settings.', 'multi-store-sync-for-woocommerce'),
                    'error_applying_preset' => __('Error applying preset.', 'multi-store-sync-for-woocommerce'),
                    /* translators: %s: profile name. */
                    'confirm_apply_profile' => __('Apply profile "%s"? This will overwrite your current settings.', 'multi-store-sync-for-woocommerce'),
                    'error_applying_profile' => __('Error applying profile.', 'multi-store-sync-for-woocommerce'),
                    /* translators: %s: profile name. */
                    'confirm_delete_profile' => __('Delete profile "%s"? This cannot be undone.', 'multi-store-sync-for-woocommerce'),
                    'error_deleting_profile' => __('Error deleting profile.', 'multi-store-sync-for-woocommerce'),
                ],
            ]);
        }

        // Strings for the category/tag mapping page
        if ($section === 'category-mapping') {
            wp_localize_script('wc-mss-admin', 'wcMssCategoryMappingData', [
                'i18n' => [
                    'loading' => __('Loading…', 'multi-store-sync-for-woocommerce'),
                    'load_failed' => __('Failed to load categories/tags for this store.', 'multi-store-sync-for-woocommerce'),
                    'no_mapping' => __('— Don\'t map (send as-is) —', 'multi-store-sync-for-woocommerce'),
                    'skip' => __('— Skip (don\'t sync) —', 'multi-store-sync-for-woocommerce'),
                    'saving' => __('Saving…', 'multi-store-sync-for-woocommerce'),
                    'saved' => __('Saved ✓', 'multi-store-sync-for-woocommerce'),
                    'save_failed' => __('Failed to save', 'multi-store-sync-for-woocommerce'),
                ],
            ]);
        }

        // Strings for the attribute name/value mapping page
        if ($section === 'attribute-mapping') {
            wp_localize_script('wc-mss-admin', 'wcMssAttributeMappingData', [
                'i18n' => [
                    'loading' => __('Loading…', 'multi-store-sync-for-woocommerce'),
                    'load_failed' => __('Failed to load attributes for this store.', 'multi-store-sync-for-woocommerce'),
                    'no_mapping' => __('— Don\'t map (send as-is) —', 'multi-store-sync-for-woocommerce'),
                    'skip' => __('— Skip (don\'t sync) —', 'multi-store-sync-for-woocommerce'),
                    'saving' => __('Saving…', 'multi-store-sync-for-woocommerce'),
                    'saved' => __('Saved ✓', 'multi-store-sync-for-woocommerce'),
                    'save_failed' => __('Failed to save', 'multi-store-sync-for-woocommerce'),
                    'no_values' => __('This attribute has no terms to map.', 'multi-store-sync-for-woocommerce'),
                ],
            ]);
        }
    }

    /**
     * AJAX: Test connection to store
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $store_url = sanitize_text_field($_POST['store_url']);
        $consumer_key = sanitize_text_field($_POST['consumer_key']);
        $consumer_secret = sanitize_text_field($_POST['consumer_secret']);

        if (empty($store_url) || !WC_Multi_Store_Settings::is_safe_remote_url($store_url)) {
            wp_send_json_error(__('Store URL is not allowed.', 'multi-store-sync-for-woocommerce'));
        }

        $api = WC_Multi_Store_API_Client::for_store($store_url, [
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret,
        ]);

        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('Connection successful!', 'multi-store-sync-for-woocommerce'));
        }
    }

    /**
     * Handle force sync all AJAX request - schedules via Action Scheduler
     */
    public function ajax_force_sync_all(): void {
        check_ajax_referer('wc_mss_force_sync_all', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        // Check Action Scheduler is available
        if (!function_exists('as_schedule_single_action')) {
            wp_send_json_error(['message' => __('Action Scheduler not available.', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        // Get stores - bypass cache to ensure fresh data
        $stores = WC_Multi_Store_Settings::get_active_stores(false);

        if (empty($stores)) {
            $all_stores = WC_Multi_Store_Settings::get_stores();
            WC_Multi_Store_Logger::write(sprintf(
                'Force sync failed: No active stores. Total stores: %d',
                count($all_stores)
            ), 'warning');
            wp_send_json_error(['message' => __('No active stores configured. Please ensure at least one store has Status set to "Active".', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        // Get product count for the message
        $product_count = wp_count_posts('product');
        $total_products = (int) ($product_count->publish ?? 0);

        if ($total_products === 0) {
            wp_send_json_error(['message' => __('No products found to sync.', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        // Cancel any existing force sync actions
        as_unschedule_all_actions('wc_multi_store_sync_force_sync_batch', [], 'wc_multi_store_sync');

        // Schedule the first batch to run immediately
        as_schedule_single_action(
            time(),
            'wc_multi_store_sync_force_sync_batch',
            ['page' => 1],
            'wc_multi_store_sync'
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Force sync scheduled: %d products will be queued in batches for sync to %d stores',
            $total_products,
            count($stores)
        ), 'info');

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of products queued for force sync. */
                __('Force sync scheduled! %d products will be queued in batches. Check the Logs tab for progress.', 'multi-store-sync-for-woocommerce'),
                $total_products
            )
        ]);
    }

    /**
     * Process force sync batch (called by Action Scheduler)
     * Processes 50 products per batch to reduce Action Scheduler overhead
     */
    public static function process_force_sync_batch(int $page = 1): void {
        $batch_size = 50; // Process 50 products per Action Scheduler action

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'paged' => $page,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        $query = new WP_Query($args);
        $product_ids = $query->posts;

        if (empty($product_ids)) {
            WC_Multi_Store_Logger::write('Force sync complete: All batches processed', 'info');
            return;
        }

        // Queue products
        $queued = 0;
        if (class_exists('WC_Multi_Store_Queue_Manager')) {
            foreach ($product_ids as $product_id) {
                WC_MSS()->queue_manager->add_product($product_id, 'force_sync', 1);
                $queued++;
            }
        }

        // Calculate total products processed so far
        $total_processed = (($page - 1) * $batch_size) + count($product_ids);

        // Log progress every 10 batches (500 products) or on first batch
        if ($page % 10 === 0 || $page === 1) {
            WC_Multi_Store_Logger::write(sprintf(
                'Force sync progress: ~%d products queued so far (batch %d)',
                $total_processed,
                $page
            ), 'info');
        }

        // If we got a full batch, schedule the next one
        if (count($product_ids) === $batch_size) {
            as_schedule_single_action(
                time(), // No delay - Action Scheduler handles throttling
                'wc_multi_store_sync_force_sync_batch',
                ['page' => $page + 1],
                'wc_multi_store_sync'
            );
        } else {
            WC_Multi_Store_Logger::write(sprintf(
                'Force sync complete: %d products queued total',
                $total_processed
            ), 'info');
        }
    }

    /**
     * AJAX: Delete sync history
     */
    public function ajax_delete_history(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        $delete_type = sanitize_text_field($_POST['delete_type'] ?? '');

        if (empty($delete_type)) {
            wp_send_json_error(['message' => __('Invalid deletion type', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        $deleted = 0;

        switch ($delete_type) {
            case 'all':
                // Clear all history
                if (WC_Multi_Store_Sync_History::clear_all()) {
                    $deleted = -1; // Signal all deleted
                }
                break;

            case 'errors':
                // Delete only error records
                $deleted = WC_Multi_Store_Sync_History::delete_errors();
                break;

            case 'successful':
                // Delete only successful records
                $deleted = WC_Multi_Store_Sync_History::delete_successful();
                break;

            case 'older_than':
                // Delete records older than X days
                $days = absint($_POST['days'] ?? 30);
                $deleted = WC_Multi_Store_Sync_History::cleanup_old_records($days);
                break;

            case 'by_store':
                // Delete records for specific store
                $store_url = sanitize_text_field($_POST['store_url'] ?? '');
                if (!empty($store_url)) {
                    $deleted = WC_Multi_Store_Sync_History::delete_by_store($store_url);
                }
                break;

            default:
                wp_send_json_error(['message' => __('Unknown deletion type', 'multi-store-sync-for-woocommerce')]);
                return;
        }

        if ($deleted === -1) {
            wp_send_json_success([
                'message' => __('All history records deleted successfully', 'multi-store-sync-for-woocommerce'),
                'deleted' => 0,
                'remaining' => 0,
            ]);
        } elseif ($deleted > 0) {
            $remaining = WC_Multi_Store_Sync_History::get_count();
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: number of history records deleted. */
                    __('%d history records deleted successfully', 'multi-store-sync-for-woocommerce'),
                    $deleted
                ),
                'deleted' => $deleted,
                'remaining' => $remaining,
            ]);
        } else {
            wp_send_json_error(['message' => __('No records found to delete', 'multi-store-sync-for-woocommerce')]);
        }
    }

}

return new WC_Multi_Store_Settings_Integration();
