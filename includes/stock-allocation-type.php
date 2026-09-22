<?php
/**
 * Stock allocation type
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

enum WC_Multi_Store_Stock_Allocation_Type: string {
    case NONE = 'none';
    case PERCENTAGE = 'percentage';
    case FIXED = 'fixed';
    case PRIORITY = 'priority';
    case RESERVE = 'reserve';
    case EQUAL = 'equal';

    /**
     * Human-readable label for this allocation type
     */
    public function label(): string {
        return match ($this) {
            self::NONE => __('No Allocation (Use Full Stock)', 'multi-store-sync-for-woocommerce'),
            self::PERCENTAGE => __('Percentage (60%, 40%, etc.)', 'multi-store-sync-for-woocommerce'),
            self::FIXED => __('Fixed Quantity (50 units)', 'multi-store-sync-for-woocommerce'),
            self::PRIORITY => __('Priority-Based (1-10)', 'multi-store-sync-for-woocommerce'),
            self::RESERVE => __('Reserve Stock for Main Store', 'multi-store-sync-for-woocommerce'),
            self::EQUAL => __('Equal Distribution', 'multi-store-sync-for-woocommerce'),
        };
    }
}
