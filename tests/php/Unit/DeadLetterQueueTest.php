<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

class DeadLetterQueueTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Logger calls current_time internally
        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir() . '/wc-mss-tests',
            'baseurl' => 'http://example.com/wp-content/uploads',
        ]);
        Functions\when('wp_mkdir_p')->justReturn(true);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    // ─── constants ─────────────────────────────────

    public function test_table_name_constant(): void
    {
        $this->assertEquals('wc_mss_dead_letter_queue', WC_Multi_Store_Dead_Letter_Queue::TABLE_NAME);
    }

    // ─── add_from_queue ────────────────────────────

    public function test_add_from_queue_inserts_item(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 42;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);
        // maybe_notify_admin_threshold counts dead items — return 0 to skip notification
        $wpdb->shouldReceive('get_var')->andReturn('0');

        $queue_item = [
            'id' => 10,
            'product_id' => 100,
            'product_sku' => 'TEST-SKU',
            'store_url' => 'https://shop2.example.com',
            'sync_type' => 'full_product',
            'source' => 'hook',
            'attempts' => 3,
            'last_error' => 'Connection timeout',
            'extra_data' => null,
            'started_at' => '2026-01-01 11:00:00',
        ];

        $result = WC_Multi_Store_Dead_Letter_Queue::add_from_queue($queue_item);
        $this->assertEquals(42, $result);
    }

    public function test_add_from_queue_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->andReturn(false);

        $queue_item = [
            'product_id' => 100,
            'store_url' => 'https://shop2.example.com',
            'sync_type' => 'full_product',
        ];

        $result = WC_Multi_Store_Dead_Letter_Queue::add_from_queue($queue_item);
        $this->assertFalse($result);
    }

    public function test_add_from_queue_uses_defaults_for_missing_fields(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_wc_mss_dead_letter_queue',
            \Mockery::on(function ($data) {
                return $data['source'] === 'unknown'
                    && $data['attempts'] === 0
                    && $data['status'] === 'dead';
            }),
            \Mockery::any()
        )->andReturn(1);
        // maybe_notify_admin_threshold counts dead items — return 0 to skip notification
        $wpdb->shouldReceive('get_var')->andReturn('0');

        $result = WC_Multi_Store_Dead_Letter_Queue::add_from_queue([
            'product_id' => 100,
            'store_url' => 'https://shop2.example.com',
            'sync_type' => 'full_product',
        ]);

        $this->assertEquals(1, $result);
    }

    // ─── get_items ─────────────────────────────────

    public function test_get_items_returns_results_and_total(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $mock_results = [
            ['id' => 1, 'product_id' => 100, 'status' => 'dead'],
            ['id' => 2, 'product_id' => 200, 'status' => 'dead'],
        ];

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn($mock_results);
        $wpdb->shouldReceive('get_var')->once()->andReturn(2);

        Functions\expect('sanitize_sql_orderby')->once()->andReturn('failed_at DESC');

        $items = WC_Multi_Store_Dead_Letter_Queue::get_items();

        $this->assertArrayHasKey('results', $items);
        $this->assertArrayHasKey('total', $items);
        $this->assertCount(2, $items['results']);
        $this->assertEquals(2, $items['total']);
    }

    public function test_get_items_with_empty_results(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);
        $wpdb->shouldReceive('get_var')->once()->andReturn(0);

        Functions\expect('sanitize_sql_orderby')->once()->andReturn('failed_at DESC');

        $items = WC_Multi_Store_Dead_Letter_Queue::get_items();

        $this->assertEmpty($items['results']);
        $this->assertEquals(0, $items['total']);
    }

    public function test_get_items_filters_by_product_id(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);
        $wpdb->shouldReceive('get_var')->once()->andReturn(0);

        Functions\expect('sanitize_sql_orderby')->once()->andReturn('failed_at DESC');

        $items = WC_Multi_Store_Dead_Letter_Queue::get_items(['product_id' => 123]);

        $this->assertIsArray($items);
    }

    // ─── retry_item ────────────────────────────────

    public function test_retry_item_returns_false_when_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = WC_Multi_Store_Dead_Letter_Queue::retry_item(999);
        $this->assertFalse($result);
    }

    // ─── resolve_item ──────────────────────────────

    public function test_resolve_item_updates_status(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = WC_Multi_Store_Dead_Letter_Queue::resolve_item(5);
        $this->assertTrue($result);
    }

    public function test_resolve_item_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = WC_Multi_Store_Dead_Letter_Queue::resolve_item(999);
        $this->assertFalse($result);
    }

    // ─── clear_all ─────────────────────────────────

    public function test_clear_all_deletes_dead_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')->once()->andReturn(5);

        $result = WC_Multi_Store_Dead_Letter_Queue::clear_all();
        $this->assertEquals(5, $result);
    }

    public function test_clear_all_returns_zero_when_empty(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')->once()->andReturn(0);

        $result = WC_Multi_Store_Dead_Letter_Queue::clear_all();
        $this->assertEquals(0, $result);
    }

    // ─── get_stats ─────────────────────────────────

    public function test_get_stats_returns_all_keys(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_var')->andReturn(0);
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $stats = WC_Multi_Store_Dead_Letter_Queue::get_stats();

        $this->assertArrayHasKey('total_dead', $stats);
        $this->assertArrayHasKey('total_retried', $stats);
        $this->assertArrayHasKey('total_resolved', $stats);
        $this->assertArrayHasKey('by_store', $stats);
        $this->assertArrayHasKey('by_error', $stats);
        $this->assertArrayHasKey('oldest_item', $stats);
        $this->assertArrayHasKey('newest_item', $stats);
    }

    public function test_get_stats_returns_integers(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_var')->andReturn('3');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $stats = WC_Multi_Store_Dead_Letter_Queue::get_stats();

        $this->assertIsInt($stats['total_dead']);
        $this->assertIsInt($stats['total_retried']);
        $this->assertIsInt($stats['total_resolved']);
    }

    // ─── cleanup ───────────────────────────────────

    public function test_cleanup_removes_old_resolved_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('DELETE ...');
        $wpdb->shouldReceive('query')->once()->andReturn(3);

        $result = WC_Multi_Store_Dead_Letter_Queue::cleanup(30);
        $this->assertEquals(3, $result);
    }

    // ─── retry_all ─────────────────────────────────

    public function test_retry_all_returns_count(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;

        $wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(0);

        Functions\expect('sanitize_sql_orderby')->andReturn('failed_at DESC');

        $result = WC_Multi_Store_Dead_Letter_Queue::retry_all();
        $this->assertEquals(0, $result);
    }
}
