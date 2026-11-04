<?php
/**
 * Regression tests for webhook authentication hardening:
 * - the custom auth path accepts a payload HMAC (x-wc-mss-signature) and
 *   prefers it over the clear x-wc-mss-secret bearer
 * - webhook-log DB rows are written only for credential-bearing auth events
 * - shared secrets are redacted before request data is persisted
 */

use Brain\Monkey\Functions;

class WebhookAuthHardeningTest extends WC_Multi_Store_TestCase {
    private WC_Multi_Store_Webhook_Receiver $receiver;

    /** Insert-spy wpdb double; replaces the global during each test. */
    private wpdb $wpdb_spy;

    protected function setUp(): void {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('untrailingslashit')->alias(fn($str) => rtrim((string) $str, '/'));
        Functions\when('rest_url')->alias(fn($path) => 'https://main-store.com/wp-json/' . ltrim((string) $path, '/'));
        Functions\when('add_query_arg')->alias(function ($key, $value = null, $url = null) {
            if (is_string($key) && $url !== null) {
                return $url . '?' . urlencode($key) . '=' . urlencode($value);
            }
            if (is_array($key)) {
                return $value . '?' . http_build_query($key);
            }
            return $value ?? $key;
        });
        Functions\when('wp_json_encode')->alias(fn($data, $flags = 0) => json_encode($data, $flags));

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->receiver = new WC_Multi_Store_Webhook_Receiver();
    }

