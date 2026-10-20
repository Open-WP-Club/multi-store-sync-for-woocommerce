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

        return is_array($cached) ? $cached : false;
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
     * @return array|false Cached variations or false if not cached
     */
    public static function get_remote_variations($store_url, $remote_product_id): array|false {
        $cache_key = self::build_cache_key('remote_variations', [
            $store_url,
            $remote_product_id,
        ]);

        $cached = get_transient($cache_key);

        return is_array($cached) ? $cached : false;
    }

    /**
     * Set remote variations in cache
     *
     * @param string $store_url Store URL
     * @param int $remote_product_id Remote product ID
     * @param array|null $variations Variations data, or null to clear the cache
     * @return bool Success
     */
    public static function set_remote_variations($store_url, $remote_product_id, $variations): bool {
        $cache_key = self::build_cache_key('remote_variations', [
            $store_url,
            $remote_product_id,
        ]);

        if ($variations === null) {
            return delete_transient($cache_key);
        }

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
        $cached = get_transient(self::build_cache_key('remote_terms', [$store_url, $type]));
        return is_array($cached) ? $cached : false;
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
        $stores = $store_url !== null ? [$store_url] : array_keys(WC_Multi_Store_Settings::get_stores());
        foreach ($stores as $url) {
            delete_transient(self::build_cache_key('remote_terms', [$url, 'categories']));
            delete_transient(self::build_cache_key('remote_terms', [$url, 'tags']));
        }
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

        $cached = get_transient($cache_key);
        return is_array($cached) ? $cached : false;
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
        // Hash the original parts separately: sanitizing SKUs/URLs merges distinct
        // values (AB.1 and AB/1, for example) and can reuse another product's cache.
        $key_parts = array_merge([self::CACHE_GROUP, sanitize_key($type)], array_map(
            static fn($part): string => md5((string) $part), $parts
        ));
        // Options persist independently of transient eviction and are coherent
        // through WordPress's object cache. Do not memoize them in this process:
        // long-running workers must observe clears between cache operations.
        $generation = [get_option(self::CACHE_GROUP . '_generation', '0')];
        if (in_array($type, ['remote_product', 'remote_variations', 'remote_terms'], true)) {
            $generation[] = get_option(self::CACHE_GROUP . '_store_generation_' . md5((string) $parts[0]), '0');
        }
        $key = implode('_', $key_parts) . '_' . md5(serialize($generation));

        // Ensure key is not too long (max 172 characters for WordPress transients)
        if (strlen($key) > 172) {
            $key = substr($key, 0, 139) . '_' . md5($key);
        }

        return $key;
    }

    /**
     * Invalidate every plugin cache, including local taxonomy data.
     *
     * Existing transients become unreachable and expire at their original TTL.
     * No key enumeration or whole-site object-cache flush is required.
     *
     * @return int 1 if invalidated, 0 if the generation could not be persisted.
     */
    public static function clear_all(): int {
        return (int) update_option(self::CACHE_GROUP . '_generation', wp_generate_uuid4(), false);
    }

    /**
     * Invalidate remote product, variation, negative and term caches for one store.
     *
     * @param string $store_url Store URL
     * @return bool Whether the new generation was persisted successfully
     */
    public static function clear_store_cache($store_url): bool {
        if ((string) $store_url === '') {
            return false;
        }
        return update_option(self::CACHE_GROUP . '_store_generation_' . md5((string) $store_url), wp_generate_uuid4(), false);
    }
}
