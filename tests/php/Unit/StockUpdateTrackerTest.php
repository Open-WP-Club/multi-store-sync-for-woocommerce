<?php
/**
 * Unit tests for WC_Multi_Store_Stock_Update_Tracker
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class StockUpdateTrackerTest extends WC_Multi_Store_TestCase
{
    /**
     * Post meta storage (simulates database)
     */
    private array $post_meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->post_meta = [];

        // Simulate get_post_meta
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single = false) {
            return $this->post_meta[$post_id][$key] ?? '';
        });

        // Simulate update_post_meta
        Functions\when('update_post_meta')->alias(function ($post_id, $key, $value) {
            $this->post_meta[$post_id][$key] = $value;
            return true;
        });

        // Simulate delete_post_meta
        Functions\when('delete_post_meta')->alias(function ($post_id, $key) {
            unset($this->post_meta[$post_id][$key]);
            return true;
        });

        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-06-15 12:00:00');
        Functions\when('update_option')->justReturn(true);
    }

    // ─── Meta key constants ────────────────────────

    public function test_meta_key_constants(): void
    {
        $this->assertEquals('_wc_mss_stock_last_update', WC_Multi_Store_Stock_Update_Tracker::META_LAST_UPDATE);
        $this->assertEquals('_wc_mss_stock_update_source', WC_Multi_Store_Stock_Update_Tracker::META_UPDATE_SOURCE);
        $this->assertEquals('_wc_mss_stock_sync_version', WC_Multi_Store_Stock_Update_Tracker::META_SYNC_VERSION);
    }

    // ─── Sync version tracking ─────────────────────

    public function test_get_sync_version_returns_zero_for_new_product(): void
    {
        $this->assertEquals(0, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(100));
    }

    public function test_record_webhook_update_increments_version(): void
    {
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(100, 'https://store.example.com');

        $this->assertEquals(1, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(100));
    }

    public function test_multiple_webhook_updates_increment_version(): void
    {
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(100, 'https://store1.com');
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(100, 'https://store2.com');
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(100, 'https://store3.com');

        $this->assertEquals(3, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(100));
    }

    public function test_different_products_have_independent_versions(): void
    {
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(100, 'https://store.com');
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(100, 'https://store.com');
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(200, 'https://store.com');

        $this->assertEquals(2, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(100));
        $this->assertEquals(1, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(200));
    }
}
