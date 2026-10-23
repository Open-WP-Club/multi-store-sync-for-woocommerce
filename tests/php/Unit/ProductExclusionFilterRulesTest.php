<?php
/**
 * Rule-evaluation edge cases for WC_Multi_Store_Product_Exclusion_Filter:
 * category hierarchy, type juggling in config values, tag/category isolation,
 * the single prepared term query, and reason reporting through ancestors.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class ProductExclusionFilterRulesTest extends WC_Multi_Store_TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wc_mss_test_get_ancestors']);
        parent::tearDown();
    }

    /**
     * @param array<int, int[]> $ancestors child id => ancestor ids (nearest first)
     */
    private function ancestors(array $ancestors): void
    {
        $GLOBALS['wc_mss_test_get_ancestors'] = $ancestors;
    }

    private function mockTermQuery(array $categories, array $tags, ?array &$prepared = null): \Mockery\MockInterface
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix             = 'wp_';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy      = 'wp_term_taxonomy';

        $rows = [];
        foreach ($categories as $id) {
            $rows[] = (object) ['term_id' => (string) $id, 'taxonomy' => 'product_cat'];
        }
        foreach ($tags as $id) {
            $rows[] = (object) ['term_id' => (string) $id, 'taxonomy' => 'product_tag'];
        }

        $prepared = [];
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$prepared) {
            $prepared[] = [$sql, $args];
            return $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn($rows);

        return $wpdb;
    }

    private function product(int $id = 77): \Mockery\MockInterface
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn($id);
        return $product;
    }

    // ── hierarchy ─────────────────────────────────────────────────

    public function test_excluding_a_grandparent_category_excludes_products_in_the_grandchild(): void
    {
        $this->ancestors([30 => [20, 10]]);

        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([30], [], ['exclude_categories' => [10]]));
        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([30], [], ['exclude_categories' => [20]]));
    }

    public function test_excluding_a_child_does_not_exclude_products_only_in_the_parent(): void
    {
        $this->ancestors([30 => [20, 10]]);

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([10], [], ['exclude_categories' => [30]]));
        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([20], [], ['exclude_categories' => [30]]));
    }

    public function test_sibling_category_is_not_excluded_by_shared_parent_of_other_branch(): void
    {
        $this->ancestors([31 => [10], 32 => [10]]);

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([31], [], ['exclude_categories' => [32]]));
    }

    public function test_ancestors_of_one_category_do_not_leak_into_another_products_decision(): void
    {
        $this->ancestors([30 => [10]]);

        // Product is in 40 (no ancestors) only. Exclusion of 10 must not apply.
        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([40], [], ['exclude_categories' => [10]]));
    }

    public function test_tag_ids_are_never_expanded_through_category_ancestors(): void
    {
        // Term 55 happens to have category ancestors registered; a tag id 55 must not inherit them.
        $this->ancestors([55 => [10]]);

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([], [55], ['exclude_categories' => [10]]));
    }

    public function test_category_and_tag_id_spaces_do_not_cross_match(): void
    {
        // Same numeric id in a different taxonomy must not trigger an exclusion.
        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([7], [], ['exclude_tags' => [7]]));
        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([], [7], ['exclude_categories' => [7]]));
    }

    // ── config value types ────────────────────────────────────────

    public static function numericIdProvider(): array
    {
        return [
            'int config, int ids' => [[20], [20]],
            'string config, int ids' => [['20'], [20]],
            'int config, string ids' => [[20], ['20']],
            'string config, string ids' => [['20'], ['20']],
        ];
    }

    #[DataProvider('numericIdProvider')]
    public function test_numeric_strings_and_ints_match_each_other(array $configured, array $productIds): void
    {
        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids($productIds, [], ['exclude_categories' => $configured]));
        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([], $productIds, ['exclude_tags' => $configured]));
    }

    public static function garbageIdProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'false' => [false],
            'word' => ['abc'],
            'numeric-prefixed word' => ['20abc'],
            'float that is not an id' => [20.5],
            'nested array' => [[20]],
        ];
    }

    #[DataProvider('garbageIdProvider')]
    public function test_garbage_entries_in_exclusion_lists_never_match_real_term_ids(mixed $garbage): void
    {
        $config = ['exclude_categories' => [$garbage], 'exclude_tags' => [$garbage]];

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([20, 5], [20, 5], $config));
    }

    public function test_garbage_entries_do_not_mask_valid_entries(): void
    {
        $config = ['exclude_categories' => [null, '', 'abc', 20]];

        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([20], [], $config));
    }

    public function test_product_with_no_terms_is_never_excluded_by_zero_or_empty_config_entries(): void
    {
        $config = ['exclude_categories' => [0, '0', ''], 'exclude_tags' => [0, '0', '']];

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([], [], $config));
    }

    public function test_unrelated_config_keys_are_ignored(): void
    {
        $config = ['exclude_category' => [20], 'exclude_tag' => [20], 'categories' => [20]];

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([20], [20], $config));
    }

    public function test_very_large_exclusion_and_term_lists_stay_correct(): void
    {
        $excluded = range(1000, 6000);
        $product = range(7000, 7500);

        $config = ['exclude_categories' => $excluded, 'exclude_tags' => $excluded];
        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids($product, $product, $config));

        $product[] = 6000; // last excluded id
        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids($product, [], $config));
    }

    // ── should_exclude() / term query ─────────────────────────────

    public function test_should_exclude_skips_the_term_query_when_nothing_is_configured(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->shouldNotReceive('prepare');
        $wpdb->shouldNotReceive('get_results');

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude($this->product(), []));
        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude($this->product(), ['exclude_categories' => [], 'exclude_tags' => []]));
        $this->assertSame([], WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($this->product(), ['exclude_categories' => [], 'exclude_tags' => []]));
    }

    public function test_should_exclude_queries_terms_once_with_the_product_id_bound(): void
    {
        $prepared = [];
        $this->mockTermQuery([10], [5], $prepared);

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude(
            $this->product(4242),
            ['exclude_categories' => [999], 'exclude_tags' => [5]]
        );

        $this->assertTrue($result);
        $this->assertCount(1, $prepared, 'one combined query, not one per taxonomy');
        $this->assertSame([4242], $prepared[0][1]);
        $this->assertStringContainsString('%d', $prepared[0][0]);
        $this->assertStringContainsString("'product_cat', 'product_tag'", $prepared[0][0]);
    }

    public function test_should_exclude_resolves_ancestors_of_the_products_categories(): void
    {
        $this->ancestors([30 => [20, 10]]);
        $this->mockTermQuery([30], []);

        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude($this->product(), ['exclude_categories' => [10]]));
    }

    public function test_term_ids_returned_as_strings_by_the_database_are_cast_before_ancestor_lookup(): void
    {
        // $wpdb returns numeric strings; get_ancestors() has an int signature and
        // the fixture is keyed by int, so a missed cast would drop the ancestors.
        $this->ancestors([30 => [10]]);
        $this->mockTermQuery([30], []);

        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude($this->product(), ['exclude_categories' => [10]]));
    }

    public function test_product_with_no_matching_terms_is_not_excluded(): void
    {
        $this->mockTermQuery([1, 2], [3]);

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude(
            $this->product(),
            ['exclude_categories' => [10], 'exclude_tags' => [20]]
        ));
    }

    // ── get_exclusion_reasons() ───────────────────────────────────

    public function test_reason_names_the_excluded_ancestor_not_the_products_own_category(): void
    {
        $this->ancestors([30 => [10]]);
        $this->mockTermQuery([30], []);
        Functions\when('get_term')->alias(fn($id, $tax) => (object) ['name' => 'term-' . $id . '-' . $tax]);

        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($this->product(), ['exclude_categories' => [10]]);

        $this->assertSame(['Category: term-10-product_cat'], $reasons);
    }

    public function test_reasons_are_empty_when_product_has_no_terms(): void
    {
        $this->mockTermQuery([], []);
        Functions\expect('get_term')->never();

        $this->assertSame([], WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons(
            $this->product(),
            ['exclude_categories' => [10], 'exclude_tags' => [5]]
        ));
    }

    public function test_reasons_list_category_before_tag_and_only_matching_entries(): void
    {
        $this->mockTermQuery([10, 11], [5, 6]);
        Functions\when('get_term')->alias(fn($id, $tax) => (object) ['name' => "$tax#$id"]);

        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons(
            $this->product(),
            ['exclude_categories' => [11, 99], 'exclude_tags' => [6, 98]]
        );

        $this->assertSame(['Category: product_cat#11', 'Tag: product_tag#6'], $reasons);
    }

    public function test_exclusion_decision_and_reasons_agree_for_a_resolvable_term(): void
    {
        $this->ancestors([30 => [10]]);
        $this->mockTermQuery([30], [5]);
        Functions\when('get_term')->alias(fn($id, $tax) => (object) ['name' => "$tax#$id"]);

        $config = ['exclude_categories' => [10], 'exclude_tags' => [5]];

        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude($this->product(), $config));
        $this->assertCount(2, WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons($this->product(), $config));
    }

    // ── regressions: malformed exclusion lists never exclude everything or warn ──

    public static function malformedListProvider(): array
    {
        return [
            'bare string id' => ['20'],
            'bare int id' => [20],
            'boolean true' => [true],
            'text' => ['everything'],
            'list holding true' => [[true]],
            'list holding text' => [['all', 'x']],
            'list holding nested array' => [[[20]]],
            'negative ids' => [[-20, -1]],
            'zero' => [[0, '0']],
        ];
    }

    #[DataProvider('malformedListProvider')]
    public function test_malformed_lists_neither_warn_nor_exclude_unrelated_products(mixed $list): void
    {
        $config = ['exclude_categories' => $list, 'exclude_tags' => $list];

        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([5], [7], $config));
    }

    public function test_a_valid_id_in_a_noisy_list_still_excludes(): void
    {
        $config = ['exclude_categories' => [true, 'x', [1], 5, null]];

        $this->assertTrue(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([5], [], $config));
        $this->assertFalse(WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids([6], [], $config));
    }

    public function test_reasons_never_report_a_term_for_a_malformed_entry(): void
    {
        $this->mockTermQuery([5], [7]);
        Functions\when('get_term')->alias(fn($id, $tax) => (object) ['name' => "$tax#$id"]);

        $reasons = WC_Multi_Store_Product_Exclusion_Filter::get_exclusion_reasons(
            $this->product(),
            ['exclude_categories' => [true, 'x', 5], 'exclude_tags' => 'all']
        );

        $this->assertSame(['Category: product_cat#5'], $reasons);
    }
}
