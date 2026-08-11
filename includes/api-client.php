<?php
/**
 * WooCommerce Multi-Store API Client
 *
 * Handles communication with WooCommerce REST API v3
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce REST API Client Class
 */
class WC_Multi_Store_API_Client {

    /**
     * Default configuration constants
     */
    const int DEFAULT_MAX_RETRIES = 3;
    const int DEFAULT_RETRY_DELAY_BASE = 2;
    const int DEFAULT_TIMEOUT = 120;
    const int DEFAULT_BATCH_SIZE = 100;
    const int BACKOFF_MULTIPLIER = 2;

    /**
     * Rate limiting constants
     * WooCommerce REST API typically allows ~25 requests per second
     * We're more conservative to avoid hitting limits
     */
    const int RATE_LIMIT_REQUESTS = 20;      // Max requests per window
    const int RATE_LIMIT_WINDOW = 10;        // Window size in seconds
    const int RATE_LIMIT_PAUSE = 1;          // Pause duration when limit reached (seconds)

    /**
     * Transient key prefix for cross-process rate limit state.
     * Key format: wc_mss_rl_{md5(store_url)}  (42 chars — within WP limits)
     */
    const string RATE_LIMIT_TRANSIENT_PREFIX = 'wc_mss_rl_';

    /**
     * Object-cache group used when a persistent backend (Redis/Memcached) is
     * available. The cache key embeds a per-window bucket so stale data
     * expires automatically when the key rotates.
     */
    const string RATE_LIMIT_CACHE_GROUP = 'wc_mss_rate_limits';

    /**
     * Per-process timestamp cache — fast path, avoids a transient read on every
     * request when the process itself is well within the limit.
     * @var array<string, float[]>
     */
    private static array $request_timestamps = [];

    /**
     * API version
     */
    private string $api_version = 'wc/v3';

    /**
     * Max retry attempts
     */
    private int $max_retries;

    /**
     * Retry delay base in seconds
     */
    private int $retry_delay_base;

    /**
     * Constructor
     *
     * @param string $store_url Store URL
     * @param string $consumer_key Consumer Key
     * @param string $consumer_secret Consumer Secret
     * @param string $auth_method Authentication method (query_string or basic_auth)
     * @param int $timeout Request timeout in seconds (defaults to DEFAULT_TIMEOUT)
     * @param bool $verify_ssl Verify SSL certificate
     */
    public function __construct(
        private string $store_url = '',
        private string $consumer_key = '',
        private string $consumer_secret = '',
        private string $auth_method = 'query_string',
        private ?int $timeout = null,
        private bool $verify_ssl = true,
        private string $wp_username = '',
        private string $wp_app_password = '',
    ) {
        $this->store_url = rtrim($this->store_url, '/');
        $this->timeout = $this->timeout ?? self::DEFAULT_TIMEOUT;
        $this->max_retries = self::DEFAULT_MAX_RETRIES;
        $this->retry_delay_base = self::DEFAULT_RETRY_DELAY_BASE;
    }

    /**
     * Build a client for a store from its stored config array.
     *
     * auth_method is a global setting (Settings > Authentication Method) —
     * there is no per-store override field in the Add/Edit Store admin form —
     * so it's read from WC_Multi_Store_Settings here rather than from $config.
     *
     * @param string $store_url Store URL
     * @param array $config Store config: consumer_key/consumer_secret, optionally
     *                       wp_username/wp_app_password (used only for the
     *                       WP-core media-upload endpoint)
     * @return self
     */
    public static function for_store(string $store_url, array $config): self {
        return new self(
            $store_url,
            $config['consumer_key'] ?? '',
            $config['consumer_secret'] ?? '',
            WC_Multi_Store_Settings::get('auth_method', 'basic_auth'),
            null,
            true,
            $config['wp_username'] ?? '',
            $config['wp_app_password'] ?? '',
        );
    }

    /**
     * Sanitize error message to remove potential sensitive data like API keys
     *
     * @param string $message Error message
     * @return string Sanitized message
     */
    private function sanitize_error_message(string $message): string {
        // Redact anything that looks like an API key (16+ alphanumeric characters)
        $message = preg_replace('/\b[a-zA-Z0-9_]{16,}\b/', '[REDACTED]', $message);

        // Redact URLs with credentials
        $message = preg_replace('/consumer_key=[^&\s]+/', 'consumer_key=[REDACTED]', $message);
        $message = preg_replace('/consumer_secret=[^&\s]+/', 'consumer_secret=[REDACTED]', $message);

        // Redact Basic Auth headers
        $message = preg_replace('/Basic\s+[A-Za-z0-9+\/=]+/', 'Basic [REDACTED]', $message);

        return $message;
    }

    /**
     * Build API endpoint URL
     *
     * @param string $endpoint Endpoint path
     * @return string Full URL
     */
    private function build_url(string $endpoint): string {
        $endpoint = ltrim($endpoint, '/');
        $url = $this->store_url . '/wp-json/' . $this->api_version . '/' . $endpoint;

        // Add authentication to query string if needed
        if ($this->auth_method === 'query_string') {
            $url = add_query_arg([
                'consumer_key' => $this->consumer_key,
                'consumer_secret' => $this->consumer_secret,
            ], $url);
        }

        return $url;
    }

    /**
     * Get request headers
     *
     * @return array Headers
     */
    private function get_headers(): array {
        $headers = [
            'Content-Type' => 'application/json',
            'Connection' => 'keep-alive', // Reuse TCP connections for better performance
        ];

        // Add basic auth header if needed
        if ($this->auth_method === 'basic_auth') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        }

