<?php
/** Bulk invalidation must work through WordPress with either transient backend. */
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheInvalidationTest extends WC_Multi_Store_TestCase {
    private bool $failWrites = false;
    private array $updates = [];

    public static function backends(): array {
        return ['database' => [false], 'persistent object cache' => [true]];
    }

    private function useBackend(bool $external): void {
        $options = [];
        $objects = [];
        Functions\when('get_option')->alias(static function ($name, $default = false) use (&$options) {
            return $options[$name] ?? $default;
        });
        Functions\when('update_option')->alias(function ($name, $value, $autoload = null) use (&$options): bool {
            $this->updates[] = [$name, $autoload];
            if ($this->failWrites) return false;
            $options[$name] = $value;
            return true;
        });
        Functions\when('set_transient')->alias(static function ($key, $value, $ttl) use ($external, &$options, &$objects): bool {
            if ($external) {
                $objects['transient'][$key] = $value;
            } else {
                $options['_transient_' . $key] = $value;
                $options['_transient_timeout_' . $key] = time() + $ttl;
            }
            return true;
        });
        Functions\when('get_transient')->alias(static function ($key) use ($external, &$options, &$objects) {
            return $external ? ($objects['transient'][$key] ?? false) : ($options['_transient_' . $key] ?? false);
        });
        Functions\when('delete_transient')->alias(static function ($key) use ($external, &$options, &$objects): bool {
            if ($external) {
                unset($objects['transient'][$key]);
            } else {
                unset($options['_transient_' . $key], $options['_transient_timeout_' . $key]);
            }
            return true;
        });
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('query')->never();
    }

    private function seedStore(string $store, int $id): void {
        WC_Multi_Store_Cache_Manager::set_remote_product($store, 'SHARED-SKU', 'sku', ['id' => $id]);
        WC_Multi_Store_Cache_Manager::set_remote_variations($store, 42, [['id' => $id]]);
        WC_Multi_Store_Cache_Manager::set_remote_terms($store, 'categories', [['id' => $id]]);
        WC_Multi_Store_Cache_Manager::set_remote_terms($store, 'tags', [['id' => $id]]);
        WC_Multi_Store_Cache_Manager::set_product_not_found($store, 'MISSING', 'sku');
    }

    private function assertStoreMisses(string $store): void {
        $this->assertFalse(WC_Multi_Store_Cache_Manager::get_remote_product($store, 'SHARED-SKU', 'sku'));
        $this->assertFalse(WC_Multi_Store_Cache_Manager::get_remote_variations($store, 42));
        $this->assertFalse(WC_Multi_Store_Cache_Manager::get_remote_terms($store, 'categories'));
        $this->assertFalse(WC_Multi_Store_Cache_Manager::get_remote_terms($store, 'tags'));
        $this->assertFalse(WC_Multi_Store_Cache_Manager::is_product_not_found($store, 'MISSING', 'sku'));
    }

    #[DataProvider('backends')]
    public function test_store_clear_preserves_other_stores_local_terms_and_unrelated_transients(bool $external): void {
        $this->useBackend($external);
        $store = 'https://a.test/' . str_repeat('catalog-', 40);
        $this->seedStore($store, 1);
        $this->seedStore('https://b.test', 2);
        WC_Multi_Store_Cache_Manager::set_taxonomy_terms('product_cat', [3], [['id' => 3]]);
        set_transient('another_plugin_cache', ['keep' => true], 900);

        $this->assertTrue(WC_Multi_Store_Cache_Manager::clear_store_cache($store));
        $this->assertStoreMisses($store);
        $this->assertSame(['id' => 2], WC_Multi_Store_Cache_Manager::get_remote_product('https://b.test', 'SHARED-SKU', 'sku'));
        $this->assertSame([['id' => 2]], WC_Multi_Store_Cache_Manager::get_remote_variations('https://b.test', 42));
        $this->assertSame([['id' => 2]], WC_Multi_Store_Cache_Manager::get_remote_terms('https://b.test', 'categories'));
        $this->assertSame([['id' => 2]], WC_Multi_Store_Cache_Manager::get_remote_terms('https://b.test', 'tags'));
        $this->assertTrue(WC_Multi_Store_Cache_Manager::is_product_not_found('https://b.test', 'MISSING', 'sku'));
        $this->assertSame([['id' => 3]], WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_cat', [3]));
        $this->assertSame(['keep' => true], get_transient('another_plugin_cache'));
        $this->seedStore($store, 10);
        $this->assertSame(['id' => 10], WC_Multi_Store_Cache_Manager::get_remote_product($store, 'SHARED-SKU', 'sku'));
        $this->assertTrue(WC_Multi_Store_Cache_Manager::clear_store_cache($store));
        $this->assertStoreMisses($store);
        foreach ($this->updates as [, $autoload]) $this->assertFalse($autoload);
    }

    #[DataProvider('backends')]
    public function test_global_clear_invalidates_all_types_and_repeated_clears_never_restore_old_entries(bool $external): void {
        $this->useBackend($external);
        $this->seedStore('https://a.test', 1);
        $this->seedStore('https://b.test', 2);
        WC_Multi_Store_Cache_Manager::set_taxonomy_terms('product_cat', [3], [['id' => 3]]);
        set_transient('another_plugin_cache', ['keep' => true], 900);
        $this->assertTrue((bool) WC_Multi_Store_Cache_Manager::clear_all());
        $this->assertStoreMisses('https://a.test');
        $this->assertStoreMisses('https://b.test');
        $this->assertFalse(WC_Multi_Store_Cache_Manager::get_taxonomy_terms('product_cat', [3]));
        $this->assertSame(['keep' => true], get_transient('another_plugin_cache'));
        $this->seedStore('https://a.test', 10);
        $this->assertTrue((bool) WC_Multi_Store_Cache_Manager::clear_all());
        $this->assertStoreMisses('https://a.test');
        $this->assertStoreMisses('https://b.test');
    }

    #[DataProvider('backends')]
    public function test_failed_invalidation_reports_failure_and_preserves_access_to_cached_values(bool $external): void {
        $this->useBackend($external);
        $this->seedStore('https://a.test', 1);
        $this->failWrites = true;
        $this->assertFalse(WC_Multi_Store_Cache_Manager::clear_store_cache('https://a.test'));
        $this->assertFalse((bool) WC_Multi_Store_Cache_Manager::clear_all());
        $this->assertSame(['id' => 1], WC_Multi_Store_Cache_Manager::get_remote_product('https://a.test', 'SHARED-SKU', 'sku'));
    }
    #[DataProvider('backends')]
    public function test_saving_a_normalized_store_url_invalidates_its_existing_cache(bool $external): void {
        $this->useBackend($external);
        Functions\when('esc_url_raw')->alias(static fn($url) => $url);
        Functions\when('wp_parse_url')->alias('parse_url');
        $this->seedStore('https://a.test', 1);
        $this->seedStore('https://b.test', 2);
        $this->assertTrue(WC_Multi_Store_Settings::update_store(' https://a.test/ ', ['status' => 'active']));
        $this->assertStoreMisses('https://a.test');
        $this->assertSame(['id' => 2], WC_Multi_Store_Cache_Manager::get_remote_product('https://b.test', 'SHARED-SKU', 'sku'));
    }

}
