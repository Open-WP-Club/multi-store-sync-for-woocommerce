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

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
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

    public function test_get_sections_returns_all_sections(): void
    {
        $sections = $this->integration->get_sections();

        $expected_keys = [
            '', 'stores', 'category-mapping', 'settings', 'queue', 'weekly-verification',
            'history', 'api-usage', 'discrepancies', 'deletion-audit',
            'orphan-cleanup', 'logs',
        ];

        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey($key, $sections, "Missing section: '{$key}'");
        }
    }

    public function test_get_sections_default_is_dashboard(): void
    {
        $sections = $this->integration->get_sections();

        $this->assertEquals('Dashboard', $sections['']);
    }

    public function test_get_sections_count(): void
    {
        $sections = $this->integration->get_sections();

        $this->assertCount(15, $sections);
    }

    // ─── save ──────────────────────────────────────

    public function test_save_does_not_throw(): void
    {
        // save() is a no-op override
        $this->integration->save();

        $this->assertTrue(true);
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
        $this->expectNotToPerformAssertions();

        $this->integration->enqueue_admin_scripts('edit.php');
    }

    public function test_enqueue_admin_scripts_skips_wrong_tab(): void
    {
        $_GET = ['tab' => 'general'];

        $this->expectNotToPerformAssertions();

        $this->integration->enqueue_admin_scripts('woocommerce_page_wc-settings');
    }

    public function test_enqueue_admin_scripts_skips_no_tab(): void
    {
        $_GET = [];

        $this->expectNotToPerformAssertions();

        $this->integration->enqueue_admin_scripts('woocommerce_page_wc-settings');
    }

    public function test_enqueue_admin_scripts_loads_on_correct_tab(): void
    {
        if (!defined('WC_MSS_PLUGIN_URL')) {
            define('WC_MSS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wc-multi-store-sync/');
        }
        if (!defined('WC_MSS_VERSION')) {
            define('WC_MSS_VERSION', '2.0.0');
        }

        $_GET = ['tab' => 'multi_store_sync'];

        Functions\expect('wp_enqueue_style')
            ->once()
            ->with('wc-mss-admin', \Mockery::type('string'), [], \Mockery::any());

        // Dashboard is the default section (no 'section' GET param), and now
        // also loads Chart.js — same as the api-usage section — for the sync
        // activity chart, so 'wc-mss-admin' depends on 'wc-mss-chartjs'.
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with('wc-mss-chartjs', \Mockery::type('string'), [], \Mockery::any(), true);

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with('wc-mss-admin', \Mockery::type('string'), ['wc-mss-chartjs'], \Mockery::any(), true);

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

        $this->assertTrue(true);
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
        $ref = new ReflectionClass($this->integration);
        $method = $ref->getMethod('handle_delete_store');

        $_POST = ['store_url' => 'https://store.com'];

        // WC_Multi_Store_Settings::delete_store is a static call
        // It uses get_option/update_option which are already mocked
        $method->invoke($this->integration);

        $this->assertTrue(true); // No exception thrown
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
}
