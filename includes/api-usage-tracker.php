<?php
/**
 * WooCommerce Multi-Store API Usage Tracker
 *
 * Tracks API calls, usage statistics, and generates reports
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Usage Tracker Class
 */
class WC_Multi_Store_API_Usage_Tracker {

    use WC_Multi_Store_Csv_Sanitizer;
    use WC_Multi_Store_Archive_Before_Delete;

    /**
     * Database table name
     *
     * @var string
     */
    private const string TABLE_NAME = 'wc_mss_api_usage';

    /**
     * Buffer of rows pending insertion. Flushed at request shutdown via a single
     * multi-row INSERT to avoid one DB write per API call.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $pending_inserts = [];

    /**
     * True once register_shutdown_function has been registered for the current process.
     *
     * @var bool
     */
    private static bool $shutdown_registered = false;

    /**
     * Hard cap on rows returned by export_to_csv() — matches
     * webhook-logger.php::export_csv()'s own export cap.
     */
    const int EXPORT_MAX_ROWS = 10000;

    /**
     * Initialize API usage tracking
     */
    public function __construct() {
        // Hook into API client calls
        add_action('wc_mss_api_request', $this->log_api_request(...), 10, 4);
    }

    /**
     * Create API usage table
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            store_url varchar(255) NOT NULL,
            endpoint varchar(255) NOT NULL,
            method varchar(10) NOT NULL,
            status_code int(11) DEFAULT NULL,
            response_time int(11) DEFAULT NULL,
            request_size int(11) DEFAULT NULL,
            response_size int(11) DEFAULT NULL,
            success tinyint(1) DEFAULT 1,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY store_url (store_url),
            KEY endpoint (endpoint),
            KEY method (method),
            KEY created_at (created_at),
            KEY success (success),
            KEY store_created (store_url(191), created_at),
            KEY success_created (success, created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log an API request.
     *
     * Buffers the row in memory and flushes the whole buffer as a single multi-row
     * INSERT at request shutdown. Saves N-1 round trips when many API calls happen
     * in one request (queue batches, weekly verification).
     *
     * @param string $store_url Store URL
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $result Result data
     */
    public function log_api_request(string $store_url, string $endpoint, string $method, array $result): void {
        self::$pending_inserts[] = [
            'store_url'     => $store_url,
            'endpoint'      => $endpoint,
            'method'        => strtoupper($method),
            'status_code'   => $result['status_code'] ?? null,
            'response_time' => $result['response_time'] ?? null,
            'request_size'  => $result['request_size'] ?? null,
            'response_size' => $result['response_size'] ?? null,
            'success'       => isset($result['success']) ? ($result['success'] ? 1 : 0) : 1,
            'error_message' => $result['error_message'] ?? null,
            'created_at'    => current_time('mysql'),
        ];

        if (!self::$shutdown_registered) {
            register_shutdown_function([self::class, 'flush_pending_inserts']);
            self::$shutdown_registered = true;
        }
    }

    /**
     * Flush all buffered API-usage rows as a single multi-row INSERT.
     *
     * Public so tests can drive the flush deterministically; also invoked by
     * register_shutdown_function. Safe to call when the buffer is empty.
     */
    public static function flush_pending_inserts(): void {
        if (empty(self::$pending_inserts)) {
            return;
        }

        $rows = self::$pending_inserts;
        self::$pending_inserts = [];

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        // [column, %s|%d format] — keep NULL semantics by emitting the SQL literal NULL
        // when a value is null instead of letting prepare() coerce it to 0 / ''.
        $columns = [
            'store_url'     => '%s',
            'endpoint'      => '%s',
            'method'        => '%s',
            'status_code'   => '%d',
            'response_time' => '%d',
            'request_size'  => '%d',
            'response_size' => '%d',
            'success'       => '%d',
            'error_message' => '%s',
            'created_at'    => '%s',
        ];

        $placeholder_groups = [];
        $values = [];
        foreach ($rows as $row) {
            $row_placeholders = [];
            foreach ($columns as $col => $format) {
                $val = $row[$col] ?? null;
                if ($val === null) {
                    $row_placeholders[] = 'NULL';
                } else {
                    $row_placeholders[] = $format;
                    $values[] = $val;
                }
            }
            $placeholder_groups[] = '(' . implode(', ', $row_placeholders) . ')';
        }

        $column_list = implode(', ', array_keys($columns));
        $sql = "INSERT INTO {$table_name} ({$column_list}) VALUES " . implode(', ', $placeholder_groups);
        $wpdb->query($wpdb->prepare($sql, $values));
    }

