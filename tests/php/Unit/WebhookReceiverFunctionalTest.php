<?php
/**
 * Functional tests for WC_Multi_Store_Webhook_Receiver
 * Tests order webhook handling, signature verification, and rate limiting
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WebhookReceiverFunctionalTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Webhook_Receiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebhookMocks();

        $this->receiver = new WC_Multi_Store_Webhook_Receiver();
    }

    protected function setUpWebhookMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return [
                    'enabled' => true,
                    'webhook_secret' => 'test_secret_key',
                    'trigger_statuses' => ['processing', 'completed'],
                    'allow_negative_stock' => false,
                ];
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
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('rest_url')->alias(fn($p = '') => 'https://example.com/wp-json/' . ltrim($p, '/'));
    }

    private function makeRequest(array $params = [], array $headers = [], string $body = ''): WP_REST_Request
    {
        $request = new WP_REST_Request();
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }
        if ($body) {
            $request->set_body($body);
        }
        return $request;
    }

    // ── verify_webhook_signature ──────────────────────────────────

    public function test_verify_signature_valid_hmac(): void
    {
        $body = '{"id":100,"status":"processing"}';
        $signature = base64_encode(hash_hmac('sha256', $body, 'test_secret_key', true));

        $request = $this->makeRequest([], ['x-wc-webhook-signature' => $signature], $body);

        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertTrue($result);
    }

    public function test_verify_signature_invalid_hmac(): void
    {
        $body = '{"id":100}';
        $request = $this->makeRequest([], ['x-wc-webhook-signature' => 'invalid_sig'], $body);

        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_signature', $result->get_error_code());
    }

    public function test_verify_signature_valid_custom_secret(): void
    {
        $request = $this->makeRequest([], ['x-wc-mss-secret' => 'test_secret_key']);

        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertTrue($result);
    }

    public function test_verify_signature_invalid_custom_secret(): void
    {
        $request = $this->makeRequest([], ['x-wc-mss-secret' => 'wrong_secret']);

        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_secret', $result->get_error_code());
    }

    public function test_verify_signature_missing_auth(): void
    {
        $request = $this->makeRequest();

        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('missing_auth', $result->get_error_code());
    }

    public function test_verify_signature_no_secret_configured(): void
    {
        // Override settings with no secret
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['enabled' => true, 'webhook_secret' => ''];
            }
            return $default;
        });

        $request = $this->makeRequest([], ['x-wc-mss-secret' => 'any_secret']);
        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('webhook_not_configured', $result->get_error_code());
    }

    // ── rate limiting ─────────────────────────────────────────────

    public function test_rate_limit_allows_normal_requests(): void
    {
        // First request should pass
        $request = $this->makeRequest([], ['x-wc-mss-secret' => 'test_secret_key']);
        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertTrue($result);
    }

    public function test_rate_limit_blocks_excessive_requests(): void
    {
        // Simulate hitting rate limit by setting transient to max
        Functions\when('get_transient')->justReturn(100); // Already at limit

        $request = $this->makeRequest([], ['x-wc-mss-secret' => 'test_secret_key']);
        $result = $this->receiver->verify_webhook_signature($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('rate_limit_exceeded', $result->get_error_code());
    }

    // ── handle_order_webhook ──────────────────────────────────────

    public function test_handle_order_webhook_rejects_empty_data(): void
    {
        $request = $this->makeRequest(['store_url' => 'https://store1.com']);
        // Empty body → empty json_params
        $result = $this->receiver->handle_order_webhook($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('empty_data', $result->get_error_code());
    }

    public function test_handle_order_webhook_rejects_missing_store_url(): void
    {
        $body = json_encode(['id' => 100, 'status' => 'processing', 'line_items' => []]);
        $request = $this->makeRequest([], [], $body);

        $result = $this->receiver->handle_order_webhook($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('missing_store_url', $result->get_error_code());
    }

    public function test_handle_order_webhook_rejects_unregistered_store(): void
    {
        $body = json_encode(['id' => 100, 'status' => 'processing', 'line_items' => []]);
        $request = $this->makeRequest(['store_url' => 'https://unknown-store.com'], [], $body);

        $result = $this->receiver->handle_order_webhook($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unknown_store', $result->get_error_code());
    }

    public function test_handle_order_webhook_skips_non_trigger_status(): void
    {
        $body = json_encode(['id' => 100, 'status' => 'pending', 'line_items' => [['sku' => 'A']]]);
        $request = $this->makeRequest(['store_url' => 'https://store1.com'], [], $body);

        $result = $this->receiver->handle_order_webhook($request);
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertEquals(200, $result->get_status());
        $data = $result->get_data();
        $this->assertStringContainsString('not configured', $data['message']);
    }

    public function test_handle_order_webhook_returns_200_when_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['enabled' => false, 'webhook_secret' => 'test'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active']];
            }
            return $default;
        });

        $body = json_encode(['id' => 100, 'status' => 'processing', 'line_items' => []]);
        $request = $this->makeRequest(['store_url' => 'https://store1.com'], [], $body);

        $result = $this->receiver->handle_order_webhook($request);
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertEquals(200, $result->get_status());
    }

    public function test_handle_order_webhook_returns_200_for_empty_line_items(): void
    {
        $body = json_encode(['id' => 100, 'status' => 'processing', 'line_items' => []]);
        $request = $this->makeRequest(['store_url' => 'https://store1.com'], [], $body);

        $result = $this->receiver->handle_order_webhook($request);
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $this->assertStringContainsString('no line items', $data['message']);
    }
}
