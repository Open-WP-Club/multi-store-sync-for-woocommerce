<?php
/**
 * Weekly Verification Report Repository
 * Persistence for weekly sync-verification reports — extracted from
 * WC_Multi_Store_Weekly_Sync_Verifier, which delegates to this class.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Weekly_Verification_Report_Repository {

    /**
     * Database table name for verification reports
     */
    const string TABLE_NAME = 'wc_multi_store_weekly_verifications';

    /**
     * Create/upgrade the verification reports table
     *
     * @return void
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            started_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            duration_seconds decimal(10,2) DEFAULT 0,
            products_checked int(11) NOT NULL DEFAULT 0,
            stores_checked int(11) NOT NULL DEFAULT 0,
            discrepancies_found int(11) NOT NULL DEFAULT 0,
            missing_products int(11) NOT NULL DEFAULT 0,
            stock_mismatches int(11) NOT NULL DEFAULT 0,
            price_mismatches int(11) NOT NULL DEFAULT 0,
            category_mismatches int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'running',
            report_data longtext,
            PRIMARY KEY  (id),
            KEY started_at (started_at),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        WC_Multi_Store_Logger::write('Weekly verification reports table created/updated');
    }

    /**
     * Save verification report to database
     *
     * @param array $report Report data
     * @return int|false Report ID or false on failure
     */
    public static function save_report(array $report): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->insert(
            $table_name,
            [
                'started_at' => $report['started_at'],
                'completed_at' => $report['completed_at'],
                'duration_seconds' => $report['duration_seconds'],
                'products_checked' => $report['products_checked'],
                'stores_checked' => $report['stores_checked'],
                'discrepancies_found' => $report['discrepancies_found'],
                'missing_products' => $report['missing_products'],
                'stock_mismatches' => $report['stock_mismatches'],
                'price_mismatches' => $report['price_mismatches'],
                'category_mismatches' => $report['category_mismatches'] ?? 0,
                'status' => $report['status'],
                'report_data' => maybe_serialize($report),
            ],
            ['%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s']
        );
    }

    /**
     * Get verification reports
     *
     * @param array $args Query arguments
     * @return array Reports
     */
    public static function get_reports(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $defaults = [
            'limit' => 10,
            'offset' => 0,
            'orderby' => 'started_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $allowed_columns = ['id', 'started_at', 'completed_at', 'duration_seconds', 'products_checked', 'stores_checked', 'discrepancies_found', 'status'];
        if (!in_array($args['orderby'], $allowed_columns, true)) {
            $args['orderby'] = 'started_at';
        }
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'started_at DESC';
        }
        $limit = absint($args['limit']);
        $offset = absint($args['offset']);

        $query = "SELECT * FROM {$table_name} ORDER BY {$orderby} LIMIT {$limit} OFFSET {$offset}";

        $results = $wpdb->get_results($query, ARRAY_A);

        // Unserialize report data
        foreach ($results as &$result) {
            if (isset($result['report_data'])) {
                $result['report_data'] = maybe_unserialize($result['report_data']);
            }
        }

        return $results;
    }

    /**
     * Get a single report by ID
     *
     * @param int $report_id Report ID
     * @return array|null Report data
     */
    public static function get_report(int $report_id): ?array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $report = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $report_id
        ), ARRAY_A);

        if ($report && isset($report['report_data'])) {
            $report['report_data'] = maybe_unserialize($report['report_data']);
        }

        return $report;
    }

    /**
     * Get latest report
     *
     * @return array|null Latest report
     */
    public static function get_latest_report(): ?array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists to prevent database errors
        if (!self::table_exists()) {
            return null;
        }

        $report = $wpdb->get_row(
            "SELECT * FROM {$table_name} ORDER BY started_at DESC LIMIT 1",
            ARRAY_A
        );

        if ($report && isset($report['report_data'])) {
            $report['report_data'] = maybe_unserialize($report['report_data']);
        }

        return $report;
    }

    /**
     * Check if the verification reports table exists
     *
     * @return bool True if table exists
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * Delete old reports
     *
     * @param int $days Keep reports from last X days (default: 90)
     * @return int|false Number of rows deleted or false on failure
     */
    public static function cleanup_old_reports(int $days = 90): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE started_at < %s",
            $date_threshold
        ));
    }

    /**
     * Extract orphan-product entries from a verification report.
     *
     * @param array|null $report Report data; defaults to the latest report when null
     * @param bool $require_remote_id Skip orphans without a remote_product_id
     * @return array List of orphan product entries
     */
    public static function get_orphan_products_from_report(?array $report = null, bool $require_remote_id = false): array {
        if ($report === null) {
            $latest = self::get_latest_report();
            if (!$latest || empty($latest['report_data'])) {
                return [];
            }
            $report = is_array($latest['report_data']) ? $latest['report_data'] : maybe_unserialize($latest['report_data']);
        }

        if (empty($report['details'])) {
            return [];
        }

        $orphans = [];

        foreach ($report['details'] as $product_report) {
            if (empty($product_report['discrepancies'])) {
                continue;
            }

            foreach ($product_report['discrepancies'] as $discrepancy) {
                if ($discrepancy['type'] !== 'orphan') {
                    continue;
                }

                // Skip orphans without remote_product_id if required
                if ($require_remote_id && empty($discrepancy['remote_product_id'])) {
                    continue;
                }

                $orphans[] = [
                    'product_id' => $product_report['product_id'],
                    'sku' => $product_report['sku'],
                    'name' => $product_report['name'],
                    'store_url' => $discrepancy['store_url'],
                    'store_name' => $discrepancy['store_name'] ?? '',
                    'remote_product_id' => $discrepancy['remote_product_id'] ?? null,
                    'exclusion_reasons' => $discrepancy['exclusion_reasons'] ?? [],
                ];
            }
        }

        return $orphans;
    }
}
