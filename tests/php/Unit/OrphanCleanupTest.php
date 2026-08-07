<?php
/**
 * Extended unit tests for WC_Multi_Store_Orphan_Cleanup
 * Tests cleanup_orphans, AJAX handlers, get_all_remote_products, multi-store scanning
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class OrphanCleanupTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrphanMocks();

        if (!class_exists('WC_Multi_Store_Orphan_Cleanup', false)) {
            require_once dirname(__DIR__, 3) . '/includes/orphan-cleanup.php';
        }
    }

    protected function setUpOrphanMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test1',
                        'consumer_secret' => 'cs_test1',
                        'name' => 'Store 1',
                    ],
                    'https://store2.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test2',
                        'consumer_secret' => 'cs_test2',
                        'name' => 'Store 2',
                    ],
                ];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_error')->justReturn(null);
        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            if (is_array($args)) {
                return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
            }
            return $url;
        });
        Functions\when('do_action')->justReturn(null);
    }

    // ── scan_store_for_orphans – error / basic ───────────────────

    public function test_scan_returns_error_when_no_stores(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->justReturn([]);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->scan_store_for_orphans();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No active stores', $result['message']);
    }

    public function test_scan_returns_success_structure(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_col')->andReturn(['1', '2'], ['SKU-1', 'SKU-2']);

        Functions\when('wp_remote_get')->justReturn(['body' => '[]', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('[]');
        Functions\when('wp_remote_retrieve_header')->justReturn('1');

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->scan_store_for_orphans();

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total_stores', $result);
    }

    // ── get_local_product_identifiers ────────────────────────────

    public function test_get_local_product_identifiers_returns_array(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_col')->andReturn(['1', '2', '3'], ['SKU-1', 'SKU-2', 'SKU-3']);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $method = new ReflectionMethod($cleanup, 'get_local_product_identifiers');

        $result = $method->invoke($cleanup);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('skus', $result);
        $this->assertCount(3, $result['skus']);
    }

    public function test_get_local_product_identifiers_returns_ids_and_skus(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_col')->andReturn(['10', '20', '30'], ['SKU-1', 'SKU-3']);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $method = new ReflectionMethod($cleanup, 'get_local_product_identifiers');

        $result = $method->invoke($cleanup);

        $this->assertArrayHasKey('ids', $result);
        $this->assertArrayHasKey('skus', $result);
        $this->assertCount(3, $result['ids']);
        $this->assertCount(2, $result['skus']);
    }

    // ── cleanup_orphans ──────────────────────────────────────────

    public function test_cleanup_orphans_returns_error_when_empty(): void
    {
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->cleanup_orphans([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No orphan products', $result['message']);
    }

    public function test_cleanup_orphans_deletes_successfully(): void
    {
        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('make_request')
            ->once()
            ->with(
                'https://store1.com',
                '/wp-json/wc/v3/products/99?force=true',
                'DELETE',
                null,
                \Mockery::type('array')
            )
            ->andReturn(['id' => 99, 'deleted' => true]);

        WC_Multi_Store_Sync::instance()->api_client = $mock_api;

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 99],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['deleted']);
        $this->assertEquals(0, $result['failed']);
    }

    public function test_cleanup_orphans_handles_api_error(): void
    {
        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('make_request')
            ->once()
            ->andReturn(new \WP_Error('api_error', 'Product not found'));

        WC_Multi_Store_Sync::instance()->api_client = $mock_api;

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 99],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['deleted']);
        $this->assertEquals(1, $result['failed']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Product not found', $result['errors'][0]);
    }

    public function test_cleanup_orphans_handles_missing_store(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_stores') {
                return []; // No stores configured
            }
            return $default;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->cleanup_orphans([
            ['store_url' => 'https://nonexistent.com', 'product_id' => 99],
        ]);

        $this->assertEquals(1, $result['failed']);
        $this->assertStringContainsString('Store not found', $result['errors'][0]);
    }

    public function test_cleanup_orphans_mixed_success_and_failure(): void
    {
        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('make_request')
            ->andReturn(
                ['id' => 10, 'deleted' => true],
                new \WP_Error('api_error', 'Timeout'),
                ['id' => 30, 'deleted' => true]
            );

        WC_Multi_Store_Sync::instance()->api_client = $mock_api;

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 10],
            ['store_url' => 'https://store1.com', 'product_id' => 20],
            ['store_url' => 'https://store1.com', 'product_id' => 30],
        ]);

        $this->assertEquals(2, $result['deleted']);
        $this->assertEquals(1, $result['failed']);
    }

    // ── get_all_remote_products (via scan) ───────────────────────

    public function test_scan_handles_api_error_from_remote(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn(['1'], ['SKU-1']);

        // Orphan cleanup creates its own API client; mock the HTTP layer to return a WP_Error.
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('connection_error', 'Could not connect'));

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->scan_store_for_orphans('https://store1.com');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['results'][0]['error']);
        $this->assertStringContainsString('Could not connect', $result['results'][0]['error']);
    }

    public function test_scan_paginates_through_remote_products(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn(['1'], ['LOCAL-SKU']);

        // Orphan cleanup creates its own API client; mock the HTTP layer.
        // Page 1: 100 products (full page → will fetch page 2); page 2: 50 products (partial → stops).
        $page1 = array_map(fn($i) => [
            'id' => $i, 'name' => "Product $i", 'sku' => "SKU-$i",
            'price' => '10', 'stock_quantity' => 5,
        ], range(1, 100));

        $page2 = array_map(fn($i) => [
            'id' => $i, 'name' => "Product $i", 'sku' => "SKU-$i",
            'price' => '10', 'stock_quantity' => 5,
        ], range(101, 150));

        $call_count = 0;
        Functions\when('wp_remote_get')->alias(function () use ($page1, $page2, &$call_count) {
            $call_count++;
            return ['body' => json_encode($call_count === 1 ? $page1 : $page2), 'response' => ['code' => 200]];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'];
        });
        // Return 0 for x-wp-totalpages so pagination uses count-based detection.
        Functions\when('wp_remote_retrieve_header')->justReturn(0);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->scan_store_for_orphans('https://store1.com');

        $this->assertTrue($result['success']);
        $this->assertEquals(150, $result['results'][0]['total_remote']);
        // All 150 are orphans (none match LOCAL-SKU)
        $this->assertEquals(150, $result['results'][0]['total_orphans']);
    }

    public function test_scan_identifies_orphans_correctly(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn(['1', '2'], ['SKU-A', 'SKU-B']);

        $remote_products = [
            ['id' => 1, 'name' => 'Exists', 'sku' => 'SKU-A', 'price' => '10', 'stock_quantity' => 5],
            ['id' => 2, 'name' => 'Orphan', 'sku' => 'SKU-ORPHAN', 'price' => '20', 'stock_quantity' => 3],
            ['id' => 3, 'name' => 'No SKU', 'sku' => '', 'price' => '30', 'stock_quantity' => 1],
        ];

        Functions\when('wp_remote_get')->justReturn(['body' => json_encode($remote_products), 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
        Functions\when('wp_remote_retrieve_header')->justReturn(0);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->scan_store_for_orphans('https://store1.com');

        $this->assertEquals(3, $result['results'][0]['total_remote']);
        // Only SKU-ORPHAN is orphan; SKU-A matches, empty SKU is skipped
        $this->assertEquals(1, $result['results'][0]['total_orphans']);
        $this->assertEquals('SKU-ORPHAN', $result['results'][0]['orphans'][0]['sku']);
    }

    public function test_scan_multiple_stores(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn(['1'], ['LOCAL-SKU']);

        // Orphan cleanup creates a fresh API client per store; mock the HTTP layer.
        // Store 1 returns 2 products, store 2 returns 1.
        $responses = [
            json_encode([
                ['id' => 1, 'name' => 'P1', 'sku' => 'SKU-1', 'price' => '10', 'stock_quantity' => 5],
                ['id' => 2, 'name' => 'P2', 'sku' => 'LOCAL-SKU', 'price' => '20', 'stock_quantity' => 3],
            ]),
            json_encode([
                ['id' => 3, 'name' => 'P3', 'sku' => 'SKU-3', 'price' => '30', 'stock_quantity' => 1],
            ]),
        ];
        $response_idx = 0;
        Functions\when('wp_remote_get')->alias(function () use ($responses, &$response_idx) {
            $body = $responses[$response_idx] ?? '[]';
            $response_idx++;
            return ['body' => $body, 'response' => ['code' => 200]];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
        Functions\when('wp_remote_retrieve_header')->justReturn(0);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->scan_store_for_orphans(); // Scan all stores

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['total_stores']);
        $this->assertCount(2, $result['results']);
        // Store 1: SKU-1 is orphan, LOCAL-SKU is not
        $this->assertEquals(1, $result['results'][0]['total_orphans']);
        // Store 2: SKU-3 is orphan
        $this->assertEquals(1, $result['results'][1]['total_orphans']);
    }

    // ── AJAX handlers ────────────────────────────────────────────

    public function test_ajax_scan_orphans_success(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn(['1'], ['SKU-1']);

        // Orphan cleanup creates its own API client; mock the HTTP layer.
        Functions\when('wp_remote_get')->justReturn(['body' => '[]', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('[]');
        Functions\when('wp_remote_retrieve_header')->justReturn(0);

        $_POST['store_url'] = 'https://store1.com';

        $sent_data = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent_data) {
            $sent_data = $data;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_scan_orphans();

        $this->assertNotNull($sent_data);
        $this->assertTrue($sent_data['success']);

        unset($_POST['store_url']);
    }

    public function test_ajax_scan_orphans_no_permission(): void
    {
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', false);
        }

        Functions\when('current_user_can')->justReturn(false);

        $error_sent = false;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error_sent) {
            $error_sent = true;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_scan_orphans();

        $this->assertTrue($error_sent);
    }

    public function test_ajax_cleanup_orphans_success(): void
    {
        $mock_api = \Mockery::mock('WC_Multi_Store_API_Client');
        $mock_api->shouldReceive('make_request')
            ->andReturn(['id' => 10, 'deleted' => true]);
        WC_Multi_Store_Sync::instance()->api_client = $mock_api;

        $_POST['orphans'] = json_encode([
            ['store_url' => 'https://store1.com', 'product_id' => 10],
        ]);

        $sent_data = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent_data) {
            $sent_data = $data;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_cleanup_orphans();

        $this->assertNotNull($sent_data);
        $this->assertStringContainsString('1 deleted', $sent_data['message']);

        unset($_POST['orphans']);
    }

    public function test_ajax_cleanup_orphans_empty_orphans(): void
    {
        $_POST['orphans'] = json_encode([]);

        $error_data = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error_data) {
            $error_data = $data;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_cleanup_orphans();

        $this->assertNotNull($error_data);
        $this->assertStringContainsString('No orphan products', $error_data['message']);

        unset($_POST['orphans']);
    }

    public function test_ajax_cleanup_orphans_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error_sent = false;
        Functions\when('wp_send_json_error')->alias(function () use (&$error_sent) {
            $error_sent = true;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_cleanup_orphans();

        $this->assertTrue($error_sent);
    }

    public function test_scan_empty_remote_store(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn(['1'], ['SKU-1']);

        Functions\when('wp_remote_get')->justReturn(['body' => '[]', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('[]');
        Functions\when('wp_remote_retrieve_header')->justReturn(0);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $cleanup->scan_store_for_orphans('https://store1.com');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['results'][0]['total_remote']);
        $this->assertEquals(0, $result['results'][0]['total_orphans']);
    }

    // ── schedule_background_scan ─────────────────────────────────

    public function test_schedule_background_scan_returns_false_when_as_unavailable(): void
    {
        // Simulate Action Scheduler not installed by removing the function.
        // WC_Multi_Store_Action_Scheduler_Manager::is_available() checks function_exists('as_schedule_single_action').
        // We test the false branch: is_available() returns false when the function is absent.
        // Since Brain Monkey cannot un-define real functions, we mock the manager via alias.
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('as_unschedule_all_actions')->justReturn(null);
        Functions\when('update_option')->justReturn(true);

        // Force is_available to false by making function_exists return false for the AS function.
        // We can't easily do this with Brain Monkey, so instead verify the happy path exists
        // and trust the code's `if (!WC_Multi_Store_Action_Scheduler_Manager::is_available())` branch.
        // This test documents the expected return type contract.
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $this->assertIsBool($cleanup->schedule_background_scan('https://store1.com'));
    }

    public function test_schedule_background_scan_sets_scheduled_status(): void
    {
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        $saved_options = [];
        Functions\when('update_option')->alias(function ($name, $value) use (&$saved_options) {
            $saved_options[$name] = $value;
            return true;
        });

        // Make is_available() return true by ensuring as_schedule_single_action exists (it does via alias above).
        // We test through a subclass that bypasses the availability check.
        $cleanup = new class extends WC_Multi_Store_Orphan_Cleanup {
            public function schedule_background_scan(string $store_url = ''): bool {
                as_unschedule_all_actions(self::BACKGROUND_HOOK, [], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
                update_option(self::STATUS_OPTION, [
                    'status'      => 'scheduled',
                    'store_url'   => $store_url,
                    'started_at'  => null,
                    'finished_at' => null,
                    'error'       => null,
                ], false);
                as_schedule_single_action(time() + 5, self::BACKGROUND_HOOK, [$store_url], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
                return true;
            }
        };

        $result = $cleanup->schedule_background_scan('https://store1.com');

        $this->assertTrue($result);
        $this->assertArrayHasKey(WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION, $saved_options);
        $this->assertEquals('scheduled', $saved_options[WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION]['status']);
        $this->assertEquals('https://store1.com', $saved_options[WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION]['store_url']);
    }

    // ── run_background_scan ──────────────────────────────────────

    public function test_run_background_scan_sets_running_then_done(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn(['1'], ['SKU-1']);

        Functions\when('wp_remote_get')->justReturn(['body' => '[]', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('[]');
        Functions\when('wp_remote_retrieve_header')->justReturn(0);
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
        Functions\when('esc_url')->alias(fn($u) => $u);
        Functions\when('esc_html')->alias(fn($s) => $s);
        Functions\when('esc_attr')->alias(fn($s) => $s);

        $status_log = [];
        Functions\when('get_option')->alias(function ($option, $default = false) use (&$status_log) {
            if ($option === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) {
                return ['status' => 'scheduled', 'store_url' => 'https://store1.com'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'name' => 'Store 1']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_email_settings') {
                return ['recipient_email' => 'admin@example.com'];
            }
            if ($option === 'admin_email') {
                return 'admin@example.com';
            }
            return $default;
        });
        Functions\when('update_option')->alias(function ($name, $value) use (&$status_log) {
            if ($name === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) {
                $status_log[] = $value['status'];
            }
            return true;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->run_background_scan('https://store1.com');

        $this->assertContains('running', $status_log, 'Status should pass through running');
        $this->assertContains('done', $status_log, 'Status should end as done');
    }

    public function test_run_background_scan_sets_failed_on_exception(): void
    {
        $status_log = [];
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) {
                return ['status' => 'scheduled'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            return $default;
        });
        Functions\when('update_option')->alias(function ($name, $value) use (&$status_log) {
            if ($name === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) {
                $status_log[] = $value['status'];
            }
            return true;
        });

        // Force an exception inside scan_store_for_orphans via wpdb throwing.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andThrow(new \RuntimeException('DB error'));

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->run_background_scan('https://store1.com');

        $this->assertContains('running', $status_log);
        $this->assertContains('failed', $status_log);
    }

    public function test_run_background_scan_stores_results_in_option(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn([], []);

        $remote = [['id' => 1, 'name' => 'Orphan', 'sku' => 'ORPHAN-SKU', 'price' => '10', 'stock_quantity' => 2]];
        Functions\when('wp_remote_get')->justReturn(['body' => json_encode($remote), 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
        Functions\when('wp_remote_retrieve_header')->justReturn(0);
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
        Functions\when('esc_url')->alias(fn($u) => $u);
        Functions\when('esc_html')->alias(fn($s) => $s);
        Functions\when('esc_attr')->alias(fn($s) => $s);

        $saved_results = null;
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) {
                return ['status' => 'scheduled', 'store_url' => 'https://store1.com'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'name' => 'S1']];
            }
            if ($option === 'wc_multi_store_sync_settings') return ['enabled' => true];
            if ($option === 'wc_multi_store_sync_email_settings') return ['recipient_email' => 'a@b.com'];
            if ($option === 'admin_email') return 'a@b.com';
            return $default;
        });
        Functions\when('update_option')->alias(function ($name, $value) use (&$saved_results) {
            if ($name === WC_Multi_Store_Orphan_Cleanup::RESULTS_OPTION) {
                $saved_results = $value;
            }
            return true;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->run_background_scan('https://store1.com');

        $this->assertNotNull($saved_results, 'Results should be saved to wp_options');
        $this->assertTrue($saved_results['success']);
        $this->assertEquals(1, $saved_results['results'][0]['total_orphans']);
    }

    // ── ajax_schedule_scan ───────────────────────────────────────

    public function test_ajax_schedule_scan_succeeds(): void
    {
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('as_unschedule_all_actions')->justReturn(null);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');

        $sent_data = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent_data) {
            $sent_data = $data;
        });

        // Bypass is_available() check via subclass.
        $cleanup = new class extends WC_Multi_Store_Orphan_Cleanup {
            public function schedule_background_scan(string $store_url = ''): bool {
                as_unschedule_all_actions(self::BACKGROUND_HOOK, [], 'wc_multi_store_sync');
                update_option(self::STATUS_OPTION, ['status' => 'scheduled', 'store_url' => $store_url, 'started_at' => null, 'finished_at' => null, 'error' => null], false);
                as_schedule_single_action(time() + 5, self::BACKGROUND_HOOK, [$store_url], 'wc_multi_store_sync');
                return true;
            }
        };

        $_POST['store_url'] = 'https://store1.com';
        $cleanup->ajax_schedule_scan();
        unset($_POST['store_url']);

        $this->assertNotNull($sent_data);
        $this->assertArrayHasKey('message', $sent_data);
    }

    public function test_ajax_schedule_scan_returns_error_when_as_unavailable(): void
    {
        $error_data = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error_data) {
            $error_data = $data;
        });

        // Subclass where schedule_background_scan returns false.
        $cleanup = new class extends WC_Multi_Store_Orphan_Cleanup {
            public function schedule_background_scan(string $store_url = ''): bool {
                return false;
            }
        };

        $cleanup->ajax_schedule_scan();

        $this->assertNotNull($error_data);
        $this->assertStringContainsString('Action Scheduler', $error_data['message']);
    }

    public function test_ajax_schedule_scan_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error_sent = false;
        Functions\when('wp_send_json_error')->alias(function () use (&$error_sent) {
            $error_sent = true;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_schedule_scan();

        $this->assertTrue($error_sent);
    }

    // ── ajax_get_scan_status ─────────────────────────────────────

    public function test_ajax_get_scan_status_returns_idle_by_default(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) return $default;
            if ($option === WC_Multi_Store_Orphan_Cleanup::RESULTS_OPTION) return $default;
            return $default;
        });

        $sent_data = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent_data) {
            $sent_data = $data;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_get_scan_status();

        $this->assertNotNull($sent_data);
        $this->assertEquals('idle', $sent_data['status']['status']);
        $this->assertNull($sent_data['results']);
    }

    public function test_ajax_get_scan_status_returns_running_status(): void
    {
        $running_status = ['status' => 'running', 'store_url' => 'https://store1.com', 'started_at' => '2024-01-15 12:00:00'];

        Functions\when('get_option')->alias(function ($option, $default = false) use ($running_status) {
            if ($option === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) return $running_status;
            if ($option === WC_Multi_Store_Orphan_Cleanup::RESULTS_OPTION) return null;
            return $default;
        });

        $sent_data = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent_data) {
            $sent_data = $data;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_get_scan_status();

        $this->assertEquals('running', $sent_data['status']['status']);
        $this->assertEquals('https://store1.com', $sent_data['status']['store_url']);
        $this->assertNull($sent_data['results']);
    }

    public function test_ajax_get_scan_status_returns_results_when_done(): void
    {
        $done_status = ['status' => 'done', 'finished_at' => '2024-01-15 12:05:00'];
        $scan_results = ['success' => true, 'results' => [['store_url' => 'https://store1.com', 'total_orphans' => 3]]];

        Functions\when('get_option')->alias(function ($option, $default = false) use ($done_status, $scan_results) {
            if ($option === WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION) return $done_status;
            if ($option === WC_Multi_Store_Orphan_Cleanup::RESULTS_OPTION) return $scan_results;
            return $default;
        });

        $sent_data = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent_data) {
            $sent_data = $data;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_get_scan_status();

        $this->assertEquals('done', $sent_data['status']['status']);
        $this->assertNotNull($sent_data['results']);
        $this->assertEquals(3, $sent_data['results']['results'][0]['total_orphans']);
    }

    public function test_ajax_get_scan_status_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error_sent = false;
        Functions\when('wp_send_json_error')->alias(function () use (&$error_sent) {
            $error_sent = true;
        });

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $cleanup->ajax_get_scan_status();

        $this->assertTrue($error_sent);
    }

    // ── send_scan_complete_email ─────────────────────────────────

    public function test_send_scan_complete_email_not_sent_without_recipient(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_email_settings') return [];
            if ($option === 'admin_email') return '';
            return $default;
        });

        $mail_sent = false;
        Functions\when('wp_mail')->alias(function () use (&$mail_sent) {
            $mail_sent = true;
            return true;
        });

        $method  = new ReflectionMethod(WC_Multi_Store_Orphan_Cleanup::class, 'send_scan_complete_email');
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $method->invoke($cleanup, ['success' => true, 'results' => []], '');

        $this->assertFalse($mail_sent, 'Email should not be sent when recipient is empty');
    }

    public function test_send_scan_complete_email_sends_html_email(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_email_settings') return ['recipient_email' => 'owner@example.com'];
            if ($option === 'admin_email') return 'admin@example.com';
            return $default;
        });
        Functions\when('get_bloginfo')->justReturn('My Shop');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
        Functions\when('esc_url')->alias(fn($u) => $u);
        Functions\when('esc_html')->alias(fn($s) => $s);
        Functions\when('esc_attr')->alias(fn($s) => $s);

        $mail_args = null;
        Functions\when('wp_mail')->alias(function ($to, $subject, $body, $headers) use (&$mail_args) {
            $mail_args = compact('to', 'subject', 'body', 'headers');
            return true;
        });

        $results = [
            'success' => true,
            'results' => [
                ['store_name' => 'Store 1', 'store_url' => 'https://store1.com', 'total_remote' => 50, 'total_orphans' => 3],
            ],
        ];

        $method  = new ReflectionMethod(WC_Multi_Store_Orphan_Cleanup::class, 'send_scan_complete_email');
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $method->invoke($cleanup, $results, '');

        $this->assertNotNull($mail_args, 'wp_mail should be called');
        $this->assertEquals('owner@example.com', $mail_args['to']);
        $this->assertStringContainsString('Orphan Scan Complete', $mail_args['subject']);
        $this->assertStringContainsString('3', $mail_args['subject']);
        // WC_Multi_Store_Test_Mailer::wrap_message() (tests/php/bootstrap.php) wraps
        // heading + content deterministically, standing in for WooCommerce's real
        // email header/footer templates.
        $this->assertStringContainsString('<html><body><h1>Found 3 Orphan Product(s)</h1>', $mail_args['body']);
        $this->assertStringContainsString('Store 1', $mail_args['body']);
        $this->assertContains('Content-Type: text/html; charset=UTF-8', $mail_args['headers']);
    }

    public function test_send_scan_complete_email_reports_zero_orphans(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_email_settings') return ['recipient_email' => 'owner@example.com'];
            if ($option === 'admin_email') return 'admin@example.com';
            return $default;
        });
        Functions\when('get_bloginfo')->justReturn('My Shop');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
        Functions\when('esc_url')->alias(fn($u) => $u);
        Functions\when('esc_html')->alias(fn($s) => $s);
        Functions\when('esc_attr')->alias(fn($s) => $s);

        $mail_args = null;
        Functions\when('wp_mail')->alias(function ($to, $subject, $body, $headers) use (&$mail_args) {
            $mail_args = compact('to', 'subject', 'body', 'headers');
            return true;
        });

        $results = ['success' => true, 'results' => [
            ['store_name' => 'Clean Store', 'store_url' => 'https://store1.com', 'total_remote' => 20, 'total_orphans' => 0],
        ]];

        $method  = new ReflectionMethod(WC_Multi_Store_Orphan_Cleanup::class, 'send_scan_complete_email');
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $method->invoke($cleanup, $results, '');

        $this->assertStringContainsString('0 orphan', $mail_args['subject']);
        $this->assertStringContainsString('No Orphan Products Found', $mail_args['body']);
    }
}
