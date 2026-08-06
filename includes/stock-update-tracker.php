<?php
/**
 * Stock Update Tracker
 * Manages stock update metadata to prevent race conditions with centralized master approach
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Stock_Update_Tracker {

    /**
     * Meta key for last stock update timestamp
     */
    const string META_LAST_UPDATE = '_wc_mss_stock_last_update';

    /**
     * Meta key for stock update source
     */
    const string META_UPDATE_SOURCE = '_wc_mss_stock_update_source';

    /**
     * Meta key for master store sync version
     */
    const string META_SYNC_VERSION = '_wc_mss_stock_sync_version';

    /**
     * Record a stock update from webhook (master store deduction)
     *
     * @param int $product_id Product ID
     * @param string $source_store URL of store that sent the webhook
     * @return void
     */
    public static function record_webhook_update(int $product_id, string $source_store): void {
        $timestamp = time();
        $version = self::increment_sync_version($product_id);

        update_post_meta($product_id, self::META_LAST_UPDATE, $timestamp);
        update_post_meta($product_id, self::META_UPDATE_SOURCE, 'webhook');
        update_post_meta($product_id, self::META_SYNC_VERSION, $version);

        WC_Multi_Store_Logger::write(sprintf(
            'Stock update recorded for product #%d: source=webhook, version=%d, store=%s',
            $product_id,
            $version,
            $source_store
        ));
    }

    /**
     * Record a stock sync to remote store
     *
     * @param int $product_id Product ID
     * @param string $target_store URL of store being synced to
     * @return void
     */
    public static function record_sync_to_store(int $product_id, string $target_store): void {
        $timestamp = time();
        update_post_meta($product_id, self::META_LAST_UPDATE, $timestamp);
        update_post_meta($product_id, self::META_UPDATE_SOURCE, 'master_sync');
    }

    /**
     * Get current sync version for a product
     *
     * @param int $product_id Product ID
     * @return int Sync version
     */
    public static function get_sync_version(int $product_id): int {
        $version = get_post_meta($product_id, self::META_SYNC_VERSION, true);
        return $version ? intval($version) : 0;
    }

    /**
     * Increment and return new sync version
     *
     * @param int $product_id Product ID
     * @return int New version number
     */
    private static function increment_sync_version(int $product_id): int {
        $current = self::get_sync_version($product_id);
        $new_version = $current + 1;
        update_post_meta($product_id, self::META_SYNC_VERSION, $new_version);
        return $new_version;
    }

}