    /**
     * Reset the pending-insert buffer. Test-only helper.
     */
    public static function reset_buffer_for_testing(): void {
        self::$pending_inserts = [];
        self::$shutdown_registered = false;
    }

    /**
     * Get API usage statistics
     *
     * @param array $args Query arguments
     * @return array Usage statistics
     */
    public static function get_statistics(array $args = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'store_url' => null,
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Build WHERE clause
        $where = [];
        $where[] = $wpdb->prepare('DATE(created_at) >= %s', $args['start_date']);
        $where[] = $wpdb->prepare('DATE(created_at) <= %s', $args['end_date']);

        if ($args['store_url']) {
            $where[] = $wpdb->prepare('store_url = %s', $args['store_url']);
        }

        $where_clause = implode(' AND ', $where);

        // Single aggregation query instead of 6 separate queries
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total_requests,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successful_requests,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed_requests,
                AVG(CASE WHEN response_time IS NOT NULL THEN response_time ELSE NULL END) AS avg_response_time,
                COALESCE(SUM(request_size), 0) AS total_data_sent,
                COALESCE(SUM(response_size), 0) AS total_data_received
            FROM {$table_name}
            WHERE {$where_clause}"
        );

        if ($stats === null) {
            return [
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
                'success_rate' => 0,
                'avg_response_time' => 0,
                'total_data_sent' => 0,
                'total_data_received' => 0,
                'total_data_transferred' => 0,
            ];
        }

        $total = intval($stats->total_requests ?? 0);
        $successful = intval($stats->successful_requests ?? 0);
        $data_sent = intval($stats->total_data_sent ?? 0);
        $data_received = intval($stats->total_data_received ?? 0);

