<?php
/**
 * Weekly Verification Comparator
 * Per-product field comparison and exclusion-rule checks for the weekly sync
 * verifier — extracted from WC_Multi_Store_Weekly_Sync_Verifier, which
 * delegates to this class.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Weekly_Verification_Comparator {

    /**
     * Tally a product's discrepancies into the shared report/progress counters.
     * Shared by run_verification() and process_verification_batch(), which each
     * keep their own running totals array ($report / $progress) but tally identically.
     *
     * @param array $discrepancies Discrepancies for one product
     * @param array $counters Report/progress array to increment, by reference
     */
    public static function tally_discrepancies_by_type(array $discrepancies, array &$counters): void {
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
    public static function build_ghost_product_detail(array $ghost): array {
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
    public static function verify_product(int $product_id, array $stores, array $settings): ?array {
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
            $should_be_excluded = WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store);

            try {
                $remote_product = WC_Multi_Store_Weekly_Verification_Remote_Data_Fetcher::get_remote_product($product, $store_url, $store);

                // If product should be excluded but exists on remote - it's an orphan
                if ($should_be_excluded) {
                    if ($remote_product) {
                        $excluded_reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($product, $store);
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

}
