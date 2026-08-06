<?php
/**
 * Tests for WC_Multi_Store_Health_Check::ajax_test_app_password()
 *
 * Covers: nonce rejection, missing-field validation, successful auth,
 *         HTTP 401/403/other error responses, use_saved_password flag,
 *         and WP_Error on connection failure.
 */

use Brain\Monkey\Functions;

class StoreHealthCheckAjaxTest extends WC_Multi_Store_TestCase
{
    private static bool $classLoaded = false;

    private string $store_url    = 'https://remote-store.example.com';
    private string $wp_username  = 'admin';
    private string $wp_password  = 'abcd 1234 efgh 5678';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-04-27 10:00:00');
        Functions\when('wp_parse_url')->alias(fn($url, $c = -1) => $c === -1 ? parse_url($url) : parse_url($url, $c));
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        if (!self::$classLoaded) {
            require_once dirname(__DIR__, 3) . '/includes/store-health-check.php';
            self::$classLoaded = true;
        }
    }

    private function makeHc(): WC_Multi_Store_Health_Check
    {
        return new WC_Multi_Store_Health_Check();
    }

    // ── nonce / permission guards ─────────────────────────────────

    public function test_rejects_invalid_nonce(): void
    {
        Functions\when('check_ajax_referer')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) {
            $error = $data;
        });

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => $this->wp_password,
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('nonce', $error['message']);

        $_POST = [];
    }

    public function test_rejects_missing_store_url(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = ['wp_username' => $this->wp_username, 'wp_app_password' => $this->wp_password];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);

        $_POST = [];
    }

    public function test_rejects_missing_username(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = ['store_url' => $this->store_url, 'wp_app_password' => $this->wp_password];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);

        $_POST = [];
    }

    public function test_rejects_missing_password(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = ['store_url' => $this->store_url, 'wp_username' => $this->wp_username];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);

        $_POST = [];
    }

    // ── HTTP response handling ────────────────────────────────────

    public function test_returns_success_with_user_name_on_200(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200], 'body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => json_encode(['id' => 1, 'name' => 'Admin User']));

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => $this->wp_password,
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($success);
        $this->assertStringContainsString('Admin User', $success['message']);

        $_POST = [];
    }

    public function test_returns_success_includes_user_id(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200], 'body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => json_encode(['id' => 42, 'name' => 'Shop Manager']));

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => $this->wp_password,
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertStringContainsString('42', $success['message']);

        $_POST = [];
    }

    public function test_returns_error_on_401(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 401], 'body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => '{}');

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => 'wrong-password',
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('authentication', $error['message']);

        $_POST = [];
    }

    public function test_returns_error_on_403(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 403], 'body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => '{}');

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => $this->wp_password,
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('forbidden', $error['message']);

        $_POST = [];
    }

    public function test_returns_error_message_from_body_on_unexpected_code(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 500], 'body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => json_encode(['message' => 'Internal server error']));

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => $this->wp_password,
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Internal server error', $error['message']);

        $_POST = [];
    }

    public function test_returns_error_on_connection_failure(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_request_failed', 'cURL error 6: Could not resolve host'));

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => $this->wp_password,
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('connection failed', $error['message']);

        $_POST = [];
    }

    // ── use_saved_password flag ───────────────────────────────────

    public function test_loads_saved_password_from_store_config_when_flag_set(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    $this->store_url => [
                        'consumer_key'    => 'ck_test',
                        'consumer_secret' => 'cs_test',
                        'status'          => 'active',
                        'wp_username'     => $this->wp_username,
                        'wp_app_password' => 'saved-secret-password',
                    ],
                ];
            }
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        $captured_headers = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$captured_headers) {
            $captured_headers = $args['headers'] ?? [];
            return ['response' => ['code' => 200], 'body' => ''];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => json_encode(['id' => 1, 'name' => 'Admin']));

        Functions\when('wp_send_json_success')->alias(function () {});

        $_POST = [
            'store_url'         => $this->store_url,
            'wp_username'       => $this->wp_username,
            'wp_app_password'   => '',
            'use_saved_password' => '1',
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($captured_headers);
        $expectedAuth = 'Basic ' . base64_encode($this->wp_username . ':saved-secret-password');
        $this->assertSame($expectedAuth, $captured_headers['Authorization']);

        $_POST = [];
    }

    public function test_returns_error_when_saved_password_is_also_empty(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                return [
                    $this->store_url => [
                        'consumer_key'    => 'ck_test',
                        'consumer_secret' => 'cs_test',
                        'status'          => 'active',
                        'wp_username'     => $this->wp_username,
                        'wp_app_password' => '', // also empty
                    ],
                ];
            }
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = [
            'store_url'         => $this->store_url,
            'wp_username'       => $this->wp_username,
            'wp_app_password'   => '',
            'use_saved_password' => '1',
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertNotNull($error);

        $_POST = [];
    }

    // ── Authorization header construction ────────────────────────

    public function test_authorization_header_uses_base64_encoded_credentials(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        $captured_args = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$captured_args) {
            $captured_args = $args;
            return ['response' => ['code' => 200], 'body' => ''];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => json_encode(['id' => 1, 'name' => 'User']));
        Functions\when('wp_send_json_success')->alias(function () {});

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => 'myuser',
            'wp_app_password' => 'my secret pass',
        ];

        $this->makeHc()->ajax_test_app_password();

        $expected = 'Basic ' . base64_encode('myuser:my secret pass');
        $this->assertSame($expected, $captured_args['headers']['Authorization']);

        $_POST = [];
    }

    public function test_request_targets_wp_v2_users_me_endpoint(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });

        $captured_url = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$captured_url) {
            $captured_url = $url;
            return ['response' => ['code' => 200], 'body' => ''];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => json_encode(['id' => 1, 'name' => 'User']));
        Functions\when('wp_send_json_success')->alias(function () {});

        $_POST = [
            'store_url'      => $this->store_url,
            'wp_username'    => $this->wp_username,
            'wp_app_password' => $this->wp_password,
        ];

        $this->makeHc()->ajax_test_app_password();

        $this->assertStringEndsWith('/wp/v2/users/me', $captured_url);

        $_POST = [];
    }
}
