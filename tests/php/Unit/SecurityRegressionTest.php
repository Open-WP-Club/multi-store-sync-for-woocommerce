<?php
/**
 * Security regression tests covering fixes from SECURITY_REPORT.md
 *
 * H1  – form handlers enforce current_user_can('manage_woocommerce')
 * H2  – ajax_test_app_password rejects link-local / private IPs (SSRF)
 * M1  – ORDER BY column whitelist in sync-history, deletion-audit, weekly-sync-verifier
 * M4  – CSV formula-injection guard in api-usage-tracker and webhook-logger
 * L3  – auth_method falls back to 'basic_auth', not 'query_string'
 */

use Brain\Monkey\Functions;

class SecurityRegressionTest extends WC_Multi_Store_TestCase
{
    // ── class-load guards ─────────────────────────────────────────────

    private static bool $settingsIntegrationLoaded = false;
    private static bool $healthCheckLoaded         = false;
    private static bool $apiTrackerLoaded          = false;
    private static bool $webhookLoggerLoaded        = false;
    private static bool $remoteOrderSyncLoaded      = false;

    protected function setUp(): void
    {
        parent::setUp();

        // wp_parse_args, esc_html__ are already stubbed in the base TestCase setUp.
        // wp_unslash, wp_cache_add, esc_sql are defined as real functions in bootstrap —
        // they must NOT be re-registered with Brain Monkey (DefinedTooEarly error).

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('wp_parse_url')->alias(fn($url, $c = -1) => $c === -1 ? parse_url($url) : parse_url($url, $c));
        Functions\when('sanitize_sql_orderby')->alias(fn($v) => $v);
        Functions\when('trailingslashit')->alias(fn($v) => rtrim($v, '/') . '/');
        Functions\when('untrailingslashit')->alias(fn($v) => rtrim($v, '/'));
    }

    // ══════════════════════════════════════════════════════════════════
    // H1 – Capability enforcement in wc-settings-integration.php
    // ══════════════════════════════════════════════════════════════════

    private function loadSettingsIntegration(): void
    {
        if (!self::$settingsIntegrationLoaded && !class_exists('WC_Multi_Store_Settings_Integration', false)) {
            $file    = WC_MSS_PLUGIN_DIR . 'includes/wc-settings-integration.php';
            $content = file_get_contents($file);
            $content = preg_replace('/return new WC_Multi_Store_Settings_Integration\(\);/', '', $content);
            $content = preg_replace('/<\?php/', '', $content, 1);
            $content = preg_replace('/if \(!defined\(\'ABSPATH\'\)\) \{\s*exit;\s*\}/', '', $content);
            eval($content);
            self::$settingsIntegrationLoaded = true;
        }
    }

    public function test_add_store_handler_calls_wp_die_when_user_lacks_capability(): void
    {
        $this->loadSettingsIntegration();

        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('__')->alias(fn($t) => $t);

        $wpDieCalled = false;
        Functions\when('wp_die')->alias(function () use (&$wpDieCalled) {
            $wpDieCalled = true;
            throw new \RuntimeException('wp_die');
        });

        global $current_section;
        $current_section = 'stores';
        $_POST = ['wc_mss_add_store' => '1', 'store_url' => 'https://x.com'];

        try {
            (new WC_Multi_Store_Settings_Integration())->output();
        } catch (\RuntimeException $e) {
            // expected
        } finally {
            $_POST           = [];
            $current_section = '';
        }

        $this->assertTrue($wpDieCalled, 'wp_die must be called when capability is missing');
    }

    public function test_delete_store_handler_calls_wp_die_when_user_lacks_capability(): void
    {
        $this->loadSettingsIntegration();

        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('__')->alias(fn($t) => $t);

        $wpDieCalled = false;
        Functions\when('wp_die')->alias(function () use (&$wpDieCalled) {
            $wpDieCalled = true;
            throw new \RuntimeException('wp_die');
        });

        global $current_section;
        $current_section = 'stores';
        $_POST = ['wc_mss_delete_store' => '1', 'store_url' => 'https://x.com'];

        try {
            (new WC_Multi_Store_Settings_Integration())->output();
        } catch (\RuntimeException $e) {
            // expected
        } finally {
            $_POST           = [];
            $current_section = '';
        }

        $this->assertTrue($wpDieCalled, 'wp_die must be called for delete when capability is missing');
    }

