<?php
/**
 * Queue Table Manager
 * Manages database table for sync queue
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Queue_Table {

    /**
     * Table name (without prefix)
     */
    const string TABLE_NAME = 'wc_mss_queue';

    /**
     * Database version
     */
    const string DB_VERSION = '1.2';

    /**
     * Create queue table
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            product_sku varchar(100) DEFAULT NULL,
            store_url varchar(255) NOT NULL,
            sync_type varchar(50) NOT NULL DEFAULT 'full_product',
            priority tinyint(3) unsigned NOT NULL DEFAULT 5,
            source varchar(100) NOT NULL DEFAULT 'manual',
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            last_error text DEFAULT NULL,
            extra_data text DEFAULT NULL,
            created_at datetime NOT NULL,
            scheduled_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status_priority (status, priority, created_at),
            KEY idx_product_id (product_id),
            KEY idx_store_url (store_url),
            KEY idx_scheduled_at (scheduled_at),
            KEY idx_status (status),
            KEY idx_queue_processing (status, scheduled_at, attempts, priority, created_at),
            KEY idx_store_status (store_url(191), status)
        ) {$charset_collate}";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Store DB version
        update_option('wc_mss_queue_db_version', self::DB_VERSION);

        WC_Multi_Store_Logger::write('Queue table created/upgraded successfully');
    }

    /**
     * Add item to queue
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param string $sync_type Sync type
     * @param int $priority Priority (1-10, lower = higher priority)
     * @param string $source Source of sync
     * @param datetime|null $scheduled_at When to run (null = immediate)
     * @param string|null $product_sku Product SKU (stored for deletion operations)
     * @param array|null $extra_data Additional data for special operations (will be JSON encoded)
     * @return int|false Insert ID or false on failure
     */
    public static function add($product_id, $store_url, $sync_type = 'full_product', $priority = 5, $source = 'manual', $scheduled_at = null, $product_sku = null, $extra_data = null): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $lock_name = 'wc_mss_enqueue_' . md5((string) $product_id . '|' . $store_url);
        $lock_acquired = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock_name)
        ) === 1;

        if (!$lock_acquired) {
            WC_Multi_Store_Logger::write('Queue add skipped: could not acquire enqueue lock.', 'warning');
            return false;
        }

        try {

        // Check if already queued (pending or processing)
        // Include 'processing' to prevent duplicates when items are stuck processing
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table_name} WHERE product_id = %d AND store_url = %s AND status IN ('pending', 'processing')",
            $product_id,
            $store_url
        ), ARRAY_A);

        if ($existing) {
            $existing_id = $existing['id'];
            $existing_status = $existing['status'];

            // If item is currently processing, insert a new queue item instead of
            // silently returning. This prevents data loss when a new operation
            // (e.g., status change) arrives while a previous sync is in progress.
            if ($existing_status === 'processing') {
                $result = $wpdb->insert(
                    $table_name,
                    [
                        'product_id' => $product_id,
                        'product_sku' => $product_sku,
                        'store_url' => $store_url,
                        'sync_type' => $sync_type,
                        'priority' => $priority,
                        'source' => $source,
                        'status' => 'pending',
                        'attempts' => 0,
                        'extra_data' => $extra_data !== null ? wp_json_encode($extra_data) : null,
                        'created_at' => current_time('mysql'),
                        'scheduled_at' => $scheduled_at,
                    ],
                    ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
                );

                return $result ? $wpdb->insert_id : false;
            }

            // Update priority if new priority is higher (lower number)
            // Also update SKU and extra_data if provided (for deletion operations)
            $update_data = [];
            $update_format = [];

            if ($priority < 5) {
                $update_data['priority'] = $priority;
                $update_data['source'] = $source;
                $update_format[] = '%d';
                $update_format[] = '%s';
            }

            // Always update SKU and extra_data if provided
            if ($product_sku !== null) {
                $update_data['product_sku'] = $product_sku;
                $update_format[] = '%s';
            }
            if ($extra_data !== null) {
                $update_data['extra_data'] = wp_json_encode($extra_data);
                $update_format[] = '%s';
            }

            if (!empty($update_data)) {
                $wpdb->update(
                    $table_name,
                    $update_data,
                    ['id' => $existing_id],
                    $update_format,
                    ['%d']
                );
            }
            return $existing_id;
        }

        // Insert new queue item
        $result = $wpdb->insert(
            $table_name,
            [
                'product_id' => $product_id,
                'product_sku' => $product_sku,
                'store_url' => $store_url,
                'sync_type' => $sync_type,
                'priority' => $priority,
                'source' => $source,
                'status' => 'pending',
                'attempts' => 0,
                'extra_data' => $extra_data !== null ? wp_json_encode($extra_data) : null,
                'created_at' => current_time('mysql'),
                'scheduled_at' => $scheduled_at,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return $result ? $wpdb->insert_id : false;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * Get next batch of items to process
     *
     * @param int $limit Number of items to get
     * @return array Queue items
     */
    public static function get_next_batch($limit = 10): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $current_time = current_time('mysql');

        // Get items that are:
        // - status = pending
        // - scheduled_at is null or in the past
        // - attempts < 3
        // Order by priority (ASC), then store_url (ASC) for HTTP keep-alive optimization,
        // then created_at (ASC)
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
            WHERE status = 'pending'
            AND (scheduled_at IS NULL OR scheduled_at <= %s)
            AND attempts < 3
            ORDER BY priority ASC, store_url ASC, created_at ASC
            LIMIT %d",
            $current_time,
            $limit
        ), ARRAY_A);

        return $items;
    }

    /**
     * Mark item as processing
     *
     * @param int $id Queue item ID
     * @return bool Success
     */
    public static function mark_processing($id): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->update(
            $table_name,
            [
                'status' => 'processing',
                'started_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Mark item as completed
     *
     * @param int $id Queue item ID
     * @return bool Success
     */
    public static function mark_completed($id): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->update(
            $table_name,
            [
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Mark item as failed
     *
     * @param int $id Queue item ID
     * @param string $error Error message
     * @param bool $no_retry If true, skip retries and fail immediately
     * @return bool Success
     */
    public static function mark_failed($id, $error = '', $no_retry = false): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Increment attempts
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET attempts = attempts + 1 WHERE id = %d",
            $id
        ));

        // Get current attempts
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT attempts FROM {$table_name} WHERE id = %d",
            $id
        ));

        // If no_retry flag is set or max attempts reached, mark as failed permanently
        if ($no_retry || $attempts >= 3) {
            // Move to dead letter queue before marking as failed
            $item = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
                ARRAY_A
            );
            if ($item) {
                $item['last_error'] = $error;
                WC_Multi_Store_Dead_Letter_Queue::add_from_queue($item);
            }

            return $wpdb->update(
                $table_name,
                [
                    'status' => 'failed',
                    'last_error' => $error,
                    'completed_at' => current_time('mysql'),
                ],
                ['id' => $id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Retry - keep as pending, schedule for later
            $retry_delay = pow(2, $attempts) * 5; // Exponential backoff: 10s, 20s, 40s
            $scheduled_at = (new DateTimeImmutable())->add(new DateInterval("PT{$retry_delay}M"))->format('Y-m-d H:i:s');

            return $wpdb->update(
                $table_name,
                [
                    'status' => 'pending',
                    'last_error' => $error,
                    'scheduled_at' => $scheduled_at,
                ],
                ['id' => $id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        }
    }

    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public static function get_stats(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $row = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
            FROM {$table_name}",
            ARRAY_A
        );

        return [
            'pending'    => (int) ($row['pending'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'completed'  => (int) ($row['completed'] ?? 0),
            'failed'     => (int) ($row['failed'] ?? 0),
            'total'      => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * Get recent queue items for display
     *
     * @param int $limit Number of items to get
     * @param string $status Filter by status (all, pending, processing, completed, failed)
     * @return array Queue items with product info
     */
    public static function get_recent_items(int $limit = 50, string $status = 'all'): array {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $limit = max(1, $limit);

        // Note: never splice an already-prepare()'d fragment into a second
        // prepare() call — a literal "%" in the escaped value would desync the
        // placeholders in the outer query. Build one prepared statement per branch instead.
        $order_sql = "ORDER BY
                CASE status
                    WHEN 'processing' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'failed' THEN 3
                    WHEN 'completed' THEN 4
                END,
                priority ASC,
                created_at DESC
            LIMIT %d";

        if ($status !== 'all') {
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE status = %s {$order_sql}",
                    $status,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $items = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table_name} {$order_sql}", $limit),
                ARRAY_A
            );
        }

        // Batch-prime post + meta caches so per-row wc_get_product() hits cache instead of N+1 SQL
        if (!empty($items) && function_exists('_prime_post_caches')) {
            $product_ids = array_unique(array_map(static fn($item) => (int) $item['product_id'], $items));
            _prime_post_caches($product_ids, false, true);
        }

        foreach ($items as &$item) {
            $product = wc_get_product($item['product_id']);

            if (empty($item['product_sku'])) {
                $item['product_sku'] = $product ? $product->get_sku() : 'N/A';
            }

            $item['product_name'] = $product ? $product->get_name() : 'Deleted';
        }

        return $items;
    }

    /**
     * Clear old completed/failed items
     *
     * @param int $days Keep items from last X days
     * @return int Number of rows deleted
     */
    public static function cleanup($days = 7): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $cutoff_date = (new DateTimeImmutable())->sub(new DateInterval('P' . (int) $days . 'D'))->format('Y-m-d H:i:s');

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name}
            WHERE status IN ('completed', 'failed')
            AND completed_at < %s",
            $cutoff_date
        ));

        WC_Multi_Store_Logger::write(sprintf('Queue cleanup: removed %d old items', $deleted));

        return $deleted;
    }

    /**
     * Clear all queue items
     *
     * @return int Number of rows deleted
     */
    public static function clear_all(): int|false {
        global $wpdb;

        $table_name = esc_sql($wpdb->prefix . self::TABLE_NAME);
        return $wpdb->query("TRUNCATE TABLE {$table_name}");
    }

    /**
     * Reset items stuck in "processing" status
     * Items stuck for more than X minutes are reset to pending
     *
     * @param int $minutes Minutes threshold (default 10)
     * @return int Number of items reset
     */
    public static function reset_stuck_items($minutes = 10): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $cutoff_time = (new DateTimeImmutable())->sub(new DateInterval('PT' . (int) $minutes . 'M'))->format('Y-m-d H:i:s');

        $reset_count = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name}
            SET status = 'pending', started_at = NULL
            WHERE status = 'processing'
            AND started_at < %s",
            $cutoff_time
        ));

        if ($reset_count > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Reset %d stuck items (processing for more than %d minutes)',
                $reset_count,
                $minutes
            ));
        }

        return $reset_count;
    }

    /**
     * Retry a single failed queue item by resetting it to pending.
     * Also marks any corresponding DLQ entry as retried.
     *
     * @param int $id Queue item ID
     * @return bool Success
     */
    public static function retry_item(int $id): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d AND status = 'failed'", $id),
            ARRAY_A
        );

        if (!$item) {
            return false;
        }

        $result = $wpdb->update(
            $table_name,
            [
                'status'       => 'pending',
                'attempts'     => 0,
                'last_error'   => null,
                'started_at'   => null,
                'completed_at' => null,
                'scheduled_at' => null,
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            return false;
        }

        // Mark corresponding DLQ entry as retried so it doesn't accumulate duplicates
        if (class_exists('WC_Multi_Store_Dead_Letter_Queue')) {
            $dlq_table = $wpdb->prefix . WC_Multi_Store_Dead_Letter_Queue::TABLE_NAME;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$dlq_table}
                 SET status = 'retried', retried_at = %s
                 WHERE original_queue_id = %d AND status = 'dead'",
                current_time('mysql'),
                $id
            ));
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Queue item %d (product %d, store %s) manually reset to pending for retry',
            $id,
            $item['product_id'],
            $item['store_url']
        ));

        return true;
    }

    /**
     * Retry all failed items
     * Resets failed items back to pending for re-processing
     *
     * @return int Number of items reset
     */
    public static function retry_failed_items(): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $reset_count = $wpdb->query(
            "UPDATE {$table_name}
            SET status = 'pending',
                attempts = 0,
                last_error = NULL,
                started_at = NULL,
                completed_at = NULL,
                scheduled_at = NULL
            WHERE status = 'failed'"
        );

        if ($reset_count > 0) {
            // Also mark corresponding DLQ entries as retried
            if (class_exists('WC_Multi_Store_Dead_Letter_Queue')) {
                $dlq_table = $wpdb->prefix . WC_Multi_Store_Dead_Letter_Queue::TABLE_NAME;
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$dlq_table} SET status = 'retried', retried_at = %s WHERE status = 'dead'",
                    current_time('mysql')
                ));
            }

            WC_Multi_Store_Logger::write(sprintf(
                'Retry failed: %d items reset to pending for re-processing',
                $reset_count
            ));
        }

        return $reset_count;
    }

    /**
     * Clear all failed items from queue
     *
     * @return int Number of items deleted
     */
    public static function clear_failed(): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $deleted = $wpdb->query(
            "DELETE FROM {$table_name} WHERE status = 'failed'"
        );

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf('Cleared %d failed queue items', $deleted));
        }

        return $deleted;
    }

    /**
     * Clear all completed items from queue
     *
     * @return int Number of items deleted
     */
    public static function clear_completed(): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $deleted = $wpdb->query(
            "DELETE FROM {$table_name} WHERE status = 'completed'"
        );

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf('Cleared %d completed queue items', $deleted));
        }

        return $deleted;
    }

    /**
     * Clear all pending items from queue
     *
     * @return int Number of items deleted
     */
    public static function clear_pending(): int|false {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $deleted = $wpdb->query(
            "DELETE FROM {$table_name} WHERE status = 'pending'"
        );

        if ($deleted > 0) {
            WC_Multi_Store_Logger::write(sprintf('Cleared %d pending queue items', $deleted));
        }

        return $deleted;
    }
}
