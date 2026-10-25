<?php
/**
 * Downloadable Files Sync
 * Synchronizes downloadable product files to remote stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Downloadable_Files_Sync {
    use WC_Multi_Store_Toggleable_Feature;

    /**
     * @return array
     */
    public static function default_settings(): array {
        return [
            'enabled' => false,
            'transfer_mode' => 'url', // Legacy 'api' values also preserve the original URL.
        ];
    }

    /**
     * @return string
     */
    public static function feature_label(): string {
        return __('Downloadable files sync', 'multi-store-sync-for-woocommerce');
    }

    /**
     * @return string
     */
    public static function central_settings_prefix(): string {
        return 'downloadable_files_sync';
    }

    /**
     * Extract downloadable file data from a product
     *
     * @param WC_Product $product Product object
     * @return array Downloadable file data for API
     */
    public static function extract_downloads(WC_Product $product): array {
        if (!self::is_enabled()) {
            return [];
        }

        if (!$product->is_downloadable()) {
            return [];
        }

        $downloads = $product->get_downloads();
        if (empty($downloads)) {
            return [];
        }

        $download_data = [];
        foreach ($downloads as $download_id => $download) {
            $file_data = [
                'name' => $download->get_name(),
                'file' => $download->get_file(),
            ];

            // Preserve download protection: the standard WordPress Media API stores
            // public files. Legacy API mode therefore falls back to the original
            // URL and never reads local paths or embeds their contents.

            $download_data[] = $file_data;
        }

        return $download_data;
    }

    /**
     * Sync downloadable files for a product to a remote store
     * Keeps original URLs so download access controls remain on the source store.
     *
     * @param WC_Multi_Store_API_Client $client API client for the remote store
     * @param WC_Product $product Local product
     * @param array $product_data Product data being sent to remote
     * @param string $store_url Remote store URL
     * @return array Modified product data with remote file URLs
     */
    public static function sync_downloads(
        WC_Multi_Store_API_Client $client,
        WC_Product $product,
        array $product_data,
        string $store_url
    ): array {
        if (!$product->is_downloadable()) {
            return $product_data;
        }

        $downloads = self::extract_downloads($product);
        if (empty($downloads)) {
            return $product_data;
        }

        $remote_downloads = [];
        foreach ($downloads as $download) {
            $remote_downloads[] = [
                'name' => $download['name'],
                'file' => $download['file'],
            ];
        }

        // Add download properties to product data
        $product_data['downloadable'] = true;
        $product_data['downloads'] = $remote_downloads;
        $product_data['download_limit'] = $product->get_download_limit();
        $product_data['download_expiry'] = $product->get_download_expiry();

        return $product_data;
    }

}
