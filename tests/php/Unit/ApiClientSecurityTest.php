<?php
/**
 * Tests for API Client security, error handling, and retry logic
 *
 * Covers: sanitize_error_message, categorize_error, is_transient_error,
 *         get_http_error_message, execute_with_retry, build_url, get_headers
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ApiClientSecurityTest extends WC_Multi_Store_TestCase
{
    private string $store_url = 'https://test-store.com';
    private string $consumer_key = 'ck_test_key_12345678901234567890';
    private string $consumer_secret = 'cs_test_secret_12345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            if (is_array($args)) {
                $query = http_build_query($args);
                return $url . (strpos($url, '?') !== false ? '&' : '?') . $query;
            }
            return $url;
        });

        Functions\when('wp_remote_retrieve_response_code')->alias(function ($response) {
            return $response['response']['code'] ?? 200;
        });

        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '';
        });

        Functions\when('do_action')->justReturn(null);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
    }

    private function createClient(string $auth = 'query_string'): WC_Multi_Store_API_Client
    {
        return new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            $auth
        );
    }

    private function invoke(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        return $ref->invokeArgs($obj, $args);
    }

    // ─── sanitize_error_message ──────────────────────

    public function test_sanitize_redacts_consumer_key_in_query(): void
    {
        $client = $this->createClient();
        $msg = 'Error: consumer_key=ck_live_abcdef123456 failed';
        $result = $this->invoke($client, 'sanitize_error_message', [$msg]);

        $this->assertStringNotContainsString('ck_live_abcdef123456', $result);
        $this->assertStringContainsString('consumer_key=[REDACTED]', $result);
    }

    public function test_sanitize_redacts_consumer_secret_in_query(): void
    {
        $client = $this->createClient();
        $msg = 'Error: consumer_secret=cs_live_secret_xyz failed';
        $result = $this->invoke($client, 'sanitize_error_message', [$msg]);

        $this->assertStringNotContainsString('cs_live_secret_xyz', $result);
        $this->assertStringContainsString('consumer_secret=[REDACTED]', $result);
    }

    public function test_sanitize_redacts_basic_auth_header(): void
    {
        $client = $this->createClient();
        $msg = 'Authorization: Basic dXNlcjpwYXNz was rejected';
        $result = $this->invoke($client, 'sanitize_error_message', [$msg]);

        $this->assertStringNotContainsString('dXNlcjpwYXNz', $result);
        $this->assertStringContainsString('Basic [REDACTED]', $result);
    }

    public function test_sanitize_redacts_long_alphanumeric_tokens(): void
    {
        $client = $this->createClient();
        $msg = 'Token abcdefghij1234567890 is invalid';
        $result = $this->invoke($client, 'sanitize_error_message', [$msg]);

        $this->assertStringNotContainsString('abcdefghij1234567890', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_sanitize_preserves_short_words(): void
    {
        $client = $this->createClient();
        $msg = 'Error connecting to store';
        $result = $this->invoke($client, 'sanitize_error_message', [$msg]);

        $this->assertEquals('Error connecting to store', $result);
    }

    public function test_sanitize_handles_multiple_credentials(): void
    {
        $client = $this->createClient();
        $msg = 'consumer_key=ck_abc123 consumer_secret=cs_xyz789 Basic dGVzdDp0ZXN0';
        $result = $this->invoke($client, 'sanitize_error_message', [$msg]);

        $this->assertStringContainsString('consumer_key=[REDACTED]', $result);
        $this->assertStringContainsString('consumer_secret=[REDACTED]', $result);
        $this->assertStringContainsString('Basic [REDACTED]', $result);
    }

    // ─── categorize_error ────────────────────────────

    public function test_categorize_500_as_server_error(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [500, ['response' => ['code' => 500]]]);

        $this->assertEquals('server_error', $result);
    }

    public function test_categorize_502_as_server_error(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [502, ['response' => ['code' => 502]]]);

        $this->assertEquals('server_error', $result);
    }

    public function test_categorize_503_as_server_error(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [503, ['response' => ['code' => 503]]]);

        $this->assertEquals('server_error', $result);
    }

    public function test_categorize_429_as_rate_limit(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [429, ['response' => ['code' => 429]]]);

        $this->assertEquals('rate_limit', $result);
    }

    public function test_categorize_408_as_timeout(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [408, ['response' => ['code' => 408]]]);

        $this->assertEquals('timeout', $result);
    }

    public function test_categorize_401_as_auth_error(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [401, ['response' => ['code' => 401]]]);

        $this->assertEquals('auth_error', $result);
    }

    public function test_categorize_403_as_auth_error(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [403, ['response' => ['code' => 403]]]);

        $this->assertEquals('auth_error', $result);
    }

    public function test_categorize_404_as_not_found(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [404, ['response' => ['code' => 404]]]);

        $this->assertEquals('not_found', $result);
    }

    public function test_categorize_422_as_client_error(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'categorize_error', [422, ['response' => ['code' => 422]]]);

        $this->assertEquals('client_error', $result);
    }

    public function test_categorize_wp_error_network_failure(): void
    {
        $client = $this->createClient();
        $wp_error = new WP_Error('http_request_failed', 'Connection refused');
        $result = $this->invoke($client, 'categorize_error', [0, $wp_error]);

        $this->assertEquals('network_error', $result);
    }

    public function test_categorize_wp_error_timeout(): void
    {
        $client = $this->createClient();
        $wp_error = new WP_Error('timeout', 'Connection timed out');
        $result = $this->invoke($client, 'categorize_error', [0, $wp_error]);

        $this->assertEquals('network_error', $result);
    }

    // ─── is_transient_error ──────────────────────────

    public function test_transient_error_server_error(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('server_error', 'Internal server error');

        $this->assertTrue($this->invoke($client, 'is_transient_error', [$error]));
    }

    public function test_transient_error_rate_limit(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('rate_limit', 'Too many requests');

        $this->assertTrue($this->invoke($client, 'is_transient_error', [$error]));
    }

    public function test_transient_error_network(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('network_error', 'Connection refused');

        $this->assertTrue($this->invoke($client, 'is_transient_error', [$error]));
    }

    public function test_transient_error_timeout(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('timeout', 'Timed out');

        $this->assertTrue($this->invoke($client, 'is_transient_error', [$error]));
    }

    public function test_non_transient_auth_error(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('auth_error', 'Invalid credentials');

        $this->assertFalse($this->invoke($client, 'is_transient_error', [$error]));
    }

    public function test_non_transient_not_found(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('not_found', 'Resource not found');

        $this->assertFalse($this->invoke($client, 'is_transient_error', [$error]));
    }

    public function test_non_transient_client_error(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('client_error', 'Bad request');

        $this->assertFalse($this->invoke($client, 'is_transient_error', [$error]));
    }

    public function test_is_transient_returns_false_for_non_wp_error(): void
    {
        $client = $this->createClient();

        $this->assertFalse($this->invoke($client, 'is_transient_error', ['not an error']));
        $this->assertFalse($this->invoke($client, 'is_transient_error', [null]));
        $this->assertFalse($this->invoke($client, 'is_transient_error', [['data']]));
    }

    // ─── get_http_error_message ──────────────────────

    public function test_http_error_message_400(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'get_http_error_message', [400, '']);

        $this->assertStringContainsString('Bad request', $result);
        $this->assertStringContainsString('400', $result);
    }

    public function test_http_error_message_429(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'get_http_error_message', [429, '']);

        $this->assertStringContainsString('rate limit', $result);
    }

    public function test_http_error_message_500(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'get_http_error_message', [500, '']);

        $this->assertStringContainsString('Internal server error', $result);
    }

    public function test_http_error_message_503(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'get_http_error_message', [503, '']);

        $this->assertStringContainsString('Service unavailable', $result);
    }

    public function test_http_error_message_unknown_code(): void
    {
        $client = $this->createClient();
        $result = $this->invoke($client, 'get_http_error_message', [418, '']);

        $this->assertEquals('HTTP error 418', $result);
    }

    // ─── build_url ───────────────────────────────────

    public function test_build_url_query_string_auth(): void
    {
        $client = $this->createClient('query_string');
        $url = $this->invoke($client, 'build_url', ['products']);

        $this->assertStringContainsString('https://test-store.com/wp-json/wc/v3/products', $url);
        $this->assertStringContainsString('consumer_key=', $url);
        $this->assertStringContainsString('consumer_secret=', $url);
    }

    public function test_build_url_basic_auth_no_credentials_in_url(): void
    {
        $client = $this->createClient('basic_auth');
        $url = $this->invoke($client, 'build_url', ['products']);

        $this->assertEquals('https://test-store.com/wp-json/wc/v3/products', $url);
        $this->assertStringNotContainsString('consumer_key', $url);
    }

    public function test_build_url_strips_leading_slash(): void
    {
        $client = $this->createClient('basic_auth');
        $url = $this->invoke($client, 'build_url', ['/products/123']);

        $this->assertStringContainsString('/wc/v3/products/123', $url);
        $this->assertStringNotContainsString('/wc/v3//products', $url);
    }

    // ─── get_headers ─────────────────────────────────

    public function test_headers_contain_json_content_type(): void
    {
        $client = $this->createClient('query_string');
        $headers = $this->invoke($client, 'get_headers', []);

        $this->assertEquals('application/json', $headers['Content-Type']);
    }

    public function test_headers_contain_keep_alive(): void
    {
        $client = $this->createClient('query_string');
        $headers = $this->invoke($client, 'get_headers', []);

        $this->assertEquals('keep-alive', $headers['Connection']);
    }

    public function test_headers_basic_auth_includes_authorization(): void
    {
        $client = $this->createClient('basic_auth');
        $headers = $this->invoke($client, 'get_headers', []);

        $this->assertArrayHasKey('Authorization', $headers);
        $expected = 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        $this->assertEquals($expected, $headers['Authorization']);
    }

    public function test_headers_query_string_has_no_authorization(): void
    {
        $client = $this->createClient('query_string');
        $headers = $this->invoke($client, 'get_headers', []);

        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    // ─── process_response ────────────────────────────

    public function test_process_response_success_json(): void
    {
        $client = $this->createClient();
        $response = [
            'response' => ['code' => 200],
            'body' => json_encode(['id' => 1, 'name' => 'Test']),
        ];

        $result = $this->invoke($client, 'process_response', [$response]);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
    }

    public function test_process_response_wp_error_passthrough(): void
    {
        $client = $this->createClient();
        $error = new WP_Error('http_request_failed', 'Connection refused');

        $result = $this->invoke($client, 'process_response', [$error]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('http_request_failed', $result->get_error_code());
    }

    public function test_process_response_html_error_page(): void
    {
        $client = $this->createClient();
        $response = [
            'response' => ['code' => 403],
            'body' => '<html><body>Access Denied by CloudFlare</body></html>',
        ];

        $result = $this->invoke($client, 'process_response', [$response]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('auth_error', $result->get_error_code());
        $this->assertStringContainsString('Forbidden', $result->get_error_message());
    }

    public function test_process_response_json_api_error(): void
    {
        $client = $this->createClient();
        $response = [
            'response' => ['code' => 404],
            'body' => json_encode(['message' => 'Product not found', 'code' => 'woocommerce_rest_product_invalid_id']),
        ];

        $result = $this->invoke($client, 'process_response', [$response]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
        $this->assertEquals('Product not found', $result->get_error_message());
    }

    public function test_process_response_invalid_json_on_200(): void
    {
        $client = $this->createClient();
        $response = [
            'response' => ['code' => 200],
            'body' => 'not json {{{',
        ];

        $result = $this->invoke($client, 'process_response', [$response]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('json_decode_error', $result->get_error_code());
    }

    public function test_process_response_empty_body_on_200(): void
    {
        $client = $this->createClient();
        $response = [
            'response' => ['code' => 200],
            'body' => '',
        ];

        $result = $this->invoke($client, 'process_response', [$response]);

        // Empty string → json_decode returns null → JSON_ERROR_NONE but result is null
        // This depends on implementation; empty body on 200 is unusual
        $this->assertTrue($result === null || is_wp_error($result));
    }

    // ─── execute_with_retry (non-retryable errors) ───

    public function test_retry_does_not_retry_auth_errors(): void
    {
        $client = $this->createClient();
        $call_count = 0;

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn([
                'response' => ['code' => 401],
                'body' => json_encode(['message' => 'Invalid credentials']),
            ]);

        $result = $client->get_products();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('auth_error', $result->get_error_code());
    }

    public function test_retry_does_not_retry_not_found(): void
    {
        $client = $this->createClient();

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn([
                'response' => ['code' => 404],
                'body' => json_encode(['message' => 'Not found']),
            ]);

        $result = $client->get_product(999);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    // ─── track_request ───────────────────────────────

    public function test_track_request_calls_do_action(): void
    {
        $client = $this->createClient();
        $action_called = false;

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body' => json_encode(['id' => 1]),
            ]);

        Functions\when('do_action')->alias(function ($hook) use (&$action_called) {
            if ($hook === 'wc_mss_api_request') {
                $action_called = true;
            }
        });

        $client->get_product(1);

        $this->assertTrue($action_called, 'do_action should be called with wc_mss_api_request');
    }
}
