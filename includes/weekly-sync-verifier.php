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
     * Transient key for verification lock (prevents concurrent runs)
     */
    const string VERIFICATION_LOCK = 'wc_mss_verification_running';

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
        $products = WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::get_products_to_verify($settings);

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
        $remote_index = WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::build_remote_index($active_stores, $match_by);
        foreach (array_chunk($products, self::REMOTE_BATCH_FETCH_SIZE) as $chunk) {
            WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::prefetch_remote_batch_data($chunk, $active_stores, $remote_index, $match_by);
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
        $ghost_results = WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::scan_ghost_products($active_stores, $settings);
        $report['ghost_products'] = count($ghost_results);
        $report['discrepancies_found'] += $report['ghost_products'];
        foreach ($ghost_results as $ghost) {
            $report['details'][] = self::build_ghost_product_detail($ghost);
        }

        $report['completed_at'] = current_time('mysql');
        $report['duration_seconds'] = round(microtime(true) - $start_time, 2);
        $report['status'] = 'completed';

        // Clear API client pool to free memory
        WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::clear_api_client_pool();

        // Clear verification lock
        delete_transient(self::VERIFICATION_LOCK);

        // Store report in database
        WC_Multi_Store_Weekly_Verification_Report_Repository::save_report($report);

        WC_Multi_Store_Logger::write(sprintf(
            'Weekly verification completed: %d products checked, %d discrepancies found in %.2f seconds',
            $report['products_checked'],
            $report['discrepancies_found'],
            $report['duration_seconds']
        ));

        // Send email notification if enabled
        if ($settings['email_enabled'] && $report['discrepancies_found'] > 0) {
            WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($report);
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
                $remote_product = WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::get_remote_product($product, $store_url, $store);

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
     * Get verification reports
     *
     * @param array $args Query arguments
     * @return array Reports
     */
    public static function get_reports(array $args = []): array {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_reports($args);
    }

    /**
     * Get a single report by ID
     *
     * @param int $report_id Report ID
     * @return array|null Report data
     */
    public static function get_report(int $report_id): ?array {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_report($report_id);
    }

    /**
     * Get latest report
     *
     * @return array|null Latest report
     */
    public static function get_latest_report(): ?array {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_latest_report();
    }

    /**
     * Check if the verification reports table exists
     *
     * @return bool True if table exists
     */
    public static function table_exists(): bool {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::table_exists();
    }

    /**
     * Delete old reports
     *
     * @param int $days Keep reports from last X days (default: 90)
     * @return int|false Number of rows deleted or false on failure
     */
    public static function cleanup_old_reports(int $days = 90): int|false {
        return WC_Multi_Store_Weekly_Verification_Report_Repository::cleanup_old_reports($days);
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
     * Format a single discrepancy entry into a display string (pre-escaped HTML).
     *
     * @param array $disc Discrepancy entry
     * @return string Formatted HTML for one discrepancy line
     */
    public static function format_discrepancy_message(array $disc): string {
        return WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message($disc);
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
        WC_Multi_Store_Weekly_Verification_Report_Repository::create_table();
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
        return WC_Multi_Store_Weekly_Verification_Report_Repository::get_orphan_products_from_report($report, $require_remote_id);
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
        $products = WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::get_products_to_verify($settings);

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
        $ghost_results = WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::scan_ghost_products($active_stores, $settings);
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
        WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::clear_api_client_pool();

        // Save report to database
        WC_Multi_Store_Weekly_Verification_Report_Repository::save_report($report);

        WC_Multi_Store_Logger::write(sprintf(
            'Async verification completed: %d products checked, %d discrepancies found in %d seconds',
            $report['products_checked'],
            $report['discrepancies_found'],
            $report['duration_seconds']
        ));

        // Send email notification if enabled
        if ($settings['email_enabled'] && $report['discrepancies_found'] > 0) {
            WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($report);
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
