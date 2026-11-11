<?php
/**
 * WooCommerce Multi-Store Remote Order Sync
 *
 * Syncs orders from remote stores to local database
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remote Order Sync Class
 */
class WC_Multi_Store_Remote_Order_Sync {

    /**
     * Per-store option holding the import high-water mark ("Y-m-d H:i:s", the
     * store's own wall clock): every order at or before it has been imported.
     */
    private const CURSOR_OPTION_PREFIX = 'wc_mss_remote_order_cursor_';

    /**
     * Seconds pulled back from the high-water mark when deriving a run's `after`
     * filter. Orders can share a timestamp and WooCommerce's `after` boundary is
     * not inclusive in every version, so the next run re-reads the boundary
     * second; process_order() is idempotent, which makes that overlap free.
     */
    private const CURSOR_OVERLAP_SECONDS = 2;

    /**
     * Maximum number of pages one run fetches. This is a work budget, not a data
     * limit: a run that reaches it leaves the un-imported tail behind the cursor
     * for the next run instead of dropping it.
     */
    private const MAX_PAGES_PER_RUN = 10;

    /**
     * Per-store option holding failed order IDs for transient retry
     * (remote order ID => number of failed attempts).
     */
    private const FAILED_ORDERS_OPTION_PREFIX = 'wc_mss_remote_order_failed_';

    /**
     * A failed order is retried on this many runs before it is dropped from the
     * retry list; without a bound, an order deleted on the remote store would be
     * requested on every run forever and the list would only ever grow.
     */
    private const MAX_FAILED_ORDER_ATTEMPTS = 5;

    /**
     * Upper bound on the retry list; the oldest entries are dropped first.
     */
    private const MAX_FAILED_ORDERS = 500;

    /**
     * Per-store option that lets a cursor-managed run continue at the page where
     * the previous run's page budget ran out while the cursor could not move
     * (more than one budget's worth of orders sharing one date_modified second,
     * e.g. after a bulk status change). Value: ['filter' => <modified_after
     * sent>, 'page' => <next page>]; ignored as soon as the cursor moves on.
     */
    private const RESUME_OPTION_PREFIX = 'wc_mss_remote_order_resume_';

    /**
     * Constructor
     */
    public function __construct() {
        // Register scheduled action
        add_action('wc_multi_store_sync_remote_orders', $this->sync_all_stores(...));

        // Add admin action hook
        add_action('admin_post_wc_mss_sync_remote_orders', $this->handle_manual_sync(...));
    }

    /**
     * Sync orders from all configured stores
     *
     * @param array $args Sync arguments
     * @return array Results
     */
    public function sync_all_stores(array $args = []): array {
        // Stores are keyed by URL: $stores[$url] = ['consumer_key' => ..., 'status' => 'active', ...]
        $stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($stores)) {
            WC_Multi_Store_Logger::write('No stores configured for remote order sync', 'warning');
            return [];
        }

        $results = [];

