<?php
/**
 * Tests for the WC_Multi_Store_Settings_Integration form handlers, capability
 * gate and section output() routing that WcSettingsIntegrationTest does not cover.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class WcSettingsIntegrationHandlersTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Settings_Integration $integration;

    /** @var array<string, mixed> Simulated wp_options table. */
    private array $options = [];

    /** @var array<int, array{0: string, 1: mixed}> update_option() calls. */
    private array $updates = [];

    /** @var array<int, array<int, mixed>> wp_die() invocations. */
    private array $died = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('remove_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html')->alias(fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(function ($t, $d = 'default') {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_attr__')->alias(fn($t, $d = 'default') => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr_e')->alias(function ($t, $d = 'default') {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_textarea')->alias(fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('wp_json_encode')->alias(fn($d, $f = 0) => json_encode($d, $f));
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('as_unschedule_all_actions')->justReturn(0);
        Functions\when('as_schedule_recurring_action')->justReturn(1);
        Functions\when('add_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $this->options = [];
        $this->updates = [];
        $this->died    = [];

        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->updates[] = [$key, $value];
            return true;
        });
        Functions\when('wp_die')->alias(function ($message = '', $title = '', $args = []) {
            $this->died[] = [$message, $title, $args];
            throw new RuntimeException('wp_die:' . $message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub.
        });

        if (!class_exists('WC_Multi_Store_Settings_Integration', false)) {
            $content = (string) file_get_contents(WC_MSS_PLUGIN_DIR . 'includes/wc-settings-integration.php');
            $content = preg_replace('/return new WC_Multi_Store_Settings_Integration\(\);/', '', $content);
            $content = preg_replace('/<\?php/', '', (string) $content, 1);
            $content = preg_replace('/if \(!defined\(\'ABSPATH\'\)\) \{\s*exit;\s*\}/', '', (string) $content);
            eval($content);
        }

        $this->integration = new WC_Multi_Store_Settings_Integration();
        WC_Admin_Settings::reset();
        WC_Multi_Store_Settings::clear_static_cache();
        WP_Query::$resultsQueue = null;
        $_POST = [];
        $_GET  = [];
        unset($GLOBALS['current_section']);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        WP_Query::$resultsQueue = null;
        unset($GLOBALS['current_section']);
        WC_Admin_Settings::reset();
        parent::tearDown();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($this->integration, $method);
        return $ref->invoke($this->integration, ...$args);
    }

    private function updated(string $key): mixed
    {
        $found = null;
        foreach ($this->updates as [$k, $v]) {
            if ($k === $key) {
                $found = $v;
            }
        }
        return $found;
    }

    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    // ─── require_manage_capability ─────────────────

    public function test_require_manage_capability_passes_silently_for_authorised_user(): void
    {
        $this->call('require_manage_capability');

        $this->assertSame([], $this->died);
    }

    public function test_require_manage_capability_dies_with_403_for_unauthorised_user(): void
    {
        Functions\when('current_user_can')->alias(function ($cap) {
            $this->assertSame('manage_woocommerce', $cap);
            return false;
        });

        try {
            $this->call('require_manage_capability');
            $this->fail('wp_die() was expected');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertCount(1, $this->died);
        $this->assertSame('You do not have permission to perform this action.', $this->died[0][0]);
        $this->assertSame('Insufficient permissions', $this->died[0][1]);
        $this->assertSame(['response' => 403], $this->died[0][2]);
    }

    public static function gated_handler_provider(): array
    {
        return [
            'add store'               => ['handle_add_store', []],
            'delete store'            => ['handle_delete_store', []],
            'update store'            => ['handle_update_store', []],
            'save settings'           => ['handle_save_settings', []],
            'save weekly verification' => ['handle_save_weekly_verification', []],
            'reschedule actions'      => ['handle_reschedule_actions', []],
            'clear queue'             => ['handle_clear_queue', ['pending']],
            'reset stuck queue'       => ['handle_reset_stuck_queue', []],
            'retry failed queue'      => ['handle_retry_failed_queue', []],
            'run verification now'    => ['handle_run_verification_now', []],
        ];
    }

    #[DataProvider('gated_handler_provider')]
    public function test_every_state_changing_handler_enforces_capability_before_touching_data(string $handler, array $args): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $_POST = ['store_url' => 'https://a.test', 'consumer_key' => 'k', 'consumer_secret' => 's', 'verification_enabled' => '1'];

        try {
            $this->call($handler, ...$args);
            $this->fail("$handler did not call wp_die() for a user without manage_woocommerce");
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertSame([], $this->updates, 'No option may be written by an unauthorised request');
        $this->assertSame([], WC_Admin_Settings::get_messages());
        $this->assertSame([], WC_Admin_Settings::get_errors());
        $this->assertSame(403, $this->died[0][2]['response']);
    }

    // ─── handle_save_weekly_verification ───────────

    public function test_save_weekly_verification_persists_sanitised_values_and_reports_success(): void
    {
        $_POST = [
            'verification_enabled'  => '1',
            'verification_schedule' => '<b>weekly</b>',
            'verification_day'      => '3',
            'verification_time'     => '04:30',
            'check_stock'           => '1',
            'product_limit'         => '250',
            'batch_size'            => '50',
            'sample_mode'           => 'random',
            'auto_correct'          => '1',
            'auto_correct_limit'    => '900',
            'email_enabled'         => '1',
            'email_recipients'      => 'ops@example.com',
            'weekly_sync_type'      => 'price_quantity',
            'weekly_stock_sync'     => 'disabled',
            'weekly_category_auto_create' => 'enabled',
        ];

        $this->call('handle_save_weekly_verification');

        $saved = $this->updated('wc_multi_store_sync_weekly_verification');
        $this->assertIsArray($saved);
        $this->assertTrue($saved['enabled']);
        $this->assertSame('weekly', $saved['schedule'], 'HTML must be stripped');
        $this->assertSame(3, $saved['day_of_week']);
        $this->assertSame('04:30', $saved['time_of_day']);
        $this->assertTrue($saved['check_stock']);
        $this->assertFalse($saved['check_prices'], 'Unchecked boxes are saved as false');
        $this->assertSame(250, $saved['product_limit']);
        $this->assertSame(50, $saved['batch_size']);
        $this->assertSame('random', $saved['sample_mode']);
        $this->assertTrue($saved['auto_correct']);
        $this->assertSame(900, $saved['auto_correct_limit']);
        $this->assertTrue($saved['email_enabled']);
        $this->assertSame('ops@example.com', $saved['email_recipients']);
        $this->assertSame('price_quantity', $saved['weekly_sync_type']);
        $this->assertSame('disabled', $saved['weekly_stock_sync']);
        $this->assertSame('enabled', $saved['weekly_category_auto_create']);
        $this->assertSame(['Weekly verification settings saved successfully.'], WC_Admin_Settings::get_messages());
        $this->assertSame([], WC_Admin_Settings::get_errors());
    }

    public function test_save_weekly_verification_with_empty_post_applies_documented_defaults(): void
    {
        $this->options['admin_email'] = 'admin@example.com';

        $this->call('handle_save_weekly_verification');

        $saved = $this->updated('wc_multi_store_sync_weekly_verification');
        $this->assertFalse($saved['enabled']);
        $this->assertSame('weekly', $saved['schedule']);
        $this->assertSame(1, $saved['day_of_week']);
        $this->assertSame('02:00', $saved['time_of_day']);
        $this->assertFalse($saved['check_stock']);
        $this->assertFalse($saved['check_prices']);
        $this->assertSame(0, $saved['product_limit']);
        $this->assertSame(20, $saved['batch_size']);
        $this->assertSame('all', $saved['sample_mode']);
        $this->assertFalse($saved['auto_correct']);
        $this->assertSame(500, $saved['auto_correct_limit']);
        $this->assertFalse($saved['email_enabled']);
        $this->assertSame('', $saved['email_recipients']);
        $this->assertSame('use_default', $saved['weekly_sync_type']);
        $this->assertSame('use_default', $saved['weekly_stock_sync']);
        $this->assertSame('use_default', $saved['weekly_category_auto_create']);
    }

    public static function numeric_clamp_provider(): array
    {
        return [
            'batch size above max'    => ['batch_size', '5000', 'batch_size', 100],
            'batch size zero'         => ['batch_size', '0', 'batch_size', 1],
            'batch size negative'     => ['batch_size', '-7', 'batch_size', 1],
            'batch size garbage'      => ['batch_size', 'abc', 'batch_size', 1],
            'auto correct above max'  => ['auto_correct_limit', '99999', 'auto_correct_limit', 10000],
            'auto correct negative'   => ['auto_correct_limit', '-5', 'auto_correct_limit', 0],
            'auto correct in range'   => ['auto_correct_limit', '10000', 'auto_correct_limit', 10000],
            'day garbage becomes 0'   => ['verification_day', 'monday', 'day_of_week', 0],
        ];
    }

    #[DataProvider('numeric_clamp_provider')]
    public function test_save_weekly_verification_clamps_numeric_fields(string $field, string $input, string $key, int $expected): void
    {
        $_POST = [$field => $input];

        $this->call('handle_save_weekly_verification');

        $saved = $this->updated('wc_multi_store_sync_weekly_verification');
        $this->assertSame($expected, $saved[$key]);
    }

    public static function invalid_choice_provider(): array
    {
        return [
            'sync type bogus'        => ['weekly_sync_type', 'drop table', 'weekly_sync_type'],
            'sync type array'        => ['weekly_sync_type', ['full_product'], 'weekly_sync_type'],
            'stock sync bogus'       => ['weekly_stock_sync', 'maybe', 'weekly_stock_sync'],
            'stock sync wrong case'  => ['weekly_stock_sync', 'ENABLED', 'weekly_stock_sync'],
            'category bogus'         => ['weekly_category_auto_create', 'quantity', 'weekly_category_auto_create'],
        ];
    }

    #[DataProvider('invalid_choice_provider')]
    public function test_save_weekly_verification_rejects_values_outside_whitelist(string $field, mixed $input, string $key): void
    {
        $_POST = [$field => $input];

        $this->call('handle_save_weekly_verification');

        $saved = $this->updated('wc_multi_store_sync_weekly_verification');
        $this->assertSame('use_default', $saved[$key]);
    }

    public function test_save_weekly_verification_preserves_unrelated_existing_keys(): void
    {
        $this->options['wc_multi_store_sync_weekly_verification'] = ['custom_marker' => 'keep-me'];

        $this->call('handle_save_weekly_verification');

        $saved = $this->updated('wc_multi_store_sync_weekly_verification');
        $this->assertSame('keep-me', $saved['custom_marker']);
    }

    public function test_save_weekly_verification_unslashes_post_data(): void
    {
        $_POST = ['email_recipients' => 'o\\\'brien@example.com', 'sample_mode' => 'recent'];

        $this->call('handle_save_weekly_verification');

        $saved = $this->updated('wc_multi_store_sync_weekly_verification');
        $this->assertSame("o'brien@example.com", $saved['email_recipients']);
    }

    public function test_save_weekly_verification_failure_to_persist_adds_no_success_message(): void
    {
        Functions\when('update_option')->justReturn(false);

        $this->call('handle_save_weekly_verification');

        $this->assertSame([], WC_Admin_Settings::get_messages());
    }

    public function test_save_weekly_verification_schedules_recurring_action_when_enabled(): void
    {
        $scheduled = [];
        // After update_option the stored value is read back; emulate WP persistence.
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->updates[] = [$key, $value];
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('as_schedule_recurring_action')->alias(function (...$args) use (&$scheduled) {
            $scheduled[] = $args;
            return 1;
        });
        $_POST = ['verification_enabled' => '1', 'verification_day' => '2', 'verification_time' => '03:15'];

        $this->call('handle_save_weekly_verification');

        $this->assertCount(1, $scheduled);
        $this->assertSame(WEEK_IN_SECONDS, $scheduled[0][1]);
        $this->assertSame('wc_multi_store_weekly_verification', $scheduled[0][2]);
        $this->assertGreaterThan(time() - 5, $scheduled[0][0]);
    }

    public function test_save_weekly_verification_does_not_schedule_when_disabled(): void
    {
        $scheduled = 0;
        Functions\when('as_schedule_recurring_action')->alias(function () use (&$scheduled) {
            $scheduled++;
            return 1;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });

        $this->call('handle_save_weekly_verification');

        $this->assertSame(0, $scheduled);
    }

    // ─── handle_run_verification_now ───────────────

    public function test_run_verification_now_reports_disabled_verification_as_error(): void
    {
        $this->call('handle_run_verification_now');

        $this->assertSame(['Verification disabled'], WC_Admin_Settings::get_errors());
        $this->assertSame([], WC_Admin_Settings::get_messages());
    }

    public function test_run_verification_now_reports_running_lock(): void
    {
        $this->options['wc_multi_store_sync_weekly_verification'] = ['enabled' => true];
        $this->options['wc_mss_verification_lock'] = time();

        $this->call('handle_run_verification_now');

        $this->assertSame(['Verification already running'], WC_Admin_Settings::get_errors());
    }

    public function test_run_verification_now_maps_no_active_stores_to_friendly_message(): void
    {
        $this->options['wc_multi_store_sync_weekly_verification'] = ['enabled' => true];
        $this->options['wc_multi_store_sync_stores'] = [
            'https://inactive.test' => ['status' => 'inactive'],
        ];

        $this->call('handle_run_verification_now');

        $errors = WC_Admin_Settings::get_errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('No active stores found', $errors[0]);
        $this->assertStringContainsString('Active', $errors[0]);
    }

    public function test_run_verification_now_maps_no_products_to_friendly_message(): void
    {
        $this->options['wc_multi_store_sync_weekly_verification'] = ['enabled' => true];
        $this->options['wc_multi_store_sync_stores'] = [
            'https://active.test' => ['status' => 'active', 'consumer_key' => 'k', 'consumer_secret' => 's'],
        ];
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 0]);
        WP_Query::$resultsQueue = [[]];

        $this->call('handle_run_verification_now');

        $errors = WC_Admin_Settings::get_errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('No products found to verify', $errors[0]);
        $this->assertSame([], WC_Admin_Settings::get_messages());
    }

    public function test_run_verification_now_converts_exceptions_into_failure_notice(): void
    {
        $this->options['wc_multi_store_sync_weekly_verification'] = ['enabled' => true];
        Functions\when('add_option')->alias(function () {
            throw new Exception('lock backend exploded');
        });

        $this->call('handle_run_verification_now');

        $this->assertSame(['Verification failed: lock backend exploded'], WC_Admin_Settings::get_errors());
        $this->assertSame([], WC_Admin_Settings::get_messages());
    }

    // ─── output_buttons / output() routing ─────────

    public function test_output_buttons_emits_nothing_to_suppress_woocommerce_save_button(): void
    {
        $html = $this->capture(fn() => $this->integration->output_buttons());

        $this->assertSame('', $html);
    }

    public function test_output_config_section_renders_redacted_preview_and_controls(): void
    {
        $GLOBALS['current_section'] = 'config';
        $this->options['wc_multi_store_sync_stores'] = [
            'https://shop.test' => ['status' => 'active', 'consumer_key' => 'ck_TOPSECRET', 'consumer_secret' => 'cs_TOPSECRET'],
        ];
        $this->options['wc_multi_store_sync_webhook_settings'] = ['webhook_secret' => 'whsec_TOPSECRET'];

        $html = $this->capture(fn() => $this->integration->output());

        $this->assertStringContainsString('Export / Import Configuration', $html);
        $this->assertStringContainsString('id="wc-mss-export-btn"', $html);
        $this->assertStringContainsString('id="wc-mss-import-file"', $html);
        $this->assertStringContainsString('https://shop.test', $html);
        $this->assertStringContainsString('***REDACTED***', $html);
        $this->assertStringNotContainsString('ck_TOPSECRET', $html);
        $this->assertStringNotContainsString('cs_TOPSECRET', $html);
        $this->assertStringNotContainsString('whsec_TOPSECRET', $html);
    }

    public function test_output_config_section_escapes_hostile_store_url_in_preview(): void
    {
        $GLOBALS['current_section'] = 'config';
        $this->options['wc_multi_store_sync_stores'] = [
            'https://x.test/</textarea><script>alert(1)</script>' => ['status' => 'active'],
        ];

        $html = $this->capture(fn() => $this->integration->output());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame(1, substr_count($html, '</textarea>'), 'Hostile value must not break out of the textarea');
    }

    public function test_output_sync_profiles_section_shows_empty_state_without_saved_profiles(): void
    {
        $GLOBALS['current_section'] = 'sync-profiles';

        $html = $this->capture(fn() => $this->integration->output());

        $this->assertStringContainsString('class="wrap wc-mss-sync-profiles"', $html);
        $this->assertStringContainsString('id="wc-mss-save-profile-btn"', $html);
        $this->assertStringContainsString('No saved profiles yet', $html);
    }

    public function test_output_sync_profiles_section_hides_empty_state_when_profiles_exist(): void
    {
        $GLOBALS['current_section'] = 'sync-profiles';
        $this->options['wc_mss_sync_profiles'] = ['p1' => ['name' => 'Peak']];

        $html = $this->capture(fn() => $this->integration->output());

        $this->assertStringNotContainsString('No saved profiles yet', $html);
        $this->assertStringContainsString('Saved Profiles', $html);
    }

    public function test_output_logs_section_renders_empty_state_when_no_log_exists(): void
    {
        $GLOBALS['current_section'] = 'logs';
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 7, 'name' => '<img src=x onerror=alert(1)>', 'count' => 3],
        ]);
        $handler = 'WC_Log_Handler_File';
        $path = $handler::get_log_file_path('wc-multi-store-sync');
        if (file_exists($path) && filesize($path) > 0) {
            $this->markTestSkipped('A shared log file already has content (parallel test run).');
        }

        $html = $this->capture(fn() => $this->integration->output());

        $this->assertStringContainsString('Sync Logs', $html);
        $this->assertStringContainsString('id="wc-mss-clear-log"', $html);
        $this->assertStringContainsString('No log entries found.', $html);
        $this->assertStringNotContainsString('id="wc-mss-log-content"', $html);
        $this->assertStringContainsString('<option value="7">&lt;img src=x onerror=alert(1)&gt; (3)</option>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_output_with_unknown_section_falls_back_to_dashboard_not_other_views(): void
    {
        $GLOBALS['current_section'] = 'does-not-exist';

        // The dashboard view needs WP functions that are not available in the
        // unit environment; reaching output_dashboard() is what we assert.
        $frames = [];
        $html = '';
        try {
            $html = $this->capture(fn() => $this->integration->output());
        } catch (Throwable $e) {
            $frames = array_column($e->getTrace(), 'function');
        }

        $this->assertStringNotContainsString('Export / Import Configuration', $html);
        $this->assertStringNotContainsString('Sync Logs', $html);
        $this->assertStringNotContainsString('Sync Profiles', $html);
        $this->assertTrue(
            in_array('output_dashboard', $frames, true) || str_contains($html, 'wc-mss-dashboard'),
            'The default arm must route to output_dashboard()'
        );
    }

    public function test_output_does_not_run_handlers_for_sections_that_do_not_own_the_post_key(): void
    {
        $GLOBALS['current_section'] = 'config';
        $_POST = ['wc_mss_save_weekly_verification' => '1', 'verification_enabled' => '1'];
        $checked = [];
        Functions\when('check_admin_referer')->alias(function ($action) use (&$checked) {
            $checked[] = $action;
            return true;
        });

        $this->capture(fn() => $this->integration->output());

        $this->assertSame([], $checked, 'No nonce check or handler should run for a mismatched section');
        $this->assertNull($this->updated('wc_multi_store_sync_weekly_verification'));
    }
}
