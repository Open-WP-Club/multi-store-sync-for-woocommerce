<?php
/**
 * Hooks Class
 * Integrates with WooCommerce hooks for automatic syncing
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Hooks {

    /**
     * Cached settings to avoid repeated database calls
     *
     * @var array|null
     */
    private static $cached_settings = null;

    /**
     * Store old stock values before changes
     *
     * @var array
     */
    private static $old_stock_values = [];

    /**
     * Pre-deletion product data cache (SKU/terms captured before the row is removed)
     * Key: product_id, Value: array with 'sku', 'categories', 'tags'
     *
     * @var array
     */
    private static $pre_deletion_cache = [];

    /**
     * Initialize hooks
     */
    public function __construct() {
        // Load settings once at initialization
        self::load_settings();
        // Product hooks
        add_action('woocommerce_update_product', $this->on_product_save(...), 10, 1);
        add_action('woocommerce_new_product', $this->on_new_product(...), 10, 1);

        // Variation hooks
        add_action('woocommerce_update_product_variation', $this->on_variation_save(...), 10, 1);
        add_action('woocommerce_new_product_variation', $this->on_variation_save(...), 10, 1);

        // Capture old stock before change
        add_action('woocommerce_before_product_object_save', $this->capture_old_stock(...), 10, 1);

        // Stock change hooks
        add_action('woocommerce_product_set_stock', $this->on_stock_change(...), 10, 1);
        add_action('woocommerce_variation_set_stock', $this->on_stock_change(...), 10, 1);

        // Price change hooks (for quick syncs)
        add_action('woocommerce_product_set_price', $this->on_price_change(...), 10, 1);

        // Capture product data before permanent deletion (post is still in DB at this point)
        add_action('before_delete_post', $this->on_before_delete_post(...), 10, 1);

        // Product deletion hook
        add_action('woocommerce_delete_product', $this->on_product_delete(...), 10, 1);

        // Product restoration hook
        add_action('woocommerce_untrashed_post', $this->on_product_restore(...), 10, 1);

        // Variation deletion hook
        add_action('woocommerce_delete_product_variation', $this->on_variation_delete(...), 10, 1);

        // Product status change hook
        add_action('transition_post_status', $this->on_product_status_change(...), 10, 3);

        // Weekly verification hook
        add_action('wc_multi_store_weekly_verification', $this->on_weekly_verification(...));

        // Async verification batch hook (Action Scheduler)
        add_action('wc_mss_async_verification_batch', $this->on_async_verification_batch(...), 10, 1);
    }

    /**
     * Load settings from database and cache them
     * Uses centralized WC_Multi_Store_Settings which has static + transient cache
     */
    private static function load_settings(): void {
        if (self::$cached_settings === null) {
            self::$cached_settings = WC_Multi_Store_Settings::get_settings();
        }
    }

    /**
     * Get cached settings (loads if not already cached)
     *
     * @return array Settings array
     */
    private static function get_settings(): array {
        self::load_settings();
        return self::$cached_settings;
    }

    /**
     * Get a specific setting value with default fallback
     *
     * @param string $key Setting key
     * @param mixed $default Default value if not set
     * @return mixed Setting value
     */
    private static function get_setting($key, $default = false): mixed {
        $settings = self::get_settings();
        return $settings[$key] ?? $default;
    }

    /**
     * Clear cached settings (useful after settings update)
     */
    public static function clear_settings_cache(): void {
        self::$cached_settings = null;
    }

    /**
     * Handle product save
     *
     * @param int $product_id Product ID
     */
    public function on_product_save($product_id): void {
        // Check if auto-sync is enabled
        if (!self::get_setting('auto_sync_on_save', false)) {
            return;
        }

        // Don't re-sync during webhook processing — webhook receiver calls
        // $product->save() which fires this hook, creating an infinite loop
        if ((defined('WC_MSS_WEBHOOK_PROCESSING') && WC_MSS_WEBHOOK_PROCESSING)
            || WC_Multi_Store_Webhook_Receiver::$is_processing_webhook) {
            return;
        }

        // Don't sync during bulk operations
        if (defined('DOING_BULK_EDIT') && DOING_BULK_EDIT) {
            return;
        }

        // Don't sync during imports
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }

        // Only sync published products - drafts/private/pending don't need to be on remote stores
        if (get_post_status($product_id) !== 'publish') {
            return;
        }

        // Queue product with normal priority - don't load product object, just queue the ID
        // The queue processor will load product data when it runs
        WC_MSS()->queue_manager->add_product(
            $product_id,
            'product_save',
            WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL
        );
    }

    /**
     * Handle new product creation
     *
     * @param int $product_id Product ID
     */
    public function on_new_product($product_id): void {
        // Check if auto-sync for new products is enabled
        if (!self::get_setting('auto_sync_new_products', false)) {
            return;
        }

        // Don't re-sync during webhook processing
        if ((defined('WC_MSS_WEBHOOK_PROCESSING') && WC_MSS_WEBHOOK_PROCESSING)
            || WC_Multi_Store_Webhook_Receiver::$is_processing_webhook) {
            return;
        }

        // Don't sync during bulk operations
        if (defined('DOING_BULK_EDIT') && DOING_BULK_EDIT) {
            return;
        }

        // Don't sync during imports
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }

        // Only sync published products - drafts/private/pending don't need to be on remote stores
        if (get_post_status($product_id) !== 'publish') {
            return;
        }

        // Queue product with high priority (new products should sync quickly)
        // Don't load product object - just queue the ID
        WC_MSS()->queue_manager->add_product(
            $product_id,
            'new_product',
            WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
        );
    }

    /**
     * Handle variation save
     *
     * @param int $variation_id Variation ID
     */
    public function on_variation_save($variation_id): void {
        // Check if auto-sync is enabled
        if (!self::get_setting('auto_sync_on_save', false)) {
            return;
        }

        // Don't re-sync during webhook processing
        if ((defined('WC_MSS_WEBHOOK_PROCESSING') && WC_MSS_WEBHOOK_PROCESSING)
            || WC_Multi_Store_Webhook_Receiver::$is_processing_webhook) {
            return;
        }

        // Don't sync during bulk operations
        if (defined('DOING_BULK_EDIT') && DOING_BULK_EDIT) {
            return;
        }

        // Only sync variations of published products
        $parent_id = wp_get_post_parent_id($variation_id);
        if (!$parent_id || get_post_status($parent_id) !== 'publish') {
            return;
        }

        // Queue the PARENT product, not the variation itself.
        // Variations must be synced through their parent's variation sync flow,
        // otherwise they would be created as standalone simple products on the remote store.
        WC_MSS()->queue_manager->add_product(
            $parent_id,
            'variation_save',
            WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL
        );
    }

    /**
     * Capture old stock value before product is saved
     *
     * @param WC_Product $product Product object
     */
    public function capture_old_stock($product): void {
        if (!$product || !$product->get_id()) {
            return;
        }

        $product_id = $product->get_id();

        // Get current stock from database (before save)
        $current_stock = get_post_meta($product_id, '_stock', true);

        // Store it for later use
        self::$old_stock_values[$product_id] = $current_stock !== '' ? (int) $current_stock : null;
    }

    /**
     * Handle stock change
     *
     * @param WC_Product $product Product object
     */
    public function on_stock_change($product): void {
        // Skip if this is triggered by webhook processing
        // The webhook receiver will queue the product itself
        if ((defined('WC_MSS_WEBHOOK_PROCESSING') && WC_MSS_WEBHOOK_PROCESSING)
            || WC_Multi_Store_Webhook_Receiver::$is_processing_webhook) {
            return;
        }

        // Check if stock sync is enabled
        if (!self::get_setting('stock_sync_enabled', true)) {
            return;
        }

        // Only sync stock for published products
        if ($product->get_status() !== 'publish') {
            return;
        }

        $product_id = $product->get_id();
        $new_stock = $product->get_stock_quantity();

        // Get old stock value (captured before save)
        $old_stock = self::$old_stock_values[$product_id] ?? '?';

        // Clear the stored value
        unset(self::$old_stock_values[$product_id]);

        $sku = $product->get_sku();

        WC_Multi_Store_Logger::write(sprintf(
            'Stock changed: SKU %s, %s → %s (auto-queuing for sync)',
            $sku ?: 'no-sku',
            $old_stock !== null ? $old_stock : 'null',
            $new_stock !== null ? $new_stock : 'null'
        ));

        // Queue product with critical priority (stock is critical)
        WC_MSS()->queue_manager->add_product(
            $product_id,
            'stock_change',
            WC_Multi_Store_Queue_Manager::PRIORITY_CRITICAL
        );
    }

    /**
     * Handle price change
     *
     * @param WC_Product $product Product object
     */
    public function on_price_change($product): void {
        // Check if auto-sync is enabled
        if (!self::get_setting('auto_sync_on_save', false)) {
            return;
        }

        // Only sync prices for published products
        if ($product->get_status() !== 'publish') {
            return;
        }

        $product_id = $product->get_id();
        $sku = $product->get_sku();

        WC_Multi_Store_Logger::write(sprintf(
            'Price changed: SKU %s (auto-queuing for sync)',
            $sku ?: 'no-sku'
        ));

        // Queue product with high priority
        WC_MSS()->queue_manager->add_product(
            $product_id,
            'price_change',
            WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
        );
    }

    /**
     * Capture product SKU and terms before permanent deletion.
     * WordPress fires before_delete_post while the row still exists in wp_posts,
     * so we can still query the meta/terms here and store them for on_product_delete.
     *
     * @param int $post_id Post ID
     */
    public function on_before_delete_post($post_id): void {
        global $wpdb;

        // Only care about products being permanently deleted from trash
        $post_type = $wpdb->get_var($wpdb->prepare(
            "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d",
            $post_id
        ));

        if (!in_array($post_type, ['product', 'product_variation'], true)) {
            return;
        }

        // Fetch SKU (product may have no SKU — that's fine, use empty string)
        $sku = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_sku' LIMIT 1",
            $post_id
        ));

        // Fetch categories and tags via queue manager helper
        $terms_data = WC_MSS()->queue_manager->get_product_terms($post_id);

        self::$pre_deletion_cache[$post_id] = [
            'sku'        => $sku,
            'categories' => $terms_data['categories'] ?? [],
            'tags'       => $terms_data['tags'] ?? [],
        ];
    }

    /**
     * Handle product deletion
     *
     * @param int $product_id Product ID
     */
    public function on_product_delete($product_id): void {
        // Check if deletion sync is enabled
        if (!self::get_setting('auto_sync_deletions', false)) {
            return;
        }

        // Don't sync during bulk operations
        if (defined('DOING_BULK_EDIT') && DOING_BULK_EDIT) {
            return;
        }

        // Don't sync during imports
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }

        // Get stores to delete from (respecting selective deletion settings)
        $stores_to_delete_from = $this->get_stores_to_delete_from($product_id);

        if (empty($stores_to_delete_from)) {
            return;
        }

        // Create deletion audit log before product is removed from DB
        $audit_id = null;
        if (class_exists('WC_Multi_Store_Deletion_Audit')) {
            $audit_id = WC_Multi_Store_Deletion_Audit::log_deletion(
                $product_id,
                array_keys($stores_to_delete_from),
                'manual'
            );
        }

        // Use pre-cached SKU/terms if available (product row already deleted from DB at this point)
        $pre_cached = self::$pre_deletion_cache[$product_id] ?? null;
        unset(self::$pre_deletion_cache[$product_id]);

        // Queue product deletion - don't load product object
        // add_product_deletion uses optimized SQL queries to get SKU and terms
        $queued = WC_MSS()->queue_manager->add_product_deletion(
            $product_id,
            'product_delete',
            WC_Multi_Store_Queue_Manager::PRIORITY_HIGH,
            $stores_to_delete_from,
            null,
            $pre_cached
        );

        if (!$queued) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to queue product deletion for ID %d',
                $product_id
            ), 'error');

            // Mark audit as failed if queuing failed
            if ($audit_id && class_exists('WC_Multi_Store_Deletion_Audit')) {
                WC_Multi_Store_Deletion_Audit::update_status($audit_id, 'failed', 'Failed to queue deletion');
            }
        }
    }

    /**
     * Get stores to delete from for a product
     * Respects selective deletion settings
     *
     * @param int $product_id Product ID
     * @return array Array of store URLs to delete from
     */
    private function get_stores_to_delete_from($product_id): array {
        // Get all active stores
        $all_stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($all_stores)) {
            return [];
        }

        // Get selective deletion settings for this product
        $deletion_settings = get_post_meta($product_id, '_wc_mss_selective_deletion', true);
        $stores_map = get_post_meta($product_id, '_wc_mss_deletion_stores_map', true);

        // If no selective settings, return all stores
        if (!is_array($deletion_settings) || empty($deletion_settings)) {
            return $all_stores;
        }

        // Filter stores based on selective deletion settings
        $selected_stores = [];

        foreach ($all_stores as $store_url => $store_config) {
            $store_key = md5($store_url);

            // Check if this store is selected for deletion
            if (isset($deletion_settings[$store_key]) && $deletion_settings[$store_key] === true) {
                $selected_stores[$store_url] = $store_config;
            }
        }

        return $selected_stores;
    }

    /**
     * Handle product restoration
     *
     * @param int $post_id Post ID
     */
    public function on_product_restore($post_id): void {
        // Check if this is a product
        $post_type = get_post_type($post_id);
        if ($post_type !== 'product') {
            return;
        }

        // Check if restoration sync is enabled
        if (!self::get_setting('auto_sync_restorations', false)) {
            return;
        }

        // Don't sync during bulk operations
        if (defined('DOING_BULK_EDIT') && DOING_BULK_EDIT) {
            return;
        }

        // Don't sync during imports
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }

        // Queue product restoration - don't load product object
        WC_MSS()->queue_manager->add_product_restoration(
            $post_id,
            'product_restore',
            WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
        );
    }

    /**
     * Handle variation deletion
     *
     * @param int $variation_id Variation ID
     */
    public function on_variation_delete($variation_id): void {
        // Check if deletion sync is enabled (reuse the deletion setting)
        if (!self::get_setting('auto_sync_deletions', false)) {
            return;
        }

        // Don't sync during bulk operations
        if (defined('DOING_BULK_EDIT') && DOING_BULK_EDIT) {
            return;
        }

        // Don't sync during imports
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }

        // Queue variation deletion - don't load product object
        // add_variation_deletion will validate and get necessary data
        WC_MSS()->queue_manager->add_variation_deletion(
            $variation_id,
            'variation_delete',
            WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
        );
    }

    /**
     * Handle product status change
     *
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function on_product_status_change($new_status, $old_status, $post): void {
        // Only handle product post type
        if ($post->post_type !== 'product') {
            return;
        }

        // Skip if statuses are the same
        if ($new_status === $old_status) {
            return;
        }

        // Skip auto-draft transitions (new product creation, not a real status change)
        if (in_array($old_status, ['new', 'auto-draft'], true)) {
            return;
        }

        // Check if status sync is enabled
        if (!self::get_setting('auto_sync_status', false)) {
            return;
        }

        // Don't sync during bulk operations
        if (defined('DOING_BULK_EDIT') && DOING_BULK_EDIT) {
            return;
        }

        // Don't sync during imports
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }

        $product_id = $post->ID;

        // Handle status transitions - don't load product object, just queue
        if ($new_status === 'trash') {
            // Product moved to trash - same as deletion
            if (self::get_setting('auto_sync_deletions', false)) {
                WC_MSS()->queue_manager->add_product_deletion(
                    $product_id,
                    'status_change_trash',
                    WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
                );
            }
        } elseif ($old_status === 'trash' && $new_status === 'publish') {
            // Product restored from trash - same as restoration
            if (self::get_setting('auto_sync_restorations', false)) {
                WC_MSS()->queue_manager->add_product_restoration(
                    $product_id,
                    'status_change_restore',
                    WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
                );
            }
        } elseif ($new_status === 'draft' || $new_status === 'private' || $new_status === 'publish') {
            // Handle status change: draft ↔ published ↔ private
            WC_MSS()->queue_manager->add_product_status_change(
                $product_id,
                $old_status,
                $new_status,
                'status_change',
                WC_Multi_Store_Queue_Manager::PRIORITY_HIGH
            );
        }
    }

    /**
     * Handle weekly verification action
     */
    public function on_weekly_verification(): void {
        WC_Multi_Store_Logger::write('Weekly verification triggered via Action Scheduler');

        try {
            // Use the async (batched) path so each batch fits within Action Scheduler's
            // execution window. The old synchronous run_verification() timed out on stores
            // with many products because it made N×M API calls in a single request.
            $result = WC_Multi_Store_Weekly_Sync_Verifier::schedule_async_verification();

            if (isset($result['success']) && $result['success']) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Weekly verification scheduled: %s',
                    $result['message'] ?? ''
                ));
            } else {
                WC_Multi_Store_Logger::write(sprintf(
                    'Weekly verification could not start: %s',
                    $result['message'] ?? 'unknown reason'
                ), 'warning');
            }
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'Weekly verification failed with exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');
        }

        // Ensure the action is still scheduled for next week
        // (Action Scheduler should handle this, but we verify just in case)
        $next_scheduled = WC_Multi_Store_Weekly_Sync_Verifier::get_next_scheduled_time();
        if (!$next_scheduled) {
            WC_Multi_Store_Logger::write('No next verification scheduled, rescheduling...', 'warning');
            WC_Multi_Store_Weekly_Sync_Verifier::schedule_verification();
        }
    }

    /**
     * Handle async verification batch action (called by Action Scheduler)
     *
     * @param int $batch Batch number
     */
    public function on_async_verification_batch($batch): void {
        WC_Multi_Store_Logger::write(sprintf('Async verification batch hook triggered with batch: %s (type: %s)', print_r($batch, true), gettype($batch)));

        try {
            WC_Multi_Store_Weekly_Sync_Verifier::process_verification_batch((int) $batch);
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'Async verification batch %d failed with exception: %s in %s:%d',
                (int) $batch,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');

            // Clean up any stale locks so verification can be retried
            delete_transient(WC_Multi_Store_Weekly_Sync_Verifier::ASYNC_PROGRESS_TRANSIENT);
        }
    }
}
