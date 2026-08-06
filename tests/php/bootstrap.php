<?php
/**
 * PHPUnit Bootstrap File
 *
 * Sets up the testing environment for unit tests.
 * Uses Brain Monkey to mock WordPress functions.
 *
 * @requires PHP 8.3
 */

// Composer autoloader
$autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!file_exists($autoloader)) {
    echo "Please run 'composer install' before running tests.\n";
    exit(1);
}

require_once $autoloader;

// Define plugin version constant
if (!defined('WC_MSS_VERSION')) {
    define('WC_MSS_VERSION', '3.1.0');
}

// Define WordPress constants needed by the plugin
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

// Time constants
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

// Database constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// Use Brain Monkey namespace
use Brain\Monkey;

/**
 * Mock WP_Error class
 */
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(
            private readonly string $code = '',
            private readonly string $message = '',
            private readonly mixed $data = '',
        ) {}

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(): mixed {
            return $this->data;
        }
    }
}

/**
 * Mock is_wp_error function
 */
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool {
        return $thing instanceof WP_Error;
    }
}

/**
 * Base test case with Brain Monkey setup
 */
abstract class WC_Multi_Store_TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->setUpWpMocks();
    }

    protected function tearDown(): void
    {
        // Clear Logger singleton buffer before mocks are torn down.
        // Prevents fatal errors in Logger::__destruct() when Brain Monkey
        // functions are no longer available.
        if (class_exists('WC_Multi_Store_Logger', false)) {
            $ref = new \ReflectionClass('WC_Multi_Store_Logger');
            $prop = $ref->getProperty('instance');
            $inst = $prop->getValue();
            if ($inst) {
                $buf = $ref->getProperty('buffer');
                $buf->setValue($inst, []);
            }
            // Note: do NOT call reset_instance() here. The singleton persists
            // with valid settings from bootstrap. Clearing the buffer is enough.
            // Resetting would force re-creation in the next test, which calls
            // get_option() in the constructor — most tests don't mock that.
        }

        // Clear API Client rate limiter timestamps — after ~20 API tests
        // the rate limit triggers logging which calls Logger without mocks.
        if (class_exists('WC_Multi_Store_API_Client', false)) {
            $ref = new \ReflectionClass('WC_Multi_Store_API_Client');
            $prop = $ref->getProperty('request_timestamps');
            $prop->setValue(null, []);
        }

        // Clear Hooks cached settings so stale settings don't leak.
        if (class_exists('WC_Multi_Store_Hooks', false)) {
            WC_Multi_Store_Hooks::clear_settings_cache();
        }

        // Clear Settings static cache so stale option values don't leak into next test.
        if (class_exists('WC_Multi_Store_Settings', false)) {
            WC_Multi_Store_Settings::clear_static_cache();
        }

        // Reset WebhookReceiver processing flag — direct reflection calls
        // in tests can bypass the finally{} reset in deduct_stock().
        if (class_exists('WC_Multi_Store_Webhook_Receiver', false)) {
            WC_Multi_Store_Webhook_Receiver::$is_processing_webhook = false;
        }

        Monkey\tearDown();
        \Mockery::close();

        // Restore global $wpdb to a clean instance.
        // Tests that mock $wpdb with Mockery leave a closed mock object
        // in the global scope, causing "no expectations specified" errors
        // in subsequent test classes.
        global $wpdb;
        $wpdb = new wpdb();

        parent::tearDown();
    }

    /**
     * Set up common WordPress function mocks
     */
    protected function setUpWpMocks(): void
    {
        Monkey\Functions\when('sanitize_key')->alias(
            fn($key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key))
        );

        Monkey\Functions\when('sanitize_text_field')->alias(
            fn($str) => trim(strip_tags($str))
        );

        Monkey\Functions\when('sanitize_email')->alias(
            fn($email) => is_string($email) ? trim($email) : ''
        );

        Monkey\Functions\when('wp_parse_args')->alias(function ($args, $defaults = []) {
            if (is_object($args)) {
                $args = get_object_vars($args);
            }
            if (!is_array($args)) {
                $args = [];
            }
            return array_merge($defaults, $args);
        });

        Monkey\Functions\when('esc_html__')->alias(
            fn($text, $domain = 'default') => htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        );

        Monkey\Functions\when('__')->alias(
            fn($text, $domain = 'default') => $text
        );

        Monkey\Functions\when('esc_attr')->alias(
            fn($text) => htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        );

        Monkey\Functions\when('apply_filters')->alias(
            fn($tag, $value) => $value
        );

        // Logger needs wp_upload_dir when any class calls Logger::write().
        // Create a real temp dir so Logger's constructor skips file_put_contents.
        $logDir = sys_get_temp_dir() . '/wc-mss-tests/wc-mss-logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        Monkey\Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir() . '/wc-mss-tests',
            'baseurl' => 'http://test.com/uploads',
        ]);
        Monkey\Functions\when('wp_mkdir_p')->justReturn(true);

        // WP cache-priming helpers used by product-extractor and weekly-sync-verifier.
        // Tests that need specific behaviour can override these with their own stubs.
        Monkey\Functions\when('_prime_post_caches')->justReturn(null);
        Monkey\Functions\when('get_posts')->justReturn([]);
        Monkey\Functions\when('update_meta_cache')->justReturn(null);

        // Default stub — returns empty array. Tests that verify category/tag exclusion
        // override this per-test via Brain Monkey's Functions\when('wp_get_post_terms').
        Monkey\Functions\when('wp_get_post_terms')->justReturn([]);
    }
}

