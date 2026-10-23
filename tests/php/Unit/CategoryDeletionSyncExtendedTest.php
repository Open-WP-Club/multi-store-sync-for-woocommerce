<?php
/**
 * Extended unit tests for WC_Multi_Store_Category_Deletion_Sync
 * Covers: delete_products() queuing/batching, uncategorize_products(), tag delete with delete action
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class CategoryDeletionSyncExtendedTest extends WC_Multi_Store_TestCase
{
    /** @var object{lines: list<array{0: string, 1: string}>} */
    private object $logSink;

    private ?WC_Multi_Store_Queue_Manager $originalQueueManager = null;

    /** @var list<int> IDs passed to wp_delete_post(). */
    private array $deletedPosts = [];

    /** @var list<int> IDs passed to wp_set_object_terms(). */
    private array $recategorized = [];

    /** Capture logger output and the destructive WordPress calls. */
    private function captureSideEffects(): void
    {
        $this->logSink = new class {
            /** @var list<array{0: string, 1: string}> */
            public array $lines = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->lines[] = [$level, $message];
            }
        };
        Functions\when('wc_get_logger')->justReturn($this->logSink);
        $this->deletedPosts = [];
        $this->recategorized = [];
        Functions\when('wp_delete_post')->alias(function ($id) {
            $this->deletedPosts[] = $id;
            return true;
        });
        Functions\when('wp_set_object_terms')->alias(function ($id) {
            $this->recategorized[] = $id;
            return [];
        });
    }

    /** @return list<string> */
    private function loggedMessages(): array
    {
        return array_column($this->logSink->lines, 1);
    }

    /** Swap WC_MSS()->queue_manager for a Mockery double; restored in tearDown. */
    private function mockQueueManager(): \Mockery\MockInterface
    {
        $this->originalQueueManager ??= WC_MSS()->queue_manager;
        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        WC_MSS()->queue_manager = $queue;
        return $queue;
    }

    protected function tearDown(): void
    {
        if ($this->originalQueueManager !== null) {
            WC_MSS()->queue_manager = $this->originalQueueManager;
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        WC_Multi_Store_Settings::clear_static_cache();

        // Queueing must succeed for a product to be deleted locally, and uncategorize
        // only reassigns products that have no remaining category.
        $queue = $this->mockQueueManager();
        $queue->shouldReceive('add_product_deletion')->andReturn(1);
        $queue->shouldReceive('add_product')->andReturn(1);
        Functions\when('wp_get_object_terms')->justReturn([]);
    }

    private function mockStoresActive(array $stores = []): void
    {
        // If empty, provide a default active store
        if (empty($stores)) {
            $stores = [
                'https://store1.com' => [
                    'status' => 'active',
                    'consumer_key' => 'ck_test',
                    'consumer_secret' => 'cs_test',
                ],
            ];
        }

        Functions\when('get_option')->alias(function ($option, $default = false) use ($stores) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'category_deletion_action' => 'delete',
                    'auto_sync_deletions' => true,
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return $stores;
            }
            return $default;
        });
    }

    private function mockWpdb(): \Mockery\MockInterface
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';
        // get_product_queue_data: find SKU
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('SKU-001');
        // get_product_terms
        $wpdb->shouldReceive('get_results')->andReturn([]);
        // queue_table: check existing queue item
        $wpdb->shouldReceive('get_row')->andReturn(null);
        // queue_product_operation / insert
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('get_charset_collate')->andReturn('');
        return $wpdb;
    }

    // ─── delete_products() queuing ────────────────

    public function test_category_delete_queues_and_deletes_products(): void
    {
        $this->mockStoresActive();
        $this->mockWpdb();

        Functions\when('get_posts')->justReturn([100, 200, 300]);

        $deleted_posts = [];
        Functions\when('wp_delete_post')->alias(function ($id, $force) use (&$deleted_posts) {
            $deleted_posts[] = $id;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Old Category'];

        $sync->on_category_delete(5, 5, $term, [100, 200, 300]);

        // All 3 products should be locally deleted
        $this->assertCount(3, $deleted_posts);
        $this->assertContains(100, $deleted_posts);
        $this->assertContains(200, $deleted_posts);
        $this->assertContains(300, $deleted_posts);
    }

    public function test_category_delete_auto_sync_disabled_skips_deletion(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'category_deletion_action' => 'delete',
                    'auto_sync_deletions' => false,
                ];
            }
            return $default;
        });

        Functions\when('get_posts')->justReturn([100]);

        $delete_called = false;
        Functions\when('wp_delete_post')->alias(function () use (&$delete_called) {
            $delete_called = true;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Category'];

        $sync->on_category_delete(5, 5, $term, [100]);

        $this->assertFalse($delete_called);
    }

    public function test_category_delete_no_active_stores_skips_deletion(): void
    {
        // No stores configured
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'category_deletion_action' => 'delete',
                    'auto_sync_deletions' => true,
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return []; // No stores
            }
            return $default;
        });

        Functions\when('get_posts')->justReturn([100]);

        $delete_called = false;
        Functions\when('wp_delete_post')->alias(function () use (&$delete_called) {
            $delete_called = true;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Category'];

        $sync->on_category_delete(5, 5, $term, [100]);

        $this->assertFalse($delete_called);
    }

    // ─── uncategorize_products() ──────────────────

    public function test_category_delete_uncategorize_action_reassigns(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'uncategorize',
        ]);

        Functions\when('get_posts')->justReturn([100, 200]);

        // Uncategorized category exists
        $uncat_term = (object) ['term_id' => 99, 'name' => 'Uncategorized'];
        Functions\when('get_term_by')->justReturn($uncat_term);

        $reassigned = [];
        Functions\when('wp_set_object_terms')->alias(function ($post_id, $terms, $taxonomy) use (&$reassigned) {
            $reassigned[] = ['post_id' => $post_id, 'terms' => $terms, 'taxonomy' => $taxonomy];
        });

        // Mock queue manager dependencies for add_product
        $this->mockWpdb();

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Deleted Category'];

        $sync->on_category_delete(5, 5, $term, [100, 200]);

        // Products should be reassigned to Uncategorized
        $this->assertCount(2, $reassigned);
        $this->assertEquals(100, $reassigned[0]['post_id']);
        $this->assertEquals([99], $reassigned[0]['terms']);
        $this->assertEquals('product_cat', $reassigned[0]['taxonomy']);
        $this->assertEquals(200, $reassigned[1]['post_id']);
    }

    public function test_uncategorize_fails_when_category_creation_fails(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'uncategorize',
        ]);

        Functions\when('get_posts')->justReturn([100]);

        // Uncategorized doesn't exist, and creation fails
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(new WP_Error('error', 'DB error'));

        $set_terms_called = false;
        Functions\when('wp_set_object_terms')->alias(function () use (&$set_terms_called) {
            $set_terms_called = true;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Category'];

        $sync->on_category_delete(5, 5, $term, [100]);

        $this->assertFalse($set_terms_called);
    }

    public function test_uncategorize_creates_category_when_missing(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'uncategorize',
        ]);

        Functions\when('get_posts')->justReturn([100]);

        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 77]);

        $reassigned = [];
        Functions\when('wp_set_object_terms')->alias(function ($post_id, $terms, $taxonomy) use (&$reassigned) {
            $reassigned[] = ['post_id' => $post_id, 'terms' => $terms];
        });

        $this->mockWpdb();

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Category'];

        $sync->on_category_delete(5, 5, $term, [100]);

        $this->assertCount(1, $reassigned);
        $this->assertEquals([77], $reassigned[0]['terms']);
    }

    // ─── Tag deletion with delete action ──────────

    public function test_tag_delete_with_delete_action_deletes_products(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'tag_deletion_action' => 'delete',
                    'auto_sync_deletions' => true,
                ];
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

        $this->mockWpdb();

        Functions\when('get_posts')->justReturn([100, 200]);

        $deleted_posts = [];
        Functions\when('wp_delete_post')->alias(function ($id) use (&$deleted_posts) {
            $deleted_posts[] = $id;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Old Tag'];

        $sync->on_tag_delete(10, 10, $term, [100, 200]);

        $this->assertCount(2, $deleted_posts);
        $this->assertContains(100, $deleted_posts);
        $this->assertContains(200, $deleted_posts);
    }

    public function test_tag_delete_does_not_support_uncategorize(): void
    {
        // on_tag_delete only handles 'delete' action, not 'uncategorize'
        Functions\when('get_option')->justReturn([
            'tag_deletion_action' => 'uncategorize',
        ]);

        Functions\when('get_posts')->justReturn([100]);

        $delete_called = false;
        Functions\when('wp_delete_post')->alias(function () use (&$delete_called) {
            $delete_called = true;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Tag'];

        $sync->on_tag_delete(10, 10, $term, [100]);

        $this->assertFalse($delete_called);
    }

    // ─── Batch processing ─────────────────────────

    public function test_delete_products_handles_large_batch(): void
    {
        $this->mockStoresActive();
        $this->mockWpdb();

        $object_ids = range(1, 120);
        Functions\when('get_posts')->justReturn($object_ids);

        $delete_count = 0;
        Functions\when('wp_delete_post')->alias(function () use (&$delete_count) {
            $delete_count++;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Big Category'];

        $sync->on_category_delete(5, 5, $term, $object_ids);

        $this->assertEquals(120, $delete_count);
    }

    public function test_delete_products_single_item(): void
    {
        $this->mockStoresActive();
        $this->mockWpdb();

        Functions\when('get_posts')->justReturn([42]);

        $deleted_posts = [];
        Functions\when('wp_delete_post')->alias(function ($id) use (&$deleted_posts) {
            $deleted_posts[] = $id;
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Small Category'];

        $sync->on_category_delete(5, 5, $term, [42]);

        $this->assertCount(1, $deleted_posts);
        $this->assertEquals(42, $deleted_posts[0]);
    }

    // ─── Edge cases ───────────────────────────────

    public function test_on_category_delete_with_non_object_deleted_term(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'delete',
            'auto_sync_deletions' => false,
        ]);
        Functions\when('get_posts')->justReturn([100]);
        $this->captureSideEffects();
        $this->mockQueueManager()->shouldNotReceive('add_product_deletion');

        $sync = new WC_Multi_Store_Category_Deletion_Sync();

        // deleted_term can be a non-object: its name is reported as Unknown
        $sync->on_category_delete(5, 5, 'not_an_object', [100]);

        $this->assertSame('Category deleted: ID 5, Name: Unknown, Affected products: 1', $this->loggedMessages()[0]);
        $this->assertSame([], $this->deletedPosts);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_category_delete_with_empty_object_ids(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'delete',
        ]);
        $this->captureSideEffects();
        $this->mockQueueManager()->shouldNotReceive('add_product_deletion');
        $lookups = 0;
        Functions\when('get_posts')->alias(function () use (&$lookups) {
            $lookups++;
            return [];
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Empty'];

        $sync->on_category_delete(5, 5, $term, []);

        $this->assertSame(0, $lookups, 'No query is issued for an empty term');
        $this->assertSame([], $this->deletedPosts);
        $this->assertSame([
            'Category deleted: ID 5, Name: Empty, Affected products: 0',
            'No products found in deleted category',
        ], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_tag_delete_with_empty_object_ids(): void
    {
        Functions\when('get_option')->justReturn([
            'tag_deletion_action' => 'delete',
        ]);
        $this->captureSideEffects();
        $this->mockQueueManager()->shouldNotReceive('add_product_deletion');

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Empty Tag'];

        $sync->on_tag_delete(10, 10, $term, []);

        $this->assertSame(['Tag deleted: ID 10, Name: Empty Tag, Affected products: 0', 'No products found with deleted tag'], $this->loggedMessages());
        $this->assertSame([], $this->deletedPosts);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_get_products_by_term_with_null_post(): void
    {
        Functions\when('get_post')->justReturn(null);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, [100, 200, 300]);

        $this->assertEmpty($result);
    }

    public function test_get_products_by_term_mixed_post_types(): void
    {
        // WP filters non-products via post_type => 'product'; simulate that.
        Functions\when('get_posts')->justReturn([100, 300]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, [100, 200, 300]);

        $this->assertCount(2, $result);
        $this->assertContains(100, $result);
        $this->assertContains(300, $result);
    }
}
