<?php
/**
 * Plugin Name: WooCommerce Multi-Store Sync
 * Plugin URI: https://gkanev.com
 * Description: Professional multi-store product synchronization for WooCommerce - standalone implementation with advanced features
 * Version: 4.0.1
 * Author: Gkanev.com
 * Author URI: https://gkanev.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wc-multi-store-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.4
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_MSS_VERSION', '4.0.1');
define('WC_MSS_PLUGIN_FILE', __FILE__);
define('WC_MSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_MSS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_MSS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Declare HPOS (High-Performance Order Storage) compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Main plugin class
 */
class WC_Multi_Store_Sync {

    /**
     * Single instance of the class
     *
     * @var WC_Multi_Store_Sync
     */
    private static ?self $instance = null;

    /**
     * API Client instance
     *
     * @var WC_Multi_Store_API_Client
     */
    public ?WC_Multi_Store_API_Client $api_client = null;

    /**
     * Sync Engine instance
     *
     * @var WC_Multi_Store_Sync_Engine
     */
    public ?WC_Multi_Store_Sync_Engine $sync_engine = null;

    /**
     * Order Sync instance
     *
     * @var WC_Multi_Store_Order_Sync
     */
    public ?WC_Multi_Store_Order_Sync $order_sync = null;

    /**
     * Hooks instance
     *
     * @var WC_Multi_Store_Hooks
     */
    public ?WC_Multi_Store_Hooks $hooks = null;

    /**
     * Queue Manager instance
     *
     * @var WC_Multi_Store_Queue_Manager
     */
    public WC_Multi_Store_Queue_Manager $queue_manager;