/**
 * Mock wpdb class for database operations
 */
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $termmeta = 'wp_termmeta';
        public string $term_relationships = 'wp_term_relationships';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public int $num_queries = 0;
        public int $insert_id = 0;
        public string $last_error = '';

        public function prepare(string $query, mixed ...$args): string {
            if (isset($args[0]) && is_array($args[0])) {
                $args = $args[0];
            }

            foreach ($args as $arg) {
                $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
            }

            $query = preg_replace('/%d/', '0', $query);

            return $query;
        }

        public function get_results(string $query, string $output = OBJECT): array {
            return [];
        }

        public function get_var(string $query): mixed {
            return null;
        }

        public function get_col(string $query): array {
            return [];
        }

        public function get_row(string $query, string $output = OBJECT): mixed {
            return null;
        }

        public function query(string $query): int|false {
            return 1;
        }

        public function insert(string $table, array $data, ?array $format = null): int|false {
            return 1;
        }

        public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false {
            return 1;
        }

        public function delete(string $table, array $where, ?array $where_format = null): int|false {
            return 1;
        }

        public function esc_like(string $text): string {
            return addcslashes($text, '_%\\');
        }
    }
}

// Initialize global $wpdb
global $wpdb;
if (!isset($wpdb)) {
    $wpdb = new wpdb();
}

/**
 * Shared WC_Multi_Store_Sync stub + WC_MSS() global.
 * Defined once here so individual test files don't conflict.
 */
