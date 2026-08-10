<?php
/**
 * Stock Verifier
 * Verifies stock levels on remote stores and tracks discrepancies
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Stock_Verifier {

    /**
     * Verify stock on a remote store matches expected value
     *
     * @param int $product_id Main store product ID
     * @param string $store_url Remote store URL
     * @param int $expected_stock Expected stock quantity
     * @return array Verification result
     */
    public static function verify_product_stock(int $product_id, string $store_url, int $expected_stock): array {
        global $wpdb;

        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'success' => false,
                'error' => 'Product not found',
            ];
        }

        $sku = $product->get_sku();
        if (empty($sku)) {
            return [
                'success' => false,
                'error' => 'Product has no SKU',
            ];
        }

        // Get store configuration — stores are keyed by URL in the option
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $store_config = null;
        $matched_url = null;

        foreach ($stores as $url => $config) {
            if (untrailingslashit($url) === untrailingslashit($store_url)) {
                $store_config = $config;
                $matched_url = $url;
                break;
            }
        }

        if (!$store_config) {
            return [
                'success' => false,
                'error' => 'Store not found in configuration',
            ];
        }

        // Skip products excluded from this store via category/tag rules
        if (
            class_exists('WC_Multi_Store_Product_Exclusion_Filter') &&
            WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config)
        ) {
            return [
                'success' => true,
                'product_id' => $product_id,
                'sku' => $sku,
                'store_url' => $store_url,
                'skipped' => true,
                'reason' => 'excluded',
            ];
        }

        // Create API client
        $api = WC_Multi_Store_API_Client::for_store($matched_url, $store_config);

        // Find remote product
        try {
            $match_by = get_option('wc_multi_store_sync_settings')['match_products_by'] ?? 'sku';
            $search_value = ($match_by === 'sku') ? $sku : $product->get_slug();

            // Prefer the stable local↔remote ID link over search-by-value —
            // SKU/slug can legitimately drift after the last sync (rename,
            // permalink edit), which would otherwise misreport this product
            // as missing here.
            $stored_id = WC_Multi_Store_Remote_Product_Manager::get_stored_remote_id($product_id, $matched_url);
            if ($stored_id !== null) {
                $by_id = $api->get_product($stored_id);
                if (!is_wp_error($by_id) && !empty($by_id)) {
                    $remote_products = [$by_id];
                } else {
                    // Stored ID no longer resolves (deleted independently on
                    // the remote store) — clear it and fall through to
                    // search-by-value below.
                    WC_Multi_Store_Remote_Product_Manager::clear_stored_remote_id($product_id, $matched_url);
                    $remote_products = $api->get_products($search_value, $match_by);
                }
            } else {
                $remote_products = $api->get_products($search_value, $match_by);
            }

            if (is_wp_error($remote_products)) {
                return [
                    'success' => false,
                    'error' => 'API Error: ' . $remote_products->get_error_message(),
                ];
            }

            if (empty($remote_products) || !isset($remote_products[0])) {
                return [
                    'success' => false,
                    'error' => 'Product not found on remote store',
                ];
            }

            $remote_product = is_array($remote_products[0]) ? (object) $remote_products[0] : $remote_products[0];
            $actual_stock = isset($remote_product->stock_quantity) ? intval($remote_product->stock_quantity) : null;

            // Check for discrepancy
            $has_discrepancy = ($actual_stock !== $expected_stock);

            $result = [
                'success' => true,
                'product_id' => $product_id,
                'sku' => $sku,
                'store_url' => $store_url,
                'expected_stock' => $expected_stock,
                'actual_stock' => $actual_stock,
                'has_discrepancy' => $has_discrepancy,
                'difference' => $has_discrepancy ? ($actual_stock - $expected_stock) : 0,
                'verified_at' => current_time('mysql'),
            ];

            // Log discrepancy if found
            if ($has_discrepancy) {
                self::log_discrepancy($result);

                WC_Multi_Store_Logger::write(sprintf(
                    'Stock discrepancy detected: Product #%d (SKU: %s) on %s - Expected: %d, Actual: %d, Difference: %+d',
                    $product_id,
                    $sku,
                    $store_url,
                    $expected_stock,
                    $actual_stock,
                    $actual_stock - $expected_stock
                ), 'warning');
            }

            return $result;

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'API Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Log stock discrepancy to database
     *
     * @param array $discrepancy Discrepancy data
     * @return int|false Insert ID or false on failure
     */
    private static function log_discrepancy(array $discrepancy): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';

        return $wpdb->insert(
            $table_name,
            [
                'product_id' => $discrepancy['product_id'],
                'sku' => $discrepancy['sku'],
                'store_url' => $discrepancy['store_url'],
                'expected_stock' => $discrepancy['expected_stock'],
                'actual_stock' => $discrepancy['actual_stock'],
                'difference' => $discrepancy['difference'],
                'detected_at' => $discrepancy['verified_at'],
                'status' => 'pending',
            ],
            ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
        );
    }

    /**
     * Get all pending discrepancies
     *
     * @param array $args Query arguments
     * @return array Discrepancies
     */
    public static function get_discrepancies(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';

        $defaults = [
            'status' => 'pending',
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'detected_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $where_values = [];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if (!empty($args['store_url'])) {
            $where[] = 'store_url = %s';
            $where_values[] = $args['store_url'];
        }

        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $where_values[] = $args['product_id'];
        }

        $where_clause = implode(' AND ', $where);

        if (!empty($where_values)) {
            $where_clause = $wpdb->prepare($where_clause, $where_values);
        }

        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        $limit = absint($args['limit']);
        $offset = absint($args['offset']);

        $query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT {$limit} OFFSET {$offset}";

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Get count of pending discrepancies
     *
     * @param string $status Status filter (default: 'pending')
     * @return int Count
     */
    public static function get_discrepancy_count(string $status = 'pending'): int {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';

        if ($status === 'all') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
            $status
        ));
    }

    /**
     * Mark discrepancy as resolved
     *
     * @param int $discrepancy_id Discrepancy ID
     * @return bool Success
     */
    public static function mark_resolved(int $discrepancy_id): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';

        return $wpdb->update(
            $table_name,
            [
                'status' => 'resolved',
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => $discrepancy_id],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Mark discrepancy as ignored
     *
     * @param int $discrepancy_id Discrepancy ID
     * @return bool Success
     */
    public static function mark_ignored(int $discrepancy_id): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';

        return $wpdb->update(
            $table_name,
            ['status' => 'ignored'],
            ['id' => $discrepancy_id],
            ['%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Delete old resolved/ignored discrepancies
     *
     * @param int $days Delete records older than X days (default: 30)
     * @return int|false Number of rows deleted or false on failure
     */
    public static function cleanup_old_discrepancies(int $days = 30): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE status IN ('resolved', 'ignored') AND detected_at < %s",
            $date_threshold
        ));
    }

    /**
     * Auto-correct a stock discrepancy by syncing from main store
     *
     * @param int $discrepancy_id Discrepancy ID
     * @return bool|WP_Error Success or error
     */
    public static function auto_correct(int $discrepancy_id): bool|WP_Error {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';

        // Get discrepancy record
        $discrepancy = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $discrepancy_id
        ), ARRAY_A);

        if (!$discrepancy) {
            return new WP_Error('not_found', 'Discrepancy not found');
        }

        $product = wc_get_product($discrepancy['product_id']);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found');
        }

        // Queue product for immediate sync
        WC_MSS()->queue_manager->add_product(
            $discrepancy['product_id'],
            1, // Highest priority
            'discrepancy_correction',
            [
                'discrepancy_id' => $discrepancy_id,
                'store_url' => $discrepancy['store_url'],
            ]
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Auto-correction queued for discrepancy #%d: Product #%d (SKU: %s) to %s',
            $discrepancy_id,
            $discrepancy['product_id'],
            $discrepancy['sku'],
            $discrepancy['store_url']
        ));

        // Mark as resolving
        $wpdb->update(
            $table_name,
            ['status' => 'resolving'],
            ['id' => $discrepancy_id],
            ['%s'],
            ['%d']
        );

        return true;
    }

    /**
     * Create database table for stock discrepancies
     *
     * @return void
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_multi_store_stock_discrepancies';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id bigint(20) UNSIGNED NOT NULL,
            sku varchar(100) NOT NULL,
            store_url varchar(255) NOT NULL,
            expected_stock int(11) NOT NULL,
            actual_stock int(11) NOT NULL,
            difference int(11) NOT NULL,
            detected_at datetime NOT NULL,
            resolved_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY store_url (store_url),
            KEY status (status),
            KEY detected_at (detected_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        WC_Multi_Store_Logger::write('Stock discrepancies table created/updated');
    }

    /**
     * Schedule verification for a product after sync
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param int $expected_stock Expected stock level
     * @return void
     */
    public static function schedule_verification(int $product_id, string $store_url, int $expected_stock): void {
        // Schedule verification for 30 seconds in the future to allow sync to complete
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return;
        }

        as_schedule_single_action(
            time() + 30,
            'wc_multi_store_verify_stock',
            [$product_id, $store_url, $expected_stock],
            WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
        );

        WC_Multi_Store_Logger::write(sprintf(
            'Stock verification scheduled: Product #%d on %s (expected: %d)',
            $product_id,
            $store_url,
            $expected_stock
        ));
    }
}
