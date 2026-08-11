<?php
/**
 * Variation Synchronizer
 * Handles synchronization of product variations to remote stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Variation Synchronizer Class
 */
class WC_Multi_Store_Variation_Synchronizer {

    /**
     * Constructor
     *
     * @param WC_Multi_Store_Product_Extractor $extractor Product extractor instance
     * @param WC_Multi_Store_Product_Transformer $transformer Product transformer instance
     */
    public function __construct(
        private WC_Multi_Store_Product_Extractor $extractor = new WC_Multi_Store_Product_Extractor(),
        private WC_Multi_Store_Product_Transformer $transformer = new WC_Multi_Store_Product_Transformer(),
    ) {}

    /**
     * Sync product variations to remote store
     *
     * @param WC_Product $product Parent product object
     * @param int $remote_parent_id Remote parent product ID
     * @param WC_Multi_Store_API_Client $api API client
     * @param string $store_url Store URL for caching
     * @param array $store_config Store configuration (optional)
     * @param string $sync_type Sync type: full_product|price_quantity|price_quantity_categories|quantity
     * @return int Number of API calls made
     */
    public function sync_variations(WC_Product $product, int $remote_parent_id, WC_Multi_Store_API_Client $api, string $store_url = '', array $store_config = [], string $sync_type = 'full_product'): int {
        if (!$product->is_type('variable')) {
            return 0;
        }

        $api_calls = 0;
        $variation_ids = $product->get_children();

        if (empty($variation_ids)) {
            return 0;
        }

        // Get all remote variations once (batch) - check cache first
        $remote_variations = null;
        if (!empty($store_url)) {
            $remote_variations = WC_Multi_Store_Cache_Manager::get_remote_variations($store_url, $remote_parent_id);
        }

        if ($remote_variations === null || $remote_variations === false) {
            $remote_variations = $api->get_product_variations($remote_parent_id);
            $api_calls++; // Get variations call

            // Cache remote variations
            if (!is_wp_error($remote_variations) && !empty($store_url)) {
                WC_Multi_Store_Cache_Manager::set_remote_variations($store_url, $remote_parent_id, $remote_variations);
            }
        }

        // Build the SKU lookup map from this sync's remote data. Remote variations
        // are already cached by Cache_Manager; retaining a static map here can make
        // a later sync use stale data after the remote-variation cache is cleared.
        $remote_variations_by_sku = [];
        if (!is_wp_error($remote_variations) && is_array($remote_variations)) {
            foreach ($remote_variations as $remote_var) {
                if (!empty($remote_var['sku'])) {
                    $remote_variations_by_sku[$remote_var['sku']] = $remote_var;
                }
            }
        }

        // Prepare batch operations for all variations
        $batch_creates = [];
        $batch_updates = [];
        $batch_deletes = [];

        // Build a set of local variation SKUs for orphan detection
        $local_variation_skus = [];

        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation) {
                continue;
            }

            $sku = $variation->get_sku();
            if (!empty($sku)) {
                $local_variation_skus[$sku] = true;
            }

            // Build variation data using extractor
            $variation_data = $this->extractor->build_variation_data($variation, $sync_type);

            // Upload variation image via API if enabled (bypasses CDN/firewall)
            if (!empty($variation_data['image']['src']) && WC_Multi_Store_Image_Proxy::is_enabled()) {
                $image_id = $variation->get_image_id();
                if ($image_id) {
                    $image_payload = WC_Multi_Store_Image_Proxy::get_image_data($image_id);
                    if ($image_payload) {
                        $upload_result = $api->upload_image($image_payload);
                        if (!is_wp_error($upload_result) && !empty($upload_result['id'])) {
                            $variation_data['image'] = ['id' => $upload_result['id']];
                            $api_calls++;
                        }
                    }
                }
            }

            // Apply per-store pricing rules if configured
            if (!empty($store_config)) {
                $variation_data = $this->transformer->apply_variation_pricing_rules($variation_data, $store_config);
                $variation_data = $this->transformer->apply_variation_stock_rules($variation_data, $variation, $store_config);
            }

            // Find existing variation by SKU using the lookup map
            $remote_variation = $remote_variations_by_sku[$sku] ?? null;

            // Add to batch create or update
            if ($remote_variation) {
                // Light syncs (quantity/price_quantity/price_quantity_categories):
                // skip the update entirely when nothing in the light payload
                // actually differs from what remote already has. full_product
                // always updates unconditionally — unchanged, pre-existing
                // behavior, since it also carries attributes/image that this
                // cheap comparison doesn't cover.
                if ($sync_type !== 'full_product' && $this->light_variation_data_unchanged($variation_data, $remote_variation)) {
                    unset($variation, $variation_data);
                    continue;
                }

                $variation_data['id'] = $remote_variation['id'];
                $batch_updates[] = $variation_data;
            } else {
                $batch_creates[] = $variation_data;
            }

