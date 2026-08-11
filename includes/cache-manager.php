<?php
/**
 * WooCommerce Multi-Store Cache Manager
 *
 * Manages caching for API responses and frequently accessed data
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache Manager Class
 */
class WC_Multi_Store_Cache_Manager {

    /**
     * Cache group for transients
     */
    const string CACHE_GROUP = 'wc_mss_cache';

    /**
     * Default cache expiration: 15 minutes (15 * MINUTE_IN_SECONDS)
     */
    const int DEFAULT_EXPIRATION = 900;

    /**
     * Remote product cache expiration: 1 week (WEEK_IN_SECONDS)
     * Cached until weekly verification - if verification passes, cache is refreshed
     */
    const int REMOTE_PRODUCT_EXPIRATION = 604800;

    /**
     * Negative cache expiration: 24 hours
     * Products not found on remote store are cached for shorter period
     * This prevents repeated sync attempts while allowing daily re-checks
     */
    const int NEGATIVE_CACHE_EXPIRATION = 86400;

    /**
     * Marker for products not found on remote store
     */
    const string NOT_FOUND_MARKER = '__WC_MSS_NOT_FOUND__';

    /**
     * Store config cache expiration: 30 minutes (30 * MINUTE_IN_SECONDS)
     */
    const int STORE_CONFIG_EXPIRATION = 1800;

    /**
     * Taxonomy cache expiration: 1 hour (HOUR_IN_SECONDS)
     */
    const int TAXONOMY_EXPIRATION = 3600;

    /**
     * Remote categories/tags cache expiration: 1 day (DAY_IN_SECONDS).
     * Cleared during daily maintenance to refresh category/tag data.
     */
    const int REMOTE_TERMS_EXPIRATION = 86400;

    /**
     * Get remote product from cache
     *
     * @param string $store_url Store URL
     * @param string $search_value SKU or slug
     * @param string $match_by Match type (sku or slug)
     * @return array|null|false Cached product data, null if marked as not found, false if not in cache
     */
    public static function get_remote_product($store_url, $search_value, $match_by): array|null|false {
        if (empty($search_value)) {
            return null;
        }

        $cache_key = self::build_cache_key('remote_product', [
            $store_url,
            $search_value,
            $match_by,
        ]);

        $cached = get_transient($cache_key);

        // Return null if marked as not found (negative cache)
        if ($cached === self::NOT_FOUND_MARKER) {
            return null;
        }

        return $cached;
    }

    /**
     * Set remote product in cache
     *
     * @param string $store_url Store URL
     * @param string $search_value SKU or slug
     * @param string $match_by Match type
     * @param array|null $product_data Product data to cache
     * @return bool Success
     */
    public static function set_remote_product($store_url, $search_value, $match_by, $product_data): bool {
        if (empty($search_value)) {
            return false;
        }

        $cache_key = self::build_cache_key('remote_product', [
            $store_url,
            $search_value,
            $match_by,
        ]);

        return set_transient($cache_key, $product_data, self::REMOTE_PRODUCT_EXPIRATION);
    }

    /**
     * Delete remote product from cache
     *
     * @param string $store_url Store URL
     * @param string $search_value SKU or slug
     * @param string $match_by Match type
     * @return bool Success
     */
    public static function delete_remote_product($store_url, $search_value, $match_by): bool {
        $cache_key = self::build_cache_key('remote_product', [
            $store_url,
            $search_value,
            $match_by,
        ]);

        return delete_transient($cache_key);
    }

    /**
     * Set negative cache - mark product as not found on remote store
     * Uses shorter expiration than regular cache to allow periodic re-checks
     *
     * @param string $store_url Store URL
     * @param string $search_value SKU or slug
     * @param string $match_by Match type (sku or slug)
     * @return bool Success
     */
    public static function set_product_not_found($store_url, $search_value, $match_by): bool {
        if (empty($search_value)) {
            return false;
        }

        $cache_key = self::build_cache_key('remote_product', [
            $store_url,
            $search_value,
            $match_by,
        ]);

        return set_transient($cache_key, self::NOT_FOUND_MARKER, self::NEGATIVE_CACHE_EXPIRATION);
    }

    /**
     * Check if product is cached as not found on remote store
     *
     * @param string $store_url Store URL
     * @param string $search_value SKU or slug
     * @param string $match_by Match type (sku or slug)
     * @return bool True if product is known to not exist on remote store
     */
    public static function is_product_not_found($store_url, $search_value, $match_by): bool {
        if (empty($search_value)) {
            return false;
        }

        $cache_key = self::build_cache_key('remote_product', [
            $store_url,
            $search_value,
            $match_by,
        ]);

        $cached = get_transient($cache_key);
        return $cached === self::NOT_FOUND_MARKER;
    }

    /**
     * Update remote product cache after successful sync
     * This refreshes the cache with fresh data and resets the expiration timer
     *
     * @param string $store_url Store URL
     * @param string $search_value SKU or slug
     * @param string $match_by Match type (sku or slug)
     * @param array $product_data Updated product data from API response
     * @return bool Success
     */
    public static function update_remote_product_after_sync($store_url, $search_value, $match_by, $product_data): bool {
        if (empty($search_value) || empty($product_data)) {
            return false;
        }

        $cache_key = self::build_cache_key('remote_product', [
            $store_url,
            $search_value,
            $match_by,
        ]);

        // Refresh cache with updated data and reset expiration
        return set_transient($cache_key, $product_data, self::REMOTE_PRODUCT_EXPIRATION);
    }

