<?php
/**
 * Webhook Logger
 * Detailed logging for webhook events with 90-day retention
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Webhook_Logger {

    use WC_Multi_Store_Csv_Sanitizer;
    use WC_Multi_Store_Archive_Before_Delete;

    /**
     * Database table name (without prefix)
     */
    const string TABLE_NAME = 'wc_mss_webhook_logs';

    /**
     * Default retention period in days
     */
    const int DEFAULT_RETENTION_DAYS = 90;

    /**
     * Create the database table
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_type varchar(50) NOT NULL,
            store_url varchar(255) DEFAULT NULL,
            remote_order_id bigint(20) UNSIGNED DEFAULT NULL,
            product_id bigint(20) UNSIGNED DEFAULT NULL,
            product_sku varchar(100) DEFAULT NULL,
            old_stock int(11) DEFAULT NULL,
            new_stock int(11) DEFAULT NULL,
            quantity_changed int(11) DEFAULT NULL,
            change_reason varchar(255) DEFAULT NULL,
            request_ip varchar(45) DEFAULT NULL,
            request_data longtext DEFAULT NULL,
            response_data longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'success',
            error_message text DEFAULT NULL,
            duration_ms int(11) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_log_type (log_type),
            KEY idx_store_url (store_url),
            KEY idx_product_sku (product_sku),
            KEY idx_created_at (created_at),
            KEY idx_status (status),
            KEY idx_remote_order_id (remote_order_id),
            KEY idx_log_type_created (log_type, created_at),
            KEY idx_status_created (status, created_at),
            KEY idx_store_created (store_url(191), created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        WC_Multi_Store_Logger::write('Webhook logs table created/verified');
    }

    /**
     * Log a webhook event
     *
     * @param string $log_type Type of log entry
     * @param array $data Log data
     * @return int|false Insert ID or false on failure
     */
    public static function log(string $log_type, array $data): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $insert_data = [
            'log_type' => $log_type,
            'store_url' => $data['store_url'] ?? null,
            'remote_order_id' => $data['remote_order_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'product_sku' => $data['product_sku'] ?? null,
            'old_stock' => $data['old_stock'] ?? null,
            'new_stock' => $data['new_stock'] ?? null,
            'quantity_changed' => $data['quantity_changed'] ?? null,
            'change_reason' => $data['change_reason'] ?? null,
            'request_ip' => $data['request_ip'] ?? self::get_client_ip(),
            'request_data' => isset($data['request_data']) ? wp_json_encode($data['request_data']) : null,
            'response_data' => isset($data['response_data']) ? wp_json_encode($data['response_data']) : null,
            'status' => $data['status'] ?? 'success',
            'error_message' => $data['error_message'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'created_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($table_name, $insert_data);

        if ($result === false) {
            WC_Multi_Store_Logger::write('Failed to insert webhook log: ' . $wpdb->last_error, 'error');
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Log incoming order webhook
     *
     * @param array $order_data Order data from webhook
     * @param string $store_url Remote store URL
     * @param string $request_ip Client IP
     * @return int|false
     */
    public static function log_order_received(array $order_data, string $store_url, string $request_ip): int|false {
        return self::log(WC_Multi_Store_Webhook_Log_Type::ORDER_RECEIVED->value, [
            'store_url' => $store_url,
            'remote_order_id' => $order_data['id'] ?? null,
            'request_ip' => $request_ip,
            'request_data' => [
                'order_id' => $order_data['id'] ?? null,
                'status' => $order_data['status'] ?? null,
                'total' => $order_data['total'] ?? null,
                'line_items_count' => isset($order_data['line_items']) ? count($order_data['line_items']) : 0,
                'line_items' => self::extract_line_items_summary($order_data['line_items'] ?? []),
            ],
            'change_reason' => sprintf(
                /* translators: 1: order ID, 2: store hostname, 3: order status */
                __('Order #%1$d received from %2$s with status "%3$s"', 'wc-multi-store-sync'),
                $order_data['id'] ?? 0,
                parse_url($store_url, PHP_URL_HOST),
                $order_data['status'] ?? 'unknown'
            ),
            'status' => 'success',
        ]);
    }

    /**
     * Log stock deduction
     *
     * @param int $product_id Local product ID
     * @param string $sku Product SKU
     * @param int $old_stock Stock before deduction
     * @param int $new_stock Stock after deduction
     * @param int $quantity Quantity deducted
     * @param int $remote_order_id Remote order ID
     * @param string $store_url Remote store URL
     * @return int|false
     */
    public static function log_stock_deducted(
        int $product_id,
        string $sku,
        int $old_stock,
        int $new_stock,
        int $quantity,
        int $remote_order_id,
        string $store_url
    ): int|false {
        return self::log(WC_Multi_Store_Webhook_Log_Type::STOCK_DEDUCTED->value, [
            'store_url' => $store_url,
            'remote_order_id' => $remote_order_id,
            'product_id' => $product_id,
            'product_sku' => $sku,
            'old_stock' => $old_stock,
            'new_stock' => $new_stock,
            'quantity_changed' => -$quantity,
            'change_reason' => sprintf(
                /* translators: 1: old stock, 2: new stock, 3: quantity, 4: order ID, 5: store hostname */
                __('Stock reduced from %1$d to %2$d (-%3$d units) due to order #%4$d from %5$s', 'wc-multi-store-sync'),
                $old_stock,
                $new_stock,
                $quantity,
                $remote_order_id,
                parse_url($store_url, PHP_URL_HOST)
            ),
            'status' => 'success',
        ]);
    }

    /**
     * Log stock sync to remote stores
     *
     * @param int $product_id Local product ID
     * @param string $sku Product SKU
     * @param int $stock_quantity Current stock
     * @param string $originating_store Store that triggered the sync
     * @return int|false
     */
    public static function log_stock_synced(
        int $product_id,
        string $sku,
        int $stock_quantity,
        string $originating_store
    ): int|false {
        return self::log(WC_Multi_Store_Webhook_Log_Type::STOCK_SYNCED->value, [
            'product_id' => $product_id,
            'product_sku' => $sku,
            'new_stock' => $stock_quantity,
            'change_reason' => sprintf(
                /* translators: 1: stock quantity, 2: store hostname */
                __('Stock (%1$d units) synced to all stores after order from %2$s', 'wc-multi-store-sync'),
                $stock_quantity,
                parse_url($originating_store, PHP_URL_HOST)
            ),
            'status' => 'success',
        ]);
    }

    /**
     * Log authentication failure
     *
     * @param string $reason Reason for failure
     * @param string $request_ip Client IP
     * @param array|null $request_data Request data
     * @return int|false
     */
    public static function log_auth_failed(string $reason, string $request_ip, ?array $request_data = null): int|false {
        return self::log(WC_Multi_Store_Webhook_Log_Type::AUTH_FAILED->value, [
            'request_ip' => $request_ip,
            'request_data' => $request_data,
            'status' => 'failed',
            'error_message' => $reason,
            'change_reason' => __('Authentication failed: ', 'wc-multi-store-sync') . $reason,
        ]);
    }

    /**
     * Log validation error
     *
     * @param string $error Error message
     * @param string|null $store_url Store URL if available
     * @param array|null $request_data Request data
     * @return int|false
     */
    public static function log_validation_error(string $error, ?string $store_url = null, ?array $request_data = null): int|false {
        return self::log(WC_Multi_Store_Webhook_Log_Type::VALIDATION_ERROR->value, [
            'store_url' => $store_url,
            'request_data' => $request_data,
            'status' => 'failed',
            'error_message' => $error,
            'change_reason' => __('Validation error: ', 'wc-multi-store-sync') . $error,
        ]);
    }

    /**
     * Log product not found
     *
     * @param string $sku Product SKU that wasn't found
     * @param int $remote_order_id Remote order ID
     * @param string $store_url Remote store URL
     * @return int|false
     */
    public static function log_product_not_found(string $sku, int $remote_order_id, string $store_url): int|false {
        return self::log(WC_Multi_Store_Webhook_Log_Type::PRODUCT_NOT_FOUND->value, [
            'store_url' => $store_url,
            'remote_order_id' => $remote_order_id,
            'product_sku' => $sku,
            'status' => 'failed',
            'error_message' => sprintf(__('Product with SKU "%s" not found in main store', 'wc-multi-store-sync'), $sku),
            'change_reason' => sprintf(
                /* translators: 1: product SKU, 2: order ID */
                __('Product SKU "%1$s" from order #%2$d not found', 'wc-multi-store-sync'),
                $sku,
                $remote_order_id
            ),
        ]);
    }

    /**
     * Log rate limit exceeded
     *
     * @param string $request_ip Client IP
     * @param int $request_count Number of requests
     * @return int|false
     */
    public static function log_rate_limited(string $request_ip, int $request_count): int|false {
        return self::log(WC_Multi_Store_Webhook_Log_Type::RATE_LIMITED->value, [
            'request_ip' => $request_ip,
            'status' => 'failed',
            'error_message' => "Rate limit exceeded: {$request_count} requests",
            'change_reason' => sprintf('Rate limit exceeded from IP %s (%d requests)', $request_ip, $request_count),
        ]);
    }

    /**
     * Extract summary of line items for logging
     *
     * @param array $line_items Order line items
     * @return array
     */
    private static function extract_line_items_summary(array $line_items): array {
        $summary = [];
        foreach ($line_items as $item) {
            $summary[] = [
                'sku' => $item['sku'] ?? 'N/A',
                'name' => $item['name'] ?? 'Unknown',
                'quantity' => $item['quantity'] ?? 0,
            ];
        }
        return $summary;
    }

    /**
     * Get client IP address.
     *
     * Only trusts CF-Connecting-IP/X-Forwarded-For/X-Real-IP proxy headers when
     * REMOTE_ADDR itself is in a configured trusted-proxy list — those headers
     * are otherwise attacker-controlled on any request that reaches this server
     * directly. Configure trusted proxies via the 'wc_mss_trusted_proxies'
     * filter or the WC_MSS_TRUSTED_PROXIES constant (comma-separated IPs).
     *
     * Canonical implementation shared with webhook-receiver.php, which
     * delegates its own get_client_ip() here.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $trusted_proxies = apply_filters('wc_mss_trusted_proxies', []);
        if (empty($trusted_proxies) && defined('WC_MSS_TRUSTED_PROXIES')) {
            $trusted_proxies = array_map('trim', explode(',', WC_MSS_TRUSTED_PROXIES));
        }

        // Only trust proxy headers if the request comes from a trusted proxy
        if (!empty($trusted_proxies) && in_array($remote_addr, $trusted_proxies, true)) {
            $proxy_headers = [
                'HTTP_CF_CONNECTING_IP', // Cloudflare
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
            ];

            foreach ($proxy_headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = $_SERVER[$header];
                    // Handle comma-separated IPs (X-Forwarded-For) — first is the client
                    if (str_contains($ip, ',')) {
                        $ip = trim(explode(',', $ip)[0]);
                    }
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        // Default: trust only REMOTE_ADDR (cannot be spoofed at HTTP level)
        if (filter_var($remote_addr, FILTER_VALIDATE_IP)) {
            return $remote_addr;
        }

        return '0.0.0.0';
    }

    /**
     * Get logs with filtering and pagination
     *
     * @param array $args Query arguments
     * @return array
     */
    public static function get_logs(array $args = []): array {
        global $wpdb;

        $defaults = [
            'per_page' => 50,
            'page' => 1,
            'log_type' => null,
            'store_url' => null,
            'product_sku' => null,
            'status' => null,
            'date_from' => null,
            'date_to' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Build WHERE clause
        $where = ['1=1'];
        $values = [];

        if (!empty($args['log_type'])) {
            $where[] = 'log_type = %s';
            $values[] = $args['log_type'];
        }

        if (!empty($args['store_url'])) {
            $where[] = 'store_url LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['store_url']) . '%';
        }

        if (!empty($args['product_sku'])) {
            $where[] = 'product_sku LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['product_sku']) . '%';
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
        if (!empty($values)) {
            $count_sql = $wpdb->prepare($count_sql, ...$values);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Get records
        $offset = ($args['page'] - 1) * $args['per_page'];
        $order_by = in_array($args['order_by'], ['created_at', 'log_type', 'store_url', 'product_sku'])
            ? $args['order_by']
            : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        $sql = $wpdb->prepare($sql, ...$values);
        $results = $wpdb->get_results($sql, ARRAY_A);

        // Decode JSON fields
        foreach ($results as &$row) {
            if (!empty($row['request_data'])) {
                $row['request_data'] = json_decode($row['request_data'], true);
            }
            if (!empty($row['response_data'])) {
                $row['response_data'] = json_decode($row['response_data'], true);
            }
        }

        return [
            'logs' => $results,
            'total' => $total,
            'pages' => ceil($total / $args['per_page']),
            'page' => $args['page'],
            'per_page' => $args['per_page'],
        ];
    }

    /**
     * Get statistics for dashboard
     *
     * @param int $days Number of days to look back
     * @return array
     */
    public static function get_stats(int $days = 30): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Total counts by type
        $type_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT log_type, COUNT(*) as count
             FROM {$table_name}
             WHERE created_at >= %s
             GROUP BY log_type",
            $date_limit
        ), ARRAY_A);

        // Status counts
        $status_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count
             FROM {$table_name}
             WHERE created_at >= %s
             GROUP BY status",
            $date_limit
        ), ARRAY_A);

        // Store activity
        $store_activity = $wpdb->get_results($wpdb->prepare(
            "SELECT store_url, COUNT(*) as count,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
             FROM {$table_name}
             WHERE created_at >= %s AND store_url IS NOT NULL
             GROUP BY store_url
             ORDER BY count DESC
             LIMIT 10",
            $date_limit
        ), ARRAY_A);

        // Daily activity (last 7 days)
        $daily_activity = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM {$table_name}
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY date DESC
             LIMIT 7",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ), ARRAY_A);

        // Total stock changes
        $stock_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_changes,
                SUM(ABS(COALESCE(quantity_changed, 0))) as total_quantity_changed,
                COUNT(DISTINCT product_sku) as unique_products
             FROM {$table_name}
             WHERE created_at >= %s AND log_type = %s",
            $date_limit,
            WC_Multi_Store_Webhook_Log_Type::STOCK_DEDUCTED->value
        ), ARRAY_A);

        return [
            'type_counts' => array_column($type_counts, 'count', 'log_type'),
            'status_counts' => array_column($status_counts, 'count', 'status'),
            'store_activity' => $store_activity,
            'daily_activity' => $daily_activity,
            'stock_stats' => $stock_stats,
            'period_days' => $days,
        ];
    }

    /**
     * Cleanup old logs (retention policy)
     *
     * @param int|null $days Days to retain (default: 90)
     * @return int Number of deleted records
     */
    public static function cleanup_old_logs(?int $days = null): int {
        global $wpdb;

        if ($days === null) {
            $settings = get_option('wc_multi_store_sync_webhook_settings', []);
            $days = (int) ($settings['webhook_log_retention_days'] ?? self::DEFAULT_RETENTION_DAYS);
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE created_at < %s ORDER BY created_at ASC",
            $cutoff_date
        ), ARRAY_A);

        if (!empty($records)) {
            self::archive_records_to_json('webhook-logs', $records, 'created_at');
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff_date
        ));

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Webhook logs cleanup: Deleted %d records older than %d days',
                $deleted,
                $days
            ));
        }

        return $deleted;
    }

    /**
     * Get log type label in Bulgarian
     *
     * @param string $type Log type
     * @return string
     */
    public static function get_type_label(string $type): string {
        return WC_Multi_Store_Webhook_Log_Type::tryFrom($type)?->label() ?? $type;
    }

    /**
     * Get status badge HTML
     *
     * @param string $status Status value
     * @return string
     */
    public static function get_status_badge(string $status): string {
        $badges = [
            'success' => '<span class="wc-mss-badge wc-mss-badge-success">' . esc_html__('Success', 'wc-multi-store-sync') . '</span>',
            'failed' => '<span class="wc-mss-badge wc-mss-badge-error">' . esc_html__('Failed', 'wc-multi-store-sync') . '</span>',
        ];

        return $badges[$status] ?? '<span class="wc-mss-badge">' . esc_html($status) . '</span>';
    }

    /**
     * Export logs to CSV
     *
     * @param array $args Query arguments
     * @return string CSV content
     */
    public static function export_csv(array $args = []): string {
        $args['per_page'] = 10000; // Max export
        $result = self::get_logs($args);

        $csv = [];
        $csv[] = [
            __('ID', 'wc-multi-store-sync'),
            __('Date/Time', 'wc-multi-store-sync'),
            __('Type', 'wc-multi-store-sync'),
            __('Store', 'wc-multi-store-sync'),
            __('Order #', 'wc-multi-store-sync'),
            __('Product ID', 'wc-multi-store-sync'),
            __('SKU', 'wc-multi-store-sync'),
            __('Old stock', 'wc-multi-store-sync'),
            __('New stock', 'wc-multi-store-sync'),
            __('Change', 'wc-multi-store-sync'),
            __('Reason', 'wc-multi-store-sync'),
            __('IP address', 'wc-multi-store-sync'),
            __('Status', 'wc-multi-store-sync'),
            __('Error', 'wc-multi-store-sync'),
        ];

        foreach ($result['logs'] as $log) {
            $csv[] = [
                $log['id'],
                $log['created_at'],
                self::get_type_label($log['log_type']),
                self::csv_cell_sanitize((string) ($log['store_url'] ?? '')),
                self::csv_cell_sanitize((string) ($log['remote_order_id'] ?? '')),
                $log['product_id'] ?? '',
                self::csv_cell_sanitize((string) ($log['product_sku'] ?? '')),
                $log['old_stock'] ?? '',
                $log['new_stock'] ?? '',
                $log['quantity_changed'] ?? '',
                self::csv_cell_sanitize((string) ($log['change_reason'] ?? '')),
                self::csv_cell_sanitize((string) ($log['request_ip'] ?? '')),
                $log['status'],
                self::csv_cell_sanitize((string) ($log['error_message'] ?? '')),
            ];
        }

        $output = fopen('php://temp', 'r+');
        try {
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
            foreach ($csv as $row) {
                fputcsv($output, $row, ',', '"', '\\');
            }
            rewind($output);
            $content = stream_get_contents($output);
        } finally {
            fclose($output);
        }

        return $content;
    }

    /**
     * Get single log entry with full details
     *
     * @param int $log_id Log ID
     * @return array|null
     */
    public static function get_log(int $log_id): ?array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $log_id
        ), ARRAY_A);

        if ($log) {
            if (!empty($log['request_data'])) {
                $log['request_data'] = json_decode($log['request_data'], true);
            }
            if (!empty($log['response_data'])) {
                $log['response_data'] = json_decode($log['response_data'], true);
            }
        }

        return $log;
    }

    /**
     * Delete all logs
     *
     * @return int Number of deleted records
     */
    public static function delete_all(): int {
        global $wpdb;

        $table_name = esc_sql($wpdb->prefix . self::TABLE_NAME);

        $deleted = $wpdb->query("TRUNCATE TABLE {$table_name}");

        WC_Multi_Store_Logger::write('All webhook logs deleted');

        return $deleted !== false ? 1 : 0;
    }

    /**
     * Delete logs by status
     *
     * @param string $status Status to delete ('success' or 'failed')
     * @return int Number of deleted records
     */
    public static function delete_by_status(string $status): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE status = %s",
            $status
        ));

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf('Deleted %d webhook logs with status: %s', $deleted, $status));
        }

        return $deleted;
    }

    /**
     * Delete logs older than specified days
     *
     * @param int $days Days threshold
     * @return int Number of deleted records
     */
    public static function delete_older_than(int $days): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff_date
        ));

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf('Deleted %d webhook logs older than %d days', $deleted, $days));
        }

        return $deleted;
    }

    /**
     * Delete logs by type
     *
     * @param string $log_type Log type to delete
     * @return int Number of deleted records
     */
    public static function delete_by_type(string $log_type): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE log_type = %s",
            $log_type
        ));

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf('Deleted %d webhook logs of type: %s', $deleted, $log_type));
        }

        return $deleted;
    }

    /**
     * Get total count of logs
     *
     * @return int
     */
    public static function get_count(): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }
}
