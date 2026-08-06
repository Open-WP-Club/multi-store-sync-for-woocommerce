<?php
/**
 * Tests for Webhook Receiver security: IP detection, signature verification,
 * rate limiting, and request validation
 *
 * Covers the actual behavior of get_client_ip, verify_webhook_signature,
 * check_rate_limit, is_store_registered, handle_order_webhook validation
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WebhookReceiverSecurityTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Webhook_Receiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-01 12:00:00');
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('untrailingslashit')->alias(fn($str) => rtrim($str, '/'));
        Functions\when('rest_url')->alias(fn($path) => 'https://main-store.com/wp-json/' . ltrim($path, '/'));
        Functions\when('add_query_arg')->alias(function ($key, $value = null, $url = null) {
            if (is_string($key) && $url !== null) {
                return $url . '?' . urlencode($key) . '=' . urlencode($value);
            }
            if (is_array($key)) {
                return $value . '?' . http_build_query($key);
            }
            return $value ?? $key;
        });

        $this->receiver = new WC_Multi_Store_Webhook_Receiver();
    }

    private function invoke(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($this->receiver, $method);
        return $ref->invokeArgs($this->receiver, $args);
    }

    // ─── get_client_ip ───────────────────────────────

    public function test_get_client_ip_returns_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        // No trusted proxies configured — apply_filters returns empty array by default
        $ip = $this->invoke('get_client_ip');

        $this->assertEquals('192.168.1.100', $ip);

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_get_client_ip_returns_fallback_when_no_remote_addr(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        $ip = $this->invoke('get_client_ip');

        $this->assertEquals('0.0.0.0', $ip);
    }

    public function test_get_client_ip_ignores_proxy_headers_without_trusted_proxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';

        $ip = $this->invoke('get_client_ip');

        // Without trusted proxies, must use REMOTE_ADDR
        $this->assertEquals('10.0.0.1', $ip);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    public function test_get_client_ip_trusts_cloudflare_header_from_trusted_proxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.16.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';

        // Override apply_filters to return trusted proxies
        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'wc_mss_trusted_proxies') {
                return ['172.16.0.1'];
            }
            return $value;
        });

        $ip = $this->invoke('get_client_ip');

        $this->assertEquals('203.0.113.50', $ip);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    public function test_get_client_ip_uses_first_ip_from_x_forwarded_for(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 70.41.3.18, 150.172.238.178';

        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'wc_mss_trusted_proxies') {
                return ['10.0.0.1'];
            }
            return $value;
        });

        $ip = $this->invoke('get_client_ip');

        $this->assertEquals('203.0.113.50', $ip);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_get_client_ip_rejects_private_ip_from_proxy_header(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1'; // Private range

        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'wc_mss_trusted_proxies') {
                return ['10.0.0.1'];
            }
            return $value;
        });

        $ip = $this->invoke('get_client_ip');

        // Private IPs are rejected by FILTER_FLAG_NO_PRIV_RANGE, falls back to REMOTE_ADDR
        $this->assertEquals('10.0.0.1', $ip);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_get_client_ip_rejects_invalid_ip_from_proxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';

        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'wc_mss_trusted_proxies') {
                return ['10.0.0.1'];
            }
            return $value;
        });

        $ip = $this->invoke('get_client_ip');

        $this->assertEquals('10.0.0.1', $ip);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    public function test_get_client_ip_invalid_remote_addr_returns_fallback(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'garbage';

        $ip = $this->invoke('get_client_ip');

        $this->assertEquals('0.0.0.0', $ip);

        unset($_SERVER['REMOTE_ADDR']);
    }

    // ─── is_store_registered ─────────────────────────

    public function test_is_store_registered_returns_true_for_active_store(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => ['status' => 'active'],
                    'https://store2.com' => ['status' => 'inactive'],
                ];
            }
            return $default;
        });

        $result = $this->invoke('is_store_registered', ['https://store1.com']);

        $this->assertTrue($result);
    }

    public function test_is_store_registered_returns_false_for_inactive_store(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    'https://store2.com' => ['status' => 'inactive'],
                ];
            }
            return $default;
        });

        $result = $this->invoke('is_store_registered', ['https://store2.com']);

        $this->assertFalse($result);
    }

    public function test_is_store_registered_returns_false_for_unknown_store(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => ['status' => 'active'],
                ];
            }
            return $default;
        });

        $result = $this->invoke('is_store_registered', ['https://unknown.com']);

        $this->assertFalse($result);
    }

    public function test_is_store_registered_normalizes_trailing_slash(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com/' => ['status' => 'active'],
                ];
            }
            return $default;
        });

        $result = $this->invoke('is_store_registered', ['https://store1.com']);

        $this->assertTrue($result);
    }

    public function test_is_store_registered_handles_empty_stores(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [];
            }
            return $default;
        });

        $result = $this->invoke('is_store_registered', ['https://any.com']);

        $this->assertFalse($result);
    }

    // ─── verify_webhook_signature ────────────────────

    public function test_verify_rejects_when_no_secret_configured(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => ''];
            }
            return $default;
        });

        $request = new WP_REST_Request();

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('webhook_not_configured', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_verify_rejects_missing_auth_headers(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => 'my_secret'];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        // No signature or secret header set

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('missing_auth', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_verify_accepts_valid_wc_signature(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $secret = 'my_webhook_secret';
        $payload = '{"id":123,"status":"processing"}';
        $signature = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        Functions\when('get_option')->alias(function ($key, $default = false) use ($secret) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => $secret];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        $request->set_header('x-wc-webhook-signature', $signature);
        $request->set_body($payload);

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertTrue($result);

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_verify_rejects_invalid_wc_signature(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => 'real_secret'];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        $request->set_header('x-wc-webhook-signature', 'totally_wrong_signature');
        $request->set_body('{"id":1}');

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_signature', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_verify_accepts_valid_custom_secret(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $secret = 'shared_secret_123';

        Functions\when('get_option')->alias(function ($key, $default = false) use ($secret) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => $secret];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        $request->set_header('x-wc-mss-secret', $secret);

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertTrue($result);

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_verify_rejects_wrong_custom_secret(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => 'correct_secret'];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        $request->set_header('x-wc-mss-secret', 'wrong_secret');

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_secret', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    // ─── check_rate_limit ────────────────────────────

    public function test_rate_limit_allows_under_threshold(): void
    {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        Functions\when('get_transient')->justReturn(50); // Under 100

        $result = $this->invoke('check_rate_limit');

        $this->assertTrue($result);

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_rate_limit_blocks_at_threshold(): void
    {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        Functions\when('get_transient')->justReturn(100); // Exactly at limit

        $result = $this->invoke('check_rate_limit');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('rate_limit_exceeded', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_rate_limit_blocks_over_threshold(): void
    {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        Functions\when('get_transient')->justReturn(200); // Way over limit

        $result = $this->invoke('check_rate_limit');

        $this->assertInstanceOf(WP_Error::class, $result);

        unset($_SERVER['REMOTE_ADDR']);
    }

    // ─── handle_order_webhook validation ─────────────

    public function test_order_webhook_rejects_empty_data(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        // Must pass rate limit and signature first
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => 'secret', 'enabled' => true, 'trigger_statuses' => ['processing']];
            }
            if ($key === 'wc_multi_store_sync_stores') {
                return ['https://remote.com' => ['status' => 'active']];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        $request->set_body(''); // Empty body → empty json params
        $request->set_param('store_url', 'https://remote.com');

        $result = $this->receiver->handle_order_webhook($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('empty_data', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_order_webhook_rejects_missing_store_url(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => 'secret', 'enabled' => true];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        $request->set_body(json_encode(['id' => 1, 'status' => 'processing', 'line_items' => []]));
        // No store_url param set

        $result = $this->receiver->handle_order_webhook($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('missing_store_url', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_order_webhook_rejects_unregistered_store(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => 'secret', 'enabled' => true];
            }
            if ($key === 'wc_multi_store_sync_stores') {
                return ['https://known.com' => ['status' => 'active']];
            }
            return $default;
        });

        $request = new WP_REST_Request();
        $request->set_body(json_encode(['id' => 1, 'status' => 'processing']));
        $request->set_param('store_url', 'https://unknown.com');

        $result = $this->receiver->handle_order_webhook($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unknown_store', $result->get_error_code());

        unset($_SERVER['REMOTE_ADDR']);
    }

    // ─── handle_test_webhook ─────────────────────────

    public function test_test_webhook_returns_success(): void
    {
        $request = new WP_REST_Request();
        $request->set_param('store_url', 'https://remote-store.com');

        $response = $this->receiver->handle_test_webhook($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('working correctly', $data['message']);
    }

    public function test_test_webhook_without_store_url(): void
    {
        $request = new WP_REST_Request();

        $response = $this->receiver->handle_test_webhook($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());
    }

    // ─── is_processing_webhook flag ──────────────────

    public function test_is_processing_webhook_default_false(): void
    {
        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
    }

    public function test_is_processing_webhook_is_static(): void
    {
        $ref = new ReflectionProperty(WC_Multi_Store_Webhook_Receiver::class, 'is_processing_webhook');
        $this->assertTrue($ref->isStatic());
    }
}
