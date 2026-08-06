<?php
/**
 * Product Extractor
 * Extracts product data from WC_Product objects for API synchronization
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Extractor Class
 */
class WC_Multi_Store_Product_Extractor {

    /**
     * Build product data array for API based on sync type
     *
     * @param WC_Product $product Product object
     * @param string $sync_type Sync type: full_product|price_quantity|quantity
     * @return array Product data for WooCommerce API
     */
    public function build_product_data(WC_Product $product, string $sync_type): array {
        $data = [];

        switch ($sync_type) {
            case 'quantity':
                // Only sync stock quantity and status
                $data = $this->get_stock_data($product);
                break;

            case 'price_quantity':
                // Sync prices and stock
                $data = array_merge(
                    $this->get_price_data($product),
                    $this->get_stock_data($product)
                );
                break;

            case 'price_quantity_categories':
                // Sync prices, stock, status, and categories/tags
                $data = array_merge(
                    $this->get_price_data($product),
                    $this->get_stock_data($product)
                );
                $data['status'] = $product->get_status();

                // Categories
                $category_ids = $product->get_category_ids();
                if (!empty($category_ids)) {
                    $data['categories'] = $this->format_categories($category_ids);
                }

                // Tags
                $tag_ids = $product->get_tag_ids();
                if (!empty($tag_ids)) {
                    $data['tags'] = $this->format_tags($tag_ids);
                }
                break;

            case 'full_product':
            default:
                // Full product sync
                $data = $this->get_full_product_data($product);
                break;
        }

        return $data;
    }

    /**
     * Get stock data
     *
     * @param WC_Product $product Product object
     * @return array Stock data
     */
    public function get_stock_data(WC_Product $product): array {
        $data = [];

        if ($product->managing_stock()) {
            $data['manage_stock'] = true;
            $data['stock_quantity'] = $product->get_stock_quantity();
            $data['stock_status'] = $product->get_stock_status();
        } else {
            $data['manage_stock'] = false;
            $data['stock_status'] = $product->get_stock_status();
        }

        return $data;
    }

    /**
     * Get price data
     *
     * @param WC_Product $product Product object
     * @return array Price data
     */
    public function get_price_data(WC_Product $product): array {
        $data = [
            'regular_price' => $product->get_regular_price(),
        ];

        if ($product->is_on_sale()) {
            $data['sale_price'] = $product->get_sale_price();

            $date_on_sale_from = $product->get_date_on_sale_from();
            $date_on_sale_to = $product->get_date_on_sale_to();

            if ($date_on_sale_from) {
                $data['date_on_sale_from'] = $date_on_sale_from->date('Y-m-d H:i:s');
            }

            if ($date_on_sale_to) {
                $data['date_on_sale_to'] = $date_on_sale_to->date('Y-m-d H:i:s');
            }
        } else {
            // Explicitly clear sale price and dates so the remote store
            // doesn't keep a stale sale that has already ended locally
            $data['sale_price'] = '';
            $data['date_on_sale_from'] = null;
            $data['date_on_sale_to'] = null;
        }

        return $data;
    }

    /**
     * Get full product data
     *
     * @param WC_Product $product Product object
     * @return array Full product data
     */
    public function get_full_product_data(WC_Product $product): array {
        $data = [
            // Basic info
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'featured' => $product->is_featured(),
            'catalog_visibility' => $product->get_catalog_visibility(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),

            // SKU
            'sku' => $product->get_sku(),
        ];

        // Prices - use existing method to avoid duplication
        $data = array_merge($data, $this->get_price_data($product));

        // Stock - use existing method to avoid duplication
        $stock_data = $this->get_stock_data($product);
        $data = array_merge($data, $stock_data);

        // Add backorders for full product data
        if ($product->managing_stock()) {
            $data['backorders'] = $product->get_backorders();
        }

        // Dimensions and weight
        if ($product->has_weight()) {
            $data['weight'] = $product->get_weight();
        }

        if ($product->has_dimensions()) {
            $data['dimensions'] = [
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height(),
            ];
        }

        // Tax
        $data['tax_status'] = $product->get_tax_status();
        $data['tax_class'] = $product->get_tax_class();

        // Categories
        $category_ids = $product->get_category_ids();
        if (!empty($category_ids)) {
            $data['categories'] = $this->format_categories($category_ids);
        }

        // Tags
        $tag_ids = $product->get_tag_ids();
        if (!empty($tag_ids)) {
            $data['tags'] = $this->format_tags($tag_ids);
        }

        // Images — always include so the remote store can clear images when local has none
        $data['images'] = $this->format_images($product);

        // Attributes (for simple and variable products)
        $attributes = $product->get_attributes();
        if (!empty($attributes)) {
            $data['attributes'] = $this->format_attributes($attributes);
        }

        // Default variation attributes (for variable products)
        if ($product->is_type('variable')) {
            $default_attrs = $product->get_default_attributes();
            if (!empty($default_attrs)) {
                $data['default_attributes'] = $this->format_default_attributes($default_attrs);
            }
        }

        // Downloadable files
        if ($product->is_downloadable()) {
            $downloads = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);
            if (!empty($downloads)) {
                $data['downloadable'] = true;
                $data['downloads'] = $downloads;
                $data['download_limit'] = $product->get_download_limit();
                $data['download_expiry'] = $product->get_download_expiry();
            }
        }