if (!class_exists('WC_Multi_Store_Sync')) {
    class WC_Multi_Store_Sync {
        private static ?self $instance = null;
        public $sync_engine = null;
        public $api_client = null;
        public WC_Multi_Store_Queue_Manager $queue_manager;

        public function __construct() {
            $this->queue_manager = new WC_Multi_Store_Queue_Manager();
        }

        public static function instance(): self {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public static function reset_instance(): void {
            self::$instance = null;
        }
    }
}

if (!function_exists('WC_MSS')) {
    function WC_MSS(): WC_Multi_Store_Sync {
        return WC_Multi_Store_Sync::instance();
    }
}

// Include the plugin files we want to test (without WordPress hooks)
$plugin_root = dirname(__DIR__, 2);

// Define plugin constants
if (!defined('WC_MSS_PLUGIN_DIR')) {
    define('WC_MSS_PLUGIN_DIR', $plugin_root . '/');
}

// Load classes that don't have WordPress dependencies first
require_once $plugin_root . '/includes/pricing-rule-type.php';
require_once $plugin_root . '/includes/pricing-rules.php';
require_once $plugin_root . '/includes/stock-allocation-type.php';
require_once $plugin_root . '/includes/stock-allocator.php';
require_once $plugin_root . '/includes/product-exclusion-filter.php';
require_once $plugin_root . '/includes/cache-manager.php';
require_once $plugin_root . '/includes/time-manager.php';
require_once $plugin_root . '/includes/custom-field-mapper.php';
require_once $plugin_root . '/includes/settings.php';
require_once $plugin_root . '/includes/logger.php';
require_once $plugin_root . '/includes/product-transformer.php';
require_once $plugin_root . '/includes/product-extractor.php';
require_once $plugin_root . '/includes/api-client.php';
require_once $plugin_root . '/includes/queue-manager.php';
require_once $plugin_root . '/includes/circuit-breaker.php';
require_once $plugin_root . '/includes/queue-table.php';
require_once $plugin_root . '/includes/sync-history.php';
require_once $plugin_root . '/includes/csv-sanitize-trait.php';
require_once $plugin_root . '/includes/archive-before-delete-trait.php';
require_once $plugin_root . '/includes/webhook-log-type.php';
require_once $plugin_root . '/includes/webhook-logger.php';
require_once $plugin_root . '/includes/webhook-receiver.php';
require_once $plugin_root . '/includes/remote-product-manager.php';
require_once $plugin_root . '/includes/variation-synchronizer.php';
require_once $plugin_root . '/includes/stock-update-tracker.php';
require_once $plugin_root . '/includes/stock-verifier.php';
require_once $plugin_root . '/includes/sync-engine.php';
require_once $plugin_root . '/includes/performance-monitor.php';
require_once $plugin_root . '/includes/api-usage-tracker.php';
require_once $plugin_root . '/includes/image-proxy.php';
require_once $plugin_root . '/includes/action-scheduler-manager.php';
require_once $plugin_root . '/includes/deletion-audit.php';
require_once $plugin_root . '/includes/email-notifications.php';
require_once $plugin_root . '/includes/category-deletion-sync.php';
require_once $plugin_root . '/includes/remote-order-sync.php';
require_once $plugin_root . '/includes/weekly-sync-verifier.php';
require_once $plugin_root . '/includes/hooks.php';
require_once $plugin_root . '/includes/remote-order-table.php';
require_once $plugin_root . '/includes/sync-previewer.php';
require_once $plugin_root . '/includes/dead-letter-queue.php';
require_once $plugin_root . '/includes/config-manager.php';
require_once $plugin_root . '/includes/dashboard-widget.php';
require_once $plugin_root . '/includes/toggleable-feature-trait.php';
require_once $plugin_root . '/includes/ajax-auth-guard-trait.php';
require_once $plugin_root . '/includes/shipping-class-sync.php';
require_once $plugin_root . '/includes/coupon-sync.php';
require_once $plugin_root . '/includes/downloadable-files-sync.php';
require_once $plugin_root . '/includes/category-mapper.php';
require_once $plugin_root . '/includes/attribute-remapper.php';
require_once $plugin_root . '/includes/sync-profiles.php';
require_once $plugin_root . '/includes/conflict-detector.php';

// WP_CLI_Command stub must be defined before loading cli-commands.php
if (!class_exists('WP_CLI_Command')) {
    class WP_CLI_Command {}
}
require_once $plugin_root . '/includes/cli-commands.php';

// Note: order-sync.php is NOT loaded here because it has file-level add_action().
// Tests that need it load it in their own setUp() after Brain Monkey is initialized.
// Note: store-health-check.php is NOT loaded here because it auto-instantiates
// with `new WC_Multi_Store_Health_Check()` which calls add_action before Brain Monkey.
// Tests that need it load it in their own setUp() after Brain Monkey is initialized.
// Note: product-edit.php, remote-order-admin.php, remote-order-list-table.php,
// wc-settings-integration.php are NOT loaded here because they have constructors
// with add_action() or extend WP classes. Tests load them after Brain Monkey setup.

/**
 * Stub WooCommerce product classes for type-hinted mocks
 */
if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_id(): int { return 0; }
        public function get_sku(): string { return ''; }
        public function get_slug(): string { return ''; }
        public function get_name(): string { return ''; }
        public function get_type(): string { return 'simple'; }
        public function is_type(string $type): bool { return $this->get_type() === $type; }
        public function get_children(): array { return []; }
        public function get_image_id(): int { return 0; }
        public function get_status(): string { return 'publish'; }
        public function get_price(): string { return '0'; }
        public function get_regular_price(): string { return '0'; }
        public function get_sale_price(): string { return ''; }
        public function get_stock_quantity(): ?int { return null; }
        public function get_stock_status(): string { return 'instock'; }
        public function get_manage_stock(): bool { return false; }
        public function get_category_ids(): array { return []; }
        public function get_tag_ids(): array { return []; }
        public function get_gallery_image_ids(): array { return []; }
        public function get_description(): string { return ''; }
        public function get_short_description(): string { return ''; }
        public function get_weight(): string { return ''; }
        public function get_length(): string { return ''; }
        public function get_width(): string { return ''; }
        public function get_height(): string { return ''; }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_id(): int { return 0; }
        public function get_items(): array { return []; }
        public function get_status(): string { return 'pending'; }
    }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        public function get_product_id(): int { return 0; }
        public function get_variation_id(): int { return 0; }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query {
        /** @var array<int,array<int,mixed>>|null Queue of results for successive WP_Query instantiations; null = default empty behavior. */
        public static ?array $resultsQueue = null;
        public array $posts = [];
        public function __construct(array $args = []) {
            if (self::$resultsQueue !== null) {
                $this->posts = array_shift(self::$resultsQueue) ?? [];
            }
        }
    }
}

