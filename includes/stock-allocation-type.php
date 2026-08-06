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
            self::NONE => __('No Allocation (Use Full Stock)', 'wc-multi-store-sync'),
            self::PERCENTAGE => __('Percentage (60%, 40%, etc.)', 'wc-multi-store-sync'),
            self::FIXED => __('Fixed Quantity (50 units)', 'wc-multi-store-sync'),
            self::PRIORITY => __('Priority-Based (1-10)', 'wc-multi-store-sync'),
            self::RESERVE => __('Reserve Stock for Main Store', 'wc-multi-store-sync'),
            self::EQUAL => __('Equal Distribution', 'wc-multi-store-sync'),
        };
    }
}
