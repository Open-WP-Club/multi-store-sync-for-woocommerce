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
     * Constructor
     */
    public function __construct() {
        // Register scheduled action
        add_action('wc_multi_store_sync_remote_orders', $this->sync_all_stores(...));

        // Add admin action hook
        add_action('admin_post_wc_mss_sync_remote_orders', $this->handle_manual_sync(...));
    }

    /**
     * Create an API client for a specific store
     */
    private function create_api_client(string $store_url, array $store_config): WC_Multi_Store_API_Client {
        return WC_Multi_Store_API_Client::for_store($store_url, $store_config);
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
     * @param string $store_url Store URL
     * @param array  $store     Store configuration
     * @param array  $args      Sync arguments
     * @return array Result
     */
    public function sync_store_orders(string $store_url, array $store, array $args = []): array {
        $defaults = [
            'per_page' => 100,
            'page' => 1,
            'after' => null,  // Date filter (ISO8601 format)
            'before' => null,
            'status' => 'any',
        ];

        $args = wp_parse_args($args, $defaults);

        WC_Multi_Store_Logger::write(sprintf('Starting order sync from %s', $store_url));

        // Create a per-store API client with proper credentials
        $api = $this->create_api_client($store_url, $store);

        $synced = 0;
        $updated = 0;
        $errors = 0;
        $page = $args['page'];
        $has_more = true;

        while ($has_more) {
            // Build API request parameters
            $params = [
                'per_page' => $args['per_page'],
                'page' => $page,
                'orderby' => 'date',
                'order' => 'desc',
            ];

            if ($args['status'] && $args['status'] !== 'any') {
                $params['status'] = $args['status'];
            }

            if ($args['after']) {
                $params['after'] = $args['after'];
            }

            if ($args['before']) {
                $params['before'] = $args['before'];
            }

            // Fetch orders from remote store
            $response = $api->get_orders($params);

            if (is_wp_error($response)) {
                WC_Multi_Store_Logger::write(
                    sprintf('API error fetching orders from %s: %s', $store_url, $response->get_error_message()),
                    'error'
                );
                $errors++;
                break;
            }

            if (empty($response) || !is_array($response)) {
                break;
            }

            // Process each order
            foreach ($response as $order_data) {
                try {
                    $result = $this->process_order($order_data, $store_url);

                    if ($result === 'created') {
                        $synced++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                } catch (Exception $e) {
                    WC_Multi_Store_Logger::write(
                        sprintf('Error processing order #%s from %s: %s',
                            $order_data['id'] ?? 'unknown',
                            $store_url,
                            $e->getMessage()
                        ),
                        'error'
                    );
                    $errors++;
                }
            }

            // Check if there are more pages
            if (count($response) < $args['per_page']) {
                $has_more = false;
            } else {
                $page++;
            }

            // Safety limit - stop after 10 pages
            if ($page > 10) {
                WC_Multi_Store_Logger::write(
                    sprintf('Order sync stopped after 10 pages for %s', $store_url),
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

        return [
            'success' => true,
            'synced' => $synced,
            'updated' => $updated,
            'errors' => $errors,
            'message' => $message,
        ];
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

            WC_Multi_Store_Remote_Order_Table::update($existing_id, $prepared_data);
            return 'updated';
        } else {
            // Create new order
            WC_Multi_Store_Remote_Order_Table::insert($prepared_data);
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
            wp_die(__('You do not have permission to perform this action', 'wc-multi-store-sync'));
        }

        // Get optional filters from request
        $args = [];

        if (isset($_GET['days'])) {
            $days = absint($_GET['days']);
            // Bounds check: limit between 1-365 days to prevent unreasonable queries
            $days = max(1, min(365, $days));
            $args['after'] = date('Y-m-d\TH:i:s', strtotime("-{$days} days"));
        }

        if (isset($_GET['status'])) {
            $args['status'] = sanitize_text_field($_GET['status']);
        }

        // Run sync
        $results = $this->sync_all_stores($args);

        // Redirect back with results
        $total_synced = 0;
        $total_updated = 0;
        $total_errors = 0;

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
        }

        wp_redirect(add_query_arg([
            'page' => 'wc-multi-store-remote-orders',
            'synced' => $total_synced,
            'updated' => $total_updated,
            'errors' => $total_errors,
        ], admin_url('admin.php')));
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