if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product {
        public function get_type(): string { return 'variation'; }
    }
}

/**
 * Mock WP_Post class
 */
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';

        public function __construct(array $data = []) {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

/**
 * Mock WP_List_Table class
 */
if (!class_exists('WP_List_Table')) {
    class WP_List_Table {
        public array $_args = [];
        public $items = [];
        public $_column_headers = [];

        public function __construct(array $args = []) {
            $this->_args = wp_parse_args($args, [
                'singular' => '',
                'plural' => '',
                'ajax' => false,
            ]);
        }

        public function get_columns(): array { return []; }
        protected function get_sortable_columns(): array { return []; }
        protected function get_bulk_actions(): array { return []; }
        protected function column_cb($item): string { return ''; }
        public function get_items_per_page(string $option, int $default = 20): int { return $default; }
        public function get_pagenum(): int { return 1; }
        public function set_pagination_args(array $args): void {}
        protected function row_actions(array $actions, bool $always_visible = false): string {
            return implode(' | ', $actions);
        }
        public function display(): void {}
        public function prepare_items(): void {}
        public function search_box(string $text, string $input_id): void {}
        public function views(): void {}
        protected function extra_tablenav(string $which): void {}
        public function no_items(): void {}
    }
}

/**
 * Mock WC_Settings_Page class
 */
if (!class_exists('WC_Settings_Page')) {
    class WC_Settings_Page {
        protected string $id = '';
        protected string $label = '';

        public function __construct() {}
        public function get_sections(): array { return []; }
        public function output(): void {}
        public function save(): void {}
        public function get_settings($current_section = ''): array { return []; }
    }
}

/**
 * Mock WC_Admin_Settings class
 */
if (!class_exists('WC_Admin_Settings')) {
    class WC_Admin_Settings {
        private static array $messages = [];
        private static array $errors = [];

        public static function add_message(string $text): void { self::$messages[] = $text; }
        public static function add_error(string $text): void { self::$errors[] = $text; }
        public static function get_messages(): array { return self::$messages; }
        public static function get_errors(): array { return self::$errors; }
        public static function reset(): void { self::$messages = []; self::$errors = []; }
    }
}

/**
 * Mock WP_REST_Response class
 */
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public function __construct(
            private readonly mixed $data = null,
            private readonly int $status = 200,
        ) {}

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

/**
 * Mock WP_REST_Request class
 */
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params = [];
        private array $headers = [];
        private string $body = '';

        public function get_param(string $key): mixed {
            return $this->params[$key] ?? null;
        }

        public function set_param(string $key, mixed $value): void {
            $this->params[$key] = $value;
        }

        public function get_header(string $key): ?string {
            $key = strtolower($key);
            return $this->headers[$key] ?? null;
        }

        public function set_header(string $key, string $value): void {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_body(): string {
            return $this->body;
        }

        public function set_body(string $body): void {
            $this->body = $body;
        }

        public function get_json_params(): array {
            return json_decode($this->body, true) ?: [];
        }
    }
}

