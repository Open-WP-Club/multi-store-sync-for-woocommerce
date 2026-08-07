<?php
/**
 * Weekly Verification Remote Data Fetcher
 * Remote API fetching, batching/caching, and product-selection for the weekly
 * sync verifier — extracted from WC_Multi_Store_Weekly_Sync_Verifier, which
 * delegates to this class.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher {

    /**
     * API client pool - reuse clients instead of creating new ones for each product
     * Key: store_url, Value: WC_Multi_Store_API_Client instance
     *
     * @var array
     */
    private static array $api_client_pool = [];

    /**
     * Per-run cache populated by prefetch_remote_batch_data() ahead of the main
     * verification loop, keyed [store_url][search_value] => remote product array
     * or null for a confirmed miss. get_remote_product() checks this first and
     * only falls back to a live per-item API call on a genuine cache miss (e.g.
     * prefetch wasn't run, or a SKU was added mid-run) — so this is purely an
     * optimization layer, never a behavior change, and existing callers/tests
     * that invoke get_remote_product()/verify_product() directly without going
     * through the prefetch step keep working exactly as before.
     *
     * @var array<string, array<string, array|null>>
     */
    private static array $batch_cache = [];

    /**
     * Safety cap on remote-catalog streaming pages when building the
     * sku/slug → remote-ID index (100 products/page). Sized to match
     * get_products_to_verify()'s own 50,000-product cap.
     */
    const int REMOTE_INDEX_MAX_PAGES = 500;

    /**
     * How many products' worth of remote IDs to request per batch-fetch
     * call (WooCommerce REST API's practical per_page/include ceiling).
     */
    const int REMOTE_BATCH_FETCH_SIZE = 100;

    /**
     * Max remote pages fetched per store during ghost scan. 100 pages × 100 per page = 10k products/store.
     */
    const int GHOST_SCAN_MAX_PAGES = 100;

    /**
     * Hard cap on ghost descriptors retained per scan. Past this, scan stops early and
     * logs a truncation warning — prevents unbounded memory growth on catalogues that are
     * mostly drift (rare but catastrophic if it happens).
     */
    const int GHOST_SCAN_MAX_RESULTS = 1000;

    /**
     * Get or create an API client for a store (pooled)
     *
     * @param string $store_url Store URL
     * @param array $store Store configuration
     * @return WC_Multi_Store_API_Client
     */
    private static function get_api_client(string $store_url, array $store): WC_Multi_Store_API_Client {
        if (!isset(self::$api_client_pool[$store_url])) {
            self::$api_client_pool[$store_url] = WC_Multi_Store_API_Client::for_store($store_url, $store);
        }
        return self::$api_client_pool[$store_url];
    }

    /**
     * Clear API client pool and prefetch batch cache (call after verification completes)
     */
    public static function clear_api_client_pool(): void {
        self::$api_client_pool = [];
        self::$batch_cache = [];
    }

    /**
     * Get remote product data for comparison
     *
     * @param WC_Product $product Local product
     * @param string $store_url Store URL
     * @param array $store Store configuration
     * @return object|null Remote product data
     */
    public static function get_remote_product(WC_Product $product, string $store_url, array $store): ?object {
        $settings = get_option('wc_multi_store_sync_settings', []);
        $match_by = $settings['match_products_by'] ?? 'sku';
        $sku = $product->get_sku();
        $search_value = ($match_by === 'sku') ? $sku : $product->get_slug();

        // Fast path: prefetch_remote_batch_data() (called from run_verification()
        // ahead of the main loop) may have already resolved this store+search_value
        // via a batched include= fetch instead of one live call per product.
        if (array_key_exists($search_value, self::$batch_cache[$store_url] ?? [])) {
            $cached = self::$batch_cache[$store_url][$search_value];
            return $cached === null ? null : (object) $cached;
        }

        // Use pooled API client instead of creating new one for each product
        $api_client = self::get_api_client($store_url, $store);

        // Prefer the stable local↔remote ID link over search-by-value — SKU/slug
        // can legitimately drift after the last sync (rename, permalink edit),
        // which would otherwise misreport this product as missing here.
        $stored_id = WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id($product->get_id(), $store_url);
        if ($stored_id !== null) {
            $by_id = $api_client->get_product($stored_id);
            if (!is_wp_error($by_id) && !empty($by_id)) {
                $remote_data = is_object($by_id) ? json_decode(json_encode($by_id), true) : $by_id;
                if (!empty($search_value)) {
                    WC_Multi_Store_Cache_Manager::update_remote_product_after_sync($store_url, $search_value, $match_by, $remote_data);
                }
                return (object) $remote_data;
            }
            // Stored ID no longer resolves (deleted independently on the
            // remote store) — clear it and fall through to search-by-value.
            WC_Multi_Store_Remote_Product_Manager::clear_stored_remote_id($product->get_id(), $store_url);
        }

        $remote_products = $api_client->get_products($search_value, $match_by);

        if (is_wp_error($remote_products)) {
            throw new Exception(sprintf(
                'API error fetching products from %s: %s',
                $store_url,
                $remote_products->get_error_message()
            ));
        }

        $remote_product = !empty($remote_products) && isset($remote_products[0]) ? (object) $remote_products[0] : null;

        // Cache immediately after fetching - BEFORE verification comparison
        // This ensures cache always has the latest data from remote store
        if ($remote_product && !empty($search_value)) {
            $remote_data = is_object($remote_product) ? json_decode(json_encode($remote_product), true) : $remote_product;
            WC_Multi_Store_Cache_Manager::update_remote_product_after_sync($store_url, $search_value, $match_by, $remote_data);
        }

        return $remote_product;
    }

    /**
     * Build a per-store sku/slug → remote-product-ID index by streaming each
     * store's full catalog once (same streaming pattern as scan_ghost_products(),
     * which already proves this is memory-safe for large catalogs — only the
     * search value and numeric ID are kept, not full product data).
     *
     * This is phase 1 of the batch prefetch: it tells us WHICH remote ID (if
     * any) corresponds to each local product, without yet fetching the full
     * product data needed for stock/price/category comparison. Phase 2
     * (prefetch_remote_batch_data()) uses this index to fetch full data in
     * chunks via the REST API's include= filter instead of one call per item.
     *
     * @param array  $active_stores Active store configurations keyed by URL
     * @param string $match_by      'sku' or 'slug'
     * @return array<string, array<string, int>> [store_url => [search_value => remote_id]]
     */
    public static function build_remote_index(array $active_stores, string $match_by): array {
        $index = [];

        foreach ($active_stores as $store_url => $store) {
            $store_index = [];
            $api_client = self::get_api_client($store_url, $store);

            $err = $api_client->stream_products(
                ['per_page' => self::REMOTE_BATCH_FETCH_SIZE, 'status' => 'publish'],
                function (array $page) use ($match_by, &$store_index): bool {
                    foreach ($page as $remote) {
                        $remote_arr = is_array($remote) ? $remote : (array) $remote;
                        $search_value = ($match_by === 'sku') ? ($remote_arr['sku'] ?? '') : ($remote_arr['slug'] ?? '');

                        if ($search_value !== '' && !empty($remote_arr['id'])) {
                            $store_index[$search_value] = (int) $remote_arr['id'];
                        }
                    }
                    return true;
                },
                self::REMOTE_INDEX_MAX_PAGES
            );

            if ($err instanceof WP_Error) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Remote index build: could not fetch products from %s — %s. Falling back to per-item lookups for this store.',
                    $store_url,
                    $err->get_error_message()
                ), 'warning');
                // Deliberately omit this store from $index (rather than storing
                // an empty array) — get_remote_product()'s cache check uses
                // array_key_exists on the OUTER store key too, so an absent
                // store here means the fallback live-call path is used for
                // every product/store pair instead of every lookup wrongly
                // resolving to "not found".
                continue;
            }

            $index[$store_url] = $store_index;
        }

        return $index;
    }

    /**
     * Resolve local product IDs to their SKU or slug in a single bulk query,
     * instead of one wc_get_product() call per product just to read a field
     * we already have the answer to in postmeta/post_name.
     *
     * @param array  $product_ids Local product IDs
     * @param string $match_by    'sku' or 'slug'
     * @return array<int, string> product_id => search value (only present when non-empty)
     */
    public static function load_search_values_for_products(array $product_ids, string $match_by): array {
        global $wpdb;

        if (empty($product_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        if ($match_by === 'sku') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_sku' AND meta_value <> '' AND post_id IN ({$placeholders})",
                ...$product_ids
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID AS post_id, post_name AS meta_value FROM {$wpdb->posts}
                 WHERE post_name <> '' AND ID IN ({$placeholders})",
                ...$product_ids
            ));
        }

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row->post_id] = $row->meta_value;
        }
        return $map;
    }

    /**
     * Phase 2 of the batch prefetch: for a chunk of local product IDs, resolve
     * each store's remote data via a single batched include= fetch (instead of
     * one get_products() call per product per store) and populate $batch_cache
     * so get_remote_product() can serve verify_product() from memory.
     *
     * Products whose search value isn't in $remote_index at all are
     * conclusively "not on this store" — cached as a confirmed miss (null)
     * without spending an API call to find that out, which is a bonus win
     * beyond just batching (the old per-item path always did a live call
     * even for products it was always going to report as missing).
     *
     * @param array  $product_ids   Local product IDs (should be ≤ REMOTE_BATCH_FETCH_SIZE per call)
     * @param array  $active_stores Active store configurations keyed by URL
     * @param array  $remote_index  Output of build_remote_index()
     * @param string $match_by      'sku' or 'slug'
     */
    public static function prefetch_remote_batch_data(array $product_ids, array $active_stores, array $remote_index, string $match_by): void {
        $search_values = self::load_search_values_for_products($product_ids, $match_by);

        foreach ($active_stores as $store_url => $store) {
            if (!isset($remote_index[$store_url])) {
                // build_remote_index() couldn't stream this store — leave it
                // out of $batch_cache entirely so get_remote_product() falls
                // back to its original live-call path for every product here.
                continue;
            }

            $store_index = $remote_index[$store_url];
            // Reverse lookup (remote ID -> exists) built from the SAME index
            // build_remote_index() already streamed — no extra API/memory
            // cost — so a product whose local SKU/slug drifted since its
            // last sync can still be resolved: it's simply present in
            // $store_index under its OLD search value.
            $id_exists = array_flip($store_index);

            $remote_ids_to_fetch = [];
            $remote_id_for_search_value = [];

            foreach ($search_values as $product_id => $search_value) {
                if ($search_value === '' || array_key_exists($search_value, self::$batch_cache[$store_url] ?? [])) {
                    continue;
                }

                if (isset($store_index[$search_value])) {
                    $remote_id = $store_index[$search_value];
                } else {
                    $stored_id = WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id((int) $product_id, $store_url);
                    if ($stored_id !== null && isset($id_exists[$stored_id])) {
                        $remote_id = $stored_id;
                    } else {
                        // Confirmed absent from this store's catalog — no API call needed.
                        self::$batch_cache[$store_url][$search_value] = null;
                        continue;
                    }
                }

                $remote_ids_to_fetch[] = $remote_id;
                $remote_id_for_search_value[$search_value] = $remote_id;
            }

            if (empty($remote_ids_to_fetch)) {
                continue;
            }

            $api_client = self::get_api_client($store_url, $store);
            $response = $api_client->get_products('', $match_by, [
                'include' => implode(',', array_unique($remote_ids_to_fetch)),
                'per_page' => self::REMOTE_BATCH_FETCH_SIZE,
            ]);

            if (is_wp_error($response)) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Batch prefetch: could not fetch products from %s — %s. Falling back to per-item lookups for this chunk.',
                    $store_url,
                    $response->get_error_message()
                ), 'warning');
                // Don't cache anything for these search values — leaves them
                // as genuine cache misses, so get_remote_product() falls back
                // to a live per-item call for this chunk/store only.
                continue;
            }

            $by_id = [];
            foreach ((array) $response as $remote) {
                $remote_arr = is_array($remote) ? $remote : (array) $remote;
                if (!empty($remote_arr['id'])) {
                    $by_id[(int) $remote_arr['id']] = $remote_arr;
                }
            }

            foreach ($remote_id_for_search_value as $search_value => $remote_id) {
                if (array_key_exists($search_value, self::$batch_cache[$store_url] ?? [])) {
                    continue;
                }

                $product_data = $by_id[$remote_id] ?? null;

                // Cached under the product's CURRENT search value (not the
                // drifted remote one, if this was a stored-ID resolution) —
                // that's what get_remote_product() looks up by.
                self::$batch_cache[$store_url][$search_value] = $product_data;

                if ($product_data !== null) {
                    WC_Multi_Store_Cache_Manager::update_remote_product_after_sync($store_url, $search_value, $match_by, $product_data);
                }
            }
        }
    }

    /**
     * Get products to verify based on settings
     *
     * @param array $settings Verification settings
     * @return array Product IDs
     */
    public static function get_products_to_verify(array $settings): array {
        $limit = intval($settings['product_limit'] ?? 0);
        $sample_mode = $settings['sample_mode'] ?? 'all';

        // Get active stores for filtering (cached)
        $active_stores = WC_Multi_Store_Settings::get_active_stores();

        // Build tax_query to exclude products that are excluded from ALL stores
        $tax_query = self::build_exclusion_tax_query($active_stores);

        // Apply a sensible maximum to prevent memory issues
        // If no limit set, use a maximum of 50000 products per verification run
        $max_products = 50000;
        $effective_limit = $limit > 0 ? min($limit, $max_products) : $max_products;

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => $effective_limit,
            'no_found_rows' => true, // Skip counting for better performance
        ];

        // Add tax_query if we have exclusions
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        switch ($sample_mode) {
            case 'recent':
                $args['orderby'] = 'date';
                $args['order'] = 'DESC';
                break;
            case 'random':
                $args['orderby'] = 'rand';
                break;
            case 'modified':
                $args['orderby'] = 'modified';
                $args['order'] = 'DESC';
                break;
            default:
                $args['orderby'] = 'ID';
                $args['order'] = 'ASC';
        }

        $query = new WP_Query($args);

        WC_Multi_Store_Logger::write(sprintf(
            'Products to verify: %d (filtered from %d total published products)',
            count($query->posts),
            wp_count_posts('product')->publish
        ));

        return $query->posts;
    }

    /**
     * Build tax_query to exclude products that are excluded from ALL stores
     * Only excludes categories/tags that are excluded in EVERY active store
     *
     * @param array $active_stores Active store configurations
     * @return array Tax query array
     */
    public static function build_exclusion_tax_query(array $active_stores): array {
        if (empty($active_stores)) {
            return [];
        }

        // Find categories excluded from ALL stores
        $common_excluded_categories = null;
        $common_excluded_tags = null;

        foreach ($active_stores as $store) {
            $store_excluded_cats = $store['exclude_categories'] ?? [];
            $store_excluded_tags = $store['exclude_tags'] ?? [];

            if ($common_excluded_categories === null) {
                $common_excluded_categories = $store_excluded_cats;
            } else {
                // Keep only categories excluded in ALL stores (intersection)
                $common_excluded_categories = array_intersect($common_excluded_categories, $store_excluded_cats);
            }

            if ($common_excluded_tags === null) {
                $common_excluded_tags = $store_excluded_tags;
            } else {
                // Keep only tags excluded in ALL stores (intersection)
                $common_excluded_tags = array_intersect($common_excluded_tags, $store_excluded_tags);
            }
        }

        $tax_query = [];

        // Exclude products in categories that are excluded from ALL stores
        if (!empty($common_excluded_categories)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => array_values($common_excluded_categories),
                'operator' => 'NOT IN',
            ];
        }

        // Exclude products with tags that are excluded from ALL stores
        if (!empty($common_excluded_tags)) {
            $tax_query[] = [
                'taxonomy' => 'product_tag',
                'field' => 'term_id',
                'terms' => array_values($common_excluded_tags),
                'operator' => 'NOT IN',
            ];
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        return $tax_query;
    }

    /**
     * Build a hash set of all local SKUs (products + variations) in a single SQL query.
     * Replaces N per-row `wc_get_product_id_by_sku()` calls with one query + O(1) lookups.
     *
     * @return array<string, true> SKU set keyed by SKU value
     */
    private static function load_local_sku_set(): array {
        global $wpdb;

        $rows = $wpdb->get_col(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
               AND pm.meta_value <> ''
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status IN ('publish', 'private', 'draft')"
        );

        $set = [];
        foreach ((array) $rows as $sku) {
            $set[$sku] = true;
        }
        return $set;
    }

    /**
     * Build a hash set of all local product slugs in a single SQL query.
     * Replaces N per-row `get_page_by_path()` calls.
     *
     * @return array<string, true> Slug set keyed by slug value
     */
    private static function load_local_slug_set(): array {
        global $wpdb;

        $rows = $wpdb->get_col(
            "SELECT post_name FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ('publish', 'private', 'draft')
               AND post_name <> ''"
        );

        $set = [];
        foreach ((array) $rows as $slug) {
            $set[$slug] = true;
        }
        return $set;
    }

    /**
     * Scan each remote store for products that have no local counterpart (ghost products).
     * A ghost is a remote product whose SKU (or slug) cannot be found in the local catalogue.
     *
     * Streams remote pages through a callback (constant memory) and tests each remote SKU/slug
     * against a pre-loaded local hash set (O(1) lookup). Stops early at GHOST_SCAN_MAX_RESULTS.
     *
     * @param array $active_stores Active store configurations keyed by URL
     * @param array $settings Verification settings
     * @return array List of ghost product descriptors
     */
    public static function scan_ghost_products(array $active_stores, array $settings): array {
        $sync_settings = WC_Multi_Store_Settings::get_settings();
        $match_by = $sync_settings['match_products_by'] ?? 'sku';

        // Pre-load entire local lookup set once — not once per store.
        $local_set = ($match_by === 'sku')
            ? self::load_local_sku_set()
            : self::load_local_slug_set();

        $memory_start = function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : 0;

        $ghosts = [];
        $truncated = false;

        foreach ($active_stores as $store_url => $store) {
            if ($truncated) {
                break;
            }

            $store_name = parse_url($store_url, PHP_URL_HOST);
            $api_client = self::get_api_client($store_url, $store);

            $err = $api_client->stream_products(
                ['per_page' => 100, 'status' => 'publish'],
                function (array $page) use ($store_url, $store_name, $match_by, $local_set, &$ghosts, &$truncated): bool {
                    foreach ($page as $remote) {
                        $remote_arr = is_array($remote) ? $remote : (array) $remote;

                        $remote_sku = $remote_arr['sku'] ?? '';
                        $remote_slug = $remote_arr['slug'] ?? '';
                        $search_value = ($match_by === 'sku') ? $remote_sku : $remote_slug;

                        if ($search_value === '' || isset($local_set[$search_value])) {
                            continue;
                        }

                        $ghosts[] = [
                            'store_url' => $store_url,
                            'store_name' => $store_name,
                            'remote_id' => $remote_arr['id'] ?? null,
                            'remote_sku' => $remote_sku,
                            'remote_name' => $remote_arr['name'] ?? '',
                        ];

                        if (count($ghosts) >= self::GHOST_SCAN_MAX_RESULTS) {
                            $truncated = true;
                            return false; // signal paginate_each to stop
                        }
                    }
                    return true;
                },
                self::GHOST_SCAN_MAX_PAGES
            );

            if ($err instanceof WP_Error) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Ghost scan: could not fetch products from %s — %s',
                    $store_url,
                    $err->get_error_message()
                ), 'warning');
                continue;
            }
        }

        $memory_end = function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : 0;

        if (!empty($ghosts) || $truncated) {
            WC_Multi_Store_Logger::write(sprintf(
                'Ghost product scan: %d ghost(s) found across %d store(s)%s | local index size: %d | peak memory: %.1f MB',
                count($ghosts),
                count($active_stores),
                $truncated ? sprintf(' (TRUNCATED at %d cap)', self::GHOST_SCAN_MAX_RESULTS) : '',
                count($local_set),
                max($memory_start, $memory_end) / 1024 / 1024
            ));
        }

        return $ghosts;
    }
}
