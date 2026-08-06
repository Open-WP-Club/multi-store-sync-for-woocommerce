<?php
/**
 * Unit tests for WC_Multi_Store_Queue_Table
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class QueueTableTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Queue_Table'));
    }

    public function test_table_name_constant(): void
    {
        $this->assertEquals('wc_mss_queue', WC_Multi_Store_Queue_Table::TABLE_NAME);
    }

    public function test_db_version_constant(): void
    {
        $this->assertEquals('1.2', WC_Multi_Store_Queue_Table::DB_VERSION);
    }

    public function test_has_required_methods(): void
    {
        $methods = [
            'create_table', 'drop_table', 'add', 'get_next_batch',
            'mark_processing', 'mark_completed', 'mark_failed',
            'get_stats', 'get_recent_items', 'cleanup', 'clear_all',
            'get_by_product', 'get_by_store', 'reset_stuck_items',
            'retry_item', 'retry_failed_items', 'clear_failed', 'clear_completed', 'clear_pending',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_Queue_Table', $method),
                "Missing method: {$method}"
            );
        }
    }

    // ─── add ───────────────────────────────────────

    public function test_add_inserts_new_item(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        // No existing item
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        // Insert new item
        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) =>
                    $data['product_id'] === 42
                    && $data['store_url'] === 'https://store.com'
                    && $data['sync_type'] === 'full_product'
                    && $data['priority'] === 5
                    && $data['status'] === 'pending'
                    && $data['attempts'] === 0
                ),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Queue_Table::add(42, 'https://store.com');

        $this->assertEquals(1, $result);
    }

    public function test_add_returns_existing_id_for_duplicate(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // Existing pending item
        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['id' => 99, 'status' => 'pending']);

        // No update since default priority
        $result = WC_Multi_Store_Queue_Table::add(42, 'https://store.com');

        $this->assertEquals(99, $result);
    }

    public function test_add_updates_priority_for_high_priority_duplicate(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['id' => 99, 'status' => 'pending']);

        // Should update priority when new priority < 5
        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) => $data['priority'] === 1),
                ['id' => 99],
                \Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Queue_Table::add(42, 'https://store.com', 'full_product', 1, 'manual_sync');

        $this->assertEquals(99, $result);
    }

    public function test_add_creates_new_item_when_existing_is_processing(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 100;

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['id' => 99, 'status' => 'processing']);

        // Should insert new item since existing is processing
        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(true);

        $result = WC_Multi_Store_Queue_Table::add(42, 'https://store.com');

        $this->assertEquals(100, $result);
    }

    public function test_add_returns_false_on_insert_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = WC_Multi_Store_Queue_Table::add(42, 'https://store.com');

        $this->assertFalse($result);
    }

    public function test_add_with_extra_data_encodes_json(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 5;

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) =>
                    $data['extra_data'] === '{"action":"delete"}'
                    && $data['product_sku'] === 'SKU-001'
                ),
                \Mockery::type('array')
            )
            ->andReturn(true);

        $result = WC_Multi_Store_Queue_Table::add(
            42, 'https://store.com', 'full_product', 5, 'manual',
            null, 'SKU-001', ['action' => 'delete']
        );

        $this->assertEquals(5, $result);
    }

    // ─── get_next_batch ────────────────────────────

    public function test_get_next_batch_returns_pending_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $items = [
            ['id' => 1, 'product_id' => 42, 'status' => 'pending'],
            ['id' => 2, 'product_id' => 43, 'status' => 'pending'],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn($items);

        $result = WC_Multi_Store_Queue_Table::get_next_batch(10);

        $this->assertCount(2, $result);
        $this->assertEquals(42, $result[0]['product_id']);
    }

    public function test_get_next_batch_returns_empty_when_no_pending(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = WC_Multi_Store_Queue_Table::get_next_batch();

        $this->assertEmpty($result);
    }

    // ─── mark_processing ───────────────────────────

    public function test_mark_processing_updates_status(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) =>
                    $data['status'] === 'processing'
                    && isset($data['started_at'])
                ),
                ['id' => 1],
                ['%s', '%s'],
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Queue_Table::mark_processing(1);

        $this->assertEquals(1, $result);
    }

    // ─── mark_completed ────────────────────────────

    public function test_mark_completed_updates_status(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) =>
                    $data['status'] === 'completed'
                    && isset($data['completed_at'])
                ),
                ['id' => 1],
                ['%s', '%s'],
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Queue_Table::mark_completed(1);

        $this->assertEquals(1, $result);
    }

    // ─── mark_failed ───────────────────────────────

    public function test_mark_failed_retries_under_max_attempts(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('UPDATE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(1); // Increment attempts
        $wpdb->shouldReceive('get_var')->once()->andReturn('1'); // 1 attempt so far

        // Should set back to pending with scheduled_at
        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) =>
                    $data['status'] === 'pending'
                    && isset($data['scheduled_at'])
                ),
                ['id' => 1],
                ['%s', '%s', '%s'],
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Queue_Table::mark_failed(1, 'API error');

        $this->assertEquals(1, $result);
    }

    public function test_mark_failed_permanently_at_max_attempts(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('UPDATE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(1);
        // get_var called twice: once for current attempts, once by DLQ notification threshold check
        $wpdb->shouldReceive('get_var')->andReturn('3', '0');

        // DLQ integration: get_row fetches item, insert adds to DLQ
        $wpdb->shouldReceive('get_row')->once()->andReturn([
            'id' => 1, 'product_id' => 100, 'store_url' => 'https://shop2.example.com',
            'sync_type' => 'full_product', 'source' => 'hook', 'attempts' => 3,
        ]);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        // Should mark as failed permanently
        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) =>
                    $data['status'] === 'failed'
                    && $data['last_error'] === 'API error'
                    && isset($data['completed_at'])
                ),
                ['id' => 1],
                ['%s', '%s', '%s'],
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Queue_Table::mark_failed(1, 'API error');

        $this->assertEquals(1, $result);
    }

    public function test_mark_failed_with_no_retry_flag(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('UPDATE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(1);
        // get_var called twice: once for current attempts, once by DLQ notification threshold check
        $wpdb->shouldReceive('get_var')->andReturn('1', '0');

        // DLQ integration: get_row fetches item, insert adds to DLQ
        $wpdb->shouldReceive('get_row')->once()->andReturn([
            'id' => 1, 'product_id' => 100, 'store_url' => 'https://shop2.example.com',
            'sync_type' => 'full_product', 'source' => 'hook', 'attempts' => 1,
        ]);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        // Should mark as failed immediately despite low attempts
        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_wc_mss_queue',
                \Mockery::on(fn($data) =>
                    $data['status'] === 'failed'
                    && isset($data['completed_at'])
                ),
                ['id' => 1],
                ['%s', '%s', '%s'],
                ['%d']
            )
            ->andReturn(1);

        $result = WC_Multi_Store_Queue_Table::mark_failed(1, 'No retry', true);

        $this->assertEquals(1, $result);
    }

    // ─── get_stats ─────────────────────────────────

    public function test_get_stats_returns_all_status_counts(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn([
                'pending'    => '10',
                'processing' => '2',
                'completed'  => '50',
                'failed'     => '3',
                'total'      => '65',
            ]);

        $stats = WC_Multi_Store_Queue_Table::get_stats();

        $this->assertEquals(10, $stats['pending']);
        $this->assertEquals(2, $stats['processing']);
        $this->assertEquals(50, $stats['completed']);
        $this->assertEquals(3, $stats['failed']);
        $this->assertEquals(65, $stats['total']);
    }

    // ─── cleanup ───────────────────────────────────

    public function test_cleanup_deletes_old_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('DELETE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(25);

        $deleted = WC_Multi_Store_Queue_Table::cleanup(7);

        $this->assertEquals(25, $deleted);
    }

    // ─── clear_all ─────────────────────────────────

    public function test_clear_all_truncates_table(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->with('TRUNCATE TABLE wp_wc_mss_queue')
            ->andReturn(1);

        $result = WC_Multi_Store_Queue_Table::clear_all();

        $this->assertEquals(1, $result);
    }

    // ─── get_by_product ────────────────────────────

    public function test_get_by_product_returns_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $items = [
            ['id' => 1, 'product_id' => 42, 'status' => 'completed'],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn($items);

        $result = WC_Multi_Store_Queue_Table::get_by_product(42);

        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]['product_id']);
    }

    // ─── get_by_store ──────────────────────────────

    public function test_get_by_store_returns_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = WC_Multi_Store_Queue_Table::get_by_store('https://store.com');

        $this->assertIsArray($result);
    }

    // ─── reset_stuck_items ─────────────────────────

    public function test_reset_stuck_items_resets_processing(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('UPDATE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(3);

        $reset = WC_Multi_Store_Queue_Table::reset_stuck_items(10);

        $this->assertEquals(3, $reset);
    }

    public function test_reset_stuck_items_returns_zero_when_none(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('UPDATE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        $reset = WC_Multi_Store_Queue_Table::reset_stuck_items();

        $this->assertEquals(0, $reset);
    }

    // ─── retry_failed_items ────────────────────────

    public function test_retry_failed_items_resets_to_pending(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->with(\Mockery::pattern("/SET status = 'pending'/"))
            ->andReturn(5);

        // DLQ cleanup query (called because reset_count > 0 and class exists)
        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(\Mockery::pattern("/SET status = 'retried'/"), \Mockery::any())
            ->andReturn("UPDATE wp_wc_mss_dead_letter_queue SET status = 'retried', retried_at = '2025-01-01' WHERE status = 'dead'");

        $wpdb->shouldReceive('query')
            ->once()
            ->with(\Mockery::pattern("/SET status = 'retried'/"))
            ->andReturn(3);

        $result = WC_Multi_Store_Queue_Table::retry_failed_items();

        $this->assertEquals(5, $result);
    }

    // ─── clear_failed ──────────────────────────────

    public function test_clear_failed_deletes_failed_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->with(\Mockery::pattern("/status = 'failed'/"))
            ->andReturn(7);

        $result = WC_Multi_Store_Queue_Table::clear_failed();

        $this->assertEquals(7, $result);
    }

    // ─── clear_completed ───────────────────────────

    public function test_clear_completed_deletes_completed_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->with(\Mockery::pattern("/status = 'completed'/"))
            ->andReturn(50);

        $result = WC_Multi_Store_Queue_Table::clear_completed();

        $this->assertEquals(50, $result);
    }

    // ─── clear_pending ─────────────────────────────

    public function test_clear_pending_deletes_pending_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')
            ->once()
            ->with(\Mockery::pattern("/status = 'pending'/"))
            ->andReturn(10);

        $result = WC_Multi_Store_Queue_Table::clear_pending();

        $this->assertEquals(10, $result);
    }
}
