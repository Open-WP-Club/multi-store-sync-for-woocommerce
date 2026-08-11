<?php
/**
 * Webhook Receiver
 * Handles incoming webhooks from remote WooCommerce stores for order-based stock synchronization
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Webhook_Receiver {

    /**
     * Rate limit: maximum requests per minute per IP
     */
    const int RATE_LIMIT_MAX_REQUESTS = 100;

    /**
     * Rate limit window in seconds
     */
    const int RATE_LIMIT_WINDOW = 60;

    /**
     * Flag to indicate webhook processing is in progress.
     * Used instead of define() so it can be reset after each webhook.
     *
     * @var bool
     */
    public static bool $is_processing_webhook = false;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', $this->register_routes(...));
    }

    /**
     * Check rate limiting for incoming requests
     *
     * @return bool|WP_Error True if allowed, WP_Error if rate limited
     */
    private function check_rate_limit(): bool|WP_Error {
        $client_ip = WC_Multi_Store_Webhook_Logger::get_client_ip();
        $rate_key = 'wc_mss_webhook_rate_' . md5($client_ip);

        // Use wp_cache_add + wp_cache_incr for atomic increment when a persistent
        // object cache (Redis/Memcached) is active. Falls back to transients otherwise.
        $cache_group = 'wc_mss_rate_limits';
        wp_cache_add($rate_key, 0, $cache_group, self::RATE_LIMIT_WINDOW);
        $current_count = wp_cache_incr($rate_key, 1, $cache_group);

        if ($current_count === false) {
            // Non-persistent object cache: fall back to transients (best-effort).
            $current_count = (int) get_transient($rate_key);
            set_transient($rate_key, $current_count + 1, self::RATE_LIMIT_WINDOW);
            $current_count++;
        }

        if ($current_count > self::RATE_LIMIT_MAX_REQUESTS) {
            WC_Multi_Store_Logger::write(
                sprintf('Rate limit exceeded for IP: %s (%d requests)', $client_ip, $current_count),
                'warning'
            );
            // Log to webhook logger
            WC_Multi_Store_Webhook_Logger::log_rate_limited($client_ip, $current_count);
            return new WP_Error(
                'rate_limit_exceeded',
                __('Too many requests. Please try again later.', 'wc-multi-store-sync'),
                ['status' => 429]
            );
        }

        return true;
    }

    /**
     * Register REST API routes for webhooks
     */
    public function register_routes(): void {
        register_rest_route('wc-multi-store-sync/v1', '/webhook/order', [
            'methods' => 'POST',
            'callback' => $this->handle_order_webhook(...),
            'permission_callback' => $this->verify_webhook_signature(...),
        ]);

        register_rest_route('wc-multi-store-sync/v1', '/webhook/test', [
            'methods' => 'POST',
            'callback' => $this->handle_test_webhook(...),
            'permission_callback' => $this->verify_webhook_signature(...),
        ]);
    }

    /**
     * Verify webhook signature for security
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function verify_webhook_signature(WP_REST_Request $request): bool|WP_Error {
        // Check rate limiting first
        $rate_check = $this->check_rate_limit();
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        // Get webhook secret from settings
        $settings = WC_Multi_Store_Settings::get_webhook_settings();
        $webhook_secret = $settings['webhook_secret'] ?? '';

        // If no secret is set, reject the request
        if (empty($webhook_secret)) {
            WC_Multi_Store_Logger::write('Webhook rejected: No webhook secret configured', 'error');
            WC_Multi_Store_Webhook_Logger::log_auth_failed(
                'Webhook secret not configured',
                WC_Multi_Store_Webhook_Logger::get_client_ip()
            );
            return new WP_Error(
                'webhook_not_configured',
                __('Webhook receiver is not configured. Please set up webhook secret in settings.', 'wc-multi-store-sync'),
                ['status' => 503]
            );
        }

        // Get signature from header (WooCommerce sends X-WC-Webhook-Signature)
        $signature = $request->get_header('x-wc-webhook-signature');

        // Also check custom header for store identification
        $store_secret = $request->get_header('x-wc-mss-secret');

        // Allow either WooCommerce standard signature or our custom secret
        if (!empty($signature)) {
            // Verify WooCommerce signature (base64 encoded HMAC-SHA256)
            $payload = $request->get_body();
            $calculated_signature = base64_encode(hash_hmac('sha256', $payload, $webhook_secret, true));

            if (!hash_equals($calculated_signature, $signature)) {
                WC_Multi_Store_Logger::write('Webhook rejected: Invalid WooCommerce signature', 'error');
                WC_Multi_Store_Webhook_Logger::log_auth_failed(
                    'Invalid WooCommerce signature',
                    WC_Multi_Store_Webhook_Logger::get_client_ip()
                );
                return new WP_Error(
                    'invalid_signature',
                    __('Invalid webhook signature.', 'wc-multi-store-sync'),
                    ['status' => 401]
                );
            }
        } elseif (!empty($store_secret)) {
            // Verify custom secret
            if (!hash_equals($webhook_secret, $store_secret)) {
                WC_Multi_Store_Logger::write('Webhook rejected: Invalid secret', 'error');
                WC_Multi_Store_Webhook_Logger::log_auth_failed(
                    'Invalid custom secret',
                    WC_Multi_Store_Webhook_Logger::get_client_ip()
                );
                return new WP_Error(
                    'invalid_secret',
                    __('Invalid webhook secret.', 'wc-multi-store-sync'),
                    ['status' => 401]
                );
            }
        } else {
            WC_Multi_Store_Logger::write('Webhook rejected: No signature or secret provided', 'error');
            WC_Multi_Store_Webhook_Logger::log_auth_failed(
                'Missing signature or secret',
                WC_Multi_Store_Webhook_Logger::get_client_ip()
            );
            return new WP_Error(
                'missing_auth',
                __('Missing webhook authentication.', 'wc-multi-store-sync'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Handle test webhook (for setup verification)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_test_webhook(WP_REST_Request $request): WP_REST_Response {
        $store_url = $request->get_param('store_url');

        WC_Multi_Store_Logger::write(sprintf(
            'Test webhook received from: %s',
            $store_url ?? 'unknown'
        ));

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Webhook receiver is working correctly!',
            'timestamp' => current_time('mysql'),
        ], 200);
    }

    /**
     * Handle order webhook from remote store
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_order_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $start_time = microtime(true);

        // Get order data from webhook payload
        $order_data = $request->get_json_params();

        // Get store URL from query parameter
        $store_url = $request->get_param('store_url');

        // Validate required data
        if (empty($order_data)) {
            WC_Multi_Store_Logger::write('Webhook rejected: Empty order data', 'error');
            WC_Multi_Store_Webhook_Logger::log_validation_error(
                'Empty order data',
                $store_url
            );
            return new WP_Error(
                'empty_data',
                __('Empty order data received.', 'wc-multi-store-sync'),
                ['status' => 400]
            );
        }

        if (empty($store_url)) {
            WC_Multi_Store_Logger::write('Webhook rejected: Missing store_url parameter', 'error');
            WC_Multi_Store_Webhook_Logger::log_validation_error(
                'Missing store_url parameter',
                null,
                $order_data
            );
            return new WP_Error(
                'missing_store_url',
                __('Missing store_url parameter.', 'wc-multi-store-sync'),
                ['status' => 400]
            );
        }

        // Verify store URL is registered
        if (!$this->is_store_registered($store_url)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Webhook rejected: Store not registered: %s',
                $store_url
            ), 'error');
            WC_Multi_Store_Webhook_Logger::log_validation_error(
                'Store not registered: ' . $store_url,
                $store_url,
                $order_data
            );
            return new WP_Error(
                'unknown_store',
                __('Store is not registered in the system.', 'wc-multi-store-sync'),
                ['status' => 403]
            );
        }

        // Check if order-based stock sync is enabled
        $webhook_settings = WC_Multi_Store_Settings::get_webhook_settings();
        if (empty($webhook_settings['enabled'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Order-based stock sync is disabled.',
            ], 200); // Return 200 to avoid webhook retries
        }

        // Extract order ID and status
        $order_id = absint($order_data['id'] ?? 0);
        $order_status = sanitize_text_field($order_data['status'] ?? '');
        $line_items = $order_data['line_items'] ?? [];

        // Check if this order status should trigger stock sync
        $trigger_statuses = $webhook_settings['trigger_statuses'] ?? ['processing', 'completed'];

        if (!in_array($order_status, $trigger_statuses)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Order #%d from %s skipped: Status "%s" not in trigger list',
                $order_id,
                $store_url,
                $order_status
            ));
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Order status not configured for stock sync.',
                'order_id' => $order_id,
                'status' => $order_status,
            ], 200);
        }

        if (empty($line_items)) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Order has no line items.',
                'order_id' => $order_id,
            ], 200);
        }

        // Replay protection: a captured (signature, body) can be re-sent
        // indefinitely and would otherwise deduct stock again. Key on the
        // status as well because the same order legitimately fires once per
        // configured trigger status (processing → completed → …).
        $dedup_key = 'wc_mss_wh_seen_' . md5($store_url . '|' . $order_id . '|' . $order_status);
        if (get_transient($dedup_key) !== false) {
            WC_Multi_Store_Logger::write(sprintf(
                'Webhook duplicate suppressed: Order #%d from %s (status: %s)',
                $order_id,
                $store_url,
                $order_status
            ));
            return new WP_REST_Response([
                'success'   => true,
                'message'   => 'Duplicate webhook ignored (already processed).',
                'order_id'  => $order_id,
                'duplicate' => true,
            ], 200);
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Processing order webhook: Order #%d from %s (status: %s, items: %d)',
            $order_id,
            $store_url,
            $order_status,
            count($line_items)
        ));

        // Log order received
        WC_Multi_Store_Webhook_Logger::log_order_received($order_data, $store_url, WC_Multi_Store_Webhook_Logger::get_client_ip());

        // Process stock deduction
        try {
            $result = $this->process_stock_deduction($order_id, $line_items, $store_url);
        } finally {
            // Always reset the webhook processing flag so subsequent
            // stock changes in this request are handled normally
            self::$is_processing_webhook = false;
        }

        $duration = round((microtime(true) - $start_time) * 1000, 2);

        if (is_wp_error($result)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Order webhook failed: Order #%d from %s - %s (took %dms)',
                $order_id,
                $store_url,
                $result->get_error_message(),
                $duration
            ), 'error');

            return $result;
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Order webhook completed: Order #%d from %s - %d products processed (took %dms)',
            $order_id,
            $store_url,
            $result['products_processed'],
            $duration
        ));

        // Only set the dedup marker after successful processing so a failed
        // first attempt does not poison the legitimate WooCommerce retry.
        set_transient($dedup_key, 1, HOUR_IN_SECONDS);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Process stock deduction for order items
     *
     * @param int $order_id Remote order ID
     * @param array $line_items Order line items
     * @param string $store_url Remote store URL
     * @return array|WP_Error
     */
    private function process_stock_deduction(int $order_id, array $line_items, string $store_url): array|WP_Error {
        $products_processed = 0;
        $products_failed = 0;
        $errors = [];
        $deducted_products = [];

        foreach ($line_items as $item) {
            $quantity = absint($item['quantity'] ?? 0);
            $sku = sanitize_text_field($item['sku'] ?? '');
            $product_id = absint($item['product_id'] ?? 0);
            $variation_id = absint($item['variation_id'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            // Find product in main store by SKU
            $main_product = null;
            if (!empty($sku)) {
                $main_product_id = wc_get_product_id_by_sku($sku);
                if ($main_product_id) {
                    $main_product = wc_get_product($main_product_id);
                }
            }

            if (!$main_product) {
                $errors[] = sprintf(
                    'Product with SKU "%s" not found in main store',
                    $sku
                );
                // Log product not found
                WC_Multi_Store_Webhook_Logger::log_product_not_found($sku, $order_id, $store_url);
                $products_failed++;
                continue;
            }

            // Deduct stock from main store
            $deduction_result = $this->deduct_stock($main_product, $quantity, $order_id, $store_url);

            if (is_wp_error($deduction_result)) {
                $errors[] = sprintf(
                    'SKU %s: %s',
                    $sku,
                    $deduction_result->get_error_message()
                );
                $products_failed++;
            } else {
                $products_processed++;
                $deducted_products[] = [
                    'product_id' => $main_product->get_id(),
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'new_stock' => $deduction_result['new_stock'],
                ];
            }
        }

        // If we successfully deducted stock, sync to all remote stores
        if (!empty($deducted_products)) {
            $this->sync_stock_to_stores($deducted_products, $store_url);
        }

        $result = [
            'success' => true,
            'order_id' => $order_id,
            'store_url' => $store_url,
            'products_processed' => $products_processed,
            'products_failed' => $products_failed,
        ];

        if (!empty($errors)) {
            $result['errors'] = $errors;
        }

        if (!empty($deducted_products)) {
            $result['deducted_products'] = $deducted_products;
        }

        return $result;
    }

    /**
     * Deduct stock from a product
     *
     * @param WC_Product $product Product object
     * @param int $quantity Quantity to deduct
     * @param int $order_id Remote order ID
     * @param string $store_url Remote store URL
     * @return array|WP_Error
     */
    private function deduct_stock(WC_Product $product, int $quantity, int $order_id, string $store_url): array|WP_Error {
        if (!$product->managing_stock()) {
            return new WP_Error(
                'stock_not_managed',
                sprintf('Product %s does not manage stock', $product->get_sku())
            );
        }

        $product_id = $product->get_id();
        $settings = WC_Multi_Store_Settings::get_webhook_settings();
        $allow_negative = $settings['allow_negative_stock'] ?? false;

        // Read current stock before deduction (for logging/history)
        $current_stock = (int) $product->get_stock_quantity();

        // Pre-check for negative stock (non-atomic, but avoids unnecessary work)
        if (($current_stock - $quantity) < 0 && !$allow_negative) {
            return new WP_Error(
                'insufficient_stock',
                sprintf(
                    'Insufficient stock for %s (current: %d, requested: %d)',
                    $product->get_sku(),
                    $current_stock,
                    $quantity
                )
            );
        }

        // Temporarily disable stock change hooks to prevent duplicate queueing.
        // Uses a static flag instead of define() so it can be reset after processing.
        // Hooks check this via the WC_MSS_WEBHOOK_PROCESSING constant OR this static flag.
        self::$is_processing_webhook = true;

        // Use WooCommerce CRUD for stock deduction — works with both
        // legacy postmeta and HPOS (High-Performance Order Storage).
        // wc_update_product_stock() handles cache clearing, stock status,
        // and fires appropriate hooks internally.
        global $wpdb;

        if ($allow_negative) {
            // Use WC's built-in stock reduction
            $final_stock = wc_update_product_stock($product_id, $quantity, 'decrease');
        } else {
            // Atomic deduction with floor at zero:
            // Use conditional UPDATE to prevent race conditions — only deduct
            // if sufficient stock exists. Check rows_affected to detect races.
            $table = $wpdb->postmeta;
            $rows = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET meta_value = CAST(meta_value AS SIGNED) - %d
                 WHERE post_id = %d AND meta_key = '_stock'
                 AND CAST(meta_value AS SIGNED) >= %d",
                $quantity,
                $product_id,
                $quantity
            ));

            if ($rows === 0) {
                // Race condition: stock was reduced by another request between
                // our pre-check and the UPDATE. Re-read and report actual stock.
                $product = wc_get_product($product_id);
                $actual_stock = $product ? (int) $product->get_stock_quantity() : 0;
                return new WP_Error(
                    'insufficient_stock',
                    sprintf(
                        'Insufficient stock for %s (current: %d, requested: %d) — concurrent deduction detected',
                        $product->get_sku(),
                        $actual_stock,
                        $quantity
                    )
                );
            }

            // Clear caches and re-read
            wp_cache_delete($product_id, 'post_meta');
            wc_delete_product_transients($product_id);

            $product = wc_get_product($product_id);
            $final_stock = $product ? (int) $product->get_stock_quantity() : 0;

            // Update stock status via WC CRUD
            if ($product) {
                $product->set_stock_status($final_stock > 0 ? 'instock' : 'outofstock');
                $product->save();
            }
        }

        // Ensure $final_stock is an int
        $final_stock = (int) $final_stock;

        // Re-fetch product to ensure we have latest data
        $product = wc_get_product($product_id);

        // Record this stock update with tracking metadata (for centralized master approach)
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update($product->get_id(), $store_url);

        // Log the stock change
        WC_Multi_Store_Logger::write(sprintf(
            'Stock deducted: %s - Qty: %d (from %d to %d) - Remote Order #%d from %s',
            $product->get_sku(),
            $quantity,
            $current_stock,
            $final_stock,
            $order_id,
            $store_url
        ));

        // Log to webhook logger with full details
        WC_Multi_Store_Webhook_Logger::log_stock_deducted(
            $product_id,
            $product->get_sku(),
            $current_stock,
            $final_stock,
            $quantity,
            $order_id,
            $store_url
        );

        return [
            'product_id' => $product_id,
            'sku' => $product->get_sku(),
            'old_stock' => $current_stock,
            'new_stock' => $final_stock,
            'quantity_deducted' => $quantity,
        ];
    }

    /**
     * Sync stock to all remote stores after deduction
     *
     * @param array $products Products that had stock deducted
     * @param string $originating_store The store that triggered the webhook
     * @return void
     */
    private function sync_stock_to_stores(array $products, string $originating_store): void {
        foreach ($products as $product_data) {
            $product_id = $product_data['product_id'];

            // Add to queue with HIGH priority (1) for immediate processing
            // Master store must sync quickly to prevent race conditions
            WC_MSS()->queue_manager->add_product(
                $product_id,
                'webhook_stock_change',
                1 // Highest priority - master store updates must propagate immediately
            );

            WC_Multi_Store_Logger::write(sprintf(
                'Queued stock sync for product #%d (SKU: %s) to all stores with HIGH priority (master sync)',
                $product_id,
                $product_data['sku']
            ));

            // Log sync queued
            WC_Multi_Store_Webhook_Logger::log_stock_synced(
                $product_id,
                $product_data['sku'],
                $product_data['new_stock'],
                $originating_store
            );
        }

        // High priority queue items will be processed on next cron run (every 5 minutes)
        // Using priority 1 ensures master store updates are synced before other changes
    }

    /**
     * Check if a store URL is registered in the system
     *
     * @param string $store_url Store URL to check
     * @return bool
     */
    private function is_store_registered(string $store_url): bool {
        $stores = get_option('wc_multi_store_sync_stores', []);

        // Normalize URL for comparison
        $store_url = untrailingslashit($store_url);

        // Stores are keyed by URL: $stores[$url] = ['status' => 'active', ...]
        foreach ($stores as $registered_url => $config) {
            if (!isset($config['status']) || $config['status'] !== 'active') {
                continue;
            }

            if (untrailingslashit($registered_url) === $store_url) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate webhook URL for a specific store
     *
     * @param string $store_url Remote store URL
     * @return string
     */
    public static function get_webhook_url(string $store_url): string {
        return add_query_arg(
            ['store_url' => urlencode($store_url)],
            rest_url('wc-multi-store-sync/v1/webhook/order')
        );
    }

}
