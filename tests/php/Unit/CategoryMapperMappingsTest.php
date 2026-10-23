<?php
/**
 * Tests for WC_Multi_Store_Category_Mapper internals: apply_term_mappings,
 * get_mappings_for_key / set_mappings_for_key and get_local_categories.
 */

use Brain\Monkey\Functions;

if (!class_exists('WP_Term')) {
    // Kept in sync with the other guarded `WP_Term` stubs in this suite
    // (CategoryMapperTest.php, ShippingClassSyncTest.php, ...).
    class WP_Term {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $taxonomy = 'product_cat';
        public string $description = '';
        public int $parent = 0;
        public int $count = 0;
    }
}

class CategoryMapperMappingsTest extends WC_Multi_Store_TestCase
{
    private const STORE_A = 'https://store-a.example.com';
    private const STORE_B = 'https://store-b.example.com';

    /** @var string[] messages sent to the WooCommerce logger */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = [];
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);

        $sink = new class ($this->logged) {
            public function __construct(public array &$messages) {}
            public function log($level, $message, $context = []): void { $this->messages[] = $message; }
        };
        Functions\when('wc_get_logger')->justReturn($sink);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Category_Mapper::class, $method);
        return $ref->invoke(null, ...$args);
    }

    private function term(int $id, string $name, string $slug, int $parent = 0, int $count = 0): WP_Term
    {
        $t = new WP_Term();
        $t->term_id = $id;
        $t->name = $name;
        $t->slug = $slug;
        $t->parent = $parent;
        $t->count = $count;
        return $t;
    }

    // ─── apply_term_mappings() ──────────────────────────────────────────────

    public function test_apply_term_mappings_with_no_terms_returns_empty(): void
    {
        $this->assertSame([], $this->call('apply_term_mappings', [], ['a' => 'b']));
    }

    public function test_apply_term_mappings_unmapped_term_keeps_all_original_keys(): void
    {
        $term = ['id' => 7, 'slug' => 'keep', 'name' => 'Keep', 'extra' => 'x'];

        $this->assertSame([$term], $this->call('apply_term_mappings', [$term], ['other' => 'z']));
    }

    public function test_apply_term_mappings_mapped_term_is_reduced_to_slug_and_name(): void
    {
        $result = $this->call(
            'apply_term_mappings',
            [['id' => 7, 'slug' => 'shirts', 'name' => 'Shirts', 'parent' => 3]],
            ['shirts' => 'tops']
        );

        $this->assertSame([['slug' => 'tops', 'name' => 'Shirts']], $result);
    }

    public function test_apply_term_mappings_mapped_term_without_name_gets_empty_name(): void
    {
        $result = $this->call('apply_term_mappings', [['slug' => 'shirts']], ['shirts' => 'tops']);

        $this->assertSame([['slug' => 'tops', 'name' => '']], $result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skipValues')]
    public function test_apply_term_mappings_drops_terms_mapped_to_skip(string $skip): void
    {
        $result = $this->call(
            'apply_term_mappings',
            [['slug' => 'a', 'name' => 'A'], ['slug' => 'b', 'name' => 'B']],
            ['a' => $skip, 'b' => 'bee']
        );

        $this->assertSame([['slug' => 'bee', 'name' => 'B']], $result);
    }

    public static function skipValues(): array
    {
        return [
            'empty string' => [''],
            'sentinel' => ['__skip__'],
        ];
    }

    public function test_apply_term_mappings_preserves_order_and_mixes_outcomes(): void
    {
        $terms = [
            ['slug' => 'one', 'name' => 'One'],
            ['slug' => 'two', 'name' => 'Two'],
            ['slug' => 'three', 'name' => 'Three'],
            ['slug' => 'four', 'name' => 'Four'],
        ];

        $result = $this->call('apply_term_mappings', $terms, ['one' => 'uno', 'two' => '__skip__', 'four' => 'cuatro']);

        $this->assertSame([
            ['slug' => 'uno', 'name' => 'One'],
            ['slug' => 'three', 'name' => 'Three'],
            ['slug' => 'cuatro', 'name' => 'Four'],
        ], $result);
    }

    public function test_apply_term_mappings_slug_matching_is_case_sensitive(): void
    {
        $term = ['slug' => 'Shirts', 'name' => 'Shirts'];

        $this->assertSame([$term], $this->call('apply_term_mappings', [$term], ['shirts' => 'tops']));
    }

    public function test_apply_term_mappings_null_mapping_value_is_treated_as_unmapped(): void
    {
        $term = ['slug' => 'a', 'name' => 'A'];

        $this->assertSame([$term], $this->call('apply_term_mappings', [$term], ['a' => null]));
    }

    public function test_apply_term_mappings_term_without_slug_passes_through(): void
    {
        $term = ['name' => 'No slug'];

        $this->assertSame([$term], $this->call('apply_term_mappings', [$term], ['a' => 'b']));
    }

    public function test_apply_term_mappings_logs_only_when_store_url_given(): void
    {
        $terms = [['slug' => 'a', 'name' => 'A'], ['slug' => 'b', 'name' => 'B']];
        $mappings = ['a' => 'alpha', 'b' => '__skip__'];

        $this->call('apply_term_mappings', $terms, $mappings);
        $this->assertSame([], $this->logged, 'No store URL means no logging (tag path)');

        $this->call('apply_term_mappings', $terms, $mappings, self::STORE_A);
        $this->assertSame([
            'Category "a" mapped to "alpha" for store ' . self::STORE_A,
            'Category "b" skipped for store ' . self::STORE_A . ' (mapped to skip)',
        ], $this->logged);
    }

    // ─── get_mappings_for_key() / set_mappings_for_key() ────────────────────

    public function test_get_mappings_for_key_reads_requested_option_and_store_hash(): void
    {
        $args = null;
        Functions\when('get_option')->alias(function ($key, $default = false) use (&$args) {
            $args = [$key, $default];
            return [md5(self::STORE_A) => ['a' => 'b'], md5(self::STORE_B) => ['c' => 'd']];
        });

        $this->assertSame(['c' => 'd'], $this->call('get_mappings_for_key', 'custom_key', self::STORE_B));
        $this->assertSame(['custom_key', []], $args);
    }

    public function test_get_mappings_for_key_returns_empty_for_unknown_store(): void
    {
        Functions\when('get_option')->justReturn([md5(self::STORE_A) => ['a' => 'b']]);

        $this->assertSame([], $this->call('get_mappings_for_key', 'k', self::STORE_B));
    }

    public function test_get_mappings_for_key_tolerates_option_stored_as_false(): void
    {
        Functions\when('get_option')->justReturn(false);

        $this->assertSame([], $this->call('get_mappings_for_key', 'k', self::STORE_A));
    }

    public function test_store_url_hash_is_exact_so_trailing_slash_is_a_different_store(): void
    {
        Functions\when('get_option')->justReturn([md5(self::STORE_A) => ['a' => 'b']]);

        $this->assertSame([], $this->call('get_mappings_for_key', 'k', self::STORE_A . '/'));
    }

    public function test_set_mappings_for_key_adds_store_and_preserves_others(): void
    {
        Functions\when('get_option')->justReturn([md5(self::STORE_A) => ['a' => 'b']]);
        $saved = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved) {
            $saved = [$key, $value];
            return true;
        });

        $this->call('set_mappings_for_key', 'my_key', self::STORE_B, ['x' => 'y']);

        $this->assertSame(
            ['my_key', [md5(self::STORE_A) => ['a' => 'b'], md5(self::STORE_B) => ['x' => 'y']]],
            $saved
        );
    }

    public function test_set_mappings_for_key_replaces_rather_than_merges_existing_store_mappings(): void
    {
        Functions\when('get_option')->justReturn([md5(self::STORE_A) => ['old' => 'one', 'keep' => 'two']]);
        $saved = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved) {
            $saved = [$key, $value];
            return true;
        });

        $this->call('set_mappings_for_key', 'my_key', self::STORE_A, ['new' => 'three']);

        $this->assertSame(['my_key', [md5(self::STORE_A) => ['new' => 'three']]], $saved);
    }

    public function test_set_mappings_for_key_with_empty_mappings_clears_store_but_keeps_entry(): void
    {
        Functions\when('get_option')->justReturn([md5(self::STORE_A) => ['a' => 'b']]);
        $saved = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved) {
            $saved = $value;
            return true;
        });

        $this->call('set_mappings_for_key', 'k', self::STORE_A, []);

        $this->assertSame([md5(self::STORE_A) => []], $saved);
    }

    public function test_category_and_tag_mappings_use_separate_option_keys(): void
    {
        $writes = [];
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[] = $key;
            return true;
        });

        WC_Multi_Store_Category_Mapper::set_mappings(self::STORE_A, ['a' => 'b']);
        WC_Multi_Store_Category_Mapper::set_tag_mappings(self::STORE_A, ['a' => 'b']);

        $this->assertSame(['wc_mss_category_mappings', 'wc_mss_tag_mappings'], $writes);
    }

    // ─── get_local_categories() ─────────────────────────────────────────────

    public function test_get_local_categories_queries_product_cat_sorted_by_name_including_empty(): void
    {
        Functions\expect('get_terms')
            ->once()
            ->with(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'])
            ->andReturn([]);

        $this->assertSame([], WC_Multi_Store_Category_Mapper::get_local_categories());
    }

    public function test_get_local_categories_maps_every_field_including_parent_and_count(): void
    {
        Functions\when('get_terms')->justReturn([
            $this->term(3, 'Shirts', 'shirts', 0, 12),
            $this->term(4, 'Polo', 'polo', 3, 0),
        ]);

        $this->assertSame([
            ['id' => 3, 'name' => 'Shirts', 'slug' => 'shirts', 'parent' => 0, 'count' => 12],
            ['id' => 4, 'name' => 'Polo', 'slug' => 'polo', 'parent' => 3, 'count' => 0],
        ], WC_Multi_Store_Category_Mapper::get_local_categories());
    }

    public function test_get_local_categories_returns_empty_on_wp_error(): void
    {
        Functions\when('get_terms')->justReturn(new WP_Error('invalid_taxonomy', 'Invalid'));

        $this->assertSame([], WC_Multi_Store_Category_Mapper::get_local_categories());
    }
}
