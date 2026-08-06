<?php
/**
 * Functional tests for WC_Multi_Store_Queue_Manager
 * Tests real business logic (not just method signatures)
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class QueueManagerFunctionalTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQueueMocks();
        // Ensure a fresh real queue_manager instance (not a leftover Mockery mock).
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();
    }

    protected function setUpQueueMocks(): void
    {
        // Clear static cache to prevent contamination from other test classes
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test1',
                        'consumer_secret' => 'cs_test1',
                    ],
                    'https://store2.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test2',
                        'consumer_secret' => 'cs_test2',
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
    }

    // ── add_product ──────────────────────────────────────────────

    public function test_add_product_inserts_to_queue_for_each_store(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        // get_product_terms returns empty; get_var returns null (not a variation)
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        // QueueTable::add checks for existing, then inserts
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $result = WC_MSS()->queue_manager->add_product(123, 'product_save');

        // 2 stores → 2 queue items
        $this->assertEquals(2, $result);
    }

    public function test_add_product_returns_zero_with_no_active_stores(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        // Override stores to empty
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_stores') {
                return [];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true, 'sync_type_default' => 'full_product'];
            }
            return $default;
        });

        $result = WC_MSS()->queue_manager->add_product(123, 'manual');
        $this->assertEquals(0, $result);
    }

    public function test_add_product_skips_excluded_store(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        // Override stores to include exclusion filter
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
                        'exclude_categories' => [5], // Exclude cat 5
                    ],
                    'https://store2.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test2',
                        'consumer_secret' => 'cs_test2',
                    ],
                ];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        // Return product categories that include excluded cat 5; get_var returns null (not a variation)
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['term_id' => 5, 'taxonomy' => 'product_cat'],
        ]);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        // Only 1 store should get an insert (store2)
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = WC_MSS()->queue_manager->add_product(123, 'product_save');

        // Only store2 (not excluded)
        $this->assertEquals(1, $result);
    }

    public function test_add_product_variation_uses_parent_categories_for_exclusion(): void
    {
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
                        'exclude_categories' => [5], // Exclude cat 5 (parent product's category)
                    ],
                ];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');

        // First get_results call: variation 456 has no categories
        // Second get_results call: parent 100 has category 5
        $wpdb->shouldReceive('get_results')->twice()->andReturn(
            [],  // variation terms (empty)
            [(object) ['term_id' => 5, 'taxonomy' => 'product_cat']] // parent terms
        );

        // get_var returns parent ID 100 — this product IS a variation
        $wpdb->shouldReceive('get_var')->once()->andReturn('100');

        $result = WC_MSS()->queue_manager->add_product(456, 'stock_change');

        // Parent is in excluded category → variation should not be queued
        $this->assertEquals(0, $result);
    }

    // ── add_products (batch) ─────────────────────────────────────

    public function test_add_products_batch_queues_all(): void
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
        $wpdb->shouldReceive('get_var')->andReturn(null); // not a variation
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        // 3 products × 2 stores = 6 inserts
        $wpdb->shouldReceive('insert')->times(6)->andReturn(1);

        $result = WC_MSS()->queue_manager->add_products([10, 20, 30], 'bulk_action');

        $this->assertEquals(6, $result);
    }

    public function test_add_products_with_empty_array_returns_zero(): void
    {
        $this->assertEquals(0, WC_MSS()->queue_manager->add_products([], 'manual'));
    }

    public function test_add_products_with_null_returns_zero(): void
    {
        $this->assertEquals(0, WC_MSS()->queue_manager->add_products(null, 'manual'));
    }

    // ── add_product_deletion ─────────────────────────────────────

    public function test_add_product_deletion_queues_for_all_stores(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        // get_product_queue_data: SKU lookup
        $wpdb->shouldReceive('get_var')->andReturn('TEST-SKU');
        // get_product_terms
        $wpdb->shouldReceive('get_results')->andReturn([]);
        // QueueTable::add - 2 stores
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->times(2)->andReturn(1);

        $result = WC_MSS()->queue_manager->add_product_deletion(100, 'product_delete');

        $this->assertEquals(2, $result);
    }

    public function test_add_product_deletion_returns_zero_when_product_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        // SKU lookup returns null → product not found
        $wpdb->shouldReceive('get_var')->andReturn(null);

        $result = WC_MSS()->queue_manager->add_product_deletion(999, 'product_delete');

        $this->assertEquals(0, $result);
    }

    // ── add_remote_orphan_deletion ───────────────────────────────

    public function test_add_remote_orphan_deletion_queues_successfully(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 42;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = WC_MSS()->queue_manager->add_remote_orphan_deletion([
            'store_url' => 'https://store1.com',
            'remote_product_id' => 555,
            'product_id' => 100,
            'sku' => 'ORPHAN-SKU',
        ]);

        $this->assertTrue($result);
    }

    public function test_add_remote_orphan_deletion_fails_without_store_url(): void
    {
        $result = WC_MSS()->queue_manager->add_remote_orphan_deletion([
            'remote_product_id' => 555,
            'sku' => 'ORPHAN-SKU',
        ]);

        $this->assertFalse($result);
    }

    public function test_add_remote_orphan_deletion_fails_without_sku_or_remote_id(): void
    {
        $result = WC_MSS()->queue_manager->add_remote_orphan_deletion([
            'store_url' => 'https://store1.com',
            'product_id' => 100,
        ]);

        $this->assertFalse($result);
    }

    // ── add_product_restoration ──────────────────────────────────

    public function test_add_product_restoration_queues_correctly(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('RESTORE-SKU');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->times(2)->andReturn(1);

        $result = WC_MSS()->queue_manager->add_product_restoration(100, 'product_restore');

        $this->assertEquals(2, $result);
    }

    // ── add_product_status_change ────────────────────────────────

    public function test_add_product_status_change_queues_correctly(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('STATUS-SKU');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->times(2)->andReturn(1);

        $result = WC_MSS()->queue_manager->add_product_status_change(
            100, 'publish', 'draft', 'status_change'
        );

        $this->assertEquals(2, $result);
    }

    // ── add_variation_deletion ────────────────────────────────────

    public function test_add_variation_deletion_queues_correctly(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        // get variation data
        $wpdb->shouldReceive('get_row')->andReturn(
            (object) ['post_parent' => 50, 'sku' => 'VAR-SKU'],
            // QueueTable::add checks
            null, null
        );
        // get_product_terms for parent
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->times(2)->andReturn(1);

        $result = WC_MSS()->queue_manager->add_variation_deletion(200, 'variation_delete');

        $this->assertEquals(2, $result);
    }

    public function test_add_variation_deletion_returns_zero_when_not_found(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn(null);

        $result = WC_MSS()->queue_manager->add_variation_deletion(999, 'variation_delete');

        $this->assertEquals(0, $result);
    }

    // ── process_queue ────────────────────────────────────────────

    public function test_process_queue_skips_when_already_processing(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(0);
        // GET_LOCK returns 0 = lock already held by another connection
        $wpdb->shouldReceive('get_var')->andReturn('0');

        $result = WC_MSS()->queue_manager->process_queue();

        $this->assertEquals('skipped', $result['status']);
        $this->assertStringContainsString('already running', $result['message']);
    }

    public function test_process_queue_returns_empty_when_no_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(0);
        // GET_LOCK returns 1 = lock acquired
        $wpdb->shouldReceive('get_var')->andReturn('1');
        // get_next_batch returns empty
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $result = WC_MSS()->queue_manager->process_queue();

        $this->assertEquals('empty', $result['status']);
    }

    // ── get_queue_count ──────────────────────────────────────────

    public function test_get_queue_count_returns_pending_count(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_row')->andReturn([
            'pending'    => '5',
            'processing' => '1',
            'completed'  => '10',
            'failed'     => '2',
            'total'      => '18',
        ]);

        $count = WC_MSS()->queue_manager->get_queue_count();

        $this->assertEquals(5, $count);
    }

    // ── get_statistics ───────────────────────────────────────────

    public function test_get_statistics_returns_counts_by_status(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('get_row')->andReturn([
            'pending'    => '3',
            'processing' => '1',
            'completed'  => '20',
            'failed'     => '5',
            'total'      => '29',
        ]);

        $stats = WC_MSS()->queue_manager->get_statistics();

        $this->assertArrayHasKey('pending', $stats);
        $this->assertArrayHasKey('processing', $stats);
        $this->assertArrayHasKey('completed', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertEquals(3, $stats['pending']);
        $this->assertEquals(1, $stats['processing']);
        $this->assertEquals(20, $stats['completed']);
        $this->assertEquals(5, $stats['failed']);
        $this->assertEquals(29, $stats['total']);
    }

    // ── remove_product ───────────────────────────────────────────

    public function test_remove_product_deletes_pending_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->once()->andReturn(3);

        $result = WC_MSS()->queue_manager->remove_product(100);

        $this->assertEquals(3, $result);
    }

    // ── is_queued ────────────────────────────────────────────────

    public function test_is_queued_returns_true_for_pending(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->once()->andReturn('2');

        $this->assertTrue(WC_MSS()->queue_manager->is_queued(100));
    }

    public function test_is_queued_returns_false_when_none_pending(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $this->assertFalse(WC_MSS()->queue_manager->is_queued(999));
    }

    // ── clear_queue ──────────────────────────────────────────────

    public function test_clear_queue_removes_all_items(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('query')->once()->andReturn(15);

        $result = WC_MSS()->queue_manager->clear_queue();

        $this->assertEquals(15, $result);
    }

    // ── cleanup_old_items ────────────────────────────────────────

    public function test_cleanup_old_items_deletes_old_completed(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->once()->andReturn(8);

        $result = WC_MSS()->queue_manager->cleanup_old_items(7);

        $this->assertEquals(8, $result);
    }
}
