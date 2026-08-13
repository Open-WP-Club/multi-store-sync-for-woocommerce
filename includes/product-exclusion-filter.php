<?php
/**
 * Product Exclusion Filter
 * Centralized logic for determining if products should be excluded from store syncing
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Exclusion Filter Class
 */
class WC_Multi_Store_Product_Exclusion_Filter {

    /**
     * Check if product should be excluded for a store (accepts Product object)
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return bool True if product should be excluded
     */
    public static function should_exclude(WC_Product $product, array $store_config): bool {
        // Fast-exit: nothing configured to exclude — skip the term query entirely.
        if (empty($store_config['exclude_categories']) && empty($store_config['exclude_tags'])) {
            return false;
        }

        $terms = self::get_product_term_ids($product->get_id());

        return self::should_exclude_by_ids($terms['categories'], $terms['tags'], $store_config);
    }

    /**
     * Fetch product_cat + product_tag term IDs in a single SQL query.
     *
     * Saves one wp_get_post_terms() call vs. the naive two-taxonomy fetch,
     * which matters on hot paths where exclusion is checked per item.
     *
     * @param int $product_id
     * @return array{categories: int[], tags: int[]}
     */
    private static function get_product_term_ids(int $product_id): array {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id, tt.taxonomy
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id = %d
               AND tt.taxonomy IN ('product_cat', 'product_tag')",
            $product_id
        ));

        $categories = [];
        $tags       = [];
        foreach ((array) $rows as $row) {
            if ($row->taxonomy === 'product_cat') {
                $categories[] = (int) $row->term_id;
            } else {
                $tags[] = (int) $row->term_id;
            }
        }

        return ['categories' => $categories, 'tags' => $tags];
    }

    /**
     * Check if product should be excluded for a store (accepts pre-fetched IDs)
     * Use this when you already have the category/tag IDs to avoid redundant DB queries
     *
     * @param array $category_ids Product category IDs
     * @param array $tag_ids Product tag IDs
     * @param array $store_config Store configuration
     * @return bool True if product should be excluded
     */
    public static function should_exclude_by_ids(array $category_ids, array $tag_ids, array $store_config): bool {
        // Check excluded categories
        if (!empty($store_config['exclude_categories'])) {
            $all_category_ids = self::expand_with_ancestors($category_ids);

            foreach ($store_config['exclude_categories'] as $excluded_cat_id) {
                if (in_array($excluded_cat_id, $all_category_ids)) {
                    return true;
                }
            }
        }

        // Check excluded tags
        if (!empty($store_config['exclude_tags'])) {
            foreach ($store_config['exclude_tags'] as $excluded_tag_id) {
                if (in_array($excluded_tag_id, $tag_ids)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get human-readable exclusion reasons for a product
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return array List of exclusion reasons (e.g., "Category: Clothing", "Tag: Sale")
     */
    public static function get_exclusion_reasons(WC_Product $product, array $store_config): array {
        $reasons = [];

        if (empty($store_config['exclude_categories']) && empty($store_config['exclude_tags'])) {
            return $reasons;
        }

        $terms        = self::get_product_term_ids($product->get_id());
        $product_cats = $terms['categories'];
        $product_tags = $terms['tags'];

        // Check excluded categories
        if (!empty($store_config['exclude_categories']) && !empty($product_cats)) {
            $all_cat_ids = self::expand_with_ancestors($product_cats);

            foreach ($store_config['exclude_categories'] as $excluded_cat_id) {
                if (in_array($excluded_cat_id, $all_cat_ids)) {
                    $term = get_term($excluded_cat_id, 'product_cat');
                    if ($term && !is_wp_error($term)) {
                        $reasons[] = sprintf('Category: %s', $term->name);
                    }
                }
            }
        }

        // Check excluded tags
        if (!empty($store_config['exclude_tags']) && !empty($product_tags)) {
            foreach ($store_config['exclude_tags'] as $excluded_tag_id) {
                if (in_array($excluded_tag_id, $product_tags)) {
                    $term = get_term($excluded_tag_id, 'product_tag');
                    if ($term && !is_wp_error($term)) {
                        $reasons[] = sprintf('Tag: %s', $term->name);
                    }
                }
            }
        }

        return $reasons;
    }

    /**
     * Expand a list of category IDs with their ancestor category IDs, so that
     * excluding a parent category also matches products filed under its children.
     *
     * @param int[] $category_ids
     * @return int[]
     */
    private static function expand_with_ancestors(array $category_ids): array {
        $all_ids = $category_ids;
        foreach ($category_ids as $cat_id) {
            $ancestors = get_ancestors((int) $cat_id, 'product_cat', 'taxonomy');
            if ($ancestors) {
                $all_ids = array_merge($all_ids, $ancestors);
            }
        }
        return $all_ids;
    }

}
