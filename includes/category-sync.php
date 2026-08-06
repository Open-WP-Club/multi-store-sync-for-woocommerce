<?php
/**
 * Category Sync
 * Queue all products in a WooCommerce category for synchronisation.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Category_Sync {

    /**
     * Get all published product IDs belonging to a category term, optionally
     * including every child category.
     *
     * @param int  $category_id      product_cat term ID
     * @param bool $include_children Also include products in child categories
     * @return int[]
     */
    public static function get_product_ids(int $category_id, bool $include_children = true): array {
        $term_ids = [$category_id];

        if ($include_children) {
            $children = get_term_children($category_id, 'product_cat');
            if (!is_wp_error($children) && !empty($children)) {
                $term_ids = array_merge($term_ids, $children);
            }
        }

        // Paginate at the SQL level — same external API, but lets the DB
        // stream a category with 50k+ products in 5k-row chunks instead of
        // building one huge resultset.
        $per_page    = 5000;
        $product_ids = [];
        $page        = 1;

        do {
            $query = new WP_Query([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'no_found_rows'  => true,
                'tax_query'      => [[
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $term_ids,
                    'operator' => 'IN',
                ]],
            ]);

            if (empty($query->posts)) {
                break;
            }

            foreach ($query->posts as $id) {
                $product_ids[] = (int) $id;
            }

            $page++;
        } while (count($query->posts) === $per_page);

        return $product_ids;
    }

    /**
     * Resolve a category by ID or slug.
     *
     * @param int|string $id_or_slug
     * @return WP_Term|null
     */
    public static function resolve_term(int|string $id_or_slug): ?WP_Term {
        if (is_numeric($id_or_slug)) {
            $term = get_term((int) $id_or_slug, 'product_cat');
        } else {
            $term = get_term_by('slug', $id_or_slug, 'product_cat');
        }

        return ($term && !is_wp_error($term)) ? $term : null;
    }

    /**
     * Queue all products in a category for synchronisation.
     *
     * @param int    $category_id      product_cat term ID
     * @param string $sync_type        full_product | price_quantity | quantity
     * @param bool   $include_children Include child categories
     * @return array{queued:int, products:int, category_name:string}|array{error:string}
     */
    public static function queue_sync(
        int $category_id,
        string $sync_type = 'full_product',
        bool $include_children = true
    ): array {
        $term = get_term($category_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return ['error' => 'Category not found'];
        }

        $valid_types = ['full_product', 'price_quantity', 'price_quantity_categories', 'quantity'];
        if (!in_array($sync_type, $valid_types, true)) {
            $sync_type = 'full_product';
        }

        $product_ids = self::get_product_ids($category_id, $include_children);

        if (empty($product_ids)) {
            return [
                'queued'        => 0,
                'products'      => 0,
                'category_name' => $term->name,
            ];
        }

        $queued = WC_MSS()->queue_manager->add_products(
            $product_ids,
            'category_sync',
            WC_Multi_Store_Queue_Manager::PRIORITY_LOW,
            $sync_type
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Category sync queued: "%s" (ID %d) — %d product(s), %d queue item(s), type: %s%s',
            $term->name,
            $category_id,
            count($product_ids),
            $queued,
            $sync_type,
            $include_children ? ' (incl. children)' : ''
        ));

        return [
            'queued'        => $queued,
            'products'      => count($product_ids),
            'category_name' => $term->name,
        ];
    }
}
