<?php
/**
 * WooCommerce Multi-Store Bulk Actions
 *
 * Adds bulk sync actions to product list
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bulk Actions Class
 */
class WC_Multi_Store_Bulk_Actions {

    /**
     * Constructor
     */
    public function __construct() {
        // Add bulk actions to product list
        add_filter('bulk_actions-edit-product', $this->add_bulk_actions(...));
        add_filter('handle_bulk_actions-edit-product', $this->handle_bulk_actions(...), 10, 3);

        // Add admin notice
        add_action('admin_notices', $this->bulk_action_notices(...));
    }

    /**
     * Add custom bulk actions
     *
     * @param array $actions Existing actions
     * @return array Modified actions
     */
    public function add_bulk_actions(array $actions): array {
        $actions['mss_sync_full'] = __('Multi-Store: Full Sync', 'wc-multi-store-sync');
        $actions['mss_sync_prices'] = __('Multi-Store: Sync Prices & Stock', 'wc-multi-store-sync');
        $actions['mss_sync_stock'] = __('Multi-Store: Sync Stock Only', 'wc-multi-store-sync');
        $actions['mss_queue_high'] = __('Multi-Store: Add to Queue (High Priority)', 'wc-multi-store-sync');
        $actions['mss_bulk_delete'] = __('Multi-Store: Bulk Delete from Remote Stores', 'wc-multi-store-sync');

        return $actions;
    }

    /**
     * Handle bulk actions
     *
     * @param string $redirect_to Redirect URL
     * @param string $action Action name
     * @param array $product_ids Selected product IDs
     * @return string Modified redirect URL
     */
    public function handle_bulk_actions(string $redirect_to, string $action, array $product_ids): string {
        // Check if it's our action
        if (!str_starts_with($action, 'mss_')) {
            return $redirect_to;
        }

        // Verify nonce — WP Products list table uses 'bulk-posts' action
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-posts')) {
            return $redirect_to;
        }

        [$query_key, $count] = match ($action) {
            'mss_sync_full' => ['mss_synced', $this->bulk_sync_products($product_ids, 'full_product')],
            'mss_sync_prices' => ['mss_synced', $this->bulk_sync_products($product_ids, 'price_quantity')],
            'mss_sync_stock' => ['mss_synced', $this->bulk_sync_products($product_ids, 'quantity')],
            'mss_queue_high' => ['mss_queued', WC_MSS()->queue_manager->add_products($product_ids, 'bulk_action', 1)],
            'mss_bulk_delete' => ['mss_bulk_deleted', $this->bulk_delete_products($product_ids)],
            default => [null, null],
        };

        if ($query_key !== null) {
            $redirect_to = add_query_arg($query_key, $count, $redirect_to);
        }

        return $redirect_to;
    }

    /**
     * Bulk sync products - queues products for background processing
     *
     * @param array $product_ids Product IDs
     * @param string $sync_type Sync type to queue ('full_product', 'price_quantity', or 'quantity')
     * @return int Number of products queued
     */
    private function bulk_sync_products(array $product_ids, string $sync_type): int {
        if (empty($product_ids)) {
            return 0;
        }

        // Get active stores
        $stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($stores)) {
            return 0;
        }

        // Queue all products for background processing instead of immediate sync
        // This prevents memory exhaustion and server timeouts
        $queued_count = WC_MSS()->queue_manager->add_products(
            $product_ids,
            'bulk_sync',
            WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL,
            $sync_type
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Bulk sync queued: %d queue items for %d products',
            $queued_count,
            count($product_ids)
        ));

        return $queued_count;
    }

    /**
     * Bulk delete products from remote stores
     *
     * @param array $product_ids Product IDs
     * @return int Number of products queued for deletion
     */
    private function bulk_delete_products(array $product_ids): int {
        if (empty($product_ids)) {
            return 0;
        }

        // Set flag to indicate bulk operation
        if (!defined('DOING_BULK_EDIT')) {
            define('DOING_BULK_EDIT', true);
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Bulk delete operation started: %d products',
            count($product_ids)
        ));

        $queued_count = 0;

        // Queue all deletions - don't load product objects
        // add_product_deletion uses optimized SQL queries
        foreach ($product_ids as $product_id) {
            $result = WC_MSS()->queue_manager->add_product_deletion(
                $product_id,
                'bulk_delete',
                5 // Lower priority for bulk operations
            );

            $queued_count += $result;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Bulk delete operation completed: %d products queued for deletion',
            $queued_count
        ));

        return $queued_count;
    }

    /**
     * Display admin notices for bulk actions
     *
     * @return void
     */
    public function bulk_action_notices(): void {
        if (isset($_REQUEST['mss_synced'])) {
            $synced = intval($_REQUEST['mss_synced']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    __('Successfully synced %d product(s) to remote stores.', 'wc-multi-store-sync'),
                    $synced
                )
            );
        }

        if (isset($_REQUEST['mss_queued'])) {
            $queued = intval($_REQUEST['mss_queued']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    __('Added %d product(s) to sync queue.', 'wc-multi-store-sync'),
                    $queued
                )
            );
        }

        if (isset($_REQUEST['mss_bulk_deleted'])) {
            $deleted = intval($_REQUEST['mss_bulk_deleted']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    __('Queued %d product(s) for deletion from remote stores.', 'wc-multi-store-sync'),
                    $deleted
                )
            );
        }
    }
}