        foreach ($stores as $store_url => $store_config) {
            if (empty($store_config['consumer_key']) || empty($store_config['consumer_secret'])) {
                continue;
            }

            try {
                $result = $this->sync_store_orders($store_url, $store_config, $args);
                $results[$store_url] = $result;
            } catch (\Throwable $e) {
                WC_Multi_Store_Logger::write(
                    sprintf('Failed to sync orders from %s: %s', $store_url, $e->getMessage()),
                    'error'
                );
                $results[$store_url] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Sync orders from a specific store
     *
     * Runs are incremental: a per-store cursor records the newest order already
     * imported, and the next run only asks for what is newer. The stream is walked
     * oldest-first so that a run hitting the page budget postpones the NEWEST
     * orders — which the cursor then resumes — instead of dropping them.
     *
     * @param string $store_url Store URL
     * @param array  $store     Store configuration
     * @param array  $args      Sync arguments
     * @return array Result, with 'truncated' telling the caller the run stopped at
     *               its page budget and the remainder continues on a later run
     */
    public function sync_store_orders(string $store_url, array $store, array $args = []): array {
        $defaults = [
            'per_page' => 100,
            'page' => 1,
            'after' => null,  // Date filter (ISO8601 format)
            'modified_after' => null, // Modified date filter (ISO8601 format)
            'before' => null,
            'status' => 'any',
        ];

        $args = wp_parse_args($args, $defaults);

        WC_Multi_Store_Logger::write(sprintf('Starting order sync from %s', $store_url));

        // Create a per-store API client with proper credentials
        $api = WC_Multi_Store_API_Client::for_store($store_url, $store);

        // Only an unfiltered walk of the stream manages the cursor. An explicit date
        // window (`after`, `modified_after`), an upper bound (`before`), a status
        // filter or a starting page is a one-off query: `after` walks are sorted by
        // date_created and the other filters cover only part of the stream, so their
        // high-water mark says nothing about which orders modified before it have
        // been imported — persisting it could skip orders for good.
        $cursor_option = self::CURSOR_OPTION_PREFIX . md5($store_url);
        $resume_option = self::RESUME_OPTION_PREFIX . md5($store_url);
        $failed_option = self::FAILED_ORDERS_OPTION_PREFIX . md5($store_url);
        $stored_cursor = (string) get_option($cursor_option, '');

        $manages_cursor = $args['before'] === null
            && empty($args['after'])
            && empty($args['modified_after'])
            && (empty($args['status']) || $args['status'] === 'any')
            && (int) $args['page'] <= 1;

        if (!empty($args['modified_after'])) {
            $modified_after_filter = (string) $args['modified_after'];
            $after_filter = '';
        } elseif (!empty($args['after'])) {
            $after_filter = (string) $args['after'];
            $modified_after_filter = '';
        } elseif ($stored_cursor !== '') {
            $modified_after_filter = date('Y-m-d\TH:i:s', strtotime($stored_cursor) - self::CURSOR_OVERLAP_SECONDS);
            $after_filter = '';
        } else {
            $modified_after_filter = '';
            $after_filter = '';
        }

        $synced = 0;
        $updated = 0;
        $errors = 0;
        $page = max(1, (int) $args['page']);
        $pages_fetched = 0;
        $has_more = true;
        $max_seen = '';
        $natural_end = false;
        $truncated = false;

        // Continue where the previous run's page budget ran out, but only while the
        // request is identical (same cursor-derived filter): once the cursor moves,
        // page numbers refer to a different result set and the walk restarts at 1.
        $resume = $manages_cursor ? get_option($resume_option, []) : [];
        $had_resume = is_array($resume) && !empty($resume);
        if ($had_resume
            && ($resume['filter'] ?? null) === $modified_after_filter
            && (int) ($resume['page'] ?? 0) > 1
        ) {
            $page = (int) $resume['page'];
            WC_Multi_Store_Logger::write(sprintf(
                'Order sync for %s resumes at page %d (the previous run exhausted its page budget without moving the cursor)',
                $store_url,
                $page
            ));
        }

        // Retry previously failed orders (transient failure recovery)
        $failed_orders = (array) get_option($failed_option, []);
        if (!empty($failed_orders)) {
            $retry_ids = array_slice(array_keys($failed_orders), 0, 100);
            $retry_response = $api->get_orders(['include' => $retry_ids, 'per_page' => 100]);
            if (is_array($retry_response)) {
                $returned = [];
                foreach ($retry_response as $retry_order) {
                    if (!is_array($retry_order) || empty($retry_order['id'])) {
                        continue;
                    }
                    $roid = (int) $retry_order['id'];
                    $returned[$roid] = true;
                    try {
                        $retry_result = $this->process_order($retry_order, $store_url);
                        unset($failed_orders[$roid]);
                        if ($retry_result === 'created') {
                            $synced++;
                        } elseif ($retry_result === 'updated') {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        $failed_orders[$roid] = ((int) ($failed_orders[$roid] ?? 0)) + 1;
                    }
                }
                // An ID the store no longer returns (deleted or trashed remotely) is a
                // failed attempt too, so it ages out below instead of being requested
                // on every run forever. A transport error is not counted.
                foreach ($retry_ids as $retry_id) {
                    if (!isset($returned[$retry_id]) && isset($failed_orders[$retry_id])) {
                        $failed_orders[$retry_id] = ((int) $failed_orders[$retry_id]) + 1;
                    }
                }
            }
            // Move attempted IDs to the end so one permanently failing group
            // cannot prevent later entries from ever being retried.
            foreach ($retry_ids as $retry_id) {
                if (isset($failed_orders[$retry_id])) {
                    $attempts = $failed_orders[$retry_id];
                    unset($failed_orders[$retry_id]);
                    $failed_orders[$retry_id] = $attempts;
                }
            }
            $failed_orders = $this->prune_failed_orders($failed_orders, $store_url);
        }

        while ($has_more) {
            // Build API request parameters
            $params = [
                'per_page' => $args['per_page'],
                'page' => $page,
                // Oldest first, so the page budget below postpones the newest
                // orders (resumable through the cursor) rather than the oldest.
                'orderby' => $args['orderby'] ?? ($after_filter !== '' ? 'date' : 'modified'),
                'order' => 'asc',
            ];

            if ($args['status'] && $args['status'] !== 'any') {
                $params['status'] = $args['status'];
            }

            if ($modified_after_filter !== '') {
                $params['modified_after'] = $modified_after_filter;
            } elseif ($after_filter !== '') {
                $params['after'] = $after_filter;
            }

            if ($args['before']) {
                $params['before'] = $args['before'];
            }

            // Fetch orders from remote store
            $response = $api->get_orders($params);
            $pages_fetched++;

            if (is_wp_error($response)) {
                WC_Multi_Store_Logger::write(
                    sprintf('API error fetching orders from %s: %s', $store_url, $response->get_error_message()),
                    'error'
                );
                $errors++;
                break;
            }

            if (empty($response)) {
                $natural_end = true;
                break;
            }

            // Process each order
            foreach ($response as $order_data) {
                try {
                    if (!is_array($order_data)) {
                        throw new Exception('Unexpected order entry in API response');
                    }

                    // Track the stream high-water mark from raw API timestamp
                    // (date_modified covers updates/refunds as well as initial creation).
                    $raw_date = $order_data['date_modified'] ?? ($order_data['date_created'] ?? null);
                    if (is_string($raw_date)
                        && preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $raw_date, $date_parts)
                    ) {
                        $order_date = $date_parts[1] . ' ' . $date_parts[2];
                        if ($max_seen === '' || $order_date > $max_seen) {
                            $max_seen = $order_date;
                        }
                    }

                    $result = $this->process_order($order_data, $store_url);

                    if (!empty($order_data['id'])) {
                        unset($failed_orders[(int) $order_data['id']]);
                    }

                    if ($result === 'created') {
                        $synced++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    if (is_array($order_data) && !empty($order_data['id'])) {
                        $failed_oid = (int) $order_data['id'];
                        $failed_orders[$failed_oid] = ((int) ($failed_orders[$failed_oid] ?? 0)) + 1;
                    }

                    WC_Multi_Store_Logger::write(
                        sprintf('Error processing order #%s from %s: %s',
                            is_array($order_data) ? ($order_data['id'] ?? 'unknown') : 'unknown',
                            $store_url,
                            $e->getMessage()
                        ),
                        'error'
                    );
                    $errors++;
                }
            }

            // Save retry IDs before moving the cursor: a worker crash must not
            // strand failed orders behind an already persisted high-water mark.
            $failed_orders = $this->prune_failed_orders($failed_orders, $store_url);
            update_option($failed_option, $failed_orders, false);

            // Persist progress after every page: only advance forward
            if ($manages_cursor && $max_seen !== '') {
                if ($stored_cursor === '' || $max_seen > $stored_cursor) {
                    update_option($cursor_option, $max_seen, false);
                }
            }

            // Check if there are more pages
            if (count($response) < $args['per_page']) {
                $natural_end = true;
                $has_more = false;
            } else {
                $page++;
            }

            // Work budget: stop after MAX_PAGES_PER_RUN pages. The cursor keeps the
            // un-imported tail reachable, so this postpones work instead of losing it.
            if ($has_more && $pages_fetched >= self::MAX_PAGES_PER_RUN) {
                $truncated = true;
                WC_Multi_Store_Logger::write(
                    sprintf('Order sync for %s paused after %d pages; the remainder continues on the next run', $store_url, self::MAX_PAGES_PER_RUN),
                    'warning'
                );
                break;
            }
        }

        $message = sprintf(
            'Order sync completed for %s: %d new, %d updated, %d errors',
            $store_url,
            $synced,
            $updated,
            $errors
        );

        WC_Multi_Store_Logger::write($message);

        if ($manages_cursor && $max_seen !== '') {
            if ($stored_cursor === '' || $max_seen > $stored_cursor) {
                update_option($cursor_option, $max_seen, false);
            }
        }

        // Remember where a truncated run stopped; a run that reached the end of the
        // stream clears the marker. After a transport error the marker is left as it
        // was, so an interrupted resume does not fall back to page 1.
        if ($manages_cursor) {
            if ($truncated) {
                update_option($resume_option, ['filter' => $modified_after_filter, 'page' => $page], false);
            } elseif ($natural_end && $had_resume) {
                delete_option($resume_option);
            }
        }

        // Keep failures until a subsequent retry actually succeeds.
        update_option($failed_option, $this->prune_failed_orders($failed_orders, $store_url), false);

        return [
            'success' => true,
            'synced' => $synced,
            'updated' => $updated,
            'errors' => $errors,
            'truncated' => $truncated,
            'message' => $message,
        ];
    }

    /**
     * Bound the per-store retry list: drop orders that have failed on
     * MAX_FAILED_ORDER_ATTEMPTS runs and keep at most MAX_FAILED_ORDERS entries
     * (newest last, oldest dropped first).
     *
     * @param array<int, int> $failed_orders Remote order ID => failed attempts
     * @param string          $store_url     Store URL (for the log line)
     * @return array<int, int>
     */
    private function prune_failed_orders(array $failed_orders, string $store_url): array {
        $dropped = [];
        foreach ($failed_orders as $order_id => $attempts) {
            if ((int) $attempts >= self::MAX_FAILED_ORDER_ATTEMPTS) {
                $dropped[] = (int) $order_id;
                unset($failed_orders[$order_id]);
            }
        }

        $overflow = count($failed_orders) - self::MAX_FAILED_ORDERS;
        if ($overflow > 0) {
            $dropped = array_merge($dropped, array_map('intval', array_slice(array_keys($failed_orders), 0, $overflow)));
            $failed_orders = array_slice($failed_orders, $overflow, null, true);
        }

        if ($dropped !== []) {
            WC_Multi_Store_Logger::write(sprintf(
                'Dropped %d remote order(s) from the retry list for %s (failed on %d runs, or the list exceeded %d entries): #%s',
                count($dropped),
                $store_url,
                self::MAX_FAILED_ORDER_ATTEMPTS,
                self::MAX_FAILED_ORDERS,
                implode(', #', array_slice($dropped, 0, 20))
            ), 'warning');
        }

        return $failed_orders;
    }

    /**
     * Process a single order
     *
     * @param array  $order_data Order data from API
     * @param string $store_url  Store URL
     * @return string Action taken (created, updated, skipped)
     */
    private function process_order(array $order_data, string $store_url): string {
        if (empty($order_data['id'])) {
            throw new Exception('Order ID missing');
        }

        $remote_order_id = $order_data['id'];

        // Check if order already exists
        $existing_id = WC_Multi_Store_Remote_Order_Table::order_exists($remote_order_id, $store_url);

        // Prepare order data for storage
        $prepared_data = $this->prepare_order_data($order_data, $store_url);

        if ($existing_id) {
            // Update existing order
            $existing_order = WC_Multi_Store_Remote_Order_Table::get($existing_id);

            // Check if order has changed (using hash)
            if ($existing_order && $existing_order->sync_hash === $prepared_data['sync_hash']) {
                return 'skipped'; // No changes
            }

            if (!WC_Multi_Store_Remote_Order_Table::update($existing_id, $prepared_data)) {
                throw new Exception('Could not update the stored order');
            }
            return 'updated';
        } else {
            // Create new order
            if (!WC_Multi_Store_Remote_Order_Table::insert($prepared_data)) {
                throw new Exception('Could not store the order');
            }
            return 'created';
        }
    }

    /**
     * Prepare order data for storage
     *
     * @param array  $order_data Order data from API
     * @param string $store_url  Store URL
     * @return array Prepared data
     */
    private function prepare_order_data(array $order_data, string $store_url): array {
        // Extract customer name
        $customer_name = '';
        if (!empty($order_data['billing']['first_name']) || !empty($order_data['billing']['last_name'])) {
            $customer_name = trim(
                ($order_data['billing']['first_name'] ?? '') . ' ' .
                ($order_data['billing']['last_name'] ?? '')
            );
        }

        // Prepare line items
        $line_items = [];
        if (!empty($order_data['line_items']) && is_array($order_data['line_items'])) {
            foreach ($order_data['line_items'] as $item) {
                $line_items[] = [
                    'remote_product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['name'] ?? '',
                    'product_sku' => $item['sku'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'subtotal' => floatval($item['subtotal'] ?? 0),
                    'total' => floatval($item['total'] ?? 0),
                    'tax_total' => floatval($item['total_tax'] ?? 0),
                    'meta_data' => $item['meta_data'] ?? [],
                ];
            }
        }

        // Calculate sync hash for change detection
        $hash_data = [
            'status' => $order_data['status'] ?? '',
            'total' => $order_data['total'] ?? 0,
            'line_items' => $line_items,
            'date_modified' => $order_data['date_modified'] ?? '',
        ];
        $sync_hash = hash('sha256', json_encode($hash_data));

        return [
            'remote_order_id' => $order_data['id'],
            'remote_store_url' => $store_url,
            'order_number' => $order_data['number'] ?? $order_data['id'],
            'order_key' => $order_data['order_key'] ?? null,
            'customer_id' => $order_data['customer_id'] ?? null,
            'customer_email' => $order_data['billing']['email'] ?? null,
            'customer_name' => $customer_name,
            'status' => $order_data['status'] ?? 'pending',
            'currency' => $order_data['currency'] ?? 'USD',
            'total' => floatval($order_data['total'] ?? 0),
            'subtotal' => floatval($order_data['line_items_total'] ?? 0),
            'tax_total' => floatval($order_data['total_tax'] ?? 0),
            'shipping_total' => floatval($order_data['shipping_total'] ?? 0),
            'discount_total' => floatval($order_data['discount_total'] ?? 0),
            'payment_method' => $order_data['payment_method'] ?? null,
            'payment_method_title' => $order_data['payment_method_title'] ?? null,
            'transaction_id' => $order_data['transaction_id'] ?? null,
            'date_created' => isset($order_data['date_created']) ? $this->convert_date($order_data['date_created']) : current_time('mysql'),
            'date_modified' => isset($order_data['date_modified']) ? $this->convert_date($order_data['date_modified']) : null,
            'date_paid' => isset($order_data['date_paid']) ? $this->convert_date($order_data['date_paid']) : null,
            'date_completed' => isset($order_data['date_completed']) ? $this->convert_date($order_data['date_completed']) : null,
            'billing_address' => $order_data['billing'] ?? null,
            'shipping_address' => $order_data['shipping'] ?? null,
            'line_items' => $line_items,
            'order_meta' => $order_data['meta_data'] ?? null,
            'sync_hash' => $sync_hash,
        ];
    }

    /**
     * Convert WooCommerce API date to MySQL format
     *
     * @param string $date Date string
     * @return string|null MySQL formatted date
     */
    private function convert_date(string $date): ?string {
        if (empty($date)) {
            return null;
        }

        try {
            $dt = new DateTime($date);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Handle manual sync from admin
     */
    public function handle_manual_sync(): void {
        check_admin_referer('wc_mss_sync_remote_orders');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action', 'multi-store-sync-for-woocommerce'));
        }

        // Get optional filters from request
        $args = [];

        if (isset($_GET['days'])) {
            $days = absint(wp_unslash($_GET['days']));
            // Bounds check: limit between 1-365 days to prevent unreasonable queries
            $days = max(1, min(365, $days));
            $args['after'] = date('Y-m-d\TH:i:s', strtotime("-{$days} days"));
        }

        if (isset($_GET['status']) && is_string($_GET['status'])) {
            $status = sanitize_key(wp_unslash($_GET['status']));
            $valid_statuses = ['any'];

            if (function_exists('wc_get_order_statuses')) {
                foreach (array_keys(wc_get_order_statuses()) as $registered_status) {
                    $valid_statuses[] = str_starts_with($registered_status, 'wc-')
                        ? substr($registered_status, 3)
                        : $registered_status;
                }
            } else {
                $valid_statuses = array_merge($valid_statuses, [
                    'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'trash',
                ]);
            }

            if (in_array($status, $valid_statuses, true)) {
                $args['status'] = $status;
            }
        }

        // Run sync
        $results = $this->sync_all_stores($args);

        // Redirect back with results
        $total_synced = 0;
        $total_updated = 0;
        $total_errors = 0;
        $truncated = false;

        foreach ($results as $result) {
            if (isset($result['synced'])) {
                $total_synced += $result['synced'];
            }
            if (isset($result['updated'])) {
                $total_updated += $result['updated'];
            }
            if (isset($result['errors'])) {
                $total_errors += $result['errors'];
            }
            if (!empty($result['truncated'])) {
                $truncated = true;
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'wc-multi-store-remote-orders',
            'synced' => $total_synced,
            'updated' => $total_updated,
            'errors' => $total_errors,
        ] + ($truncated ? ['truncated' => 1] : []), admin_url('admin.php')));
        exit;
    }

    /**
     * Schedule automatic sync
     *
     * @param string $interval Cron interval (hourly, daily, twicedaily)
     */
    public static function schedule_sync(string $interval = 'daily'): void {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return;
        }

        $hook = 'wc_multi_store_sync_remote_orders';
        $group = WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP;

        // Unschedule existing before rescheduling
        as_unschedule_all_actions($hook, [], $group);

        $interval_seconds = match ($interval) {
            'hourly' => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            default => DAY_IN_SECONDS,
        };

        as_schedule_recurring_action(time(), $interval_seconds, $hook, [], $group);
    }

    /**
     * Unschedule automatic sync
     */
    public static function unschedule_sync(): void {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(
            'wc_multi_store_sync_remote_orders',
            [],
            WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
        );
    }
}
