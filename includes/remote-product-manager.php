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
     * @return array|WP_Error|null Remote product, lookup error, or null if not found
     */
    public function find_remote_product(WC_Multi_Store_API_Client $api, WC_Product $product, string $match_by, string $store_url = ''): array|\WP_Error|null {
        $search_value = ($match_by === 'sku') ? $product->get_sku() : $product->get_slug();
        $bypass_cache = false;
        // Prefer the stable local↔remote ID link over search-by-value: SKU/slug
        // can legitimately change after the first sync, and re-searching by the
        // NEW value would miss the existing remote record — risking a duplicate
        // create on light syncs when auto_create_missing_products is enabled.
        if (!empty($store_url)) {
            $stored_id = self::get_stored_remote_id($product->get_id(), $store_url);
            if ($stored_id !== null) {
                $by_id = $api->get_product($stored_id);
                if (!is_wp_error($by_id) && filter_var($by_id['id'] ?? null, FILTER_VALIDATE_INT) === $stored_id) {
                    if (!empty($search_value)) {
                        WC_Multi_Store_Cache_Manager::set_remote_product($store_url, $search_value, $match_by, $by_id);
                    }
                    return $by_id;
                }
                // An outage or rejected credentials do not mean the product
                // was deleted. Keep its identity and let the queue retry.
                if (is_wp_error($by_id)) {
                    $error_data = $by_id->get_error_data();
                    if (!is_array($error_data) || (int) ($error_data['status'] ?? 0) !== 404) {
                        return $by_id;
                    }
                } else {
                    return new \WP_Error('invalid_product_response', 'Stored remote product lookup returned an invalid product ID');
                }
                // Stored ID no longer resolves (deleted independently on the
                // remote store) — clear it and fall through to search-by-value.
                self::clear_stored_remote_id($product->get_id(), $store_url);
                $bypass_cache = true;
                // A stale positive/negative cache must not undo the live 404.
                if ($search_value !== '') {
                    WC_Multi_Store_Cache_Manager::delete_remote_product($store_url, $search_value, $match_by);
                }
            }
        }

        if (empty($search_value)) {
            return null;
        }

        // Try to get from cache first
        if (!empty($store_url) && !$bypass_cache) {
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
            return $result;
        }

        if (!array_is_list($result) || (!empty($result) && (!is_array($result[0]) || filter_var($result[0]['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false))) {
            return new \WP_Error('invalid_product_response', 'Remote product search returned an invalid product list');
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
        $original_product_data = $product_data;

        if ($remote_product) {
            // Update existing product — remove 'type' as it's not needed for updates
            // and causes errors if the remote store doesn't have the same product types
            unset($product_data['type']);
            $result = $api->update_product($remote_product['id'], $product_data);
            $action = 'updated';

            // The remote product we were about to update no longer exists (deleted on the remote
            // store while a cached or stored ID still pointed at it). Forget the stale identity,
            // look the product up again, and either update what is really there or create it.
            if (is_wp_error($result) && self::remote_http_status($result) === 404) {
                $healed = $this->recover_from_missing_remote_product($api, $product, $remote_product, $product_data, $original_product_data, $sync_type);
                if ($healed !== null) {
                    [$result, $action, $remote_product] = $healed;
                }
            }

            // A duplicate SKU is a data problem on the remote store; explain it and stop retrying.
            if (is_wp_error($result) && self::is_duplicate_sku_error($result)) {
                return [
                    'success' => false,
                    'action' => $action,
                    'result' => $result,
                    'message' => $this->handle_duplicate_sku($api, $product, (int) $remote_product['id']),
                ];
            }
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

            if (is_wp_error($result) && self::is_duplicate_sku_error($result)) {
                return [
                    'success' => false,
                    'action' => $action,
                    'result' => $result,
                    'message' => $this->handle_duplicate_sku($api, $product, 0),
                ];
            }
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

        WC_Multi_Store_Sku_Conflicts::clear($product->get_id(), $api->get_store_url());

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

    /** HTTP status carried by an API error, or 0 when it has none. */
    private static function remote_http_status(\WP_Error $error): int {
        $data = $error->get_error_data();

        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    /**
     * WooCommerce rejects a SKU that another product already uses. Its message is localised to the
     * remote site's language, so detect it by the machine-readable error code, not by the text.
     */
    private static function is_duplicate_sku_error(\WP_Error $error): bool {
        $data = $error->get_error_data();
        $remote_code = is_array($data) && is_array($data['data'] ?? null) ? (string) ($data['data']['code'] ?? '') : '';

        return in_array($remote_code, ['product_invalid_sku', 'woocommerce_rest_product_sku_unique'], true);
    }

    /**
     * Warn about a duplicate SKU and build an actionable message for it. The wording ("is already
     * used by another product") is also what the queue uses to stop retrying: the same request
     * cannot succeed until somebody fixes the products on the remote store.
     */
    private function handle_duplicate_sku(WC_Multi_Store_API_Client $api, WC_Product $product, int $target_remote_id): string {
        $sku = $product->get_sku();
        $owner_id = 0;
        $owner_name = '';

        if ($sku !== '') {
            $found = $api->get_products($sku, 'sku');
            if (is_array($found) && isset($found[0]['id']) && (int) $found[0]['id'] !== $target_remote_id) {
                $owner_id = (int) $found[0]['id'];
                $owner_name = (string) ($found[0]['name'] ?? '');
            }
        }

        WC_Multi_Store_Sku_Conflicts::record($product->get_id(), $api->get_store_url(), $sku, $target_remote_id, $owner_id, $owner_name);

        return sprintf(
            'SKU "%s" is already used by another product on the remote store%s, so %s could not be saved. Fix or remove the duplicate on the remote store.',
            $sku,
            $owner_id > 0 ? sprintf(' (remote product #%d)', $owner_id) : '',
            $target_remote_id > 0 ? sprintf('remote product #%d', $target_remote_id) : 'the product'
        );
    }

    /**
     * Recover from a 404 while updating a remote product.
     *
     * @return array{0: array|\WP_Error, 1: string, 2: array|null}|null [result, action, remote product], or null to keep the original error
     */
    private function recover_from_missing_remote_product(
        WC_Multi_Store_API_Client $api,
        WC_Product $product,
        array $stale_remote_product,
        array $update_data,
        array $create_data,
        string $sync_type
    ): ?array {
        $store_url = $api->get_store_url();
        $match_by = (string) WC_Multi_Store_Settings::get('match_products_by', 'sku');
        $search_value = $match_by === 'sku' ? $product->get_sku() : $product->get_slug();

        self::clear_stored_remote_id($product->get_id(), $store_url);
        if ($search_value !== '') {
            WC_Multi_Store_Cache_Manager::delete_remote_product($store_url, $search_value, $match_by);
        }

        $fresh = $this->find_remote_product($api, $product, $match_by, $store_url);
        if (is_wp_error($fresh)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Stale remote product %d on %s could not be re-resolved: %s',
                (int) ($stale_remote_product['id'] ?? 0),
                $store_url,
                $fresh->get_error_message()
            ), 'error');
            return null;
        }

        if (is_array($fresh) && (int) ($fresh['id'] ?? 0) > 0 && (int) $fresh['id'] !== (int) $stale_remote_product['id']) {
            return [$api->update_product((int) $fresh['id'], $update_data), 'updated', $fresh];
        }

        // Truly gone from the remote store: only a full sync may create it again.
        if ($fresh === null && $sync_type === 'full_product') {
            $standard_types = ['simple', 'grouped', 'external', 'variable'];
            if (isset($create_data['type']) && !in_array($create_data['type'], $standard_types, true)) {
                unset($create_data['type']);
            }

            return [$api->create_product($create_data), 'created', null];
        }

        return null;
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
        if (array_key_exists('images', $expected)) {
            $images = $actual['images'] ?? null;
            $expected_ids = array_filter(array_column($expected['images'], 'id'));
            if (!is_array($images) || count($images) !== count($expected['images'])
                || array_diff($expected_ids, array_column($images, 'id'))) {
                return new \WP_Error('product_verification_failed', sprintf('Remote product %d verification failed: images do not match the sent gallery', $remote_id));
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
