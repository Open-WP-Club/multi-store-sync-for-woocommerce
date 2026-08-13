<?php
/**
 * Custom Field Mapper
 *
 * Handles syncing of custom post meta and ACF fields
 * - Map local fields to remote fields
 * - Support for ACF (Advanced Custom Fields)
 * - Data type preservation
 * - Support for repeater fields and complex field types
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom Field Mapper Class
 */
class WC_Multi_Store_Custom_Field_Mapper {

    /**
     * Get all custom fields for a product
     *
     * @param int $product_id Product ID
     * @return array Custom fields array
     */
    public static function get_product_custom_fields(int $product_id): array {
        // Get all postmeta excluding WooCommerce internal fields. get_post_meta()
        // already unserializes each value, so the underscore-prefix check below
        // matches the SQL version's "NOT LIKE '_%'" — the explicit exclude_keys
        // check still matters for any non-underscore key added via the
        // 'wc_mss_excluded_meta_keys' filter.
        $exclude_keys = self::get_excluded_meta_keys();

        $custom_fields = [];
        foreach (get_post_meta($product_id) as $meta_key => $values) {
            if (str_starts_with($meta_key, '_') || in_array($meta_key, $exclude_keys, true)) {
                continue;
            }
            $custom_fields[$meta_key] = $values[0] ?? null;
        }

        ksort($custom_fields);

        return $custom_fields;
    }

    /**
     * Get excluded meta keys (WooCommerce internal fields)
     *
     * @return array Excluded meta keys
     */
    private static function get_excluded_meta_keys(): array {
        return apply_filters('wc_mss_excluded_meta_keys', [
            '_edit_lock',
            '_edit_last',
            '_wp_page_template',
            '_thumbnail_id',
            '_product_image_gallery',
            '_sku',
            '_regular_price',
            '_sale_price',
            '_price',
            '_stock',
            '_stock_status',
            '_manage_stock',
            '_backorders',
            '_sold_individually',
            '_weight',
            '_length',
            '_width',
            '_height',
            '_tax_status',
            '_tax_class',
            '_virtual',
            '_downloadable',
            '_download_limit',
            '_download_expiry',
            '_product_attributes',
            '_default_attributes',
            '_children',
            '_product_version',
            '_wp_old_slug',
            '_wp_old_date',
        ]);
    }

    /**
     * Map and sync custom fields
     *
     * @param int $product_id Local product ID
     * @param array $field_mapping Field mapping configuration
     * @param WC_Multi_Store_API_Client $api API client
     * @param int $remote_product_id Remote product ID
     * @return array Result with success/error info
     */
    public static function sync_custom_fields(int $product_id, array $field_mapping, mixed $api, int $remote_product_id): array {
        if (empty($field_mapping) || !is_array($field_mapping)) {
            return [
                'success' => true,
                'message' => 'No custom fields configured for sync',
                'fields_synced' => 0,
            ];
        }

        // Get custom fields from product
        $custom_fields = self::get_product_custom_fields($product_id);

        if (empty($custom_fields)) {
            return [
                'success' => true,
                'message' => 'No custom fields found on product',
                'fields_synced' => 0,
            ];
        }

        $synced_count = 0;
        $errors = [];

        // Prepare meta data for API
        $meta_data = [];

        foreach ($field_mapping as $local_field => $remote_field) {
            // Skip if field doesn't exist on product
            if (!isset($custom_fields[$local_field])) {
                continue;
            }

            $value = $custom_fields[$local_field];

            // Handle ACF fields
            if (function_exists('get_field_object')) {
                $field_object = get_field_object($local_field, $product_id);
                if ($field_object) {
                    $value = self::format_acf_value($value, $field_object);
                }
            }

            // Prepare for API
            $meta_data[] = [
                'key' => $remote_field,
                'value' => $value,
            ];

            $synced_count++;
        }

        // Update product meta via API
        if (!empty($meta_data)) {
            $result = $api->update_product($remote_product_id, [
                'meta_data' => $meta_data,
            ]);

            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'message' => 'Failed to sync custom fields: ' . $result->get_error_message(),
                    'fields_synced' => 0,
                ];
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Successfully synced %d custom field(s)', $synced_count),
            'fields_synced' => $synced_count,
        ];
    }

    /**
     * Format ACF value for API
     *
     * @param mixed $value Field value
     * @param array $field_object ACF field object
     * @return mixed Formatted value
     */
    private static function format_acf_value(mixed $value, array $field_object): mixed {
        $field_type = $field_object['type'] ?? '';

        switch ($field_type) {
            case 'image':
            case 'file':
                // Return URL instead of attachment ID
                if (is_numeric($value)) {
                    return wp_get_attachment_url($value);
                }
                break;

            case 'gallery':
                // Convert array of IDs to URLs
                if (is_array($value)) {
                    $urls = [];
                    foreach ($value as $id) {
                        if (is_numeric($id)) {
                            $urls[] = wp_get_attachment_url($id);
                        }
                    }
                    return $urls;
                }
                break;

            case 'post_object':
            case 'relationship':
                // Convert post IDs to titles or slugs
                if (is_array($value)) {
                    $titles = [];
                    foreach ($value as $post_id) {
                        $title = get_post($post_id)?->post_title;
                        if ($title) {
                            $titles[] = $title;
                        }
                    }
                    return $titles;
                } elseif (is_numeric($value)) {
                    return get_post($value)?->post_title ?? $value;
                }
                break;

            case 'taxonomy':
                // Convert term IDs to names
                if (is_array($value)) {
                    $names = [];
                    foreach ($value as $term_id) {
                        $term = get_term($term_id);
                        if ($term && !is_wp_error($term)) {
                            $names[] = $term->name;
                        }
                    }
                    return $names;
                } elseif (is_numeric($value)) {
                    $term = get_term($value);
                    return ($term && !is_wp_error($term)) ? $term->name : $value;
                }
                break;
        }

        return $value;
    }

}
