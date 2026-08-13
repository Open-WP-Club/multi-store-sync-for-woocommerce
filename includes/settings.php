<?php
/**
 * WooCommerce Multi-Store Settings Manager
 *
 * Handles plugin settings and configuration
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings Manager Class
 */
class WC_Multi_Store_Settings {

    /**
     * In-memory static cache for settings (avoids repeated DB/transient calls within same request)
     *
     * @var array|null
     */
    private static $settings_cache = null;

    /**
     * In-memory static cache for active stores
     *
     * @var array|null
     */
    private static $active_stores_cache = null;

    /**
     * In-memory static cache for webhook settings
     *
     * @var array|null
     */
    private static $webhook_settings_cache = null;

    /**
     * Clear in-memory caches (called when settings are updated)
     */
    public static function clear_static_cache(): void {
        self::$settings_cache = null;
        self::$active_stores_cache = null;
        self::$webhook_settings_cache = null;
    }

    /**
     * Get webhook settings with request-scoped static cache.
     * Avoids repeated get_option() hits inside the webhook handler and sync hot loop.
     *
     * @param bool $use_cache Whether to use the static cache
     * @return array Webhook settings array (raw, no defaults merged)
     */
    public static function get_webhook_settings(bool $use_cache = true): array {
        if ($use_cache && self::$webhook_settings_cache !== null) {
            return self::$webhook_settings_cache;
        }

        $settings = get_option('wc_multi_store_sync_webhook_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        if ($use_cache) {
            self::$webhook_settings_cache = $settings;
        }

        return $settings;
    }

    /**
     * Get all settings
     *
     * @param bool $use_cache Whether to use cache
     * @return array Settings array
     */
    public static function get_settings(bool $use_cache = true): array {
        $defaults = [
            'enabled' => false,
            'sync_type_default' => 'full_product',
            'auth_method' => 'basic_auth',
            'match_products_by' => 'sku',
            'category_match_mode' => 'full_path',
            'category_match_by' => 'slug',
            'category_auto_create' => true,
            'stock_sync_enabled' => true,
            'delete_orphan_variations' => false,
            'auto_create_missing_products' => false,
            'image_proxy_enabled' => false,
            'cleanup_on_uninstall' => false,
            'circuit_breaker_threshold' => 10,
            'circuit_breaker_duration'  => 1800,
            'category_mapper_enabled' => false,
            'attribute_remapping_enabled' => false,
            'shipping_class_sync_enabled' => false,
            'shipping_class_sync_auto_sync_on_change' => true,
            'downloadable_files_sync_enabled' => false,
            'downloadable_files_sync_transfer_mode' => 'url',
        ];

        // In-memory static cache (per-request). No transient/object-cache layer
        // on top of this: get_option() is already served from WP's own options
        // cache (persistent when an external object cache is active, otherwise
        // a transient would cost the same DB round trip get_option() does), so
        // a second cache here would only add an invalidation path to keep in
        // sync for no real benefit.
        if ($use_cache && self::$settings_cache !== null) {
            return wp_parse_args(self::$settings_cache, $defaults);
        }

        $settings = get_option('wc_multi_store_sync_settings', []);

        if ($use_cache) {
            self::$settings_cache = $settings;
        }

        return wp_parse_args($settings, $defaults);
    }

    /**
     * Get single setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    public static function get(string $key, mixed $default = null): mixed {
        $settings = self::get_settings();
        return $settings[$key] ?? $default;
    }

    /**
     * Update setting
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool Success
     */
    public static function update(string $key, mixed $value): bool {
        $settings = self::get_settings();
        $settings[$key] = $value;
        $result = update_option('wc_multi_store_sync_settings', $settings);

        if ($result) {
            self::clear_static_cache();
        }

        return $result;
    }

    /**
     * Update all settings
     *
     * @param array $settings Settings array
     * @return bool Success
     */
    public static function update_all(array $settings): bool {
        $result = update_option('wc_multi_store_sync_settings', $settings);

        if ($result) {
            self::clear_static_cache();
        }

        return $result;
    }

    /**
     * Get all stores
     *
     * @return array Stores configuration keyed by store URL
     */
    public static function get_stores(): array {
        $stores = get_option('wc_multi_store_sync_stores', []);

        if (!is_array($stores)) {
            return [];
        }

        // Guard against corrupted data: keys must be non-empty strings (store URLs).
        // Numeric or null keys indicate data saved in the wrong format.
        $clean = [];
        foreach ($stores as $key => $config) {
            if (is_string($key) && $key !== '' && is_array($config)) {
                $clean[$key] = $config;
            }
        }

        return $clean;
    }

    /**
     * Get single store
     *
     * @param string $store_url Store URL
     * @return array|null Store configuration or null
     */
    public static function get_store(string $store_url): ?array {
        $stores = self::get_stores();
        return $stores[$store_url] ?? null;
    }