    protected function tearDown(): void {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    /**
     * Stub the webhook secret and install an insert-spy $wpdb so every
     * webhook-log write can be counted and inspected. Logger::write() is a
     * no-op in tests (wc_get_logger() is undefined), so these are the only
     * inserts the auth path can produce.
     */
    private function configureWebhook(string $secret): void {
        Functions\when('get_option')->alias(function ($key, $default = false) use ($secret) {
            if ($key === 'wc_multi_store_sync_webhook_settings') {
                return ['webhook_secret' => $secret];
            }
            return $default;
        });
        WC_Multi_Store_Settings::clear_static_cache();

        $this->wpdb_spy = new class extends wpdb {
            public int $insert_calls = 0;
            /** @var array<int, array> */
            public array $insert_rows = [];

            public function insert(string $table, array $data, ?array $format = null): int|false {
                $this->insert_calls++;
                $this->insert_rows[] = $data;
                return 1;
            }
        };
        global $wpdb;
        $wpdb = $this->wpdb_spy;
    }

    /** Rows written to the webhook log table so far. */
    private function logRows(): array {
        return $this->wpdb_spy->insert_rows;
    }

    // ─── credential-less requests must not write DB rows ───

    public function test_requests_without_credentials_are_rejected_without_db_log_rows(): void {
        $this->configureWebhook('real_secret');

        $result = $this->receiver->verify_webhook_signature(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_auth', $result->get_error_code());
        $this->assertSame(0, $this->wpdb_spy->insert_calls, 'Requests with no credentials must not write webhook log rows.');
    }

    public function test_unauthenticated_rate_limited_flood_does_not_write_log_rows(): void {
        $this->configureWebhook('real_secret');
        Functions\when('get_transient')->justReturn(500); // far past the limit

        $result = $this->receiver->verify_webhook_signature(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rate_limit_exceeded', $result->get_error_code());
        $this->assertSame(0, $this->wpdb_spy->insert_calls, 'The rate limiter must not give credential-less floods a DB write either.');
    }

    public function test_not_configured_skips_db_rows_for_credential_less_requests(): void {
        $this->configureWebhook('');

        $result = $this->receiver->verify_webhook_signature(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('webhook_not_configured', $result->get_error_code());
        $this->assertSame(0, $this->wpdb_spy->insert_calls);
    }

    // ─── credential-bearing auth events keep their DB trail ───

    public function test_failed_authentication_with_credentials_still_writes_a_log_row(): void {
        $this->configureWebhook('real_secret');
        $request = new WP_REST_Request();
        $request->set_header('x-wc-mss-secret', 'wrong_secret');

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_secret', $result->get_error_code());
        $this->assertSame(1, $this->wpdb_spy->insert_calls);
        $this->assertSame('Invalid custom secret', $this->logRows()[0]['error_message']);
    }

    public function test_rate_limiting_still_logs_credential_bearing_clients(): void {
        $this->configureWebhook('real_secret');
        Functions\when('get_transient')->justReturn(500); // far past the limit
        $request = new WP_REST_Request();
        $request->set_header('x-wc-mss-secret', 'real_secret');

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rate_limit_exceeded', $result->get_error_code());
        $this->assertSame(1, $this->wpdb_spy->insert_calls);
    }

    public function test_not_configured_still_logs_credential_bearing_attempts(): void {
        $this->configureWebhook('');
        $request = new WP_REST_Request();
        $request->set_header('x-wc-mss-secret', 'some_captured_secret');

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('webhook_not_configured', $result->get_error_code());
        $this->assertSame(1, $this->wpdb_spy->insert_calls);
    }

    // ─── payload HMAC on the custom path ───

    public function test_custom_signature_header_verifies_the_payload_hmac(): void {
        $this->configureWebhook('my_secret');
        $payload = '{"id":5,"status":"processing"}';
        $request = new WP_REST_Request();
        $request->set_body($payload);
        $request->set_header('x-wc-mss-signature', base64_encode(hash_hmac('sha256', $payload, 'my_secret', true)));

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertTrue($result);
        $this->assertSame(0, $this->wpdb_spy->insert_calls);
    }

    public function test_custom_signature_header_rejects_a_tampered_payload(): void {
        $this->configureWebhook('my_secret');
        $request = new WP_REST_Request();
        // Signature over a different body: the HMAC must bind the payload.
        $request->set_body('{"id":5,"status":"cancelled"}');
        $request->set_header('x-wc-mss-signature', base64_encode(hash_hmac('sha256', '{"id":5,"status":"processing"}', 'my_secret', true)));

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
        $this->assertSame(1, $this->wpdb_spy->insert_calls);
    }

    public function test_valid_payload_signature_wins_over_a_stale_clear_secret(): void {
        $this->configureWebhook('my_secret');
        $payload = '{"id":7}';
        $request = new WP_REST_Request();
        $request->set_body($payload);
        $request->set_header('x-wc-mss-signature', base64_encode(hash_hmac('sha256', $payload, 'my_secret', true)));
        $request->set_header('x-wc-mss-secret', 'rotated_away');

        $this->assertTrue($this->receiver->verify_webhook_signature($request));
    }

    public function test_invalid_payload_signature_is_not_rescued_by_the_clear_secret(): void {
        $this->configureWebhook('my_secret');
        $request = new WP_REST_Request();
        // Body was tampered with after signing, but the (correct) clear
        // secret rides along — the signature is authoritative when present.
        $request->set_body('{"id":7,"qty":999}');
        $request->set_header('x-wc-mss-signature', base64_encode(hash_hmac('sha256', '{"id":7}', 'my_secret', true)));
        $request->set_header('x-wc-mss-secret', 'my_secret');

        $result = $this->receiver->verify_webhook_signature($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    // ─── secrets never persisted ───

    public function test_webhook_log_rows_redact_secret_keys_from_request_data(): void {
        $this->configureWebhook('real_secret');

        WC_Multi_Store_Webhook_Logger::log('auth_failed', [
            'request_ip' => '9.9.9.9',
            'error_message' => 'probe',
            'request_data' => [
                'x-wc-mss-secret' => 'super_secret_value',
                'nested' => ['authorization' => 'Basic dXNlcjpwYXNz', 'order_id' => 3],
            ],
        ]);

        $this->assertSame(1, $this->wpdb_spy->insert_calls);
        $encoded = (string) $this->logRows()[0]['request_data'];
        $this->assertStringNotContainsString('super_secret_value', $encoded);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
        $this->assertStringContainsString('"order_id":3', $encoded);
    }
}
