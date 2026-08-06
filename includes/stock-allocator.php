<?php
/**
 * Stock Allocator
 *
 * Distributes stock across stores intelligently:
 * - Percentage-based allocation (60% to Store A, 40% to Store B)
 * - Fixed quantity allocation (50 units to Store A)
 * - Reserve stock for main store
 * - Priority-based allocation
 * - Auto-rebalance when stock changes
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stock Allocator Class
 */
class WC_Multi_Store_Stock_Allocator {

    /**
     * Calculate allocated stock for a store
     *
     * @param int $total_stock Total available stock
     * @param array $allocation_rules Allocation rules for the store
     * @param array $all_stores_rules All stores' allocation rules
     * @return int Allocated stock quantity
     */
    public static function calculate_allocation(int $total_stock, array $allocation_rules, array $all_stores_rules = []): int {
        if (empty($allocation_rules) || !is_array($allocation_rules)) {
            return $total_stock;
        }

        // Check if allocation is enabled
        if (empty($allocation_rules['enabled'])) {
            return $total_stock;
        }

        $allocation_type = $allocation_rules['type'] ?? 'none';

        return match ($allocation_type) {
            'percentage' => self::calculate_percentage_allocation($total_stock, $allocation_rules),
            'fixed' => self::calculate_fixed_allocation($total_stock, $allocation_rules),
            'priority' => self::calculate_priority_allocation($total_stock, $allocation_rules, $all_stores_rules),
            'reserve' => self::calculate_reserve_allocation($total_stock, $allocation_rules),
            'equal' => self::calculate_equal_allocation($total_stock, $all_stores_rules),
            default => $total_stock,
        };
    }

    /**
     * Calculate percentage-based allocation
     *
     * @param int $total_stock Total stock
     * @param array $allocation_rules Allocation rules
     * @return int Allocated stock
     */
    private static function calculate_percentage_allocation(int $total_stock, array $allocation_rules): int {
        $percentage = floatval($allocation_rules['percentage'] ?? 100);

        // Ensure percentage is between 0 and 100
        $percentage = max(0, min(100, $percentage));

        $allocated = floor($total_stock * ($percentage / 100));

        return intval($allocated);
    }

    /**
     * Calculate fixed quantity allocation
     *
     * @param int $total_stock Total stock
     * @param array $allocation_rules Allocation rules
     * @return int Allocated stock
     */
    private static function calculate_fixed_allocation(int $total_stock, array $allocation_rules): int {
        $fixed_qty = intval($allocation_rules['fixed_quantity'] ?? 0);

        // Don't allocate more than total stock
        $allocated = min($fixed_qty, $total_stock);

        return max(0, $allocated);
    }

    /**
     * Calculate priority-based allocation
     *
     * @param int $total_stock Total stock
     * @param array $allocation_rules Current store's rules
     * @param array $all_stores_rules All stores' rules
     * @return int Allocated stock
     */
    private static function calculate_priority_allocation(int $total_stock, array $allocation_rules, array $all_stores_rules): int {
        $priority = intval($allocation_rules['priority'] ?? 5);

        // Get all stores' priorities
        $priorities = [];
        foreach ($all_stores_rules as $store_url => $rules) {
            if (!empty($rules['enabled']) && isset($rules['priority'])) {
                $priorities[$store_url] = intval($rules['priority']);
            }
        }

        // Calculate total priority points
        $total_priority = array_sum($priorities);

        if ($total_priority <= 0) {
            return $total_stock;
        }

        // Calculate allocation based on priority weight
        $allocated = floor($total_stock * ($priority / $total_priority));

        return intval($allocated);
    }

    /**
     * Calculate allocation with main store reserve
     *
     * @param int $total_stock Total stock
     * @param array $allocation_rules Allocation rules
     * @return int Allocated stock
     */
    private static function calculate_reserve_allocation(int $total_stock, array $allocation_rules): int {
        $reserve_qty = intval($allocation_rules['reserve_quantity'] ?? 0);
        $reserve_percentage = floatval($allocation_rules['reserve_percentage'] ?? 0);

        // Calculate reserve amount
        $reserve = 0;
        if ($reserve_percentage > 0) {
            $reserve = ceil($total_stock * ($reserve_percentage / 100));
        } else {
            $reserve = $reserve_qty;
        }

        // Allocate remaining stock
        $available = $total_stock - $reserve;
        $allocated = max(0, $available);

        // Apply additional percentage if specified
        if (isset($allocation_rules['percentage']) && $allocation_rules['percentage'] > 0) {
            $allocated = floor($allocated * ($allocation_rules['percentage'] / 100));
        }

        return intval($allocated);
    }

    /**
     * Calculate equal allocation across stores
     *
     * @param int $total_stock Total stock
     * @param array $all_stores_rules All stores' rules
     * @return int Allocated stock per store
     */
    private static function calculate_equal_allocation(int $total_stock, array $all_stores_rules): int {
        // Count active stores with allocation enabled
        $active_stores = 0;
        foreach ($all_stores_rules as $rules) {
            if (!empty($rules['enabled'])) {
                $active_stores++;
            }
        }

        if ($active_stores <= 0) {
            return $total_stock;
        }

        $allocated = floor($total_stock / $active_stores);

        return intval($allocated);
    }

