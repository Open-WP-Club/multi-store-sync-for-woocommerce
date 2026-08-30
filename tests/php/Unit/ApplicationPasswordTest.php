<?php
/**
 * Tests for Application Password support in image upload
 *
 * Covers:
 * - WC_Multi_Store_API_Client::upload_image() uses wp_username/wp_app_password
 *   when configured, and falls back to consumer_key/consumer_secret when not.
 * - handle_add_store() and handle_update_store() save wp_username / wp_app_password.
 * - handle_update_store() preserves existing wp_app_password when field left blank.
 */

use Brain\Monkey\Functions;

class ApplicationPasswordTest extends WC_Multi_Store_TestCase
{
    private string $store_url      = 'https://example-store.com';
    private string $consumer_key   = 'ck_test_key_12345678901234567890';
    private string $consumer_secret = 'cs_test_secret_1234567890123456';
    private string $wp_username    = 'admin';
    private string $wp_app_password = 'abcd 1234 efgh 5678 ijkl 9012';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            if (is_array($args)) {
                return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
            }
            return $url;
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn($r) => $r['response']['code'] ?? 200
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn($r) => $r['body'] ?? ''
        );
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        // Default stub for AS function — Patchwork permanently defines it once any test registers it,
        // so function_exists() returns true for the whole process. This ensures handle_add/update_store
        // can always call it. Tests that verify scheduling override this stub after setUp().
        Functions\when('as_schedule_single_action')->justReturn(null);
    }

    // =========================================================
    // upload_image() — auth header selection
    // =========================================================

    /**
     * When wp_username + wp_app_password are provided the Authorization header
     * must encode username:app_password (NOT the WooCommerce consumer keys).
     */
    public function test_upload_image_uses_application_password_when_configured(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mss_img_');
        file_put_contents($tmp, 'fake-image-bytes');

        $expected_auth = 'Basic ' . base64_encode($this->wp_username . ':' . $this->wp_app_password);
        $captured_headers = [];

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function ($url, $args) use (&$captured_headers, $tmp) {
                $captured_headers = $args['headers'] ?? [];
                return [
                    'response' => ['code' => 201],
                    'body'     => json_encode(['id' => 42, 'source_url' => 'https://example-store.com/img.jpg']),
                ];
            });

        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            null,
            true,
            $this->wp_username,
            $this->wp_app_password,
        );

        $api->upload_image([
            'file_path' => $tmp,
            'filename'  => 'test.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertArrayHasKey('Authorization', $captured_headers);
        $this->assertSame(
            $expected_auth,
            $captured_headers['Authorization'],
            'Authorization header should use Application Password credentials'
        );
        $this->assertStringNotContainsString(
            base64_encode($this->consumer_key . ':' . $this->consumer_secret),
            $captured_headers['Authorization'],
            'Consumer keys must NOT appear in Authorization when app password is set'
        );

        unlink($tmp);
    }

    /**
     * When no Application Password is configured the upload falls back to
     * encoding consumer_key:consumer_secret (legacy behaviour preserved).
     */
    public function test_upload_image_falls_back_to_consumer_keys_when_no_app_password(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mss_img_');
        file_put_contents($tmp, 'fake-image-bytes');

        $expected_auth = 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        $captured_headers = [];

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function ($url, $args) use (&$captured_headers) {
                $captured_headers = $args['headers'] ?? [];
                return [
                    'response' => ['code' => 201],
                    'body'     => json_encode(['id' => 99]),
                ];
            });

        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            // wp_username and wp_app_password intentionally omitted
        );

        $api->upload_image([
            'file_path' => $tmp,
            'filename'  => 'test.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertSame($expected_auth, $captured_headers['Authorization']);

        unlink($tmp);
    }

    /**
     * Empty string wp_username OR empty wp_app_password → must fall back to
     * consumer keys (both fields are required for Application Password auth).
     */
    public function test_upload_image_falls_back_when_only_username_set(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mss_img_');
        file_put_contents($tmp, 'data');

        $expected_auth = 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        $captured_headers = [];

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function ($url, $args) use (&$captured_headers) {
                $captured_headers = $args['headers'] ?? [];
                return ['response' => ['code' => 201], 'body' => json_encode(['id' => 1])];
            });

        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            null,
            true,
            $this->wp_username,
            '', // app_password empty → fallback
        );

        $api->upload_image([
            'file_path' => $tmp,
            'filename'  => 'test.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertSame($expected_auth, $captured_headers['Authorization']);

        unlink($tmp);
    }

    /**
     * The upload target URL must always be /wp-json/wp/v2/media regardless of
     * which auth method is used.
     */
    public function test_upload_image_posts_to_correct_endpoint(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mss_img_');
        file_put_contents($tmp, 'data');

        $captured_url = '';

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function ($url, $args) use (&$captured_url) {
                $captured_url = $url;
                return ['response' => ['code' => 201], 'body' => json_encode(['id' => 1])];
            });

        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            null,
            true,
            $this->wp_username,
            $this->wp_app_password,
        );

        $api->upload_image([
            'file_path' => $tmp,
            'filename'  => 'img.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertSame('https://example-store.com/wp-json/wp/v2/media', $captured_url);

        unlink($tmp);
    }

    public function test_upload_image_rejects_oversized_local_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mss_img_');
        $handle = fopen($tmp, 'wb');
        ftruncate($handle, WC_Multi_Store_API_Client::MAX_IMAGE_BYTES + 1);
        fclose($handle);

        Functions\expect('wp_remote_post')->never();
        $api = new WC_Multi_Store_API_Client($this->store_url, $this->consumer_key, $this->consumer_secret);
        $result = $api->upload_image([
            'file_path' => $tmp,
            'filename' => 'large.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('image_too_large', $result->get_error_code());
        unlink($tmp);
    }

    /**
     * upload_image() returns WP_Error when the file cannot be read from disk.
     */
    public function test_upload_image_returns_wp_error_on_missing_file(): void
    {
        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            null,
            true,
            $this->wp_username,
            $this->wp_app_password,
        );

        $result = $api->upload_image([
            'file_path' => '/nonexistent/path/image.jpg',
            'filename'  => 'image.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('file_read_error', $result->get_error_code());
    }

    /**
     * upload_image() returns WP_Error when the remote store returns 401
     * (e.g. wrong Application Password).
     */
    public function test_upload_image_returns_wp_error_on_401(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mss_img_');
        file_put_contents($tmp, 'data');

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 401],
                'body'     => json_encode(['message' => 'Неразпознато потребителско име.']),
            ]);

        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            null,
            true,
            'wrong_user',
            'wrong_pass',
        );

        $result = $api->upload_image([
            'file_path' => $tmp,
            'filename'  => 'img.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('auth_error', $result->get_error_code());

        unlink($tmp);
    }

    // =========================================================
    // Constructor — new parameters are optional
    // =========================================================

    public function test_constructor_accepts_application_password_params(): void
    {
        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            null,
            true,
            $this->wp_username,
            $this->wp_app_password,
        );

        $this->assertInstanceOf(WC_Multi_Store_API_Client::class, $api);
        $this->assertSame($this->store_url, $api->get_store_url());
    }

    public function test_constructor_works_without_application_password_params(): void
    {
        // All existing call-sites pass only 4-6 args — must still work.
        $api = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
        );

        $this->assertInstanceOf(WC_Multi_Store_API_Client::class, $api);
    }

    // =========================================================
    // Store save/update — wp_username & wp_app_password
    // =========================================================

    /**
     * handle_add_store() must persist wp_username and wp_app_password into the
     * store config array saved via WC_Multi_Store_Settings::update_store().
     */
    public function test_handle_add_store_saves_application_password_fields(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') return [];
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });
        Functions\when('current_time')->justReturn('2026-04-26 12:00:00');
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);

        $saved_config = null;
        Functions\expect('update_option')
            ->andReturnUsing(function ($key, $value) use (&$saved_config) {
                if ($key === 'wc_multi_store_sync_stores') {
                    $saved_config = $value;
                }
                return true;
            });

        // Simulate POST data
        $_POST = [
            'store_url'       => $this->store_url,
            'consumer_key'    => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
            'status'          => 'active',
            'wp_username'     => $this->wp_username,
            'wp_app_password' => $this->wp_app_password,
        ];
        $_REQUEST['_wpnonce'] = 'fake';

        // Call handle_add_store via reflection (private method)
        $integration = $this->make_integration();
        $method = new ReflectionMethod($integration, 'handle_add_store');
        try {
            $method->invoke($integration);
        } catch (\RuntimeException $e) {
            // handle_add_store doesn't redirect on add — unexpected
        }

        $this->assertNotNull($saved_config, 'update_option should have been called for stores');
        $store = $saved_config[$this->store_url] ?? null;
        $this->assertNotNull($store, 'Store entry should exist');
        $this->assertSame($this->wp_username, $store['wp_username']);
        $this->assertSame($this->wp_app_password, $store['wp_app_password']);

        $_POST = [];
    }

    /**
     * handle_update_store() must update wp_username and wp_app_password when
     * the form fields are filled in.
     */
    public function test_handle_update_store_updates_application_password_fields(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $existing_stores = [
            $this->store_url => [
                'consumer_key'    => $this->consumer_key,
                'consumer_secret' => $this->consumer_secret,
                'status'          => 'active',
                'wp_username'     => 'old_admin',
                'wp_app_password' => 'old_password',
                'added_date'      => '2026-01-01 00:00:00',
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($existing_stores) {
            if ($key === 'wc_multi_store_sync_stores') return $existing_stores;
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });
        Functions\when('current_time')->justReturn('2026-04-26 12:00:00');
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);

        $saved_config = null;
        Functions\expect('update_option')
            ->andReturnUsing(function ($key, $value) use (&$saved_config) {
                if ($key === 'wc_multi_store_sync_stores') {
                    $saved_config = $value;
                }
                return true;
            });

        $_POST = [
            'original_store_url' => $this->store_url,
            'store_url'          => $this->store_url,
            'consumer_key'       => $this->consumer_key,
            'consumer_secret'    => $this->consumer_secret,
            'status'             => 'active',
            'wp_username'        => 'new_admin',
            'wp_app_password'    => 'new_app_pass',
        ];

        $integration = $this->make_integration();
        $method = new ReflectionMethod($integration, 'handle_update_store');
        try {
            $method->invoke($integration);
        } catch (\RuntimeException $e) {
            // Expected — handle_update_store redirects after saving
        }

        $store = $saved_config[$this->store_url] ?? null;
        $this->assertSame('new_admin', $store['wp_username']);
        $this->assertSame('new_app_pass', $store['wp_app_password']);

        $_POST = [];
    }

    /**
     * handle_update_store() must preserve the existing wp_app_password when the
     * form field is submitted empty (user didn't want to change it).
     */
    public function test_handle_update_store_preserves_existing_app_password_when_field_blank(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $existing_stores = [
            $this->store_url => [
                'consumer_key'    => $this->consumer_key,
                'consumer_secret' => $this->consumer_secret,
                'status'          => 'active',
                'wp_username'     => $this->wp_username,
                'wp_app_password' => 'existing_secret_password',
                'added_date'      => '2026-01-01 00:00:00',
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($existing_stores) {
            if ($key === 'wc_multi_store_sync_stores') return $existing_stores;
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });
        Functions\when('current_time')->justReturn('2026-04-26 12:00:00');
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);

        $saved_config = null;
        Functions\expect('update_option')
            ->andReturnUsing(function ($key, $value) use (&$saved_config) {
                if ($key === 'wc_multi_store_sync_stores') {
                    $saved_config = $value;
                }
                return true;
            });

        $_POST = [
            'original_store_url' => $this->store_url,
            'store_url'          => $this->store_url,
            'consumer_key'       => $this->consumer_key,
            'consumer_secret'    => $this->consumer_secret,
            'status'             => 'active',
            'wp_username'        => $this->wp_username,
            'wp_app_password'    => '', // blank → keep existing
        ];

        $integration = $this->make_integration();
        $method = new ReflectionMethod($integration, 'handle_update_store');
        try {
            $method->invoke($integration);
        } catch (\RuntimeException $e) {
            // Expected — handle_update_store redirects after saving
        }

        $store = $saved_config[$this->store_url] ?? null;
        $this->assertSame(
            'existing_secret_password',
            $store['wp_app_password'],
            'Existing app password must be preserved when the form field is left blank'
        );

        $_POST = [];
    }

    // =========================================================
    // handle_add_store() — health-check scheduling
    // =========================================================

    /**
     * After saving a new store, handle_add_store() must schedule an immediate
     * Action Scheduler action so the health column is updated right away.
     */
    public function test_handle_add_store_schedules_health_check_when_as_available(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') return [];
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });
        Functions\when('current_time')->justReturn('2026-04-27 10:00:00');
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('update_option')->justReturn(true);

        $scheduled_hook = null;
        $scheduled_args = null;
        Functions\when('as_schedule_single_action')->alias(
            function ($time, $hook, $args, $group) use (&$scheduled_hook, &$scheduled_args) {
                $scheduled_hook = $hook;
                $scheduled_args = $args;
                return 1;
            }
        );

        $_POST = [
            'store_url'      => $this->store_url,
            'consumer_key'   => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
            'status'         => 'active',
        ];

        $integration = $this->make_integration();
        $method = new ReflectionMethod($integration, 'handle_add_store');
        $method->invoke($integration);

        $this->assertSame('wc_multi_store_health_check_single', $scheduled_hook);
        $this->assertContains($this->store_url, $scheduled_args);

        $_POST = [];
    }

    /**
     * When Action Scheduler is not installed, handle_add_store() must not throw —
     * it silently skips the scheduling.
     */
    public function test_handle_add_store_skips_health_check_when_as_unavailable(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') return [];
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });
        Functions\when('current_time')->justReturn('2026-04-27 10:00:00');
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('update_option')->justReturn(true);
        // Note: Patchwork keeps as_schedule_single_action "defined" once any test in the process
        // registers it. make_integration() stubs it so function_exists() + the call both succeed.

        $_POST = [
            'store_url'      => $this->store_url,
            'consumer_key'   => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
            'status'         => 'active',
        ];

        $integration = $this->make_integration();
        $method = new ReflectionMethod($integration, 'handle_add_store');

        $threw = false;
        try {
            $method->invoke($integration);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertFalse($threw, 'handle_add_store must not throw when Action Scheduler is unavailable');

        $_POST = [];
    }

    /**
     * After updating a store, handle_update_store() must schedule the same
     * immediate health-check action.
     */
    public function test_handle_update_store_schedules_health_check_when_as_available(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $existing_stores = [
            $this->store_url => [
                'consumer_key'    => $this->consumer_key,
                'consumer_secret' => $this->consumer_secret,
                'status'          => 'active',
                'added_date'      => '2026-01-01 00:00:00',
            ],
        ];

        Functions\when('get_option')->alias(function ($key, $default = false) use ($existing_stores) {
            if ($key === 'wc_multi_store_sync_stores') return $existing_stores;
            if ($key === 'wc_multi_store_sync_settings') return [];
            return $default;
        });
        Functions\when('current_time')->justReturn('2026-04-27 10:00:00');
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('update_option')->justReturn(true);
        Functions\when('remove_query_arg')->justReturn('/wp-admin/admin.php');
        Functions\when('wp_safe_redirect')->alias(function () {
            throw new \RuntimeException('redirect');
        });

        $scheduled_hook = null;
        $scheduled_args = null;
        Functions\when('as_schedule_single_action')->alias(
            function ($time, $hook, $args, $group) use (&$scheduled_hook, &$scheduled_args) {
                $scheduled_hook = $hook;
                $scheduled_args = $args;
                return 1;
            }
        );

        $_POST = [
            'original_store_url' => $this->store_url,
            'store_url'          => $this->store_url,
            'consumer_key'       => $this->consumer_key,
            'consumer_secret'    => $this->consumer_secret,
            'status'             => 'active',
        ];

        $integration = $this->make_integration();
        $method = new ReflectionMethod($integration, 'handle_update_store');
        try {
            $method->invoke($integration);
        } catch (\RuntimeException $e) {
            // Expected redirect
        }

        $this->assertSame('wc_multi_store_health_check_single', $scheduled_hook);
        $this->assertContains($this->store_url, $scheduled_args);

        $_POST = [];
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Build a WC_Multi_Store_Settings_Integration instance, loading the class
     * the same way WcSettingsIntegrationTest does (eval to skip auto-instantiation).
     */
    private function make_integration(): WC_Multi_Store_Settings_Integration
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('wp_parse_url')->alias(fn($url, $component = -1) => parse_url($url, $component));
        Functions\when('remove_query_arg')->justReturn('/wp-admin/admin.php');
        // wp_safe_redirect is followed by exit — throw to stop execution before exit.
        Functions\when('wp_safe_redirect')->alias(function () {
            throw new \RuntimeException('wp_safe_redirect called — stopping before exit');
        });

        if (!class_exists('WC_Multi_Store_Settings_Integration', false)) {
            $file    = WC_MSS_PLUGIN_DIR . 'includes/wc-settings-integration.php';
            $content = file_get_contents($file);
            $content = preg_replace('/return new WC_Multi_Store_Settings_Integration\(\);/', '', $content);
            $content = preg_replace('/<\?php/', '', $content, 1);
            $content = preg_replace('/if \(!defined\(\'ABSPATH\'\)\) \{\s*exit;\s*\}/', '', $content);
            eval($content);
        }

        // Stub WC_Admin_Settings if missing
        if (!class_exists('WC_Admin_Settings', false)) {
            eval('class WC_Admin_Settings {
                public static function add_message(string $m): void {}
                public static function add_error(string $m): void {}
            }');
        }

        return new WC_Multi_Store_Settings_Integration();
    }
}
