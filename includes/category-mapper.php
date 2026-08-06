<?php
/**
 * Category Mapper
 * Maps local categories to different remote categories per store
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Category_Mapper {
    use WC_Multi_Store_Toggleable_Feature;

    /**
     * Settings option key
     */
    const SETTINGS_KEY = 'wc_mss_category_mapper_settings';

    /**
     * Option key for storing category mappings
     */
    const OPTION_KEY = 'wc_mss_category_mappings';
    const TAG_OPTION_KEY = 'wc_mss_tag_mappings';

    /**
     * @return array
     */
    public static function default_settings(): array {
        return ['enabled' => false];
    }

    /**
     * @return string
     */
    public static function feature_label(): string {
        return __('Category mapping', 'wc-multi-store-sync');
    }

    /**
     * Get all category mappings for a specific store
     *
     * @param string $store_url Remote store URL
     * @return array Mappings: [local_slug => remote_slug]
     */
    public static function get_mappings(string $store_url): array {
        return self::get_mappings_for_key(self::OPTION_KEY, $store_url);
    }

    /**
     * Set category mappings for a specific store
     *
     * @param string $store_url Remote store URL
     * @param array $mappings Mappings: [local_slug => remote_slug]
     */
    public static function set_mappings(string $store_url, array $mappings): void {
        self::set_mappings_for_key(self::OPTION_KEY, $store_url, $mappings);
    }

    /**
     * Get mappings stored under a given option key for a specific store
     *
     * @param string $option_key wp_options key the mapping table lives under
     * @param string $store_url Remote store URL
     * @return array Mappings: [local_slug => remote_slug]
     */
    private static function get_mappings_for_key(string $option_key, string $store_url): array {
        $all_mappings = get_option($option_key, []);
        $store_key = md5($store_url);

        return $all_mappings[$store_key] ?? [];
    }

    /**
     * Set mappings stored under a given option key for a specific store
     *
     * @param string $option_key wp_options key the mapping table lives under
     * @param string $store_url Remote store URL
     * @param array $mappings Mappings: [local_slug => remote_slug]
     */
    private static function set_mappings_for_key(string $option_key, string $store_url, array $mappings): void {
        $all_mappings = get_option($option_key, []);
        $store_key = md5($store_url);
        $all_mappings[$store_key] = $mappings;

        update_option($option_key, $all_mappings);
    }

    /**
     * Add a single category mapping for a store
     *
     * @param string $store_url Remote store URL
     * @param string $local_slug Local category slug
     * @param string $remote_slug Remote category slug to map to
     */
    public static function add_mapping(string $store_url, string $local_slug, string $remote_slug): void {
        $mappings = self::get_mappings($store_url);
        $mappings[$local_slug] = $remote_slug;
        self::set_mappings($store_url, $mappings);
    }

    /**
     * Remove a single category mapping for a store
     *
     * @param string $store_url Remote store URL
     * @param string $local_slug Local category slug to unmap
     */
    public static function remove_mapping(string $store_url, string $local_slug): void {
        $mappings = self::get_mappings($store_url);
        unset($mappings[$local_slug]);
        self::set_mappings($store_url, $mappings);
    }

    /**
     * Apply category mappings to product data before sending to a remote store
     *
     * @param array $product_data Product data array (with 'categories' key)
     * @param string $store_url Remote store URL
     * @return array Modified product data with mapped categories
     */
    public static function apply_mappings(array $product_data, string $store_url): array {
        if (!self::is_enabled()) {
            return $product_data;
        }

        if (empty($product_data['categories'])) {
            return $product_data;
        }

        $mappings = self::get_mappings($store_url);
        if (empty($mappings)) {
            return $product_data;
        }

        $mapped_categories = [];
        foreach ($product_data['categories'] as $category) {
            $slug = $category['slug'] ?? '';
            $name = $category['name'] ?? '';

            if (isset($mappings[$slug])) {
                $remote_slug = $mappings[$slug];

                // Special case: empty mapping means skip this category
                if ($remote_slug === '' || $remote_slug === '__skip__') {
                    WC_Multi_Store_Logger::write(sprintf(
                        'Category "%s" skipped for store %s (mapped to skip)',
                        $slug,
                        $store_url
                    ));
                    continue;
                }

                $mapped_categories[] = [
                    'slug' => $remote_slug,
                    'name' => $name,
                ];

                WC_Multi_Store_Logger::write(sprintf(
                    'Category "%s" mapped to "%s" for store %s',
                    $slug,
                    $remote_slug,
                    $store_url
                ));
            } else {
                // No mapping: pass through as-is
                $mapped_categories[] = $category;
            }
        }

        $product_data['categories'] = $mapped_categories;
        return $product_data;
    }

    /**
     * Apply tag mappings to product data (same logic as categories)
     *
     * @param array $product_data Product data array (with 'tags' key)
     * @param string $store_url Remote store URL
     * @return array Modified product data with mapped tags
     */
    public static function apply_tag_mappings(array $product_data, string $store_url): array {
        if (!self::is_enabled()) {
            return $product_data;
        }

        if (empty($product_data['tags'])) {
            return $product_data;
        }

        $mappings = self::get_tag_mappings($store_url);
        if (empty($mappings)) {
            return $product_data;
        }

        $mapped_tags = [];
        foreach ($product_data['tags'] as $tag) {
            $slug = $tag['slug'] ?? '';

            if (isset($mappings[$slug])) {
                $remote_slug = $mappings[$slug];

                if ($remote_slug === '' || $remote_slug === '__skip__') {
                    continue;
                }

                $mapped_tags[] = [
                    'slug' => $remote_slug,
                    'name' => $tag['name'] ?? '',
                ];
            } else {
                $mapped_tags[] = $tag;
            }
        }

        $product_data['tags'] = $mapped_tags;
        return $product_data;
    }

    /**
     * Get tag mappings for a store
     */
    public static function get_tag_mappings(string $store_url): array {
        return self::get_mappings_for_key(self::TAG_OPTION_KEY, $store_url);
    }

    /**
     * Set tag mappings for a store
     */
    public static function set_tag_mappings(string $store_url, array $mappings): void {
        self::set_mappings_for_key(self::TAG_OPTION_KEY, $store_url, $mappings);
    }

    /**
     * Get all local categories for the mapping UI
     */
    public static function get_local_categories(): array {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(fn(WP_Term $term) => [
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'parent' => $term->parent,
            'count' => $term->count,
        ], $terms);
    }

    /**
     * Get remote categories for the mapping UI (fetches from remote store)
     */
    public static function get_remote_categories(WC_Multi_Store_API_Client $client): array {
        $page = 1;
        $all_categories = [];

        do {
            $response = $client->get('products/categories', [
                'per_page' => 100,
                'page' => $page,
            ]);

            if (is_wp_error($response) || empty($response)) {
                break;
            }

            $all_categories = array_merge($all_categories, $response);
            $page++;
        } while (count($response) === 100);

        return array_map(fn($cat) => [
            'id' => $cat['id'],
            'name' => $cat['name'],
            'slug' => $cat['slug'],
            'parent' => $cat['parent'],
            'count' => $cat['count'],
        ], $all_categories);
    }

    /**
     * AJAX handler: Save category mappings
     */
    public static function ajax_save_mappings(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $store_url = sanitize_text_field($_POST['store_url'] ?? '');
        $mappings_raw = $_POST['mappings'] ?? [];
        $mapping_type = sanitize_text_field($_POST['mapping_type'] ?? 'category');

        if (empty($store_url)) {
            wp_send_json_error(['message' => __('Store URL is required', 'wc-multi-store-sync')]);
            return;
        }

        $mappings = [];
        if (is_array($mappings_raw)) {
            foreach ($mappings_raw as $local_slug => $remote_slug) {
                $mappings[sanitize_text_field($local_slug)] = sanitize_text_field($remote_slug);
            }
        }

        if ($mapping_type === 'tag') {
            self::set_tag_mappings($store_url, $mappings);
        } else {
            self::set_mappings($store_url, $mappings);
        }

        wp_send_json_success([
            'message' => sprintf(
                __('%d %s mapping(s) saved for store', 'wc-multi-store-sync'),
                count($mappings),
                $mapping_type
            ),
        ]);
    }

    /**
     * AJAX handler: Get category mappings for a store
     */
    public static function ajax_get_mappings(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $store_url = sanitize_text_field($_GET['store_url'] ?? '');
        $mapping_type = sanitize_text_field($_GET['mapping_type'] ?? 'category');

        if (empty($store_url)) {
            wp_send_json_error(['message' => __('Store URL is required', 'wc-multi-store-sync')]);
            return;
        }

        $mappings = $mapping_type === 'tag'
            ? self::get_tag_mappings($store_url)
            : self::get_mappings($store_url);

        wp_send_json_success([
            'mappings' => $mappings,
            'local_categories' => self::get_local_categories(),
        ]);
    }
}
