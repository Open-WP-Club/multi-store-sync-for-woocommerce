<?php
/**
 * Safety tests for WC_Multi_Store_Category_Deletion_Sync: the destructive
 * "delete products when a category/tag is deleted" and "uncategorize" actions.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class CategoryDeletionSafetyTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $settings = [];
    private array $stores = [];
    /** @var int[] */
    private array $deletedPosts = [];
    /** @var array<int, array> */
    private array $termCalls = [];
    private \Mockery\MockInterface $queue;
    /** @var array<int, mixed> product id => result of wp_get_object_terms() */
    private array $remainingCategories = [];
    /** @var array<int, int[]> chunk sizes seen by get_posts */
    private array $getPostsChunks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = ['category_deletion_action' => 'delete', 'tag_deletion_action' => 'delete', 'auto_sync_deletions' => true];
        $this->stores = ['https://s1.example.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
        $this->deletedPosts = [];
        $this->termCalls = [];
        $this->getPostsChunks = [];
        $this->remainingCategories = [];

        Functions\when('wp_get_object_terms')->alias(fn($id) => $this->remainingCategories[$id] ?? []);
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($o, $d = false) {
            return match ($o) {
                'wc_multi_store_sync_settings' => $this->settings,
                'wc_multi_store_sync_stores' => $this->stores,
                default => $d,
            };
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-03-10 12:00:00');
        Functions\when('get_posts')->alias(function ($args) {
            $this->getPostsChunks[] = $args['post__in'];
            return $args['post__in']; // every id is a product
        });
        Functions\when('wp_delete_post')->alias(function ($id, $force = false) {
            $this->deletedPosts[] = $id;
            return (object) ['ID' => $id];
        });
        Functions\when('wp_set_object_terms')->alias(function ($id, $terms, $tax, $append = false) {
            $this->termCalls[] = [$id, $terms, $tax, $append];
            return [1];
        });

        $this->queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        WC_Multi_Store_Sync::instance()->queue_manager = $this->queue;
        WC_Multi_Store_Settings::clear_static_cache();
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Sync::reset_instance();
        parent::tearDown();
    }

    private function sync(): WC_Multi_Store_Category_Deletion_Sync
    {
        return new WC_Multi_Store_Category_Deletion_Sync();
    }

    private function term(string $name = 'Sale'): object
    {
        return (object) ['name' => $name];
    }

    // ── gating ────────────────────────────────────────────────────

    public static function inertActionProvider(): array
    {
        return [
            'none' => ['none'],
            'unknown string' => ['purge-everything'],
            'empty string' => [''],
            'wrong case' => ['DELETE'],
            'boolean' => [true],
        ];
    }

    #[DataProvider('inertActionProvider')]
    public function test_unrecognised_category_actions_touch_nothing(mixed $action): void
    {
        $this->settings['category_deletion_action'] = $action;
        $this->queue->shouldNotReceive('add_product_deletion');
        $this->queue->shouldNotReceive('add_product');

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2, 3]);

        $this->assertSame([], $this->deletedPosts);
        $this->assertSame([], $this->termCalls);
    }

    public function test_delete_action_without_auto_sync_deletions_never_deletes_locally(): void
    {
        $this->settings['auto_sync_deletions'] = false;
        $this->queue->shouldNotReceive('add_product_deletion');

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2]);

        $this->assertSame([], $this->deletedPosts);
    }

    public function test_delete_action_with_no_active_stores_never_deletes_locally(): void
    {
        $this->stores = ['https://off.example.com' => ['status' => 'inactive', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
        $this->queue->shouldNotReceive('add_product_deletion');

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2]);

        $this->assertSame([], $this->deletedPosts);
    }

    public function test_empty_or_no_product_terms_do_nothing(): void
    {
        $this->queue->shouldNotReceive('add_product_deletion');
        $this->sync()->on_category_delete(5, 5, $this->term(), []);
        $this->sync()->on_tag_delete(5, 5, $this->term(), []);

        Functions\when('get_posts')->justReturn([]);
        $this->sync()->on_category_delete(5, 5, $this->term(), [9, 10]);

        $this->assertSame([], $this->deletedPosts);
    }

    public function test_tag_deletion_supports_only_the_delete_action(): void
    {
        $this->settings['tag_deletion_action'] = 'uncategorize';
        $this->queue->shouldNotReceive('add_product_deletion');
        $this->queue->shouldNotReceive('add_product');

        $this->sync()->on_tag_delete(5, 5, $this->term(), [1]);

        $this->assertSame([], $this->deletedPosts);
        $this->assertSame([], $this->termCalls);
    }

    // ── queued deletion ──────────────────────────────────────────

    public function test_every_product_is_queued_for_all_active_stores_then_deleted_locally(): void
    {
        $order = [];
        $this->queue->shouldReceive('add_product_deletion')->times(3)->andReturnUsing(function ($id, $trigger, $prio, $stores) use (&$order) {
            $order[] = "queue:$id";
            $this->assertSame('category_delete', $trigger);
            $this->assertSame(3, $prio);
            $this->assertSame(['https://s1.example.com'], array_keys($stores));
            return 1;
        });
        Functions\when('wp_delete_post')->alias(function ($id) use (&$order) {
            $order[] = "delete:$id";
            return (object) ['ID' => $id];
        });

        $this->sync()->on_category_delete(5, 5, $this->term(), [11, 12, 13]);

        $this->assertSame(['queue:11', 'delete:11', 'queue:12', 'delete:12', 'queue:13', 'delete:13'], $order);
    }

    public function test_tag_deletion_uses_the_tag_trigger(): void
    {
        $this->queue->shouldReceive('add_product_deletion')->once()->with(7, 'tag_delete', 3, \Mockery::type('array'))->andReturn(1);

        $this->sync()->on_tag_delete(5, 5, $this->term(), [7]);

        $this->assertSame([7], $this->deletedPosts);
    }

    public function test_large_terms_are_fetched_in_chunks_of_one_thousand(): void
    {
        $this->queue->shouldReceive('add_product_deletion')->andReturn(1);

        $this->sync()->on_category_delete(5, 5, $this->term(), range(1, 2500));

        $this->assertSame([1000, 1000, 500], array_map('count', $this->getPostsChunks));
        $this->assertCount(2500, $this->deletedPosts);
    }

    public function test_non_product_posts_in_the_term_are_never_deleted(): void
    {
        Functions\when('get_posts')->alias(fn($args) => array_values(array_intersect($args['post__in'], [2, 4])));
        $this->queue->shouldReceive('add_product_deletion')->twice()->andReturn(1);

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2, 3, 4]);

        $this->assertSame([2, 4], $this->deletedPosts);
    }

    public function test_get_posts_is_asked_for_products_only(): void
    {
        $seen = [];
        Functions\when('get_posts')->alias(function ($args) use (&$seen) {
            $seen[] = $args;
            return [];
        });

        $this->sync()->on_category_delete(5, 5, $this->term(), [1]);

        $this->assertSame('product', $seen[0]['post_type']);
        $this->assertSame('ids', $seen[0]['fields']);
    }

    public function test_deleted_term_that_is_not_an_object_does_not_crash(): void
    {
        $this->queue->shouldReceive('add_product_deletion')->andReturn(1);

        foreach ([null, 'Sale', 7, ['name' => 'x']] as $junk) {
            $this->sync()->on_category_delete(5, 5, $junk, [1]);
        }

        $this->assertCount(4, $this->deletedPosts);
    }

    // ── uncategorize ─────────────────────────────────────────────

    public function test_uncategorize_reuses_the_existing_uncategorized_term(): void
    {
        $this->settings['category_deletion_action'] = 'uncategorize';
        Functions\when('get_term_by')->justReturn((object) ['term_id' => 15]);
        Functions\expect('wp_insert_term')->never();
        $this->queue->shouldReceive('add_product')->twice()->with(\Mockery::type('int'), 'category_change', 3);

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2]);

        $this->assertCount(2, $this->termCalls);
        $this->assertSame([15], $this->termCalls[0][1]);
        $this->assertSame('product_cat', $this->termCalls[0][2]);
        $this->assertSame([], $this->deletedPosts, 'uncategorizing must never delete products');
    }

    public function test_uncategorize_aborts_without_touching_products_when_term_creation_fails(): void
    {
        $this->settings['category_deletion_action'] = 'uncategorize';
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(new \WP_Error('term_exists', 'exists'));
        $this->queue->shouldNotReceive('add_product');

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2]);

        $this->assertSame([], $this->termCalls);
        $this->assertSame([], $this->deletedPosts);
    }

    public function test_uncategorize_creates_the_missing_term_once_for_the_whole_batch(): void
    {
        $this->settings['category_deletion_action'] = 'uncategorize';
        Functions\when('get_term_by')->justReturn(false);
        Functions\expect('wp_insert_term')->once()->andReturn(['term_id' => 77, 'term_taxonomy_id' => 77]);
        $this->queue->shouldReceive('add_product');

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2, 3]);

        $this->assertCount(3, $this->termCalls);
        foreach ($this->termCalls as $call) {
            $this->assertSame([77], $call[1]);
        }
    }

    // ── regressions: never lose data ──────────────────────────────

    public function test_product_is_kept_when_its_remote_deletion_could_not_be_queued(): void
    {
        $this->queue->shouldReceive('add_product_deletion')->andReturnUsing(fn($id) => $id === 2 ? 0 : 1);

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2, 3]);

        $this->assertSame([1, 3], $this->deletedPosts, 'product 2 failed to queue and must survive');
    }

    public function test_no_product_is_deleted_when_every_queue_attempt_fails(): void
    {
        $this->queue->shouldReceive('add_product_deletion')->andReturn(0);

        $this->sync()->on_tag_delete(5, 5, $this->term(), [1, 2]);

        $this->assertSame([], $this->deletedPosts);
    }

    public function test_uncategorize_keeps_products_that_still_belong_to_another_category(): void
    {
        $this->settings['category_deletion_action'] = 'uncategorize';
        Functions\when('get_term_by')->justReturn((object) ['term_id' => 15]);
        $this->remainingCategories = [1 => [22, 23], 2 => [], 3 => new \WP_Error('invalid_taxonomy', 'x')];
        $this->queue->shouldReceive('add_product');

        $this->sync()->on_category_delete(5, 5, $this->term(), [1, 2, 3]);

        $assigned = array_column($this->termCalls, 0);
        $this->assertSame([2, 3], $assigned, 'only products left with no category are moved to Uncategorized');
        $this->assertNotContains(1, $assigned);
    }
}