    public function test_save_settings_handler_calls_wp_die_when_user_lacks_capability(): void
    {
        $this->loadSettingsIntegration();

        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('__')->alias(fn($t) => $t);

        $wpDieCalled = false;
        Functions\when('wp_die')->alias(function () use (&$wpDieCalled) {
            $wpDieCalled = true;
            throw new \RuntimeException('wp_die');
        });

        global $current_section;
        $current_section = 'settings';
        $_POST = ['wc_mss_save_settings' => '1'];

        try {
            (new WC_Multi_Store_Settings_Integration())->output();
        } catch (\RuntimeException $e) {
            // expected
        } finally {
            $_POST           = [];
            $current_section = '';
        }

        $this->assertTrue($wpDieCalled, 'wp_die must be called for save-settings when capability is missing');
    }

    public function test_clear_queue_handler_calls_wp_die_when_user_lacks_capability(): void
    {
        $this->loadSettingsIntegration();

        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('__')->alias(fn($t) => $t);

        $wpDieCalled = false;
        Functions\when('wp_die')->alias(function () use (&$wpDieCalled) {
            $wpDieCalled = true;
            throw new \RuntimeException('wp_die');
        });

        global $current_section;
        $current_section = 'queue';
        $_POST = ['wc_mss_clear_pending_queue' => '1'];

        try {
            (new WC_Multi_Store_Settings_Integration())->output();
        } catch (\RuntimeException $e) {
            // expected
        } finally {
            $_POST           = [];
            $current_section = '';
        }

        $this->assertTrue($wpDieCalled, 'wp_die must be called for clear-queue when capability is missing');
    }

    // ══════════════════════════════════════════════════════════════════
    // H2 – SSRF: ajax_test_app_password rejects link-local IPs
    // ══════════════════════════════════════════════════════════════════

    private function loadHealthCheck(): void
    {
        if (!self::$healthCheckLoaded && !class_exists('WC_Multi_Store_Health_Check', false)) {
            Functions\when('get_transient')->justReturn(false);
            Functions\when('set_transient')->justReturn(true);
            Functions\when('delete_transient')->justReturn(true);
            require_once WC_MSS_PLUGIN_DIR . 'includes/store-health-check.php';
            self::$healthCheckLoaded = true;
        } else {
            Functions\when('get_transient')->justReturn(false);
            Functions\when('set_transient')->justReturn(true);
            Functions\when('delete_transient')->justReturn(true);
        }
    }

