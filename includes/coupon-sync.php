<?php
/**
 * Coupon Sync
 * Synchronizes WooCommerce coupons to remote stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Coupon_Sync {
    use WC_Multi_Store_Ajax_Auth_Guard;

    /**
     * TTL for the per-store SKU→remote-product-ID / slug→remote-category-ID
     * lookup cache used by resolve_remote_ids(). Kept short relative to
     * sync-engine's DAY_IN_SECONDS term cache since coupon restrictions are
     * more likely to reference just-created products/categories.
     */
    const REMOTE_ID_CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Action Scheduler hook names used to defer coupon sync/delete out of the
     * request that triggered them (see schedule_async() below).
     */
    const ASYNC_SYNC_HOOK = 'wc_mss_sync_coupon_async';
    const ASYNC_DELETE_HOOK = 'wc_mss_delete_coupon_async';

    /**
     * Initialize hooks for auto-syncing coupon changes
     */
    public function __construct() {
        $settings = self::get_coupon_settings();

        if (empty($settings['enabled'])) {
            return;
        }

        add_action('woocommerce_new_coupon', $this->on_coupon_saved(...), 10, 2);
        add_action('woocommerce_update_coupon', $this->on_coupon_saved(...), 10, 2);
        add_action('woocommerce_delete_coupon', $this->on_coupon_deleted(...), 10, 1);
        add_action('woocommerce_trash_coupon', $this->on_coupon_deleted(...), 10, 1);

        // Action Scheduler callbacks — run out of the admin request that
        // triggered on_coupon_saved()/on_coupon_deleted() so saving a coupon
        // restricted to many products doesn't block wp-admin on N remote
        // stores worth of API calls.
        add_action(self::ASYNC_SYNC_HOOK, $this->sync_coupon_by_id(...), 10, 1);
        add_action(self::ASYNC_DELETE_HOOK, $this->delete_coupon_by_code(...), 10, 1);
    }

    /**
     * When a coupon is created or updated
     */
    public function on_coupon_saved(int $coupon_id, WC_Coupon $coupon): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        // Avoid sync loops
        if (get_post_meta($coupon_id, '_wc_mss_syncing', true)) {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Coupon saved: "%s" (ID: %d) - queuing sync to remote stores',
            $coupon->get_code(),
            $coupon_id
        ));

        $this->schedule_async(self::ASYNC_SYNC_HOOK, [$coupon_id]);
    }

    /**
     * When a coupon is deleted or trashed
     */
    public function on_coupon_deleted(int $coupon_id): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $coupon = new WC_Coupon($coupon_id);
        if (!$coupon->get_id()) {
            return;
        }

        // Capture the code now, synchronously — by the time an async job
        // runs, woocommerce_delete_coupon may have already removed the post,
        // so new WC_Coupon($coupon_id) would come back empty.
        $code = $coupon->get_code();

        WC_Multi_Store_Logger::write(sprintf(
            'Coupon deleted: "%s" (ID: %d) - queuing removal from remote stores',
            $code,
            $coupon_id
        ));

        $this->schedule_async(self::ASYNC_DELETE_HOOK, [$code]);
    }

    /**
     * Action Scheduler callback for on_coupon_saved(). Public because Action
     * Scheduler invokes hook callbacks like any other WordPress action.
     */
    public function sync_coupon_by_id(int $coupon_id): void {
        $coupon = new WC_Coupon($coupon_id);
        if (!$coupon->get_id()) {
            return;
        }

        $this->sync_coupon_to_all_stores($coupon);
    }

    /**
     * Action Scheduler callback for on_coupon_deleted().
     */
    public function delete_coupon_by_code(string $code): void {
        $this->delete_coupon_from_all_stores_by_code($code);
    }

    /**
     * Defer a hook to run via Action Scheduler instead of inline in the
     * current request. Matches the skip-if-unavailable convention used
     * elsewhere in the plugin (e.g. WC_Multi_Store_Stock_Verifier::schedule_verification())
     * rather than falling back to synchronous execution — Action Scheduler
     * ships with WooCommerce, which this plugin already requires, so the
     * unavailable case should only happen on a broken install.
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
     * Extract coupon data for the WooCommerce REST API
     */
    public function extract_coupon_data(WC_Coupon $coupon): array {
        $data = [
            'code' => $coupon->get_code(),
            'discount_type' => $coupon->get_discount_type(),
            'amount' => $coupon->get_amount(),
            'description' => $coupon->get_description(),
            'date_expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d\TH:i:s') : null,
            'individual_use' => $coupon->get_individual_use(),
            'usage_limit' => $coupon->get_usage_limit(),
            'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
            'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(),
            'free_shipping' => $coupon->get_free_shipping(),
            'exclude_sale_items' => $coupon->get_exclude_sale_items(),
            'minimum_amount' => $coupon->get_minimum_amount(),
            'maximum_amount' => $coupon->get_maximum_amount(),
        ];

        // Product restrictions by SKU (instead of IDs which differ across stores)
        $product_ids = $coupon->get_product_ids();
        if (!empty($product_ids)) {
            $data['meta_data'] = $data['meta_data'] ?? [];
            $skus = $this->get_skus_for_product_ids($product_ids);
            $data['meta_data'][] = ['key' => '_wc_mss_product_skus', 'value' => $skus];
        }

        $excluded_ids = $coupon->get_excluded_product_ids();
        if (!empty($excluded_ids)) {
            $data['meta_data'] = $data['meta_data'] ?? [];
            $skus = $this->get_skus_for_product_ids($excluded_ids);
            $data['meta_data'][] = ['key' => '_wc_mss_excluded_product_skus', 'value' => $skus];
        }

        // Category restrictions by slug (instead of IDs)
        $category_ids = $coupon->get_product_categories();
        if (!empty($category_ids)) {
            $data['meta_data'] = $data['meta_data'] ?? [];
            $slugs = $this->get_slugs_for_category_ids($category_ids);
            $data['meta_data'][] = ['key' => '_wc_mss_category_slugs', 'value' => $slugs];
        }

        $excluded_cat_ids = $coupon->get_excluded_product_categories();
        if (!empty($excluded_cat_ids)) {
            $data['meta_data'] = $data['meta_data'] ?? [];
            $slugs = $this->get_slugs_for_category_ids($excluded_cat_ids);
            $data['meta_data'][] = ['key' => '_wc_mss_excluded_category_slugs', 'value' => $slugs];
        }

        // Email restrictions
        $email_restrictions = $coupon->get_email_restrictions();
        if (!empty($email_restrictions)) {
            $data['email_restrictions'] = $email_restrictions;
        }

        return $data;
    }

    /**
     * Sync a coupon to all active remote stores
     */
    public function sync_coupon_to_all_stores(WC_Coupon $coupon): array {
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $data = $this->extract_coupon_data($coupon);
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $results[$store['store_url']] = $this->sync_coupon_to_store($client, $coupon, $data, $store['store_url']);
        }

        return $results;
    }

    /**
     * Sync a coupon to a specific remote store
     */
    public function sync_coupon_to_store(WC_Multi_Store_API_Client $client, WC_Coupon $coupon, array $data, string $store_url): bool {
        // Resolve product/category IDs on the remote store
        $data = $this->resolve_remote_ids($client, $data, $store_url);

        // Find existing coupon on remote by code
        $remote_coupon = $this->find_remote_coupon($client, $coupon->get_code());

        if ($remote_coupon) {
            $response = $client->put('coupons/' . $remote_coupon['id'], $data);
        } else {
            $response = $client->post('coupons', $data);
        }

        if (is_wp_error($response)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to sync coupon "%s" to %s: %s',
                $coupon->get_code(),
                $store_url,
                $response->get_error_message()
            ), 'error');
            return false;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Coupon "%s" synced to %s (remote ID: %d)',
            $coupon->get_code(),
            $store_url,
            $response['id'] ?? 0
        ));

        return true;
    }

    /**
     * Delete a coupon from all remote stores
     */
    public function delete_coupon_from_all_stores(WC_Coupon $coupon): array {
        return $this->delete_coupon_from_all_stores_by_code($coupon->get_code());
    }

    /**
     * Delete a coupon from all remote stores by code alone — used by the
     * Action Scheduler callback, where the local WC_Coupon post may already
     * be gone by the time the job runs (see on_coupon_deleted()).
     */
    public function delete_coupon_from_all_stores_by_code(string $code): array {
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $remote_coupon = $this->find_remote_coupon($client, $code);

            if ($remote_coupon) {
                $response = $client->delete('coupons/' . $remote_coupon['id'], ['force' => true]);
                $success = !is_wp_error($response);
                $results[$store['store_url']] = $success;

                if ($success) {
                    WC_Multi_Store_Logger::write(sprintf(
                        'Coupon "%s" deleted from %s',
                        $code,
                        $store['store_url']
                    ));
                }
            }
        }

        return $results;
    }

    /**
     * Bulk sync all published coupons.
     *
     * Paged in batches of 50 so we never hold the full ID list in memory and
     * the WC_Coupon objects from one batch can be freed before the next
     * batch loads. Each coupon also fans out to per-store API calls, so the
     * old unbounded foreach would blow the rate limiter on a few hundred
     * coupons.
     */
    public static function sync_all_coupons(): array {
        $instance   = new self();
        $page_size  = 50;
        $page       = 1;
        $synced     = 0;
        $failed     = 0;
        $total      = 0;

        do {
            $coupon_ids = get_posts([
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => $page_size,
                'paged'          => $page,
                'no_found_rows'  => true,
            ]);

            if (empty($coupon_ids)) {
                break;
            }

            foreach ($coupon_ids as $coupon_id) {
                $coupon  = new WC_Coupon($coupon_id);
                $results = $instance->sync_coupon_to_all_stores($coupon);

                foreach ($results as $success) {
                    if ($success) {
                        $synced++;
                    } else {
                        $failed++;
                    }
                }

                // Release the per-coupon object before moving on so memory
                // doesn't accumulate across hundreds of coupons.
                unset($coupon);
            }

            $total += count($coupon_ids);
            $batch_count = count($coupon_ids);
            $page++;
        } while ($batch_count === $page_size);

        return [
            'synced' => $synced,
            'failed' => $failed,
            'total'  => $total,
        ];
    }

    /**
     * Find a remote coupon by code
     */
    private function find_remote_coupon(WC_Multi_Store_API_Client $client, string $code): ?array {
        $response = $client->get('coupons', ['code' => $code]);

        if (is_wp_error($response) || empty($response)) {
            return null;
        }

        return is_array($response) ? ($response[0] ?? null) : null;
    }

    /**
     * Resolve product SKUs and category slugs to remote IDs
     */
    private function resolve_remote_ids(WC_Multi_Store_API_Client $client, array $data, string $store_url): array {
        // Remove meta_data entries used for cross-store mapping after resolving
        $meta_data = $data['meta_data'] ?? [];
        $product_skus = [];
        $excluded_product_skus = [];
        $category_slugs = [];
        $excluded_category_slugs = [];

        foreach ($meta_data as $meta) {
            match ($meta['key'] ?? '') {
                '_wc_mss_product_skus' => $product_skus = $meta['value'],
                '_wc_mss_excluded_product_skus' => $excluded_product_skus = $meta['value'],
                '_wc_mss_category_slugs' => $category_slugs = $meta['value'],
                '_wc_mss_excluded_category_slugs' => $excluded_category_slugs = $meta['value'],
                default => null,
            };
        }

        // Remove our internal meta keys from the data
        unset($data['meta_data']);

        // Resolve product SKUs to remote IDs
        if (!empty($product_skus)) {
            $data['product_ids'] = $this->resolve_skus_to_remote_ids($client, $product_skus, $store_url);
        }

        if (!empty($excluded_product_skus)) {
            $data['excluded_product_ids'] = $this->resolve_skus_to_remote_ids($client, $excluded_product_skus, $store_url);
        }

        // Resolve category slugs to remote IDs
        if (!empty($category_slugs)) {
            $data['product_categories'] = $this->resolve_category_slugs_to_remote_ids($client, $category_slugs, $store_url);
        }

        if (!empty($excluded_category_slugs)) {
            $data['excluded_product_categories'] = $this->resolve_category_slugs_to_remote_ids($client, $excluded_category_slugs, $store_url);
        }

        return $data;
    }

    /**
     * Resolve SKUs to remote product IDs.
     *
     * Cached per store (sku => remote_id|null map, including negative
     * lookups) so a coupon with many restricted products — or repeated
     * syncs of different coupons touching the same store — doesn't issue
     * one API call per SKU every time.
     */
    private function resolve_skus_to_remote_ids(WC_Multi_Store_API_Client $client, array $skus, string $store_url): array {
        return $this->resolve_via_cached_map(
            $client,
            $skus,
            'wc_mss_coupon_sku_map_' . md5($store_url),
            fn($sku) => $client->get('products', ['sku' => $sku, 'per_page' => 1])
        );
    }

    /**
     * Resolve category slugs to remote category IDs. Same per-store cache
     * pattern as resolve_skus_to_remote_ids() above.
     */
    private function resolve_category_slugs_to_remote_ids(WC_Multi_Store_API_Client $client, array $slugs, string $store_url): array {
        return $this->resolve_via_cached_map(
            $client,
            $slugs,
            'wc_mss_coupon_category_map_' . md5($store_url),
            fn($slug) => $client->get('products/categories', ['slug' => $slug, 'per_page' => 1])
        );
    }

    /**
     * Shared lookup-with-cache helper for the two resolvers above. $fetch is
     * called only for keys not already in the cached map (including keys
     * cached as "not found", stored as null, so a bad SKU on a coupon
     * doesn't get re-queried every sync within the TTL window).
     */
    private function resolve_via_cached_map(WC_Multi_Store_API_Client $client, array $keys, string $cache_key, \Closure $fetch): array {
        $map = get_transient($cache_key);
        if (!is_array($map)) {
            $map = [];
        }

        $ids = [];
        $map_changed = false;

        foreach ($keys as $key) {
            if (array_key_exists($key, $map)) {
                if ($map[$key] !== null) {
                    $ids[] = $map[$key];
                }
                continue;
            }

            $response = $fetch($key);
            $remote_id = (!is_wp_error($response) && !empty($response[0]['id'])) ? $response[0]['id'] : null;

            $map[$key] = $remote_id;
            $map_changed = true;

            if ($remote_id !== null) {
                $ids[] = $remote_id;
            }
        }

        if ($map_changed) {
            set_transient($cache_key, $map, self::REMOTE_ID_CACHE_TTL);
        }

        return $ids;
    }

    /**
     * Get SKUs for a list of local product IDs
     */
    private function get_skus_for_product_ids(array $product_ids): array {
        $skus = [];
        foreach ($product_ids as $id) {
            $product = wc_get_product($id);
            if ($product && $product->get_sku()) {
                $skus[] = $product->get_sku();
            }
        }
        return $skus;
    }

    /**
     * Get slugs for a list of local category IDs
     */
    private function get_slugs_for_category_ids(array $category_ids): array {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'include' => $category_ids,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(fn($term) => $term->slug, $terms);
    }

    /**
     * Get coupon sync settings
     */
    private static function get_coupon_settings(): array {
        return get_option('wc_multi_store_sync_coupon_settings', [
            'enabled' => false,
            'auto_sync_on_save' => true,
            'auto_sync_deletions' => true,
        ]);
    }

    /**
     * Create an API client for a store
     */
    private static function get_api_client(array $store): WC_Multi_Store_API_Client {
        return WC_Multi_Store_API_Client::for_store($store['store_url'], $store);
    }

    /**
     * AJAX handler: Sync all coupons
     */
    public static function ajax_sync_all(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $results = self::sync_all_coupons();

        wp_send_json_success([
            'message' => sprintf(
                __('Coupons synced: %d successful, %d failed (out of %d total)', 'wc-multi-store-sync'),
                $results['synced'],
                $results['failed'],
                $results['total']
            ),
            'results' => $results,
        ]);
    }

    /**
     * AJAX handler: Toggle coupon sync on/off
     */
    public static function ajax_toggle(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $enabled = !empty($_POST['enabled']);
        $settings = self::get_coupon_settings();
        $settings['enabled'] = $enabled;
        update_option('wc_multi_store_sync_coupon_settings', $settings);

        wp_send_json_success([
            'message' => $enabled
                ? __('Coupon sync enabled', 'wc-multi-store-sync')
                : __('Coupon sync disabled', 'wc-multi-store-sync'),
            'enabled' => $enabled,
        ]);
    }
}
