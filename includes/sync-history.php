<?php
/**
 * WooCommerce Multi-Store Sync History
 *
 * Manages sync history and statistics using custom database table
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync History Class
 */
class WC_Multi_Store_Sync_History {

    /**
     * Table name (without prefix)
     */
    private static string $table_name = 'wc_mss_sync_history';

    /**
     * Create database table on activation
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            product_sku varchar(200) DEFAULT '',
            product_name varchar(200) DEFAULT '',
            store_url varchar(200) NOT NULL,
            sync_type varchar(50) NOT NULL,
            sync_source varchar(50) DEFAULT 'manual',
            status varchar(20) NOT NULL,
            message text,
            remote_product_id bigint(20) DEFAULT NULL,
            duration_ms int DEFAULT NULL,
            memory_mb decimal(10,2) DEFAULT NULL,
            api_calls int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY store_url (store_url),
            KEY status (status),
            KEY created_at (created_at),
            KEY sync_type (sync_type),
            KEY sync_source (sync_source),
            KEY product_store (product_id, store_url(50)),
            KEY status_created (status, created_at),
            KEY sync_type_created (sync_type, created_at),
            KEY sync_source_created (sync_source, created_at),
            KEY product_created (product_id, created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Log table creation
        WC_Multi_Store_Logger::write('Sync history table created: ' . $table_name);
    }

    /**
     * Log sync operation
     *
     * @param array $data Sync data
     * @return int|false Insert ID or false on failure
     */
    public static function log_sync(array $data): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $defaults = [
            'product_id' => 0,
            'product_sku' => '',
            'product_name' => '',
            'store_url' => '',
            'sync_type' => 'full_product',
            'sync_source' => 'manual',
            'status' => 'success',
            'message' => '',
            'remote_product_id' => null,
            'duration_ms' => null,
            'memory_mb' => null,
            'api_calls' => 0,
        ];

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table_name,
            [
                'product_id' => absint($data['product_id']),
                'product_sku' => sanitize_text_field($data['product_sku']),
                'product_name' => sanitize_text_field($data['product_name']),
                'store_url' => esc_url_raw($data['store_url']),
                'sync_type' => sanitize_text_field($data['sync_type']),
                'sync_source' => sanitize_text_field($data['sync_source']),
                'status' => sanitize_text_field($data['status']),
                'message' => sanitize_textarea_field($data['message']),
                'remote_product_id' => $data['remote_product_id'] ? absint($data['remote_product_id']) : null,
                'duration_ms' => $data['duration_ms'] ? absint($data['duration_ms']) : null,
                'memory_mb' => $data['memory_mb'] ? floatval($data['memory_mb']) : null,
                'api_calls' => absint($data['api_calls']),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d']
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get sync history with pagination
     *
     * @param array $args Query arguments
     * @return array Results
     */
    public static function get_history(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'product_id' => null,
            'store_url' => null,
            'status' => null,
            'sync_type' => null,
            'date_from' => null,
            'date_to' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where = ['1=1'];

        if ($args['product_id']) {
            $where[] = $wpdb->prepare('product_id = %d', $args['product_id']);
        }

        if ($args['store_url']) {
            // Use LIKE for flexible URL matching (handles trailing slashes, http/https differences)
            $store_url_clean = rtrim(preg_replace('#^https?://#', '', $args['store_url']), '/');
            $store_url_pattern = '%' . $wpdb->esc_like($store_url_clean) . '%';
            $where[] = $wpdb->prepare('store_url LIKE %s', $store_url_pattern);
        }

        if ($args['status']) {
            $where[] = $wpdb->prepare('status = %s', $args['status']);
        }

        if ($args['sync_type']) {
            $where[] = $wpdb->prepare('sync_type = %s', $args['sync_type']);
        }

        if ($args['date_from']) {
            $where[] = $wpdb->prepare('created_at >= %s', $args['date_from']);
        }

        if ($args['date_to']) {
            $where[] = $wpdb->prepare('created_at <= %s', $args['date_to']);
        }

        $where_clause = implode(' AND ', $where);

        // Build ORDER BY clause — whitelist valid columns to prevent schema exposure via SQL errors.
        $allowed_columns = ['id', 'product_id', 'product_sku', 'product_name', 'store_url', 'sync_type', 'sync_source', 'status', 'duration_ms', 'api_calls', 'created_at'];
        if (!in_array($args['orderby'], $allowed_columns, true)) {
            $args['orderby'] = 'created_at';
        }
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'created_at DESC';
        }

        // Get results
        $sql = "SELECT * FROM {$table_name}
                WHERE {$where_clause}
                ORDER BY {$orderby}
                LIMIT %d OFFSET %d";

        $results = $wpdb->get_results(
            $wpdb->prepare($sql, $args['limit'], $args['offset']),
            ARRAY_A
        );

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
        $total = $wpdb->get_var($count_sql);

        return [
            'results' => $results,
            'total' => (int) $total,
            'limit' => $args['limit'],
            'offset' => $args['offset'],
        ];
    }

