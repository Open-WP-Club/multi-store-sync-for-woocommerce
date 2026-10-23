<?php
/**
 * Unit tests for WC_Multi_Store_Category_Deletion_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class CategoryDeletionSyncTest extends WC_Multi_Store_TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCategoryDeletionMocks();
    }

    protected function setUpCategoryDeletionMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir() . '/wc-mss-tests',
            'baseurl' => 'http://example.com/wp-content/uploads',
        ]);
        Functions\when('wp_mkdir_p')->justReturn(true);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        if ($this->originalQueueManager !== null) {
            WC_MSS()->queue_manager = $this->originalQueueManager;
        }
        parent::tearDown();
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Category_Deletion_Sync'));
    }

    public function test_on_category_delete_action_none_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'none',
        ]);
        $this->captureSideEffects();
        $queue = $this->mockQueueManager();
        $queue->shouldNotReceive('add_product_deletion');
        $queue->shouldNotReceive('add_product');
        $lookups = 0;
        Functions\when('get_posts')->alias(function () use (&$lookups) {
            $lookups++;
            return [100];
        });

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Test Category'];

        // Should return early without processing
        $sync->on_category_delete(1, 1, $term, [100, 200]);

        $this->assertSame(0, $lookups, 'Products must not be looked up when the action is none');
        $this->assertSame([], $this->deletedPosts);
        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_category_delete_no_products_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'delete',
        ]);
        Functions\when('get_posts')->justReturn([]);
        $this->captureSideEffects();
        $queue = $this->mockQueueManager();
        $queue->shouldNotReceive('add_product_deletion');
        $queue->shouldNotReceive('add_product');

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Empty Category'];

        $sync->on_category_delete(1, 1, $term, [100]);

        $this->assertSame([], $this->deletedPosts);
        $this->assertSame([
            'Category deleted: ID 1, Name: Empty Category, Affected products: 1',
            'No products found in deleted category',
        ], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_tag_delete_action_none_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'tag_deletion_action' => 'none',
        ]);
        $this->captureSideEffects();
        $queue = $this->mockQueueManager();
        $queue->shouldNotReceive('add_product_deletion');

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Test Tag'];

        $sync->on_tag_delete(1, 1, $term, [100]);

        $this->assertSame([], $this->deletedPosts);
        $this->assertSame([], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_on_tag_delete_no_products_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'tag_deletion_action' => 'delete',
        ]);
        Functions\when('get_posts')->justReturn([]);
        $this->captureSideEffects();
        $queue = $this->mockQueueManager();
        $queue->shouldNotReceive('add_product_deletion');

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Empty Tag'];

        $sync->on_tag_delete(1, 1, $term, [100, 200]);

        $this->assertSame([], $this->deletedPosts);
        $this->assertSame([
            'Tag deleted: ID 1, Name: Empty Tag, Affected products: 2',
            'No products found with deleted tag',
        ], $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_get_products_by_term_empty_input(): void
    {
        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_products_by_term_filters_non_products(): void
    {
        // get_posts with post_type=>'product' already filters non-products — simulate that.
        Functions\when('get_posts')->justReturn([100]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, [100, 200]);

        $this->assertCount(1, $result);
        $this->assertContains(100, $result);
    }

    public function test_get_products_by_term_returns_only_products(): void
    {
        Functions\when('get_posts')->justReturn([100, 200, 300]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, [100, 200, 300]);

        $this->assertCount(3, $result);
    }

    public function test_on_category_delete_with_delete_action_and_auto_sync_disabled(): void
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
        $this->captureSideEffects();
        $queue = $this->mockQueueManager();
        $queue->shouldNotReceive('add_product_deletion');

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Test Category'];

        // auto_sync_deletions is false, so products should NOT be deleted
        $sync->on_category_delete(1, 1, $term, [100]);

        $this->assertSame([], $this->deletedPosts, 'Local products must survive when deletion sync is off');
        $this->assertContains('Auto sync deletions is disabled, skipping product deletion', $this->loggedMessages());
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_get_or_create_uncategorized_existing(): void
    {
        $term = (object) ['term_id' => 42, 'name' => 'Uncategorized'];
        Functions\when('get_term_by')->justReturn($term);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_or_create_uncategorized_category');

        $result = $method->invoke($sync);

        $this->assertEquals(42, $result);
    }

    public function test_get_or_create_uncategorized_creates_new(): void
    {
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 99]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_or_create_uncategorized_category');

        $result = $method->invoke($sync);

        $this->assertEquals(99, $result);
    }

    public function test_get_or_create_uncategorized_insert_error(): void
    {
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(new WP_Error('term_exists', 'Term already exists'));

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_or_create_uncategorized_category');

        $result = $method->invoke($sync);

        $this->assertFalse($result);
    }
}
