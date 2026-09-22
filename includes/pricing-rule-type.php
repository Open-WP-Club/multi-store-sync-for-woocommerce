<?php
/**
 * Pricing rule type
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

enum WC_Multi_Store_Pricing_Rule_Type: string {
    case NONE = 'none';
    case FIXED = 'fixed';
    case PERCENTAGE = 'percentage';
    case MULTIPLIER = 'multiplier';
    case CURRENCY = 'currency';
    case CUSTOM = 'custom';

    /**
     * Human-readable label for this rule type
     */
    public function label(): string {
        return match ($this) {
            self::NONE => __('No Price Adjustment', 'multi-store-sync-for-woocommerce'),
            self::FIXED => __('Fixed Amount (+$10, -$5)', 'multi-store-sync-for-woocommerce'),
            self::PERCENTAGE => __('Percentage (+15%, -10%)', 'multi-store-sync-for-woocommerce'),
            self::MULTIPLIER => __('Multiplier (1.15x, 0.90x)', 'multi-store-sync-for-woocommerce'),
            self::CURRENCY => __('Currency Conversion', 'multi-store-sync-for-woocommerce'),
            self::CUSTOM => __('Custom (via filter)', 'multi-store-sync-for-woocommerce'),
        };
    }
}
