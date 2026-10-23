<?php
/**
 * Image API Transfer
 * Transfers product images via WordPress REST API media endpoint.
 *
 * When the source site is behind Cloudflare or other CDN/WAF, the remote store
 * cannot download images via standard URLs (gets 403 Forbidden).
 *
 * This class reads images from local disk and the API client uploads them
 * as binary data to the remote store's standard WordPress media endpoint
 * (POST /wp-json/wp/v2/media). No plugin or custom endpoint needed on the
 * receiving side — just WooCommerce with API keys from an Admin user.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Image_Proxy {

    /**
     * Get image data for API transfer
     *
     * Called on the SENDING (parent) site to prepare image data.
     * Tries local filesystem first (fastest). Falls back to the public attachment
     * URL so images stored externally (S3, cloud storage, CDN) work too.
     *
     * The returned array always contains 'filename' and 'mime_type'.
     * Exactly one of 'file_path' (local) or 'url' (remote) will be set —
     * api-client.php checks which key is present to decide how to read the bytes.
     *
     * @param int $attachment_id Local attachment ID
     * @return array|null {file_path|url, filename, mime_type} or null if unavailable
     */
    public static function get_image_data(int $attachment_id): ?array {
        $mime_type = get_post_mime_type($attachment_id);
        if (!$mime_type || !str_starts_with($mime_type, 'image/')) {
            return null;
        }

        // Prefer local file — no HTTP round-trip needed
        $file_path = get_attached_file($attachment_id);
        if ($file_path && self::is_inside_uploads($file_path)) {
            return [
                'file_path' => $file_path,
                'filename'  => basename($file_path),
                'mime_type' => $mime_type,
            ];
        }

        // File not on local disk (S3, cloud storage, etc.) — use the public URL
        $url = wp_get_attachment_url($attachment_id);
        if (!$url || !self::is_fetchable_url($url)) {
            return null;
        }

        $filename = basename((string) parse_url($url, PHP_URL_PATH));

        return [
            'url'       => $url,
            'filename'  => $filename !== '' ? $filename : 'image-' . $attachment_id,
            'mime_type' => $mime_type,
        ];
    }

    /**
     * A local attachment is only read when it is a real file inside the uploads directory;
     * a tampered _wp_attached_file meta must not make the plugin upload /etc/hosts.
     */
    private static function is_inside_uploads(string $file_path): bool {
        if (!is_file($file_path)) {
            return false;
        }

        $uploads = wp_upload_dir();
        $base = realpath($uploads['basedir']);
        $real = realpath($file_path);
        if ($base === false || $real === false) {
            return false;
        }

        return str_starts_with($real, rtrim($base, '/\\') . DIRECTORY_SEPARATOR);
    }

    /** Only http(s) URLs are fetched, and never literal link-local (cloud metadata) addresses. */
    private static function is_fetchable_url(string $url): bool {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }

        return !filter_var($host, FILTER_VALIDATE_IP) || WC_Multi_Store_Settings::is_safe_remote_url($url);
    }

    /**
     * Check if API image transfer is enabled in settings
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) WC_Multi_Store_Settings::get('image_proxy_enabled', false);
    }
}
