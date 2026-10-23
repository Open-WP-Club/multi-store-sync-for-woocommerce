<?php
/**
 * Registry of products blocked by a duplicate SKU on a remote store.
 */

use Brain\Monkey\Functions;

class SkuConflictsTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];

        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
    }

    public function test_record_stores_the_conflict_once_per_product_and_store(): void
    {
        WC_Multi_Store_Sku_Conflicts::record(7, 'https://a.com', 'SKU-1', 100, 0);
        WC_Multi_Store_Sku_Conflicts::record(7, 'https://a.com/', 'SKU-1', 100, 4242);

        $all = array_values(WC_Multi_Store_Sku_Conflicts::get_all());

        $this->assertSame(1, WC_Multi_Store_Sku_Conflicts::count());
        $this->assertSame(4242, $all[0]['owner_remote_id']);
        $this->assertSame('SKU-1', $all[0]['sku']);
    }

    public function test_same_product_on_different_stores_is_tracked_separately(): void
    {
        WC_Multi_Store_Sku_Conflicts::record(7, 'https://a.com', 'SKU-1', 100, 0);
        WC_Multi_Store_Sku_Conflicts::record(7, 'https://b.com', 'SKU-1', 200, 0);

        $this->assertSame(2, WC_Multi_Store_Sku_Conflicts::count());
    }

    public function test_clear_removes_only_that_product_and_store(): void
    {
        WC_Multi_Store_Sku_Conflicts::record(7, 'https://a.com', 'SKU-1', 100, 0);
        WC_Multi_Store_Sku_Conflicts::record(7, 'https://b.com', 'SKU-1', 200, 0);

        WC_Multi_Store_Sku_Conflicts::clear(7, 'https://a.com/');

        $remaining = array_values(WC_Multi_Store_Sku_Conflicts::get_all());
        $this->assertCount(1, $remaining);
        $this->assertSame('https://b.com', $remaining[0]['store_url']);
    }

    public function test_clear_without_a_conflict_does_not_write(): void
    {
        WC_Multi_Store_Sku_Conflicts::clear(7, 'https://a.com');

        $this->assertArrayNotHasKey(WC_Multi_Store_Sku_Conflicts::OPTION, $this->options);
    }

    public function test_oldest_entries_are_dropped_beyond_the_cap(): void
    {
        for ($id = 1; $id <= WC_Multi_Store_Sku_Conflicts::MAX_ENTRIES + 5; $id++) {
            WC_Multi_Store_Sku_Conflicts::record($id, 'https://a.com', 'S' . $id, $id, 0);
        }

        $ids = array_column(WC_Multi_Store_Sku_Conflicts::get_all(), 'product_id');

        $this->assertCount(WC_Multi_Store_Sku_Conflicts::MAX_ENTRIES, $ids);
        $this->assertNotContains(1, $ids);
        $this->assertContains(WC_Multi_Store_Sku_Conflicts::MAX_ENTRIES + 5, $ids);
    }

    public function test_corrupt_option_is_treated_as_empty(): void
    {
        $this->options[WC_Multi_Store_Sku_Conflicts::OPTION] = 'garbage';

        $this->assertSame([], WC_Multi_Store_Sku_Conflicts::get_all());
        $this->assertSame(0, WC_Multi_Store_Sku_Conflicts::count());
    }

    public function test_owner_name_is_kept_and_remote_edit_url_points_at_the_remote_admin(): void
    {
        WC_Multi_Store_Sku_Conflicts::record(7, 'https://a.com', 'SKU-1', 100, 4242, 'Blue shirt');
        Functions\when('untrailingslashit')->alias(fn($v) => rtrim($v, '/'));

        $all = array_values(WC_Multi_Store_Sku_Conflicts::get_all());

        $this->assertSame('Blue shirt', $all[0]['owner_name']);
        $this->assertSame(
            'https://a.com/wp-admin/post.php?post=4242&action=edit',
            WC_Multi_Store_Sku_Conflicts::remote_edit_url('https://a.com/', 4242)
        );
    }
}
