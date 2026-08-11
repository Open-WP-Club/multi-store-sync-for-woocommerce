<?php
/**
 * Product Transformer
 * Applies per-store rules (pricing, stock allocation) to product data
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Transformer Class
 */
class WC_Multi_Store_Product_Transformer {

    /**
     * Cached allocation rules for all stores (per-request cache)
     *
     * @var array|null
     */
    private $cached_allocation_rules = null;

    /**
     * Product IDs already checked for stock-allocation over-allocation this
     * run, so maybe_warn_over_allocation() logs at most once per product
     * even though apply_store_rules() is called once per store.
     *
     * @var array<int, true>
     */
    private array $checked_allocation_products = [];

    /**
     * Apply per-store rules to product data (pricing and stock allocation)
     *
     * @param array $product_data Product data array
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return array Modified product data
     */
    public function apply_store_rules(array $product_data, WC_Product $product, array $store_config): array {
        // Apply per-store pricing rules if configured
        if (isset($store_config['pricing_rules'])) {
            $product_data = WC_Multi_Store_Pricing_Rules::apply_pricing_rules(
                $product_data,
                $store_config['pricing_rules'],
                $product
            );
        }

        // Apply stock allocation rules if configured
        if (isset($store_config['stock_allocation_rules']) && isset($product_data['stock_quantity'])) {
            $all_allocation_rules = $this->get_all_allocation_rules();

            $product_data = WC_Multi_Store_Stock_Allocator::apply_stock_allocation(
                $product_data,
                $store_config['stock_allocation_rules'],
                $product->get_stock_quantity(),
                $all_allocation_rules
            );

            $this->maybe_warn_over_allocation($product, $all_allocation_rules);
        }

        // Notify if stock destined for this store is low. Rate-limited and
        // gated by settings inside send_low_stock_notification() — safe to
        // call unconditionally here.
        if (isset($product_data['stock_quantity']) && !empty($store_config['store_url'])) {
            WC_Multi_Store_Email_Notifications::trigger_low_stock(
                $product->get_id(),
                $store_config['store_url'],
                (int) $product_data['stock_quantity']
            );
        }

        // Apply category mapping per store
        $store_url = $store_config['store_url'] ?? '';
        if ($store_url) {
            $product_data = WC_Multi_Store_Category_Mapper::apply_mappings($product_data, $store_url);
            $product_data = WC_Multi_Store_Category_Mapper::apply_tag_mappings($product_data, $store_url);

            // Apply attribute remapping per store
            $product_data = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, $store_url);
            $product_data = WC_Multi_Store_Attribute_Remapper::apply_default_attribute_mappings($product_data, $store_url);
        }

        // Add shipping class slug for shipping class sync
        $shipping_class_slug = WC_Multi_Store_Shipping_Class_Sync::get_product_shipping_class_slug($product);
        if ($shipping_class_slug) {
            $product_data['shipping_class'] = $shipping_class_slug;
        }

        return $product_data;
    }

    /**
     * Apply per-store pricing rules to variation data
     *
     * @param array $variation_data Variation data array
     * @param array $store_config Store configuration
     * @return array Modified variation data
     */
    public function apply_variation_pricing_rules(array $variation_data, array $store_config): array {
        if (isset($store_config['pricing_rules'])) {
            $variation_data = WC_Multi_Store_Pricing_Rules::apply_pricing_rules(
                $variation_data,
                $store_config['pricing_rules']
            );
        }

        // Apply attribute remapping to variation attributes
        $store_url = $store_config['store_url'] ?? '';
        if ($store_url) {
            $variation_data = WC_Multi_Store_Attribute_Remapper::apply_variation_mappings($variation_data, $store_url);
        }

        return $variation_data;
    }

    /**
     * Apply per-store stock allocation rules to variation data
     *
     * @param array $variation_data Variation data array
     * @param WC_Product_Variation $variation Variation object
     * @param array $store_config Store configuration
     * @return array Modified variation data
     */
    public function apply_variation_stock_rules(array $variation_data, WC_Product_Variation $variation, array $store_config): array {
        if (isset($store_config['stock_allocation_rules']) && isset($variation_data['stock_quantity'])) {
            $all_allocation_rules = $this->get_all_allocation_rules();

            $variation_data = WC_Multi_Store_Stock_Allocator::apply_stock_allocation(
                $variation_data,
                $store_config['stock_allocation_rules'],
                $variation->get_stock_quantity(),
                $all_allocation_rules
            );

            $this->maybe_warn_over_allocation($variation, $all_allocation_rules);
        }

        return $variation_data;
    }

    /**
     * Get cached allocation rules for all stores
     *
     * @return array Allocation rules keyed by store URL
     */
    private function get_all_allocation_rules(): array {
        if ($this->cached_allocation_rules === null) {
            $this->cached_allocation_rules = [];
            $all_stores = WC_Multi_Store_Settings::get_active_stores();
            foreach ($all_stores as $url => $config) {
                if (isset($config['stock_allocation_rules'])) {
                    $this->cached_allocation_rules[$url] = $config['stock_allocation_rules'];
                }
            }
        }
        return $this->cached_allocation_rules;
    }

    /**
     * Log a warning (once per product/variation per transformer instance —
     * apply_store_rules()/apply_variation_stock_rules() run once PER STORE,
     * so without dedup this would log once per active store for the same
     * misconfiguration) when the configured per-store allocation rules add
     * up to more than the physical stock on hand.
     *
     * This does NOT change what gets sent to any store — calculate_allocation()
     * intentionally still computes each store's share independently, matching
     * this class's existing behavior and every current test — it only surfaces
     * WC_Multi_Store_Stock_Allocator::validate_total_allocation()'s existing,
     * already-tested detection logic (previously wired up nowhere outside its
     * own unit tests) so admins can see and fix an over-committed rule set,
     * e.g. two stores both configured for 60% of the same physical stock.
     *
     * @param WC_Product|WC_Product_Variation $product_or_variation
     * @param array $all_allocation_rules Output of get_all_allocation_rules()
     */
    private function maybe_warn_over_allocation($product_or_variation, array $all_allocation_rules): void {
        $id = $product_or_variation->get_id();
        if (isset($this->checked_allocation_products[$id])) {
            return;
        }
        $this->checked_allocation_products[$id] = true;

        // Over-allocation across stores is only possible with 2+ stores
        // configuring rules for the same product.
        if (count($all_allocation_rules) < 2) {
            return;
        }

        $total_stock = $product_or_variation->get_stock_quantity();
        $validation  = WC_Multi_Store_Stock_Allocator::validate_total_allocation($total_stock, $all_allocation_rules);

        if (empty($validation['preview']['_summary']['over_allocated'])) {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Stock allocation for "%s" (ID %d) is over-committed: rules across active stores add up to %d units but only %d are physically in stock. Review each store\'s Stock Allocation Rules.',
            $product_or_variation->get_sku(),
            $id,
            $validation['preview']['_summary']['total_allocated'],
            $total_stock
        ), 'warning');
    }

    /**
     * Clear cached allocation rules (call when store config changes)
     *
     * @return void
     */
    public function clear_cache(): void {
        $this->cached_allocation_rules = null;
    }
}
