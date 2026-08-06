<?php
/**
 * Extended unit tests for WC_Multi_Store_Bulk_Actions
 *
 * Covers: all action types, bulk_delete_products, empty inputs,
 * no active stores, notices for all types.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class BulkActionsTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBulkMocks();
        $this->loadBulkActionsClass();
    }

    protected function tearDown(): void
    {
        unset($_REQUEST['_wpnonce'], $_REQUEST['mss_synced'], $_REQUEST['mss_queued'], $_REQUEST['mss_bulk_deleted']);
        parent::tearDown();
    }

    protected function setUpBulkMocks(): void
    {
        // Ensure a fresh real queue_manager instance (not a leftover Mockery mock).
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();

        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                    ],
                ];
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('current_datetime')->justReturn(new \DateTimeImmutable('2024-01-15 12:00:00'));
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 3) {
                return $args[2] . '&' . $args[0] . '=' . $args[1];
            }
            return $args[count($args) - 1] ?? '';
        });
    }

    private function loadBulkActionsClass(): void
    {
        $file = dirname(__DIR__, 3) . '/includes/bulk-actions.php';
        if (!class_exists('WC_Multi_Store_Bulk_Actions', false)) {
            require_once $file;
        }
    }

    private function setUpWpdbMock(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->insert_id = 1;
    }

    // ── add_bulk_actions ─────────────────────────────────────────

    public function test_add_bulk_actions_returns_all_five_actions(): void
    {
        $bulk = new WC_Multi_Store_Bulk_Actions();
        $result = $bulk->add_bulk_actions([]);

        $this->assertArrayHasKey('mss_sync_full', $result);
        $this->assertArrayHasKey('mss_sync_prices', $result);
        $this->assertArrayHasKey('mss_sync_stock', $result);
        $this->assertArrayHasKey('mss_queue_high', $result);
        $this->assertArrayHasKey('mss_bulk_delete', $result);
        $this->assertCount(5, $result);
    }

    public function test_add_bulk_actions_preserves_existing_actions(): void
    {
        $bulk = new WC_Multi_Store_Bulk_Actions();
        $existing = ['trash' => 'Move to Trash', 'edit' => 'Edit'];
        $result = $bulk->add_bulk_actions($existing);

        $this->assertArrayHasKey('trash', $result);
        $this->assertArrayHasKey('edit', $result);
        $this->assertArrayHasKey('mss_sync_full', $result);
        $this->assertCount(7, $result);
    }

    // ── handle_bulk_actions – mss_sync_prices ────────────────────

    public function test_handle_sync_prices_action(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        $this->setUpWpdbMock();

        $bulk = new WC_Multi_Store_Bulk_Actions();
        $result = $bulk->handle_bulk_actions('https://example.com/admin', 'mss_sync_prices', [100, 200]);

        $this->assertStringContainsString('mss_synced', $result);
    }

    // ── handle_bulk_actions – mss_sync_stock ─────────────────────

    public function test_handle_sync_stock_action(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        $this->setUpWpdbMock();

        $bulk = new WC_Multi_Store_Bulk_Actions();
        $result = $bulk->handle_bulk_actions('https://example.com/admin', 'mss_sync_stock', [100]);

        $this->assertStringContainsString('mss_synced', $result);
    }

    // ── handle_bulk_actions – mss_bulk_delete ────────────────────

    public function test_handle_bulk_delete_action(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        $this->setUpWpdbMock();

        $bulk = new WC_Multi_Store_Bulk_Actions();
        $result = $bulk->handle_bulk_actions('https://example.com/admin', 'mss_bulk_delete', [100, 200, 300]);

        $this->assertStringContainsString('mss_bulk_deleted', $result);
    }

    // ── handle_bulk_actions – no nonce set ────────────────────────

    public function test_handle_bulk_actions_without_nonce_returns_redirect(): void
    {
        unset($_REQUEST['_wpnonce']); // guard against leakage from other tests
        $bulk = new WC_Multi_Store_Bulk_Actions();
        $result = $bulk->handle_bulk_actions('https://example.com/admin', 'mss_sync_full', [100]);

        $this->assertEquals('https://example.com/admin', $result);
    }

    // ── bulk_sync_products empty inputs ──────────────────────────

    public function test_bulk_sync_empty_products_returns_zero(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';

        $bulk = new WC_Multi_Store_Bulk_Actions();
        $method = new ReflectionMethod($bulk, 'bulk_sync_products');

        $result = $method->invoke($bulk, [], 'full_product');

        $this->assertEquals(0, $result);
    }

    public function test_bulk_sync_no_active_stores_returns_zero(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_stores') {
                return []; // No stores
            }
            return $default;
        });

        $bulk = new WC_Multi_Store_Bulk_Actions();
        $method = new ReflectionMethod($bulk, 'bulk_sync_products');

        $result = $method->invoke($bulk, [100, 200], 'full_product');

        $this->assertEquals(0, $result);
    }

    // ── bulk_delete_products ─────────────────────────────────────

    public function test_bulk_delete_empty_products_returns_zero(): void
    {
        $bulk = new WC_Multi_Store_Bulk_Actions();
        $method = new ReflectionMethod($bulk, 'bulk_delete_products');

        $result = $method->invoke($bulk, []);

        $this->assertEquals(0, $result);
    }

    public function test_bulk_delete_queues_each_product(): void
    {
        $this->setUpWpdbMock();

        $bulk = new WC_Multi_Store_Bulk_Actions();
        $method = new ReflectionMethod($bulk, 'bulk_delete_products');

        $result = $method->invoke($bulk, [100, 200, 300]);

        // Each product should be queued (add_product_deletion returns count)
        $this->assertIsInt($result);
    }

    // ── bulk_action_notices ──────────────────────────────────────

    public function test_bulk_action_notices_shows_queued_count(): void
    {
        $_REQUEST['mss_queued'] = 8;

        $bulk = new WC_Multi_Store_Bulk_Actions();

        ob_start();
        $bulk->bulk_action_notices();
        $output = ob_get_clean();

        $this->assertStringContainsString('8', $output);
        $this->assertStringContainsString('queue', $output);
    }

    public function test_bulk_action_notices_shows_deleted_count(): void
    {
        $_REQUEST['mss_bulk_deleted'] = 3;

        $bulk = new WC_Multi_Store_Bulk_Actions();

        ob_start();
        $bulk->bulk_action_notices();
        $output = ob_get_clean();

        $this->assertStringContainsString('3', $output);
        $this->assertStringContainsString('deletion', $output);
    }

    public function test_bulk_action_notices_no_request_params_outputs_nothing(): void
    {
        $bulk = new WC_Multi_Store_Bulk_Actions();

        ob_start();
        $bulk->bulk_action_notices();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_bulk_action_notices_shows_multiple_notices(): void
    {
        $_REQUEST['mss_synced'] = 5;
        $_REQUEST['mss_queued'] = 3;

        $bulk = new WC_Multi_Store_Bulk_Actions();

        ob_start();
        $bulk->bulk_action_notices();
        $output = ob_get_clean();

        // Both notices should be shown
        $this->assertStringContainsString('5', $output);
        $this->assertStringContainsString('3', $output);
    }

    // ── handle_bulk_actions – non-mss action (passthrough) ───────

    public function test_handle_bulk_actions_ignores_non_mss_action(): void
    {
        $bulk = new WC_Multi_Store_Bulk_Actions();

        $method = new ReflectionMethod($bulk, 'handle_bulk_actions');
        $result = $method->invoke($bulk, 'https://example.com/admin', 'delete', [1, 2]);

        $this->assertEquals('https://example.com/admin', $result);
    }

    // ── handle_bulk_actions – invalid nonce ───────────────────────

    public function test_handle_bulk_actions_rejects_invalid_nonce(): void
    {
        $_REQUEST['_wpnonce'] = 'bad_nonce';
        Functions\when('wp_verify_nonce')->justReturn(false);

        $bulk = new WC_Multi_Store_Bulk_Actions();

        $method = new ReflectionMethod($bulk, 'handle_bulk_actions');
        $result = $method->invoke($bulk, 'https://example.com/admin', 'mss_sync_full', [100]);

        $this->assertEquals('https://example.com/admin', $result);
    }

    // ── handle_bulk_actions – unknown mss_ action ────────────────

    public function test_handle_unknown_mss_action_does_nothing(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';

        $bulk = new WC_Multi_Store_Bulk_Actions();
        $result = $bulk->handle_bulk_actions('https://example.com/admin', 'mss_unknown_action', [100]);

        // Unknown mss_ action passes nonce check but falls through switch
        $this->assertEquals('https://example.com/admin', $result);
    }
}
