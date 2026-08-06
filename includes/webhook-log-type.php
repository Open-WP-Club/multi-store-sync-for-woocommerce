<?php
/**
 * Webhook log type
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

enum WC_Multi_Store_Webhook_Log_Type: string {
    case ORDER_RECEIVED = 'order_received';
    case STOCK_DEDUCTED = 'stock_deducted';
    case STOCK_SYNCED = 'stock_synced';
    case AUTH_FAILED = 'auth_failed';
    case VALIDATION_ERROR = 'validation_error';
    case PRODUCT_NOT_FOUND = 'product_not_found';
    case RATE_LIMITED = 'rate_limited';

    /**
     * Human-readable label for this log type
     */
    public function label(): string {
        return match ($this) {
            self::ORDER_RECEIVED => __('Order received', 'wc-multi-store-sync'),
            self::STOCK_DEDUCTED => __('Stock deducted', 'wc-multi-store-sync'),
            self::STOCK_SYNCED => __('Stock synced', 'wc-multi-store-sync'),
            self::AUTH_FAILED => __('Auth failed', 'wc-multi-store-sync'),
            self::VALIDATION_ERROR => __('Validation error', 'wc-multi-store-sync'),
            self::PRODUCT_NOT_FOUND => __('Product not found', 'wc-multi-store-sync'),
            self::RATE_LIMITED => __('Rate limited', 'wc-multi-store-sync'),
        };
    }
}