    /**
     * Apply stock allocation to product data
     *
     * @param array $product_data Product data array
     * @param array $allocation_rules Allocation rules for the store
     * @param int $original_stock Original stock quantity
     * @param array $all_stores_rules All stores' rules
     * @return array Modified product data with allocated stock
     */
    public static function apply_stock_allocation(array $product_data, array $allocation_rules, int $original_stock, array $all_stores_rules = []): array {
        if (!isset($product_data['stock_quantity'])) {
            return $product_data;
        }

        $allocated_stock = self::calculate_allocation(
            $original_stock,
            $allocation_rules,
            $all_stores_rules
        );

        // Update stock quantity
        $product_data['stock_quantity'] = $allocated_stock;

        // Update stock status based on allocated quantity
        if ($allocated_stock <= 0) {
            $product_data['stock_status'] = 'outofstock';
        } else {
            $product_data['stock_status'] = 'instock';
        }

        return $product_data;
    }

    /**
     * Get default allocation rules structure
     *
     * @return array Default allocation rules
     */
    public static function get_default_rules(): array {
        return [
            'enabled' => false,
            'type' => 'none', // none, percentage, fixed, priority, reserve, equal
            'percentage' => 100,
            'fixed_quantity' => 0,
            'priority' => 5,
            'reserve_quantity' => 0,
            'reserve_percentage' => 0,
        ];
    }

    /**
     * Validate allocation rules
     *
     * @param mixed $rules Allocation rules to validate
     * @return array Validated rules
     */
    public static function validate_rules(mixed $rules): array {
        if (!is_array($rules)) {
            return self::get_default_rules();
        }

        $defaults = self::get_default_rules();
        $validated = wp_parse_args($rules, $defaults);

        // Validate type
        $valid_types = ['none', 'percentage', 'fixed', 'priority', 'reserve', 'equal'];
        if (!in_array($validated['type'], $valid_types)) {
            $validated['type'] = 'none';
        }

        // Validate numeric values
        $validated['percentage'] = max(0, min(100, floatval($validated['percentage'])));
        $validated['fixed_quantity'] = max(0, intval($validated['fixed_quantity']));
        $validated['priority'] = max(1, min(10, intval($validated['priority'])));
        $validated['reserve_quantity'] = max(0, intval($validated['reserve_quantity']));
        $validated['reserve_percentage'] = max(0, min(100, floatval($validated['reserve_percentage'])));

        return $validated;
    }

    /**
     * Get allocation types with labels
     *
     * @return array Allocation types
     */
    public static function get_allocation_types(): array {
        return [
            'none' => __('No Allocation (Use Full Stock)', 'wc-multi-store-sync'),
            'percentage' => __('Percentage (60%, 40%, etc.)', 'wc-multi-store-sync'),
            'fixed' => __('Fixed Quantity (50 units)', 'wc-multi-store-sync'),
            'priority' => __('Priority-Based (1-10)', 'wc-multi-store-sync'),
            'reserve' => __('Reserve Stock for Main Store', 'wc-multi-store-sync'),
            'equal' => __('Equal Distribution', 'wc-multi-store-sync'),
        ];
    }

    /**
     * Preview stock allocation
     *
     * @param int $total_stock Total stock
     * @param array $all_stores_with_rules All stores with their rules
     * @return array Preview of stock allocation per store
     */
    public static function preview_allocation(int $total_stock, array $all_stores_with_rules): array {
        $preview = [];
        $total_allocated = 0;

        foreach ($all_stores_with_rules as $store_url => $rules) {
            $allocated = self::calculate_allocation($total_stock, $rules, $all_stores_with_rules);

            $preview[$store_url] = [
                'allocated' => $allocated,
                'percentage' => $total_stock > 0 ? round(($allocated / $total_stock) * 100, 2) : 0,
            ];

            $total_allocated += $allocated;
        }

        $preview['_summary'] = [
            'total_stock' => $total_stock,
            'total_allocated' => $total_allocated,
            'unallocated' => $total_stock - $total_allocated,
            'over_allocated' => $total_allocated > $total_stock,
        ];

        return $preview;
    }

    /**
     * Validate total allocation doesn't exceed stock
     *
     * @param int $total_stock Total stock
     * @param array $all_stores_with_rules All stores with their rules
     * @return array Validation result with warnings
     */
    public static function validate_total_allocation(int $total_stock, array $all_stores_with_rules): array {
        $preview = self::preview_allocation($total_stock, $all_stores_with_rules);

        $warnings = [];

        if ($preview['_summary']['over_allocated']) {
            $warnings[] = sprintf(
                __('Total allocated stock (%d) exceeds available stock (%d)', 'wc-multi-store-sync'),
                $preview['_summary']['total_allocated'],
                $total_stock
            );
        }

        if ($preview['_summary']['unallocated'] > 0) {
            $warnings[] = sprintf(
                __('%d units of stock will not be allocated to any store', 'wc-multi-store-sync'),
                $preview['_summary']['unallocated']
            );
        }

        return [
            'valid' => empty($warnings),
            'warnings' => $warnings,
            'preview' => $preview,
        ];
    }
}