// Stub WordPress object cache functions not covered by Brain Monkey.
// Only stub functions that are never mocked via Brain Monkey's when().
// wp_cache_get/set/delete/flush are mocked per-test, so must not be pre-defined here.
//
// wp_cache_add/wp_cache_incr behaviour is steered via globals because Patchwork
// can't redefine a function once it's been included. By default incr returns
// false to mirror a non-persistent object cache (forcing fallback paths in
// production code). Tests that exercise the persistent path do:
//   $GLOBALS['wc_mss_test_wp_cache_incr_return'] = 7;
// and reset to null on teardown.
if (!function_exists('wp_cache_add')) {
    function wp_cache_add(string $key, mixed $data, string $group = '', int $expire = 0): bool {
        return true;
    }
}
if (!function_exists('wp_cache_incr')) {
    function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false {
        $override = $GLOBALS['wc_mss_test_wp_cache_incr_return'] ?? null;
        if ($override === null) {
            return false;
        }
        if (is_callable($override)) {
            return $override($key, $offset, $group);
        }
        return $override;
    }
}
// Default to "no persistent object cache" so the rate limiter exercises its
// transient fallback path under tests. Tests that want the object-cache path
// flip $GLOBALS['wc_mss_test_use_object_cache'] = true and reset it on
// teardown — Brain Monkey's when() can't override this because Patchwork
// requires the override to be registered before the function is defined.
if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache(): bool {
        return !empty($GLOBALS['wc_mss_test_use_object_cache']);
    }
}

// Default to "no ancestors" so ancestor-expansion is a no-op unless a test opts in.
// Like wp_cache_incr above, Patchwork can't redefine this once included, so tests
// that verify ancestor-based exclusion steer it via a global instead of when():
//   $GLOBALS['wc_mss_test_get_ancestors'] = [30 => [20, 10]];
// and reset to null on teardown.
if (!function_exists('get_ancestors')) {
    function get_ancestors(int $object_id, string $object_type = '', string $resource_type = ''): array {
        return $GLOBALS['wc_mss_test_get_ancestors'][$object_id] ?? [];
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed {
        return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
    }
}

if (!function_exists('esc_sql')) {
    function esc_sql(mixed $data): mixed {
        global $wpdb;
        return is_array($data) ? array_map('esc_sql', $data) : addslashes((string) $data);
    }
}

if (!function_exists('wp_convert_hr_to_bytes')) {
    function wp_convert_hr_to_bytes(string $size): int {
        $size  = strtolower(trim($size));
        $bytes = (int) $size;
        $unit  = substr($size, -1);
        return match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}

