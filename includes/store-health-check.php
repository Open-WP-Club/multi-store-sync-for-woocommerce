<?php
/**
 * Store Health Check
 * Daily automated health checks for all configured stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Health_Check {

    /**
     * Consecutive failures threshold for alert
     */
    const int FAILURE_THRESHOLD = 3;

    /**
     * Initialize health check
     */
    public function __construct() {
        // Schedule daily health check
        add_action('init', $this->schedule_health_check(...));

        // Hook for health check execution
        add_action('wc_multi_store_health_check', $this->run_health_check(...));

        // AJAX endpoint for manual health check
        add_action('wp_ajax_wc_mss_run_health_check', $this->ajax_run_health_check(...));

        // Hook for single store health check
        add_action('wp_ajax_wc_mss_check_single_store', $this->ajax_check_single_store(...));

        // AJAX endpoint for testing WP Application Password credentials
        add_action('wp_ajax_wc_mss_test_app_password', $this->ajax_test_app_password(...));

        // AS action fired when a store is added or updated
        add_action('wc_multi_store_health_check_single', $this->check_and_update_store(...));
    }

    /**
     * Schedule daily health check
     */
    public function schedule_health_check(): void {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return;
        }

        $hook = 'wc_multi_store_health_check';
        $group = WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP;

        if (!as_next_scheduled_action($hook, [], $group)) {
            as_schedule_recurring_action(time(), DAY_IN_SECONDS, $hook, [], $group);
        }
    }

    /**
     * Unschedule health check (on deactivation)
     */
    public static function unschedule_health_check(): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(
                'wc_multi_store_health_check',
                [],
                WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
            );
        }
    }

    /**
     * Run health check on all stores
     *
     * @return array Health check results
     */
    public function run_health_check(): array {
        $stores = WC_Multi_Store_Settings::get_stores();
        $results = [];

        foreach ($stores as $store_url => $store_config) {
            $result = $this->check_store_health($store_url, $store_config);
            $results[$store_url] = $result;

            // Update store health status
            $this->update_store_health_status($store_url, $result);

            // Track failures and send alerts if threshold exceeded
            $this->track_failures($store_url, $result);

            // Log if there's an issue
            if (!$result['healthy']) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Health check failed for store: %s - %s',
                    $store_url,
                    $result['message']
                ), 'warning');
            }
        }

        // Save overall health check results
        update_option('wc_mss_last_health_check', [
            'timestamp' => current_time('mysql'),
            'results' => $results,
        ]);

        return $results;
    }

    /**
     * Check health of a single store
     *
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @return array Health check result
     */
    private function check_store_health(string $store_url, array $store_config): array {
        $result = [
            'healthy' => false,
            'message' => '',
            'response_time' => 0,
            'checked_at' => current_time('mysql'),
            'checks' => [],
        ];

        $start_time = microtime(true);

        try {
            // Initialize API client
            $api = WC_Multi_Store_API_Client::for_store($store_url, $store_config);

            // Check 1: Basic connectivity
            $result['checks']['connectivity'] = ['status' => 'checking'];
            $response = $api->test_connection();

            $response_time = round((microtime(true) - $start_time) * 1000, 2);
            $result['response_time'] = $response_time;

            if (is_wp_error($response)) {
                $result['checks']['connectivity'] = [
                    'status' => 'fail',
                    'message' => $response->get_error_message(),
                ];
                $result['message'] = $response->get_error_message();
                return $result;
            }

            $result['checks']['connectivity'] = [
                'status' => 'pass',
                'message' => sprintf('Connected in %sms', $response_time),
            ];

            // Check 2: API version compatibility
            $result['checks']['api_version'] = ['status' => 'checking'];
            if (isset($response['routes']) || isset($response['namespace'])) {
                $result['checks']['api_version'] = [
                    'status' => 'pass',
                    'message' => 'WooCommerce REST API v3 available',
                ];
            } else {
                $result['checks']['api_version'] = [
                    'status' => 'warning',
                    'message' => 'API version could not be verified',
                ];
            }

            // Check 3: Response time threshold
            $result['checks']['response_time'] = ['status' => 'checking'];
            if ($response_time < 1000) {
                $result['checks']['response_time'] = [
                    'status' => 'pass',
                    'message' => sprintf('Fast response: %sms', $response_time),
                ];
            } elseif ($response_time < 5000) {
                $result['checks']['response_time'] = [
                    'status' => 'warning',
                    'message' => sprintf('Slow response: %sms', $response_time),
                ];
            } else {
                $result['checks']['response_time'] = [
                    'status' => 'fail',
                    'message' => sprintf('Very slow response: %sms', $response_time),
                ];
            }

            // Check 4: SSL certificate (for HTTPS URLs)
            $result['checks']['ssl'] = ['status' => 'checking'];
            $parsed_url = wp_parse_url($store_url);
            if (isset($parsed_url['scheme']) && $parsed_url['scheme'] === 'https') {
                $result['checks']['ssl'] = [
                    'status' => 'pass',
                    'message' => 'HTTPS enabled',
                ];
            } else {
                $result['checks']['ssl'] = [
                    'status' => 'warning',
                    'message' => 'Not using HTTPS',
                ];
            }

            // Overall health determination
            $has_failures = false;
            foreach ($result['checks'] as $check) {
                if ($check['status'] === 'fail') {
                    $has_failures = true;
                    break;
                }
            }

            $result['healthy'] = !$has_failures;
            $result['message'] = $has_failures
                ? 'One or more checks failed'
                : sprintf('All checks passed - Response time: %sms', $response_time);

        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
            $result['checks']['exception'] = [
                'status' => 'fail',
                'message' => $e->getMessage(),
            ];
        }

        return $result;
    }

    /**
     * Track consecutive failures and send alert if threshold exceeded
     *
     * @param string $store_url Store URL
     * @param array $result Health check result
     */
    private function track_failures(string $store_url, array $result): void {
        $failure_counts = get_option('wc_mss_health_failure_counts', []);

        if (!$result['healthy']) {
            // Increment failure count
            $failure_counts[$store_url] = isset($failure_counts[$store_url])
                ? $failure_counts[$store_url] + 1
                : 1;

            // Send alert if threshold exceeded
            if ($failure_counts[$store_url] === self::FAILURE_THRESHOLD) {
                $this->send_health_alert($store_url, $result, $failure_counts[$store_url]);
            }
        } else {
            // Reset failure count on success
            $failure_counts[$store_url] = 0;
        }

        update_option('wc_mss_health_failure_counts', $failure_counts);
    }

    /**
     * Send health alert email
     *
     * @param string $store_url Store URL
     * @param array $result Health check result
     * @param int $failure_count Consecutive failure count
     */
    private function send_health_alert(string $store_url, array $result, int $failure_count): void {
        $email_settings = get_option('wc_multi_store_sync_email_settings', []);
        $enabled = isset($email_settings['notifications_enabled']) && $email_settings['notifications_enabled'];

        if (!$enabled) {
            return;
        }

        $recipients = $email_settings['recipients'] ?? get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = sprintf(
            '[%s] Store Health Alert: %s',
            $site_name,
            wp_parse_url($store_url, PHP_URL_HOST)
        );

        $message = sprintf(
            "A remote store has failed %d consecutive health checks.\n\n" .
            "Store URL: %s\n" .
            "Last Error: %s\n" .
            "Checked At: %s\n\n" .
            "Please check the store connection settings and ensure the remote store is accessible.\n\n" .
            "---\n" .
            "WC Multi-Store Sync\n" .
            "%s",
            $failure_count,
            $store_url,
            $result['message'],
            $result['checked_at'],
            home_url()
        );

        wp_mail($recipients, $subject, $message);

        WC_Multi_Store_Logger::write(sprintf(
            'Health alert sent for store %s (%d consecutive failures)',
            $store_url,
            $failure_count
        ), 'warning');
    }

    /**
     * Update store health status in store configuration
     *
     * @param string $store_url Store URL
     * @param array $health_result Health check result
     */
    private function update_store_health_status(string $store_url, array $health_result): void {
        $store = WC_Multi_Store_Settings::get_store($store_url);

        if ($store) {
            $store['health_status'] = $health_result;
            WC_Multi_Store_Settings::update_store($store_url, $store);
        }
    }

    /**
     * AJAX handler for manual health check
     */
    public function ajax_run_health_check(): void {
        // Verify nonce
        if (!check_ajax_referer('wc_mss_run_health_check', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }

        // Check capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $results = $this->run_health_check();

        $healthy_count = 0;
        $total_count = count($results);

        foreach ($results as $result) {
            if ($result['healthy']) {
                $healthy_count++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                '%d of %d stores are healthy',
                $healthy_count,
                $total_count
            ),
            'results' => $results,
            'healthy_count' => $healthy_count,
            'total_count' => $total_count,
        ]);
    }

    /**
     * AJAX handler for single store health check
     */
    public function ajax_check_single_store(): void {
        // Verify nonce
        if (!check_ajax_referer('wc_mss_check_single_store', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }

        // Check capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $store_url = isset($_POST['store_url']) ? esc_url_raw($_POST['store_url']) : '';

        if (empty($store_url)) {
            wp_send_json_error(['message' => 'Store URL is required']);
            return;
        }

        $store_config = WC_Multi_Store_Settings::get_store($store_url);

        if (!$store_config) {
            wp_send_json_error(['message' => 'Store not found']);
            return;
        }

        $result = $this->check_store_health($store_url, $store_config);

        // Update store health status
        $this->update_store_health_status($store_url, $result);

        wp_send_json_success([
            'store_url' => $store_url,
            'result' => $result,
        ]);
    }

    /**
     * Run health check for a single store and persist the result.
     * Called after a store is added or updated so the health column
     * is never stuck on "Not checked" until the daily cron fires.
     *
     * @param string $store_url Store URL
     * @return array|null Health result or null when store not found
     */
    public function check_and_update_store(string $store_url): ?array {
        $store_config = WC_Multi_Store_Settings::get_store($store_url);
        if (!$store_config) {
            return null;
        }

        $result = $this->check_store_health($store_url, $store_config);
        $this->update_store_health_status($store_url, $result);
        $this->track_failures($store_url, $result);

        return $result;
    }

    /**
     * AJAX handler: test WP Application Password credentials.
     * Sends a GET to /wp/v2/users/me with Basic Auth and reports success/failure.
     */
    public function ajax_test_app_password(): void {
        if (!check_ajax_referer('wc_mss_test_app_password', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $store_url        = isset($_POST['store_url'])      ? esc_url_raw($_POST['store_url'])           : '';
        $wp_username      = isset($_POST['wp_username'])    ? sanitize_text_field($_POST['wp_username']) : '';
        $wp_password      = isset($_POST['wp_app_password']) ? sanitize_text_field($_POST['wp_app_password']) : '';
        $use_saved        = !empty($_POST['use_saved_password']);

        // Saved-credentials path: only allow reusing the stored password if the
        // URL is one we actually have on file. Without this an attacker who
        // captures the nonce can swap store_url to anything they like.
        if ($use_saved) {
            $store_config = WC_Multi_Store_Settings::get_store($store_url);
            if (!$store_config) {
                wp_send_json_error(['message' => 'Unknown store URL']);
                return;
            }
            if (empty($wp_password)) {
                $wp_password = $store_config['wp_app_password'] ?? '';
            }
            if (empty($wp_username)) {
                $wp_username = $store_config['wp_username'] ?? '';
            }
        }

        if (empty($store_url) || empty($wp_username) || empty($wp_password)) {
            wp_send_json_error(['message' => 'Store URL, username and application password are required']);
            return;
        }

        // Block link-local targets (cloud-metadata IPs) before dialing.
        if (!WC_Multi_Store_Settings::is_safe_remote_url($store_url)) {
            wp_send_json_error(['message' => 'Store URL is not allowed']);
            return;
        }

        $settings = WC_Multi_Store_Settings::get_settings();
        $verify_ssl = !($settings['disable_ssl_verification'] ?? false);

        $url      = rtrim($store_url, '/') . '/wp-json/wp/v2/users/me';
        $response = wp_remote_get($url, [
            'timeout'   => 15,
            'sslverify' => $verify_ssl,
            'headers'   => [
                'Authorization' => 'Basic ' . base64_encode($wp_username . ':' . $wp_password),
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Connection failed: ' . $response->get_error_message()]);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['name'])) {
            wp_send_json_success([
                'message' => sprintf('Connected as "%s" (ID %d)', $body['name'], $body['id'] ?? 0),
            ]);
        } elseif ($code === 401) {
            wp_send_json_error(['message' => 'Authentication failed — check username and application password']);
        } elseif ($code === 403) {
            wp_send_json_error(['message' => 'Access forbidden — the user may lack required permissions']);
        } else {
            $error = $body['message'] ?? ('Unexpected response: HTTP ' . $code);
            wp_send_json_error(['message' => $error]);
        }
    }

}