        // Virtual product flag
        if ($product->is_virtual()) {
            $data['virtual'] = true;
        }

        return $data;
    }

    /**
     * Format categories for API using batch fetch to avoid N+1 queries
     *
     * @param array $category_ids Category IDs
     * @return array Formatted categories
     */
    public function format_categories(array $category_ids): array {
        if (empty($category_ids)) {
            return [];
        }

        // Get category match settings
        $settings = WC_Multi_Store_Settings::get_settings();
        $match_mode = $settings['category_match_mode'] ?? 'full_path';
        $match_by = $settings['category_match_by'] ?? 'slug';

        // Generate cache key that includes match mode and match_by
        $cache_key = $match_mode . '_' . $match_by . '_' . implode('_', $category_ids);

        // Try to get from cache first
        $cached = WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_cat', $cache_key);
        if ($cached !== false && $cached !== null) {
            return $cached;
        }

        // Batch fetch all terms in a single query
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'include' => $category_ids,
            'hide_empty' => false,
            'orderby' => 'include',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        // Filter to leaf categories only if that mode is enabled
        if ($match_mode === 'leaf_only') {
            $terms = $this->filter_to_leaf_categories($terms, $category_ids);
        }

        $categories = [];
        foreach ($terms as $term) {
            // Always include both name and slug
            // - name: for display and name-based matching
            // - slug: for slug-based matching and category creation
            $categories[] = [
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

        // Cache the result
        WC_Multi_Store_Cache_Manager::set_taxonomy_terms('product_cat', $cache_key, $categories);

        return $categories;
    }

    /**
     * Filter terms to only include leaf categories (those with no children in the list)
     *
     * A leaf category is one where no other category in the product's list has it as a parent.
     * This allows matching by the deepest category name regardless of hierarchy path.
     *
     * @param array $terms Array of WP_Term objects
     * @param array $category_ids All category IDs assigned to the product
     * @return array Filtered array of WP_Term objects (only leaves)
     */
    private function filter_to_leaf_categories(array $terms, array $category_ids): array {
        if (empty($terms)) {
            return $terms;
        }

        // Build a map of term_id => term for quick lookup
        $term_map = [];
        foreach ($terms as $term) {
            $term_map[$term->term_id] = $term;
        }

        // Find all parent IDs that exist in our term list
        $parent_ids = [];
        foreach ($terms as $term) {
            if ($term->parent && isset($term_map[$term->parent])) {
                $parent_ids[$term->parent] = true;
            }
        }

        // Filter to only terms that are NOT parents of other terms in the list
        $leaf_terms = [];
        foreach ($terms as $term) {
            if (!isset($parent_ids[$term->term_id])) {
                $leaf_terms[] = $term;
            }
        }

        return $leaf_terms;
    }

    /**
     * Format tags for API using batch fetch to avoid N+1 queries
     *
     * @param array $tag_ids Tag IDs
     * @return array Formatted tags
     */
    public function format_tags(array $tag_ids): array {
        if (empty($tag_ids)) {
            return [];
        }

        // Get category match settings (reuse for tags)
        $settings = WC_Multi_Store_Settings::get_settings();
        $match_by = $settings['category_match_by'] ?? 'slug';

        // Generate cache key that includes match_by
        $cache_key = $match_by . '_' . implode('_', $tag_ids);

        // Try to get from cache first
        $cached = WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_tag', $cache_key);
        if ($cached !== false && $cached !== null) {
            return $cached;
        }

        // Batch fetch all terms in a single query
        $terms = get_terms([
            'taxonomy' => 'product_tag',
            'include' => $tag_ids,
            'hide_empty' => false,
            'orderby' => 'include',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $tags = [];
        foreach ($terms as $term) {
            if ($match_by === 'name') {
                // Only send name - API will match by display name (supports Cyrillic)
                $tags[] = [
                    'name' => $term->name,
                ];
            } else {
                // Send both name and slug - API matches by slug
                $tags[] = [
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }

        // Cache the result
        WC_Multi_Store_Cache_Manager::set_taxonomy_terms('product_tag', $cache_key, $tags);

        return $tags;
    }

    /**
     * Format images for API
     *
     * @param WC_Product $product Product object
     * @return array Formatted images
     */
    public function format_images(WC_Product $product): array {
        $images = [];

        // Main image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                $images[] = [
                    'src' => $image_url,
                    'position' => 0,
                ];
            }
        }

        // Gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        $position = 1;
        foreach ($gallery_ids as $gallery_id) {
            $image_url = wp_get_attachment_url($gallery_id);
            if ($image_url) {
                $images[] = [
                    'src' => $image_url,
                    'position' => $position,
                ];
                $position++;
            }
        }

        return $images;
    }

    /**
     * Get image filenames for comparison (extracts just filenames from URLs)
     *
     * @param WC_Product $product Product object
     * @return array Array of image filenames
     */
    public function get_image_filenames(WC_Product $product): array {
        $image_id    = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();

        $all_ids = array_filter(array_merge(
            $image_id ? [$image_id] : [],
            $gallery_ids
        ));

        if (empty($all_ids)) {
            return [];
        }

        // Prime the WP object cache for all attachments in one query so the
        // subsequent wp_get_attachment_url() calls hit cache instead of individual DB lookups.
        _prime_post_caches($all_ids, false, true);

        $filenames = [];
        foreach ($all_ids as $id) {
            $url = wp_get_attachment_url($id);
            if ($url) {
                $filenames[] = basename($url);
            }
        }

        return $filenames;
    }

    /**
     * Check if a synced value's hash differs from the one recorded for a store.
     * Shared by the have_X_changed() methods below — they only differ in the
     * meta-key prefix and the value being hashed.
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @param string $prefix Meta-key prefix identifying the compared value (e.g. 'images', 'cats')
     * @param mixed $current_value Current value to hash and compare
     * @return bool True if the value has changed since the last recorded hash
     */
    private function has_synced_value_changed(WC_Product $product, string $store_url, string $prefix, mixed $current_value): bool {
        $current_hash = md5(serialize($current_value));
        $meta_key = "_mss_{$prefix}_hash_" . md5($store_url);
        $last_hash = get_post_meta($product->get_id(), $meta_key, true);

        return $last_hash !== $current_hash;
    }

    /**
     * Save a synced value's hash for a store. Shared by the save_synced_X_hash()
     * methods below — they only differ in the meta-key prefix and the value hashed.
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param string $prefix Meta-key prefix identifying the saved value (e.g. 'images', 'cats')
     * @param mixed $value Value that was synced
     */
    private function save_synced_value_hash(int $product_id, string $store_url, string $prefix, mixed $value): void {
        $hash = md5(serialize($value));
        $meta_key = "_mss_{$prefix}_hash_" . md5($store_url);
        update_post_meta($product_id, $meta_key, $hash);
    }

    /**
     * Check if product images have changed since last sync to a specific store
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @return bool True if images have changed
     */
    public function have_images_changed(WC_Product $product, string $store_url): bool {
        return $this->has_synced_value_changed($product, $store_url, 'images', $this->get_image_filenames($product));
    }

    /**
     * Save image hash after successful sync
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param array $filenames Image filenames that were synced
     */
    public function save_synced_images_hash(int $product_id, string $store_url, array $filenames): void {
        $this->save_synced_value_hash($product_id, $store_url, 'images', $filenames);
    }

    /**
     * Format images for API, optionally skipping if unchanged
     *
     * @param WC_Product $product Product object
     * @param string|null $store_url Store URL for comparison (null = always include)
     * @param bool $is_update Whether this is an update (true) or create (false)
     * @return array|null Formatted images or null if unchanged
     */
    public function format_images_smart(WC_Product $product, ?string $store_url = null, bool $is_update = false): ?array {
        // For create operations, always include images
        if (!$is_update || $store_url === null) {
            return $this->format_images($product);
        }

        // For updates, check if images have changed
        if ($this->have_images_changed($product, $store_url)) {
            return $this->format_images($product);
        }

        // Images haven't changed, return null to skip
        return null;
    }

    // =========================================
    // Smart Category/Tag Comparison Methods
    // =========================================

    /**
     * Check if product categories have changed since last sync
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @return bool True if categories have changed
     */
    public function have_categories_changed(WC_Product $product, string $store_url): bool {
        $current_ids = $product->get_category_ids();
        sort($current_ids); // Sort for consistent comparison

        return $this->has_synced_value_changed($product, $store_url, 'cats', $current_ids);
    }

    /**
     * Check if product tags have changed since last sync
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @return bool True if tags have changed
     */
    public function have_tags_changed(WC_Product $product, string $store_url): bool {
        $current_ids = $product->get_tag_ids();
        sort($current_ids);

        return $this->has_synced_value_changed($product, $store_url, 'tags', $current_ids);
    }

    /**
     * Save category hash after successful sync
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param array $category_ids Category IDs that were synced
     */
    public function save_synced_categories_hash(int $product_id, string $store_url, array $category_ids): void {
        sort($category_ids);
        $this->save_synced_value_hash($product_id, $store_url, 'cats', $category_ids);
    }

    /**
     * Save tag hash after successful sync
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param array $tag_ids Tag IDs that were synced
     */
    public function save_synced_tags_hash(int $product_id, string $store_url, array $tag_ids): void {
        sort($tag_ids);
        $this->save_synced_value_hash($product_id, $store_url, 'tags', $tag_ids);
    }

    /**
     * Check if product description/short description have changed since last sync to a store
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @return bool True if description or short description have changed
     */
    public function have_description_changed(WC_Product $product, string $store_url): bool {
        return $this->has_synced_value_changed(
            $product,
            $store_url,
            'desc',
            [$product->get_description(), $product->get_short_description()]
        );
    }

    /**
     * Save description hash after successful sync
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param string $description Description that was synced
     * @param string $short_description Short description that was synced
     */
    public function save_synced_description_hash(int $product_id, string $store_url, string $description, string $short_description): void {
        $this->save_synced_value_hash($product_id, $store_url, 'desc', [$description, $short_description]);
    }

    /**
     * Save all sync hashes at once (images, categories, tags, description, attributes)
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     */
    public function save_all_sync_hashes(WC_Product $product, string $store_url): void {
        $product_id = $product->get_id();

        // Images
        $image_filenames = $this->get_image_filenames($product);
        $this->save_synced_images_hash($product_id, $store_url, $image_filenames);

        // Categories
        $category_ids = $product->get_category_ids();
        $this->save_synced_categories_hash($product_id, $store_url, $category_ids);

        // Tags
        $tag_ids = $product->get_tag_ids();
        $this->save_synced_tags_hash($product_id, $store_url, $tag_ids);

        // Description
        $this->save_synced_description_hash($product_id, $store_url, $product->get_description(), $product->get_short_description());

        // Attributes
        $this->save_synced_attributes_hash($product, $store_url);
    }

    /**
     * Build a lightweight, comparable signature of a product's attributes.
     *
     * Uses raw options (term IDs for taxonomy attributes, not resolved names)
     * so computing the signature never triggers the taxonomy label lookups
     * that format_attributes()/format_default_attributes() do — same
     * "hash the raw IDs, not the formatted output" approach as categories/tags.
     *
     * @param WC_Product $product Product object
     * @return array Comparable attribute signature
     */
    private function build_attributes_signature(WC_Product $product): array {
        $signature = [];

        foreach ($product->get_attributes() as $key => $attribute) {
            if (!($attribute instanceof \WC_Product_Attribute)) {
                $signature[] = [
                    'name' => is_string($key) ? $key : (string) $attribute,
                    'options' => is_array($attribute) ? $attribute : [(string) $attribute],
                ];
                continue;
            }

            $signature[] = [
                'name' => $attribute->get_name(),
                'options' => $attribute->get_options(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
            ];
        }

        if ($product->is_type('variable')) {
            $signature['default_attributes'] = $product->get_default_attributes();
        }

        return $signature;
    }

    /**
     * Check if product attributes/default attributes have changed since last sync to a store
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @return bool True if attributes have changed
     */
    public function have_attributes_changed(WC_Product $product, string $store_url): bool {
        return $this->has_synced_value_changed($product, $store_url, 'attrs', $this->build_attributes_signature($product));
    }

    /**
     * Save attributes hash after successful sync
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     */
    public function save_synced_attributes_hash(WC_Product $product, string $store_url): void {
        $this->save_synced_value_hash($product->get_id(), $store_url, 'attrs', $this->build_attributes_signature($product));
    }

    /**
     * Format attributes for API
     *
     * @param array $attributes Product attributes
     * @return array Formatted attributes
     */
    public function format_attributes(array $attributes): array {
        $formatted = [];

        foreach ($attributes as $key => $attribute) {
            // Skip non-object attributes (plain strings from custom product attributes)
            if (!($attribute instanceof \WC_Product_Attribute)) {
                $formatted[] = [
                    'name' => is_string($key) ? $key : (string) $attribute,
                    'visible' => true,
                    'variation' => false,
                    'options' => is_array($attribute) ? $attribute : [(string) $attribute],
                ];
                continue;
            }

            $formatted_attr = [
                'name' => $attribute->get_name(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
            ];

            if ($attribute->is_taxonomy()) {
                // Use the human-readable label instead of ID — IDs are auto-increment
                // and won't match between stores. The REST API will find or create
                // the taxonomy attribute by name on the remote store.
                $taxonomy = $attribute->get_taxonomy_object();
                if ($taxonomy) {
                    $formatted_attr['name'] = $taxonomy->attribute_label;
                }
                $terms = $attribute->get_terms();
                $formatted_attr['options'] = wp_list_pluck($terms, 'name');
            } else {
                $formatted_attr['options'] = $attribute->get_options();
            }

            $formatted[] = $formatted_attr;
        }

        return $formatted;
    }

    /**
     * Format variation attributes
     *
     * @param WC_Product_Variation $variation Variation object
     * @return array Formatted attributes
     */
    public function format_variation_attributes(WC_Product_Variation $variation): array {
        $attributes = [];
        $variation_attributes = $variation->get_variation_attributes();

        foreach ($variation_attributes as $raw_key => $attr_value) {
            // Remove 'attribute_' prefix to get the taxonomy slug or custom attribute key
            $attr_key = str_replace('attribute_', '', $raw_key);

            if (taxonomy_exists($attr_key)) {
                // Taxonomy attribute: use human-readable label (consistent with format_attributes())
                $attr_name = wc_attribute_label($attr_key);

                // Resolve term slug → term name (consistent with format_attributes() which uses term names)
                if (!empty($attr_value)) {
                    $term = get_term_by('slug', $attr_value, $attr_key);
                    if ($term && !is_wp_error($term)) {
                        $attr_value = $term->name;
                    }
                }
            } else {
                $attr_name = $attr_key;
            }

            $attributes[] = [
                'name' => $attr_name,
                'option' => $attr_value,
            ];
        }

        return $attributes;
    }

    /**
     * Format default_attributes for a variable product
     * Uses the same name/value resolution as format_variation_attributes().
     *
     * @param array $default_attrs Raw default attributes from WC_Product::get_default_attributes()
     * @return array Formatted for the WooCommerce REST API
     */
    public function format_default_attributes(array $default_attrs): array {
        $formatted = [];

        foreach ($default_attrs as $attr_key => $attr_value) {
            if (taxonomy_exists($attr_key)) {
                $attr_name = wc_attribute_label($attr_key);

                if (!empty($attr_value)) {
                    $term = get_term_by('slug', $attr_value, $attr_key);
                    if ($term && !is_wp_error($term)) {
                        $attr_value = $term->name;
                    }
                }
            } else {
                $attr_name = $attr_key;
            }

            $formatted[] = [
                'name'   => $attr_name,
                'option' => $attr_value,
            ];
        }

        return $formatted;
    }

    /**
     * Build variation data array for API
     *
     * @param WC_Product_Variation $variation Variation object
     * @param string $sync_type Sync type: full_product|price_quantity|price_quantity_categories|quantity
     * @return array Variation data for WooCommerce API
     */
    public function build_variation_data(WC_Product_Variation $variation, string $sync_type = 'full_product'): array {
        if ($sync_type === 'quantity') {
            return array_merge(['sku' => $variation->get_sku()], $this->get_stock_data($variation));
        }

        if ($sync_type === 'price_quantity' || $sync_type === 'price_quantity_categories') {
            return array_merge(
                ['sku' => $variation->get_sku()],
                $this->get_price_data($variation),
                $this->get_stock_data($variation)
            );
        }

        // full_product (default) — price, stock, attributes, image.
        $data = [
            'sku' => $variation->get_sku(),
            'regular_price' => $variation->get_regular_price(),
        ];

        // Sale price — always send to clear stale sales on remote
        if ($variation->is_on_sale()) {
            $data['sale_price'] = $variation->get_sale_price();
        } else {
            $data['sale_price'] = '';
        }

        // Stock
        if ($variation->managing_stock()) {
            $data['manage_stock'] = true;
            $data['stock_quantity'] = $variation->get_stock_quantity();
            $data['stock_status'] = $variation->get_stock_status();
        } else {
            $data['manage_stock'] = false;
            $data['stock_status'] = $variation->get_stock_status();
        }

        // Variation attributes
        $data['attributes'] = $this->format_variation_attributes($variation);

        // Image
        $image_id = $variation->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                $data['image'] = ['src' => $image_url];
            }
        }

        return $data;
    }
}
