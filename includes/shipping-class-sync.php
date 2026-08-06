<?php
/**
 * Shipping Class Sync
 * Synchronizes WooCommerce shipping classes to remote stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Shipping_Class_Sync {
    use WC_Multi_Store_Toggleable_Feature;

    /**
     * Settings option key
     */
    const SETTINGS_KEY = 'wc_mss_shipping_class_sync_settings';

    /**
     * Cache key prefix for shipping classes
     */
    const CACHE_PREFIX = 'wc_mss_shipping_class_';

    /**
     * Cache TTL in seconds (1 hour)
     */
    const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Action Scheduler hook names used to defer sync/delete out of the
     * WordPress term save/delete hook that triggered them.
     */
    const ASYNC_SYNC_HOOK = 'wc_mss_sync_shipping_class_async';
    const ASYNC_DELETE_HOOK = 'wc_mss_delete_shipping_class_async';

    /**
     * @return array
     */
    public static function default_settings(): array {
        return [
            'enabled' => false,
            'auto_sync_on_change' => true,
        ];
    }

    /**
     * @return string
     */
    public static function feature_label(): string {
        return __('Shipping class sync', 'wc-multi-store-sync');
    }

    /**
     * @return string
     */
    public static function central_settings_prefix(): string {
        return 'shipping_class_sync';
    }

    /**
     * Initialize hooks for auto-syncing shipping class changes
     */
    public function __construct() {
        if (!self::is_enabled()) {
            return;
        }

        $settings = self::get_settings();
        if (!empty($settings['auto_sync_on_change'])) {
            add_action('created_product_shipping_class', [$this, 'on_shipping_class_created'], 10, 2);
            add_action('edited_product_shipping_class', [$this, 'on_shipping_class_edited'], 10, 2);
            add_action('delete_product_shipping_class', [$this, 'on_shipping_class_deleted'], 10, 4);
        }

        // Action Scheduler callbacks — run out of the request that triggered
        // the term hook above so saving/deleting a shipping class doesn't
        // block wp-admin on API calls to every active remote store.
        add_action(self::ASYNC_SYNC_HOOK, [$this, 'sync_shipping_class_by_term_id'], 10, 1);
        add_action(self::ASYNC_DELETE_HOOK, [$this, 'delete_shipping_class_by_data'], 10, 2);
    }

    /**
     * When a shipping class is created locally, sync to all remote stores
     */
    public function on_shipping_class_created(int $term_id, int $tt_id): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $term = get_term($term_id, 'product_shipping_class');
        if (!$term || is_wp_error($term)) {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Shipping class created locally: "%s" (slug: %s) - queuing sync to remote stores',
            $term->name,
            $term->slug
        ));

        $this->schedule_async(self::ASYNC_SYNC_HOOK, [$term_id]);
    }

    /**
     * When a shipping class is edited locally, sync updates to remote stores
     */
    public function on_shipping_class_edited(int $term_id, int $tt_id): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $term = get_term($term_id, 'product_shipping_class');
        if (!$term || is_wp_error($term)) {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Shipping class updated locally: "%s" - queuing sync to remote stores',
            $term->name
        ));

        $this->schedule_async(self::ASYNC_SYNC_HOOK, [$term_id]);
    }

    /**
     * When a shipping class is deleted locally, remove from remote stores
     */
    public function on_shipping_class_deleted(int $term_id, int $tt_id, WP_Term $term, array $object_ids): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Shipping class deleted locally: "%s" - queuing removal from remote stores',
            $term->name
        ));

        // Capture name/slug now — the term row is already gone by the time
        // delete_product_shipping_class fires, so an async job can't re-fetch
        // it by ID later.
        $this->schedule_async(self::ASYNC_DELETE_HOOK, [$term->name, $term->slug]);
    }

    /**
     * Action Scheduler callback for on_shipping_class_created()/on_shipping_class_edited().
     */
    public function sync_shipping_class_by_term_id(int $term_id): void {
        $term = get_term($term_id, 'product_shipping_class');
        if (!$term || is_wp_error($term)) {
            return;
        }

        $this->sync_shipping_class_to_all_stores($term);
    }

    /**
     * Action Scheduler callback for on_shipping_class_deleted().
     */
    public function delete_shipping_class_by_data(string $name, string $slug): void {
        $this->delete_shipping_class_from_all_stores_by_data($name, $slug);
    }

    /**
     * Defer a hook to run via Action Scheduler instead of inline in the
     * current request. Skips (with a logged warning) rather than falling
     * back to synchronous execution if Action Scheduler is unavailable —
     * matches WC_Multi_Store_Stock_Verifier::schedule_verification() and
     * WC_Multi_Store_Coupon_Sync::schedule_async().
     */
    private function schedule_async(string $hook, array $args): void {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            WC_Multi_Store_Logger::write(
                "Action Scheduler unavailable — skipped queuing {$hook}",
                'warning'
            );
            return;
        }

        as_schedule_single_action(time(), $hook, $args, WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
    }

    /**
     * Sync a single shipping class to all active remote stores
     */
    public function sync_shipping_class_to_all_stores(WP_Term $term): array {
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $results[$store['store_url']] = $this->sync_shipping_class_to_store($client, $term, $store['store_url']);
        }

        return $results;
    }

    /**
     * Sync a shipping class to a specific remote store
     */
    public function sync_shipping_class_to_store(WC_Multi_Store_API_Client $client, WP_Term $term, string $store_url): bool {
        $remote_class = $this->find_remote_shipping_class($client, $term->slug, $store_url);

        $data = [
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
        ];

        if ($remote_class) {
            $response = $client->put('products/shipping_classes/' . $remote_class['id'], $data);
        } else {
            $response = $client->post('products/shipping_classes', $data);
        }

        if (is_wp_error($response)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to sync shipping class "%s" to %s: %s',
                $term->name,
                $store_url,
                $response->get_error_message()
            ), 'error');
            return false;
        }

        // Clear cache for this store
        delete_transient(self::CACHE_PREFIX . md5($store_url));

        WC_Multi_Store_Logger::write(sprintf(
            'Shipping class "%s" synced to %s (ID: %d)',
            $term->name,
            $store_url,
            $response['id'] ?? 0
        ));

        return true;
    }

    /**
     * Delete a shipping class from all remote stores
     */
    public function delete_shipping_class_from_all_stores(WP_Term $term): array {
        return $this->delete_shipping_class_from_all_stores_by_data($term->name, $term->slug);
    }

    /**
     * Delete a shipping class from all remote stores by name/slug alone —
     * used by the Action Scheduler callback, where the local WP_Term is
     * already gone by the time the job runs (see on_shipping_class_deleted()).
     */
    public function delete_shipping_class_from_all_stores_by_data(string $name, string $slug): array {
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $remote_class = $this->find_remote_shipping_class($client, $slug, $store['store_url']);

            if ($remote_class) {
                $response = $client->delete('products/shipping_classes/' . $remote_class['id'], ['force' => true]);
                $success = !is_wp_error($response);
                $results[$store['store_url']] = $success;

                if ($success) {
                    delete_transient(self::CACHE_PREFIX . md5($store['store_url']));
                    WC_Multi_Store_Logger::write(sprintf(
                        'Shipping class "%s" deleted from %s',
                        $name,
                        $store['store_url']
                    ));
                } else {
                    WC_Multi_Store_Logger::write(sprintf(
                        'Failed to delete shipping class "%s" from %s: %s',
                        $name,
                        $store['store_url'],
                        $response->get_error_message()
                    ), 'error');
                }
            }
        }

        return $results;
    }

    /**
     * Sync ALL shipping classes to all remote stores (bulk operation)
     */
    public static function sync_all_shipping_classes(): array {
        $terms = get_terms([
            'taxonomy' => 'product_shipping_class',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return ['synced' => 0, 'failed' => 0, 'total' => 0];
        }

        $instance = new self();
        $synced = 0;
        $failed = 0;

        foreach ($terms as $term) {
            $results = $instance->sync_shipping_class_to_all_stores($term);
            foreach ($results as $success) {
                if ($success) {
                    $synced++;
                } else {
                    $failed++;
                }
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'total' => count($terms),
        ];
    }

    /**
     * Find a remote shipping class by slug
     */
    private function find_remote_shipping_class(WC_Multi_Store_API_Client $client, string $slug, string $store_url): ?array {
        $remote_classes = $this->get_remote_shipping_classes($client, $store_url);

        foreach ($remote_classes as $class) {
            if (($class['slug'] ?? '') === $slug) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Get all remote shipping classes (cached)
     */
    private function get_remote_shipping_classes(WC_Multi_Store_API_Client $client, string $store_url): array {
        $cache_key = self::CACHE_PREFIX . md5($store_url);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = $client->get('products/shipping_classes', ['per_page' => 100]);

        if (is_wp_error($response)) {
            return [];
        }

        $classes = is_array($response) ? $response : [];
        set_transient($cache_key, $classes, self::CACHE_TTL);

        return $classes;
    }

    /**
     * Get the shipping class slug for a product (used during product sync)
     */
    public static function get_product_shipping_class_slug(WC_Product $product): string {
        $shipping_class_id = $product->get_shipping_class_id();
        if (!$shipping_class_id) {
            return '';
        }

        $term = get_term($shipping_class_id, 'product_shipping_class');
        if (!$term || is_wp_error($term)) {
            return '';
        }

        return $term->slug;
    }

    /**
     * Create an API client for a store
     */
    private static function get_api_client(array $store): WC_Multi_Store_API_Client {
        return WC_Multi_Store_API_Client::for_store($store['store_url'], $store);
    }

    /**
     * AJAX handler: Sync all shipping classes
     */
    public static function ajax_sync_all(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $results = self::sync_all_shipping_classes();

        wp_send_json_success([
            'message' => sprintf(
                __('Shipping classes synced: %d successful, %d failed (out of %d total)', 'wc-multi-store-sync'),
                $results['synced'],
                $results['failed'],
                $results['total']
            ),
            'results' => $results,
        ]);
    }

}
