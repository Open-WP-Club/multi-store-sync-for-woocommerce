<?php
/**
 * Conflict Detector
 * Detects changes made manually on remote stores before overwriting them
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Conflict_Detector {
    use WC_Multi_Store_Ajax_Auth_Guard;

    /**
     * DB table names (without prefix)
     */
    const HASHES_TABLE = 'wc_mss_conflict_hashes';
    const LOG_TABLE    = 'wc_mss_conflict_log';

    /**
     * Legacy option keys (kept for migration only)
     */
    const LEGACY_HASH_OPTION_KEY = 'wc_mss_remote_product_hashes';
    const LEGACY_CONFLICT_LOG_KEY = 'wc_mss_conflict_log';

    /**
     * Fields to compare for conflict detection
     */
    const TRACKED_FIELDS = [
        'name',
        'description',
        'short_description',
        'regular_price',
        'sale_price',
        'sku',
        'stock_quantity',
        'stock_status',
        'status',
        'weight',
        'categories',
    ];

    /**
     * Create database tables for hashes and conflict log
     */
    public static function create_table(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $hashes_table = $wpdb->prefix . self::HASHES_TABLE;
        $sql_hashes = "CREATE TABLE IF NOT EXISTS {$hashes_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            local_product_id bigint(20) unsigned NOT NULL,
            store_url_hash varchar(32) NOT NULL,
            store_url varchar(500) NOT NULL,
            hash varchar(32) NOT NULL,
            snapshot longtext DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_product_store (local_product_id, store_url_hash),
            KEY idx_local_product_id (local_product_id)
        ) {$charset_collate};";

        $log_table = $wpdb->prefix . self::LOG_TABLE;
        $sql_log = "CREATE TABLE IF NOT EXISTS {$log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            local_product_id bigint(20) unsigned NOT NULL,
            remote_product_id bigint(20) unsigned NOT NULL,
            store_url varchar(500) NOT NULL,
            changed_fields text NOT NULL,
            detected_at datetime NOT NULL,
            resolved tinyint(1) NOT NULL DEFAULT 0,
            resolution varchar(50) DEFAULT NULL,
            resolved_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_resolved (resolved),
            KEY idx_store_url (store_url(191)),
            KEY idx_detected_at (detected_at),
            KEY idx_local_product_id (local_product_id),
            KEY idx_resolved_detected (resolved, detected_at),
            KEY idx_product_resolved (local_product_id, resolved)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_hashes);
        dbDelta($sql_log);

        self::maybe_migrate_from_options();

        WC_Multi_Store_Logger::write('Conflict detector tables created/upgraded');
    }

    /**
     * One-time migration from wp_options to dedicated tables
     */
    public static function maybe_migrate_from_options(): void {
        if (get_option('wc_mss_conflict_db_migrated')) {
            return;
        }

        global $wpdb;

        // Migrate hashes
        $old_hashes = get_option(self::LEGACY_HASH_OPTION_KEY, []);
        if (!empty($old_hashes)) {
            $hashes_table = $wpdb->prefix . self::HASHES_TABLE;
            foreach ($old_hashes as $key => $entry) {
                // Key format: "{product_id}_{md5(store_url)}"
                $parts = explode('_', $key, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $local_product_id = (int) $parts[0];
                $store_url_hash   = $parts[1];

                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$hashes_table}
                        (local_product_id, store_url_hash, store_url, hash, snapshot, updated_at)
                     VALUES (%d, %s, %s, %s, %s, %s)",
                    $local_product_id,
                    $store_url_hash,
                    '',
                    $entry['hash'] ?? '',
                    wp_json_encode($entry['snapshot'] ?? null),
                    isset($entry['timestamp'])
                        ? gmdate('Y-m-d H:i:s', $entry['timestamp'])
                        : current_time('mysql', true)
                ));
            }
            delete_option(self::LEGACY_HASH_OPTION_KEY);
        }

        // Migrate conflict log
        $old_log = get_option(self::LEGACY_CONFLICT_LOG_KEY, []);
        if (!empty($old_log)) {
            $log_table = $wpdb->prefix . self::LOG_TABLE;
            foreach (array_reverse($old_log) as $entry) {
                $wpdb->insert($log_table, [
                    'local_product_id'  => (int) ($entry['local_product_id'] ?? 0),
                    'remote_product_id' => (int) ($entry['remote_product_id'] ?? 0),
                    'store_url'         => $entry['store_url'] ?? '',
                    'changed_fields'    => wp_json_encode($entry['changed_fields'] ?? []),
                    'detected_at'       => $entry['detected_at'] ?? current_time('mysql'),
                    'resolved'          => (int) ($entry['resolved'] ?? 0),
                    'resolution'        => $entry['resolution'] ?? null,
                    'resolved_at'       => $entry['resolved_at'] ?? null,
                ], ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']);
            }
            delete_option(self::LEGACY_CONFLICT_LOG_KEY);
        }

        update_option('wc_mss_conflict_db_migrated', true, false);
        WC_Multi_Store_Logger::write('Conflict detector: migrated from wp_options to DB tables');
    }

    /**
     * Check for conflicts before syncing a product to a remote store
     *
     * @param WC_Multi_Store_API_Client $client API client
     * @param int $remote_product_id Remote product ID
     * @param int $local_product_id Local product ID
     * @param string $store_url Remote store URL
     * @return array{has_conflict: bool, changed_fields: array, remote_data: array|null}
     */
    public static function check_for_conflicts(
        WC_Multi_Store_API_Client $client,
        int $remote_product_id,
        int $local_product_id,
        string $store_url
    ): array {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return ['has_conflict' => false, 'changed_fields' => [], 'remote_data' => null];
        }

        $remote_product = $client->get_product($remote_product_id);
        if (is_wp_error($remote_product)) {
            return ['has_conflict' => false, 'changed_fields' => [], 'remote_data' => null];
        }

        $last_known_hash = self::get_stored_hash($local_product_id, $store_url);

        if (!$last_known_hash) {
            self::store_hash($local_product_id, $store_url, $remote_product);
            return ['has_conflict' => false, 'changed_fields' => [], 'remote_data' => $remote_product];
        }

        $current_hash = self::calculate_hash($remote_product);

        if ($current_hash === $last_known_hash) {
            return ['has_conflict' => false, 'changed_fields' => [], 'remote_data' => $remote_product];
        }

        $changed_fields = self::identify_changed_fields($remote_product, $local_product_id, $store_url);

        self::log_conflict($local_product_id, $remote_product_id, $store_url, $changed_fields);

        WC_Multi_Store_Email_Notifications::trigger_conflict_detected($local_product_id, $remote_product_id, $store_url, $changed_fields);

        WC_Multi_Store_Logger::write(sprintf(
            'CONFLICT DETECTED: Product #%d on %s was modified remotely. Changed fields: %s',
            $local_product_id,
            $store_url,
            implode(', ', $changed_fields)
        ), 'warning');

        return [
            'has_conflict'   => true,
            'changed_fields' => $changed_fields,
            'remote_data'    => $remote_product,
        ];
    }

    /**
     * Store the hash of a remote product after a successful sync
     */
    public static function store_hash(int $local_product_id, string $store_url, array $remote_product): void {
        global $wpdb;

        $table = $wpdb->prefix . self::HASHES_TABLE;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (local_product_id, store_url_hash, store_url, hash, snapshot, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                store_url  = VALUES(store_url),
                hash       = VALUES(hash),
                snapshot   = VALUES(snapshot),
                updated_at = VALUES(updated_at)",
            $local_product_id,
            md5($store_url),
            $store_url,
            self::calculate_hash($remote_product),
            wp_json_encode(self::extract_tracked_fields($remote_product)),
            current_time('mysql', true)
        ));
    }

    /**
     * Delete the stored hash for a product/store (e.g. after product deletion)
     */
    public static function delete_hash(int $local_product_id, string $store_url): void {
        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . self::HASHES_TABLE,
            ['local_product_id' => $local_product_id, 'store_url_hash' => md5($store_url)],
            ['%d', '%s']
        );
    }

    /**
     * Get stored hash for a product/store combination
     */
    private static function get_stored_hash(int $local_product_id, string $store_url): ?string {
        global $wpdb;

        $table = $wpdb->prefix . self::HASHES_TABLE;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT hash FROM {$table} WHERE local_product_id = %d AND store_url_hash = %s LIMIT 1",
            $local_product_id,
            md5($store_url)
        )) ?: null;
    }

    /**
     * Get stored snapshot for a product/store combination
     */
    private static function get_stored_snapshot(int $local_product_id, string $store_url): ?array {
        global $wpdb;

        $table = $wpdb->prefix . self::HASHES_TABLE;

        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT snapshot FROM {$table} WHERE local_product_id = %d AND store_url_hash = %s LIMIT 1",
            $local_product_id,
            md5($store_url)
        ));

        if (!$json) {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Calculate a hash of the tracked fields in a remote product
     */
    private static function calculate_hash(array $remote_product): string {
        return md5(wp_json_encode(self::extract_tracked_fields($remote_product)));
    }

    /**
     * Extract only the tracked fields from remote product data
     */
    private static function extract_tracked_fields(array $product): array {
        $fields = [];
        foreach (self::TRACKED_FIELDS as $field) {
            $fields[$field] = $product[$field] ?? null;
        }
        return $fields;
    }

    /**
     * Identify which fields changed on the remote product
     */
    private static function identify_changed_fields(array $remote_product, int $local_product_id, string $store_url): array {
        $snapshot = self::get_stored_snapshot($local_product_id, $store_url);
        if (!$snapshot) {
            return ['unknown'];
        }

        $current = self::extract_tracked_fields($remote_product);
        $changed = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if (wp_json_encode($snapshot[$field] ?? null) !== wp_json_encode($current[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Log a conflict for admin review
     */
    private static function log_conflict(int $local_product_id, int $remote_product_id, string $store_url, array $changed_fields): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::LOG_TABLE,
            [
                'local_product_id'  => $local_product_id,
                'remote_product_id' => $remote_product_id,
                'store_url'         => $store_url,
                'changed_fields'    => wp_json_encode($changed_fields),
                'detected_at'       => current_time('mysql'),
                'resolved'          => 0,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d']
        );
    }

    /**
     * Get all conflicts with optional filters
     *
     * @param string $store_url  Filter by store URL (empty = all)
     * @param int    $limit      Max rows to return
     * @param int    $offset     Row offset for pagination
     * @param bool   $include_resolved  Include resolved conflicts (default true)
     */
    public static function get_conflicts(string $store_url = '', int $limit = 50, int $offset = 0, bool $include_resolved = true): array {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE;
        $where = [];
        $args  = [];

        if (!$include_resolved) {
            $where[] = 'resolved = 0';
        }
        if ($store_url !== '') {
            $where[] = 'store_url = %s';
            $args[]  = $store_url;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $args[]    = $limit;
        $args[]    = $offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where_sql} ORDER BY detected_at DESC LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        );

        return array_map(static function (array $row): array {
            $row['changed_fields'] = json_decode($row['changed_fields'], true) ?? [];
            $row['resolved']       = (bool) $row['resolved'];
            return $row;
        }, $rows ?: []);
    }

    /**
     * Resolve a conflict by its row ID
     *
     * @param int    $id         Conflict log row ID
     * @param string $resolution 'overwrite' | 'keep_remote' | 'merge'
     */
    public static function resolve_conflict(int $id, string $resolution): bool {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . self::LOG_TABLE,
            [
                'resolved'    => 1,
                'resolution'  => $resolution,
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => $id, 'resolved' => 0],
            ['%d', '%s', '%s'],
            ['%d', '%d']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * Resolve all unresolved conflicts, optionally filtered by store
     */
    public static function resolve_all(string $store_url = '', string $resolution = 'overwrite'): int {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE;
        $now   = current_time('mysql');

        if ($store_url !== '') {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET resolved = 1, resolution = %s, resolved_at = %s
                 WHERE resolved = 0 AND store_url = %s",
                $resolution,
                $now,
                $store_url
            ));
        } else {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET resolved = 1, resolution = %s, resolved_at = %s
                 WHERE resolved = 0",
                $resolution,
                $now
            ));
        }

        return (int) $updated;
    }

    /**
     * Clear the entire conflict log
     */
    public static function clear_log(): void {
        global $wpdb;
        $table_name = esc_sql($wpdb->prefix . self::LOG_TABLE);
        $wpdb->query("TRUNCATE TABLE {$table_name}");
    }

    /**
     * Get conflict detection settings
     */
    public static function get_settings(): array {
        return wp_parse_args(get_option('wc_mss_conflict_settings', []), [
            'enabled' => false,
            'action_on_conflict' => 'warn',
            'notify_email' => true,
        ]);
    }

    /**
     * Update conflict detection settings
     */
    public static function update_settings(array $settings): void {
        $current = self::get_settings();
        update_option('wc_mss_conflict_settings', array_merge($current, $settings));
    }

    /**
     * Get conflict statistics
     */
    public static function get_stats(): array {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE;

        $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $unresolved = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE resolved = 0");
        $stores     = (int) $wpdb->get_var("SELECT COUNT(DISTINCT store_url) FROM {$table}");

        return [
            'total'            => $total,
            'unresolved'       => $unresolved,
            'resolved'         => $total - $unresolved,
            'stores_affected'  => $stores,
        ];
    }

    /**
     * AJAX handler: Get conflicts
     */
    public static function ajax_get_conflicts(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $store_url        = sanitize_text_field($_GET['store_url'] ?? '');
        $limit             = absint($_GET['limit'] ?? 50);
        $offset            = absint($_GET['offset'] ?? 0);
        $include_resolved = sanitize_text_field($_GET['status'] ?? 'unresolved') !== 'unresolved';

        $conflicts = self::get_conflicts($store_url, $limit, $offset, $include_resolved);

        // Enrich with product display data — get_conflicts() stays a thin DB
        // read (and keeps its existing test coverage) since this is purely
        // a presentation concern of the admin table.
        foreach ($conflicts as &$conflict) {
            $product = wc_get_product($conflict['local_product_id']);
            $conflict['product_name'] = $product ? $product->get_name() : __('(Product not found)', 'wc-multi-store-sync');
            $conflict['product_sku']  = $product ? $product->get_sku() : '';
            $conflict['edit_url']     = $product ? get_edit_post_link($conflict['local_product_id']) : '';
        }
        unset($conflict);

        wp_send_json_success([
            'conflicts' => $conflicts,
            'stats'     => self::get_stats(),
        ]);
    }

    /**
     * AJAX handler: Resolve a conflict
     */
    public static function ajax_resolve_conflict(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $id         = absint($_POST['id'] ?? 0);
        $resolution = sanitize_text_field($_POST['resolution'] ?? 'overwrite');

        if (!in_array($resolution, ['overwrite', 'keep_remote', 'merge'], true)) {
            wp_send_json_error(['message' => __('Invalid resolution type', 'wc-multi-store-sync')]);
            return;
        }

        if ($id > 0 && self::resolve_conflict($id, $resolution)) {
            wp_send_json_success(['message' => __('Conflict resolved', 'wc-multi-store-sync')]);
        } else {
            wp_send_json_error(['message' => __('Conflict not found', 'wc-multi-store-sync')]);
        }
    }

    /**
     * AJAX handler: Resolve all conflicts
     */
    public static function ajax_resolve_all(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $store_url  = sanitize_text_field($_POST['store_url'] ?? '');
        $resolution = sanitize_text_field($_POST['resolution'] ?? 'overwrite');

        $resolved = self::resolve_all($store_url, $resolution);

        wp_send_json_success([
            'message'  => sprintf(__('%d conflict(s) resolved', 'wc-multi-store-sync'), $resolved),
            'resolved' => $resolved,
        ]);
    }

    /**
     * AJAX handler: Toggle conflict detection on/off
     */
    public static function ajax_toggle(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return;
        }

        $enabled = !empty($_POST['enabled']);
        self::update_settings(['enabled' => $enabled]);

        wp_send_json_success([
            'message' => $enabled
                ? __('Conflict detection enabled', 'wc-multi-store-sync')
                : __('Conflict detection disabled', 'wc-multi-store-sync'),
            'enabled' => $enabled,
        ]);
    }
}
