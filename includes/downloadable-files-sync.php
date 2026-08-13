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
            'transfer_mode' => 'url', // 'url' = use original URL, 'api' = upload via Media API
        ];
    }

    /**
     * @return string
     */
    public static function feature_label(): string {
        return __('Downloadable files sync', 'wc-multi-store-sync');
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

            // If using API transfer mode, encode the file content
            $dl_settings = self::get_settings();
            if (($dl_settings['transfer_mode'] ?? 'url') === 'api') {
                $local_path = self::resolve_download_path($download->get_file());
                if ($local_path && file_exists($local_path)) {
                    if (filesize($local_path) > 10 * MB_IN_BYTES) {
                        WC_Multi_Store_Logger::write("File too large for inline sync: {$local_path}", 'warning');
                        continue;
                    }
                    $content = file_get_contents($local_path);
                    if ($content === false) {
                        WC_Multi_Store_Logger::write("Cannot read downloadable file: {$local_path}", 'error');
                    } else {
                        $file_data['_wc_mss_file_content'] = base64_encode($content);
                        $file_data['_wc_mss_file_name'] = basename($local_path);
                        $file_data['_wc_mss_mime_type'] = wp_check_filetype($local_path)['type'] ?? 'application/octet-stream';
                    }
                }
            }

            $download_data[] = $file_data;
        }

        return $download_data;
    }

    /**
     * Sync downloadable files for a product to a remote store
     * Handles file upload via WordPress Media API when in transfer mode
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

        $dl_settings = self::get_settings();
        $transfer_enabled = ($dl_settings['transfer_mode'] ?? 'url') === 'api';

        $remote_downloads = [];
        foreach ($downloads as $download) {
            if ($transfer_enabled && !empty($download['_wc_mss_file_content'])) {
                // Upload file to remote store via Media API
                $remote_url = self::upload_file_to_remote(
                    $client,
                    $download['_wc_mss_file_content'],
                    $download['_wc_mss_file_name'],
                    $download['_wc_mss_mime_type'],
                    $store_url
                );

                if ($remote_url) {
                    $remote_downloads[] = [
                        'name' => $download['name'],
                        'file' => $remote_url,
                    ];

                    WC_Multi_Store_Logger::write(sprintf(
                        'Downloadable file "%s" uploaded to %s',
                        $download['name'],
                        $store_url
                    ));
                } else {
                    // Fallback: use original URL (remote store must be able to access it)
                    $remote_downloads[] = [
                        'name' => $download['name'],
                        'file' => $download['file'],
                    ];

                    WC_Multi_Store_Logger::write(sprintf(
                        'Failed to upload file "%s" to %s, using original URL as fallback',
                        $download['name'],
                        $store_url
                    ), 'warning');
                }
            } else {
                // No transfer mode: use the original URL directly
                $remote_downloads[] = [
                    'name' => $download['name'],
                    'file' => $download['file'],
                ];
            }
        }

        // Add download properties to product data
        $product_data['downloadable'] = true;
        $product_data['downloads'] = $remote_downloads;
        $product_data['download_limit'] = $product->get_download_limit();
        $product_data['download_expiry'] = $product->get_download_expiry();

        return $product_data;
    }

    /**
     * Upload a file to a remote store via the WordPress Media REST API
     *
     * @param WC_Multi_Store_API_Client $client API client
     * @param string $base64_content Base64-encoded file content
     * @param string $filename Original filename
     * @param string $mime_type MIME type
     * @param string $store_url Store URL for logging
     * @return string|null Remote file URL or null on failure
     */
    private static function upload_file_to_remote(
        WC_Multi_Store_API_Client $client,
        string $base64_content,
        string $filename,
        string $mime_type,
        string $store_url
    ): ?string {
        $file_content = base64_decode($base64_content);
        if ($file_content === false) {
            return null;
        }

        // Reuse the API client's WordPress Media REST upload (wp/v2/media) —
        // the same endpoint/auth path already used for product images.
        $tmp_path = wp_tempnam($filename);
        if (!$tmp_path || file_put_contents($tmp_path, $file_content) === false) {
            return null;
        }

        $response = $client->upload_image([
            'file_path' => $tmp_path,
            'filename' => $filename,
            'mime_type' => $mime_type,
        ]);

        wp_delete_file($tmp_path);

        if (is_wp_error($response) || empty($response['source_url'])) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to upload downloadable file "%s" to %s: %s',
                $filename,
                $store_url,
                is_wp_error($response) ? $response->get_error_message() : 'No URL returned'
            ), 'error');
            return null;
        }

        return $response['source_url'];
    }

    /**
     * Resolve a download URL to a local file path
     *
     * @param string $file_url Download URL or path
     * @return string|null Local file path or null
     */
    private static function resolve_download_path(string $file_url): ?string {
        // If it's already a local path
        if (file_exists($file_url)) {
            return $file_url;
        }

        // Try to convert URL to local path
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $upload_path = $upload_dir['basedir'];

        if (str_starts_with($file_url, $upload_url)) {
            $relative = str_replace($upload_url, '', $file_url);
            $local_path = $upload_path . $relative;
            if (file_exists($local_path)) {
                return $local_path;
            }
        }

        // Try site URL conversion
        $site_url = site_url();
        if (str_starts_with($file_url, $site_url)) {
            $relative = str_replace($site_url, '', $file_url);
            $local_path = ABSPATH . ltrim($relative, '/');
            if (file_exists($local_path)) {
                return $local_path;
            }
        }

        return null;
    }
}
