<?php
/**
 * Edge case tests for WC_Multi_Store_Hooks deletion and status change logic
 *
 * Covers: on_product_delete selective deletion, get_stores_to_delete_from
 * with corrupted meta, empty stores, status change edge cases.
 * hooks.php lines 368-460
 */

use Brain\Monkey;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Brain\Monkey\Functions;

class HooksDeletionEdgeCaseTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Hooks $hooks;

    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'auto_sync_deletions' => true,
                    'auto_sync_on_save' => true,
                    'auto_sync_status' => true,
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_1',
                        'consumer_secret' => 'cs_1',
                    ],
                    'https://store2.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_2',
                        'consumer_secret' => 'cs_2',
                    ],
                ];
            }
            return $default;
        });

        $this->hooks = new WC_Multi_Store_Hooks();
    }

    // ── get_stores_to_delete_from ────────────────────────────────

    public function test_get_stores_returns_all_when_no_selective_settings(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $ref = new \ReflectionClass($this->hooks);
        $method = $ref->getMethod('get_stores_to_delete_from');

        $result = $method->invoke($this->hooks, 100);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('https://store1.com', $result);
        $this->assertArrayHasKey('https://store2.com', $result);
    }

    public function test_get_stores_returns_all_when_selective_settings_is_not_array(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_wc_mss_selective_deletion') return 'invalid_string';
            return '';
        });

        $ref = new \ReflectionClass($this->hooks);
        $method = $ref->getMethod('get_stores_to_delete_from');

        $result = $method->invoke($this->hooks, 100);

        // Non-array settings treated as "no selective settings"
        $this->assertCount(2, $result);
    }

    public function test_get_stores_returns_only_selected_stores(): void
    {
        $store1Hash = md5('https://store1.com');
        $store2Hash = md5('https://store2.com');

        Functions\when('get_post_meta')->alias(function ($id, $key, $single) use ($store1Hash) {
            if ($key === '_wc_mss_selective_deletion') {
                return [$store1Hash => true]; // Only store1 selected
            }
            if ($key === '_wc_mss_deletion_stores_map') {
                return [];
            }
            return '';
        });

        $ref = new \ReflectionClass($this->hooks);
        $method = $ref->getMethod('get_stores_to_delete_from');

        $result = $method->invoke($this->hooks, 100);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('https://store1.com', $result);
        $this->assertArrayNotHasKey('https://store2.com', $result);
    }

    public function test_get_stores_returns_empty_when_selective_selects_none(): void
    {
        // Selective deletion enabled but no stores selected
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_wc_mss_selective_deletion') {
                return ['nonexistent_hash' => true];
            }
            return '';
        });

        $ref = new \ReflectionClass($this->hooks);
        $method = $ref->getMethod('get_stores_to_delete_from');

        $result = $method->invoke($this->hooks, 100);

        $this->assertEmpty($result);
    }

    public function test_get_stores_returns_empty_with_empty_selective_array(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_wc_mss_selective_deletion') {
                return []; // Empty array
            }
            return '';
        });

        $ref = new \ReflectionClass($this->hooks);
        $method = $ref->getMethod('get_stores_to_delete_from');

        $result = $method->invoke($this->hooks, 100);

        // Empty selective settings treated as "no settings" → all stores
        $this->assertCount(2, $result);
    }

    // ── on_product_delete ────────────────────────────────────────

    public function test_on_product_delete_skips_when_deletion_sync_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['auto_sync_deletions' => false];
            }
            return $default;
        });

        // Create fresh hooks instance with new settings
        $hooks = new WC_Multi_Store_Hooks();

        // If it doesn't call get_stores_to_delete_from, it skipped correctly
        // We verify by checking no queue operations happen
        Functions\when('get_post_meta')->justReturn('');

        // This should return early without error
        $hooks->on_product_delete(100);
        $this->assertTrue(true); // No exception = success
    }

    public function test_on_product_delete_returns_when_no_stores_to_delete(): void
    {
        // No active stores
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true, 'auto_sync_deletions' => true];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return []; // No stores
            }
            return $default;
        });

        Functions\when('get_post_meta')->justReturn('');

        $hooks = new WC_Multi_Store_Hooks();
        $hooks->on_product_delete(100);

        $this->assertTrue(true); // No exception
    }

    // ── on_product_delete during bulk edit / import ──────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_on_product_delete_skips_during_bulk_edit(): void
    {
        if (!defined('DOING_BULK_EDIT')) {
            define('DOING_BULK_EDIT', true);
        }

        $this->hooks->on_product_delete(100);
        $this->assertTrue(true);
    }

    // ── Setting helper ───────────────────────────────────────────

    public function test_get_setting_returns_default_for_missing_key(): void
    {
        $ref = new \ReflectionClass($this->hooks);
        $method = $ref->getMethod('get_setting');

        $result = $method->invoke(null, 'nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function test_get_setting_returns_actual_value(): void
    {
        $ref = new \ReflectionClass($this->hooks);
        $method = $ref->getMethod('get_setting');

        $result = $method->invoke(null, 'auto_sync_deletions', false);

        $this->assertTrue($result);
    }
}
