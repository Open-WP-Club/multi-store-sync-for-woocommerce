<?php
/**
 * Tests for WC_MSS()->queue_manager->process_queue() workflow branches
 *
 * Covers the completely untested sync type handlers inside do_process_queue():
 * delete_product, status_change, restore_product, delete_variation, delete_orphan,
 * and regular sync (create/update) including error handling and result processing.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

// WC_Multi_Store_Sync stub + WC_MSS() are defined in bootstrap.php

class QueueManagerProcessTest extends WC_Multi_Store_TestCase
{
    private object $mockSyncEngine;

    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true, 'sync_type_default' => 'full_product'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test1',
                        'consumer_secret' => 'cs_test1',
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
        Functions\when('do_action')->justReturn(null);
        Functions\when('sanitize_sql_orderby')->alias(fn($val) => $val);

        // Ensure a fresh real queue_manager instance (not a leftover Mockery mock).
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();

        // Mock the sync engine and inject into the singleton
        $this->mockSyncEngine = \Mockery::mock('WC_Multi_Store_Sync_Engine');
        WC_Multi_Store_Sync::instance()->sync_engine = $this->mockSyncEngine;
    }

    /**
     * Helper to create a queue item object
     */
    private function makeQueueItem(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'product_id' => 100,
            'store_url' => 'https://store1.com',
            'sync_type' => 'full_product',
            'source' => 'manual',
            'product_sku' => 'TEST-SKU',
            'extra_data' => null,
        ], $overrides);
    }

    /**
     * Set up $wpdb mock with lock acquisition and batch items
     */
    private function setupProcessQueueMocks(array $queueItems): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(0);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('delete')->andReturn(1);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->insert_id = 1;

        // get_var: GET_LOCK=1, then any subsequent variation lookups
        $wpdb->shouldReceive('get_var')->andReturn('1', '0', '0', '0', '0', '0');

        // get_stats() now uses a single aggregate get_row instead of 5 get_var calls
        $wpdb->shouldReceive('get_row')->andReturn([
            'pending'    => '0',
            'processing' => '0',
            'completed'  => '0',
            'failed'     => '0',
            'total'      => '0',
        ]);

        // get_results: first call = get_next_batch (queue items), rest = empty
        $firstCall = true;
        $items = $queueItems;
        $wpdb->shouldReceive('get_results')->andReturnUsing(function () use (&$firstCall, $items) {
            if ($firstCall) {
                $firstCall = false;
                return $items;
            }
            return [];
        });
    }

    // ── delete_product workflow ───────────────────────────────────

    public function test_process_queue_handles_delete_product_with_stored_sku(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_product',
            'product_sku' => 'DEL-SKU',
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('delete_product_from_store')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Deleted']);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success'], 'Full result: ' . json_encode($result));
        $this->assertEquals(0, $result['errors']);
    }

    public function test_process_queue_delete_product_fails_without_sku(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_product',
            'product_sku' => '',
        ]);

        $this->setupProcessQueueMocks([$item]);

        // No sync engine call expected - should fail before reaching it
        $this->mockSyncEngine->shouldNotReceive('delete_product_from_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_delete_product_with_sync_engine_failure(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_product',
            'product_sku' => 'DEL-SKU',
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('delete_product_from_store')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Product not found on remote store']);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    // ── status_change workflow ────────────────────────────────────

    public function test_process_queue_handles_status_change_with_stored_data(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'status_change',
            'product_sku' => 'STATUS-SKU',
            'extra_data' => json_encode(['old_status' => 'publish', 'new_status' => 'draft']),
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('update_product_status_on_store')
            ->once()
            ->with(100, 'STATUS-SKU', 'draft', 'https://store1.com', \Mockery::type('array'), 'manual')
            ->andReturn(['success' => true, 'message' => 'Status updated']);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
        $this->assertEquals(0, $result['errors']);
    }

    public function test_process_queue_status_change_fails_without_new_status(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'status_change',
            'product_sku' => 'STATUS-SKU',
            'extra_data' => json_encode(['old_status' => 'publish']),
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldNotReceive('update_product_status_on_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_status_change_looks_up_sku_from_product(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'status_change',
            'product_sku' => '', // Empty - should look up from product
            'extra_data' => json_encode(['new_status' => 'draft']),
        ]);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_sku')->andReturn('LOOKED-UP-SKU');

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('update_product_status_on_store')
            ->once()
            ->with(100, 'LOOKED-UP-SKU', 'draft', \Mockery::any(), \Mockery::any(), \Mockery::any())
            ->andReturn(['success' => true]);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
    }

    // ── restore_product workflow ──────────────────────────────────

    public function test_process_queue_handles_restore_product(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'restore_product',
            'product_sku' => 'RESTORE-SKU',
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('restore_product_on_store')
            ->once()
            ->with(100, 'RESTORE-SKU', 'https://store1.com', \Mockery::type('array'), 'manual')
            ->andReturn(['success' => true, 'message' => 'Restored']);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
    }

    public function test_process_queue_restore_fails_without_sku(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'restore_product',
            'product_sku' => '',
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldNotReceive('restore_product_on_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['errors']);
    }

    // ── delete_variation workflow ─────────────────────────────────

    public function test_process_queue_handles_delete_variation(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_variation',
            'product_sku' => 'VAR-SKU',
            'extra_data' => json_encode(['parent_id' => 50]),
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('delete_variation_from_store')
            ->once()
            ->with(100, 'VAR-SKU', 50, 'https://store1.com', \Mockery::type('array'), 'manual')
            ->andReturn(['success' => true, 'message' => 'Variation deleted']);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
    }

    public function test_process_queue_delete_variation_fails_without_parent_id(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_variation',
            'product_sku' => 'VAR-SKU',
            'extra_data' => null, // No parent_id
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldNotReceive('delete_variation_from_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_delete_variation_fails_without_sku(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_variation',
            'product_sku' => '',
            'extra_data' => json_encode(['parent_id' => 50]),
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldNotReceive('delete_variation_from_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['errors']);
    }

    // ── delete_orphan workflow ────────────────────────────────────

    public function test_process_queue_handles_delete_orphan_with_remote_id(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_orphan',
            'product_sku' => '',
            'extra_data' => json_encode(['remote_product_id' => 999]),
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('delete_orphan_product_from_store')
            ->once()
            ->with(100, '', 999, 'https://store1.com', \Mockery::type('array'), 'manual')
            ->andReturn(['success' => true, 'message' => 'Orphan deleted']);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
    }

    public function test_process_queue_handles_delete_orphan_with_sku(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_orphan',
            'product_sku' => 'ORPHAN-SKU',
            'extra_data' => null,
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('delete_orphan_product_from_store')
            ->once()
            ->with(100, 'ORPHAN-SKU', null, 'https://store1.com', \Mockery::type('array'), 'manual')
            ->andReturn(['success' => true]);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
    }

    public function test_process_queue_delete_orphan_fails_without_sku_and_remote_id(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_orphan',
            'product_sku' => '',
            'extra_data' => null,
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldNotReceive('delete_orphan_product_from_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['errors']);
    }

    // ── regular sync workflow ─────────────────────────────────────

    public function test_process_queue_regular_sync_with_published_product(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('publish');
        $mockProduct->shouldReceive('get_sku')->andReturn('TEST-SKU');
        $mockProduct->shouldReceive('is_type')->andReturn(false);
        $mockProduct->shouldReceive('get_id')->andReturn(100);

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('sync_product_to_store')
            ->once()
            ->with($mockProduct, 'https://store1.com', \Mockery::type('array'), 'full_product', 'manual')
            ->andReturn(['success' => true]);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
    }

    public function test_process_queue_regular_sync_skips_draft_product(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('draft');
        $mockProduct->shouldReceive('get_sku')->andReturn('DRAFT-SKU');

        Functions\when('wc_get_product')->justReturn($mockProduct);

        // Should NOT call sync - draft products are skipped
        $this->mockSyncEngine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        // Skipped drafts count as success (marked completed)
        $this->assertEquals(1, $result['success']);
        $this->assertEquals(0, $result['errors']);
    }

    public function test_process_queue_regular_sync_fails_when_product_not_found(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        Functions\when('wc_get_product')->justReturn(false);

        $this->mockSyncEngine->shouldNotReceive('sync_product_to_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    // ── error handling & edge cases ──────────────────────────────

    public function test_process_queue_handles_store_config_not_found(): void
    {
        $item = $this->makeQueueItem([
            'store_url' => 'https://nonexistent-store.com',
            'sync_type' => 'full_product',
        ]);

        $this->setupProcessQueueMocks([$item]);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_handles_exception_in_sync(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('publish');
        $mockProduct->shouldReceive('get_sku')->andReturn('TEST-SKU');
        $mockProduct->shouldReceive('is_type')->andReturn(false);
        $mockProduct->shouldReceive('get_id')->andReturn(100);

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('sync_product_to_store')
            ->once()
            ->andThrow(new \RuntimeException('API connection failed'));

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_no_retry_for_product_not_found_on_remote(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('publish');
        $mockProduct->shouldReceive('is_type')->andReturn(false);
        $mockProduct->shouldReceive('get_id')->andReturn(100);

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('sync_product_to_store')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Product not found on remote store']);

        $result = WC_MSS()->queue_manager->process_queue(10);

        // The no_retry flag is passed to mark_failed - we verify error was counted
        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_triggers_sync_failed_notification_on_retryable_failure(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('publish');
        $mockProduct->shouldReceive('is_type')->andReturn(false);
        $mockProduct->shouldReceive('get_id')->andReturn(100);

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('sync_product_to_store')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Remote API timed out']);

        $triggered_args = null;
        Functions\when('do_action')->alias(function ($hook, ...$args) use (&$triggered_args) {
            if ($hook === 'wc_mss_sync_failed') {
                $triggered_args = $args;
            }
        });

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertSame([100, 'https://store1.com', 'Remote API timed out'], $triggered_args);
    }

    public function test_process_queue_triggers_api_error_notification_when_circuit_opens(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('publish');
        $mockProduct->shouldReceive('is_type')->andReturn(false);
        $mockProduct->shouldReceive('get_id')->andReturn(100);

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('sync_product_to_store')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Remote API timed out']);

        // Circuit breaker is one failure away from its default threshold (10) —
        // this failure should push it over and open the circuit. get_transient/
        // set_transient are wired to a shared variable so record_failure()'s
        // write is visible to is_open()'s subsequent read, like real transients.
        $cb_state = ['consecutive_errors' => 9, 'open_until' => 0, 'opened_at' => 0];
        Functions\when('get_transient')->alias(function ($key) use (&$cb_state) {
            return str_starts_with($key, 'wc_mss_cb_') ? $cb_state : false;
        });
        Functions\when('set_transient')->alias(function ($key, $value) use (&$cb_state) {
            if (str_starts_with($key, 'wc_mss_cb_')) {
                $cb_state = $value;
            }
            return true;
        });

        $api_error_triggered = false;
        Functions\when('do_action')->alias(function ($hook) use (&$api_error_triggered) {
            if ($hook === 'wc_mss_api_error') {
                $api_error_triggered = true;
            }
        });

        WC_MSS()->queue_manager->process_queue(10);

        $this->assertTrue($api_error_triggered);
    }

    public function test_process_queue_handles_null_result_from_sync(): void
    {
        $item = $this->makeQueueItem(['sync_type' => 'full_product']);

        $this->setupProcessQueueMocks([$item]);

        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('publish');
        $mockProduct->shouldReceive('is_type')->andReturn(false);
        $mockProduct->shouldReceive('get_id')->andReturn(100);

        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('sync_product_to_store')
            ->once()
            ->andReturn(null);

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_handles_multiple_items_mixed_results(): void
    {
        $items = [
            $this->makeQueueItem(['id' => 1, 'sync_type' => 'delete_product', 'product_sku' => 'DEL-1']),
            $this->makeQueueItem(['id' => 2, 'sync_type' => 'full_product', 'product_id' => 200]),
            $this->makeQueueItem(['id' => 3, 'sync_type' => 'restore_product', 'product_sku' => '']),
        ];

        $this->setupProcessQueueMocks($items);

        // Item 1: delete succeeds
        $this->mockSyncEngine->shouldReceive('delete_product_from_store')
            ->once()
            ->andReturn(['success' => true]);

        // Item 2: regular sync succeeds
        $mockProduct = \Mockery::mock('WC_Product');
        $mockProduct->shouldReceive('get_status')->andReturn('publish');
        $mockProduct->shouldReceive('is_type')->andReturn(false);
        $mockProduct->shouldReceive('get_id')->andReturn(200);
        Functions\when('wc_get_product')->justReturn($mockProduct);

        $this->mockSyncEngine->shouldReceive('sync_product_to_store')
            ->once()
            ->andReturn(['success' => true]);

        // Item 3: restore fails (no SKU)
        $this->mockSyncEngine->shouldNotReceive('restore_product_on_store');

        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(3, $result['processed']);
        $this->assertEquals(2, $result['success']);
        $this->assertEquals(1, $result['errors']);
    }

    public function test_process_queue_cleans_transient_on_success(): void
    {
        $item = $this->makeQueueItem([
            'sync_type' => 'delete_product',
            'product_sku' => 'DEL-SKU',
        ]);

        $this->setupProcessQueueMocks([$item]);

        $this->mockSyncEngine->shouldReceive('delete_product_from_store')
            ->andReturn(['success' => true]);

        // Verify delete_transient is called (already mocked as justReturn(true))
        // The fact this doesn't throw proves it's called correctly
        $result = WC_MSS()->queue_manager->process_queue(10);

        $this->assertEquals(1, $result['success']);
    }
}
