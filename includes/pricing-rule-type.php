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
            self::NONE => __('No Price Adjustment', 'wc-multi-store-sync'),
            self::FIXED => __('Fixed Amount (+$10, -$5)', 'wc-multi-store-sync'),
            self::PERCENTAGE => __('Percentage (+15%, -10%)', 'wc-multi-store-sync'),
            self::MULTIPLIER => __('Multiplier (1.15x, 0.90x)', 'wc-multi-store-sync'),
            self::CURRENCY => __('Currency Conversion', 'wc-multi-store-sync'),
            self::CUSTOM => __('Custom (via filter)', 'wc-multi-store-sync'),
        };
    }
}