    /**
     * Get remote variations from cache
     *
     * @param string $store_url Store URL
     * @param int $remote_product_id Remote product ID
     * @return array|null Cached variations or null
     */
    public static function get_remote_variations($store_url, $remote_product_id): array|false {
        $cache_key = self::build_cache_key('remote_variations', [
            $store_url,
            $remote_product_id,
        ]);

        $cached = get_transient($cache_key);

        return $cached === null ? false : $cached;
    }

    /**
     * Set remote variations in cache
     *
     * @param string $store_url Store URL
     * @param int $remote_product_id Remote product ID
     * @param array $variations Variations data
     * @return bool Success
     */
    public static function set_remote_variations($store_url, $remote_product_id, $variations): bool {
        $cache_key = self::build_cache_key('remote_variations', [
            $store_url,
            $remote_product_id,
        ]);

        return set_transient($cache_key, $variations, self::REMOTE_PRODUCT_EXPIRATION);
    }

    /**
     * Get all remote categories or tags fetched from a store's API, from cache.
     *
     * @param string $store_url Store URL
     * @param string $type 'categories' or 'tags'
     * @return array|false Cached terms, or false if not cached
     */
    public static function get_remote_terms(string $store_url, string $type): array|false {
        return get_transient(self::build_cache_key('remote_terms', [$store_url, $type]));
    }

    /**
     * Set all remote categories or tags fetched from a store's API, in cache.
     *
     * @param string $store_url Store URL
     * @param string $type 'categories' or 'tags'
     * @param array $terms Terms data
     * @return bool Success
     */
    public static function set_remote_terms(string $store_url, string $type, array $terms): bool {
        return set_transient(self::build_cache_key('remote_terms', [$store_url, $type]), $terms, self::REMOTE_TERMS_EXPIRATION);
    }

    /**
     * Clear cached remote categories/tags for a store, or all stores if none given.
     *
     * @param string|null $store_url Specific store URL, or null for all stores
     */
    public static function clear_remote_terms(?string $store_url = null): void {
        global $wpdb;

        if ($store_url !== null) {
            delete_transient(self::build_cache_key('remote_terms', [$store_url, 'categories']));
            delete_transient(self::build_cache_key('remote_terms', [$store_url, 'tags']));
            return;
        }

        $pattern = $wpdb->esc_like('_transient') . '%' . $wpdb->esc_like(self::CACHE_GROUP . '_remote_terms') . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            )
        );
    }

    /**
     * Get taxonomy terms from cache
     *
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Term IDs
     * @return array|null Cached terms or null
     */
    public static function get_taxonomy_terms($taxonomy, $term_ids): array|false|null {
        if (empty($term_ids)) {
            return null;
        }

        // Ensure $term_ids is an array
        if (!is_array($term_ids)) {
            $term_ids = [$term_ids];
        }

        sort($term_ids); // Ensure consistent cache key
        $cache_key = self::build_cache_key('taxonomy_terms', [
            $taxonomy,
            implode(',', $term_ids),
        ]);

        return get_transient($cache_key);
    }

    /**
     * Set taxonomy terms in cache
     *
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Term IDs
     * @param array $terms Terms data
     * @return bool Success
     */
    public static function set_taxonomy_terms($taxonomy, $term_ids, $terms): bool {
        if (empty($term_ids)) {
            return false;
        }

        // Ensure $term_ids is an array
        if (!is_array($term_ids)) {
            $term_ids = [$term_ids];
        }

        sort($term_ids); // Ensure consistent cache key
        $cache_key = self::build_cache_key('taxonomy_terms', [
            $taxonomy,
            implode(',', $term_ids),
        ]);

        return set_transient($cache_key, $terms, self::TAXONOMY_EXPIRATION);
    }

    /**
     * Build cache key
     *
     * @param string $type Cache type
     * @param array $parts Key parts
     * @return string Cache key
     */
    private static function build_cache_key($type, $parts): string {
        $key_parts = array_merge([self::CACHE_GROUP, $type], $parts);
        $key = implode('_', array_map('sanitize_key', $key_parts));

        // Ensure key is not too long (max 172 characters for WordPress transients)
        if (strlen($key) > 172) {
            $key = substr($key, 0, 140) . '_' . md5($key);
        }

        return $key;
    }

    /**
     * Clear all plugin caches
     * PERFORMANCE FIX: Combined single query instead of two separate patterns
     *
     * @return int Number of caches cleared
     */
    public static function clear_all(): int {
        global $wpdb;

        // PERFORMANCE FIX: Use single query to delete both transient and timeout entries
        $pattern = $wpdb->esc_like('_transient') . '%' . $wpdb->esc_like(self::CACHE_GROUP) . '%';

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            )
        );

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write("Cleared {$deleted} cache entries");
        }

        return $deleted;
    }

    /**
     * Clear cache for specific store
     * PERFORMANCE FIX: Single query pattern for better performance
     *
     * @param string $store_url Store URL
     * @return bool Success
     */
    public static function clear_store_cache($store_url): bool {
        global $wpdb;

        $store_key = sanitize_key($store_url);
        // PERFORMANCE FIX: Use single wildcard pattern that matches both transient and timeout
        $pattern = $wpdb->esc_like('_transient') . '%' . $wpdb->esc_like(self::CACHE_GROUP) . '%' . $wpdb->esc_like($store_key) . '%';

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            )
        );

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write("Cleared {$deleted} cache entries for store: {$store_url}");
        }

        return $deleted > 0;
    }
}
