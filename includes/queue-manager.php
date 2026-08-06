<?php
/**
 * Queue Manager Class
 * Handles queuing and batch processing of product syncs
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Queue_Manager {

    /**
     * Queue priority constants
     */
    const int PRIORITY_CRITICAL = 1;  // Stock changes - highest priority
    const int PRIORITY_HIGH = 2;       // Price changes, deletions, status changes
    const int PRIORITY_NORMAL = 3;     // Product updates, variations
    const int PRIORITY_LOW = 10;       // Scheduled syncs, bulk operations

    /**
     * MySQL advisory lock name for queue processing
     */
    const string LOCK_NAME = 'wc_mss_queue_processor';

    /**
     * Constructor
     */
    public function __construct() {}

    /**
     * Queue a product operation to stores with common logic
     *
     * This helper method consolidates the duplicated code pattern across
     * add_product_deletion, add_product_restoration, add_product_status_change,
     * and add_variation_deletion methods.
     *
     * @param array $args {
     *     Arguments for queuing the operation.
     *
     *     @type int      $product_id       Product/Variation ID
     *     @type string   $product_sku      Product SKU
     *     @type array    $category_ids     Product category IDs for exclusion check
     *     @type array    $tag_ids          Product tag IDs for exclusion check
     *     @type string   $sync_type        Queue sync type (delete_product, restore_product, etc.)
     *     @type string   $trigger          What triggered the queue
     *     @type int      $priority         Queue priority
     *     @type array    $transient_data   Additional data to store in transient
     *     @type string   $log_action       Action name for logging (e.g., 'deletion', 'restoration')
     *     @type array    $specific_stores  Optional specific stores to use (null = all active)
     *     @type bool     $skip_exclusion   Whether to skip exclusion check (for specific stores)
     * }
     * @return int Number of queue items added
     */
    private function queue_product_operation(array $args): int {
        $defaults = [
            'product_id' => 0,
            'product_sku' => '',
            'category_ids' => [],
            'tag_ids' => [],
            'sync_type' => '',
            'trigger' => 'unknown',
            'priority' => self::PRIORITY_HIGH,
            'transient_data' => [],
            'log_action' => 'operation',
            'specific_stores' => null,
            'skip_exclusion' => false,
        ];

        $args = wp_parse_args($args, $defaults);

        // Use specific stores if provided, otherwise get all active stores
        $stores = !empty($args['specific_stores'])
            ? $args['specific_stores']
            : WC_Multi_Store_Settings::get_active_stores();

        if (empty($stores)) {
            return 0;
        }

        $added = 0;
        foreach ($stores as $store_url => $store_config) {
            // Check exclusion unless skipped (e.g., when specific stores already filtered)
            if (!$args['skip_exclusion'] &&
                WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
                    $args['category_ids'],
                    $args['tag_ids'],
                    $store_config
                )
            ) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Product %s skipped for store %s: Product ID %d excluded by category/tag filter',
                    $args['log_action'],
                    $store_url,
                    $args['product_id']
                ));
                continue;
            }

            // Prepare extra data for special operations (deletion, status change, etc.)
            $extra_data = $args['transient_data'];

            // Add to queue with SKU and extra_data stored directly in the table
            $result = WC_Multi_Store_Queue_Table::add(
                $args['product_id'],
                $store_url,
                $args['sync_type'],
                $args['priority'],
                $args['trigger'],
                null, // scheduled_at
                $args['product_sku'], // SKU stored directly
                $extra_data // extra data stored directly
            );

            if ($result) {
                $added++;
            }
        }

        if ($added > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Product %s queued: SKU %s to %d store(s) (Trigger: %s, Priority: %d)',
                $args['log_action'],
                $args['product_sku'] ?: 'no-sku',
                $added,
                $args['trigger'],
                $args['priority']
            ));
        }

        return $added;
    }

    /**
     * Get product data for queuing operations
     * Uses direct SQL queries to avoid loading full product object
     *
     * @param int $product_id Product ID
     * @return array|null Product data array or null if not found
     */
    private function get_product_queue_data(int $product_id): ?array {
        global $wpdb;

        // Check if product exists and get SKU in one query
        $sku = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.post_id = %d AND pm.meta_key = '_sku'
            AND p.post_type IN ('product', 'product_variation')
            AND p.post_status != 'trash'",
            $product_id
        ));

        // If no SKU found, check if product exists at all
        if ($sku === null) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE ID = %d AND post_type IN ('product', 'product_variation') AND post_status != 'trash'",
                $product_id
            ));
            if (!$exists) {
                return null;
            }
            $sku = ''; // Product exists but has no SKU
        }

        // Get categories and tags using shared helper
        $terms_data = $this->get_product_terms($product_id);

        return [
            'sku' => $sku,
            'categories' => $terms_data['categories'],
            'tags' => $terms_data['tags'],
        ];
    }

    /**
     * Get product terms (categories and tags) by product ID
     * Optimized single SQL query for both taxonomies
     *
     * @param int $product_id Product ID
     * @return array Array with 'categories' and 'tags' keys
     */
    public function get_product_terms(int $product_id): array {
        global $wpdb;

        $terms = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = %d AND tt.taxonomy IN ('product_cat', 'product_tag')",
            $product_id
        ));

        $categories = [];
        $tags = [];
        foreach ($terms as $term) {
            if ($term->taxonomy === 'product_cat') {
                $categories[] = (int) $term->term_id;
            } else {
                $tags[] = (int) $term->term_id;
            }
        }

        return [
            'categories' => $categories,
            'tags' => $tags,
        ];
    }

    /**
     * Add a product to the sync queue for all active stores
     *
     * @param int $product_id Product ID
     * @param string $trigger What triggered the queue (e.g., 'product_save', 'order', 'manual')
     * @param int $priority Priority (lower number = higher priority)
     * @return int Number of queue items added
     */
    public function add_product($product_id, $trigger = 'unknown', $priority = self::PRIORITY_LOW, $sync_type_override = null): int {
        $stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($stores)) {
            return 0;
        }

        // Get product categories and tags for exclusion check
        $terms_data = $this->get_product_terms($product_id);
        $product_categories = $terms_data['categories'];
        $product_tags = $terms_data['tags'];

        // Variations don't have direct category assignments in wp_term_relationships;
        // use the parent product's terms so the exclusion check works correctly
        global $wpdb;
        $parent_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'product_variation'",
            $product_id
        ));
        if ($parent_id > 0) {
            $parent_terms = $this->get_product_terms($parent_id);
            $product_categories = $parent_terms['categories'];
            $product_tags = $parent_terms['tags'];
        }

        // Use override sync type if provided, otherwise use default from settings
        if ($sync_type_override !== null) {
            $sync_type = $sync_type_override;
        } else {
            // Settings are cached, this is fast
            $settings = WC_Multi_Store_Settings::get_settings();
            $sync_type = $settings['sync_type_default'] ?? 'full_product';
        }

        return $this->queue_product_to_stores($product_id, $product_categories, $product_tags, $sync_type, $priority, $trigger, $stores);
    }

    /**
     * Shared tail end of add_product()/add_products(): given a product's
     * already-resolved categories/tags (variations pre-resolved to their
     * parent's terms by the caller), run the per-store exclusion check and
     * enqueue. Extracted so add_products() can supply categories/tags from a
     * single bulk lookup instead of add_product() re-querying them per item.
     *
     * @param int    $product_id
     * @param array  $product_categories
     * @param array  $product_tags
     * @param string $sync_type
     * @param int    $priority
     * @param string $trigger
     * @param array  $stores Active stores (already fetched by the caller)
     * @return int Number of queue items added for this product
     */
    private function queue_product_to_stores(int $product_id, array $product_categories, array $product_tags, string $sync_type, int $priority, string $trigger, array $stores): int {
        $added = 0;
        foreach ($stores as $store_url => $store_config) {
            // Check if product should be excluded for this store
            if (WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids($product_categories, $product_tags, $store_config)) {
                continue;
            }

            $result = WC_Multi_Store_Queue_Table::add(
                $product_id,
                $store_url,
                $sync_type,
                $priority,
                $trigger
            );

            if ($result) {
                $added++;
            }
        }

        // Log without loading full product - just log the ID
        // SKU will be available when queue is processed
        if ($added > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Product ID %d queued to %d store(s) (Trigger: %s, Priority: %d)',
                $product_id,
                $added,
                $trigger,
                $priority
            ));
        }

        return $added;
    }

    /**
     * Resolve which of the given IDs are product_variation posts and their
     * parent product ID, in a single query instead of one get_var() call per ID.
     *
     * @param int[] $product_ids
     * @return array<int, int> variation_id => parent_id (only variations present)
     */
    private function get_variation_parents_bulk(array $product_ids): array {
        global $wpdb;

        if (empty($product_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_parent FROM {$wpdb->posts}
             WHERE ID IN ({$placeholders}) AND post_type = 'product_variation'",
            ...$product_ids
        ));

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row->ID] = (int) $row->post_parent;
        }
        return $map;
    }

    /**
     * Bulk version of get_product_terms(): resolves categories/tags for every
     * given ID in a single query instead of one per ID.
     *
     * @param int[] $product_ids
     * @return array<int, array{categories: int[], tags: int[]}>
     */
    private function get_product_terms_bulk(array $product_ids): array {
        global $wpdb;

        $map = [];
        foreach ($product_ids as $id) {
            $map[$id] = ['categories' => [], 'tags' => []];
        }

        if (empty($product_ids)) {
            return $map;
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id IN ({$placeholders}) AND tt.taxonomy IN ('product_cat', 'product_tag')",
            ...$product_ids
        ));

        foreach ((array) $rows as $row) {
            $object_id = (int) $row->object_id;
            if (!isset($map[$object_id])) {
                $map[$object_id] = ['categories' => [], 'tags' => []];
            }
            if ($row->taxonomy === 'product_cat') {
                $map[$object_id]['categories'][] = (int) $row->term_id;
            } else {
                $map[$object_id]['tags'][] = (int) $row->term_id;
            }
        }

        return $map;
    }

    /**
     * Add multiple products to the sync queue
     *
     * @param array $product_ids Array of product IDs
     * @param string $trigger What triggered the queue
     * @param int $priority Priority level
     * @return int Number of queue items added
     */
    public function add_products($product_ids, $trigger = 'unknown', $priority = self::PRIORITY_LOW, $sync_type_override = null): int {
        if (empty($product_ids) || !is_array($product_ids)) {
            return 0;
        }

        $stores = WC_Multi_Store_Settings::get_active_stores();
        if (empty($stores)) {
            return 0;
        }

        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));

        if ($sync_type_override !== null) {
            $sync_type = $sync_type_override;
        } else {
            $settings = WC_Multi_Store_Settings::get_settings();
            $sync_type = $settings['sync_type_default'] ?? 'full_product';
        }

        // Bulk-resolve variation parents and categories/tags in 2-3 fixed
        // queries instead of add_product()'s ~2-3 queries PER product
        // (get_product_terms() + a variation-parent get_var() call, each
        // called once per ID when this simply looped add_product() before).
        $parent_map = $this->get_variation_parents_bulk($product_ids);

        $lookup_ids = $product_ids;
        foreach ($parent_map as $variation_id => $parent_id) {
            if ($parent_id > 0) {
                $lookup_ids[] = $parent_id;
            }
        }
        $terms_map = $this->get_product_terms_bulk(array_values(array_unique($lookup_ids)));

        $added_count = 0;

        foreach ($product_ids as $product_id) {
            $parent_id = $parent_map[$product_id] ?? 0;
            $effective_id = $parent_id > 0 ? $parent_id : $product_id;
            $terms_data = $terms_map[$effective_id] ?? ['categories' => [], 'tags' => []];

            $added_count += $this->queue_product_to_stores(
                $product_id,
                $terms_data['categories'],
                $terms_data['tags'],
                $sync_type,
                $priority,
                $trigger,
                $stores
            );
        }

        if ($added_count > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                '%d queue item(s) added for %d product(s) (Trigger: %s, Priority: %d, Type: %s)',
                $added_count,
                count($product_ids),
                $trigger,
                $priority,
                $sync_type_override ?: 'default'
            ));
        }

        return $added_count;
    }

    /**
     * Add a product deletion to the sync queue for all active stores
     *
     * @param int $product_id Product ID
     * @param string $trigger What triggered the queue (e.g., 'product_delete')
     * @param int $priority Priority (lower number = higher priority)
     * @param array|null $specific_stores Optional array of specific stores to delete from (if null, uses all active stores)
     * @param int|null $audit_id Optional deletion audit ID for tracking
     * @return int Number of queue items added
     */
    public function add_product_deletion($product_id, $trigger = 'product_delete', $priority = self::PRIORITY_HIGH, $specific_stores = null, $audit_id = null, ?array $pre_cached_data = null): int {
        $product_data = $pre_cached_data ?? $this->get_product_queue_data($product_id);

        if (!$product_data) {
            WC_Multi_Store_Logger::write(sprintf('Cannot queue deletion: Product ID %d not found', $product_id));
            return 0;
        }

        return $this->queue_product_operation([
            'product_id' => $product_id,
            'product_sku' => $product_data['sku'],
            'category_ids' => $product_data['categories'],
            'tag_ids' => $product_data['tags'],
            'sync_type' => 'delete_product',
            'trigger' => $trigger,
            'priority' => $priority,
            'transient_data' => [
                'categories' => $product_data['categories'],
                'tags' => $product_data['tags'],
                'audit_id' => $audit_id,
            ],
            'log_action' => 'deletion',
            'specific_stores' => $specific_stores,
            'skip_exclusion' => !empty($specific_stores),
        ]);
    }

    /**
     * Add a remote orphan product deletion to the queue
     *
     * Orphan products are products that exist on a remote store but shouldn't
     * (e.g., excluded by category/tag filter). Unlike regular deletions,
     * orphans may not have a corresponding local product.
     *
     * @param array $orphan_data {
     *     Orphan product data from verification report.
     *
     *     @type int    $product_id        Local product ID (may still exist)
     *     @type string $sku               Product SKU
     *     @type string $store_url         Store URL where orphan exists
     *     @type int    $remote_product_id Remote product ID to delete
     *     @type array  $exclusion_reasons Why product was excluded
     * }
     * @param string $trigger What triggered the queue
     * @param int $priority Priority (lower number = higher priority)
     * @return bool Whether the item was queued successfully
     */
    public function add_remote_orphan_deletion(array $orphan_data, $trigger = 'orphan_cleanup', $priority = self::PRIORITY_HIGH): bool {
        $store_url = $orphan_data['store_url'] ?? '';
        $remote_product_id = $orphan_data['remote_product_id'] ?? null;
        $product_id = $orphan_data['product_id'] ?? 0;
        $sku = $orphan_data['sku'] ?? '';

        // Need either store_url + remote_product_id, or store_url + sku
        if (empty($store_url)) {
            WC_Multi_Store_Logger::write('Cannot queue orphan deletion: Missing store_url', 'warning');
            return false;
        }

        if (empty($remote_product_id) && empty($sku)) {
            WC_Multi_Store_Logger::write('Cannot queue orphan deletion: Need either remote_product_id or sku', 'warning');
            return false;
        }

        // Prepare extra data for orphan deletion
        $extra_data = [
            'remote_product_id' => $remote_product_id,
            'exclusion_reasons' => $orphan_data['exclusion_reasons'] ?? [],
            'is_orphan' => true,
        ];

        // Add to queue with SKU and extra_data stored directly in the table
        $result = WC_Multi_Store_Queue_Table::add(
            $product_id,
            $store_url,
            'delete_orphan',
            $priority,
            $trigger,
            null, // scheduled_at
            $sku, // SKU stored directly
            $extra_data // extra data stored directly
        );

        if ($result) {
            WC_Multi_Store_Logger::write(sprintf(
                'Orphan deletion queued: SKU %s (remote ID: %d) from %s (Trigger: %s)',
                $sku ?: 'no-sku',
                $remote_product_id,
                $store_url,
                $trigger
            ));
        }

        return (bool) $result;
    }

    /**
     * Add a product restoration to the sync queue for all active stores
     *
     * @param int $product_id Product ID
     * @param string $trigger What triggered the queue (e.g., 'product_restore')
     * @param int $priority Priority (lower number = higher priority)
     * @return int Number of queue items added
     */
    public function add_product_restoration($product_id, $trigger = 'product_restore', $priority = self::PRIORITY_HIGH): int {
        $product_data = $this->get_product_queue_data($product_id);

        if (!$product_data) {
            WC_Multi_Store_Logger::write(sprintf('Cannot queue restoration: Product ID %d not found', $product_id));
            return 0;
        }

        return $this->queue_product_operation([
            'product_id' => $product_id,
            'product_sku' => $product_data['sku'],
            'category_ids' => $product_data['categories'],
            'tag_ids' => $product_data['tags'],
            'sync_type' => 'restore_product',
            'trigger' => $trigger,
            'priority' => $priority,
            'transient_data' => [
                'categories' => $product_data['categories'],
                'tags' => $product_data['tags'],
            ],
            'log_action' => 'restoration',
        ]);
    }

    /**
     * Add a product status change to the sync queue for all active stores
     *
     * @param int $product_id Product ID
     * @param string $old_status Old status
     * @param string $new_status New status
     * @param string $trigger What triggered the queue (e.g., 'status_change')
     * @param int $priority Priority (lower number = higher priority)
     * @return int Number of queue items added
     */
    public function add_product_status_change($product_id, $old_status, $new_status, $trigger = 'status_change', $priority = self::PRIORITY_HIGH): int {
        $product_data = $this->get_product_queue_data($product_id);

        if (!$product_data) {
            WC_Multi_Store_Logger::write(sprintf('Cannot queue status change: Product ID %d not found', $product_id));
            return 0;
        }

        $added = $this->queue_product_operation([
            'product_id' => $product_id,
            'product_sku' => $product_data['sku'],
            'category_ids' => $product_data['categories'],
            'tag_ids' => $product_data['tags'],
            'sync_type' => 'status_change',
            'trigger' => $trigger,
            'priority' => $priority,
            'transient_data' => [
                'old_status' => $old_status,
                'new_status' => $new_status,
            ],
            'log_action' => 'status change',
        ]);

        // Additional logging with status details
        if ($added > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Status change details: SKU %s from "%s" to "%s"',
                $product_data['sku'] ?: 'no-sku',
                $old_status,
                $new_status
            ));
        }

        return $added;
    }

    /**
     * Add a variation deletion to the sync queue for all active stores
     *
     * @param int $variation_id Variation ID
     * @param string $trigger What triggered the queue (e.g., 'variation_delete')
     * @param int $priority Priority (lower number = higher priority)
     * @return int Number of queue items added
     */
    public function add_variation_deletion($variation_id, $trigger = 'variation_delete', $priority = self::PRIORITY_HIGH): int {
        global $wpdb;

        // Get variation data and parent ID with direct SQL - no product object loading
        $variation_data = $wpdb->get_row($wpdb->prepare(
            "SELECT p.post_parent, pm.meta_value as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.ID = %d AND p.post_type = 'product_variation'",
            $variation_id
        ));

        if (!$variation_data || !$variation_data->post_parent) {
            WC_Multi_Store_Logger::write(sprintf('Cannot queue variation deletion: Variation ID %d not found or has no parent', $variation_id));
            return 0;
        }

        $parent_id = (int) $variation_data->post_parent;
        $sku = $variation_data->sku ?: '';

        // Get parent product categories and tags
        $terms_data = $this->get_product_terms($parent_id);

        return $this->queue_product_operation([
            'product_id' => $variation_id,
            'product_sku' => $sku,
            'category_ids' => $terms_data['categories'],
            'tag_ids' => $terms_data['tags'],
            'sync_type' => 'delete_variation',
            'trigger' => $trigger,
            'priority' => $priority,
            'transient_data' => [
                'parent_id' => $parent_id,
            ],
            'log_action' => 'variation deletion',
        ]);
    }

    /**
     * Get queue count
     *
     * @return int Number of items in queue
     */
    public function get_queue_count(): int {
        $stats = WC_Multi_Store_Queue_Table::get_stats();
        return $stats['pending'];
    }

    /**
     * Process the queue
     *
     * @param int $batch_size Number of products to process (0 = use time-based batch size)
     * @return array Processing results
     */
    public function process_queue($batch_size = 0, ?callable $item_callback = null): array {
        // Always reset stuck items first, even before checking processing flag
        // This ensures items don't stay stuck indefinitely if the processor crashed
        // Use shorter timeout (5 min) to be more aggressive about recovery
        WC_Multi_Store_Queue_Table::reset_stuck_items(5);

        global $wpdb;

        // Acquire a MySQL advisory lock (atomic, no race conditions).
        // GET_LOCK returns 1 if acquired, 0 if already held by another connection.
        // Timeout of 0 means non-blocking: returns immediately if lock is held.
        $lock_acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, 0)",
            self::LOCK_NAME
        ));

        if ($lock_acquired != 1) {
            WC_Multi_Store_Logger::write('Queue processor: Already running (DB lock held), skipping.');
            return [
                'status' => 'skipped',
                'message' => 'Queue processor already running',
            ];
        }

        try {
            $results = $this->do_process_queue($batch_size, $item_callback);
        } finally {
            // Release the advisory lock
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", self::LOCK_NAME));
        }

        return $results;
    }

    /**
     * Internal queue processing
     *
     * @param int $batch_size Batch size
     * @return array Results
     */
    private function do_process_queue($batch_size, ?callable $item_callback = null): array {
        // Secondary reset for items stuck longer (10 min threshold)
        // Primary reset with 5 min threshold runs in process_queue() before flag check
        WC_Multi_Store_Queue_Table::reset_stuck_items(10);

        // Get batch size from time manager if not specified
        if ($batch_size === 0) {
            $batch_size = WC_Multi_Store_Time_Manager::get_batch_size();
        }

        // Get next batch from queue table
        $queue_items = WC_Multi_Store_Queue_Table::get_next_batch($batch_size);

        $time_period = WC_Multi_Store_Time_Manager::get_time_period();

        if (empty($queue_items)) {
            return [
                'status' => 'empty',
                'message' => 'Queue is empty',
            ];
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Queue processor started: processing %d item(s) (%s)',
            count($queue_items),
            $time_period
        ));

        // Fetch stores once before loop to avoid N+1 query pattern
        $stores = WC_Multi_Store_Settings::get_stores();
        $sync_engine = WC_MSS()->sync_engine;

        $processed = 0;
        $success_count = 0;
        $error_count = 0;
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $total_items = count($queue_items);
        $item_index = 0;

        foreach ($queue_items as $queue_item) {
            $item_index++;
            if ($memory_limit > 0 && memory_get_usage(true) > $memory_limit * 0.85) {
                WC_Multi_Store_Logger::write('Queue processor: approaching memory limit, stopping batch early', 'warning');
                break;
            }

            $item_id = $queue_item['id'];
            $product_id = $queue_item['product_id'];
            $store_url = $queue_item['store_url'];
            $sync_type = $queue_item['sync_type'];

            // Get SKU and extra data from queue table (primary source)
            $stored_sku = $queue_item['product_sku'] ?? null;
            $stored_extra = !empty($queue_item['extra_data']) ? json_decode($queue_item['extra_data'], true) : [];

            $notify = $item_callback ? static function (string $status, string $message = '') use ($item_callback, $item_id, $product_id, $stored_sku, $store_url, $sync_type, $item_index, $total_items): void {
                ($item_callback)([
                    'status'     => $status,
                    'message'    => $message,
                    'item_id'    => $item_id,
                    'product_id' => $product_id,
                    'sku'        => $stored_sku ?? '',
                    'store_url'  => $store_url,
                    'sync_type'  => $sync_type,
                    'index'      => $item_index,
                    'total'      => $total_items,
                ]);
            } : null;

            if (WC_Multi_Store_Circuit_Breaker::is_open($store_url)) {
                $cb_status = WC_Multi_Store_Circuit_Breaker::get_status($store_url);
                $mins      = (int) ceil($cb_status['seconds_remaining'] / 60);
                WC_Multi_Store_Logger::write(sprintf(
                    'Circuit open for %s — skipping item %d, resumes in ~%d min.',
                    $store_url, $item_id, $mins
                ), 'debug');
                if ($notify) $notify('skipped', "Circuit open — server recovering (~{$mins} min)");
                continue;
            }

            // Mark as processing
            WC_Multi_Store_Queue_Table::mark_processing($item_id);

            try {
                // Get store config from pre-loaded stores
                if (!isset($stores[$store_url])) {
                    WC_Multi_Store_Queue_Table::mark_failed($item_id, 'Store configuration not found');
                    $error_count++;
                    $processed++;
                    if ($notify) $notify('error', 'Store configuration not found');
                    continue;
                }

                $store_config = $stores[$store_url];
                $result = null;
                $transient_key = null; // Track transient for cleanup on success only

                // Handle special sync types
                if ($sync_type === 'delete_product') {
                    $product_sku = $stored_sku;
                    $transient_key = 'wc_mss_delete_' . $product_id . '_' . md5($store_url);

                    if (empty($product_sku)) {
                        WC_Multi_Store_Queue_Table::mark_failed($item_id, 'Product SKU not found for deletion');
                        WC_Multi_Store_Logger::write(sprintf('Product deletion: SKU not found for ID %d, queue item %d failed', $product_id, $item_id));
                        $error_count++;
                        $processed++;
                        if ($notify) $notify('error', 'Product SKU not found for deletion');
                        continue;
                    }

                    // Delete product from remote store
                    $result = $sync_engine->delete_product_from_store(
                        $product_id,
                        $product_sku,
                        $store_url,
                        $store_config,
                        $queue_item['source']
                    );

                    // Update deletion audit log status
                    if (class_exists('WC_Multi_Store_Deletion_Audit')) {
                        $audit_status = ($result && !empty($result['success'])) ? 'completed' : 'failed';
                        $audit_error = ($audit_status === 'failed') ? ($result['message'] ?? 'Unknown error') : null;

                        // Find the most recent pending audit for this product
                        $audit_logs = WC_Multi_Store_Deletion_Audit::get_logs([
                            'product_id' => $product_id,
                            'status' => 'pending',
                            'per_page' => 1,
                        ]);
                        if (!empty($audit_logs) && !empty($audit_logs[0]['id'])) {
                            WC_Multi_Store_Deletion_Audit::update_status((int) $audit_logs[0]['id'], $audit_status, $audit_error);
                        }
                    }

                } elseif ($sync_type === 'status_change') {
                    $product_sku = $stored_sku;
                    $new_status = $stored_extra['new_status'] ?? null;
                    $transient_key = 'wc_mss_status_' . $product_id . '_' . md5($store_url);

                    // If SKU is missing from stored data, try to look it up from the live product
                    if (empty($product_sku)) {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $product_sku = $product->get_sku();
                        }
                    }

                    if (empty($new_status)) {
                        WC_Multi_Store_Queue_Table::mark_failed($item_id, 'Status change data not found: missing new_status');
                        WC_Multi_Store_Logger::write(sprintf('Product status change data not found for ID %d, queue item %d failed', $product_id, $item_id));
                        $error_count++;
                        $processed++;
                        if ($notify) $notify('error', 'Status change data not found');
                        continue;
                    }

                    // Update product status on remote store
                    $result = $sync_engine->update_product_status_on_store(
                        $product_id,
                        $product_sku,
                        $new_status,
                        $store_url,
                        $store_config,
                        $queue_item['source']
                    );

                } elseif ($sync_type === 'restore_product') {
                    $product_sku = $stored_sku;
                    $transient_key = 'wc_mss_restore_' . $product_id . '_' . md5($store_url);

                    if (empty($product_sku)) {
                        WC_Multi_Store_Queue_Table::mark_failed($item_id, 'Product SKU not found for restoration');
                        WC_Multi_Store_Logger::write(sprintf('Product restoration data not found for ID %d, queue item %d failed', $product_id, $item_id));
                        $error_count++;
                        $processed++;
                        if ($notify) $notify('error', 'Product SKU not found for restoration');
                        continue;
                    }

                    // Restore product on remote store
                    $result = $sync_engine->restore_product_on_store(
                        $product_id,
                        $product_sku,
                        $store_url,
                        $store_config,
                        $queue_item['source']
                    );

                } elseif ($sync_type === 'delete_variation') {
                    $product_sku = $stored_sku;
                    $parent_id = $stored_extra['parent_id'] ?? null;
                    $transient_key = 'wc_mss_delete_var_' . $product_id . '_' . md5($store_url);

                    if (empty($product_sku) || empty($parent_id)) {
                        WC_Multi_Store_Queue_Table::mark_failed($item_id, 'Variation data not found for deletion');
                        WC_Multi_Store_Logger::write(sprintf('Variation deletion data not found for ID %d, queue item %d failed', $product_id, $item_id));
                        $error_count++;
                        $processed++;
                        if ($notify) $notify('error', 'Variation data not found for deletion');
                        continue;
                    }

                    // Delete variation from remote store
                    $result = $sync_engine->delete_variation_from_store(
                        $product_id,
                        $product_sku,
                        $parent_id,
                        $store_url,
                        $store_config,
                        $queue_item['source']
                    );

                } elseif ($sync_type === 'delete_orphan') {
                    $product_sku = $stored_sku;
                    $remote_product_id = $stored_extra['remote_product_id'] ?? null;
                    $transient_key = 'wc_mss_orphan_delete_' . $product_id . '_' . md5($store_url);

                    // Need either remote_product_id OR sku for deletion
                    if (empty($remote_product_id) && empty($product_sku)) {
                        WC_Multi_Store_Queue_Table::mark_failed($item_id, 'Orphan data not found (need remote_product_id or sku)');
                        WC_Multi_Store_Logger::write(sprintf('Orphan deletion data not found for ID %d, queue item %d failed', $product_id, $item_id));
                        $error_count++;
                        $processed++;
                        if ($notify) $notify('error', 'Orphan data not found');
                        continue;
                    }

                    // Delete orphan product (sync engine will look up by SKU if remote_product_id is empty)
                    $result = $sync_engine->delete_orphan_product_from_store(
                        $product_id,
                        $product_sku ?: '',
                        $remote_product_id,
                        $store_url,
                        $store_config,
                        $queue_item['source']
                    );

                } else {
                    // Regular sync (create/update)
                    $product = wc_get_product($product_id);

                    if (!$product) {
                        // Product no longer exists, mark as failed
                        WC_Multi_Store_Queue_Table::mark_failed($item_id, 'Product not found');
                        WC_Multi_Store_Logger::write(sprintf('Product ID %d not found, queue item %d failed', $product_id, $item_id));
                        $error_count++;
                        $processed++;
                        if ($notify) $notify('error', 'Product not found');
                        continue;
                    }

                    // Skip non-published products - only published products should be synced to remote stores
                    if ($product->get_status() !== 'publish') {
                        WC_Multi_Store_Queue_Table::mark_completed($item_id);
                        WC_Multi_Store_Logger::write(sprintf(
                            'Skipping sync for product %s (ID %d) - status is "%s", only published products are synced',
                            $product->get_sku() ?: 'no-sku',
                            $product_id,
                            $product->get_status()
                        ), 'debug');
                        $success_count++;
                        $processed++;
                        if ($notify) $notify('skipped', 'Not published (' . $product->get_status() . ')');
                        continue;
                    }

                    // Re-check exclusion at processing time — catches products moved to an excluded
                    // category after being queued, and variations whose queue-time check used empty categories
                    $exclusion_target = $product->is_type('variation')
                        ? (wc_get_product($product->get_parent_id()) ?: $product)
                        : $product;
                    if (WC_Multi_Store_Product_Exclusion_Filter::should_exclude($exclusion_target, $store_config)) {
                        WC_Multi_Store_Queue_Table::mark_completed($item_id);
                        WC_Multi_Store_Logger::write(sprintf(
                            'Product %s (ID %d) skipped for store %s: excluded by category/tag filter',
                            $product->get_sku() ?: 'no-sku',
                            $product_id,
                            $store_url
                        ), 'debug');
                        $success_count++;
                        $processed++;
                        if ($notify) $notify('skipped', 'Excluded by category/tag filter');
                        continue;
                    }

                    // Sync product to this specific store
                    $result = $sync_engine->sync_product_to_store(
                        $product,
                        $store_url,
                        $store_config,
                        $sync_type,
                        $queue_item['source']
                    );
                }

                if ($result && isset($result['success']) && $result['success']) {
                    WC_Multi_Store_Queue_Table::mark_completed($item_id);
                    $success_count++;
                    WC_Multi_Store_Circuit_Breaker::record_success($store_url);
                    if ($notify) $notify('success');

                    // Clean up transient only on success (so retries can still access the data)
                    if ($transient_key) {
                        delete_transient($transient_key);
                    }
                } else {
                    $error_message = $result['message'] ?? 'Unknown error';

                    // Don't retry if product not found on remote store or has no SKU - retrying is pointless
                    $no_retry = str_contains($error_message, 'Product not found on remote store')
                        || str_contains($error_message, 'Product has no SKU');

                    WC_Multi_Store_Queue_Table::mark_failed($item_id, $error_message, $no_retry);
                    $error_count++;
                    if (!$no_retry) {
                        WC_Multi_Store_Circuit_Breaker::record_failure($store_url);
                    }
                    if ($notify) $notify('error', $error_message);
                }

                $processed++;

            } catch (\Throwable $e) {
                WC_Multi_Store_Queue_Table::mark_failed($item_id, $e->getMessage());
                $error_count++;
                $processed++;
                WC_Multi_Store_Circuit_Breaker::record_failure($store_url);
                if ($notify) $notify('error', $e->getMessage());

                WC_Multi_Store_Logger::write(sprintf(
                    'Exception processing queue item %d (Product ID %d): %s in %s:%d',
                    $item_id,
                    $product_id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ), 'error');
            }

            // Free memory
            unset($product, $result);

            // Throttle: brief pause every 50 items to give remote APIs breathing room
            // Note: the API client already has its own rate limiter, so this is just a safety net
            if ($processed > 0 && $processed % 50 === 0) {
                usleep(500000); // 0.5 seconds
            }
        }

        // Get remaining count
        $stats = WC_Multi_Store_Queue_Table::get_stats();

        WC_Multi_Store_Logger::write(sprintf(
            'Queue processor finished: %d processed, %d success, %d errors, %d pending',
            $processed,
            $success_count,
            $error_count,
            $stats['pending']
        ));

        return [
            'status' => 'completed',
            'processed' => $processed,
            'success' => $success_count,
            'errors' => $error_count,
            'remaining' => $stats['pending'],
            'time_period' => $time_period,
        ];
    }

    /**
     * Clear the queue
     *
     * @return int Number of items cleared
     */
    public function clear_queue(): int|false {
        WC_Multi_Store_Logger::write('Queue cleared manually');
        return WC_Multi_Store_Queue_Table::clear_all();
    }

    /**
     * Remove a product from the queue (all pending items for this product)
     *
     * @param int $product_id Product ID
     * @return int Number of items removed
     */
    public function remove_product($product_id): int {
        global $wpdb;
        $table_name = $wpdb->prefix . WC_Multi_Store_Queue_Table::TABLE_NAME;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE product_id = %d AND status = 'pending'",
            $product_id
        ));

        return $deleted ? $deleted : 0;
    }

    /**
     * Check if a product is in the queue
     *
     * @param int $product_id Product ID
     * @return bool True if in queue
     */
    public function is_queued($product_id): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . WC_Multi_Store_Queue_Table::TABLE_NAME;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE product_id = %d AND status = 'pending'",
            $product_id
        ));

        return $count > 0;
    }

    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public function get_statistics(): array {
        return WC_Multi_Store_Queue_Table::get_stats();
    }

    /**
     * Cleanup old completed and failed queue items
     *
     * @param int $days Keep items from last X days
     * @return int Number of items removed
     */
    public function cleanup_old_items($days = 7): int|false {
        return WC_Multi_Store_Queue_Table::cleanup($days);
    }
}