    /**
     * SSRF guard: block link-local targets (covers cloud-metadata IPs like
     * 169.254.169.254 on AWS/GCP/Azure) before we let the server dial an
     * admin-supplied URL. Private ranges (10/8, 192.168/16) and loopback are
     * intentionally allowed because the plugin officially supports localhost
     * and self-hosted dev environments — see validate_store_url() below.
     */
    public static function is_safe_remote_url(string $url): bool {
        $parsed = wp_parse_url($url);
        $host   = $parsed['host'] ?? '';
        if ($host === '') {
            return false;
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    if (!empty($rec['ip']))   { $ips[] = $rec['ip']; }
                    if (!empty($rec['ipv6'])) { $ips[] = $rec['ipv6']; }
                }
            }
            if (empty($ips)) {
                $resolved = gethostbyname($host);
                if ($resolved !== $host) {
                    $ips[] = $resolved;
                }
            }
        }

        foreach ($ips as $ip) {
            if (self::is_blocked_link_local_ip($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pure IP-range check used by is_safe_remote_url() above. Split out so
     * validate_store_url() can reject a literal link-local IP entered directly
     * as a store URL (e.g. "http://169.254.169.254/") without performing a DNS
     * lookup — DNS-based checks stay confined to the explicit "Test Connection"
     * flow (is_safe_remote_url()) so store save/import doesn't gain a
     * dependency on network/DNS availability, and existing unit tests that
     * mock wp_parse_url() with strict call counts keep working.
     */
    private static function is_blocked_link_local_ip(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // 169.254.0.0/16 — IPv4 link-local (cloud metadata).
            return strpos($ip, '169.254.') === 0;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // fe80::/10 — IPv6 link-local.
            $lower = strtolower($ip);
            return strpos($lower, 'fe8') === 0 || strpos($lower, 'fe9') === 0
                || strpos($lower, 'fea') === 0 || strpos($lower, 'feb') === 0;
        }
        return false;
    }

    /**
     * Validate store URL
     *
     * @param string $url URL to validate
     * @return string|WP_Error Sanitized URL or WP_Error
     */
    public static function validate_store_url(string $url): string|\WP_Error {
        // Sanitize URL
        $url = esc_url_raw(trim($url));

        // Validate URL format
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new \WP_Error(
                'invalid_url',
                __('Invalid store URL format.', 'wc-multi-store-sync')
            );
        }

        // Ensure HTTPS for security (allow HTTP only for localhost/dev)
        $parsed = wp_parse_url($url);
        $host = $parsed['host'] ?? '';

        $is_local = in_array($host, ['localhost', '127.0.0.1'], true) ||
                    preg_match('/\.local$/', $host) ||
                    preg_match('/\.test$/', $host);

        if (!$is_local && isset($parsed['scheme']) && $parsed['scheme'] !== 'https') {
            return new \WP_Error(
                'insecure_url',
                __('Store URL must use HTTPS for security.', 'wc-multi-store-sync')
            );
        }

        // SSRF guard: reject a literal link-local/cloud-metadata IP (e.g.
        // 169.254.169.254) entered directly as the store URL, on every path
        // that persists a store (add/update form, config import) — not just
        // the "Test Connection" button. This is the literal-IP fast path only
        // (see is_blocked_link_local_ip()); DNS-name targets that resolve to a
        // blocked range are still caught at "Test Connection" time via the
        // full is_safe_remote_url() check, which intentionally isn't run here
        // to keep store save/import free of a DNS-availability dependency.
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) && self::is_blocked_link_local_ip($host)) {
            return new \WP_Error(
                'unsafe_url',
                __('Store URL resolves to a blocked address range.', 'wc-multi-store-sync')
            );
        }

        // Remove trailing slash for consistency
        return rtrim($url, '/');
    }

    /**
     * Add or update store
     *
     * @param string $store_url Store URL
     * @param array $config Store configuration
     * @return bool|WP_Error Success or WP_Error
     */
    public static function update_store(string $store_url, array $config): bool|\WP_Error {
        // Validate URL
        $validated_url = self::validate_store_url($store_url);
        if (is_wp_error($validated_url)) {
            return $validated_url;
        }

        $stores = self::get_stores();
        $stores[$validated_url] = $config;
        $result = update_option('wc_multi_store_sync_stores', $stores);

        // Clear cache when stores are updated
        if ($result) {
            self::$active_stores_cache = null;
            WC_Multi_Store_Cache_Manager::clear_store_cache($store_url);
        }

        return $result;
    }

    /**
     * Delete store
     *
     * @param string $store_url Store URL
     * @return bool Success
     */
    public static function delete_store(string $store_url): bool {
        $stores = self::get_stores();
        if (isset($stores[$store_url])) {
            unset($stores[$store_url]);
            $result = update_option('wc_multi_store_sync_stores', $stores);

            // Clear cache when store is deleted
            if ($result) {
                self::$active_stores_cache = null;
                WC_Multi_Store_Cache_Manager::clear_store_cache($store_url);
            }

            return $result;
        }
        return false;
    }

    /**
     * Get active stores
     *
     * @param bool $use_cache Whether to use cache
     * @return array Active stores
     */
    public static function get_active_stores(bool $use_cache = true): array {
        // In-memory static cache only — no transient/object-cache layer on
        // top: get_stores() is already served from WP's own options cache,
        // so a second cache here would only add an invalidation path to
        // keep in sync for no real benefit (same reasoning as get() above).
        if ($use_cache && self::$active_stores_cache !== null) {
            return self::$active_stores_cache;
        }

        $stores = self::get_stores();
        $active = [];

        foreach ($stores as $url => $config) {
            if (isset($config['status']) && $config['status'] === 'active') {
                $active[$url] = $config;
            }
        }

        if ($use_cache) {
            self::$active_stores_cache = $active;
        }

        return $active;
    }

    /**
     * Get count of products that will be synced (excluding specified categories/tags)
     *
     * @param array $exclude_categories Category IDs to exclude
     * @param array $exclude_tags Tag IDs to exclude
     * @return int Number of products that will be synced
     */
    public static function get_sync_product_count(array $exclude_categories = [], array $exclude_tags = []): int {
        // Use posts_per_page=1 to minimize memory usage - we only need found_posts
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false, // We need found_posts
        ];

        $tax_query = ['relation' => 'AND'];

        if (!empty($exclude_categories)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $exclude_categories,
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($exclude_tags)) {
            $tax_query[] = [
                'taxonomy' => 'product_tag',
                'field' => 'term_id',
                'terms' => $exclude_tags,
                'operator' => 'NOT IN',
            ];
        }

        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        }

        $query = new WP_Query($args);
        return $query->found_posts;
    }

    /**
     * Get effective settings based on sync source
     *
     * When sync source is 'scheduled_sync' or 'weekly_verification_correction',
     * this applies any source-specific overrides to the base settings.
     * For other sources, returns base settings.
     *
     * @param string $sync_source Source of sync (e.g., 'scheduled_sync', 'weekly_verification_correction', 'manual', 'hook')
     * @return array Effective settings
     */
    public static function get_effective_settings(string $sync_source = ''): array {
        $settings = self::get_settings();

        // Apply scheduled sync overrides
        if ($sync_source === 'scheduled_sync') {
            $scheduled_settings = get_option('wc_multi_store_sync_scheduled', []);

            // Override category_auto_create if specified
            if (isset($scheduled_settings['scheduled_category_auto_create']) && $scheduled_settings['scheduled_category_auto_create'] !== 'use_default') {
                $settings['category_auto_create'] = ($scheduled_settings['scheduled_category_auto_create'] === 'enabled');
            }

            // Override stock_sync_enabled if specified
            if (isset($scheduled_settings['scheduled_stock_sync']) && $scheduled_settings['scheduled_stock_sync'] !== 'use_default') {
                $settings['stock_sync_enabled'] = ($scheduled_settings['scheduled_stock_sync'] === 'enabled');
            }

            return $settings;
        }

        // Apply weekly verification overrides
        if ($sync_source === 'weekly_verification_correction') {
            $weekly_settings = get_option('wc_multi_store_sync_weekly_verification', []);

            // Override category_auto_create if specified
            if (isset($weekly_settings['weekly_category_auto_create']) && $weekly_settings['weekly_category_auto_create'] !== 'use_default') {
                $settings['category_auto_create'] = ($weekly_settings['weekly_category_auto_create'] === 'enabled');
            }

            // Override stock_sync_enabled if specified
            if (isset($weekly_settings['weekly_stock_sync']) && $weekly_settings['weekly_stock_sync'] !== 'use_default') {
                $settings['stock_sync_enabled'] = ($weekly_settings['weekly_stock_sync'] === 'enabled');
            }

            return $settings;
        }

        return $settings;
    }

    /**
     * Get scheduled sync settings
     *
     * @return array Scheduled sync settings
     */
    public static function get_scheduled_settings(): array {
        return get_option('wc_multi_store_sync_scheduled', [
            'scheduled_sync_enabled' => true,
            'scheduled_sync_interval' => '10min',
            'sync_all_products' => true,
            'sync_modified_hours' => 24,
            'batch_size_peak' => 5,
            'batch_size_offpeak' => 20,
            'scheduled_sync_type' => 'use_default',
            'scheduled_stock_sync' => 'use_default',
            'scheduled_category_auto_create' => 'use_default',
        ]);
    }
}

// Invalidate the static webhook-settings cache whenever the option is written.
// Covers both the admin save handler and config-manager import path.
if (function_exists('add_action')) {
    add_action('update_option_wc_multi_store_sync_webhook_settings', [WC_Multi_Store_Settings::class, 'clear_static_cache']);
    add_action('add_option_wc_multi_store_sync_webhook_settings', [WC_Multi_Store_Settings::class, 'clear_static_cache']);

    // Invalidate circuit breaker config cache when main settings are saved.
    add_action('update_option_wc_multi_store_sync_settings', [WC_Multi_Store_Circuit_Breaker::class, 'clear_config_cache']);
    add_action('add_option_wc_multi_store_sync_settings', [WC_Multi_Store_Circuit_Breaker::class, 'clear_config_cache']);
}
