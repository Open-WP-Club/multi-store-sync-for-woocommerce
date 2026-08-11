<?php
/**
 * Order Sync Class
 * Handles order-based product synchronization
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Order_Sync {

    /**
     * Debounce transient prefix
     */
    const string DEBOUNCE_PREFIX = 'wc_mss_order_debounce_';

    /**
     * Large order threshold (number of items)
     */
    const int LARGE_ORDER_THRESHOLD = 10;

    /**
     * Debounce timeout in seconds
     */
    const int DEBOUNCE_TIMEOUT = 30;

    /**
     * PERFORMANCE FIX: Cached settings to avoid repeated get_option() calls
     *
     * @var array|null
     */
    private static ?array $cached_order_settings = null;

    /**
     * Initialize order sync
     */
    public function __construct() {
        // PERFORMANCE FIX: Cache settings on initialization
        self::$cached_order_settings = get_option('wc_multi_store_sync_orders', []);

        // Hook into order status changes
        add_action('woocommerce_order_status_changed', $this->on_order_status_changed(...), 10, 4);

        // Hook into order creation
        add_action('woocommerce_new_order', $this->on_new_order(...), 10, 1);
    }

    /**
     * Get cached order settings
     * PERFORMANCE FIX: Reduces repeated get_option() database calls
     *
     * @return array
     */
    private static function get_order_settings(): array {
        if (self::$cached_order_settings === null) {
            self::$cached_order_settings = get_option('wc_multi_store_sync_orders', []);
        }
        return self::$cached_order_settings;
    }

    /**
     * Handle order status change
     *
     * @param int $order_id Order ID
     * @param string $old_status Old status
     * @param string $new_status New status
     * @param WC_Order $order Order object
     */
    public function on_order_status_changed(int $order_id, string $old_status, string $new_status, WC_Order $order): void {
        // PERFORMANCE FIX: Use cached settings instead of get_option()
        $settings = self::get_order_settings();
        $enabled = $settings['auto_sync_enabled'] ?? false;

        if (!$enabled) {
            return;
        }

        // Check if this status should trigger sync
        $sync_statuses = $settings['sync_statuses'] ?? ['processing', 'completed'];

        if (!in_array($new_status, $sync_statuses)) {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Order #%d status changed: %s → %s (triggering sync)',
            $order_id,
            $old_status,
            $new_status
        ));

        // Queue order products for sync
        $this->queue_order_products($order_id, $order);
    }

    /**
     * Handle new order creation
     *
     * @param int $order_id Order ID
     */
    public function on_new_order(int $order_id): void {
        // PERFORMANCE FIX: Use cached settings instead of get_option()
        $settings = self::get_order_settings();
        $enabled = $settings['auto_sync_enabled'] ?? false;

        if (!$enabled) {
            return;
        }

        $sync_on_new = $settings['sync_on_new'] ?? false;

        if (!$sync_on_new) {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf('New order #%d created (triggering sync)', $order_id));

        $order = wc_get_order($order_id);
        if ($order) {
            $this->queue_order_products($order_id, $order);
        }
    }

    /**
     * Queue order products for sync
     *
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    private function queue_order_products(int $order_id, WC_Order $order): void {
        $product_ids = self::get_order_product_ids($order);

        if (empty($product_ids)) {
            WC_Multi_Store_Logger::write(sprintf('Order #%d has no products to sync', $order_id));
            return;
        }

        $item_count = count($product_ids);

        // Check if this is a large order (needs debouncing)
        if ($item_count >= self::LARGE_ORDER_THRESHOLD) {
            $this->queue_large_order_products($order_id, $product_ids);
        } else {
            // Small order: queue with low priority to prevent overwhelming the system
            WC_MSS()->queue_manager->add_products(
                $product_ids,
                'order_' . $order_id,
                10 // Low priority - will be processed by next cron run
            );

            WC_Multi_Store_Logger::write(sprintf(
                'Order #%d: %d product(s) queued for sync (will be processed by next cron run)',
                $order_id,
                $item_count
            ));
        }
    }

    /**
     * Queue large order products with debouncing
     *
     * @param int $order_id Order ID
     * @param array $product_ids Product IDs
     */
    private function queue_large_order_products(int $order_id, array $product_ids): void {
        // PERFORMANCE FIX: Use cached settings
        $settings = self::get_order_settings();
        $debounce_timeout = isset($settings['debounce_timeout']) ? (int) $settings['debounce_timeout'] : self::DEBOUNCE_TIMEOUT;

        $debounce_key = self::DEBOUNCE_PREFIX . $order_id;

        // Check if already debouncing
        $existing_products = get_transient($debounce_key);

        if ($existing_products !== false) {
            // Merge with existing products
            $product_ids = array_unique(array_merge($existing_products, $product_ids));
            WC_Multi_Store_Logger::write(sprintf(
                'Order #%d: Large order debouncing extended (total: %d products)',
                $order_id,
                count($product_ids)
            ));
        } else {
            WC_Multi_Store_Logger::write(sprintf(
                'Order #%d: Large order detected (%d products), debouncing for %d seconds',
                $order_id,
                count($product_ids),
                $debounce_timeout
            ));
        }

        // Store products in transient
        set_transient($debounce_key, $product_ids, $debounce_timeout);

        // Use Action Scheduler for debouncing
        WC_Multi_Store_Action_Scheduler_Manager::schedule_debounced_order($order_id, $debounce_timeout);
    }

    /**
     * Process debounced order
     *
     * @param int $order_id Order ID
     */
    public static function process_debounced_order(int $order_id): void {
        $debounce_key = self::DEBOUNCE_PREFIX . $order_id;
        $product_ids = get_transient($debounce_key);

        if ($product_ids === false || empty($product_ids)) {
            return;
        }

        // Queue products with low priority to prevent overwhelming the system
        WC_MSS()->queue_manager->add_products(
            $product_ids,
            'order_' . $order_id . '_debounced',
            10 // Low priority - will be processed by next cron run
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Order #%d: Debouncing complete, %d product(s) queued for sync (will be processed by next cron run)',
            $order_id,
            count($product_ids)
        ));

        // Clear transient
        delete_transient($debounce_key);
    }

    /**
     * Get product IDs from order
     *
     * @param WC_Order $order Order object
     * @return array Product IDs
     */
    private static function get_order_product_ids(WC_Order $order): array {
        $product_ids = [];

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();

            // Use variation ID if it exists, otherwise use product ID
            if ($variation_id) {
                $product_ids[] = $variation_id;
            } elseif ($product_id) {
                $product_ids[] = $product_id;
            }
        }

        return array_unique($product_ids);
    }

}

// Register debounced order processing hook
add_action('wc_multi_store_sync_process_debounced_order', WC_Multi_Store_Order_Sync::process_debounced_order(...));