    /**
     * Get single instance
     *
     * @return WC_Multi_Store_Sync
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', $this->woocommerce_missing_notice(...));
            return;
        }

        // Include required files
        $this->includes();

        // Initialize the plugin
        $this->init();

        // Setup hooks
        $this->setup_hooks();
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    private function is_woocommerce_active() {
        // Check regular activation
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        // Check network-wide activation (multisite)
        if (is_multisite()) {
            $network_plugins = get_site_option('active_sitewide_plugins', []);
            if (isset($network_plugins['woocommerce/woocommerce.php'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('WooCommerce Multi-Store Sync requires WooCommerce to be installed and active.', 'wc-multi-store-sync'); ?></p>
        </div>
        <?php
    }

    /**
     * Include required files
     */
    private function includes() {
        // Composer's classmap autoloader (vendor/composer/autoload_classmap.php,
        // generated from composer.json's "classmap": ["includes/"]) already maps
        // every WC_Multi_Store_* class to its file, so it covers plugin classes
        // in addition to PSR-3 etc. No separate plugin autoloader is needed.
        $composer_autoload = WC_MSS_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($composer_autoload)) {
            require_once $composer_autoload;
        }
    }

    /**
     * Initialize plugin components
     */
    private function init(): void {
        $this->init_core();
        $this->init_features();
        $this->init_ajax();
        $this->init_admin();
        $this->init_hooks();
    }

    /**
     * Initialize core sync engine components.
     * These are needed on every request (frontend, admin, cron, AJAX).
     */
    private function init_core(): void {
        $this->api_client    = new WC_Multi_Store_API_Client();
        $this->sync_engine   = new WC_Multi_Store_Sync_Engine($this->api_client);
        $this->queue_manager = new WC_Multi_Store_Queue_Manager();
        $this->order_sync    = new WC_Multi_Store_Order_Sync();
        $this->hooks         = new WC_Multi_Store_Hooks();

        new WC_Multi_Store_Action_Scheduler_Manager();
        new WC_Multi_Store_Webhook_Receiver();
    }

    /**
     * Initialize feature modules.
     * Each module registers its own hooks internally on construction.
     * Modules with their own enable-toggle are gated here so disabled
     * features skip both the constructor and the class autoload.
     */
    private function init_features(): void {
        new WC_Multi_Store_Category_Deletion_Sync();

        if (WC_Multi_Store_Shipping_Class_Sync::is_enabled()) {
            new WC_Multi_Store_Shipping_Class_Sync();
        }

        $coupon_settings = get_option('wc_multi_store_sync_coupon_settings', ['enabled' => false]);
        if (!empty($coupon_settings['enabled'])) {
            new WC_Multi_Store_Coupon_Sync();
        }

        if (WC_Multi_Store_Email_Notifications::is_enabled()) {
            new WC_Multi_Store_Email_Notifications();
        }

        new WC_Multi_Store_API_Usage_Tracker();

        // Orphan cleanup and remote-order sync only ever fire from admin,
        // AJAX, or scheduled-cron contexts — skip on plain front-end loads.
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            new WC_Multi_Store_Orphan_Cleanup();
            new WC_Multi_Store_Remote_Order_Sync();
        }
    }

    /**
     * Register all wp_ajax_wc_mss_* handlers.
     * Must run outside any is_admin() gate so AJAX requests are served correctly.
     */
    private function init_ajax(): void {
        new WC_Multi_Store_Admin_Ajax();
    }

    /**
     * Initialize admin-only UI components.
     */
    private function init_admin(): void {
        if (!is_admin()) {
            return;
        }

        new WC_Multi_Store_Bulk_Actions();
        new WC_Multi_Store_Product_Edit();
        new WC_Multi_Store_Remote_Order_Admin();
        new WC_Multi_Store_Dashboard_Widget();

        add_filter('woocommerce_get_settings_pages', $this->add_woocommerce_settings_tab(...));
    }

    /**
     * Register WordPress action/filter hooks and late-running utilities.
     */
    private function init_hooks(): void {
        // Stock verification triggered by scheduled Action Scheduler job
        add_action('wc_multi_store_verify_stock', WC_Multi_Store_Stock_Verifier::verify_product_stock(...), 10, 3);

        // Database upgrades (admin / cron / AJAX only, gated inside the method)
        add_action('init', $this->maybe_upgrade_database(...), 5);

        // Post-activation cleanup of duplicate scheduled actions
        add_action('init', $this->maybe_cleanup_scheduled_actions(...), 15);

        // WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('mss', 'WC_Multi_Store_CLI_Commands');
        }
    }

    /**
     * Check if database needs upgrading (creates missing tables)
     */
    public function maybe_upgrade_database() {
        // Only check on admin/AJAX/cron requests — no need on frontend page loads
        if (!is_admin() && !wp_doing_cron() && !wp_doing_ajax()) {
            return;
        }

        $db_version = get_option('wc_mss_db_version', '0');
        $current_version = '1.9'; // Increment this when adding new tables or columns

        if (version_compare($db_version, $current_version, '<')) {
            // Create any missing tables and upgrade existing ones
            WC_Multi_Store_Sync_History::create_table();
            WC_Multi_Store_Queue_Table::create_table(); // Now also adds product_sku and extra_data columns
            WC_Multi_Store_API_Usage_Tracker::create_table();
            WC_Multi_Store_Stock_Verifier::create_table();
            WC_Multi_Store_Deletion_Audit::create_table();
            WC_Multi_Store_Remote_Order_Table::create_table();
            WC_Multi_Store_Weekly_Sync_Verifier::create_table();
            WC_Multi_Store_Webhook_Logger::create_table();
            WC_Multi_Store_Dead_Letter_Queue::create_table();

            update_option('wc_mss_db_version', $current_version);
            WC_Multi_Store_Logger::write('Database upgraded to version ' . $current_version);
        }
    }

    /**
     * Clean up duplicate scheduled actions if flagged
     * Runs only once after plugin activation (not on every page load)
     */
    public function maybe_cleanup_scheduled_actions() {
        // Check if cleanup is needed (flag set during activation)
        if (!get_option('wc_mss_needs_action_cleanup')) {
            return;
        }

        // Make sure Action Scheduler is available
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return;
        }

        // Perform cleanup
        $result = WC_Multi_Store_Action_Scheduler_Manager::cleanup_duplicate_actions();

        // Clear the flag so this doesn't run again
        delete_option('wc_mss_needs_action_cleanup');

        if ($result['cleaned'] > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Post-activation cleanup: Removed %d duplicate scheduled action(s)',
                $result['cleaned']
            ));
        }
    }

    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, $this->activate(...));
        register_deactivation_hook(__FILE__, $this->deactivate(...));

        // Plugin loaded
        add_action('plugins_loaded', $this->load_textdomain(...));

        // Add settings link on plugins page
        add_filter('plugin_action_links_' . WC_MSS_PLUGIN_BASENAME, $this->plugin_action_links(...));

        // History archival: independent recurring job, decoupled from logging
        add_action('init', WC_Multi_Store_Logger::schedule_archival(...));
        add_action('wc_mss_archive_history', WC_Multi_Store_Logger::archive_database_history(...));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create default options
        $default_settings = [
            'enabled' => false,
            'sync_type_default' => 'full_product',
            'auth_method' => 'query_string',
            'match_products_by' => 'sku',
            'stock_sync_enabled' => true,
            'auto_sync_on_save' => false,
        ];

        if (!get_option('wc_multi_store_sync_settings')) {
            add_option('wc_multi_store_sync_settings', $default_settings);
        }

        if (!get_option('wc_multi_store_sync_stores')) {
            add_option('wc_multi_store_sync_stores', []);
        }

        // Phase 2: Scheduled sync settings
        $default_scheduled = [
            'enabled' => false,
            'peak_start_hour' => 6,
            'peak_end_hour' => 23,
            'batch_size_peak' => 5,
            'batch_size_offpeak' => 20,
            'force_full_sync' => false,
            'sync_all_products' => false,
            'sync_modified_hours' => 24,
        ];

        if (!get_option('wc_multi_store_sync_scheduled')) {
            add_option('wc_multi_store_sync_scheduled', $default_scheduled);
        }

        // Phase 2: Order sync settings
        $default_orders = [
            'auto_sync_enabled' => false,
            'sync_on_new' => false,
            'sync_statuses' => ['processing', 'completed'],
            'debounce_timeout' => 30,
        ];

        if (!get_option('wc_multi_store_sync_orders')) {
            add_option('wc_multi_store_sync_orders', $default_orders);
        }

        // Webhook settings for order-based stock sync
        $default_webhook = [
            'enabled' => false,
            'webhook_secret' => wp_generate_password(32, false),
            'trigger_statuses' => ['processing', 'completed'],
            'allow_negative_stock' => false,
            'auto_verify' => true,
        ];

        if (!get_option('wc_multi_store_sync_webhook_settings')) {
            add_option('wc_multi_store_sync_webhook_settings', $default_webhook);
        }

        // P1 Features: Email notification settings
        $default_email = [
            'enabled' => false,
            'recipient_email' => get_option('admin_email'),
            'failed_sync_enabled' => true,
            'daily_summary_enabled' => false,
            'low_stock_enabled' => false,
            'low_stock_threshold' => 10,
            'api_error_enabled' => true,
            'daily_summary_time' => '08:00',
        ];

        if (!get_option('wc_multi_store_sync_email_settings')) {
            add_option('wc_multi_store_sync_email_settings', $default_email);
        }

        // Create logs directory
        $logs_dir = WC_MSS_PLUGIN_DIR . 'assets/logs';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
            // Add .htaccess to protect logs
            file_put_contents($logs_dir . '/.htaccess', 'Deny from all');
        }

        // Phase 3: Create sync history table
        WC_Multi_Store_Sync_History::create_table();

        // Create queue table
        WC_Multi_Store_Queue_Table::create_table();

        // Create stock discrepancies table
        WC_Multi_Store_Stock_Verifier::create_table();

        // Phase 5: Create deletion audit table
        WC_Multi_Store_Deletion_Audit::create_table();

        // P1 Features: Create API usage table
        WC_Multi_Store_API_Usage_Tracker::create_table();

        // Remote Order Sync: Create tables
        WC_Multi_Store_Remote_Order_Table::create_table();

        // Weekly Verification: Create table
        WC_Multi_Store_Weekly_Sync_Verifier::create_table();

        // Webhook Logger: Create table
        WC_Multi_Store_Webhook_Logger::create_table();

        // Dead Letter Queue: Create table
        WC_Multi_Store_Dead_Letter_Queue::create_table();

        // Conflict Detector: Create tables (migrates from wp_options if needed)
        WC_Multi_Store_Conflict_Detector::create_table();

        // Weekly Verification: Default settings
        $default_weekly_verification = [
            'enabled' => false,
            'schedule' => 'weekly',
            'day_of_week' => 1, // Monday
            'time_of_day' => '02:00',
            'check_stock' => true,
            'check_prices' => true,
            'product_limit' => 0,
            'sample_mode' => 'all',
            'auto_correct' => false,
            'email_enabled' => false,
            'email_recipients' => get_option('admin_email'),
        ];

        if (!get_option('wc_multi_store_sync_weekly_verification')) {
            add_option('wc_multi_store_sync_weekly_verification', $default_weekly_verification);
        }

        // New features: Default settings
        if (!get_option('wc_mss_conflict_settings')) {
            add_option('wc_mss_conflict_settings', [
                'enabled' => false,
                'action_on_conflict' => 'warn',
                'notify_email' => true,
            ]);
        }

        if (!get_option('wc_multi_store_sync_coupon_settings')) {
            add_option('wc_multi_store_sync_coupon_settings', [
                'enabled' => false,
                'auto_sync_on_save' => true,
                'auto_sync_deletions' => true,
            ]);
        }

        if (!get_option('wc_mss_attribute_remapping_settings')) {
            add_option('wc_mss_attribute_remapping_settings', [
                'enabled' => false,
            ]);
        }

        if (!get_option('wc_mss_shipping_class_sync_settings')) {
            add_option('wc_mss_shipping_class_sync_settings', [
                'enabled' => false,
                'auto_sync_on_change' => true,
            ]);
        }

        if (!get_option('wc_mss_downloadable_files_sync_settings')) {
            add_option('wc_mss_downloadable_files_sync_settings', [
                'enabled' => false,
                'transfer_mode' => 'url',
            ]);
        }

        if (!get_option('wc_mss_category_mapper_settings')) {
            add_option('wc_mss_category_mapper_settings', [
                'enabled' => false,
            ]);
        }

        // Phase 4: Clear cache on activation
        WC_Multi_Store_Cache_Manager::clear_all();

        // Mark that we need to clean up scheduled actions (Action Scheduler may not be ready yet)
        update_option('wc_mss_needs_action_cleanup', true, false);

        // Log activation
        WC_Multi_Store_Logger::write('Plugin activated - Version ' . WC_MSS_VERSION);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear all scheduled actions
        WC_Multi_Store_Action_Scheduler_Manager::unschedule_all();

        // Clear health check schedule
        WC_Multi_Store_Health_Check::unschedule_health_check();

        // Clear weekly verification schedule
        WC_Multi_Store_Weekly_Sync_Verifier::unschedule_verification();

        // Clear remote order sync schedule
        WC_Multi_Store_Remote_Order_Sync::unschedule_sync();

        // Log deactivation
        WC_Multi_Store_Logger::write('Plugin deactivated - scheduled events cleared');
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-multi-store-sync', false, dirname(WC_MSS_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Add WooCommerce settings tab
     *
     * @param array $settings
     * @return array
     */
    public function add_woocommerce_settings_tab($settings) {
        $settings[] = include WC_MSS_PLUGIN_DIR . 'includes/wc-settings-integration.php';
        return $settings;
    }

    /**
     * Add settings link to plugins page
     *
     * @param array $links
     * @return array
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=multi_store_sync') . '">' . __('Settings', 'wc-multi-store-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version() {
        return WC_MSS_VERSION;
    }

}

/**
 * Returns the main instance of WC_Multi_Store_Sync
 *
 * @return WC_Multi_Store_Sync
 */
function WC_MSS() {
    return WC_Multi_Store_Sync::instance();
}

// Initialize the plugin
WC_MSS();
