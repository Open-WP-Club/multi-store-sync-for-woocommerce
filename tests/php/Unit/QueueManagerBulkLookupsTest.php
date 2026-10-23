<?php
/**
 * Tests for the private bulk-lookup helpers of WC_Multi_Store_Queue_Manager
 * (get_variation_parents_bulk, get_product_terms_bulk) and for
 * queue_product_to_stores (per-store exclusion + enqueue).
 */

use Brain\Monkey\Functions;

class QueueManagerBulkLookupsTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Queue_Manager $manager;

    /** @var list<array{string, array}> */
    private array $prepared = [];

    /** @var list<array> */
    private array $inserts = [];

    /** @var list<int|string> */
    private array $lockResults = [];

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
        $this->prepared = [];
        $this->inserts = [];

        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));

        $this->manager = new WC_Multi_Store_Queue_Manager();
    }

    private function db(array $rows = [], bool $withQueue = false): \Mockery\MockInterface
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            $this->prepared[] = [$query, $args];
            return $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn($rows)->byDefault();
        if ($withQueue) {
            $wpdb->shouldReceive('get_var')->andReturn(1);
            $wpdb->shouldReceive('get_row')->andReturn(null);
            $wpdb->shouldReceive('insert')->andReturnUsing(function ($table, $data) {
                $this->inserts[] = $data;
                return 1;
            });
        }
        return $wpdb;
    }

    private function invoke(string $method, array $args): mixed
    {
        return (new ReflectionMethod($this->manager, $method))->invokeArgs($this->manager, $args);
    }

    private function row(array $props): object
    {
        return (object) $props;
    }

    // ---- get_variation_parents_bulk --------------------------------------

    public function test_variation_parents_empty_input_runs_no_query(): void
    {
        $wpdb = $this->db();
        $wpdb->shouldNotReceive('get_results');

        $this->assertSame([], $this->invoke('get_variation_parents_bulk', [[]]));
        $this->assertSame([], $this->prepared);
    }

    public function test_variation_parents_maps_variation_to_parent_with_single_query(): void
    {
        $this->db([
            $this->row(['ID' => '101', 'post_parent' => '10']),
            $this->row(['ID' => '202', 'post_parent' => '20']),
        ]);

        $result = $this->invoke('get_variation_parents_bulk', [[101, 5, 202]]);

        $this->assertSame([101 => 10, 202 => 20], $result, 'Non-variation ids are absent from the map');
        $this->assertCount(1, $this->prepared, 'Exactly one query regardless of how many ids');
        [$query, $args] = $this->prepared[0];
        $this->assertStringContainsString('IN (%d,%d,%d)', $query);
        $this->assertStringContainsString("post_type = 'product_variation'", $query);
        $this->assertSame([101, 5, 202], $args);
    }

    public function test_variation_parents_placeholder_count_matches_ids(): void
    {
        $this->db([]);

        $this->assertSame([], $this->invoke('get_variation_parents_bulk', [[7]]));

        $this->assertStringContainsString('IN (%d)', $this->prepared[0][0]);
        $this->assertStringNotContainsString('%d,%d', $this->prepared[0][0]);
    }

    public function test_variation_parents_tolerates_non_array_result(): void
    {
        global $wpdb;
        $this->db();
        $wpdb->shouldReceive('get_results')->andReturn(null);

        $this->assertSame([], $this->invoke('get_variation_parents_bulk', [[1, 2]]));
    }

    // ---- get_product_terms_bulk ------------------------------------------

    public function test_terms_bulk_empty_input_returns_empty_map_without_query(): void
    {
        $wpdb = $this->db();
        $wpdb->shouldNotReceive('get_results');

        $this->assertSame([], $this->invoke('get_product_terms_bulk', [[]]));
    }

    public function test_terms_bulk_groups_categories_and_tags_per_product(): void
    {
        $this->db([
            $this->row(['object_id' => '1', 'term_id' => '11', 'taxonomy' => 'product_cat']),
            $this->row(['object_id' => '1', 'term_id' => '12', 'taxonomy' => 'product_cat']),
            $this->row(['object_id' => '1', 'term_id' => '90', 'taxonomy' => 'product_tag']),
            $this->row(['object_id' => '2', 'term_id' => '91', 'taxonomy' => 'product_tag']),
        ]);

        $result = $this->invoke('get_product_terms_bulk', [[1, 2, 3]]);

        $this->assertSame([
            1 => ['categories' => [11, 12], 'tags' => [90]],
            2 => ['categories' => [], 'tags' => [91]],
            3 => ['categories' => [], 'tags' => []],
        ], $result);
    }

    public function test_terms_bulk_uses_one_query_with_all_ids_and_both_taxonomies(): void
    {
        $this->db([]);

        $this->invoke('get_product_terms_bulk', [[4, 5]]);

        $this->assertCount(1, $this->prepared);
        [$query, $args] = $this->prepared[0];
        $this->assertStringContainsString('tr.object_id IN (%d,%d)', $query);
        $this->assertStringContainsString("'product_cat', 'product_tag'", $query);
        $this->assertSame([4, 5], $args);
    }

    public function test_terms_bulk_adds_entry_for_unrequested_object_in_result(): void
    {
        $this->db([$this->row(['object_id' => '99', 'term_id' => '5', 'taxonomy' => 'product_cat'])]);

        $result = $this->invoke('get_product_terms_bulk', [[1]]);

        $this->assertSame(['categories' => [], 'tags' => []], $result[1]);
        $this->assertSame(['categories' => [5], 'tags' => []], $result[99]);
    }

    public function test_terms_bulk_casts_term_ids_to_int(): void
    {
        $this->db([$this->row(['object_id' => '1', 'term_id' => '007', 'taxonomy' => 'product_cat'])]);

        $result = $this->invoke('get_product_terms_bulk', [[1]]);

        $this->assertSame([7], $result[1]['categories']);
    }

    // ---- queue_product_to_stores -----------------------------------------

    public function test_queue_product_to_stores_enqueues_one_row_per_store_with_arguments(): void
    {
        $this->db([], true);
        $stores = ['https://a.com' => ['status' => 'active'], 'https://b.com' => ['status' => 'active']];

        $added = $this->invoke('queue_product_to_stores', [42, [1], [2], 'price_quantity', 3, 'stock_change', $stores]);

        $this->assertSame(2, $added);
        $this->assertCount(2, $this->inserts);
        $this->assertSame(['https://a.com', 'https://b.com'], array_column($this->inserts, 'store_url'));
        foreach ($this->inserts as $row) {
            $this->assertSame(42, $row['product_id']);
            $this->assertSame('price_quantity', $row['sync_type']);
            $this->assertSame(3, $row['priority']);
            $this->assertSame('stock_change', $row['source']);
            $this->assertSame('pending', $row['status']);
        }
    }

    public function test_queue_product_to_stores_skips_stores_that_exclude_category_or_tag(): void
    {
        $this->db([], true);
        $stores = [
            'https://cat-excl.com' => ['exclude_categories' => [5]],
            'https://tag-excl.com' => ['exclude_tags' => [9]],
            'https://ok.com' => ['exclude_categories' => [77], 'exclude_tags' => [88]],
            'https://open.com' => [],
        ];

        $added = $this->invoke('queue_product_to_stores', [1, [5], [9], 'full_product', 5, 'manual', $stores]);

        $this->assertSame(2, $added);
        $this->assertSame(['https://ok.com', 'https://open.com'], array_column($this->inserts, 'store_url'));
    }

    public function test_queue_product_to_stores_returns_zero_when_all_excluded(): void
    {
        $this->db([], true);
        $stores = ['https://a.com' => ['exclude_tags' => [3]]];

        $added = $this->invoke('queue_product_to_stores', [1, [], [3], 'full_product', 5, 'manual', $stores]);

        $this->assertSame(0, $added);
        $this->assertSame([], $this->inserts);
    }

    public function test_queue_product_to_stores_does_not_count_failed_enqueues(): void
    {
        global $wpdb;
        $this->db([], false);
        // Lock never acquired -> Queue_Table::add returns false for every store.
        $wpdb->shouldReceive('get_var')->andReturn(0);
        $wpdb->shouldNotReceive('insert');

        $added = $this->invoke('queue_product_to_stores', [1, [], [], 'full_product', 5, 'manual', ['https://a.com' => []]]);

        $this->assertSame(0, $added);
    }

    public function test_queue_product_to_stores_with_no_stores_returns_zero(): void
    {
        $this->db([], true);

        $this->assertSame(0, $this->invoke('queue_product_to_stores', [1, [], [], 'full_product', 5, 'manual', []]));
    }
}
