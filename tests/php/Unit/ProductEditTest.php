<?php
/**
 * Unit tests for WC_Multi_Store_Product_Edit
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

class ProductEditTest extends WC_Multi_Store_TestCase
{
    /** @var list<array{0: string, 1: array}> Recorded update_post_meta()/add_meta_box() calls. */
    private array $recorded = [];

    /** Record calls to the given functions instead of executing them. */
    private function recordCalls(string ...$functions): void
    {
        $this->recorded = [];
        foreach ($functions as $fn) {
            Functions\when($fn)->alias(function (...$args) use ($fn) {
                $this->recorded[] = [$fn, $args];
                return true;
            });
        }
    }

    /** @param array<string, array> $stores */
    private function stubStores(array $stores): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(fn($option, $default = false) => $option === 'wc_multi_store_sync_stores' ? $stores : $default);
    }

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

    public function test_constructor_registers_expected_hooks(): void
    {
        $this->recordCalls('add_action');

        new WC_Multi_Store_Product_Edit();

        $hooks = array_map(static fn(array $c): array => [$c[1][0], $c[1][2] ?? 10, $c[1][3] ?? 1], $this->recorded);
        $this->assertSame([
            ['add_meta_boxes', 10, 1],
            ['add_meta_boxes', 10, 1],
            ['wp_ajax_wc_mss_sync_product', 10, 1],
            ['wp_ajax_wc_mss_preview_sync', 10, 1],
            ['save_post_product', 10, 1],
            ['admin_enqueue_scripts', 10, 1],
        ], $hooks);
        foreach ($this->recorded as [, $args]) {
            $this->assertIsCallable($args[1]);
        }
    }

    // ─── add_sync_metabox ──────────────────────────

    public function test_add_sync_metabox_registers_side_metabox_on_product_screen(): void
    {
        $this->recordCalls('add_meta_box');

        $this->editor->add_sync_metabox();

        $this->assertCount(1, $this->recorded);
        [$id, $title, $callback, $screen, $context, $priority] = $this->recorded[0][1];
        $this->assertSame('wc_multi_store_sync_actions', $id);
        $this->assertSame('Multi-Store Sync', $title);
        $this->assertSame('product', $screen);
        $this->assertSame('side', $context);
        $this->assertSame('high', $priority);
        $this->assertIsCallable($callback);
    }

    // ─── add_store_deletion_metabox ────────────────

    public function test_add_store_deletion_metabox_registers_default_priority_side_metabox(): void
    {
        $this->recordCalls('add_meta_box');

        $this->editor->add_store_deletion_metabox();

        $this->assertCount(1, $this->recorded);
        [$id, $title, $callback, $screen, $context, $priority] = $this->recorded[0][1];
        $this->assertSame('wc_multi_store_deletion_settings', $id);
        $this->assertSame('Selective Store Deletion', $title);
        $this->assertSame('product', $screen);
        $this->assertSame('side', $context);
        $this->assertSame('default', $priority);
        $this->assertIsCallable($callback);
    }

    // ─── save_store_deletion_settings ──────────────

    public function test_save_store_deletion_settings_skips_without_nonce(): void
    {
        $_POST = [];
        $this->recordCalls('update_post_meta', 'wp_verify_nonce');

        $this->editor->save_store_deletion_settings(1);

        $this->assertSame([], $this->recorded, 'Without a nonce nothing is verified or saved');
    }

    public function test_save_store_deletion_settings_skips_invalid_nonce(): void
    {
        $_POST = [
            'wc_mss_store_deletion_nonce' => 'invalid',
            '_wc_mss_delete_from_stores' => [md5('https://a.example') => '1'],
        ];
        $this->recordCalls('update_post_meta');

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('invalid', 'wc_mss_store_deletion_nonce')
            ->andReturn(false);

        $this->editor->save_store_deletion_settings(1);

        $this->assertSame([], $this->recorded, 'An invalid nonce must not write post meta');
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_save_store_deletion_settings_skips_without_edit_capability(): void
    {
        $_POST = ['wc_mss_store_deletion_nonce' => 'good'];
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\expect('current_user_can')->once()->with('edit_product', 9)->andReturn(false);
        $this->recordCalls('update_post_meta');

        $this->editor->save_store_deletion_settings(9);

        $this->assertSame([], $this->recorded);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_save_store_deletion_settings_stores_checked_state_per_active_store(): void
    {
        $a = 'https://a.example';
        $b = 'https://b.example';
        $this->stubStores([
            $a => ['status' => 'active', 'name' => 'A'],
            $b => ['status' => 'active', 'name' => 'B'],
            'https://off.example' => ['status' => 'inactive'],
        ]);
        $_POST = [
            'wc_mss_store_deletion_nonce' => 'good',
            '_wc_mss_delete_from_stores' => [md5($a) => '1'],
        ];
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $this->recordCalls('update_post_meta');

        $this->editor->save_store_deletion_settings(5);

        $this->assertSame([
            ['update_post_meta', [5, '_wc_mss_selective_deletion', [md5($a) => true, md5($b) => false]]],
            ['update_post_meta', [5, '_wc_mss_deletion_stores_map', [md5($a) => $a, md5($b) => $b]]],
        ], $this->recorded);
    }

    public function test_save_store_deletion_settings_treats_missing_checkbox_array_as_none_selected(): void
    {
        $a = 'https://a.example';
        $this->stubStores([$a => ['status' => 'active']]);
        $_POST = ['wc_mss_store_deletion_nonce' => 'good'];
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $this->recordCalls('update_post_meta');

        $this->editor->save_store_deletion_settings(5);

        $this->assertSame([5, '_wc_mss_selective_deletion', [md5($a) => false]], $this->recorded[0][1]);
    }

    // ─── enqueue_scripts ───────────────────────────

    public function test_enqueue_scripts_skips_non_product_pages(): void
    {
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_localize_script')->never();

        $this->editor->enqueue_scripts('edit.php');

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_enqueue_scripts_skips_when_no_post(): void
    {
        global $post;
        $post = null;

        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();

        $this->editor->enqueue_scripts('post.php');

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_enqueue_scripts_skips_non_product_post_type(): void
    {
        global $post;
        $post = new WP_Post(['ID' => 1, 'post_type' => 'page']);

        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();

        $this->editor->enqueue_scripts('post.php');

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_enqueue_scripts_loads_on_product_edit(): void
    {
        define('WC_MSS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wc-multi-store-sync/');

        global $post;
        $post = new WP_Post(['ID' => 1, 'post_type' => 'product']);

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'wc-mss-product-sync',
                'http://example.com/wp-content/plugins/wc-multi-store-sync/admin/js/product-sync.js',
                ['jquery'],
                WC_MSS_VERSION,
                true
            );

        Functions\expect('wp_localize_script')
            ->once()
            ->with(
                'wc-mss-product-sync',
                'wcMssProduct',
                \Mockery::on(static fn($data): bool => is_array($data)
                    && $data['ajaxUrl'] === 'http://example.com/wp-admin/admin-ajax.php'
                    && $data['syncNonce'] === 'nonce_wc_mss_sync_product'
                    && $data['previewNonce'] === 'nonce_wc_mss_preview_sync'
                    && isset($data['i18n']['syncing'], $data['i18n']['errorOccurred']))
            );

        Functions\expect('wp_create_nonce')
            ->twice()
            ->andReturnUsing(static fn(string $action): string => 'nonce_' . $action);

        Functions\expect('admin_url')
            ->once()
            ->with('admin-ajax.php')
            ->andReturn('http://example.com/wp-admin/admin-ajax.php');

        Functions\expect('wp_enqueue_style')
            ->once()
            ->with(
                'wc-mss-product-edit',
                'http://example.com/wp-content/plugins/wc-multi-store-sync/admin/css/product-edit.css',
                [],
                WC_MSS_VERSION
            );

        $this->editor->enqueue_scripts('post.php');

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
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
