<?php
/**
 * WooCommerce Multi-Store Remote Order Table
 *
 * Manages remote order data storage using custom database tables
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remote Order Table Class
 */
class WC_Multi_Store_Remote_Order_Table {

    /**
     * Database version
     */
    const string DB_VERSION = '1.0';

    /**
     * Orders table name (without prefix)
     *
     * @var string
     */
    private const string ORDERS_TABLE = 'wc_mss_remote_orders';

    /**
     * Order items table name (without prefix)
     *
     * @var string
     */
    private const string ITEMS_TABLE = 'wc_mss_remote_order_items';

    /**
     * Create database tables on activation
     */
    public static function create_table(): void {
        global $wpdb;

        $orders_table = $wpdb->prefix . self::ORDERS_TABLE;
        $items_table = $wpdb->prefix . self::ITEMS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        // Create remote orders table
        $sql_orders = "CREATE TABLE IF NOT EXISTS {$orders_table} (
            id                bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            remote_order_id   bigint(20) UNSIGNED NOT NULL,
            remote_store_url  varchar(255) NOT NULL,
            order_number      varchar(50) NOT NULL,
            order_key         varchar(100) DEFAULT NULL,

            customer_id       bigint(20) UNSIGNED DEFAULT NULL,
            customer_email    varchar(100) DEFAULT NULL,
            customer_name     varchar(200) DEFAULT NULL,

            status            varchar(50) NOT NULL,
            currency          varchar(10) NOT NULL,
            total             decimal(10,2) NOT NULL,
            subtotal          decimal(10,2) DEFAULT 0,
            tax_total         decimal(10,2) DEFAULT 0,
            shipping_total    decimal(10,2) DEFAULT 0,
            discount_total    decimal(10,2) DEFAULT 0,

            payment_method    varchar(100) DEFAULT NULL,
            payment_method_title varchar(200) DEFAULT NULL,
            transaction_id    varchar(200) DEFAULT NULL,

            date_created      datetime NOT NULL,
            date_modified     datetime DEFAULT NULL,
            date_paid         datetime DEFAULT NULL,
            date_completed    datetime DEFAULT NULL,

            billing_address   longtext DEFAULT NULL,
            shipping_address  longtext DEFAULT NULL,
            line_items        longtext NOT NULL,
            order_meta        longtext DEFAULT NULL,

            synced_at         datetime NOT NULL,
            last_updated      datetime DEFAULT NULL,
            sync_hash         varchar(64) DEFAULT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY remote_order_store (remote_order_id, remote_store_url(100)),
            KEY remote_store_url (remote_store_url),
            KEY customer_email (customer_email),
            KEY status (status),
            KEY date_created (date_created),
            KEY date_paid (date_paid),
            KEY order_number (order_number)
        ) {$charset_collate};";

        // Create remote order items table
        $sql_items = "CREATE TABLE IF NOT EXISTS {$items_table} (
            id                bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            remote_order_id   bigint(20) UNSIGNED NOT NULL,
            product_id        bigint(20) UNSIGNED DEFAULT NULL,
            remote_product_id bigint(20) UNSIGNED DEFAULT NULL,
            product_name      varchar(255) NOT NULL,
            product_sku       varchar(100) DEFAULT NULL,
            quantity          int(11) NOT NULL,
            subtotal          decimal(10,2) NOT NULL,
            total             decimal(10,2) NOT NULL,
            tax_total         decimal(10,2) DEFAULT 0,
            meta_data         longtext DEFAULT NULL,

