<?php
/**
 * Attribute Remapper
 * Maps product attribute names and values differently per store
 * Useful for multilingual stores (e.g., 'Цвят' → 'Color', 'Размер' → 'Size')
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Attribute_Remapper {
    use WC_Multi_Store_Toggleable_Feature;

    /**
     * Option key for attribute name mappings
     */
    const NAME_MAPPING_KEY = 'wc_mss_attribute_name_mappings';

    /**
     * Option key for attribute value mappings
     */
    const VALUE_MAPPING_KEY = 'wc_mss_attribute_value_mappings';

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
        return __('Attribute remapping', 'wc-multi-store-sync');
    }

    /**
     * @return string
     */
    public static function central_settings_prefix(): string {
        return 'attribute_remapping';
    }

    /**
     * Get attribute name mappings for a store
     * Example: ['цвят' => 'color', 'размер' => 'size']
     *
     * @param string $store_url Remote store URL
     * @return array Mappings: [local_name => remote_name]
     */
    public static function get_name_mappings(string $store_url): array {
        $all = get_option(self::NAME_MAPPING_KEY, []);
        $key = md5($store_url);
        return $all[$key] ?? [];
    }

    /**
     * Set attribute name mappings for a store
     *
     * @param string $store_url Remote store URL
     * @param array $mappings [local_name => remote_name]
     */
    public static function set_name_mappings(string $store_url, array $mappings): void {
        $all = get_option(self::NAME_MAPPING_KEY, []);
        $key = md5($store_url);
        $all[$key] = $mappings;
        update_option(self::NAME_MAPPING_KEY, $all);
    }

    /**
     * Get attribute value mappings for a store
     * Example: ['Червен' => 'Red', 'Синьо' => 'Blue']
     *
     * @param string $store_url Remote store URL
     * @param string $attribute_name Local attribute name (to scope mappings)
     * @return array Mappings: [local_value => remote_value]
     */
    public static function get_value_mappings(string $store_url, string $attribute_name = ''): array {
        $all = get_option(self::VALUE_MAPPING_KEY, []);
        $key = md5($store_url);
        $store_mappings = $all[$key] ?? [];

        if ($attribute_name) {
            $attr_key = sanitize_title($attribute_name);
            return $store_mappings[$attr_key] ?? [];
        }

        return $store_mappings;
    }

    /**
     * Set attribute value mappings for a store
     *
     * @param string $store_url Remote store URL
     * @param string $attribute_name Local attribute name
     * @param array $mappings [local_value => remote_value]
     */
    public static function set_value_mappings(string $store_url, string $attribute_name, array $mappings): void {
        $all = get_option(self::VALUE_MAPPING_KEY, []);
        $key = md5($store_url);
        $attr_key = sanitize_title($attribute_name);

        if (!isset($all[$key])) {
            $all[$key] = [];
        }

        $all[$key][$attr_key] = $mappings;
        update_option(self::VALUE_MAPPING_KEY, $all);
    }

    /**
     * Find a mapped attribute name, matching case-insensitively or by slug
     *
     * @param string $local_name Local attribute name to look up
     * @param array $name_mappings Mappings: [local_name => remote_name]
     * @return string|null Remote name if a mapping matched, null otherwise
     */
    private static function find_name_mapping(string $local_name, array $name_mappings): ?string {
        $local_slug = sanitize_title($local_name);

        foreach ($name_mappings as $from => $to) {
            if (mb_strtolower($from) === mb_strtolower($local_name) || sanitize_title($from) === $local_slug) {
                return $to;
            }
        }

        return null;
    }

    /**
     * Find a mapped attribute value, matching case-insensitively
     *
     * @param string $value Local value to look up
     * @param array $value_mappings Mappings: [local_value => remote_value]
     * @return string|null Remote value if a mapping matched, null otherwise
     */
    private static function find_value_mapping(string $value, array $value_mappings): ?string {
        foreach ($value_mappings as $from => $to) {
            if (mb_strtolower($from) === mb_strtolower($value)) {
                return $to;
            }
        }

        return null;
    }

    /**
     * Apply attribute remapping to product data before sending to a remote store
     *
     * @param array $product_data Product data array (with 'attributes' key)
     * @param string $store_url Remote store URL
     * @return array Modified product data with remapped attributes
     */
    public static function apply_mappings(array $product_data, string $store_url): array {
        if (!self::is_enabled()) {
            return $product_data;
        }

        if (empty($product_data['attributes'])) {
            return $product_data;
        }

        $name_mappings = self::get_name_mappings($store_url);
        if (empty($name_mappings)) {
            // No name mappings, check for value mappings
            $value_mappings_all = self::get_value_mappings($store_url);
            if (empty($value_mappings_all)) {
                return $product_data;
            }
        }

        $remapped_attributes = [];
        foreach ($product_data['attributes'] as $attribute) {
            $local_name = $attribute['name'] ?? '';

            // Remap attribute name
            $matched_name = self::find_name_mapping($local_name, $name_mappings);
            $remote_name = $matched_name ?? $local_name;
            if ($matched_name !== null) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Attribute name remapped: "%s" → "%s" for store %s',
                    $local_name,
                    $matched_name,
                    $store_url
                ));
            }

            // Remap attribute values
            $value_mappings = self::get_value_mappings($store_url, $local_name);
            $options = $attribute['options'] ?? [];

            if (!empty($value_mappings) && !empty($options)) {
                $options = array_map(
                    fn($option) => self::find_value_mapping($option, $value_mappings) ?? $option,
                    $options
                );
            }

            $remapped_attributes[] = array_merge($attribute, [
                'name' => $remote_name,
                'options' => $options,
            ]);
        }

        $product_data['attributes'] = $remapped_attributes;
        return $product_data;
    }

    /**
     * Apply attribute remapping to variation data
     *
     * @param array $variation_data Variation data with 'attributes' key
     * @param string $store_url Remote store URL
     * @return array Modified variation data with remapped attribute references
     */
    public static function apply_variation_mappings(array $variation_data, string $store_url): array {
        if (!self::is_enabled()) {
            return $variation_data;
        }

        if (empty($variation_data['attributes'])) {
            return $variation_data;
        }

        $name_mappings = self::get_name_mappings($store_url);
        $remapped_attrs = [];

        foreach ($variation_data['attributes'] as $attr) {
            $local_name = $attr['name'] ?? '';
            $value = $attr['option'] ?? '';

            $remote_name = self::find_name_mapping($local_name, $name_mappings) ?? $local_name;

            $value_mappings = self::get_value_mappings($store_url, $local_name);
            $remote_value = self::find_value_mapping($value, $value_mappings) ?? $value;

            $remapped_attrs[] = [
                'id' => $attr['id'] ?? 0,
                'name' => $remote_name,
                'option' => $remote_value,
            ];
        }

        $variation_data['attributes'] = $remapped_attrs;
        return $variation_data;
    }

    /**
     * Apply remapping to default_attributes in variable product data
     *
     * @param array $product_data Product data with 'default_attributes' key
     * @param string $store_url Remote store URL
     * @return array Modified product data
     */
    public static function apply_default_attribute_mappings(array $product_data, string $store_url): array {
        if (!self::is_enabled()) {
            return $product_data;
        }

        if (empty($product_data['default_attributes'])) {
            return $product_data;
        }

        $name_mappings = self::get_name_mappings($store_url);
        $remapped = [];

        foreach ($product_data['default_attributes'] as $attr) {
            $local_name = $attr['name'] ?? '';
            $value = $attr['option'] ?? '';

            $remote_name = self::find_name_mapping($local_name, $name_mappings) ?? $local_name;

            $value_mappings = self::get_value_mappings($store_url, $local_name);
            $remote_value = self::find_value_mapping($value, $value_mappings) ?? $value;

            $remapped[] = [
                'id' => $attr['id'] ?? 0,
                'name' => $remote_name,
                'option' => $remote_value,
            ];
        }

        $product_data['default_attributes'] = $remapped;
        return $product_data;
    }

    /**
     * Get all local product attributes for the mapping UI
     */
    public static function get_local_attributes(): array {
        $taxonomies = wc_get_attribute_taxonomies();
        $attributes = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => wc_attribute_taxonomy_name($taxonomy->attribute_name),
                'hide_empty' => false,
            ]);

            $values = [];
            if (!is_wp_error($terms)) {
                $values = array_map(fn($term) => $term->name, $terms);
            }

            $attributes[] = [
                'id' => $taxonomy->attribute_id,
                'name' => $taxonomy->attribute_label,
                'slug' => $taxonomy->attribute_name,
                'type' => $taxonomy->attribute_type,
                'values' => $values,
            ];
        }

        return $attributes;
    }

    /**
     * AJAX handler: Save attribute mappings
     */
    public static function ajax_save_mappings(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $store_url = sanitize_text_field($_POST['store_url'] ?? '');
        $mapping_type = sanitize_text_field($_POST['mapping_type'] ?? 'names');

        if (empty($store_url)) {
            wp_send_json_error(['message' => __('Store URL is required', 'wc-multi-store-sync')]);
            return;
        }

        if ($mapping_type === 'names') {
            $raw = $_POST['name_mappings'] ?? [];
            $mappings = [];
            if (is_array($raw)) {
                foreach ($raw as $from => $to) {
                    $mappings[sanitize_text_field($from)] = sanitize_text_field($to);
                }
            }
            self::set_name_mappings($store_url, $mappings);

            wp_send_json_success([
                'message' => sprintf(
                    __('%d attribute name mapping(s) saved', 'wc-multi-store-sync'),
                    count($mappings)
                ),
            ]);
        } elseif ($mapping_type === 'values') {
            $attribute_name = sanitize_text_field($_POST['attribute_name'] ?? '');
            $raw = $_POST['value_mappings'] ?? [];
            $mappings = [];
            if (is_array($raw)) {
                foreach ($raw as $from => $to) {
                    $mappings[sanitize_text_field($from)] = sanitize_text_field($to);
                }
            }
            self::set_value_mappings($store_url, $attribute_name, $mappings);

            wp_send_json_success([
                'message' => sprintf(
                    __('%d value mapping(s) saved for attribute "%s"', 'wc-multi-store-sync'),
                    count($mappings),
                    $attribute_name
                ),
            ]);
        } else {
            wp_send_json_error(['message' => __('Invalid mapping type', 'wc-multi-store-sync')]);
        }
    }

    /**
     * AJAX handler: Get attribute mappings for a store
     */
    public static function ajax_get_mappings(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $store_url = sanitize_text_field($_GET['store_url'] ?? '');

        if (empty($store_url)) {
            wp_send_json_error(['message' => __('Store URL is required', 'wc-multi-store-sync')]);
            return;
        }

        wp_send_json_success([
            'name_mappings' => self::get_name_mappings($store_url),
            'value_mappings' => self::get_value_mappings($store_url),
            'local_attributes' => self::get_local_attributes(),
        ]);
    }
}
