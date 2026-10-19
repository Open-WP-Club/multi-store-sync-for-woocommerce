<?php
/**
 * Remote Product Manager
 * Handles product operations on remote WooCommerce stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remote Product Manager Class
 */
class WC_Multi_Store_Remote_Product_Manager {

    /**
     * Meta key storing this store's remote product ID for a local product —
     * a stable identity link independent of SKU/slug, since either can
     * legitimately change after the first sync (rename, permalink edit).
     *
     * @param string $store_url Store URL
     * @return string Meta key
     */
    private static function remote_id_meta_key(string $store_url): string {
        return '_mss_remote_id_' . md5($store_url);
    }

    /**
     * Get the stored remote product ID for a local product on a specific store.
     *
     * @param int $product_id Local product ID
     * @param string $store_url Store URL
     * @return int|null Remote product ID, or null if never synced/stored
     */
    public static function get_stored_remote_id(int $product_id, string $store_url): ?int {
        $value = get_post_meta($product_id, self::remote_id_meta_key($store_url), true);
        return ($value !== '' && $value !== false) ? (int) $value : null;
    }

    /**
     * Persist the remote product ID for a local product on a specific store.
     * Called after every successful create/update so future syncs can look
     * the product up by stable ID instead of re-searching by SKU/slug.
     *
     * @param int $product_id Local product ID
     * @param string $store_url Store URL
     * @param int $remote_product_id Remote product ID
     */
    public static function save_remote_product_id(int $product_id, string $store_url, int $remote_product_id): void {
        update_post_meta($product_id, self::remote_id_meta_key($store_url), $remote_product_id);
    }

    /**
     * Clear a stale ID mapping — the remote product no longer resolves by ID
     * (e.g. deleted independently on the remote store).
     *
     * @param int $product_id Local product ID
     * @param string $store_url Store URL
     */
    public static function clear_stored_remote_id(int $product_id, string $store_url): void {
        delete_post_meta($product_id, self::remote_id_meta_key($store_url));
    }

    /**
     * Find product on remote store
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param WC_Product $product Product object
     * @param string $match_by Match by 'sku' or 'slug'
     * @param string $store_url Store URL for caching
     * @return array|null Remote product data or null if not found
     */
    public function find_remote_product(WC_Multi_Store_API_Client $api, WC_Product $product, string $match_by, string $store_url = ''): ?array {
        // Prefer the stable local↔remote ID link over search-by-value: SKU/slug
        // can legitimately change after the first sync, and re-searching by the
        // NEW value would miss the existing remote record — risking a duplicate
        // create on light syncs when auto_create_missing_products is enabled.
        if (!empty($store_url)) {
            $stored_id = self::get_stored_remote_id($product->get_id(), $store_url);
            if ($stored_id !== null) {
                $by_id = $api->get_product($stored_id);
                if (!is_wp_error($by_id) && !empty($by_id)) {
                    $search_value = ($match_by === 'sku') ? $product->get_sku() : $product->get_slug();
                    if (!empty($search_value)) {
                        WC_Multi_Store_Cache_Manager::set_remote_product($store_url, $search_value, $match_by, $by_id);
                    }
                    return $by_id;
                }
                // Stored ID no longer resolves (deleted independently on the
                // remote store) — clear it and fall through to search-by-value.
                self::clear_stored_remote_id($product->get_id(), $store_url);
            }
        }

        if ($match_by === 'sku') {
            $search_value = $product->get_sku();
        } else {
            $search_value = $product->get_slug();
        }

        if (empty($search_value)) {
            return null;
        }

        // Try to get from cache first
        if (!empty($store_url)) {
            // Check if product is known to not exist (negative cache)
            if (WC_Multi_Store_Cache_Manager::is_product_not_found($store_url, $search_value, $match_by)) {
                return null;
            }

            $cached = WC_Multi_Store_Cache_Manager::get_remote_product($store_url, $search_value, $match_by);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Not in cache, fetch from API
        $result = $api->get_products($search_value, $match_by);

        if (is_wp_error($result)) {
            return null;
        }

        $remote_product = !empty($result) ? $result[0] : null;

        // Cache the result
        if (!empty($store_url)) {
            if ($remote_product === null) {
                // Use negative cache for products not found on remote store
                WC_Multi_Store_Cache_Manager::set_product_not_found($store_url, $search_value, $match_by);
            } else {
                WC_Multi_Store_Cache_Manager::set_remote_product($store_url, $search_value, $match_by, $remote_product);
            }
        }

        return $remote_product;
    }

    /**
     * Create or update product on remote store
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param WC_Product $product Product object
     * @param array|null $remote_product Existing remote product or null
     * @param array $product_data Product data to send
     * @param string $sync_type Sync type
     * @param string $sync_source Sync source for error reporting
     * @return array Result with 'success', 'action', 'result', 'message'
     */
    public function create_or_update(WC_Multi_Store_API_Client $api, WC_Product $product, ?array $remote_product, array $product_data, string $sync_type, string $sync_source = 'manual'): array {
        if ($remote_product) {
            // Update existing product — remove 'type' as it's not needed for updates
            // and causes errors if the remote store doesn't have the same product types
            unset($product_data['type']);
            $result = $api->update_product($remote_product['id'], $product_data);
            $action = 'updated';
        } else {
            // Create new product (only for full sync)
            if ($sync_type !== 'full_product') {
                $source_desc = $this->get_sync_source_description($sync_source);
                return [
                    'success' => false,
                    'action' => null,
                    'result' => null,
                    'message' => sprintf(
                        'Product not found on remote store (non-full sync). Trigger: %s (%s)',
                        $source_desc,
                        $sync_source
                    ),
                ];
            }

            // For create: only send type if it's a standard WooCommerce type,
            // otherwise remote store may reject custom types (bundle, subscription, etc.)
            $standard_types = ['simple', 'grouped', 'external', 'variable'];
            if (isset($product_data['type']) && !in_array($product_data['type'], $standard_types, true)) {
                unset($product_data['type']);
            }

            $result = $api->create_product($product_data);
            $action = 'created';
        }

        // Check result
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'action' => $action,
                'result' => $result,
                'message' => $result->get_error_message(),
            ];
        }