            // Free memory
            unset($variation, $variation_data);
        }

        // Check for orphan variations (remote variations that don't exist locally)
        // Only delete variations that HAVE a SKU which doesn't exist locally
        // Variations without SKU are skipped - we can't reliably match them
        $delete_orphan_variations = WC_Multi_Store_Settings::get('delete_orphan_variations', false);
        if ($delete_orphan_variations && !is_wp_error($remote_variations) && is_array($remote_variations)) {
            foreach ($remote_variations as $remote_var) {
                $remote_sku = $remote_var['sku'] ?? '';

                // Only delete if remote has a SKU that doesn't exist locally
                // Skip variations without SKU - they may exist in source too
                if (!empty($remote_sku) && !isset($local_variation_skus[$remote_sku])) {
                    $batch_deletes[] = $remote_var['id'];

                    WC_Multi_Store_Logger::write(
                        sprintf(
                            'Orphan variation detected: ID %d (SKU: %s) on remote parent %d - marked for deletion',
                            $remote_var['id'],
                            $remote_sku,
                            $remote_parent_id
                        ),
                        'info'
                    );
                }
            }
        }

        // Execute batch operations (massively reduces API calls)
        // WooCommerce batch API has a 100-item limit, so split into chunks
        if (!empty($batch_creates) || !empty($batch_updates) || !empty($batch_deletes)) {
            if (!empty($batch_deletes)) {
                WC_Multi_Store_Logger::write(
                    sprintf(
                        'Deleting %d orphan variation(s) from remote parent product %d',
                        count($batch_deletes),
                        $remote_parent_id
                    ),
                    'info'
                );
            }

            $chunks = $this->chunk_batch_operations($batch_creates, $batch_updates, $batch_deletes, 100);

            foreach ($chunks as $batch_data) {
                $batch_result = $api->batch_product_variations($remote_parent_id, $batch_data);
                $api_calls++;

                if (is_wp_error($batch_result)) {
                    WC_Multi_Store_Logger::write('Batch variation sync failed: ' . $batch_result->get_error_message(), 'error');
                    continue;
                }

                // Check for partial failures in successful batch response
                $this->log_batch_partial_failures($batch_result, $remote_parent_id);
            }
        }

        // Clear cache after sync to ensure fresh data next time
        if (!empty($store_url)) {
            WC_Multi_Store_Cache_Manager::set_remote_variations($store_url, $remote_parent_id, null);
        }

        return $api_calls;
    }

    /**
     * Whether a light-sync variation payload (price/stock fields only) is
     * identical to what the remote variation already has. Only checks the
     * fields a light sync actually carries — full_product's extra
     * attributes/image are out of scope here and always update unconditionally.
     *
     * @param array $variation_data Local variation payload about to be sent
     * @param array $remote_variation Current remote variation data
     * @return bool True if nothing relevant differs
     */
    private function light_variation_data_unchanged(array $variation_data, array $remote_variation): bool {
        foreach (['regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'manage_stock'] as $field) {
            if (!array_key_exists($field, $variation_data)) {
                continue;
            }
            $remote_value = $remote_variation[$field] ?? null;
            if ((string) $variation_data[$field] !== (string) $remote_value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split batch operations into chunks that respect the 100-item API limit
     *
     * @param array $creates Items to create
     * @param array $updates Items to update
     * @param array $deletes Item IDs to delete
     * @param int $max_per_batch Maximum items per batch
     * @return array Array of batch data chunks
     */
    private function chunk_batch_operations(array $creates, array $updates, array $deletes, int $max_per_batch = 100): array {
        $chunks = [];
        $create_chunks = array_chunk($creates, $max_per_batch);
        $update_chunks = array_chunk($updates, $max_per_batch);
        $delete_chunks = array_chunk($deletes, $max_per_batch);

        // Merge all items into a flat list with their operation type
        $all_items = [];
        foreach ($creates as $item) {
            $all_items[] = ['op' => 'create', 'data' => $item];
        }
        foreach ($updates as $item) {
            $all_items[] = ['op' => 'update', 'data' => $item];
        }
        foreach ($deletes as $item) {
            $all_items[] = ['op' => 'delete', 'data' => $item];
        }

        // Split into chunks of max_per_batch
        $item_chunks = array_chunk($all_items, $max_per_batch);

        foreach ($item_chunks as $chunk) {
            $batch = [];
            foreach ($chunk as $item) {
                $batch[$item['op']][] = $item['data'];
            }
            $chunks[] = $batch;
        }

        return $chunks ?: [];
    }

    /**
     * Log partial failures from a batch API response
     *
     * WooCommerce batch API can return 200 OK with individual item errors.
     * Each item in the response may have an 'error' key indicating failure.
     *
     * @param array $batch_result Batch API response
     * @param int $remote_parent_id Remote parent product ID
     */
    private function log_batch_partial_failures(array $batch_result, int $remote_parent_id): void {
        foreach (['create', 'update', 'delete'] as $operation) {
            if (empty($batch_result[$operation]) || !is_array($batch_result[$operation])) {
                continue;
            }

            foreach ($batch_result[$operation] as $item) {
                if (!empty($item['error'])) {
                    $error_msg = $item['error']['message'] ?? 'Unknown error';
                    $error_code = $item['error']['code'] ?? 'unknown';
                    $sku = $item['sku'] ?? ($item['id'] ?? '?');

                    WC_Multi_Store_Logger::write(
                        sprintf(
                            'Batch variation %s failed for parent %d (SKU/ID: %s): [%s] %s',
                            $operation,
                            $remote_parent_id,
                            $sku,
                            $error_code,
                            $error_msg
                        ),
                        'error'
                    );
                }
            }
        }
    }

    /**
     * Find remote parent product ID by searching for the parent product
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param WC_Product $parent_product Parent product object
     * @param string $match_by Match by 'sku' or 'slug'
     * @return int|null Remote parent product ID or null if not found
     */
    public function find_remote_parent_id(WC_Multi_Store_API_Client $api, WC_Product $parent_product, string $match_by = 'sku'): ?int {
        $search_value = ($match_by === 'sku') ? $parent_product->get_sku() : $parent_product->get_slug();

        if (empty($search_value)) {
            return null;
        }

        $result = $api->get_products($search_value, $match_by);

        if (is_wp_error($result) || empty($result)) {
            return null;
        }

        return $result[0]['id'];
    }
}