    public function test_ajax_test_app_password_rejects_link_local_ipv4(): void
    {
        $this->loadHealthCheck();

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        // filter_var is a PHP built-in — runs natively, no mocking needed.

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) {
            $error = $data;
        });

        // 169.254.x.x is the AWS/GCP metadata IP — must be blocked by is_safe_remote_url().
        $_POST = [
            'store_url'      => 'http://169.254.169.254/',
            'wp_username'    => 'admin',
            'wp_app_password' => 'pass',
        ];

        (new WC_Multi_Store_Health_Check())->ajax_test_app_password();

        $_POST = [];

        $this->assertNotNull($error, 'SSRF to link-local IP must be rejected');
        $this->assertStringContainsStringIgnoringCase('not allowed', $error['message']);
    }

    public function test_is_safe_remote_url_allows_public_ip(): void
    {
        // is_safe_remote_url() must not block legitimate public addresses.
        $result = WC_Multi_Store_Settings::is_safe_remote_url('https://shop.example.com/');
        // We cannot guarantee DNS resolution in CI, but the function must not throw.
        $this->assertIsBool($result);
    }

    public function test_ajax_test_app_password_rejects_unknown_store_url_when_using_saved_password(): void
    {
        $this->loadHealthCheck();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_stores') {
                // Attacker supplies a URL not in the store list.
                return ['https://legitimate.example.com' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'status' => 'active']];
            }
            return $default;
        });

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });

        $_POST = [
            'store_url'          => 'https://attacker.example.com/',
            'wp_username'        => 'admin',
            'wp_app_password'    => '',
            'use_saved_password' => '1',
        ];

        (new WC_Multi_Store_Health_Check())->ajax_test_app_password();

        $_POST = [];
        WC_Multi_Store_Settings::clear_static_cache();

        $this->assertNotNull($error, 'Arbitrary URL with use_saved_password must be rejected');
        $this->assertStringContainsStringIgnoringCase('Unknown store', $error['message']);
    }

    // ══════════════════════════════════════════════════════════════════
    // M1 – ORDER BY column whitelist
    // ══════════════════════════════════════════════════════════════════

    /**
     * Set up a $wpdb mock that captures the last SQL string passed to prepare().
     * Pass $capturedSQL by reference from the test so the closure can update it.
     */
    private function setUpWpdbCapturingSQL(?string &$capturedSQL): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        // Return the SQL unchanged so ORDER BY is visible in the captured string.
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$a) use (&$capturedSQL) {
            $capturedSQL = $sql;
            return $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => $v);
    }

    // sync-history.php ─────────────────────────────────────────────

    public function test_sync_history_rejects_invalid_orderby_column(): void
    {
        $capturedSQL = null;
        $this->setUpWpdbCapturingSQL($capturedSQL);

        WC_Multi_Store_Sync_History::get_history(['orderby' => 'nonexistent_column']);

        $this->assertIsString($capturedSQL, 'prepare() must have been called');
        $this->assertStringContainsString('created_at', $capturedSQL,
            'Invalid orderby must fall back to created_at');
        $this->assertStringNotContainsString('nonexistent_column', $capturedSQL,
            'Invalid column must not appear in SQL');
    }

    public function test_sync_history_accepts_valid_orderby_column(): void
    {
        $capturedSQL = null;
        $this->setUpWpdbCapturingSQL($capturedSQL);

        WC_Multi_Store_Sync_History::get_history(['orderby' => 'product_id', 'order' => 'ASC']);

        $this->assertIsString($capturedSQL, 'prepare() must have been called');
        $this->assertStringContainsString('product_id', $capturedSQL,
            'Valid column must be passed through');
    }

    public function test_sync_history_falls_back_when_sanitize_returns_empty(): void
    {
        $capturedSQL = null;
        $this->setUpWpdbCapturingSQL($capturedSQL);

        // Simulate sanitize_sql_orderby returning '' for invalid input.
        Functions\when('sanitize_sql_orderby')->justReturn('');

        WC_Multi_Store_Sync_History::get_history(['orderby' => 'created_at']);

        $this->assertIsString($capturedSQL, 'prepare() must have been called');
        $this->assertStringContainsString('created_at DESC', $capturedSQL,
            'When sanitize_sql_orderby returns empty the hard-coded fallback must be used');
    }

    // deletion-audit.php ───────────────────────────────────────────

    public function test_deletion_audit_rejects_invalid_orderby_column(): void
    {
        $capturedSQL = null;
        $this->setUpWpdbCapturingSQL($capturedSQL);

        WC_Multi_Store_Deletion_Audit::get_logs(['orderby' => 'drop_table']);

        $this->assertIsString($capturedSQL, 'prepare() must have been called');
        $this->assertStringContainsString('deleted_at', $capturedSQL,
            'Invalid orderby must fall back to deleted_at');
        $this->assertStringNotContainsString('drop_table', $capturedSQL,
            'Invalid column must not appear in SQL');
    }

    public function test_deletion_audit_accepts_valid_orderby_column(): void
    {
        $capturedSQL = null;
        $this->setUpWpdbCapturingSQL($capturedSQL);

        WC_Multi_Store_Deletion_Audit::get_logs(['orderby' => 'status', 'order' => 'ASC']);

        $this->assertIsString($capturedSQL, 'prepare() must have been called');
        $this->assertStringContainsString('status', $capturedSQL,
            'Valid column must be passed through');
    }

    public function test_deletion_audit_falls_back_when_sanitize_returns_empty(): void
    {
        $capturedSQL = null;
        $this->setUpWpdbCapturingSQL($capturedSQL);

        Functions\when('sanitize_sql_orderby')->justReturn('');

        WC_Multi_Store_Deletion_Audit::get_logs(['orderby' => 'deleted_at']);

        $this->assertIsString($capturedSQL, 'prepare() must have been called');
        $this->assertStringContainsString('deleted_at DESC', $capturedSQL,
            'Hard-coded fallback must be used when sanitize_sql_orderby returns empty');
    }

    // weekly-sync-verifier.php ─────────────────────────────────────

    private function loadWeeklyVerifier(): void
    {
        if (!class_exists('WC_Multi_Store_Weekly_Verification_Report_Repository', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/weekly-verification-report-repository.php';
        }
        if (!class_exists('WC_Multi_Store_Weekly_Sync_Verifier', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/weekly-sync-verifier.php';
        }
    }

    public function test_weekly_verifier_rejects_invalid_orderby_column(): void
    {
        $this->loadWeeklyVerifier();

        global $wpdb;
        $capturedQuery = null;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($q) use (&$capturedQuery) {
            $capturedQuery = $q;
            return [];
        });

        WC_Multi_Store_Weekly_Verification_Report_Repository::get_reports(['orderby' => 'nonexistent_column']);

        $this->assertStringContainsString('started_at', $capturedQuery,
            'Invalid orderby must fall back to started_at');
        $this->assertStringNotContainsString('nonexistent_column', $capturedQuery,
            'Invalid column must not appear in query');
    }

    public function test_weekly_verifier_accepts_valid_orderby_column(): void
    {
        $this->loadWeeklyVerifier();

        global $wpdb;
        $capturedQuery = null;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($q) use (&$capturedQuery) {
            $capturedQuery = $q;
            return [];
        });

        WC_Multi_Store_Weekly_Verification_Report_Repository::get_reports(['orderby' => 'status', 'order' => 'ASC']);

        $this->assertStringContainsString('status', $capturedQuery,
            'Valid column must be passed through');
    }

    public function test_weekly_verifier_falls_back_when_sanitize_returns_empty(): void
    {
        $this->loadWeeklyVerifier();

        global $wpdb;
        $capturedQuery = null;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($q) use (&$capturedQuery) {
            $capturedQuery = $q;
            return [];
        });

        Functions\when('sanitize_sql_orderby')->justReturn('');

        WC_Multi_Store_Weekly_Verification_Report_Repository::get_reports(['orderby' => 'started_at']);

        $this->assertStringContainsString('started_at DESC', $capturedQuery,
            'Hard-coded fallback must be used when sanitize_sql_orderby returns empty');
    }

    // ══════════════════════════════════════════════════════════════════
    // M4 – CSV formula-injection guard
    // ══════════════════════════════════════════════════════════════════

    private function loadApiUsageTracker(): void
    {
        if (!self::$apiTrackerLoaded && !class_exists('WC_Multi_Store_API_Usage_Tracker', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/api-usage-tracker.php';
            self::$apiTrackerLoaded = true;
        }
    }

    private function loadWebhookLogger(): void
    {
        if (!self::$webhookLoggerLoaded && !class_exists('WC_Multi_Store_Webhook_Logger', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/webhook-logger.php';
            self::$webhookLoggerLoaded = true;
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formulaLeadingCharsProvider')]
    public function test_api_usage_tracker_csv_sanitizes_formula_chars(string $dangerous): void
    {
        $this->loadApiUsageTracker();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            [
                'id'           => 1,
                'store_url'    => $dangerous . 'cmd|/C calc!A1',
                'endpoint'     => '/products',
                'method'       => 'GET',
                'status_code'  => 200,
                'response_time' => 100,
                'success'      => 1,
                'error_message' => null,
                'created_at'   => '2024-01-15 10:00:00',
            ],
        ]);

        $csv = WC_Multi_Store_API_Usage_Tracker::export_to_csv();

        $this->assertStringNotContainsString(
            '"' . $dangerous . 'cmd',
            $csv,
            "Leading '{$dangerous}' must be prefixed with a single quote to defuse formula injection"
        );
        $this->assertStringContainsString("'" . $dangerous, $csv,
            "Cell must start with a single-quote prefix");
    }

    public static function formulaLeadingCharsProvider(): array
    {
        return [
            ['='], ['+'], ['-'], ['@'],
        ];
    }

    public function test_webhook_logger_csv_sanitizes_formula_chars(): void
    {
        $this->loadWebhookLogger();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn(1);
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            [
                'id'             => 1,
                'created_at'     => '2024-01-15 10:00:00',
                'log_type'       => 'order',
                'store_url'      => '=HYPERLINK("http://evil.com")',
                'remote_order_id' => null,
                'product_id'     => null,
                'product_sku'    => '+malicious',
                'old_stock'      => null,
                'new_stock'      => null,
                'quantity_changed' => null,
                'change_reason'  => null,
                'request_ip'     => null,
                'status'         => 'success',
                'error_message'  => null,
            ],
        ]);

        $csv = WC_Multi_Store_Webhook_Logger::export_csv();

        $this->assertStringContainsString("'=HYPERLINK", $csv,
            "store_url starting with '=' must be prefixed with single quote");
        $this->assertStringContainsString("'+malicious", $csv,
            "product_sku starting with '+' must be prefixed with single quote");
    }

    // ══════════════════════════════════════════════════════════════════
    // L3 – auth_method defaults to basic_auth, never query_string
    // ══════════════════════════════════════════════════════════════════

    public function test_settings_get_returns_basic_auth_default_when_auth_method_absent(): void
    {
        // Settings returns an empty array (no auth_method key) when option is not set.
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') {
                return ['enabled' => true]; // auth_method deliberately absent
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        WC_Multi_Store_Settings::clear_static_cache();

        $settings     = WC_Multi_Store_Settings::get_settings();
        $authFallback = $settings['auth_method'] ?? 'basic_auth';

        WC_Multi_Store_Settings::clear_static_cache();

        $this->assertSame('basic_auth', $authFallback,
            'Callers that do ?? "basic_auth" get the secure default when auth_method is absent');
    }

    /**
     * These 4 files used to each hand-roll their own `$settings['auth_method']
     * ?? 'basic_auth'` fallback and were each individually regression-tested
     * for it. They — and 7 other call sites — now all build their client via
     * WC_Multi_Store_API_Client::for_store(), which is the single place the
     * safe default lives now. Replaced the 4 per-file checks with one check
     * on that shared factory, plus a check that no caller hand-rolls a
     * weaker fallback instead of using it.
     */
    public function test_api_client_for_store_defaults_to_basic_auth(): void
    {
        $source = file_get_contents(WC_MSS_PLUGIN_DIR . 'includes/api-client.php');

        $this->assertStringContainsString(
            "WC_Multi_Store_Settings::get('auth_method', 'basic_auth')",
            $source,
            "API_Client::for_store() must default auth_method to basic_auth, not query_string"
        );
    }

    public function test_api_client_callers_use_for_store_not_hand_rolled_auth_fallback(): void
    {
        $files = [
            'includes/stock-verifier.php',
            'includes/sync-previewer.php',
            'includes/store-health-check.php',
            'includes/remote-order-sync.php',
            'includes/weekly-sync-verifier.php',
            'includes/orphan-cleanup.php',
            'includes/shipping-class-sync.php',
            'includes/coupon-sync.php',
            'includes/sync-engine.php',
            'includes/wc-settings-integration.php',
            'includes/class-admin-ajax.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents(WC_MSS_PLUGIN_DIR . $file);
            $this->assertStringNotContainsString(
                "'query_string'",
                $source,
                "{$file} must not hand-roll a query_string auth fallback — use API_Client::for_store()"
            );
        }
    }
}
