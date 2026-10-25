<?php
/**
 * Extended unit tests for WC_Multi_Store_Bulk_Actions
 *
 * Covers: all action types, bulk_delete_products, empty inputs,
 * no active stores, notices for all types.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('absint')->alias(static fn($id) => abs((int) $id));
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

    // ── precise queue interaction ────────────────────────────────

    private function mockQueueManager(): \Mockery\MockInterface
    {
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        WC_Multi_Store_Sync::instance()->queue_manager = $queue;

        return $queue;
    }

    public static function syncActionProvider(): array
    {
        return [
            'full sync'   => ['mss_sync_full', 'full_product'],
            'prices'      => ['mss_sync_prices', 'price_quantity'],
            'stock only'  => ['mss_sync_stock', 'quantity'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('syncActionProvider')]
    public function test_sync_actions_queue_with_matching_sync_type_and_normal_priority(string $action, string $syncType): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        $this->mockQueueManager()
            ->shouldReceive('add_products')
            ->once()
            ->with([100, 200], 'bulk_sync', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL, $syncType)
            ->andReturn(2);

        $result = (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('https://example.com/admin', $action, [100, 200]);

        $this->assertSame('https://example.com/admin&mss_synced=2', $result);
    }

    public function test_queue_high_action_queues_with_highest_priority_and_reports_queued_count(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        $this->mockQueueManager()
            ->shouldReceive('add_products')
            ->once()
            ->with([7, 8, 9], 'bulk_action', 1)
            ->andReturn(3);

        $result = (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('https://example.com/admin', 'mss_queue_high', [7, 8, 9]);

        $this->assertSame('https://example.com/admin&mss_queued=3', $result);
    }

    public function test_sync_action_reports_the_actual_count_returned_by_the_queue(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        // Two stores x two products: the queue decides how many items it created.
        $this->mockQueueManager()->shouldReceive('add_products')->andReturn(4);

        $result = (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('https://example.com/admin', 'mss_sync_full', [1, 2]);

        $this->assertStringEndsWith('mss_synced=4', $result);
    }

    public function test_sync_action_with_no_active_stores_never_touches_the_queue(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        Functions\when('get_option')->alias(fn($option, $default = false) => $default);
        WC_Multi_Store_Settings::clear_static_cache();
        $this->mockQueueManager()->shouldReceive('add_products')->never();

        $result = (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('https://example.com/admin', 'mss_sync_full', [1]);

        $this->assertSame('https://example.com/admin&mss_synced=0', $result);
    }

    public function test_unauthenticated_request_never_touches_the_queue(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);
        $_REQUEST['_wpnonce'] = 'forged';
        $this->mockQueueManager()->shouldReceive('add_products')->never();
        $this->mockQueueManager()->shouldReceive('add_product_deletion')->never();

        $bulk = new WC_Multi_Store_Bulk_Actions();
        foreach (['mss_sync_full', 'mss_queue_high', 'mss_bulk_delete'] as $action) {
            $this->assertSame('https://example.com/admin', $bulk->handle_bulk_actions('https://example.com/admin', $action, [1]));
        }
    }

    // bulk_delete_products() define()s DOING_BULK_EDIT, which would otherwise leak into every later test.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_bulk_delete_queues_each_product_at_low_priority_and_sums_results(): void
    {
        $_REQUEST['_wpnonce'] = 'test_nonce';
        $queue = $this->mockQueueManager();
        $queue->shouldReceive('add_product_deletion')->once()->with(10, 'bulk_delete', 5)->andReturn(1);
        $queue->shouldReceive('add_product_deletion')->once()->with(11, 'bulk_delete', 5)->andReturn(0); // already queued
        $queue->shouldReceive('add_product_deletion')->once()->with(12, 'bulk_delete', 5)->andReturn(1);

        $result = (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('https://example.com/admin', 'mss_bulk_delete', [10, 11, 12]);

        $this->assertSame('https://example.com/admin&mss_bulk_deleted=2', $result);
        $this->assertTrue(defined('DOING_BULK_EDIT') && DOING_BULK_EDIT);
    }

    // ── constructor / notices ────────────────────────────────────

    public function test_constructor_hooks_into_product_list_and_admin_notices(): void
    {
        $hooks = [];
        Functions\when('add_filter')->alias(function ($hook, $callback, $priority = 10, $args = 1) use (&$hooks) {
            $hooks[$hook] = [$priority, $args];
            return true;
        });
        Functions\when('add_action')->alias(function ($hook) use (&$hooks) {
            $hooks[$hook] = true;
            return true;
        });

        new WC_Multi_Store_Bulk_Actions();

        $this->assertSame([10, 1], $hooks['bulk_actions-edit-product']);
        $this->assertSame([10, 3], $hooks['handle_bulk_actions-edit-product']);
        $this->assertArrayHasKey('admin_notices', $hooks);
    }

    public function test_bulk_action_notices_shows_synced_count(): void
    {
        $_REQUEST['mss_synced'] = '12';

        ob_start();
        (new WC_Multi_Store_Bulk_Actions())->bulk_action_notices();
        $output = ob_get_clean();

        $this->assertStringContainsString('Successfully synced 12 product(s) to remote stores.', $output);
        $this->assertStringContainsString('notice-success', $output);
    }

    public function test_bulk_action_notices_never_show_negative_or_non_numeric_counts(): void
    {
        $_REQUEST['mss_queued'] = '-5';
        $_REQUEST['mss_bulk_deleted'] = '<script>alert(1)</script>';

        ob_start();
        (new WC_Multi_Store_Bulk_Actions())->bulk_action_notices();
        $output = ob_get_clean();

        $this->assertStringContainsString('Added 5 product(s)', $output);
        $this->assertStringContainsString('Queued 0 product(s)', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }
}
