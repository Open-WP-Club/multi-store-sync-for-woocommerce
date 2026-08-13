<?php
/**
 * Deletion Audit Log Class
 * Enhanced logging specifically for product deletions
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Deletion_Audit {

    use WC_Multi_Store_Archive_Before_Delete;

    /**
     * Database table name
     *
     * @var string
     */
    private const string TABLE_NAME = 'wc_mss_deletion_audit';

    /**
     * Create the deletion audit table
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            product_sku varchar(100) DEFAULT NULL,
            product_name text DEFAULT NULL,
            user_id bigint(20) unsigned NOT NULL,
            user_name varchar(255) DEFAULT NULL,
            deletion_type varchar(50) NOT NULL DEFAULT 'manual',
            stores_affected text NOT NULL,
            product_data_before longtext DEFAULT NULL,
            product_data_after longtext DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            error_message text DEFAULT NULL,
            deleted_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY product_sku (product_sku),
            KEY user_id (user_id),
            KEY deleted_at (deleted_at),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        WC_Multi_Store_Logger::write('Deletion audit table created/verified');
    }

    /**
     * Log a product deletion
     *
     * @param int $product_id Product ID
     * @param array $stores_affected Array of store URLs
     * @param string $deletion_type Type of deletion (manual, bulk, category, etc.)
     * @return int|false Audit log ID or false on failure
     */
    public static function log_deletion(int $product_id, array $stores_affected = [], string $deletion_type = 'manual'): int|false {
        global $wpdb;

        // Get product object
        $product = wc_get_product($product_id);

        if (!$product) {
            WC_Multi_Store_Logger::write(sprintf('Deletion audit: Product ID %d not found', $product_id));
            return false;
        }

        // Get current user
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_name = $current_user->display_name;

        // Capture product data before deletion
        $product_data_before = self::capture_product_data($product);

        // Prepare stores affected
        $stores_json = json_encode($stores_affected);

        // Insert audit log
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->insert(
            $table_name,
            [
                'product_id' => $product_id,
                'product_sku' => $product->get_sku(),
                'product_name' => $product->get_name(),
                'user_id' => $user_id,
                'user_name' => $user_name,
                'deletion_type' => $deletion_type,
                'stores_affected' => $stores_json,
                'product_data_before' => json_encode($product_data_before),
                'product_data_after' => null,
                'status' => 'pending',
                'deleted_at' => current_time('mysql'),
            ],
            [
                '%d', // product_id
                '%s', // product_sku
                '%s', // product_name
                '%d', // user_id
                '%s', // user_name
                '%s', // deletion_type
                '%s', // stores_affected
                '%s', // product_data_before
                '%s', // product_data_after
                '%s', // status
                '%s', // deleted_at
            ]
        );

        if ($result === false) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to create deletion audit log for product ID %d: %s',
                $product_id,
                $wpdb->last_error
            ));
            return false;
        }

        $audit_id = $wpdb->insert_id;

        WC_Multi_Store_Logger::write(sprintf(
            'Deletion audit log created: ID %d, Product ID %d, User: %s, Stores: %d',
            $audit_id,
            $product_id,
            $user_name,
            count($stores_affected)
        ));

        return $audit_id;
    }

    /**
     * Update audit log status
     *
     * @param int $audit_id Audit log ID
     * @param string $status Status (pending, completed, failed)
     * @param string|null $error_message Optional error message
     * @return bool Success
     */
    public static function update_status(int $audit_id, string $status, ?string $error_message = null): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $update_data = [
            'status' => $status,
        ];

        $update_format = ['%s'];

        if ($status === 'completed' || $status === 'failed') {
            $update_data['completed_at'] = current_time('mysql');
            $update_format[] = '%s';
        }

        if ($error_message !== null) {
            $update_data['error_message'] = $error_message;
            $update_format[] = '%s';
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $audit_id],
            $update_format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Capture product data for audit trail
     *
     * @param WC_Product $product Product object
     * @return array Product data
     */
    private static function capture_product_data(WC_Product $product): array {
        $data = [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'manage_stock' => $product->get_manage_stock(),
            'categories' => [],
            'tags' => [],
            'images' => [],
        ];

        // Get categories
        $category_ids = $product->get_category_ids();
        foreach ($category_ids as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $data['categories'][] = $term->name;
            }
        }

        // Get tags
        $tag_ids = $product->get_tag_ids();
        foreach ($tag_ids as $tag_id) {
            $term = get_term($tag_id, 'product_tag');
            if ($term && !is_wp_error($term)) {
                $data['tags'][] = $term->name;
            }
        }

        // Get images
        $image_id = $product->get_image_id();
        if ($image_id) {
            $data['images']['main'] = wp_get_attachment_url($image_id);
        }

        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $gallery_id) {
            $data['images']['gallery'][] = wp_get_attachment_url($gallery_id);
        }

        // For variable products, include variations
        if ($product->is_type('variable')) {
            $data['variations'] = [];
            $variations = $product->get_children();

            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $data['variations'][] = [
                        'id' => $variation_id,
                        'sku' => $variation->get_sku(),
                        'price' => $variation->get_price(),
                        'stock' => $variation->get_stock_quantity(),
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * Get audit logs with filters
     *
     * @param array $args Query arguments
     * @return array Audit logs
     */
    public static function get_logs(array $args = []): array {
        global $wpdb;

        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'product_id' => null,
            'user_id' => null,
            'status' => null,
            'deletion_type' => null,
            'date_from' => null,
            'date_to' => null,
            'orderby' => 'deleted_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        [$where_sql, $where_values] = self::build_where_clause($args);

        $allowed_columns = ['id', 'product_id', 'product_sku', 'product_name', 'user_id', 'deletion_type', 'status', 'deleted_at', 'completed_at'];
        if (!in_array($args['orderby'], $allowed_columns, true)) {
            $args['orderby'] = 'deleted_at';
        }
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'deleted_at DESC';
        }

        $sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Decode JSON fields
        foreach ($results as &$result) {
            $result['stores_affected'] = json_decode($result['stores_affected'], true);
            $result['product_data_before'] = json_decode($result['product_data_before'], true);
            $result['product_data_after'] = json_decode($result['product_data_after'], true);
        }

        return $results;
    }

    /**
     * Get total count of audit logs
     *
     * @param array $args Query arguments
     * @return int Total count
     */
    public static function get_total_count(array $args = []): int {
        global $wpdb;

        $defaults = [
            'product_id' => null,
            'user_id' => null,
            'status' => null,
            'deletion_type' => null,
            'date_from' => null,
            'date_to' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        [$where_sql, $where_values] = self::build_where_clause($args);

        $sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Build the WHERE clause + prepared values shared by get_logs() and
     * get_total_count() — both filter on the same subset of $args.
     *
     * @param array $args Filter arguments (product_id, user_id, status, deletion_type, date_from, date_to)
     * @return array{0: string, 1: array} [$where_sql, $where_values]
     */
    private static function build_where_clause(array $args): array {
        $where = ['1=1'];
        $where_values = [];

        if ($args['product_id']) {
            $where[] = 'product_id = %d';
            $where_values[] = $args['product_id'];
        }

        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }

        if ($args['status']) {
            $where[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if ($args['deletion_type']) {
            $where[] = 'deletion_type = %s';
            $where_values[] = $args['deletion_type'];
        }

        if ($args['date_from']) {
            $where[] = 'deleted_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if ($args['date_to']) {
            $where[] = 'deleted_at <= %s';
            $where_values[] = $args['date_to'];
        }

        return [implode(' AND ', $where), $where_values];
    }

    /**
     * Delete old audit logs
     *
     * @param int $days Days to keep (default 90)
     * @return int Number of deleted logs
     */
    public static function cleanup_old_logs(int $days = 90): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $records = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE deleted_at < %s ORDER BY deleted_at ASC",
                $cutoff_date
            ),
            ARRAY_A
        );

        if (!empty($records)) {
            self::archive_records_to_json('deletion-audit', $records, 'deleted_at');
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE deleted_at < %s",
                $cutoff_date
            )
        );

        WC_Multi_Store_Logger::write(sprintf('Cleaned up %d old deletion audit logs', $result));

        return $result;
    }
}
