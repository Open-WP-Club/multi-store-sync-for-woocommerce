<?php
/**
 * Unit tests for WC_Multi_Store_Settings_Integration
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WcSettingsIntegrationTest extends WC_Multi_Store_TestCase
{
    private ?WC_Multi_Store_Settings_Integration $integration = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('WC_MSS_PLUGIN_URL')) {
            define('WC_MSS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wc-multi-store-sync/');
        }

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('wp_set_script_translations')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('as_schedule_single_action')->justReturn(null);

        // Load the class after Brain Monkey is set up
        if (!class_exists('WC_Multi_Store_Settings_Integration', false)) {
            // We need to require it but prevent the auto-instantiation at the bottom
            // by loading the class definition manually
            $file = WC_MSS_PLUGIN_DIR . 'includes/wc-settings-integration.php';
            $content = file_get_contents($file);

            // Only load if not already loaded
            if (!class_exists('WC_Multi_Store_Settings_Integration', false)) {
                // Use eval to load only the class (skip the return at end)
                $content = preg_replace('/return new WC_Multi_Store_Settings_Integration\(\);/', '', $content);
                $content = preg_replace('/<\?php/', '', $content, 1);
                $content = preg_replace('/if \(!defined\(\'ABSPATH\'\)\) \{\s*exit;\s*\}/', '', $content);
                eval($content);
            }
        }

        $this->integration = new WC_Multi_Store_Settings_Integration();

        // Prevent message state from leaking across tests
        WC_Admin_Settings::reset();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        parent::tearDown();
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Settings_Integration'));
    }

    public function test_extends_wc_settings_page(): void
    {
        $this->assertInstanceOf('WC_Settings_Page', $this->integration);
    }

    public function test_has_required_methods(): void
    {
        $methods = [
            'get_sections', 'output', 'save', 'get_settings',
            'ajax_test_connection', 'ajax_force_sync_all',
            'ajax_delete_history', 'enqueue_admin_scripts',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_Settings_Integration', $method),
                "Missing method: {$method}"
            );
        }
    }

    // ─── get_sections ──────────────────────────────

    public function test_get_sections_returns_always_visible_sections(): void
    {
        // setUp() mocks get_option() to justReturn([]), so category mapping,
        // attribute mapping and conflict detection all read as disabled
        // (their default) — their tabs must not appear (see
        // test_get_sections_hides_optional_features_by_default below).
        $sections = $this->integration->get_sections();

        $expected_keys = [
            '', 'stores', 'settings', 'queue', 'weekly-verification',
            'history', 'api-usage', 'discrepancies', 'deletion-audit',
            'orphan-cleanup', 'logs',
        ];

        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey($key, $sections, "Missing section: '{$key}'");
        }
    }

    public function test_get_sections_hides_optional_features_by_default(): void
    {
        // Category mapping, attribute mapping and conflict detection are all
        // opt-in — an admin who never turned them on shouldn't see tabs for
        // screens that don't apply to them.
        $sections = $this->integration->get_sections();

        $this->assertArrayNotHasKey('category-mapping', $sections);
        $this->assertArrayNotHasKey('attribute-mapping', $sections);
        $this->assertArrayNotHasKey('conflicts', $sections);
    }

    public function test_get_sections_shows_optional_features_when_enabled(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'category_mapper_enabled' => true,
                    'attribute_remapping_enabled' => true,
                ];
            }
            if ($option === 'wc_mss_conflict_settings') {
                return ['enabled' => true];
            }
            return $default;
        });

        $sections = $this->integration->get_sections();

        $this->assertArrayHasKey('category-mapping', $sections);
        $this->assertArrayHasKey('attribute-mapping', $sections);
        $this->assertArrayHasKey('conflicts', $sections);
    }

    public function test_get_sections_default_is_dashboard(): void
    {
        $sections = $this->integration->get_sections();

        $this->assertEquals('Dashboard', $sections['']);
    }

    public function test_get_sections_count(): void
    {
        // Optional feature tabs (category/attribute mapping, conflicts) are
        // hidden by default — see test_get_sections_hides_optional_features_by_default.
        $sections = $this->integration->get_sections();

        $this->assertCount(14, $sections);
    }

    public function test_get_sections_count_with_all_optional_features_enabled(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'category_mapper_enabled' => true,
                    'attribute_remapping_enabled' => true,
                ];
            }
            if ($option === 'wc_mss_conflict_settings') {
                return ['enabled' => true];
            }
            return $default;
        });

        $sections = $this->integration->get_sections();

        $this->assertCount(17, $sections);
    }

    // ─── save ──────────────────────────────────────

    public function test_save_is_a_noop_override(): void
    {
        // WC_Settings_Page::save() would persist get_settings() fields; this
        // tab saves through per-section handlers, so the override must write nothing.
        $writes = 0;
        Functions\when('update_option')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });
        $_POST = ['store_url' => 'https://store.com'];

        $this->integration->save();

        $this->assertSame(0, $writes);
        $this->assertSame([], WC_Admin_Settings::get_messages());
        $this->assertSame([], WC_Admin_Settings::get_errors());
    }

    // ─── get_settings ──────────────────────────────

    public function test_get_settings_returns_empty_array(): void
    {
        $settings = $this->integration->get_settings();

        $this->assertIsArray($settings);
        $this->assertEmpty($settings);
    }

    // ─── enqueue_admin_scripts ─────────────────────

    public function test_enqueue_admin_scripts_skips_non_wc_settings(): void
    {
        $_GET = ['tab' => 'multi_store_sync'];
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_localize_script')->never();

        $this->integration->enqueue_admin_scripts('edit.php');

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_enqueue_admin_scripts_skips_wrong_tab(): void
    {
        $_GET = ['tab' => 'general'];
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_localize_script')->never();

        $this->integration->enqueue_admin_scripts('woocommerce_page_wc-settings');

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_enqueue_admin_scripts_skips_no_tab(): void
    {
        $_GET = [];
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_localize_script')->never();

        $this->integration->enqueue_admin_scripts('woocommerce_page_wc-settings');

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_enqueue_admin_scripts_loads_on_correct_tab(): void
    {
        $_GET = ['tab' => 'multi_store_sync'];

        Functions\expect('wp_enqueue_style')
            ->once()
            ->with('wc-mss-admin', \Mockery::type('string'), [], \Mockery::any());

        Functions\expect('wp_enqueue_script')->once()->with(
            'wc-mss-admin-ui', \Mockery::type('string'), ['wp-util', 'wp-i18n'], \Mockery::any(), true
        );
        Functions\expect('wp_enqueue_script')->once()->with(
            'wc-mss-verification', \Mockery::type('string'), ['wc-mss-admin'], \Mockery::any(), true
        );

        // The default dashboard also loads Chart.js for the activity chart.
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with('wc-mss-chartjs', \Mockery::type('string'), [], \Mockery::any(), true);

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with('wc-mss-admin', \Mockery::type('string'), ['wc-mss-admin-ui', 'wp-i18n', 'wc-mss-chartjs'], \Mockery::any(), true);

        Functions\expect('wp_localize_script')
            ->atLeast()
            ->once()
            ->with(\Mockery::type('string'), \Mockery::type('string'), \Mockery::type('array'));

        Functions\expect('admin_url')->andReturn('http://example.com/wp-admin/admin-ajax.php');
        Functions\expect('wp_create_nonce')->andReturn('test_nonce');
        Functions\expect('get_terms')->andReturn([]);
        // is_wp_error is defined in bootstrap.php before Patchwork, so we can't
        // use expect(). The real function works fine — it checks instanceof WP_Error,
        // and get_terms returns [], which is not WP_Error, so no mock needed.
        Functions\expect('wp_count_posts')->andReturn((object) ['publish' => 10]);

        $this->integration->enqueue_admin_scripts('woocommerce_page_wc-settings');

        // The exact-argument Mockery expectations above are the proof.
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_settings_load_native_dialog_without_verification_or_chart(): void
    {
        $_GET = ['tab' => 'multi_store_sync', 'section' => 'settings'];
        Functions\expect('wp_enqueue_style')->once()->with('wc-mss-admin', \Mockery::type('string'), [], \Mockery::any());
        Functions\expect('wp_enqueue_style')->once()->with('wp-jquery-ui-dialog');
        Functions\expect('wp_enqueue_script')->once()->with('wc-mss-admin-ui', \Mockery::type('string'), ['wp-util', 'wp-i18n'], \Mockery::any(), true);
        Functions\expect('wp_enqueue_script')->once()->with('wc-mss-admin', \Mockery::type('string'), ['wc-mss-admin-ui', 'wp-i18n', 'jquery-ui-dialog'], \Mockery::any(), true);
        Functions\expect('wp_localize_script')->once()->with('wc-mss-admin', 'wcMssAdmin', \Mockery::type('array'));
        Functions\when('admin_url')->justReturn('https://main.test/wp-admin/admin-ajax.php');
        Functions\when('wp_create_nonce')->justReturn('nonce');
        $this->integration->enqueue_admin_scripts('woocommerce_page_wc-settings');
        $this->addToAssertionCount(1);
    }

    // ─── handle_add_store (private) ────────────────

    public function test_handle_add_store_validates_required_fields(): void
    {
        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_add_store');

        $_POST = [
            'store_url' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'status' => 'active',
        ];

        $method->invoke($this->integration);

        // Should add error for empty fields
        $errors = WC_Admin_Settings::get_errors();
        $this->assertNotEmpty($errors);

        WC_Admin_Settings::reset();
    }

    // ─── handle_delete_store (private) ─────────────

    public function test_handle_delete_store_calls_settings(): void
    {
        $stores = [
            'https://store.com' => ['status' => 'active'],
            'https://other.com' => ['status' => 'active'],
        ];
        Functions\when('get_option')->alias(fn($option, $default = false) => $option === 'wc_multi_store_sync_stores' ? $stores : $default);
        $saved = [];
        Functions\when('update_option')->alias(function ($option, $value) use (&$saved) {
            $saved[$option] = $value;
            return true;
        });

        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_delete_store');
        $_POST = ['store_url' => 'https://store.com'];

        $method->invoke($this->integration);

        $this->assertSame(
            ['https://other.com' => ['status' => 'active']],
            $saved['wc_multi_store_sync_stores'],
            'Only the requested store is removed'
        );
        $this->assertSame(['Store deleted successfully.'], WC_Admin_Settings::get_messages());
    }

    public function test_handle_delete_store_unknown_store_changes_nothing(): void
    {
        Functions\when('get_option')->alias(fn($option, $default = false) => $option === 'wc_multi_store_sync_stores'
            ? ['https://other.com' => ['status' => 'active']]
            : $default);
        $writes = 0;
        Functions\when('update_option')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });

        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_delete_store');
        $_POST = ['store_url' => 'https://missing.com'];

        $method->invoke($this->integration);

        $this->assertSame(0, $writes);
        $this->assertSame([], WC_Admin_Settings::get_messages());
    }

    // ─── handle_clear_queue (private) ──────────────

    public function test_handle_clear_queue_deletes_by_status(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(10);

        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_clear_queue');

        $method->invoke($this->integration, 'pending');

        $messages = WC_Admin_Settings::get_messages();
        $this->assertNotEmpty($messages);

        WC_Admin_Settings::reset();
    }

    // ─── handle_reset_stuck_queue (private) ────────

    public function test_handle_reset_stuck_queue_shows_message(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('UPDATE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_reset_stuck_queue');

        $method->invoke($this->integration);

        $messages = WC_Admin_Settings::get_messages();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('No stuck items', $messages[0]);

        WC_Admin_Settings::reset();
    }

    // ─── handle_retry_failed_queue (private) ───────

    public function test_handle_retry_failed_queue_shows_count(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')->once()
            ->with(\Mockery::pattern("/SET status = 'pending'/"))
            ->andReturn(5);

        $wpdb->shouldReceive('prepare')->once()
            ->with(\Mockery::pattern("/SET status = 'retried'/"), \Mockery::any())
            ->andReturn("UPDATE wp_wc_mss_dead_letter_queue SET status = 'retried'");

        $wpdb->shouldReceive('query')->once()
            ->with(\Mockery::pattern("/SET status = 'retried'/"))
            ->andReturn(3);

        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_retry_failed_queue');

        $method->invoke($this->integration);

        $messages = WC_Admin_Settings::get_messages();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('5', $messages[0]);

        WC_Admin_Settings::reset();
    }

    // ─── handle_reschedule_actions (private) ───────

    public function test_handle_reschedule_actions_delegates_to_reschedule_all(): void
    {
        // No real ActionScheduler class in the test environment, so
        // WC_Multi_Store_Action_Scheduler_Manager::is_available() is false.
        // handle_reschedule_actions() checks this itself before doing
        // anything, same as before this method stopped duplicating
        // reschedule_all()'s unschedule/reschedule calls inline.
        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_reschedule_actions');

        $method->invoke($this->integration);

        $errors = WC_Admin_Settings::get_errors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Action Scheduler', $errors[0]);

        WC_Admin_Settings::reset();
    }

    // ─── process_force_sync_batch (static) ─────────

    public function test_process_force_sync_batch_method_exists(): void
    {
        $this->assertTrue(
            method_exists('WC_Multi_Store_Settings_Integration', 'process_force_sync_batch')
        );
    }

    public function test_process_force_sync_batch_is_static(): void
    {
        $ref = new ReflectionMethod('WC_Multi_Store_Settings_Integration', 'process_force_sync_batch');

        $this->assertTrue($ref->isStatic());
    }

    // ─── Settings ID ───────────────────────────────

    public function test_settings_id(): void
    {
        $ref = new ReflectionClass($this->integration);
        $prop = $ref->getProperty('id');

        $this->assertEquals('multi_store_sync', $prop->getValue($this->integration));
    }

    public function test_settings_label(): void
    {
        $ref = new ReflectionClass($this->integration);
        $prop = $ref->getProperty('label');

        $this->assertEquals('Multi-Store Sync', $prop->getValue($this->integration));
    }
    public function test_store_forms_show_cache_failure_without_success_redirect_or_health_check(): void {
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('get_option')->alias(static fn($name, $default = false) =>
            $name === 'wc_multi_store_sync_stores' ? ['https://store.test' => ['consumer_key' => 'key', 'consumer_secret' => 'secret']] : $default
        );
        Functions\when('update_option')->alias(static fn($name) => $name === 'wc_multi_store_sync_stores');
        Functions\when('as_schedule_single_action')->alias(static function () { throw new RuntimeException('Unexpected health check'); });
        Functions\when('wp_safe_redirect')->alias(static function () { throw new RuntimeException('Unexpected success redirect'); });
        $post = $_POST;
        try {
            foreach (['handle_add_store', 'handle_update_store'] as $handler) {
                WC_Admin_Settings::reset();
                $_POST = ['store_url' => 'https://store.test', 'original_store_url' => 'https://store.test',
                    'consumer_key' => 'key', 'consumer_secret' => 'secret', 'status' => 'active'];
                (new ReflectionMethod($this->integration, $handler))->invoke($this->integration);
                $this->assertSame([], WC_Admin_Settings::get_messages());
                $this->assertCount(1, WC_Admin_Settings::get_errors());
                $this->assertStringContainsString('cache could not be cleared', WC_Admin_Settings::get_errors()[0]);
            }
        } finally {
            $_POST = $post;
            WC_Admin_Settings::reset();
        }
    }

    // ─── ajax_test_connection ──────────────────────────

    /**
     * Stubs wp_send_json_* so that, like the real functions, they end the request.
     *
     * @return \Closure(): array{success: bool, data: mixed}|null Returns what was sent.
     */
    private function haltingJsonResponses(): \Closure
    {
        $sent = null;
        Functions\when('wp_send_json_success')->alias(function ($data = null) use (&$sent): void {
            $sent = ['success' => true, 'data' => $data];
            throw new \RuntimeException('json_response');
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) use (&$sent): void {
            $sent = ['success' => false, 'data' => $data];
            throw new \RuntimeException('json_response');
        });

        return function () use (&$sent) {
            return $sent;
        };
    }

    private function callAjax(string $method): void
    {
        try {
            $this->integration->$method();
        } catch (\RuntimeException $e) {
            $this->assertSame('json_response', $e->getMessage());
        }
    }

    public function test_test_connection_rejects_users_without_capability_before_touching_input(): void
    {
        $sent = $this->haltingJsonResponses();
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_remote_get')->never();
        $_POST = ['store_url' => 'https://store.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'];

        $this->callAjax('ajax_test_connection');

        $this->assertSame(['success' => false, 'data' => 'Unauthorized'], $sent());
    }

    public static function unsafeStoreUrlProvider(): array
    {
        return [
            'missing'              => [null],
            'empty'                => [''],
            'cloud metadata'       => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 link-local'      => ['http://[fe80::1]/'],
            'array instead of url' => [['http://store.example.com']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeStoreUrlProvider')]
    public function test_test_connection_refuses_unsafe_urls_without_sending_credentials(mixed $url): void
    {
        $sent = $this->haltingJsonResponses();
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_remote_request')->never();
        $_POST = ['consumer_key' => 'ck_secret', 'consumer_secret' => 'cs_secret'];
        if ($url !== null) {
            $_POST['store_url'] = $url;
        }

        $this->callAjax('ajax_test_connection');

        $this->assertSame(['success' => false, 'data' => 'Store URL is not allowed.'], $sent());
    }

    // ─── ajax_force_sync_all ───────────────────────────

    private function configureStores(bool $active): void
    {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($active) {
            if ($name === 'wc_multi_store_sync_stores') {
                return ['https://store.example.com' => [
                    'status' => $active ? 'active' : 'inactive',
                    'consumer_key' => 'ck',
                    'consumer_secret' => 'cs',
                ]];
            }
            return $name === 'wc_multi_store_sync_settings' ? [] : $default;
        });
        WC_Multi_Store_Settings::clear_static_cache();
    }

    public function test_force_sync_all_checks_nonce_and_rejects_users_without_capability(): void
    {
        $sent = $this->haltingJsonResponses();
        Functions\expect('check_ajax_referer')->once()->with('wc_mss_force_sync_all', 'nonce')->andReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('as_schedule_single_action')->never();

        $this->callAjax('ajax_force_sync_all');

        $this->assertFalse($sent()['success']);
        $this->assertSame(['message' => 'Unauthorized'], $sent()['data']);
    }

    public function test_force_sync_all_without_active_stores_schedules_nothing(): void
    {
        $sent = $this->haltingJsonResponses();
        Functions\when('check_ajax_referer')->justReturn(1);
        $this->configureStores(false);
        Functions\expect('as_schedule_single_action')->never();
        Functions\expect('as_unschedule_all_actions')->never();

        $this->callAjax('ajax_force_sync_all');

        $this->assertFalse($sent()['success']);
        $this->assertStringContainsString('No active stores configured', $sent()['data']['message']);
    }

    public function test_force_sync_all_without_published_products_schedules_nothing(): void
    {
        $sent = $this->haltingJsonResponses();
        Functions\when('check_ajax_referer')->justReturn(1);
        $this->configureStores(true);
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 0, 'draft' => 12]);
        Functions\expect('as_schedule_single_action')->never();

        $this->callAjax('ajax_force_sync_all');

        $this->assertSame(['message' => 'No products found to sync.'], $sent()['data']);
    }

    public function test_force_sync_all_cancels_stale_batches_then_schedules_the_first_page(): void
    {
        $sent = $this->haltingJsonResponses();
        Functions\when('check_ajax_referer')->justReturn(1);
        $this->configureStores(true);
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 250]);

        $calls = [];
        Functions\when('as_unschedule_all_actions')->alias(function (...$args) use (&$calls) {
            $calls[] = ['unschedule', $args];
        });
        Functions\when('as_schedule_single_action')->alias(function (...$args) use (&$calls) {
            $calls[] = ['schedule', $args];
        });

        $this->callAjax('ajax_force_sync_all');

        $this->assertSame(['unschedule', 'schedule'], array_column($calls, 0), 'stale batches must be cleared before the new run starts');
        $this->assertSame(['wc_multi_store_sync_force_sync_batch', [], 'wc_multi_store_sync'], $calls[0][1]);
        $this->assertSame('wc_multi_store_sync_force_sync_batch', $calls[1][1][1]);
        $this->assertSame(['page' => 1], $calls[1][1][2]);
        $this->assertSame('wc_multi_store_sync', $calls[1][1][3]);
        $this->assertTrue($sent()['success']);
        $this->assertStringContainsString('250 products', $sent()['data']['message']);
    }
}