        return $headers;
    }

    /**
     * Track API request for usage statistics
     *
     * @param string $endpoint Endpoint path
     * @param string $method HTTP method
     * @param float $start_time Request start time
     * @param mixed $response Response data
     * @param int|null $request_size Request body size
     */
    private function track_request(string $endpoint, string $method, float $start_time, mixed $response, ?int $request_size = null): void {
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000); // Convert to ms

        $result = [
            'response_time' => $response_time,
            'request_size' => $request_size,
            'response_size' => null,
            'success' => true,
            'status_code' => null,
            'error_message' => null,
        ];

        if (is_wp_error($response)) {
            $result['success'] = false;
            $result['error_message'] = $this->sanitize_error_message($response->get_error_message());
            $error_data = $response->get_error_data();
            if (is_array($error_data) && isset($error_data['status'])) {
                $result['status_code'] = $error_data['status'];
            }
        } else {
            $result['success'] = true;
            $result['status_code'] = 200;
            $result['response_size'] = strlen(json_encode($response));
        }

        // Fire the tracking action
        do_action('wc_mss_api_request', $this->store_url, $endpoint, $method, $result);
    }

    /**
     * Check and enforce rate limit before making a request.
     *
     * Two-layer sliding window:
     *   1. In-memory (this process) — zero-overhead fast path.
     *   2. Transient (shared across PHP-FPM workers) — cross-process visibility.
     *
     * The two layers are merged on each check: the union of in-memory timestamps
     * and the transient value gives each worker full visibility into all recent
     * requests, regardless of which worker made them.
     *
     * Race-condition note: the transient read-modify-write is not atomic (no
     * Redis MULTI/EXEC or MySQL SELECT … FOR UPDATE). In the worst case, a burst
     * of concurrent workers may undercount by up to (pool_size − 1) requests
     * within a single window.  For the typical small-store PHP-FPM pool (≤ 20
     * workers) this is acceptable and vastly better than the previous per-process
     * approach which provided zero cross-process protection.
     */
    private function enforce_rate_limit(): void {
        $store_key     = md5($this->store_url);
        $transient_key = self::RATE_LIMIT_TRANSIENT_PREFIX . $store_key;
        $now           = microtime(true);

        $wait_time = $this->sliding_window_record($store_key, $transient_key, $now);

        if ($wait_time > 0.0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Rate limit reached for %s (%d/%d requests in %ds window), waiting %.2fs',
                $this->store_url,
                self::RATE_LIMIT_REQUESTS,
                self::RATE_LIMIT_REQUESTS,
                self::RATE_LIMIT_WINDOW,
                $wait_time
            ), 'debug');

            usleep((int)($wait_time * 1_000_000));

            // Re-record after the wait with a fresh timestamp.
            $this->sliding_window_record($store_key, $transient_key, microtime(true));
        }
    }

    /**
     * Merge in-memory + transient timestamps, prune the window, check the limit,
     * and — if not over limit — record the current request.
     *
     * When a persistent object cache is available (Redis/Memcached), takes a
     * fast path that uses wp_cache_add + wp_cache_incr — atomic, O(1), and
     * with zero wp_options I/O. Otherwise falls back to the transient-backed
     * sliding window.
     *
     * @param string $store_key     md5 of store URL (in-memory array key)
     * @param string $transient_key WordPress transient key
     * @param float  $now           Current microtime
     * @return float Seconds to wait (0.0 = no wait needed, request was recorded)
     */
    private function sliding_window_record(string $store_key, string $transient_key, float $now): float {
        // Fast path: atomic counter in a persistent object cache, no DB I/O.
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return $this->cache_window_record($store_key, $now);
        }

        return $this->transient_window_record($store_key, $transient_key, $now);
    }

    /**
     * Fixed-window counter backed by the persistent object cache.
     *
     * The bucket key rotates every RATE_LIMIT_WINDOW seconds; the cache
     * backend's atomic incr() avoids the read-modify-write race that affects
     * the transient path. Trade-off: traffic can burst at bucket boundaries,
     * which is acceptable for "be polite to remote" usage.
     *
     * @param string $store_key md5 of store URL
     * @param float  $now       Current microtime
     * @return float Seconds to wait (0.0 = no wait needed)
     */
    private function cache_window_record(string $store_key, float $now): float {
        $window    = self::RATE_LIMIT_WINDOW;
        $bucket    = (int) floor($now / $window);
        $cache_key = 'rl_' . $store_key . '_' . $bucket;

        wp_cache_add($cache_key, 0, self::RATE_LIMIT_CACHE_GROUP, $window + 2);
        $count = wp_cache_incr($cache_key, 1, self::RATE_LIMIT_CACHE_GROUP);

        // Non-persistent backend (incr unsupported / counter lost): allow the
        // request rather than wedging the caller. The transient path would
        // catch a real burst on the next call.
        if ($count === false) {
            return 0.0;
        }

        if ($count > self::RATE_LIMIT_REQUESTS) {
            $bucket_end = (float) (($bucket + 1) * $window);
            return max((float) self::RATE_LIMIT_PAUSE, $bucket_end - $now + 0.1);
        }

        return 0.0;
    }

    /**
     * Transient-backed sliding window (used when no persistent object cache).
     *
     * Known limitation: the transient read-modify-write is not atomic across
     * PHP-FPM workers. Under concurrent load a worker may undercount requests
     * by up to (pool_size - 1). Acceptable for typical store sizes.
     *
     * @param string $store_key     md5 of store URL
     * @param string $transient_key WordPress transient key
     * @param float  $now           Current microtime
     * @return float Seconds to wait (0.0 = no wait needed, request was recorded)
     */
    private function transient_window_record(string $store_key, string $transient_key, float $now): float {
        $window_start = $now - self::RATE_LIMIT_WINDOW;

        // In-memory timestamps for this process (may include entries other
        // processes have never seen).
        $local = self::$request_timestamps[$store_key] ?? [];

        // Persisted timestamps written by other (or this) process earlier.
        $persisted = get_transient($transient_key);
        $persisted = is_array($persisted) ? $persisted : [];

        // Merge, deduplicate (defending against the same timestamp appearing in
        // both layers after this process's last write), and prune. Skip sort —
        // we only need the oldest, which min() computes in O(N).
        $merged = array_values(array_unique(array_merge($local, $persisted)));
        $merged = array_values(array_filter($merged, fn($ts) => $ts > $window_start));

        if (count($merged) >= self::RATE_LIMIT_REQUESTS) {
            return max((float) self::RATE_LIMIT_PAUSE, (min($merged) + self::RATE_LIMIT_WINDOW) - $now + 0.1);
        }

        // Record the request in both layers.
        $merged[] = $now;

        self::$request_timestamps[$store_key] = $merged;
        set_transient($transient_key, $merged, self::RATE_LIMIT_WINDOW + 2);

        return 0.0;
    }

    /**
     * Get store URL
     *
     * @return string Store URL
     */
    public function get_store_url(): string {
        return $this->store_url;
    }

    /**
     * Get current rate limit status for debugging.
     * Reflects the merged (cross-process) view, same as enforce_rate_limit().
     *
     * @return array Rate limit info
     */
    public function get_rate_limit_status(): array {
        $store_key = md5($this->store_url);
        $now       = microtime(true);

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $bucket    = (int) floor($now / self::RATE_LIMIT_WINDOW);
            $cache_key = 'rl_' . $store_key . '_' . $bucket;
            $count     = (int) wp_cache_get($cache_key, self::RATE_LIMIT_CACHE_GROUP);
        } else {
            $transient_key = self::RATE_LIMIT_TRANSIENT_PREFIX . $store_key;
            $window_start  = $now - self::RATE_LIMIT_WINDOW;

            $local     = self::$request_timestamps[$store_key] ?? [];
            $persisted = get_transient($transient_key);
            $persisted = is_array($persisted) ? $persisted : [];

            $merged = array_values(array_unique(array_merge($local, $persisted)));
            $recent = array_filter($merged, fn($ts) => $ts > $window_start);
            $count  = count($recent);
        }

        return [
            'requests_in_window' => $count,
            'max_requests'       => self::RATE_LIMIT_REQUESTS,
            'window_seconds'     => self::RATE_LIMIT_WINDOW,
            'available'          => max(0, self::RATE_LIMIT_REQUESTS - $count),
        ];
    }

    /**
     * Make GET request
     *
     * @param string $endpoint Endpoint path
     * @param array $params Query parameters
     * @return array|WP_Error Response array or WP_Error
     */
    private function get(string $endpoint, array $params = []): array|\WP_Error {
        $this->enforce_rate_limit();
        $start_time = microtime(true);

        $response = $this->execute_with_retry(function() use ($endpoint, $params) {
            $url = $this->build_url($endpoint);

            // Add additional query parameters
            if (!empty($params)) {
                $url = add_query_arg($params, $url);
            }

            $args = [
                'timeout' => $this->timeout,
                'headers' => $this->get_headers(),
                'sslverify' => $this->verify_ssl,
            ];

            $response = wp_remote_get($url, $args);

            return $this->process_response($response);
        });

        $this->track_request($endpoint, 'GET', $start_time, $response);

        return $response;
    }

    /**
     * Make POST request
     *
     * @param string $endpoint Endpoint path
     * @param array $data Request body data
     * @return array|WP_Error Response array or WP_Error
     */
    private function post(string $endpoint, array $data = []): array|\WP_Error {
        $this->enforce_rate_limit();
        $start_time = microtime(true);
        $body = json_encode($data);
        $request_size = strlen($body);

        $response = $this->execute_with_retry(function() use ($endpoint, $body) {
            $url = $this->build_url($endpoint);

            $args = [
                'timeout' => $this->timeout,
                'headers' => $this->get_headers(),
                'sslverify' => $this->verify_ssl,
                'body' => $body,
            ];

            $response = wp_remote_post($url, $args);

            return $this->process_response($response);
        });

        $this->track_request($endpoint, 'POST', $start_time, $response, $request_size);

        return $response;
    }

    /**
     * Make PUT request
     *
     * @param string $endpoint Endpoint path
     * @param array $data Request body data
     * @return array|WP_Error Response array or WP_Error
     */
    private function put(string $endpoint, array $data = []): array|\WP_Error {
        $this->enforce_rate_limit();
        $start_time = microtime(true);
        $body = json_encode($data);
        $request_size = strlen($body);

        $response = $this->execute_with_retry(function() use ($endpoint, $body) {
            $url = $this->build_url($endpoint);

            $args = [
                'method' => 'PUT',
                'timeout' => $this->timeout,
                'headers' => $this->get_headers(),
                'sslverify' => $this->verify_ssl,
                'body' => $body,
            ];

            $response = wp_remote_request($url, $args);

            return $this->process_response($response);
        });

        $this->track_request($endpoint, 'PUT', $start_time, $response, $request_size);

        return $response;
    }

    /**
     * Make DELETE request
     *
     * @param string $endpoint Endpoint path
     * @param array $params Query parameters
     * @return array|WP_Error Response array or WP_Error
     */
    private function delete(string $endpoint, array $params = []): array|\WP_Error {
        $this->enforce_rate_limit();
        $start_time = microtime(true);

        $response = $this->execute_with_retry(function() use ($endpoint, $params) {
            $url = $this->build_url($endpoint);

            if (!empty($params)) {
                $url = add_query_arg($params, $url);
            }

            $args = [
                'method' => 'DELETE',
                'timeout' => $this->timeout,
                'headers' => $this->get_headers(),
                'sslverify' => $this->verify_ssl,
            ];

            return $this->process_response(wp_remote_request($url, $args));
        });

        $this->track_request($endpoint, 'DELETE', $start_time, $response);

        return $response;
    }

    /**
     * Process API response
     *
     * @param array|WP_Error $response Response from wp_remote_*
     * @return array|WP_Error Processed response or WP_Error
     */
    private function process_response(array|\WP_Error $response): array|\WP_Error {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Check for HTTP errors first, before JSON parsing
        // This handles cases where servers return HTML error pages (e.g., 403 from firewalls/CDNs)
        if ($code >= 400) {
            $error_code = $this->categorize_error($code, $response);

            // Try to parse JSON to get a proper error message from the API
            $data = json_decode($body, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['message'])) {
                // Valid JSON with error message from WooCommerce API
                $message = $data['message'];
            } else {
                // Non-JSON response (HTML error page, etc.) - provide meaningful error based on status
                $message = $this->get_http_error_message($code, $body);
                $data = null;
            }

            return new WP_Error(
                $error_code,
                $message,
                [
                    'status' => $code,
                    'data' => $data,
                    'body_preview' => substr($body, 0, 200),
                ]
            );
        }

        // For success responses, parse JSON
        $data = json_decode($body, true);

        // Check for JSON decode errors on success responses
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'json_decode_error',
                sprintf(
                    'Invalid JSON response: %s (HTTP %d)',
                    json_last_error_msg(),
                    $code
                ),
                [
                    'status' => $code,
                    'body_preview' => substr($body, 0, 200),
                ]
            );
        }

        return $data;
    }

    /**
     * Get a human-readable error message based on HTTP status code
     *
     * @param int $code HTTP status code
     * @param string $body Response body
     * @return string Human-readable error message
     */
    private function get_http_error_message(int $code, string $body): string {
        $message = match ($code) {
            400 => 'Bad request - the server could not understand the request',
            401 => 'Unauthorized - invalid or missing API credentials',
            403 => 'Forbidden - access denied. Check API credentials and permissions, or the server may be blocking requests (firewall/CDN)',
            404 => 'Not found - the requested resource does not exist',
            405 => 'Method not allowed',
            408 => 'Request timeout',
            429 => 'Too many requests - rate limit exceeded',
            500 => 'Internal server error',
            502 => 'Bad gateway - the server received an invalid response',
            503 => 'Service unavailable - the server is temporarily overloaded or under maintenance',
            504 => 'Gateway timeout',
            default => null,
        };

        if ($message !== null) {
            return sprintf('%s (HTTP %d)', $message, $code);
        }

        return sprintf('HTTP error %d', $code);
    }

    /**
     * Categorize error based on HTTP status code and response
     *
     * @param int $code HTTP status code
     * @param array|WP_Error $response Response
     * @return string Error code
     */
    private function categorize_error(int $code, array|\WP_Error $response): string {
        // Network/connection errors (always retryable)
        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            if (in_array($error_code, ['http_request_failed', 'connect_error', 'timeout'])) {
                return 'network_error';
            }
        }

        // Categorize by HTTP status code
        return match (true) {
            $code >= 500 => 'server_error',             // Retryable
            $code === 429 => 'rate_limit',              // Retryable with delay
            $code === 408 => 'timeout',                 // Retryable
            $code === 401, $code === 403 => 'auth_error', // Not retryable
            $code === 404 => 'not_found',               // Not retryable
            $code >= 400 => 'client_error',             // Not retryable
            default => 'api_error',
        };
    }

    /**
     * Check if error is transient (retryable)
     *
     * @param mixed $error Error object
     * @return bool True if error is transient
     */
    private function is_transient_error(mixed $error): bool {
        if (!is_wp_error($error)) {
            return false;
        }

        $transient_codes = [
            'network_error',
            'server_error',
            'rate_limit',
            'timeout',
            'http_request_failed',
        ];

        return in_array($error->get_error_code(), $transient_codes);
    }

    /**
     * Execute request with retry logic
     *
     * @param callable $callback Request callback function
     * @param array $args Callback arguments
     * @return array|WP_Error Response or error
     */
    private function execute_with_retry(callable $callback, array $args = []): array|\WP_Error {
        $attempt = 0;

        while ($attempt < $this->max_retries) {
            $attempt++;

            $response = $callback(...$args);

            // Success - return response
            if (!is_wp_error($response)) {
                return $response;
            }

            // Check if error is retryable
            if (!$this->is_transient_error($response)) {
                // Permanent error - don't retry
                return $response;
            }

            // Last attempt - return error
            if ($attempt >= $this->max_retries) {
                // Add retry info to error
                $error_data = $response->get_error_data();
                $error_data['retry_attempts'] = $attempt;
                return new WP_Error(
                    $response->get_error_code(),
                    $response->get_error_message() . ' (after ' . $attempt . ' attempts)',
                    $error_data
                );
            }

            // Calculate exponential backoff delay
            $delay = $this->retry_delay_base * pow(self::BACKOFF_MULTIPLIER, $attempt - 1);

            // Add jitter (random 0-1 seconds) to prevent thundering herd
            $jitter = mt_rand(0, 1000) / 1000;
            $total_delay = $delay + $jitter;

            // Log retry attempt with sanitized message to avoid exposing credentials
            WC_Multi_Store_Logger::write(sprintf(
                'Retry attempt %d/%d after %0.2fs for error: %s',
                $attempt,
                $this->max_retries,
                $total_delay,
                $this->sanitize_error_message($response->get_error_message())
            ), 'warning');

            // Sleep before retry
            usleep($total_delay * 1000000);
        }

        return new WP_Error('max_retries_exceeded', 'Maximum retry attempts exceeded');
    }

    // ========================================
    // Product Operations
    // ========================================

    /**
     * Get products
     *
     * @param string $search Search term (SKU or slug)
     * @param string $search_type Search type ('sku' or 'slug')
     * @param array $params Additional parameters
     * @return array|WP_Error Products array or WP_Error
     */
    public function get_products(string $search = '', string $search_type = 'sku', array $params = []): array|\WP_Error {
        $default_params = [
            'per_page' => self::DEFAULT_BATCH_SIZE,
        ];

        if (!empty($search)) {
            if ($search_type === 'sku') {
                $default_params['sku'] = $search;
            } else {
                $default_params['slug'] = $search;
            }
        }

        $params = wp_parse_args($params, $default_params);

        return $this->get('products', $params);
    }

    /**
     * Get single product
     *
     * @param int $product_id Product ID
     * @return array|WP_Error Product data or WP_Error
     */
    public function get_product(int $product_id): array|\WP_Error {
        return $this->get('products/' . $product_id);
    }

    /**
     * Create product
     *
     * @param array $data Product data
     * @return array|WP_Error Created product data or WP_Error
     */
    public function create_product(array $data): array|\WP_Error {
        return $this->post('products', $data);
    }

    /**
     * Update product
     *
     * @param int $product_id Product ID
     * @param array $data Product data
     * @return array|WP_Error Updated product data or WP_Error
     */
    public function update_product(int $product_id, array $data): array|\WP_Error {
        return $this->put('products/' . $product_id, $data);
    }

    /**
     * Delete product
     *
     * @param int $product_id Product ID
     * @param bool $force Force delete (bypass trash)
     * @return array|WP_Error Deleted product data or WP_Error
     */
    public function delete_product(int $product_id, bool $force = false): array|\WP_Error {
        $params = ['force' => $force];
        return $this->delete('products/' . $product_id, $params);
    }

    // ========================================
    // Product Variation Operations
    // ========================================

    /**
     * Get product variations
     *
     * @param int $product_id Product ID
     * @param array $params Additional parameters
     * @return array|WP_Error Variations array or WP_Error
     */
    public function get_product_variations(int $product_id, array $params = []): array|\WP_Error {
        $default_params = [
            'per_page' => self::DEFAULT_BATCH_SIZE,
        ];

        $params = wp_parse_args($params, $default_params);

        return $this->get('products/' . $product_id . '/variations', $params);
    }

    /**
     * Get single product variation
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @return array|WP_Error Variation data or WP_Error
     */
    public function get_product_variation(int $product_id, int $variation_id): array|\WP_Error {
        return $this->get('products/' . $product_id . '/variations/' . $variation_id);
    }

    /**
     * Create product variation
     *
     * @param int $product_id Product ID
     * @param array $data Variation data
     * @return array|WP_Error Created variation data or WP_Error
     */
    public function create_product_variation(int $product_id, array $data): array|\WP_Error {
        return $this->post('products/' . $product_id . '/variations', $data);
    }

    /**
     * Update product variation
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @param array $data Variation data
     * @return array|WP_Error Updated variation data or WP_Error
     */
    public function update_product_variation(int $product_id, int $variation_id, array $data): array|\WP_Error {
        return $this->put('products/' . $product_id . '/variations/' . $variation_id, $data);
    }

    /**
     * Delete product variation
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @param bool $force Force delete
     * @return array|WP_Error Deleted variation data or WP_Error
     */
    public function delete_product_variation(int $product_id, int $variation_id, bool $force = false): array|\WP_Error {
        $params = ['force' => $force];
        return $this->delete('products/' . $product_id . '/variations/' . $variation_id, $params);
    }

    // ========================================
    // Image Upload Operations
    // ========================================

    /**
     * Upload an image to the remote store via WordPress REST API media endpoint
     *
     * Reads the image file from local disk and sends it as binary data to
     * POST /wp-json/wp/v2/media. No custom endpoint or plugin needed on the
     * receiving side — just standard WordPress REST API.
     *
     * Authentication: WooCommerce consumer key/secret — WC_REST_Authentication
     * hooks into determine_current_user for ALL REST routes, so the API key
     * user is authenticated automatically.
     *
     * @param array $image_info {file_path|url, filename, mime_type} from WC_Multi_Store_Image_Proxy::get_image_data()
     * @return array|WP_Error WordPress media response with 'id' on success
     */
    public function upload_image(array $image_info): array|\WP_Error {
        $this->enforce_rate_limit();
        $start_time = microtime(true);

        if (isset($image_info['file_path'])) {
            if (!file_exists($image_info['file_path']) || !is_readable($image_info['file_path'])) {
                return new \WP_Error('file_read_error', 'Could not read image file: ' . $image_info['file_path']);
            }
            $file_contents = file_get_contents($image_info['file_path']);
            if ($file_contents === false) {
                return new \WP_Error('file_read_error', 'Could not read image file: ' . $image_info['file_path']);
            }
        } else {
            // Image stored externally — fetch via HTTP
            $remote = wp_remote_get($image_info['url'], ['timeout' => 30, 'sslverify' => $this->verify_ssl]);
            if (is_wp_error($remote)) {
                return new \WP_Error('image_fetch_error', 'Could not fetch image from URL: ' . $image_info['url']);
            }
            $content_type = wp_remote_retrieve_header($remote, 'content-type');
            $allowed_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
            if (!in_array(strtok($content_type, ';'), $allowed_image_types, true)) {
                return new \WP_Error('invalid_image_type', 'Remote URL did not return a valid image (content-type: ' . $content_type . '): ' . $image_info['url']);
            }
            $file_contents = wp_remote_retrieve_body($remote);
            if ($file_contents === '') {
                return new \WP_Error('image_fetch_empty', 'Empty response fetching image from URL: ' . $image_info['url']);
            }
        }

        $request_size = strlen($file_contents);

        $url = $this->store_url . '/wp-json/wp/v2/media';

        // wp/v2/media is a WordPress core endpoint — requires WordPress credentials.
        // Use Application Password (username:app_password) if configured, as WooCommerce
        // API keys (ck_xxx:cs_xxx) are not valid WordPress usernames and will be rejected.
        if (!empty($this->wp_username) && !empty($this->wp_app_password)) {
            $media_auth = base64_encode($this->wp_username . ':' . $this->wp_app_password);
        } else {
            $media_auth = base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        }

        $headers = [
            'Content-Type' => $image_info['mime_type'],
            'Content-Disposition' => 'attachment; filename="' . $image_info['filename'] . '"',
            'Authorization' => 'Basic ' . $media_auth,
        ];

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => $headers,
            'sslverify' => $this->verify_ssl,
            'body' => $file_contents,
        ]);

        $result = $this->process_response($response);
        $this->track_request('wp/v2/media', 'POST', $start_time, $result, $request_size);

        return $result;
    }

    // ========================================
    // Category Operations
    // ========================================

    /**
     * Get categories
     *
     * @param string $slug Category slug
     * @param array $params Additional parameters
     * @return array|WP_Error Categories array or WP_Error
     */
    public function get_categories(string $slug = '', array $params = []): array|\WP_Error {
        $default_params = [
            'per_page' => self::DEFAULT_BATCH_SIZE,
        ];

        if (!empty($slug)) {
            $default_params['slug'] = $slug;
        }

        $params = wp_parse_args($params, $default_params);

        return $this->get('products/categories', $params);
    }

    /**
     * Get single category
     *
     * @param int $category_id Category ID
     * @return array|WP_Error Category data or WP_Error
     */
    public function get_category(int $category_id): array|\WP_Error {
        return $this->get('products/categories/' . $category_id);
    }

    /**
     * Create category
     *
     * @param array $data Category data
     * @return array|WP_Error Created category data or WP_Error
     */
    public function create_category(array $data): array|\WP_Error {
        return $this->post('products/categories', $data);
    }

    /**
     * Update category
     *
     * @param int $category_id Category ID
     * @param array $data Category data
     * @return array|WP_Error Updated category data or WP_Error
     */
    public function update_category(int $category_id, array $data): array|\WP_Error {
        return $this->put('products/categories/' . $category_id, $data);
    }

    /**
     * Delete category
     *
     * @param int $category_id Category ID
     * @param bool $force Force delete
     * @return array|WP_Error Deleted category data or WP_Error
     */
    public function delete_category(int $category_id, bool $force = false): array|\WP_Error {
        $params = ['force' => $force];
        return $this->delete('products/categories/' . $category_id, $params);
    }

    // ========================================
    // Tag Operations
    // ========================================

    /**
     * Get tags
     *
     * @param string $slug Tag slug
     * @param array $params Additional parameters
     * @return array|WP_Error Tags array or WP_Error
     */
    public function get_tags(string $slug = '', array $params = []): array|\WP_Error {
        $default_params = [
            'per_page' => self::DEFAULT_BATCH_SIZE,
        ];

        if (!empty($slug)) {
            $default_params['slug'] = $slug;
        }

        $params = wp_parse_args($params, $default_params);

        return $this->get('products/tags', $params);
    }

    /**
     * Get single tag
     *
     * @param int $tag_id Tag ID
     * @return array|WP_Error Tag data or WP_Error
     */
    public function get_tag(int $tag_id): array|\WP_Error {
        return $this->get('products/tags/' . $tag_id);
    }

    /**
     * Create tag
     *
     * @param array $data Tag data
     * @return array|WP_Error Created tag data or WP_Error
     */
    public function create_tag(array $data): array|\WP_Error {
        return $this->post('products/tags', $data);
    }

    /**
     * Update tag
     *
     * @param int $tag_id Tag ID
     * @param array $data Tag data
     * @return array|WP_Error Updated tag data or WP_Error
     */
    public function update_tag(int $tag_id, array $data): array|\WP_Error {
        return $this->put('products/tags/' . $tag_id, $data);
    }

    /**
     * Delete tag
     *
     * @param int $tag_id Tag ID
     * @param bool $force Force delete
     * @return array|WP_Error Deleted tag data or WP_Error
     */
    public function delete_tag(int $tag_id, bool $force = false): array|\WP_Error {
        $params = ['force' => $force];
        return $this->delete('products/tags/' . $tag_id, $params);
    }

    // ========================================
    // Attribute Operations
    // ========================================

    /**
     * Get global product attributes (e.g. Color, Size)
     *
     * @param array $params Additional parameters
     * @return array|WP_Error Attributes array or WP_Error
     */
    public function get_attributes(array $params = []): array|\WP_Error {
        $default_params = [
            'per_page' => self::DEFAULT_BATCH_SIZE,
        ];

        $params = wp_parse_args($params, $default_params);

        return $this->get('products/attributes', $params);
    }

    // ========================================
    // Batch Term Operations
    // ========================================

    /**
     * Batch create/update/delete categories
     *
     * @param array $batch Batch data with 'create', 'update', 'delete' keys
     * @return array|WP_Error Batch result or WP_Error
     */
    public function batch_categories(array $batch): array|\WP_Error {
        return self::validate_batch($batch) ?? $this->post('products/categories/batch', $batch);
    }

    /**
     * Batch create/update/delete tags
     *
     * @param array $batch Batch data with 'create', 'update', 'delete' keys
     * @return array|WP_Error Batch result or WP_Error
     */
    public function batch_tags(array $batch): array|\WP_Error {
        return self::validate_batch($batch) ?? $this->post('products/tags/batch', $batch);
    }

    /**
     * Validate a batch payload before sending: must be a non-empty array,
     * and its combined create/update/delete items must fit the WooCommerce
     * batch endpoint's 100-item limit. Shared by all batch_*() methods.
     *
     * @param array $batch Batch data with 'create', 'update', 'delete' keys
     * @return WP_Error|null Error if invalid, null if the batch is valid
     */
    private static function validate_batch(array $batch): ?WP_Error {
        if (empty($batch) || !is_array($batch)) {
            return new WP_Error('invalid_batch', 'Invalid batch data');
        }

        $total = 0;
        $total += isset($batch['create']) ? count($batch['create']) : 0;
        $total += isset($batch['update']) ? count($batch['update']) : 0;
        $total += isset($batch['delete']) ? count($batch['delete']) : 0;

        if ($total > 100) {
            return new WP_Error('batch_too_large', 'Batch size exceeds 100 items');
        }

        return null;
    }

    // ========================================
    // Order Operations (Optional - for future use)
    // ========================================

    /**
     * Get orders
     *
     * @param array $params Query parameters
     * @return array|WP_Error Orders array or WP_Error
     */
    public function get_orders(array $params = []): array|\WP_Error {
        $default_params = [
            'per_page' => self::DEFAULT_BATCH_SIZE,
        ];

        $params = wp_parse_args($params, $default_params);

        return $this->get('orders', $params);
    }

    /**
     * Get single order
     *
     * @param int $order_id Order ID
     * @return array|WP_Error Order data or WP_Error
     */
    public function get_order(int $order_id): array|\WP_Error {
        return $this->get('orders/' . $order_id);
    }

    // ========================================
    // Batch Operations
    // ========================================

    /**
     * Batch update products
     *
     * @param array $updates Array of product updates (max 100)
     * @return array|WP_Error Batch results or WP_Error
     */
    public function batch_update_products(array $updates): array|\WP_Error {
        if (empty($updates) || !is_array($updates)) {
            return new WP_Error('invalid_batch', 'Invalid batch data');
        }

        // WooCommerce batch endpoint accepts max 100 items
        $updates = array_slice($updates, 0, 100);

        return $this->post('products/batch', ['update' => $updates]);
    }

    /**
     * Batch create products
     *
     * @param array $creates Array of product data (max 100)
     * @return array|WP_Error Batch results or WP_Error
     */
    public function batch_create_products(array $creates): array|\WP_Error {
        if (empty($creates) || !is_array($creates)) {
            return new WP_Error('invalid_batch', 'Invalid batch data');
        }

        // WooCommerce batch endpoint accepts max 100 items
        $creates = array_slice($creates, 0, 100);

        return $this->post('products/batch', ['create' => $creates]);
    }

    /**
     * Batch delete products
     *
     * @param array $product_ids Array of product IDs to delete (max 100)
     * @param bool $force Force delete
     * @return array|WP_Error Batch results or WP_Error
     */
    public function batch_delete_products(array $product_ids, bool $force = false): array|\WP_Error {
        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_Error('invalid_batch', 'Invalid batch data');
        }

        // WooCommerce batch endpoint accepts max 100 items
        $product_ids = array_slice($product_ids, 0, 100);

        $deletes = array_map(fn($id) => ['id' => $id], $product_ids);

        $params = ['delete' => $deletes];
        if ($force) {
            foreach ($params['delete'] as &$item) {
                $item['force'] = true;
            }
        }

        return $this->post('products/batch', $params);
    }

    /**
     * Batch operations (mixed create/update/delete)
     *
     * @param array $batch Batch data with 'create', 'update', 'delete' keys
     * @return array|WP_Error Batch results or WP_Error
     */
    public function batch_products(array $batch): array|\WP_Error {
        return self::validate_batch($batch) ?? $this->post('products/batch', $batch);
    }

    /**
     * Batch operations for product variations (mixed create/update/delete)
     * Reduces API calls by up to 50x when syncing multiple variations
     *
     * @param int $product_id Parent product ID
     * @param array $batch Batch data with 'create', 'update', 'delete' keys
     * @return array|WP_Error Batch results or WP_Error
     */
    public function batch_product_variations(int $product_id, array $batch): array|\WP_Error {
        return self::validate_batch($batch) ?? $this->post('products/' . $product_id . '/variations/batch', $batch);
    }

    // ========================================
    // Paginated Retrieval
    // ========================================

    /**
     * Get all products with automatic pagination
     *
     * Follows the X-WP-TotalPages header to retrieve all pages.
     * Use this when you need the complete product list (e.g., verification).
     *
     * @param array $params Query parameters (per_page defaults to 100)
     * @param int $max_pages Safety limit on pages to fetch (0 = unlimited)
     * @return array|WP_Error All products or WP_Error
     */
    public function get_all_products(array $params = [], int $max_pages = 50): array|\WP_Error {
        return $this->get_all_paginated('products', $params, $max_pages);
    }

    /**
     * Stream each page of a paginated WooCommerce REST API endpoint to a callback.
     *
     * Lets callers process pages one at a time without ever holding the full
     * accumulated result set in memory. The callback receives ($page_items, $page_number)
     * and may return false to stop pagination early.
     *
     * @param string   $endpoint    API endpoint
     * @param array    $params      Query parameters
     * @param callable $on_page     fn(array $items, int $page) — return false to stop
     * @param int      $max_pages   Safety limit (0 = unlimited)
     * @return WP_Error|null        WP_Error on transport / HTTP / JSON failure, null on success
     */
    private function paginate_each(string $endpoint, array $params, callable $on_page, int $max_pages = 50): ?\WP_Error {
        $params['per_page'] = $params['per_page'] ?? self::DEFAULT_BATCH_SIZE;
        $params['page'] = 1;

        $pages_fetched = 0;

        do {
            $this->enforce_rate_limit();
            $start_time = microtime(true);

            $url = $this->build_url($endpoint);
            $url = add_query_arg($params, $url);

            $raw_response = wp_remote_get($url, [
                'timeout' => $this->timeout,
                'headers' => $this->get_headers(),
                'sslverify' => $this->verify_ssl,
            ]);

            if (is_wp_error($raw_response)) {
                $this->track_request($endpoint, 'GET', $start_time, $raw_response);
                return $raw_response;
            }

            $code = wp_remote_retrieve_response_code($raw_response);
            if ($code >= 400) {
                $processed = $this->process_response($raw_response);
                $this->track_request($endpoint, 'GET', $start_time, $processed);
                return $processed instanceof \WP_Error ? $processed : new \WP_Error('http_error', 'HTTP ' . $code);
            }

            $body = wp_remote_retrieve_body($raw_response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new \WP_Error('json_decode_error', 'Invalid JSON on page ' . $params['page']);
            }

            $this->track_request($endpoint, 'GET', $start_time, $data);

            $current_page = (int) $params['page'];
            $continue = $on_page($data, $current_page);
            $pages_fetched++;

            if ($continue === false) {
                break;
            }

            $total_pages = (int) wp_remote_retrieve_header($raw_response, 'x-wp-totalpages');
            $params['page']++;

            $has_more = !empty($data) && (
                ($total_pages > 0 && $params['page'] <= $total_pages) ||
                ($total_pages === 0 && count($data) >= $params['per_page'])
            );

            if ($max_pages > 0 && $pages_fetched >= $max_pages) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Pagination safety limit reached: %d pages fetched for %s',
                    $pages_fetched,
                    $endpoint
                ), 'warning');
                break;
            }
        } while ($has_more);

        return null;
    }

    /**
     * Get all items from a paginated WooCommerce REST API endpoint.
     *
     * Thin wrapper around paginate_each() that accumulates the full result set.
     * Prefer paginate_each() directly when working with large catalogues.
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @param int $max_pages Safety limit (0 = unlimited)
     * @return array|WP_Error All items or WP_Error on first failure
     */
    private function get_all_paginated(string $endpoint, array $params = [], int $max_pages = 50): array|\WP_Error {
        $all_items = [];
        $err = $this->paginate_each(
            $endpoint,
            $params,
            function (array $page) use (&$all_items): void {
                array_push($all_items, ...$page);
            },
            $max_pages
        );

        return $err ?? $all_items;
    }

    /**
     * Stream pages of products through a callback. Public wrapper around paginate_each().
     *
     * Use this when iterating large remote catalogues (10k+ products) to avoid
     * loading the whole list into memory. The callback receives one page at a time;
     * return false to stop early.
     *
     * @param array    $params    Query parameters (per_page defaults to 100)
     * @param callable $on_page   fn(array $items, int $page) — return false to stop
     * @param int      $max_pages Safety limit (0 = unlimited)
     * @return WP_Error|null      WP_Error on first failure, null on success
     */
    public function stream_products(array $params, callable $on_page, int $max_pages = 50): ?\WP_Error {
        return $this->paginate_each('products', $params, $on_page, $max_pages);
    }

    // ========================================
    // Utility Methods
    // ========================================

    /**
     * Test connection to store
     *
     * @return bool|WP_Error True if connection successful, WP_Error otherwise
     */
    public function test_connection(): bool|\WP_Error {
        $result = $this->get('system_status');

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

}
