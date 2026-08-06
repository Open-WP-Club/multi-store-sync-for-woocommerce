<?php
/**
 * Dead Letter Queue
 *
 * Manages permanently failed sync items that have exhausted all retry attempts.
 * Provides visibility into failures and allows manual retry or dismissal.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Dead_Letter_Queue {

    /**
     * Table name (without prefix)
     */
    const string TABLE_NAME = 'wc_mss_dead_letter_queue';

    /**
     * Number of dead items that triggers an admin notification
     */
    const int NOTIFICATION_THRESHOLD = 10;

    /**
     * Minimum seconds between repeated notifications (1 hour)
     */
    const int NOTIFICATION_COOLDOWN = 3600;

    /**
     * Option key for tracking last notification timestamp
     */
    const string NOTIFICATION_OPTION = 'wc_mss_dlq_last_notification';

    /**
     * Create database table
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            product_sku varchar(100) DEFAULT NULL,
            store_url varchar(255) NOT NULL,
            sync_type varchar(50) NOT NULL DEFAULT 'full_product',
            source varchar(100) NOT NULL DEFAULT 'unknown',
            attempts int unsigned NOT NULL DEFAULT 0,
            last_error text DEFAULT NULL,
            extra_data text DEFAULT NULL,
            original_queue_id bigint(20) unsigned DEFAULT NULL,
            first_failed_at datetime DEFAULT NULL,
            failed_at datetime NOT NULL,
            retried_at datetime DEFAULT NULL,
            resolved_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'dead',
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_product_id (product_id),
            KEY idx_store_url (store_url),
            KEY idx_failed_at (failed_at),
            KEY idx_sync_type (sync_type)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        WC_Multi_Store_Logger::write('Dead letter queue table created/upgraded');
    }

    /**
     * Move a failed queue item to the dead letter queue
     *
     * @param array $queue_item The failed queue item data
     * @return int|false Insert ID or false on failure
     */
    public static function add_from_queue(array $queue_item): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $product_id = (int) ($queue_item['product_id'] ?? 0);
        $store_url  = $queue_item['store_url'] ?? '';
        $sync_type  = $queue_item['sync_type'] ?? 'unknown';

        $result = $wpdb->insert(
            $table_name,
            [
                'product_id' => $product_id,
                'product_sku' => $queue_item['product_sku'] ?? null,
                'store_url' => $store_url,
                'sync_type' => $sync_type,
                'source' => $queue_item['source'] ?? 'unknown',
                'attempts' => (int) ($queue_item['attempts'] ?? 0),
                'last_error' => $queue_item['last_error'] ?? null,
                'extra_data' => $queue_item['extra_data'] ?? null,
                'original_queue_id' => (int) ($queue_item['id'] ?? 0),
                'first_failed_at' => $queue_item['started_at'] ?? current_time('mysql'),
                'failed_at' => current_time('mysql'),
                'status' => 'dead',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if ($result) {
            WC_Multi_Store_Logger::write(sprintf(
                'Dead letter queue: Added item for product %d (SKU: %s) to store %s - Error: %s',
                $product_id,
                $queue_item['product_sku'] ?? 'N/A',
                $store_url,
                mb_substr($queue_item['last_error'] ?? 'Unknown', 0, 200)
            ));

            // Fire action for email notifications
            do_action('wc_mss_dead_letter_added', $queue_item);

            self::maybe_notify_admin_threshold();

            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Send admin notification if dead item count exceeds threshold
     * Respects a cooldown period to avoid notification spam
     */
    private static function maybe_notify_admin_threshold(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $total_dead = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'dead'");

        if ($total_dead < self::NOTIFICATION_THRESHOLD) {
            return;
        }

        $last_notified = (int) get_option(self::NOTIFICATION_OPTION, 0);
        if (time() - $last_notified < self::NOTIFICATION_COOLDOWN) {
            return;
        }

        update_option(self::NOTIFICATION_OPTION, time());

        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        $subject = sprintf('[%s] Dead Letter Queue: %d failed sync items', $site_name, $total_dead);
        $message = sprintf(
            "The WooCommerce Multi-Store Sync dead letter queue has %d failed items.\n\nPlease review them at: %s\n\nThis notification will not repeat for at least 1 hour.",
            $total_dead,
            admin_url('admin.php?page=wc-multi-store-sync&tab=dead-letter-queue')
        );

        wp_mail($admin_email, $subject, $message);
        set_transient('wc_mss_dlq_admin_notice', $total_dead, DAY_IN_SECONDS);

        WC_Multi_Store_Logger::write(sprintf(
            'Dead letter queue: Admin notification sent — %d dead items (threshold: %d)',
            $total_dead,
            self::NOTIFICATION_THRESHOLD
        ));
    }

    /**
     * Get dead letter items with pagination
     *
     * @param array $args Query arguments
     * @return array Results with 'results' and 'total' keys
     */
    public static function get_items(array $args = []): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'status' => 'dead',
            'product_id' => null,
            'store_url' => null,
            'sync_type' => null,
            'orderby' => 'failed_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];

        if ($args['status']) {
            $where[] = $wpdb->prepare('status = %s', $args['status']);
        }

        if ($args['product_id']) {
            $where[] = $wpdb->prepare('product_id = %d', $args['product_id']);
        }

        if ($args['store_url']) {
            $store_url_clean = rtrim(preg_replace('#^https?://#', '', $args['store_url']), '/');
            $where[] = $wpdb->prepare('store_url LIKE %s', '%' . $wpdb->esc_like($store_url_clean) . '%');
        }

        if ($args['sync_type']) {
            $where[] = $wpdb->prepare('sync_type = %s', $args['sync_type']);
        }

        $where_clause = implode(' AND ', $where);

        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'failed_at DESC';
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            ),
            ARRAY_A
        );

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}");

        return [
            'results' => $results ?: [],
            'total' => $total,
        ];
    }

    /**
     * Retry a specific dead letter item by re-queuing it
     *
     * @param int $id Dead letter item ID
     * @return bool Success
     */
    public static function retry_item(int $id): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d AND status = 'dead'", $id),
            ARRAY_A
        );

        if (!$item) {
            return false;
        }

        // Re-add to the main queue
        $extra_data = !empty($item['extra_data']) ? json_decode($item['extra_data'], true) : null;

        $queue_result = WC_Multi_Store_Queue_Table::add(
            (int) $item['product_id'],
            $item['store_url'],
            $item['sync_type'],
            WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL,
            'dlq_retry',
            null,
            $item['product_sku'],
            $extra_data
        );

        if ($queue_result) {
            // Mark as retried in DLQ
            $wpdb->update(
                $table_name,
                [
                    'status' => 'retried',
                    'retried_at' => current_time('mysql'),
                ],
                ['id' => $id],
                ['%s', '%s'],
                ['%d']
            );

            // Remove the original failed queue item so it doesn't linger as 'failed'
            if (!empty($item['original_queue_id'])) {
                $queue_table = $wpdb->prefix . WC_Multi_Store_Queue_Table::TABLE_NAME;
                $wpdb->delete(
                    $queue_table,
                    ['id' => (int) $item['original_queue_id'], 'status' => 'failed'],
                    ['%d', '%s']
                );
            }

            WC_Multi_Store_Logger::write(sprintf(
                'Dead letter queue: Item %d re-queued for product %d (SKU: %s)',
                $id,
                $item['product_id'],
                $item['product_sku'] ?? 'N/A'
            ));

            return true;
        }

        return false;
    }

    /**
     * Retry all dead letter items
     *
     * @return int Number of items retried
     */
    public static function retry_all(): int {
        $items = self::get_items(['limit' => 1000, 'status' => 'dead']);
        $retried = 0;

        foreach ($items['results'] as $item) {
            if (self::retry_item((int) $item['id'])) {
                $retried++;
            }
        }

        return $retried;
    }

    /**
     * Dismiss/resolve a dead letter item
     *
     * @param int $id Item ID
     * @return bool Success
     */
    public static function resolve_item(int $id): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->update(
            $table_name,
            [
                'status' => 'resolved',
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return (bool) $result;
    }

    /**
     * Clear all dead letter items
     *
     * @return int Number of items deleted
     */
    public static function clear_all(): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $deleted = $wpdb->query("DELETE FROM {$table_name} WHERE status = 'dead'");

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf('Dead letter queue: Cleared %d item(s)', $deleted));
        }

        return (int) $deleted;
    }

    /**
     * Get statistics
     *
     * @return array Stats
     */
    public static function get_stats(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return [
            'total_dead' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'dead'"),
            'total_retried' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'retried'"),
            'total_resolved' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'resolved'"),
            'by_store' => $wpdb->get_results(
                "SELECT store_url, COUNT(*) as count FROM {$table_name} WHERE status = 'dead' GROUP BY store_url ORDER BY count DESC",
                ARRAY_A
            ) ?: [],
            'by_error' => $wpdb->get_results(
                "SELECT LEFT(last_error, 100) as error_summary, COUNT(*) as count FROM {$table_name} WHERE status = 'dead' GROUP BY error_summary ORDER BY count DESC LIMIT 10",
                ARRAY_A
            ) ?: [],
            'oldest_item' => $wpdb->get_var("SELECT MIN(failed_at) FROM {$table_name} WHERE status = 'dead'"),
            'newest_item' => $wpdb->get_var("SELECT MAX(failed_at) FROM {$table_name} WHERE status = 'dead'"),
        ];
    }

    /**
     * Cleanup old resolved/retried items
     *
     * @param int $days Keep items from last N days
     * @return int Number of items deleted
     */
    public static function cleanup(int $days = 30): int {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE status IN ('resolved', 'retried') AND failed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        return (int) $deleted;
    }

    /**
     * AJAX handler for listing dead letter items
     */
    public static function ajax_get_items(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $args = [
            'limit' => (int) ($_POST['limit'] ?? 50),
            'offset' => (int) ($_POST['offset'] ?? 0),
            'status' => sanitize_text_field($_POST['status'] ?? 'dead'),
        ];

        $items = self::get_items($args);
        $stats = self::get_stats();

        wp_send_json_success([
            'items' => $items['results'],
            'total' => $items['total'],
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX handler for retrying a dead letter item
     */
    public static function ajax_retry_item(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $id = (int) ($_POST['item_id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid item ID.', 'wc-multi-store-sync')]);
            return;
        }

        $result = self::retry_item($id);

        if ($result) {
            wp_send_json_success(['message' => __('Item re-queued for processing.', 'wc-multi-store-sync')]);
        } else {
            wp_send_json_error(['message' => __('Failed to retry item.', 'wc-multi-store-sync')]);
        }
    }

    /**
     * AJAX handler for retrying all dead letter items
     */
    public static function ajax_retry_all(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $count = self::retry_all();

        wp_send_json_success([
            'message' => sprintf(__('%d item(s) re-queued for processing.', 'wc-multi-store-sync'), $count),
            'count' => $count,
        ]);
    }

    /**
     * AJAX handler for resolving a dead letter item
     */
    public static function ajax_resolve_item(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $id = (int) ($_POST['item_id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid item ID.', 'wc-multi-store-sync')]);
            return;
        }

        $result = self::resolve_item($id);

        if ($result) {
            wp_send_json_success(['message' => __('Item marked as resolved.', 'wc-multi-store-sync')]);
        } else {
            wp_send_json_error(['message' => __('Failed to resolve item.', 'wc-multi-store-sync')]);
        }
    }

    /**
     * AJAX handler for clearing dead letter queue
     */
    public static function ajax_clear_all(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $cleared = self::clear_all();

        wp_send_json_success([
            'message' => sprintf(__('Cleared %d item(s) from dead letter queue.', 'wc-multi-store-sync'), $cleared),
            'count' => $cleared,
        ]);
    }
}
