<?php
/**
 * Weekly Sync Verifier
 * Performs comprehensive weekly audits of product synchronization across all stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Weekly_Sync_Verifier {

    /**
     * Database table name for verification reports
     */
    const string TABLE_NAME = 'wc_multi_store_weekly_verifications';

    /**
     * Transient key for verification lock (prevents concurrent runs)
     */
    const string VERIFICATION_LOCK = 'wc_mss_verification_running';

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
    private static function clear_api_client_pool(): void {
        self::$api_client_pool = [];
        self::$batch_cache = [];
    }

    /**
     * Run a complete sync verification audit
     *
     * @return array Report data
     */
    public static function run_verification(): array {
        // Check settings first
        $settings = self::get_settings();
        if (!$settings['enabled']) {
            WC_Multi_Store_Logger::write('Weekly verification is disabled in settings, skipping', 'warning');
            return ['error' => 'Verification disabled'];
        }

        // Check if verification is already running (prevent concurrent runs)
        $lock_data = get_transient(self::VERIFICATION_LOCK);
        if ($lock_data) {
            // Check if it's a stale lock (running for more than 1 hour)
            $lock_time = is_numeric($lock_data) ? (int) $lock_data : 0;
            $time_running = $lock_time > 0 ? (time() - $lock_time) : HOUR_IN_SECONDS + 1;

            if ($time_running > HOUR_IN_SECONDS) {
                // Stale lock detected, clear it
                WC_Multi_Store_Logger::write(sprintf(
                    'Clearing stale verification lock (running for %d minutes)',
                    round($time_running / 60)
                ), 'warning');
                delete_transient(self::VERIFICATION_LOCK);
            } else {
                WC_Multi_Store_Logger::write('Verification already running, skipping', 'warning');
                return ['error' => 'Verification already running'];
            }
        }

        // Set lock with timestamp (expires in 2 hours as safety)
        set_transient(self::VERIFICATION_LOCK, time(), 2 * HOUR_IN_SECONDS);

        $start_time = microtime(true);

        WC_Multi_Store_Logger::write('Starting weekly sync verification audit');

        // Note: $settings already loaded at start of function

        // Use cached active stores instead of fetching all stores and filtering
        $active_stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($active_stores)) {
            WC_Multi_Store_Logger::write('No active stores found for verification', 'warning');
            delete_transient(self::VERIFICATION_LOCK);
            return ['error' => 'No active stores'];
        }

        // Determine which products to verify
        $products = self::get_products_to_verify($settings);

        if (empty($products)) {
            WC_Multi_Store_Logger::write('No products found for verification');
            delete_transient(self::VERIFICATION_LOCK);
            return ['error' => 'No products to verify'];
        }

        WC_Multi_Store_Logger::write(sprintf('Verifying %d products across %d stores', count($products), count($active_stores)));

        // Prime post meta cache for all products at once so get_gallery_image_ids(),
        // get_attributes(), get_stock_quantity() etc. hit WP object cache instead of
        // making individual DB queries per product.
        update_meta_cache('post', $products);

        // Batch-prefetch remote product data ahead of the per-product loop:
        // build a lightweight sku/slug → remote-ID index per store (one
        // catalog stream per store — same memory profile as the ghost-scan
        // index build further down, which already proves this is safe for
        // large catalogs), then hydrate full product data in chunks via a
        // single include= API call per chunk per store instead of one live
        // call per product per store (previously up to products × stores
        // calls in a single run). get_remote_product() transparently falls
        // back to its original per-item live-call behavior for anything this
        // didn't resolve (a store that failed to stream, a mid-chunk API
        // error), so a partial prefetch failure degrades to the old
        // (correct, just slower) behavior rather than skewing results.
        self::$batch_cache = [];
        $match_by = WC_Multi_Store_Settings::get_settings()['match_products_by'] ?? 'sku';
        $remote_index = self::build_remote_index($active_stores, $match_by);
        foreach (array_chunk($products, self::REMOTE_BATCH_FETCH_SIZE) as $chunk) {
            self::prefetch_remote_batch_data($chunk, $active_stores, $remote_index, $match_by);
        }

        $report = [
            'started_at' => current_time('mysql'),
            'products_checked' => 0,
            'stores_checked' => count($active_stores),
            'discrepancies_found' => 0,
            'missing_products' => 0,
            'orphan_products' => 0,
            'ghost_products' => 0,
            'stock_mismatches' => 0,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0, // name, status, description, tags, images, attributes, weight, etc.
            'details' => [],
            'stores' => [],
        ];

        // Verify each product
        // Note: Cache is updated immediately when fetching remote product data (in get_remote_product)
        // This ensures cache always has the latest data BEFORE verification comparison
        foreach ($products as $product_id) {
            $product_report = self::verify_product($product_id, $active_stores, $settings);

            if ($product_report) {
                $report['products_checked']++;

                if (!empty($product_report['discrepancies'])) {
                    $report['discrepancies_found'] += count($product_report['discrepancies']);
                    $report['details'][] = $product_report;
                    self::tally_discrepancies_by_type($product_report['discrepancies'], $report);
                }
            }

            // Respect batch limits to avoid timeouts
            if ($report['products_checked'] % 50 === 0) {
                WC_Multi_Store_Logger::write(sprintf('Progress: %d/%d products verified', $report['products_checked'], count($products)));
            }
        }

        // Phase 2: reverse scan — find ghost products on each remote store
        $ghost_results = self::scan_ghost_products($active_stores, $settings);
        $report['ghost_products'] = count($ghost_results);
        $report['discrepancies_found'] += $report['ghost_products'];
        foreach ($ghost_results as $ghost) {
            $report['details'][] = self::build_ghost_product_detail($ghost);
        }

        $report['completed_at'] = current_time('mysql');
        $report['duration_seconds'] = round(microtime(true) - $start_time, 2);
        $report['status'] = 'completed';

        // Clear API client pool to free memory
        self::clear_api_client_pool();

        // Clear verification lock
        delete_transient(self::VERIFICATION_LOCK);

        // Store report in database
        self::save_report($report);

        WC_Multi_Store_Logger::write(sprintf(
            'Weekly verification completed: %d products checked, %d discrepancies found in %.2f seconds',
            $report['products_checked'],
            $report['discrepancies_found'],
            $report['duration_seconds']
        ));

        // Send email notification if enabled
        if ($settings['email_enabled'] && $report['discrepancies_found'] > 0) {
            self::send_email_notification($report);
        }

        // Auto-correct if enabled
        if ($settings['auto_correct'] && $report['discrepancies_found'] > 0) {
            self::auto_correct_discrepancies($report);
        }

        return $report;
    }

    /**
     * Tally a product's discrepancies into the shared report/progress counters.
     * Shared by run_verification() and process_verification_batch(), which each
     * keep their own running totals array ($report / $progress) but tally identically.
     *
     * @param array $discrepancies Discrepancies for one product
     * @param array $counters Report/progress array to increment, by reference
     */
    private static function tally_discrepancies_by_type(array $discrepancies, array &$counters): void {
        foreach ($discrepancies as $discrepancy) {
            match ($discrepancy['type']) {
                'missing' => $counters['missing_products']++,
                'orphan' => $counters['orphan_products']++,
                'stock' => $counters['stock_mismatches']++,
                'price' => $counters['price_mismatches']++,
                'category' => $counters['category_mismatches']++,
                default => $counters['field_mismatches']++,
            };
        }
    }

    /**
     * Build a report "details" entry for a ghost product (exists on a remote
     * store, no local counterpart). Shared by run_verification() and
     * finalize_async_verification().
     *
     * @param array $ghost Ghost product data from scan_ghost_products()
     * @return array Report detail entry
     */
    private static function build_ghost_product_detail(array $ghost): array {
        return [
            'product_id' => null,
            'sku' => $ghost['remote_sku'],
            'name' => $ghost['remote_name'],
            'discrepancies' => [[
                'type' => 'ghost',
                'store_url' => $ghost['store_url'],
                'store_name' => $ghost['store_name'],
                'remote_product_id' => $ghost['remote_id'],
                'remote_sku' => $ghost['remote_sku'],
                'message' => 'Product exists on remote store but has no local counterpart',
            ]],
        ];
    }

    /**
     * Verify a single product across all stores
     *
     * @param int $product_id Product ID
     * @param array $stores Active stores
     * @param array $settings Verification settings
     * @return array|null Product verification report
     */
    private static function verify_product(int $product_id, array $stores, array $settings): ?array {
        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        $sku = $product->get_sku();
        if (empty($sku)) {
            return null; // Skip products without SKU
        }

        $report = [
            'product_id' => $product_id,
            'sku' => $sku,
            'name' => $product->get_name(),
            'discrepancies' => [],
        ];

        $main_stock = $product->get_stock_quantity();
        $main_regular_price = $product->get_regular_price();
        $main_sale_price = $product->get_sale_price();

        // Fetched once (cached in-memory by WC_Multi_Store_Settings) and reused
        // below instead of re-fetching once per store inside the loop — it's a
        // constant for the whole verify_product() call, not per-store data.
        $sync_settings = WC_Multi_Store_Settings::get_settings();

        // Get local category identifiers for category verification
        // Uses the same matching method as the main sync (from main sync settings)
        $main_category_ids = [];
        if ($settings['check_categories']) {
            $match_by = $sync_settings['category_match_by'] ?? 'slug';

            // Get categories based on match method
            $field = ($match_by === 'name') ? 'names' : 'slugs';
            $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => $field]);
            if (!is_wp_error($categories)) {
                $main_category_ids = $categories;
                sort($main_category_ids); // Sort for consistent comparison
            }
        }

        foreach ($stores as $store_url => $store) {
            $store_name = parse_url($store_url, PHP_URL_HOST);

            // Check if this product should be excluded for this store
            $should_be_excluded = self::should_exclude_product($product, $store);

            try {
                $remote_product = self::get_remote_product($product, $store_url, $store);

                // If product should be excluded but exists on remote - it's an orphan
                if ($should_be_excluded) {
                    if ($remote_product) {
                        $excluded_reasons = self::get_exclusion_reasons($product, $store);
                        $remote_id = $remote_product->id ?? null;
                        $report['discrepancies'][] = [
                            'type' => 'orphan',
                            'store_url' => $store_url,
                            'store_name' => $store_name,
                            'remote_product_id' => $remote_id,
                            'message' => 'Product exists on remote but should be excluded',
                            'exclusion_reasons' => $excluded_reasons,
                        ];
                    }
                    // If excluded and not found on remote - that's correct, skip
                    continue;
                }

                // Product should be synced - check if it exists
                if (!$remote_product) {
                    $report['discrepancies'][] = [
                        'type' => 'missing',
                        'store_url' => $store_url,
                        'store_name' => $store_name,
                        'message' => 'Product not found on remote store',
                    ];
                    continue;
                }

                // Check stock if enabled
                if ($settings['check_stock']) {
                    $remote_stock = isset($remote_product->stock_quantity) ? intval($remote_product->stock_quantity) : null;

                    if ($remote_stock !== $main_stock) {
                        $report['discrepancies'][] = [
                            'type' => 'stock',
                            'store_url' => $store_url,
                            'store_name' => $store_name,
                            'field' => 'stock_quantity',
                            'expected' => $main_stock,
                            'actual' => $remote_stock,
                            'difference' => $remote_stock - $main_stock,
                        ];
                    }
                }

                // Check prices if enabled
                if ($settings['check_prices']) {
                    $remote_regular_price = $remote_product->regular_price ?? null;
                    $remote_sale_price = $remote_product->sale_price ?? null;

                    if ($remote_regular_price != $main_regular_price) {
                        $report['discrepancies'][] = [
                            'type' => 'price',
                            'store_url' => $store_url,
                            'store_name' => $store_name,
                            'field' => 'regular_price',
                            'expected' => $main_regular_price,
                            'actual' => $remote_regular_price,
                        ];
                    }

                    if ($remote_sale_price != $main_sale_price) {
                        $report['discrepancies'][] = [
                            'type' => 'price',
                            'store_url' => $store_url,
                            'store_name' => $store_name,
                            'field' => 'sale_price',
                            'expected' => $main_sale_price,
                            'actual' => $remote_sale_price,
                        ];
                    }
                }

                // Check full product fields (name, description, tags, images, attributes, etc.)
                if ($settings['check_full_product'] ?? false) {
                    $full_discrepancies = self::check_full_product_fields(
                        $product, $remote_product, $store_url, $store_name, $sync_settings
                    );
                    array_push($report['discrepancies'], ...$full_discrepancies);
                }

                // Check categories if enabled
                if ($settings['check_categories'] && !empty($main_category_ids)) {
                    // Get remote categories using same match method as main sync
                    $match_by = $sync_settings['category_match_by'] ?? 'slug';

                    $remote_categories = $remote_product->categories ?? [];
                    $remote_category_ids = [];

                    foreach ($remote_categories as $cat) {
                        // Handle both object and array formats from API
                        $value = '';
                        if ($match_by === 'name') {
                            $value = is_object($cat) ? ($cat->name ?? '') : ($cat['name'] ?? '');
                        } else {
                            $value = is_object($cat) ? ($cat->slug ?? '') : ($cat['slug'] ?? '');
                        }
                        if (!empty($value)) {
                            $remote_category_ids[] = $value;
                        }
                    }
                    sort($remote_category_ids); // Sort for consistent comparison

                    // Compare categories
                    $missing_categories = array_diff($main_category_ids, $remote_category_ids);
                    $extra_categories = array_diff($remote_category_ids, $main_category_ids);

                    if (!empty($missing_categories) || !empty($extra_categories)) {
                        $report['discrepancies'][] = [
                            'type' => 'category',
                            'store_url' => $store_url,
                            'store_name' => $store_name,
                            'field' => 'categories',
                            'match_by' => $match_by,
                            'expected' => $main_category_ids,
                            'actual' => $remote_category_ids,
                            'missing' => array_values($missing_categories),
                            'extra' => array_values($extra_categories),
                        ];
                    }
                }

            } catch (Exception $e) {
                $report['discrepancies'][] = [
                    'type' => 'error',
                    'store_url' => $store_url,
                    'store_name' => $store_name,
                    'message' => 'API Error: ' . $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * Compare all full_product fields between a local product and its remote counterpart.
     * Returns an array of discrepancy entries (may be empty).
     *
     * @param WC_Product $product Local product
     * @param object     $remote  Remote product data from the API
     * @param string     $store_url  Store URL (for discrepancy metadata)
     * @param string     $store_name Display name of the store
     * @param array      $sync_settings Main sync settings
     * @return array Discrepancy entries
     */
    private static function check_full_product_fields(
        WC_Product $product,
        object $remote,
        string $store_url,
        string $store_name,
        array $sync_settings
    ): array {
        $match_by = $sync_settings['category_match_by'] ?? 'slug';

        // Each helper below is a pure, independent field-group comparison —
        // no shared state beyond the returned discrepancy arrays, no early
        // returns — so this is a straight concatenation of what used to be
        // one 231-line method, not a behavior change.
        return [
            ...self::compare_scalar_fields($product, $remote, $store_url, $store_name),
            ...self::compare_description_fields($product, $remote, $store_url, $store_name),
            ...self::compare_weight($product, $remote, $store_url, $store_name),
            ...self::compare_dimensions($product, $remote, $store_url, $store_name),
            ...self::compare_tags($product, $remote, $store_url, $store_name, $match_by),
            ...self::compare_images($product, $remote, $store_url, $store_name),
            ...self::compare_attributes($product, $remote, $store_url, $store_name),
        ];
    }

    /** @return array Discrepancy entries for name/status/featured/catalog_visibility/tax_status/tax_class/backorders */
    private static function compare_scalar_fields(WC_Product $product, object $remote, string $store_url, string $store_name): array {
        $discrepancies = [];

        $scalar_fields = [
            'name'               => [$product->get_name(),               $remote->name ?? null],
            'status'             => [$product->get_status(),             $remote->status ?? null],
            'featured'           => [(bool) $product->is_featured(),     isset($remote->featured) ? (bool) $remote->featured : null],
            'catalog_visibility' => [$product->get_catalog_visibility(), $remote->catalog_visibility ?? null],
            'tax_status'         => [$product->get_tax_status(),         $remote->tax_status ?? null],
            'tax_class'          => [$product->get_tax_class(),          $remote->tax_class ?? null],
            'backorders'         => [$product->get_backorders(),         $remote->backorders ?? null],
        ];

        foreach ($scalar_fields as $field => [$local_val, $remote_val]) {
            if ($remote_val === null) {
                continue;
            }
            // Cast both sides to string for a consistent comparison
            if ((string) $local_val !== (string) $remote_val) {
                $discrepancies[] = [
                    'type'       => $field,
                    'store_url'  => $store_url,
                    'store_name' => $store_name,
                    'field'      => $field,
                    'expected'   => $local_val,
                    'actual'     => $remote_val,
                ];
            }
        }

        return $discrepancies;
    }

    /** @return array Discrepancy entries for description/short_description (whitespace-normalized) */
    private static function compare_description_fields(WC_Product $product, object $remote, string $store_url, string $store_name): array {
        $discrepancies = [];

        foreach (['description' => [$product->get_description(), $remote->description ?? null],
                  'short_description' => [$product->get_short_description(), $remote->short_description ?? null]]
                 as $field => [$local_val, $remote_val]) {
            if ($remote_val === null) {
                continue;
            }
            // Normalise line endings and trim to avoid meaningless whitespace diffs
            $local_norm  = trim(preg_replace('/\r\n|\r/', "\n", $local_val));
            $remote_norm = trim(preg_replace('/\r\n|\r/', "\n", $remote_val));
            if ($local_norm !== $remote_norm) {
                $discrepancies[] = [
                    'type'       => $field,
                    'store_url'  => $store_url,
                    'store_name' => $store_name,
                    'field'      => $field,
                    'expected'   => mb_substr($local_norm, 0, 200),
                    'actual'     => mb_substr($remote_norm, 0, 200),
                ];
            }
        }

        return $discrepancies;
    }

    /** @return array Zero or one discrepancy entry for weight */
    private static function compare_weight(WC_Product $product, object $remote, string $store_url, string $store_name): array {
        if (!$product->has_weight()) {
            return [];
        }

        $local_weight  = (float) $product->get_weight();
        $remote_weight = isset($remote->weight) ? (float) $remote->weight : null;
        if ($remote_weight === null || abs($local_weight - $remote_weight) <= 0.0001) {
            return [];
        }

        return [[
            'type'       => 'weight',
            'store_url'  => $store_url,
            'store_name' => $store_name,
            'field'      => 'weight',
            'expected'   => $local_weight,
            'actual'     => $remote_weight,
        ]];
    }

    /** @return array Discrepancy entries for length/width/height */
    private static function compare_dimensions(WC_Product $product, object $remote, string $store_url, string $store_name): array {
        if (!$product->has_dimensions()) {
            return [];
        }

        $dim_map = [
            'length' => (float) $product->get_length(),
            'width'  => (float) $product->get_width(),
            'height' => (float) $product->get_height(),
        ];
        $remote_dims = $remote->dimensions ?? null;
        if (!$remote_dims) {
            return [];
        }
        $remote_dims = (array) $remote_dims;

        $discrepancies = [];
        foreach ($dim_map as $dim => $local_dim) {
            $remote_dim = isset($remote_dims[$dim]) ? (float) $remote_dims[$dim] : null;
            if ($remote_dim !== null && abs($local_dim - $remote_dim) > 0.0001) {
                $discrepancies[] = [
                    'type'       => 'dimensions',
                    'store_url'  => $store_url,
                    'store_name' => $store_name,
                    'field'      => 'dimensions.' . $dim,
                    'expected'   => $local_dim,
                    'actual'     => $remote_dim,
                ];
            }
        }

        return $discrepancies;
    }

    /** @return array Zero or one discrepancy entry for tags */
    private static function compare_tags(WC_Product $product, object $remote, string $store_url, string $store_name, string $match_by): array {
        $tag_ids = $product->get_tag_ids();
        if (empty($tag_ids)) {
            return [];
        }

        $local_tags = [];
        $terms = get_terms(['taxonomy' => 'product_tag', 'include' => $tag_ids, 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $local_tags[] = ($match_by === 'name') ? $term->name : $term->slug;
            }
        }
        sort($local_tags);

        $remote_tags = [];
        foreach ((array) ($remote->tags ?? []) as $t) {
            $t = (array) $t;
            $remote_tags[] = ($match_by === 'name') ? ($t['name'] ?? '') : ($t['slug'] ?? '');
        }
        sort($remote_tags);

        $missing_tags = array_values(array_diff($local_tags, $remote_tags));
        $extra_tags   = array_values(array_diff($remote_tags, $local_tags));

        if (empty($missing_tags) && empty($extra_tags)) {
            return [];
        }

        return [[
            'type'       => 'tag',
            'store_url'  => $store_url,
            'store_name' => $store_name,
            'field'      => 'tags',
            'match_by'   => $match_by,
            'expected'   => $local_tags,
            'actual'     => $remote_tags,
            'missing'    => $missing_tags,
            'extra'      => $extra_tags,
        ]];
    }

    /** @return array Zero or one discrepancy entry for images (compared by sorted filename) */
    private static function compare_images(WC_Product $product, object $remote, string $store_url, string $store_name): array {
        $local_images = [];
        $image_id = $product->get_image_id();
        if ($image_id) {
            $url = wp_get_attachment_url($image_id);
            if ($url) {
                $local_images[] = basename(strtok($url, '?'));
            }
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $url = wp_get_attachment_url($gid);
            if ($url) {
                $local_images[] = basename(strtok($url, '?'));
            }
        }
        sort($local_images);

        $remote_images = [];
        foreach ((array) ($remote->images ?? []) as $img) {
            $img = (array) $img;
            if (!empty($img['src'])) {
                $remote_images[] = basename(strtok($img['src'], '?'));
            }
        }
        sort($remote_images);

        if ($local_images === $remote_images) {
            return [];
        }

        return [[
            'type'       => 'image',
            'store_url'  => $store_url,
            'store_name' => $store_name,
            'field'      => 'images',
            'expected'   => $local_images,
            'actual'     => $remote_images,
            'missing'    => array_values(array_diff($local_images, $remote_images)),
            'extra'      => array_values(array_diff($remote_images, $local_images)),
        ]];
    }

    /** @return array Zero or one discrepancy entry for attributes (compared by name + sorted options) */
    private static function compare_attributes(WC_Product $product, object $remote, string $store_url, string $store_name): array {
        $local_attrs = [];
        foreach ($product->get_attributes() as $key => $attribute) {
            if ($attribute instanceof WC_Product_Attribute) {
                $name = $attribute->is_taxonomy()
                    ? ($attribute->get_taxonomy_object()->attribute_label ?? $attribute->get_name())
                    : $attribute->get_name();
                $options = $attribute->is_taxonomy()
                    ? wp_list_pluck($attribute->get_terms(), 'name')
                    : $attribute->get_options();
            } else {
                $name    = is_string($key) ? $key : (string) $attribute;
                $options = is_array($attribute) ? $attribute : [(string) $attribute];
            }
            sort($options);
            $local_attrs[strtolower($name)] = $options;
        }
        ksort($local_attrs);

        $remote_attrs = [];
        foreach ((array) ($remote->attributes ?? []) as $attr) {
            $attr    = (array) $attr;
            $name    = strtolower($attr['name'] ?? '');
            $options = (array) ($attr['options'] ?? []);
            sort($options);
            $remote_attrs[$name] = $options;
        }
        ksort($remote_attrs);

        if ($local_attrs === $remote_attrs) {
            return [];
        }

        return [[
            'type'       => 'attribute',
            'store_url'  => $store_url,
            'store_name' => $store_name,
            'field'      => 'attributes',
            'expected'   => $local_attrs,
            'actual'     => $remote_attrs,
        ]];
    }

    /**
     * Get remote product from store and cache it immediately
     * Caching happens BEFORE verification comparison, so cache always has latest remote data
     *
     * @param WC_Product $product Local product
     * @param string $store_url Store URL
     * @param array $store Store configuration
     * @return object|null Remote product data
     */
    private static function get_remote_product(WC_Product $product, string $store_url, array $store): ?object {
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
    private static function build_remote_index(array $active_stores, string $match_by): array {
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
    private static function load_search_values_for_products(array $product_ids, string $match_by): array {
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
    private static function prefetch_remote_batch_data(array $product_ids, array $active_stores, array $remote_index, string $match_by): void {
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
     * Check if product should be excluded for a specific store
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return bool True if product should be excluded
     */
    private static function should_exclude_product(WC_Product $product, array $store_config): bool {
        return WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config);
    }

    /**
     * Get human-readable exclusion reasons for a product
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return array List of exclusion reasons
     */
    private static function get_exclusion_reasons(WC_Product $product, array $store_config): array {
        return WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store_config);
    }

    /**
     * Get products to verify based on settings
     *
     * @param array $settings Verification settings
     * @return array Product IDs
     */
    private static function get_products_to_verify(array $settings): array {
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
    private static function build_exclusion_tax_query(array $active_stores): array {
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
     * Save verification report to database
     *
     * @param array $report Report data
     * @return int|false Report ID or false on failure
     */
    private static function save_report(array $report): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->insert(
            $table_name,
            [
                'started_at' => $report['started_at'],
                'completed_at' => $report['completed_at'],
                'duration_seconds' => $report['duration_seconds'],
                'products_checked' => $report['products_checked'],
                'stores_checked' => $report['stores_checked'],
                'discrepancies_found' => $report['discrepancies_found'],
                'missing_products' => $report['missing_products'],
                'stock_mismatches' => $report['stock_mismatches'],
                'price_mismatches' => $report['price_mismatches'],
                'category_mismatches' => $report['category_mismatches'] ?? 0,
                'status' => $report['status'],
                'report_data' => maybe_serialize($report),
            ],
            ['%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s']
        );
    }

    /**
     * Get verification reports
     *
     * @param array $args Query arguments
     * @return array Reports
     */
    public static function get_reports(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $defaults = [
            'limit' => 10,
            'offset' => 0,
            'orderby' => 'started_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $allowed_columns = ['id', 'started_at', 'completed_at', 'duration_seconds', 'products_checked', 'stores_checked', 'discrepancies_found', 'status'];
        if (!in_array($args['orderby'], $allowed_columns, true)) {
            $args['orderby'] = 'started_at';
        }
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'started_at DESC';
        }
        $limit = absint($args['limit']);
        $offset = absint($args['offset']);

        $query = "SELECT * FROM {$table_name} ORDER BY {$orderby} LIMIT {$limit} OFFSET {$offset}";

        $results = $wpdb->get_results($query, ARRAY_A);

        // Unserialize report data
        foreach ($results as &$result) {
            if (isset($result['report_data'])) {
                $result['report_data'] = maybe_unserialize($result['report_data']);
            }
        }

        return $results;
    }

    /**
     * Get a single report by ID
     *
     * @param int $report_id Report ID
     * @return array|null Report data
     */
    public static function get_report(int $report_id): ?array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $report = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $report_id
        ), ARRAY_A);

        if ($report && isset($report['report_data'])) {
            $report['report_data'] = maybe_unserialize($report['report_data']);
        }

        return $report;
    }

    /**
     * Get latest report
     *
     * @return array|null Latest report
     */
    public static function get_latest_report(): ?array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists to prevent database errors
        if (!self::table_exists()) {
            return null;
        }

        $report = $wpdb->get_row(
            "SELECT * FROM {$table_name} ORDER BY started_at DESC LIMIT 1",
            ARRAY_A
        );

        if ($report && isset($report['report_data'])) {
            $report['report_data'] = maybe_unserialize($report['report_data']);
        }

        return $report;
    }

    /**
     * Check if the verification reports table exists
     *
     * @return bool True if table exists
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * Delete old reports
     *
     * @param int $days Keep reports from last X days (default: 90)
     * @return int|false Number of rows deleted or false on failure
     */
    public static function cleanup_old_reports(int $days = 90): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE started_at < %s",
            $date_threshold
        ));
    }

    /**
     * Auto-correct discrepancies found in report
     * Limited to prevent queue overflow
     *
     * @param array $report Verification report
     * @return int Number of products queued for correction
     */
    private static function auto_correct_discrepancies(array $report): int {
        $queued = 0;

        if (empty($report['details'])) {
            return 0;
        }

        // Get limit from settings (default: 500 products max)
        $settings = self::get_settings();
        $max_auto_correct = (int) ($settings['auto_correct_limit'] ?? 500);

        // If limit is 0, no limit (but log a warning)
        if ($max_auto_correct === 0) {
            $max_auto_correct = PHP_INT_MAX;
            WC_Multi_Store_Logger::write('Auto-correct limit is 0 (unlimited) - this may cause queue overflow', 'warning');
        }

        // Determine sync type override based on weekly settings
        $sync_type_override = null;
        $weekly_sync_type = $settings['weekly_sync_type'] ?? 'use_default';
        if ($weekly_sync_type !== 'use_default') {
            $sync_type_override = $weekly_sync_type;
        }

        $skipped = 0;

        foreach ($report['details'] as $product_report) {
            if (!empty($product_report['discrepancies'])) {
                // Ghost products (product_id = null) exist only on remote — they can't be
                // corrected by re-syncing a local product; skip them here.
                if (empty($product_report['product_id'])) {
                    continue;
                }

                // Check limit
                if ($queued >= $max_auto_correct) {
                    $skipped++;
                    continue;
                }

                // Queue product for sync with high priority
                // Parameters: product_id, trigger, priority, sync_type_override
                WC_MSS()->queue_manager->add_product(
                    $product_report['product_id'],
                    'weekly_verification_correction',
                    1, // Highest priority
                    $sync_type_override
                );
                $queued++;
            }
        }

        if ($skipped > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Auto-correction: %d products queued, %d skipped (limit: %d, sync_type: %s)',
                $queued,
                $skipped,
                $max_auto_correct,
                $sync_type_override ?: 'default'
            ), 'warning');
        } else {
            WC_Multi_Store_Logger::write(sprintf(
                'Auto-correction: %d products queued for sync (sync_type: %s)',
                $queued,
                $sync_type_override ?: 'default'
            ));
        }

        return $queued;
    }

    /**
     * Send email notification about verification results
     *
     * @param array $report Verification report
     * @return bool Success
     */
    private static function send_email_notification(array $report): bool {
        $settings = self::get_settings();

        if (empty($settings['email_recipients'])) {
            return false;
        }

        $to = array_map('trim', explode(',', $settings['email_recipients']));
        $subject = sprintf(
            '[%s] Weekly Sync Verification: %d discrepancies found',
            get_bloginfo('name'),
            $report['discrepancies_found']
        );

        $message = self::format_email_message($report);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $message, $headers);

        if ($sent) {
            WC_Multi_Store_Logger::write('Email notification sent to: ' . implode(', ', $to));
        } else {
            WC_Multi_Store_Logger::write('Failed to send email notification', 'error');
        }

        return $sent;
    }

    /**
     * Format email message for verification report
     *
     * @param array $report Verification report
     * @return string HTML email content
     */
    private static function format_email_message(array $report): string {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #0073aa; color: white; padding: 20px; }
                .content { padding: 20px; }
                .summary { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .summary-item { display: inline-block; margin-right: 30px; }
                .summary-label { font-weight: bold; }
                .discrepancy { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 10px 0; }
                .footer { background: #f5f5f5; padding: 20px; margin-top: 30px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Weekly Sync Verification Report</h1>
            </div>
            <div class="content">
                <div class="summary">
                    <div class="summary-item">
                        <span class="summary-label">Products Checked:</span> <?php echo $report['products_checked']; ?>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Stores Checked:</span> <?php echo $report['stores_checked']; ?>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Discrepancies Found:</span> <?php echo $report['discrepancies_found']; ?>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Duration:</span> <?php echo $report['duration_seconds']; ?>s
                    </div>
                </div>

                <h2>Breakdown</h2>
                <ul>
                    <li><strong>Missing Products:</strong> <?php echo $report['missing_products']; ?></li>
                    <li><strong>Orphan Products:</strong> <?php echo $report['orphan_products'] ?? 0; ?> <em>(exist on remote but should be excluded)</em></li>
                    <li><strong>Ghost Products:</strong> <?php echo $report['ghost_products'] ?? 0; ?> <em>(exist on remote but not in local catalogue)</em></li>
                    <li><strong>Stock Mismatches:</strong> <?php echo $report['stock_mismatches']; ?></li>
                    <li><strong>Price Mismatches:</strong> <?php echo $report['price_mismatches']; ?></li>
                    <li><strong>Category Mismatches:</strong> <?php echo $report['category_mismatches'] ?? 0; ?></li>
                    <li><strong>Field Mismatches:</strong> <?php echo $report['field_mismatches'] ?? 0; ?> <em>(name, description, tags, images, attributes, weight, dimensions, tax, status, etc.)</em></li>
                </ul>

                <?php if (!empty($report['details'])): ?>
                    <h2>Discrepancy Details (Top 10)</h2>
                    <?php
                    $count = 0;
                    foreach ($report['details'] as $product_report):
                        if ($count >= 10) break;
                        if (!empty($product_report['discrepancies'])):
                            $count++;
                    ?>
                        <div class="discrepancy">
                            <strong><?php echo esc_html($product_report['name']); ?></strong> (SKU: <?php echo esc_html($product_report['sku']); ?>)<br>
                            <ul>
                                <?php foreach ($product_report['discrepancies'] as $disc): ?>
                                    <li>
                                        <strong><?php echo esc_html($disc['store_name']); ?>:</strong>
                                        <?php echo self::format_discrepancy_message($disc); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php
                        endif;
                    endforeach;
                    ?>
                <?php endif; ?>

                <p><a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=weekly-verification'); ?>">View Full Report in Dashboard</a></p>
            </div>
            <div class="footer">
                <p>This is an automated message from WooCommerce Multi-Store Sync plugin.</p>
                <p>Site: <?php echo get_bloginfo('name'); ?> (<?php echo get_site_url(); ?>)</p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Format a single discrepancy entry into a display string (pre-escaped HTML).
     * Shared by the email report (format_email_message()) and the admin
     * dashboard's "Sample Discrepancies" view (admin/views/weekly-verification.php).
     *
     * The admin view previously only handled missing/orphan/stock/price and fell
     * through to an unset $disc['message'] for every other type (ghost/tag/image/
     * category/attribute/generic field) — those types never set a 'message' key
     * (see verify_product()/compare_*() below), so it silently rendered blank.
     * This brings it to parity with the richer formatting the email already had.
     *
     * @param array $disc Discrepancy entry
     * @return string Formatted HTML for one discrepancy line
     */
    public static function format_discrepancy_message(array $disc): string {
        return match (true) {
            $disc['type'] === 'missing' => __('Product not found', 'wc-multi-store-sync'),
            $disc['type'] === 'orphan' => self::format_orphan_discrepancy_message($disc),
            $disc['type'] === 'ghost' => self::format_ghost_discrepancy_message($disc),
            $disc['type'] === 'stock' => sprintf(
                /* translators: 1: expected stock, 2: actual stock, 3: signed difference */
                __('Stock mismatch - Expected: %1$d, Actual: %2$d (Diff: %3$+d)', 'wc-multi-store-sync'),
                $disc['expected'],
                $disc['actual'],
                $disc['difference']
            ),
            $disc['type'] === 'price' => sprintf(
                /* translators: 1: field name, 2: expected value, 3: actual value */
                __('%1$s mismatch - Expected: %2$s, Actual: %3$s', 'wc-multi-store-sync'),
                ucfirst($disc['field']),
                $disc['expected'],
                $disc['actual']
            ),
            in_array($disc['type'], ['tag', 'image', 'category'], true) => self::format_list_discrepancy_message($disc),
            $disc['type'] === 'attribute' => __('Attribute mismatch', 'wc-multi-store-sync'),
            default => self::format_generic_field_discrepancy_message($disc),
        };
    }

    private static function format_orphan_discrepancy_message(array $disc): string {
        $message = '<strong style="color: #d63638;">' . __('ORPHAN', 'wc-multi-store-sync') . '</strong> - '
            . __('Product exists but should be excluded', 'wc-multi-store-sync');

        if (!empty($disc['exclusion_reasons'])) {
            $message .= ' (' . esc_html(implode(', ', $disc['exclusion_reasons'])) . ')';
        }

        return $message;
    }

    private static function format_ghost_discrepancy_message(array $disc): string {
        $message = '<strong style="color: #8c3800;">' . __('GHOST', 'wc-multi-store-sync') . '</strong> - '
            . __('Remote product has no local counterpart', 'wc-multi-store-sync');

        if (!empty($disc['remote_sku'])) {
            $message .= ' (SKU: ' . esc_html($disc['remote_sku']) . ')';
        }

        return $message;
    }

    private static function format_list_discrepancy_message(array $disc): string {
        $message = esc_html(ucfirst($disc['type'])) . ' ' . __('mismatch', 'wc-multi-store-sync');

        if (!empty($disc['missing'])) {
            $message .= ' — ' . __('missing:', 'wc-multi-store-sync') . ' ' . esc_html(implode(', ', (array) $disc['missing']));
        }

        if (!empty($disc['extra'])) {
            $message .= ' — ' . __('extra:', 'wc-multi-store-sync') . ' ' . esc_html(implode(', ', (array) $disc['extra']));
        }

        return $message;
    }

    private static function format_generic_field_discrepancy_message(array $disc): string {
        $expected = is_scalar($disc['expected']) ? $disc['expected'] : json_encode($disc['expected']);
        $actual   = is_scalar($disc['actual'])   ? $disc['actual']   : json_encode($disc['actual']);

        return esc_html(ucfirst(str_replace('_', ' ', $disc['field'] ?? $disc['type'])))
            . ' ' . __('mismatch', 'wc-multi-store-sync') . ' — Expected: ' . esc_html(mb_substr((string) $expected, 0, 80))
            . ', Actual: ' . esc_html(mb_substr((string) $actual, 0, 80));
    }

    /**
     * Get verification settings
     *
     * @return array Settings
     */
    public static function get_settings(): array {
        $defaults = [
            'enabled' => false,
            'schedule' => 'weekly',
            'day_of_week' => 1, // Monday
            'time_of_day' => '02:00',
            'check_stock' => true,
            'check_prices' => true,
            'product_limit' => 0, // 0 = all products
            'batch_size' => 20, // Products per batch
            'sample_mode' => 'all', // all, recent, random, modified
            'auto_correct' => false,
            'email_enabled' => false,
            'email_recipients' => get_option('admin_email'),
        ];

        $settings = get_option('wc_multi_store_sync_weekly_verification', []);
        $merged = wp_parse_args($settings, $defaults);

        // Derive field-check flags from main sync settings
        $sync_settings = WC_Multi_Store_Settings::get_settings();
        $merged['check_categories'] = ($sync_settings['sync_type_default'] === 'full_product');
        $merged['check_full_product'] = ($sync_settings['sync_type_default'] === 'full_product');

        return $merged;
    }

    /**
     * Update verification settings
     *
     * @param array $settings Settings to update
     * @return bool Success
     */
    public static function update_settings(array $settings): bool {
        $current = self::get_settings();
        $updated = array_merge($current, $settings);

        return update_option('wc_multi_store_sync_weekly_verification', $updated);
    }

    /**
     * Create database table for verification reports
     *
     * @return void
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            started_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            duration_seconds decimal(10,2) DEFAULT 0,
            products_checked int(11) NOT NULL DEFAULT 0,
            stores_checked int(11) NOT NULL DEFAULT 0,
            discrepancies_found int(11) NOT NULL DEFAULT 0,
            missing_products int(11) NOT NULL DEFAULT 0,
            stock_mismatches int(11) NOT NULL DEFAULT 0,
            price_mismatches int(11) NOT NULL DEFAULT 0,
            category_mismatches int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'running',
            report_data longtext,
            PRIMARY KEY  (id),
            KEY started_at (started_at),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        WC_Multi_Store_Logger::write('Weekly verification reports table created/updated');
    }

    /**
     * Schedule the weekly verification
     *
     * @return void
     */
    public static function schedule_verification(): void {
        $settings = self::get_settings();

        if (!$settings['enabled']) {
            self::unschedule_verification();
            return;
        }

        // Calculate next run time based on settings
        $next_run = self::calculate_next_run_time($settings);

        // Unschedule existing action
        as_unschedule_all_actions('wc_multi_store_weekly_verification', [], 'wc_multi_store_sync');

        // Schedule new recurring action
        as_schedule_recurring_action(
            $next_run,
            WEEK_IN_SECONDS,
            'wc_multi_store_weekly_verification',
            [],
            'wc_multi_store_sync'
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Weekly verification scheduled for %s',
            date('Y-m-d H:i:s', $next_run)
        ));
    }

    /**
     * Unschedule the weekly verification
     *
     * @return void
     */
    public static function unschedule_verification(): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wc_multi_store_weekly_verification', [], 'wc_multi_store_sync');
        }
        WC_Multi_Store_Logger::write('Weekly verification unscheduled');
    }

    /**
     * Calculate next run time based on settings
     *
     * @param array $settings Verification settings
     * @return int Timestamp
     */
    private static function calculate_next_run_time(array $settings): int {
        $day_of_week = intval($settings['day_of_week'] ?? 1);
        $time_of_day = $settings['time_of_day'] ?? '02:00';
        [$hour, $minute] = array_map('intval', explode(':', $time_of_day));

        $now = new DateTimeImmutable('now');
        $days_until_target = ($day_of_week - (int) $now->format('w') + 7) % 7;

        $next_occurrence = $now->modify("+{$days_until_target} days")->setTime($hour, $minute, 0);

        // If today is the target day and today's target time has already
        // passed, roll over to next week (matches strtotime("next {day}")'s
        // behavior of always skipping today, even when today is the target day).
        if ($days_until_target === 0 && $next_occurrence <= $now) {
            $next_occurrence = $next_occurrence->modify('+7 days');
        }

        return $next_occurrence->getTimestamp();
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false Timestamp or false if not scheduled
     */
    public static function get_next_scheduled_time(): int|false {
        return as_next_scheduled_action('wc_multi_store_weekly_verification', [], 'wc_multi_store_sync');
    }

    /**
     * Extract orphan products from a verification report
     *
     * @param array|null $report Optional report data. If null, uses latest report.
     * @param bool $require_remote_id If true, only return orphans with remote_product_id
     * @return array Array of orphan products with store_url, remote_product_id, product_id, sku
     */
    public static function get_orphan_products_from_report(?array $report = null, bool $require_remote_id = false): array {
        if ($report === null) {
            $latest = self::get_latest_report();
            if (!$latest || empty($latest['report_data'])) {
                return [];
            }
            $report = is_array($latest['report_data']) ? $latest['report_data'] : maybe_unserialize($latest['report_data']);
        }

        if (empty($report['details'])) {
            return [];
        }

        $orphans = [];

        foreach ($report['details'] as $product_report) {
            if (empty($product_report['discrepancies'])) {
                continue;
            }

            foreach ($product_report['discrepancies'] as $discrepancy) {
                if ($discrepancy['type'] !== 'orphan') {
                    continue;
                }

                // Skip orphans without remote_product_id if required
                if ($require_remote_id && empty($discrepancy['remote_product_id'])) {
                    continue;
                }

                $orphans[] = [
                    'product_id' => $product_report['product_id'],
                    'sku' => $product_report['sku'],
                    'name' => $product_report['name'],
                    'store_url' => $discrepancy['store_url'],
                    'store_name' => $discrepancy['store_name'] ?? '',
                    'remote_product_id' => $discrepancy['remote_product_id'] ?? null,
                    'exclusion_reasons' => $discrepancy['exclusion_reasons'] ?? [],
                ];
            }
        }

        return $orphans;
    }

    // =========================================================================
    // ASYNC BATCH VERIFICATION (for manual trigger to avoid timeouts)
    // =========================================================================

    /**
     * Transient key for async verification progress
     */
    const string ASYNC_PROGRESS_TRANSIENT = 'wc_mss_async_verification_progress';

    /**
     * Action hook for batch processing
     */
    const string ASYNC_BATCH_HOOK = 'wc_mss_async_verification_batch';

    /**
     * Get batch size for async verification (uses weekly verification settings)
     *
     * @return int Batch size from settings
     */
    private static function get_async_batch_size(): int {
        $settings = self::get_settings();
        return (int) ($settings['batch_size'] ?? 20);
    }

    /**
     * Schedule async verification (called from admin UI)
     *
     * @return array Result with status
     */
    public static function schedule_async_verification(): array {
        WC_Multi_Store_Logger::write('schedule_async_verification called');

        // Check if verification is already running
        $progress = self::get_verification_progress();
        if ($progress && $progress['status'] === 'running') {
            // Check if it's stale (no activity for more than 30 minutes)
            // Use last_activity if available (more accurate), otherwise fall back to started_at
            $last_activity = $progress['last_activity'] ?? strtotime($progress['started_at'] ?? 'now');
            $time_since_activity = time() - $last_activity;
            $max_stale_time = 30 * MINUTE_IN_SECONDS;

            if ($time_since_activity > $max_stale_time) {
                // Stale verification detected, clear it
                WC_Multi_Store_Logger::write(sprintf(
                    'Clearing stale async verification (no activity for %d minutes, processed %d/%d products)',
                    round($time_since_activity / 60),
                    $progress['processed_products'] ?? 0,
                    $progress['total_products'] ?? 0
                ), 'warning');
                delete_transient(self::ASYNC_PROGRESS_TRANSIENT);

                // Also cancel any pending batch actions
                if (function_exists('as_unschedule_all_actions')) {
                    as_unschedule_all_actions(self::ASYNC_BATCH_HOOK, null, 'wc_multi_store_sync');
                }
            } else {
                WC_Multi_Store_Logger::write('Verification already running, aborting');
                return [
                    'success' => false,
                    'message' => __('Verification is already running.', 'wc-multi-store-sync'),
                ];
            }
        }

        // Check Action Scheduler availability
        if (!function_exists('as_schedule_single_action')) {
            WC_Multi_Store_Logger::write('Action Scheduler not available', 'error');
            return [
                'success' => false,
                'message' => __('Action Scheduler not available.', 'wc-multi-store-sync'),
            ];
        }

        $settings = self::get_settings();

        // Use cached active stores
        $active_stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($active_stores)) {
            WC_Multi_Store_Logger::write('No active stores found for verification', 'warning');
            return [
                'success' => false,
                'message' => __('No active stores found.', 'wc-multi-store-sync'),
            ];
        }

        WC_Multi_Store_Logger::write(sprintf('Found %d active stores for verification', count($active_stores)));

        // Get products to verify
        $products = self::get_products_to_verify($settings);

        if (empty($products)) {
            WC_Multi_Store_Logger::write('No products found for verification', 'warning');
            return [
                'success' => false,
                'message' => __('No products found to verify.', 'wc-multi-store-sync'),
            ];
        }

        WC_Multi_Store_Logger::write(sprintf('Found %d products for verification', count($products)));

        // Get batch size at start time (stays consistent throughout verification)
        $batch_size = self::get_async_batch_size();

        // Initialize progress tracking
        $progress_data = [
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'last_activity' => time(),
            'total_products' => count($products),
            'processed_products' => 0,
            'current_batch' => 0,
            'batch_size' => $batch_size,
            'total_batches' => ceil(count($products) / $batch_size),
            'discrepancies_found' => 0,
            'missing_products' => 0,
            'orphan_products' => 0,
            'stock_mismatches' => 0,
            'price_mismatches' => 0,
            'category_mismatches' => 0,
            'field_mismatches' => 0,
            'stores_checked' => count($active_stores),
            'details' => [],
            'product_ids' => $products,
        ];

        set_transient(self::ASYNC_PROGRESS_TRANSIENT, $progress_data, HOUR_IN_SECONDS);

        WC_Multi_Store_Logger::write(sprintf(
            'Async verification scheduled: %d products in %d batches (batch size: %d)',
            count($products),
            $progress_data['total_batches'],
            $batch_size
        ));

        // Schedule first batch immediately
        // Use positional array for Action Scheduler compatibility
        $action_id = as_schedule_single_action(
            time(),
            self::ASYNC_BATCH_HOOK,
            [0], // batch number as positional argument
            'wc_multi_store_sync'
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Scheduled async verification batch action (ID: %s)',
            $action_id ?: 'unknown'
        ));

        return [
            'success' => true,
            'message' => sprintf(
                __('Verification started: %d products will be checked in %d batches.', 'wc-multi-store-sync'),
                count($products),
                $progress_data['total_batches']
            ),
            'total_products' => count($products),
            'total_batches' => $progress_data['total_batches'],
        ];
    }

    /**
     * Process a single batch of verification (called by Action Scheduler)
     *
     * @param int $batch_number Current batch number (0-indexed)
     */
    public static function process_verification_batch(int $batch_number): void {
        WC_Multi_Store_Logger::write(sprintf('process_verification_batch called with batch_number: %d', $batch_number));

        $progress = get_transient(self::ASYNC_PROGRESS_TRANSIENT);

        if (!$progress || $progress['status'] !== 'running') {
            WC_Multi_Store_Logger::write(sprintf(
                'Async verification batch skipped: no active verification (progress: %s, status: %s)',
                $progress ? 'exists' : 'null',
                $progress ? ($progress['status'] ?? 'unknown') : 'N/A'
            ));
            return;
        }

        $settings = self::get_settings();

        // Use cached active stores
        $active_stores = WC_Multi_Store_Settings::get_active_stores();

        // Get products for this batch (use stored batch size for consistency)
        $batch_size = (int) ($progress['batch_size'] ?? self::get_async_batch_size());
        $offset = $batch_number * $batch_size;
        $batch_products = array_slice($progress['product_ids'], $offset, $batch_size);

        if (empty($batch_products)) {
            // No more products, finalize
            self::finalize_async_verification();
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Processing verification batch %d/%d (%d products)',
            $batch_number + 1,
            $progress['total_batches'],
            count($batch_products)
        ));

        // Process each product in the batch
        // Note: Cache is updated immediately when fetching remote product data (in get_remote_product)
        foreach ($batch_products as $product_id) {
            $product_report = self::verify_product($product_id, $active_stores, $settings);

            if ($product_report) {
                $progress['processed_products']++;

                if (!empty($product_report['discrepancies'])) {
                    $progress['discrepancies_found'] += count($product_report['discrepancies']);
                    $progress['details'][] = $product_report;
                    self::tally_discrepancies_by_type($product_report['discrepancies'], $progress);
                }
            }
        }

        $progress['current_batch'] = $batch_number + 1;
        $progress['last_activity'] = time(); // Update activity timestamp

        // Save progress with extended expiry
        set_transient(self::ASYNC_PROGRESS_TRANSIENT, $progress, 2 * HOUR_IN_SECONDS);

        // Schedule next batch if there are more products
        $next_batch = $batch_number + 1;
        if ($next_batch < $progress['total_batches']) {
            as_schedule_single_action(
                time(),
                self::ASYNC_BATCH_HOOK,
                [$next_batch], // batch number as positional argument
                'wc_multi_store_sync'
            );
        } else {
            // All batches done, finalize
            self::finalize_async_verification();
        }
    }

    /**
     * Finalize async verification and save report
     */
    private static function finalize_async_verification(): void {
        $progress = get_transient(self::ASYNC_PROGRESS_TRANSIENT);

        if (!$progress) {
            return;
        }

        $settings = self::get_settings();

        // Ghost product reverse scan (done once at finalization, not per batch)
        $active_stores = WC_Multi_Store_Settings::get_active_stores();
        $ghost_results = self::scan_ghost_products($active_stores, $settings);
        $ghost_details = [];
        foreach ($ghost_results as $ghost) {
            $ghost_details[] = self::build_ghost_product_detail($ghost);
        }

        // Create final report
        $report = [
            'started_at' => $progress['started_at'],
            'completed_at' => current_time('mysql'),
            'products_checked' => $progress['processed_products'],
            'stores_checked' => $progress['stores_checked'],
            'discrepancies_found' => $progress['discrepancies_found'] + count($ghost_results),
            'missing_products' => $progress['missing_products'],
            'orphan_products' => $progress['orphan_products'],
            'ghost_products' => count($ghost_results),
            'stock_mismatches' => $progress['stock_mismatches'],
            'price_mismatches' => $progress['price_mismatches'],
            'category_mismatches' => $progress['category_mismatches'] ?? 0,
            'field_mismatches' => $progress['field_mismatches'] ?? 0,
            'details' => [...$progress['details'], ...$ghost_details],
            'status' => 'completed',
        ];

        // Calculate duration
        $start = strtotime($progress['started_at']);
        $end = strtotime($report['completed_at']);
        $report['duration_seconds'] = $end - $start;

        // Clear API client pool to free memory
        self::clear_api_client_pool();

        // Save report to database
        self::save_report($report);

        WC_Multi_Store_Logger::write(sprintf(
            'Async verification completed: %d products checked, %d discrepancies found in %d seconds',
            $report['products_checked'],
            $report['discrepancies_found'],
            $report['duration_seconds']
        ));

        // Send email notification if enabled
        if ($settings['email_enabled'] && $report['discrepancies_found'] > 0) {
            self::send_email_notification($report);
        }

        // Auto-correct if enabled
        if ($settings['auto_correct'] && $report['discrepancies_found'] > 0) {
            self::auto_correct_discrepancies($report);
        }

        // Note: We no longer clear term cache here as it causes "No cache yet" in System Health UI
        // The term cache is cleared by daily maintenance instead, which allows time for re-population
        // during normal sync operations before the next maintenance run

        // Update progress to completed
        $progress['status'] = 'completed';
        $progress['completed_at'] = $report['completed_at'];
        set_transient(self::ASYNC_PROGRESS_TRANSIENT, $progress, 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Get current verification progress
     *
     * @return array|null Progress data or null if no verification running
     */
    public static function get_verification_progress(): ?array {
        $progress = get_transient(self::ASYNC_PROGRESS_TRANSIENT);
        return $progress ?: null;
    }

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
    private static function scan_ghost_products(array $active_stores, array $settings): array {
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

    /**
     * Cancel running async verification
     *
     * @return bool Success
     */
    public static function cancel_async_verification(): bool {
        $progress = get_transient(self::ASYNC_PROGRESS_TRANSIENT);

        if (!$progress || $progress['status'] !== 'running') {
            return false;
        }

        // Cancel pending batch actions
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ASYNC_BATCH_HOOK, null, 'wc_multi_store_sync');
        }

        // Update progress to cancelled
        $progress['status'] = 'cancelled';
        $progress['cancelled_at'] = current_time('mysql');
        set_transient(self::ASYNC_PROGRESS_TRANSIENT, $progress, 5 * MINUTE_IN_SECONDS);

        WC_Multi_Store_Logger::write('Async verification cancelled by user');

        return true;
    }
}