    /**
     * Get sync statistics
     *
     * @param array $args Filter arguments
     * @return array Statistics
     */
    public static function get_statistics(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $defaults = [
            'days' => 7,
            'store_url' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where = [];
        $where[] = $wpdb->prepare('created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $args['days']);

        if ($args['store_url']) {
            // Use LIKE for flexible URL matching (handles trailing slashes, http/https differences)
            $store_url_clean = rtrim(preg_replace('#^https?://#', '', $args['store_url']), '/');
            $store_url_pattern = '%' . $wpdb->esc_like($store_url_clean) . '%';
            $where[] = $wpdb->prepare('store_url LIKE %s', $store_url_pattern);
        }

        $where_clause = implode(' AND ', $where);

        // Get overall statistics
        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total_syncs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_syncs,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed_syncs,
                AVG(duration_ms) as avg_duration_ms,
                MAX(duration_ms) as max_duration_ms,
                AVG(memory_mb) as avg_memory_mb,
                SUM(api_calls) as total_api_calls
            FROM {$table_name}
            WHERE {$where_clause}
        ", ARRAY_A);

        // Get by sync type
        $by_type = $wpdb->get_results("
            SELECT
                sync_type,
                COUNT(*) as count,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                AVG(duration_ms) as avg_duration_ms
            FROM {$table_name}
            WHERE {$where_clause}
            GROUP BY sync_type
        ", ARRAY_A);

        // Get by store
        $by_store = $wpdb->get_results("
            SELECT
                store_url,
                COUNT(*) as count,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed,
                AVG(duration_ms) as avg_duration_ms
            FROM {$table_name}
            WHERE {$where_clause}
            GROUP BY store_url
        ", ARRAY_A);

        // Get daily stats — LIMIT to requested days to avoid unbounded result sets
        $daily_stats = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed
            FROM {$table_name}
            WHERE {$where_clause}
            GROUP BY DATE(created_at)
            ORDER BY date DESC
            LIMIT %d
        ", $args['days']), ARRAY_A);

        // Calculate success rate
        $success_rate = 0;
        if ($stats['total_syncs'] > 0) {
            $success_rate = round(($stats['successful_syncs'] / $stats['total_syncs']) * 100, 2);
        }

        return [
            'overall' => array_merge($stats, ['success_rate' => $success_rate]),
            'by_type' => $by_type,
            'by_store' => $by_store,
            'daily' => $daily_stats,
        ];
    }

    /**
     * Clear old history records
     *
     * @param int $days Keep records from last N days
     * @return int Number of deleted records
     */
    public static function cleanup_old_records(int $days = 90): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        if ($deleted) {
            WC_Multi_Store_Logger::write("Cleaned up {$deleted} old sync history records (older than {$days} days)");
            $wpdb->query("OPTIMIZE TABLE {$table_name}");
        }

        return (int) $deleted;
    }

    /**
     * Delete all history records
     *
     * @return bool Success
     */
    public static function clear_all(): bool {
        global $wpdb;

        $table_name = esc_sql($wpdb->prefix . self::$table_name);

        $result = $wpdb->query("TRUNCATE TABLE {$table_name}");

        if ($result !== false) {
            WC_Multi_Store_Logger::write('All sync history cleared');
            return true;
        }

        return false;
    }

    /**
     * Delete history records by criteria
     *
     * @param array $args {
     *     Deletion criteria (at least one required)
     *
     *     @type string $status      Delete by status (success/error)
     *     @type string $store_url   Delete by store URL
     *     @type string $sync_type   Delete by sync type
     *     @type string $date_before Delete records before this date (Y-m-d format)
     *     @type int    $days_old    Delete records older than N days
     * }
     * @return int Number of deleted records
     */
    public static function delete_by_criteria(array $args): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        // Build WHERE clause - require at least one criteria
        $where = [];

        if (!empty($args['status'])) {
            $where[] = $wpdb->prepare('status = %s', sanitize_text_field($args['status']));
        }

        if (!empty($args['store_url'])) {
            $store_url_clean = rtrim(preg_replace('#^https?://#', '', $args['store_url']), '/');
            $store_url_pattern = '%' . $wpdb->esc_like($store_url_clean) . '%';
            $where[] = $wpdb->prepare('store_url LIKE %s', $store_url_pattern);
        }

        if (!empty($args['sync_type'])) {
            $where[] = $wpdb->prepare('sync_type = %s', sanitize_text_field($args['sync_type']));
        }

        if (!empty($args['date_before'])) {
            $where[] = $wpdb->prepare('created_at < %s', sanitize_text_field($args['date_before']));
        }

        if (!empty($args['days_old'])) {
            $where[] = $wpdb->prepare('created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', absint($args['days_old']));
        }

        // Safety check - don't delete everything without explicit criteria
        if (empty($where)) {
            WC_Multi_Store_Logger::write('Delete by criteria called without any criteria - operation aborted', 'warning');
            return 0;
        }

        $where_clause = implode(' AND ', $where);

        $deleted = $wpdb->query("DELETE FROM {$table_name} WHERE {$where_clause}");

        if ($deleted) {
            $criteria_desc = implode(', ', array_keys(array_filter($args)));
            WC_Multi_Store_Logger::write("Deleted {$deleted} sync history records by criteria: {$criteria_desc}");
            $wpdb->query("OPTIMIZE TABLE {$table_name}");
        }

        return (int) $deleted;
    }

    /**
     * Delete history records by status
     *
     * @param string $status Status to delete (success/error)
     * @return int Number of deleted records
     */
    public static function delete_by_status(string $status): int {
        return self::delete_by_criteria(['status' => $status]);
    }

    /**
     * Delete history records by store URL
     *
     * @param string $store_url Store URL
     * @return int Number of deleted records
     */
    public static function delete_by_store(string $store_url): int {
        return self::delete_by_criteria(['store_url' => $store_url]);
    }

    /**
     * Delete error records only
     *
     * @return int Number of deleted records
     */
    public static function delete_errors(): int {
        return self::delete_by_status('error');
    }

    /**
     * Delete success records only
     *
     * @return int Number of deleted records
     */
    public static function delete_successful(): int {
        return self::delete_by_status('success');
    }

    /**
     * Get count of records by status
     *
     * @param string|null $status Optional status filter
     * @return int Count
     */
    public static function get_count(?string $status = null): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        if ($status) {
            return (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", $status)
            );
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }

    /**
     * Get table size in bytes
     *
     * @return int Size in bytes
     */
    public static function get_table_size(): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $size = $wpdb->get_var($wpdb->prepare(
            "SELECT (data_length + index_length) as size
             FROM information_schema.TABLES
             WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));

        return (int) $size;
    }

    /**
     * Get table name with prefix
     *
     * @return string Full table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }
}