            PRIMARY KEY (id),
            KEY remote_order_id (remote_order_id),
            KEY product_id (product_id),
            KEY product_sku (product_sku)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_orders);
        dbDelta($sql_items);

        // Store database version
        update_option(self::ORDERS_TABLE . '_db_version', self::DB_VERSION);

        // Log table creation
        WC_Multi_Store_Logger::write('Remote order tables created: ' . $orders_table . ', ' . $items_table);
    }

    /**
     * Insert new remote order
     *
     * @param array $order_data Order data
     * @return int|false Insert ID or false on failure
     */
    public static function insert(array $order_data): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ORDERS_TABLE;

        // Calculate sync hash
        $sync_hash = self::calculate_hash($order_data);

        $defaults = [
            'remote_order_id' => 0,
            'remote_store_url' => '',
            'order_number' => '',
            'order_key' => null,
            'customer_id' => null,
            'customer_email' => null,
            'customer_name' => null,
            'status' => 'pending',
            'currency' => 'USD',
            'total' => 0,
            'subtotal' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'payment_method' => null,
            'payment_method_title' => null,
            'transaction_id' => null,
            'date_created' => current_time('mysql'),
            'date_modified' => null,
            'date_paid' => null,
            'date_completed' => null,
            'billing_address' => null,
            'shipping_address' => null,
            'line_items' => '[]',
            'order_meta' => null,
            'synced_at' => current_time('mysql'),
            'last_updated' => current_time('mysql'),
            'sync_hash' => $sync_hash,
        ];

        $data = wp_parse_args($order_data, $defaults);

        // Encode JSON fields
        if (is_array($data['billing_address'])) {
            $data['billing_address'] = json_encode($data['billing_address']);
        }
        if (is_array($data['shipping_address'])) {
            $data['shipping_address'] = json_encode($data['shipping_address']);
        }
        if (is_array($data['line_items'])) {
            $data['line_items'] = json_encode($data['line_items']);
        }
        if (is_array($data['order_meta'])) {
            $data['order_meta'] = json_encode($data['order_meta']);
        }

        $result = $wpdb->insert(
            $table_name,
            $data,
            [
                '%d', // remote_order_id
                '%s', // remote_store_url
                '%s', // order_number
                '%s', // order_key
                '%d', // customer_id
                '%s', // customer_email
                '%s', // customer_name
                '%s', // status
                '%s', // currency
                '%f', // total
                '%f', // subtotal
                '%f', // tax_total
                '%f', // shipping_total
                '%f', // discount_total
                '%s', // payment_method
                '%s', // payment_method_title
                '%s', // transaction_id
                '%s', // date_created
                '%s', // date_modified
                '%s', // date_paid
                '%s', // date_completed
                '%s', // billing_address
                '%s', // shipping_address
                '%s', // line_items
                '%s', // order_meta
                '%s', // synced_at
                '%s', // last_updated
                '%s', // sync_hash
            ]
        );

        if ($result === false) {
            WC_Multi_Store_Logger::write('Failed to insert remote order: ' . $wpdb->last_error, 'error');
            return false;
        }

        $order_id = $wpdb->insert_id;

        // Insert line items if provided
        if (!empty($order_data['line_items']) && is_array($order_data['line_items'])) {
            self::insert_line_items($order_id, $order_data['line_items']);
        }

        return $order_id;
    }

    /**
     * Insert line items for an order
     *
     * @param int   $order_id   Order ID
     * @param array $line_items Line items data
     * @return bool Success status
     */
    private static function insert_line_items(int $order_id, array $line_items): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ITEMS_TABLE;
        $success = true;

        foreach ($line_items as $item) {
            $defaults = [
                'remote_order_id' => $order_id,
                'product_id' => null,
                'remote_product_id' => null,
                'product_name' => '',
                'product_sku' => null,
                'quantity' => 1,
                'subtotal' => 0,
                'total' => 0,
                'tax_total' => 0,
                'meta_data' => null,
            ];

            $item_data = wp_parse_args($item, $defaults);

            // Encode meta data if array
            if (is_array($item_data['meta_data'])) {
                $item_data['meta_data'] = json_encode($item_data['meta_data']);
            }

            $result = $wpdb->insert(
                $table_name,
                $item_data,
                [
                    '%d', // remote_order_id
                    '%d', // product_id
                    '%d', // remote_product_id
                    '%s', // product_name
                    '%s', // product_sku
                    '%d', // quantity
                    '%f', // subtotal
                    '%f', // total
                    '%f', // tax_total
                    '%s', // meta_data
                ]
            );

            if ($result === false) {
                WC_Multi_Store_Logger::write('Failed to insert order line item: ' . $wpdb->last_error, 'error');
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Update existing remote order
     *
     * @param int   $id         Order ID
     * @param array $order_data Order data
     * @return bool Success status
     */
    public static function update(int $id, array $order_data): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ORDERS_TABLE;

        // Calculate new sync hash
        $sync_hash = self::calculate_hash($order_data);
        $order_data['sync_hash'] = $sync_hash;
        $order_data['last_updated'] = current_time('mysql');

        // Encode JSON fields
        if (isset($order_data['billing_address']) && is_array($order_data['billing_address'])) {
            $order_data['billing_address'] = json_encode($order_data['billing_address']);
        }
        if (isset($order_data['shipping_address']) && is_array($order_data['shipping_address'])) {
            $order_data['shipping_address'] = json_encode($order_data['shipping_address']);
        }
        if (isset($order_data['line_items']) && is_array($order_data['line_items'])) {
            $order_data['line_items'] = json_encode($order_data['line_items']);
        }
        if (isset($order_data['order_meta']) && is_array($order_data['order_meta'])) {
            $order_data['order_meta'] = json_encode($order_data['order_meta']);
        }

        $result = $wpdb->update(
            $table_name,
            $order_data,
            ['id' => $id],
            null, // Let wpdb determine format
            ['%d']
        );

        if ($result === false) {
            WC_Multi_Store_Logger::write('Failed to update remote order: ' . $wpdb->last_error, 'error');
            return false;
        }

        return true;
    }

    /**
     * Get remote order by ID
     *
     * @param int $id Order ID
     * @return object|null Order object or null if not found
     */
    public static function get(int $id): ?object {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ORDERS_TABLE;

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $id
        ));

        if ($order) {
            // Decode JSON fields
            $order->billing_address = json_decode($order->billing_address, true);
            $order->shipping_address = json_decode($order->shipping_address, true);
            $order->line_items = json_decode($order->line_items, true);
            $order->order_meta = json_decode($order->order_meta, true);

            // Get line items from items table
            $order->items = self::get_order_items($id);
        }

        return $order;
    }

    /**
     * Get line items for an order
     *
     * @param int $order_id Order ID
     * @return array Line items
     */
    public static function get_order_items(int $order_id): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ITEMS_TABLE;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE remote_order_id = %d",
            $order_id
        ));

        // Decode meta data for each item
        foreach ($items as $item) {
            $item->meta_data = json_decode($item->meta_data, true);
        }

        return $items;
    }

    /**
     * Build the WHERE clause + prepared values shared by get_orders(),
     * get_count(), and get_statistics() — each filters on a subset of the
     * same keys (missing keys are simply skipped).
     *
     * @param array $args May contain store_url, status, customer_email, date_from, date_to
     * @return array{0: string, 1: array} [$where_clause, $where_values]
     */
    private static function build_where_clause(array $args): array {
        $where = ['1=1'];
        $where_values = [];

        if (!empty($args['store_url'])) {
            $where[] = 'remote_store_url = %s';
            $where_values[] = $args['store_url'];
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if (!empty($args['customer_email'])) {
            $where[] = 'customer_email = %s';
            $where_values[] = $args['customer_email'];
        }

        if (!empty($args['date_from'])) {
            $where[] = 'date_created >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'date_created <= %s';
            $where_values[] = $args['date_to'];
        }

        return [implode(' AND ', $where), $where_values];
    }

    /**
     * Get orders with filters
     *
     * @param array $args Query arguments
     * @return array Orders
     */
    public static function get_orders(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ORDERS_TABLE;

        $defaults = [
            'store_url' => null,
            'status' => null,
            'customer_email' => null,
            'date_from' => null,
            'date_to' => null,
            'orderby' => 'date_created',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
        ];

        $args = wp_parse_args($args, $defaults);

        [$where_clause, $where_values] = self::build_where_clause($args);

        // Build ORDER BY clause
        $allowed_orderby = ['id', 'date_created', 'date_modified', 'total', 'status', 'customer_email'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'date_created';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build query
        $query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order}";

        if ($args['limit'] > 0) {
            $query .= $wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);
        }

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        $orders = $wpdb->get_results($query);

        // Decode JSON fields for each order
        foreach ($orders as $order) {
            $order->billing_address = json_decode($order->billing_address, true);
            $order->shipping_address = json_decode($order->shipping_address, true);
            $order->line_items = json_decode($order->line_items, true);
            $order->order_meta = json_decode($order->order_meta, true);
        }

        return $orders;
    }

    /**
     * Get total count of orders
     *
     * @param array $args Query arguments
     * @return int Total count
     */
    public static function get_count(array $args = []): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ORDERS_TABLE;

        $defaults = [
            'store_url' => null,
            'status' => null,
            'customer_email' => null,
            'date_from' => null,
            'date_to' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        [$where_clause, $where_values] = self::build_where_clause($args);

        $query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        return (int) $wpdb->get_var($query);
    }

    /**
     * Delete remote order
     *
     * @param int $id Order ID
     * @return bool Success status
     */
    public static function delete(int $id): bool {
        global $wpdb;

        $orders_table = $wpdb->prefix . self::ORDERS_TABLE;
        $items_table = $wpdb->prefix . self::ITEMS_TABLE;

        // Delete line items first
        $wpdb->delete($items_table, ['remote_order_id' => $id], ['%d']);

        // Delete order
        $result = $wpdb->delete($orders_table, ['id' => $id], ['%d']);

        return $result !== false;
    }

    /**
     * Check if order exists by remote order ID and store URL
     *
     * @param int    $remote_order_id Remote order ID
     * @param string $store_url       Store URL
     * @return string|null Local order ID or null if not found
     */
    public static function order_exists(int $remote_order_id, string $store_url): ?string {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ORDERS_TABLE;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE remote_order_id = %d AND remote_store_url = %s",
            $remote_order_id,
            $store_url
        ));
    }

    /**
     * Calculate hash for order data (for change detection)
     *
     * @param array $order_data Order data
     * @return string Hash
     */
    private static function calculate_hash(array $order_data): string {
        // Include key fields that indicate order changes
        $hash_data = [
            'status' => $order_data['status'] ?? '',
            'total' => $order_data['total'] ?? 0,
            'line_items' => $order_data['line_items'] ?? [],
            'date_modified' => $order_data['date_modified'] ?? '',
        ];

        return hash('sha256', json_encode($hash_data));
    }

    /**
     * Cleanup old records
     *
     * @param int $days Number of days to keep (default 90)
     * @return int Number of deleted records
     */
    public static function cleanup_old_records(int $days = 90): int {
        global $wpdb;

        $orders_table = $wpdb->prefix . self::ORDERS_TABLE;
        $items_table = $wpdb->prefix . self::ITEMS_TABLE;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Get IDs of orders to delete
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$orders_table} WHERE synced_at < %s",
            $cutoff_date
        ));

        if (empty($order_ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

        // Delete line items for these orders
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$items_table} WHERE remote_order_id IN ({$placeholders})",
            $order_ids
        ));

        // Delete orders
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$orders_table} WHERE synced_at < %s",
            $cutoff_date
        ));

        WC_Multi_Store_Logger::write("Cleaned up {$deleted} old remote orders older than {$days} days");

        return $deleted;
    }

    /**
     * Get statistics for remote orders
     *
     * @param array $args Filter arguments
     * @return array Statistics
     */
    public static function get_statistics(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::ORDERS_TABLE;

        $defaults = [
            'store_url' => null,
            'date_from' => null,
            'date_to' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        [$where_clause, $where_values] = self::build_where_clause($args);

        // Get statistics
        $query = "SELECT
            COUNT(*) as total_orders,
            SUM(total) as total_revenue,
            AVG(total) as average_order_value,
            COUNT(DISTINCT customer_email) as unique_customers,
            COUNT(DISTINCT remote_store_url) as store_count
            FROM {$table_name}
            WHERE {$where_clause}";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        $stats = $wpdb->get_row($query, ARRAY_A);

        // Get order count by status
        $query = "SELECT status, COUNT(*) as count
            FROM {$table_name}
            WHERE {$where_clause}
            GROUP BY status";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        $stats['by_status'] = $wpdb->get_results($query, ARRAY_A);

        return $stats;
    }
}