        // A successful HTTP response alone does not confirm a product write.
        // Reject empty/error-shaped payloads and responses for a different ID
        // before the engine persists the mapping or reports success.
        $remote_id = filter_var($result['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $validation_error = null;
        if ($remote_id === false) {
            $validation_error = 'Remote product response is missing a valid product ID';
        } elseif ($remote_product && $remote_id !== (int) $remote_product['id']) {
            $validation_error = sprintf('Remote product response ID mismatch: expected %d, received %d', $remote_product['id'], $remote_id);
        } elseif (($result['status'] ?? '') === 'trash' && ($product_data['status'] ?? '') !== 'trash') {
            $validation_error = sprintf('Remote product %d is in trash; sync did not restore it', $remote_id);
        } elseif (isset($product_data['status']) && ($result['status'] ?? null) !== $product_data['status']) {
            $validation_error = sprintf('Remote product %d status mismatch: expected %s, received %s', $remote_id, $product_data['status'], $result['status'] ?? 'missing');
        }

        if ($validation_error !== null) {
            return [
                'success' => false,
                'action' => $action,
                'result' => $result,
                'message' => $validation_error,
            ];
        }

        return [
            'success' => true,
            'action' => $action,
            'result' => $result,
            'message' => 'Product ' . $action . ' successfully',
        ];
    }

    /**
     * Read the product back from the API and verify fields actually sent.
     * This deliberately bypasses the local remote-product cache.
     *
     * @param WC_Multi_Store_API_Client $api Remote API client.
     * @param int $remote_id Product ID returned by the write.
     * @param array $expected Data sent after store rules and conflict preservation.
     * @return array|WP_Error Fresh product, or a verification failure.
     */
    public function verify_remote_product(WC_Multi_Store_API_Client $api, int $remote_id, array $expected): array|\WP_Error {
        $actual = $api->get_product($remote_id);
        if (is_wp_error($actual)) {
            return new \WP_Error('product_verification_failed', sprintf('Remote product %d verification failed: %s', $remote_id, $actual->get_error_message()));
        }
        if (filter_var($actual['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== $remote_id) {
            return new \WP_Error('product_verification_failed', sprintf('Remote product %d verification failed: product was not returned by GET', $remote_id));
        }
        if (($actual['status'] ?? '') === 'trash' && ($expected['status'] ?? '') !== 'trash') {
            return new \WP_Error('product_verification_failed', sprintf('Remote product %d verification failed: product is in trash', $remote_id));
        }

        foreach (['sku', 'status', 'catalog_visibility', 'manage_stock', 'stock_quantity', 'stock_status', 'regular_price', 'sale_price'] as $field) {
            if (!array_key_exists($field, $expected)) {
                continue;
            }
            $matches = array_key_exists($field, $actual) && $actual[$field] === $expected[$field];
            // WooCommerce returns prices as decimal strings and may normalize
            // their precision. Quantities may be serialized as ints or floats.
            if (in_array($field, ['stock_quantity', 'regular_price', 'sale_price'], true)
                && is_numeric($expected[$field]) && is_numeric($actual[$field] ?? null)) {
                $matches = (float) $expected[$field] === (float) $actual[$field];
            }
            if (!$matches) {
                return new \WP_Error('product_verification_failed', sprintf(
                    'Remote product %d verification failed: %s expected %s, received %s',
                    $remote_id, $field, json_encode($expected[$field]),
                    array_key_exists($field, $actual) ? json_encode($actual[$field]) : 'missing'
                ));
            }
        }
        return $actual;
    }

    /**
     * Get human-readable sync source description
     *
     * @param string $sync_source Sync source code
     * @return string Human-readable description
     */
    private function get_sync_source_description(string $sync_source): string {
        $descriptions = [
            'manual' => 'manual sync',
            'product_save' => 'product save',
            'new_product' => 'new product',
            'variation_save' => 'variation save',
            'stock_change' => 'stock change',
            'price_change' => 'price change',
            'product_delete' => 'product delete',
            'product_restore' => 'product restore',
            'variation_delete' => 'variation delete',
            'status_change' => 'status change',
            'status_change_trash' => 'move to trash',
            'status_change_restore' => 'restore from trash',
            'scheduled' => 'scheduled task',
            'scheduled_sync' => 'scheduled sync',
            'webhook' => 'webhook event',
            'bulk_action' => 'bulk action',
            'category_sync' => 'category sync',
            'order_stock_reduction' => 'order stock reduction',
            'weekly_verification' => 'weekly verification',
        ];

        return $descriptions[$sync_source] ?? $sync_source;
    }
}
