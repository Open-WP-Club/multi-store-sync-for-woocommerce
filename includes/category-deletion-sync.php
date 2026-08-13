<?php
/**
 * Category and Tag Deletion Sync Class
 * Handles product deletion/uncategorization when categories or tags are deleted
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Category_Deletion_Sync {

    /**
     * Initialize category/tag deletion sync
     */
    public function __construct() {
        // Hook into category deletion
        add_action('delete_product_cat', $this->on_category_delete(...), 10, 4);

        // Hook into tag deletion
        add_action('delete_product_tag', $this->on_tag_delete(...), 10, 4);
    }

    /**
     * Handle category deletion
     *
     * @param int $term_id Term ID
     * @param int $tt_id Term taxonomy ID
     * @param mixed $deleted_term Deleted term object
     * @param array $object_ids Array of object IDs
     */
    public function on_category_delete(int $term_id, int $tt_id, mixed $deleted_term, array $object_ids): void {
        $settings = WC_Multi_Store_Settings::get_settings();
        $category_deletion_action = $settings['category_deletion_action'] ?? 'none';

        if ($category_deletion_action === 'none') {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Category deleted: ID %d, Name: %s, Affected products: %d',
            $term_id,
            is_object($deleted_term) ? $deleted_term->name : 'Unknown',
            count($object_ids)
        ));

        // Get all products in this category
        $products = $this->get_products_by_term($object_ids);

        if (empty($products)) {
            WC_Multi_Store_Logger::write('No products found in deleted category');
            return;
        }

        if ($category_deletion_action === 'delete') {
            $this->delete_products($products, 'category', $term_id);
        } elseif ($category_deletion_action === 'uncategorize') {
            $this->uncategorize_products($products);
        }
    }

    /**
     * Handle tag deletion
     *
     * @param int $term_id Term ID
     * @param int $tt_id Term taxonomy ID
     * @param mixed $deleted_term Deleted term object
     * @param array $object_ids Array of object IDs
     */
    public function on_tag_delete(int $term_id, int $tt_id, mixed $deleted_term, array $object_ids): void {
        $settings = WC_Multi_Store_Settings::get_settings();
        $tag_deletion_action = $settings['tag_deletion_action'] ?? 'none';

        if ($tag_deletion_action === 'none') {
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Tag deleted: ID %d, Name: %s, Affected products: %d',
            $term_id,
            is_object($deleted_term) ? $deleted_term->name : 'Unknown',
            count($object_ids)
        ));

        // Get all products with this tag
        $products = $this->get_products_by_term($object_ids);

        if (empty($products)) {
            WC_Multi_Store_Logger::write('No products found with deleted tag');
            return;
        }

        if ($tag_deletion_action === 'delete') {
            $this->delete_products($products, 'tag', $term_id);
        }
    }

    /**
     * Get products by term object IDs
     *
     * @param array $object_ids Array of object IDs
     * @return array Array of product IDs
     */
    private function get_products_by_term(array $object_ids): array {
        if (empty($object_ids)) {
            return [];
        }

        // Stream in fixed-size chunks. post__in is already bounded by the
        // taxonomy term, but a large term (50k+ rows) still benefits from
        // splitting the WHERE IN list rather than firing one giant query.
        $chunk_size  = 1000;
        $product_ids = [];

        foreach (array_chunk($object_ids, $chunk_size) as $chunk) {
            $ids = get_posts([
                'post__in'       => $chunk,
                'post_type'      => 'product',
                'fields'         => 'ids',
                'posts_per_page' => $chunk_size,
                'post_status'    => 'any',
                'no_found_rows'  => true,
            ]);

            foreach ($ids as $id) {
                $product_ids[] = (int) $id;
            }
        }

        return $product_ids;
    }

    /**
     * Delete products
     *
     * @param array $product_ids Array of product IDs
     * @param string $deletion_reason Reason for deletion (category/tag)
     * @param int $term_id Term ID
     */
    private function delete_products(array $product_ids, string $deletion_reason, int $term_id): void {
        $settings = WC_Multi_Store_Settings::get_settings();
        $auto_sync_deletions = $settings['auto_sync_deletions'] ?? false;

        if (!$auto_sync_deletions) {
            WC_Multi_Store_Logger::write('Auto sync deletions is disabled, skipping product deletion');
            return;
        }

        // Get active stores
        $stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($stores)) {
            WC_Multi_Store_Logger::write('No active stores configured, skipping product deletion');
            return;
        }

        // Batch delete products
        $batch_size = 50;
        $batches = array_chunk($product_ids, $batch_size);

        WC_Multi_Store_Logger::write(sprintf(
            'Deleting %d products due to %s deletion (Term ID: %d) in %d batch(es)',
            count($product_ids),
            $deletion_reason,
            $term_id,
            count($batches)
        ));

        foreach ($batches as $batch_index => $batch) {
            foreach ($batch as $product_id) {
                // Queue product deletion - uses optimized SQL, no product loading
                // Audit log will be created in the queue processor when deletion actually happens
                WC_MSS()->queue_manager->add_product_deletion(
                    $product_id,
                    $deletion_reason . '_delete',
                    3, // Lower priority for bulk operations
                    $stores
                );

                // Delete the local product
                wp_delete_post($product_id, true);
            }

            WC_Multi_Store_Logger::write(sprintf(
                'Batch %d/%d: Queued %d products for deletion',
                $batch_index + 1,
                count($batches),
                count($batch)
            ));
        }
    }

    /**
     * Uncategorize products (move to "Uncategorized")
     *
     * @param array $product_ids Array of product IDs
     */
    private function uncategorize_products(array $product_ids): void {
        // Get or create "Uncategorized" category
        $uncategorized = $this->get_or_create_uncategorized_category();

        if (!$uncategorized) {
            WC_Multi_Store_Logger::write('Failed to get or create Uncategorized category');
            return;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Moving %d products to Uncategorized category',
            count($product_ids)
        ));

        foreach ($product_ids as $product_id) {
            // Remove all categories and set to Uncategorized
            wp_set_object_terms($product_id, [$uncategorized], 'product_cat', false);

            // Queue product for sync
            WC_MSS()->queue_manager->add_product(
                $product_id,
                'category_change',
                3 // Lower priority
            );

            WC_Multi_Store_Logger::write(sprintf(
                'Product ID %d moved to Uncategorized',
                $product_id
            ));
        }
    }

    /**
     * Get or create Uncategorized category
     *
     * @return int|false Category term ID or false on failure
     */
    private function get_or_create_uncategorized_category(): int|false {
        // Check if Uncategorized category exists
        $term = get_term_by('name', 'Uncategorized', 'product_cat');

        if ($term) {
            return $term->term_id;
        }

        // Create Uncategorized category
        $result = wp_insert_term(
            'Uncategorized',
            'product_cat',
            [
                'description' => 'Products without a category',
                'slug' => 'uncategorized',
            ]
        );

        if (is_wp_error($result)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to create Uncategorized category: %s',
                $result->get_error_message()
            ));
            return false;
        }

        return $result['term_id'];
    }

}
