<?php
/**
 * WooCommerce Multi-Store Sync Previewer
 *
 * Handles dry-run / preview mode for sync operations
 * Shows what will change before actually executing the sync
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync Previewer Class
 */
class WC_Multi_Store_Sync_Previewer {

    /**
     * API Client instance
     *
     * @var WC_Multi_Store_API_Client|null
     */
    private ?WC_Multi_Store_API_Client $api_client;

    /**
     * Constructor
     *
     * @param WC_Multi_Store_API_Client|null $api_client API client instance
     */
    public function __construct(?WC_Multi_Store_API_Client $api_client = null) {
        $this->api_client = $api_client;
    }

    /**
     * Preview product sync
     *
     * @param int $product_id Local product ID
     * @param array $stores Array of store configurations
     * @param string $sync_type Sync type: full_product|price_quantity|quantity
     * @return array Preview results with before/after comparisons
     */
    public function preview_product_sync(int $product_id, array $stores, string $sync_type = 'full_product'): array {
        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wc-multi-store-sync'),
            ];
        }

        $previews = [];

        foreach ($stores as $store_url => $store_config) {
            // Skip if store is not active
            if (isset($store_config['status']) && $store_config['status'] !== 'active') {
                continue;
            }

            $preview = $this->preview_product_to_store($product, $store_url, $store_config, $sync_type);
            $previews[$store_url] = $preview;
        }

        return [
            'success' => true,
            'previews' => $previews,
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'product_sku' => $product->get_sku(),
            'sync_type' => $sync_type,
        ];
    }

    /**
     * Preview product sync to a single store
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_type Sync type
     * @return array Preview data with before/after comparison
     */
    private function preview_product_to_store(WC_Product $product, string $store_url, array $store_config, string $sync_type): array {
        // Initialize API client for this store
        $settings = get_option('wc_multi_store_sync_settings', []);
        $api = WC_Multi_Store_API_Client::for_store($store_url, $store_config);

        // Get current state from remote store
        $remote_product = $this->get_remote_product($api, $product, $settings);

        // Build data that would be synced
        $sync_data = $this->build_sync_data($product, $sync_type, $store_config);

        // Compare before and after
        $changes = $this->compare_data($remote_product, $sync_data, $sync_type);

        return [
            'success' => true,
            'store_url' => $store_url,
            'store_name' => $store_config['name'] ?? $store_url,
            'remote_exists' => $remote_product !== null,
            'remote_product_id' => $remote_product ? $remote_product['id'] : null,
            'action' => $remote_product ? 'update' : 'create',
            'before' => $remote_product,
            'after' => $sync_data,
            'changes' => $changes,
            'has_changes' => !empty($changes),
            'conflicts' => $this->detect_conflicts($remote_product, $sync_data),
        ];
    }

    /**
     * Get remote product from store
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param WC_Product $product Local product
     * @param array $settings Plugin settings
     * @return array|null Remote product data or null if not found
     */
    private function get_remote_product(WC_Multi_Store_API_Client $api, WC_Product $product, array $settings): ?array {
        $match_by = $settings['match_products_by'] ?? 'sku';

        try {
            if ($match_by === 'sku' && $product->get_sku()) {
                $remote_products = $api->get_products($product->get_sku(), 'sku');
                if (!empty($remote_products) && is_array($remote_products)) {
                    return $remote_products[0];
                }
            } elseif ($match_by === 'slug') {
                $remote_products = $api->get_products($product->get_slug(), 'slug');
                if (!empty($remote_products) && is_array($remote_products)) {
                    return $remote_products[0];
                }
            }
        } catch (Exception $e) {
            // Product doesn't exist on remote
            return null;
        }

        return null;
    }

    /**
     * Build sync data based on sync type
     *
     * @param WC_Product $product Product object
     * @param string $sync_type Sync type
     * @param array $store_config Store configuration
     * @return array Sync data
     */
    private function build_sync_data(WC_Product $product, string $sync_type, array $store_config): array {
        return match ($sync_type) {
            'full_product' => $this->build_full_product_data($product, $store_config),
            'price_quantity' => $this->build_price_quantity_data($product, $store_config),
            'quantity' => $this->build_quantity_data($product, $store_config),
            default => [],
        };
    }

    /**
     * Build full product data
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return array Product data
     */
    private function build_full_product_data(WC_Product $product, array $store_config): array {
        return [
            'name' => $product->get_name(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'sku' => $product->get_sku(),
            'regular_price' => $this->apply_pricing_rules($product->get_regular_price(), $store_config),
            'sale_price' => $this->apply_pricing_rules($product->get_sale_price(), $store_config),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $this->apply_stock_allocation($product->get_stock_quantity(), $store_config),
            'stock_status' => $product->get_stock_status(),
            'weight' => $product->get_weight(),
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
        ];
    }

    /**
     * Build price and quantity data
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return array Price and quantity data
     */
    private function build_price_quantity_data(WC_Product $product, array $store_config): array {
        return [
            'regular_price' => $this->apply_pricing_rules($product->get_regular_price(), $store_config),
            'sale_price' => $this->apply_pricing_rules($product->get_sale_price(), $store_config),
            'stock_quantity' => $this->apply_stock_allocation($product->get_stock_quantity(), $store_config),
            'stock_status' => $product->get_stock_status(),
        ];
    }

    /**
     * Build quantity data
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return array Quantity data
     */
    private function build_quantity_data(WC_Product $product, array $store_config): array {
        return [
            'stock_quantity' => $this->apply_stock_allocation($product->get_stock_quantity(), $store_config),
            'stock_status' => $product->get_stock_status(),
        ];
    }

    /**
     * Apply pricing rules to a price
     *
     * @param string|float $price Original price
     * @param array $store_config Store configuration
     * @return string|float Modified price
     */
    private function apply_pricing_rules(string|float $price, array $store_config): string|float {
        if (empty($price) || empty($store_config['pricing_rules']) || !class_exists('WC_Multi_Store_Pricing_Rules')) {
            return $price;
        }

        $preview = WC_Multi_Store_Pricing_Rules::preview_price((float) $price, $store_config['pricing_rules']);
        return $preview['adjusted'];
    }

    /**
     * Apply stock allocation rules
     *
     * @param int|null $stock_quantity Original stock quantity
     * @param array $store_config Store configuration
     * @return int|null Modified stock quantity
     */
    private function apply_stock_allocation(int|null $stock_quantity, array $store_config): int|null {
        if (empty($stock_quantity) || empty($store_config['stock_allocation_rules']) || !class_exists('WC_Multi_Store_Stock_Allocator')) {
            return $stock_quantity;
        }

        return WC_Multi_Store_Stock_Allocator::calculate_allocation($stock_quantity, $store_config['stock_allocation_rules']);
    }

    /**
     * Compare data to detect changes
     *
     * @param array|null $before Remote product data (before)
     * @param array $after Sync data (after)
     * @param string $sync_type Sync type
     * @return array Array of changes
     */
    private function compare_data(?array $before, array $after, string $sync_type): array {
        $changes = [];

        if (!$before) {
            // New product - all fields are "changes"
            foreach ($after as $key => $value) {
                $changes[$key] = [
                    'before' => null,
                    'after' => $value,
                    'type' => 'new',
                ];
            }
            return $changes;
        }

        // Compare fields
        foreach ($after as $key => $new_value) {
            $old_value = $before[$key] ?? null;

            // Normalize values for comparison
            $old_normalized = $this->normalize_value($old_value);
            $new_normalized = $this->normalize_value($new_value);

            if ($old_normalized !== $new_normalized) {
                $changes[$key] = [
                    'before' => $old_value,
                    'after' => $new_value,
                    'type' => 'modified',
                ];
            }
        }

        return $changes;
    }

    /**
     * Normalize value for comparison
     *
     * @param mixed $value Value to normalize
     * @return mixed Normalized value
     */
    private function normalize_value(mixed $value): mixed {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    /**
     * Detect potential conflicts
     *
     * @param array|null $remote_product Remote product data
     * @param array $sync_data Sync data
     * @return array Array of conflicts
     */
    private function detect_conflicts(?array $remote_product, array $sync_data): array {
        $conflicts = [];

        if (!$remote_product) {
            return $conflicts;
        }

        // Check for potential conflicts
        // Example: Remote stock is higher than what we're trying to sync
        if (isset($sync_data['stock_quantity']) && isset($remote_product['stock_quantity'])) {
            if ($remote_product['stock_quantity'] > $sync_data['stock_quantity']) {
                $conflicts[] = [
                    'field' => 'stock_quantity',
                    'type' => 'warning',
                    'message' => sprintf(
                        __('Remote stock (%d) is higher than local stock (%d). Syncing will reduce remote stock.', 'wc-multi-store-sync'),
                        $remote_product['stock_quantity'],
                        $sync_data['stock_quantity']
                    ),
                ];
            }
        }

        // Check for price conflicts
        if (isset($sync_data['regular_price']) && isset($remote_product['regular_price'])) {
            $price_diff_percent = abs(($remote_product['regular_price'] - $sync_data['regular_price']) / $remote_product['regular_price'] * 100);
            if ($price_diff_percent > 20) { // 20% difference threshold
                $conflicts[] = [
                    'field' => 'regular_price',
                    'type' => 'warning',
                    'message' => sprintf(
                        __('Price difference is significant: Remote: %s, Local: %s (%d%% change)', 'wc-multi-store-sync'),
                        wc_price($remote_product['regular_price']),
                        wc_price($sync_data['regular_price']),
                        round($price_diff_percent)
                    ),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Format preview for display
     *
     * @param array $preview Preview data
     * @return string HTML formatted preview
     */
    public function format_preview_html(array $preview): string {
        if (!$preview['success']) {
            return '<div class="wc-mss-preview-error">' . esc_html($preview['message']) . '</div>';
        }

        $html = '<div class="wc-mss-preview">';
        $html .= '<h3>' . sprintf(__('Preview: %s', 'wc-multi-store-sync'), esc_html($preview['store_name'])) . '</h3>';

        // Action
        $html .= '<p><strong>' . __('Action:', 'wc-multi-store-sync') . '</strong> ';
        $html .= $preview['action'] === 'create' ? __('Create new product', 'wc-multi-store-sync') : __('Update existing product', 'wc-multi-store-sync');
        $html .= '</p>';

        // Conflicts
        if (!empty($preview['conflicts'])) {
            $html .= '<div class="wc-mss-preview-conflicts">';
            $html .= '<h4>' . __('⚠️ Warnings:', 'wc-multi-store-sync') . '</h4>';
            foreach ($preview['conflicts'] as $conflict) {
                $html .= '<div class="wc-mss-conflict wc-mss-conflict-' . esc_attr($conflict['type']) . '">';
                $html .= esc_html($conflict['message']);
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Changes
        if (!empty($preview['changes'])) {
            $html .= '<div class="wc-mss-preview-changes">';
            $html .= '<h4>' . __('Changes:', 'wc-multi-store-sync') . '</h4>';
            $html .= '<table class="wc-mss-preview-table">';
            $html .= '<thead><tr><th>' . __('Field', 'wc-multi-store-sync') . '</th><th>' . __('Before', 'wc-multi-store-sync') . '</th><th>' . __('After', 'wc-multi-store-sync') . '</th></tr></thead>';
            $html .= '<tbody>';

            foreach ($preview['changes'] as $field => $change) {
                $html .= '<tr>';
                $html .= '<td><strong>' . esc_html($this->format_field_name($field)) . '</strong></td>';
                $html .= '<td>' . esc_html($this->format_field_value($field, $change['before'])) . '</td>';
                $html .= '<td>' . esc_html($this->format_field_value($field, $change['after'])) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div>';
        } else {
            $html .= '<p class="wc-mss-no-changes">' . __('No changes detected.', 'wc-multi-store-sync') . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Format field name for display
     *
     * @param string $field Field name
     * @return string Formatted field name
     */
    private function format_field_name(string $field): string {
        $names = [
            'name' => __('Name', 'wc-multi-store-sync'),
            'regular_price' => __('Regular Price', 'wc-multi-store-sync'),
            'sale_price' => __('Sale Price', 'wc-multi-store-sync'),
            'stock_quantity' => __('Stock Quantity', 'wc-multi-store-sync'),
            'stock_status' => __('Stock Status', 'wc-multi-store-sync'),
            'sku' => __('SKU', 'wc-multi-store-sync'),
            'description' => __('Description', 'wc-multi-store-sync'),
            'short_description' => __('Short Description', 'wc-multi-store-sync'),
        ];

        return $names[$field] ?? ucwords(str_replace('_', ' ', $field));
    }

    /**
     * Format field value for display
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @return string Formatted value
     */
    private function format_field_value(string $field, mixed $value): string {
        if ($value === null || $value === '') {
            return __('(empty)', 'wc-multi-store-sync');
        }

        if (in_array($field, ['regular_price', 'sale_price'])) {
            return wc_price($value);
        }

        if (is_bool($value)) {
            return $value ? __('Yes', 'wc-multi-store-sync') : __('No', 'wc-multi-store-sync');
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        // Truncate long text
        if (strlen($value) > 100) {
            return substr($value, 0, 100) . '...';
        }

        return (string) $value;
    }
}
