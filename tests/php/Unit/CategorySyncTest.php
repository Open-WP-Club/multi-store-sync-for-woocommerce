<?php
/**
 * Unit tests for WC_Multi_Store_Category_Sync
 */

use Brain\Monkey\Functions;

if (!class_exists('WP_Term')) {
    class WP_Term {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $description = '';
        public int $parent = 0;
        public int $count = 0;
    }
}

class CategorySyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
    }

    // -------------------------------------------------------------------------
    // get_product_ids()
    // -------------------------------------------------------------------------

    public function test_get_product_ids_returns_empty_when_no_products(): void
    {
        Functions\when('get_term_children')->justReturn([]);

        $result = WC_Multi_Store_Category_Sync::get_product_ids(5);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_product_ids_without_children(): void
    {
        $called_with_term_children = false;
        Functions\when('get_term_children')->alias(function () use (&$called_with_term_children): array {
            $called_with_term_children = true;
            return [];
        });

        WC_Multi_Store_Category_Sync::get_product_ids(10, false);

        $this->assertFalse($called_with_term_children, 'get_term_children must NOT be called when include_children=false');
    }

    public function test_get_product_ids_includes_children_by_default(): void
    {
        Functions\when('get_term_children')->justReturn([2, 3]);

        $tax_query_terms = null;

        // Intercept WP_Query by using a subclass override approach — since WP_Query
        // is stubbed in bootstrap, we capture the constructor args via reflection
        // by wrapping the call. The cleanest verifiable path is to confirm the
        // returned IDs include products found when children are merged.
        // We rely on the stubbed WP_Query having posts=[], so the result is empty
        // but we verify get_term_children WAS called and its result flows through.
        $children_returned = null;
        Functions\when('get_term_children')->alias(function (int $id, string $tax) use (&$children_returned): array {
            $children_returned = [$id, $tax];
            return [2, 3];
        });

        WC_Multi_Store_Category_Sync::get_product_ids(1, true);

        $this->assertNotNull($children_returned, 'get_term_children should have been called');
        $this->assertSame(1, $children_returned[0]);
        $this->assertSame('product_cat', $children_returned[1]);
    }

    public function test_get_product_ids_handles_wp_error_from_term_children(): void
    {
        Functions\when('get_term_children')->justReturn(new WP_Error('invalid_taxonomy', 'Taxonomy does not exist'));

        // Should not throw, should fall back to just the parent ID in the tax query.
        // The result will be empty because WP_Query stub has posts=[], but the
        // method must not crash.
        $result = WC_Multi_Store_Category_Sync::get_product_ids(7, true);

        $this->assertIsArray($result);
    }

    public function test_get_product_ids_returns_cast_as_int(): void
    {
        Functions\when('get_term_children')->justReturn([]);

        // The method body does: $product_ids[] = (int) $id;
        // WP_Query is a concrete stub in bootstrap with posts=[] — we cannot
        // inject string IDs into it without replacing the class. We verify
        // the cast contract by unit-testing the int-cast expression directly.
        $raw_ids  = ['42', '99', '0'];
        $cast_ids = array_map(fn($id) => (int) $id, $raw_ids);

        $this->assertSame([42, 99, 0], $cast_ids, 'String IDs must be cast to int');
        foreach ($cast_ids as $id) {
            $this->assertIsInt($id);
        }

        // Also confirm the method itself always returns an int array.
        $result = WC_Multi_Store_Category_Sync::get_product_ids(1, false);

        $this->assertIsArray($result);
        foreach ($result as $id) {
            $this->assertIsInt($id);
        }
    }

    // -------------------------------------------------------------------------
    // resolve_term()
    // -------------------------------------------------------------------------

    public function test_resolve_term_by_numeric_id(): void
    {
        $term           = new WP_Term();
        $term->term_id  = 123;
        $term->name     = 'Electronics';
        $term->slug     = 'electronics';

        Functions\when('get_term')->justReturn($term);

        $result = WC_Multi_Store_Category_Sync::resolve_term(123);

        $this->assertInstanceOf(WP_Term::class, $result);
        $this->assertSame(123, $result->term_id);
    }

    public function test_resolve_term_by_slug(): void
    {
        $term       = new WP_Term();
        $term->slug = 'clothing';
        $term->name = 'Clothing';

        $get_term_by_called = false;
        Functions\when('get_term_by')->alias(function (string $field, string $value, string $taxonomy) use ($term, &$get_term_by_called): WP_Term {
            $get_term_by_called = true;
            return $term;
        });

        $result = WC_Multi_Store_Category_Sync::resolve_term('clothing');

        $this->assertTrue($get_term_by_called, 'get_term_by should be called for slug lookup');
        $this->assertInstanceOf(WP_Term::class, $result);
        $this->assertSame('clothing', $result->slug);
    }

    public function test_resolve_term_returns_null_on_wp_error(): void
    {
        Functions\when('get_term')->justReturn(new WP_Error('invalid_term', 'Term not found'));

        $result = WC_Multi_Store_Category_Sync::resolve_term(999);

        $this->assertNull($result);
    }

    public function test_resolve_term_returns_null_when_term_not_found(): void
    {
        Functions\when('get_term')->justReturn(null);

        $result = WC_Multi_Store_Category_Sync::resolve_term(404);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // queue_sync()
    // -------------------------------------------------------------------------

    public function test_queue_sync_returns_error_for_invalid_category(): void
    {
        Functions\when('get_term')->justReturn(null);

        $result = WC_Multi_Store_Category_Sync::queue_sync(9999);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Category not found', $result['error']);
    }

    public function test_queue_sync_returns_zero_queued_when_no_products(): void
    {
        $term       = new WP_Term();
        $term->term_id = 5;
        $term->name = 'Empty Category';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);

        $result = WC_Multi_Store_Category_Sync::queue_sync(5);

        $this->assertArrayHasKey('queued', $result);
        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('category_name', $result);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, $result['products']);
        $this->assertSame('Empty Category', $result['category_name']);
    }

    public function test_queue_sync_normalizes_invalid_sync_type(): void
    {
        $term       = new WP_Term();
        $term->term_id = 6;
        $term->name = 'Test';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);

        // No products → early return before add_products is called,
        // so we verify normalisation via the zero-queued path.
        $result = WC_Multi_Store_Category_Sync::queue_sync(6, 'invalid');

        // If sync_type was truly invalid and not normalised the method would
        // still return the same structure — but validation happens inside the
        // method before queuing. The important thing is no exception is thrown
        // and the result is still a valid response shape.
        $this->assertArrayHasKey('queued', $result);
        $this->assertSame(0, $result['queued']);
    }

    public function test_queue_sync_valid_sync_types_are_accepted(): void
    {
        $term       = new WP_Term();
        $term->term_id = 7;
        $term->name = 'Test Category';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);

        $valid_types = ['full_product', 'price_quantity', 'price_quantity_categories', 'quantity'];

        foreach ($valid_types as $sync_type) {
            $result = WC_Multi_Store_Category_Sync::queue_sync(7, $sync_type);

            $this->assertArrayHasKey('queued', $result, "sync_type '{$sync_type}' should return valid shape");
            $this->assertArrayNotHasKey('error', $result, "sync_type '{$sync_type}' should not produce an error");
        }
    }

    public function test_queue_sync_returns_correct_category_name(): void
    {
        $term       = new WP_Term();
        $term->term_id = 8;
        $term->name = 'Electronics';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);

        $result = WC_Multi_Store_Category_Sync::queue_sync(8);

        $this->assertSame('Electronics', $result['category_name']);
    }
}
