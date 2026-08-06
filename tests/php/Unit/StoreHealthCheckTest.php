<?php
/**
 * Unit tests for WC_Multi_Store_Health_Check
 * Tests health check logic, failure tracking, and alerting
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class StoreHealthCheckTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHealthCheckMocks();
    }

    protected function setUpHealthCheckMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true, 'auth_method' => 'query_string'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                    ],
                ];
            }
            if ($option === 'wc_mss_health_failure_counts') {
                return [];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 2 && is_array($args[0])) {
                return $args[1] . '?' . http_build_query($args[0]);
            }
            return $args[count($args) - 1] ?? '';
        });
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => '{"routes":{}}',
        ]);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('wp_parse_url')->alias(fn($url, $c = -1) => $c === -1 ? parse_url($url) : parse_url($url, $c));
        Functions\when('esc_url_raw')->alias(fn($url) => $url);
        Functions\when('sanitize_textarea_field')->alias(fn($str) => strip_tags($str));
        Functions\when('wp_remote_post')->justReturn([
            'response' => ['code' => 200],
            'body' => '{"routes":{}}',
        ]);
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200],
            'body' => '{"routes":{}}',
        ]);
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
    }

    private function loadHealthCheckClass(): void
    {
        $file = dirname(__DIR__, 3) . '/includes/store-health-check.php';
        if (!class_exists('WC_Multi_Store_Health_Check', false)) {
            require_once $file;
        }
    }

    // ── run_health_check ──────────────────────────────────────────

    public function test_run_health_check_returns_results_per_store(): void
    {
        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('https://store1.com', $results);
    }

    public function test_run_health_check_result_structure(): void
    {
        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();
        $result = $results['https://store1.com'];

        $this->assertArrayHasKey('healthy', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('response_time', $result);
        $this->assertArrayHasKey('checked_at', $result);
        $this->assertArrayHasKey('checks', $result);
    }

    public function test_run_health_check_connectivity_pass(): void
    {
        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();
        $result = $results['https://store1.com'];

        $this->assertTrue($result['healthy']);
        $this->assertEquals('pass', $result['checks']['connectivity']['status']);
    }

    public function test_run_health_check_connectivity_fail(): void
    {
        // Use non-retryable error code to avoid retry delays in tests.
        // 'connection_failed' is not in the transient_codes list, so
        // execute_with_retry returns immediately.
        Functions\when('wp_remote_get')->justReturn(
            new \WP_Error('connection_failed', 'Connection refused')
        );

        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();
        $result = $results['https://store1.com'];

        $this->assertFalse($result['healthy']);
        $this->assertEquals('fail', $result['checks']['connectivity']['status']);
    }

    public function test_run_health_check_ssl_pass_for_https(): void
    {
        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();
        $result = $results['https://store1.com'];

        $this->assertEquals('pass', $result['checks']['ssl']['status']);
    }

    public function test_run_health_check_ssl_warning_for_http(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'http://insecure-store.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                    ],
                ];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true, 'auth_method' => 'query_string'];
            }
            return $default;
        });

        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();
        $result = $results['http://insecure-store.com'];

        $this->assertEquals('warning', $result['checks']['ssl']['status']);
    }

    public function test_run_health_check_api_version_warning(): void
    {
        // test_connection() returns true (bool), not response data,
        // so the health check cannot verify routes/namespace → 'warning'
        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();
        $result = $results['https://store1.com'];

        $this->assertEquals('warning', $result['checks']['api_version']['status']);
    }

    public function test_run_health_check_empty_stores(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->justReturn([]);

        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $results = $check->run_health_check();

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // ── track_failures ────────────────────────────────────────────

    public function test_track_failures_increments_on_unhealthy(): void
    {
        $captured_option = null;
        Functions\when('update_option')->alias(function ($name, $value) use (&$captured_option) {
            if ($name === 'wc_mss_health_failure_counts') {
                $captured_option = $value;
            }
            return true;
        });

        // Make API fail with non-retryable error to avoid retry delays
        Functions\when('wp_remote_get')->justReturn(
            new \WP_Error('connection_failed', 'Connection refused')
        );

        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $check->run_health_check();

        $this->assertNotNull($captured_option);
        $this->assertEquals(1, $captured_option['https://store1.com'] ?? 0);
    }

    public function test_failure_threshold_constant(): void
    {
        $this->loadHealthCheckClass();
        $this->assertEquals(3, WC_Multi_Store_Health_Check::FAILURE_THRESHOLD);
    }

    // ── check_and_update_store ────────────────────────────────────

    public function test_check_and_update_store_returns_null_for_unknown_store(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->justReturn([]);

        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $result = $check->check_and_update_store('https://nonexistent.com');

        $this->assertNull($result);
    }

    public function test_check_and_update_store_returns_health_result_array(): void
    {
        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $result = $check->check_and_update_store('https://store1.com');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('healthy', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('checked_at', $result);
    }

    public function test_check_and_update_store_persists_health_status_in_store_config(): void
    {
        $saved_store = null;

        Functions\when('update_option')->alias(function ($key, $value) use (&$saved_store) {
            if ($key === 'wc_multi_store_sync_stores') {
                $saved_store = $value;
            }
            return true;
        });

        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $check->check_and_update_store('https://store1.com');

        $this->assertNotNull($saved_store);
        $this->assertArrayHasKey('health_status', $saved_store['https://store1.com']);
        $this->assertArrayHasKey('healthy', $saved_store['https://store1.com']['health_status']);
    }

    public function test_check_and_update_store_increments_failure_count_on_unhealthy(): void
    {
        $failure_counts = null;

        Functions\when('wp_remote_get')->justReturn(
            new \WP_Error('connection_failed', 'Connection refused')
        );
        Functions\when('update_option')->alias(function ($key, $value) use (&$failure_counts) {
            if ($key === 'wc_mss_health_failure_counts') {
                $failure_counts = $value;
            }
            return true;
        });

        $this->loadHealthCheckClass();
        $check = new WC_Multi_Store_Health_Check();

        $check->check_and_update_store('https://store1.com');

        $this->assertNotNull($failure_counts);
        $this->assertEquals(1, $failure_counts['https://store1.com'] ?? 0);
    }
}