        return [
            'total_requests' => $total,
            'successful_requests' => $successful,
            'failed_requests' => intval($stats->failed_requests ?? 0),
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0,
            'avg_response_time' => $stats->avg_response_time ? round($stats->avg_response_time) : 0,
            'total_data_sent' => $data_sent,
            'total_data_received' => $data_received,
            'total_data_transferred' => $data_sent + $data_received,
        ];
    }

    /**
     * Get usage by store
     *
     * @param array $args Query arguments
     * @return array Usage by store
     */
    public static function get_usage_by_store(array $args = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $sql = $wpdb->prepare(
            "SELECT
                store_url,
                COUNT(*) as total_requests,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_requests,
                AVG(response_time) as avg_response_time,
                SUM(request_size) as total_data_sent,
                SUM(response_size) as total_data_received
            FROM {$table_name}
            WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s
            GROUP BY store_url
            ORDER BY total_requests DESC",
            $args['start_date'],
            $args['end_date']
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Calculate success rate for each store
        foreach ($results as &$result) {
            $result['success_rate'] = $result['total_requests'] > 0
                ? round(($result['successful_requests'] / $result['total_requests']) * 100, 2)
                : 0;
            $result['avg_response_time'] = $result['avg_response_time'] ? round($result['avg_response_time']) : 0;
        }

        return $results;
    }

    /**
     * Get usage by endpoint
     *
     * @param array $args Query arguments
     * @return array Usage by endpoint
     */
    public static function get_usage_by_endpoint(array $args = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'store_url' => null,
            'limit' => 10,
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Build WHERE clause
        $where = [];
        $where[] = $wpdb->prepare('DATE(created_at) >= %s', $args['start_date']);
        $where[] = $wpdb->prepare('DATE(created_at) <= %s', $args['end_date']);

        if ($args['store_url']) {
            $where[] = $wpdb->prepare('store_url = %s', $args['store_url']);
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                endpoint,
                method,
                COUNT(*) as total_requests,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_requests,
                AVG(response_time) as avg_response_time
            FROM {$table_name}
            WHERE {$where_clause}
            GROUP BY endpoint, method
            ORDER BY total_requests DESC
            LIMIT " . intval($args['limit']);

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get daily usage trend
     *
     * @param array $args Query arguments
     * @return array Daily usage data
     */
    public static function get_daily_trend(array $args = []): array {
        global $wpdb;

        $defaults = [
            'days' => 30,
            'store_url' => null,
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Build WHERE clause
        $where = [];
        $where[] = $wpdb->prepare('DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL %d DAY)', $args['days']);

        if ($args['store_url']) {
            $where[] = $wpdb->prepare('store_url = %s', $args['store_url']);
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                DATE(created_at) as date,
                COUNT(*) as total_requests,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_requests,
                AVG(response_time) as avg_response_time
            FROM {$table_name}
            WHERE {$where_clause}
            GROUP BY DATE(created_at)
            ORDER BY date ASC";

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get recent errors
     *
     * @param int $limit Number of errors to retrieve
     * @return array Recent errors
     */
    public static function get_recent_errors(int $limit = 10): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $sql = $wpdb->prepare(
            "SELECT *
            FROM {$table_name}
            WHERE success = 0 AND error_message IS NOT NULL
            ORDER BY created_at DESC
            LIMIT %d",
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get estimated API costs (configurable rates)
     *
     * @param array $args Query arguments
     * @return array Cost estimates
     */
    public static function get_cost_estimates(array $args = []): array {
        $stats = self::get_statistics($args);

        // Default cost assumptions (can be configured)
        $cost_per_1000_requests = get_option('wc_mss_api_cost_per_1000', 0);
        $cost_per_gb_transferred = get_option('wc_mss_api_cost_per_gb', 0);

        $total_gb_transferred = $stats['total_data_transferred'] / 1024 / 1024 / 1024;

        return [
            'request_cost' => ($stats['total_requests'] / 1000) * $cost_per_1000_requests,
            'data_transfer_cost' => $total_gb_transferred * $cost_per_gb_transferred,
            'total_estimated_cost' => (($stats['total_requests'] / 1000) * $cost_per_1000_requests) + ($total_gb_transferred * $cost_per_gb_transferred),
            'cost_per_1000_requests' => $cost_per_1000_requests,
            'cost_per_gb_transferred' => $cost_per_gb_transferred,
        ];
    }

    /**
     * Export usage data to CSV
     *
     * @param array $args Query arguments
     * @return string CSV data
     */
    public static function export_to_csv(array $args = []): string {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Every outbound API call gets logged to this table, so an unbounded
        // export on a busy multi-store install can be hundreds of thousands
        // of rows. Cap it the same way webhook-logger.php::export_csv() caps
        // its own export, instead of loading the whole date range into memory.
        $sql = $wpdb->prepare(
            "SELECT *
            FROM {$table_name}
            WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s
            ORDER BY created_at DESC
            LIMIT %d",
            $args['start_date'],
            $args['end_date'],
            self::EXPORT_MAX_ROWS
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Build rows as arrays and let fputcsv() below handle quoting/escaping
        // (RFC 4180) instead of manual string concatenation — matches the
        // pattern already used by webhook-logger.php::export_csv().
        $csv = [];
        $csv[] = ['ID', 'Store URL', 'Endpoint', 'Method', 'Status Code', 'Response Time (ms)', 'Success', 'Error Message', 'Created At'];

        foreach ($results as $row) {
            $csv[] = [
                $row['id'],
                self::csv_cell_sanitize($row['store_url']),
                self::csv_cell_sanitize($row['endpoint']),
                self::csv_cell_sanitize($row['method']),
                $row['status_code'] ?: '',
                $row['response_time'] ?: '',
                $row['success'] ? 'Yes' : 'No',
                self::csv_cell_sanitize($row['error_message'] ?: ''),
                $row['created_at'],
            ];
        }

        $output = fopen('php://temp', 'r+');
        try {
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
     * Clean up old usage data
     *
     * @param int $days Days to keep
     * @return int Number of rows deleted
     */
    public static function cleanup_old_data(int $days = 90): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
            ORDER BY created_at ASC",
            $days
        ), ARRAY_A);

        if (!empty($records)) {
            self::archive_records_to_json('api-usage', $records, 'created_at');
        }

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Clear all API usage data
     *
     * @return int|false Number of rows deleted or false on error
     */
    public static function clear_all_data(): int|false {
        global $wpdb;

        $table_name = esc_sql($wpdb->prefix . self::TABLE_NAME);

        return $wpdb->query("TRUNCATE TABLE {$table_name}");
    }
}
