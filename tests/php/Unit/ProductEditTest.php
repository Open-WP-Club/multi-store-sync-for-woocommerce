<?php
/**
 * Unit tests for WC_Multi_Store_Product_Edit
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

class ProductEditTest extends WC_Multi_Store_TestCase
{
    private ?WC_Multi_Store_Product_Edit $editor = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_meta_box')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('absint')->alias(fn($v) => abs((int)$v));

        // Load the class after Brain Monkey is set up (has constructor with add_action)
        if (!class_exists('WC_Multi_Store_Product_Edit', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/product-edit.php';
        }

        $this->editor = new WC_Multi_Store_Product_Edit();
    }

    protected function tearDown(): void
    {
        // Restore queue_manager in case a test replaced it with a Mockery mock.
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();
        $_POST = [];
        parent::tearDown();
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Product_Edit'));
    }

    public function test_has_required_methods(): void
    {
        $methods = [
            'add_sync_metabox',
            'render_sync_metabox',
            'add_store_deletion_metabox',
            'render_store_deletion_metabox',
            'save_store_deletion_settings',
            'ajax_sync_product',
            'ajax_preview_sync',
            'enqueue_scripts',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_Product_Edit', $method),
                "Missing method: {$method}"
            );
        }
    }

    // ─── Constructor hooks ─────────────────────────

    public function test_constructor_completes_without_errors(): void
    {
        // Constructor calls add_action 5 times (mocked via when)
        $editor = new WC_Multi_Store_Product_Edit();

        $this->assertInstanceOf(WC_Multi_Store_Product_Edit::class, $editor);
    }

    // ─── add_sync_metabox ──────────────────────────

    public function test_add_sync_metabox_does_not_throw(): void
    {
        // add_meta_box is mocked in setUp; verify no exception
        $this->editor->add_sync_metabox();

        $this->assertTrue(true);
    }

    // ─── add_store_deletion_metabox ────────────────

    public function test_add_store_deletion_metabox_does_not_throw(): void
    {
        // add_meta_box is mocked in setUp; verify no exception
        $this->editor->add_store_deletion_metabox();

        $this->assertTrue(true);
    }

    // ─── save_store_deletion_settings ──────────────

    public function test_save_store_deletion_settings_skips_without_nonce(): void
    {
        $_POST = [];

        $this->editor->save_store_deletion_settings(1);

        // Reached here without calling update_post_meta
        $this->assertTrue(true);
    }

    public function test_save_store_deletion_settings_skips_invalid_nonce(): void
    {
        $_POST = [
            'wc_mss_store_deletion_nonce' => 'invalid',
        ];

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('invalid', 'wc_mss_store_deletion_nonce')
            ->andReturn(false);

        $this->editor->save_store_deletion_settings(1);

        $this->assertTrue(true);
    }

    // ─── enqueue_scripts ───────────────────────────

    public function test_enqueue_scripts_skips_non_product_pages(): void
    {
        $this->expectNotToPerformAssertions();

        $this->editor->enqueue_scripts('edit.php');
    }

    public function test_enqueue_scripts_skips_when_no_post(): void
    {
        global $post;
        $post = null;

        $this->expectNotToPerformAssertions();

        $this->editor->enqueue_scripts('post.php');
    }

    public function test_enqueue_scripts_skips_non_product_post_type(): void
    {
        global $post;
        $post = new WP_Post(['ID' => 1, 'post_type' => 'page']);

        $this->expectNotToPerformAssertions();

        $this->editor->enqueue_scripts('post.php');
    }

    public function test_enqueue_scripts_loads_on_product_edit(): void
    {
        if (!defined('WC_MSS_PLUGIN_URL')) {
            define('WC_MSS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wc-multi-store-sync/');
        }
        if (!defined('WC_MSS_VERSION')) {
            define('WC_MSS_VERSION', '2.0.0');
        }

        global $post;
        $post = new WP_Post(['ID' => 1, 'post_type' => 'product']);

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'wc-mss-product-sync',
                \Mockery::type('string'),
                ['jquery'],
                \Mockery::any(),
                true
            );

        Functions\expect('wp_localize_script')
            ->once()
            ->with(
                'wc-mss-product-sync',
                'wcMssProduct',
                \Mockery::type('array')
            );

        Functions\expect('wp_create_nonce')
            ->twice()
            ->andReturn('test_nonce');

        Functions\expect('admin_url')
            ->once()
            ->andReturn('http://example.com/wp-admin/admin-ajax.php');

        Functions\expect('wp_enqueue_style')
            ->once()
            ->with(
                'wc-mss-product-edit',
                \Mockery::type('string'),
                [],
                \Mockery::any()
            );

        $this->editor->enqueue_scripts('post.php');

        $this->assertTrue(true);
    }

    // ─── ajax_sync_product ─────────────────────────

    public function test_ajax_sync_product_invalid_product_id_returns_error(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);

        $_POST = ['product_id' => '0', 'sync_type' => 'full_product'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->editor->ajax_sync_product();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid', $error['message']);
    }

    public function test_ajax_sync_product_product_not_found_returns_error(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(null);

        $_POST = ['product_id' => '55', 'sync_type' => 'full_product'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->editor->ajax_sync_product();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('not found', $error['message']);
    }

    public function test_ajax_sync_product_no_active_stores_returns_error(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(\Mockery::mock('WC_Product'));

        $settingsRef = new ReflectionClass('WC_Multi_Store_Settings');
        $cacheProp   = $settingsRef->getProperty('active_stores_cache');
        $cacheProp->setValue(null, []);

        $_POST = ['product_id' => '42', 'sync_type' => 'full_product'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->editor->ajax_sync_product();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('No active stores', $error['message']);
    }

    public function test_ajax_sync_product_uses_manual_test_source_and_returns_success(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(\Mockery::mock('WC_Product'));

        $settingsRef = new ReflectionClass('WC_Multi_Store_Settings');
        $cacheProp   = $settingsRef->getProperty('active_stores_cache');
        $cacheProp->setValue(null, ['https://store1.com' => ['status' => 'active']]);

        $mockQueue = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $mockQueue->shouldReceive('add_product')
            ->once()
            ->withArgs(function ($id, $source, $priority, $sync_type) {
                return $id === 42 && $source === 'manual_test' && $sync_type === 'full_product';
            })
            ->andReturn(1);
        WC_Multi_Store_Sync::instance()->queue_manager = $mockQueue;

        $_POST = ['product_id' => '42', 'sync_type' => 'full_product'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->editor->ajax_sync_product();

        $this->assertNotNull($success);
        $this->assertTrue($success['queued']);
        $this->assertEquals(1, $success['queued_count']);
    }

    public function test_ajax_sync_product_passes_sync_type_from_post(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(\Mockery::mock('WC_Product'));

        $settingsRef = new ReflectionClass('WC_Multi_Store_Settings');
        $cacheProp   = $settingsRef->getProperty('active_stores_cache');
        $cacheProp->setValue(null, ['https://store1.com' => ['status' => 'active']]);

        $capturedType = null;
        $mockQueue    = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $mockQueue->shouldReceive('add_product')
            ->once()
            ->andReturnUsing(function ($id, $source, $priority, $type) use (&$capturedType) {
                $capturedType = $type;
                return 1;
            });
        WC_Multi_Store_Sync::instance()->queue_manager = $mockQueue;

        $_POST = ['product_id' => '42', 'sync_type' => 'price_quantity'];

        Functions\when('wp_send_json_success')->justReturn(null);
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->editor->ajax_sync_product();

        $this->assertEquals('price_quantity', $capturedType);
    }

    public function test_ajax_sync_product_zero_queued_returns_error(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(\Mockery::mock('WC_Product'));

        $settingsRef = new ReflectionClass('WC_Multi_Store_Settings');
        $cacheProp   = $settingsRef->getProperty('active_stores_cache');
        $cacheProp->setValue(null, ['https://store1.com' => ['status' => 'active']]);

        $mockQueue = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $mockQueue->shouldReceive('add_product')->once()->andReturn(0);
        WC_Multi_Store_Sync::instance()->queue_manager = $mockQueue;

        $_POST = ['product_id' => '42', 'sync_type' => 'full_product'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->editor->ajax_sync_product();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Failed', $error['message']);
    }

    // ─── get_store_url_map (private) ───────────────

    public function test_get_store_url_map_returns_md5_keyed_urls(): void
    {
        // Inject active stores directly into the Settings static cache
        // so we don't need to fight with get_option mocking.
        $stores = [
            'https://store1.com' => ['name' => 'Store 1', 'status' => 'active'],
            'https://store2.com' => ['name' => 'Store 2', 'status' => 'active'],
        ];

        $settingsRef = new ReflectionClass('WC_Multi_Store_Settings');
        $cacheProp = $settingsRef->getProperty('active_stores_cache');
        $cacheProp->setValue(null, $stores);

        $ref = new ReflectionClass($this->editor);
        $method = $ref->getMethod('get_store_url_map');

        $map = $method->invoke($this->editor);

        // Clean up static cache
        $cacheProp->setValue(null, null);

        $this->assertArrayHasKey(md5('https://store1.com'), $map);
        $this->assertArrayHasKey(md5('https://store2.com'), $map);
        $this->assertEquals('https://store1.com', $map[md5('https://store1.com')]);
        $this->assertEquals('https://store2.com', $map[md5('https://store2.com')]);
    }
}
