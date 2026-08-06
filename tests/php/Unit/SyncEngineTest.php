<?php
/**
 * Unit tests for WC_Multi_Store_Sync_Engine
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncEngineTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSyncEngineMocks();
    }

    /**
     * Set up mocks specific to sync engine tests
     */
    protected function setUpSyncEngineMocks(): void
    {
        // Mock get_option for settings
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return array(
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'auth_method' => 'query_string',
                    'match_products_by' => 'sku',
                    'category_auto_create' => true,
                    'deletion_mode' => 'trash',
                );
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return array(
                    'https://store1.com' => array(
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                    ),
                );
            }
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return array('auto_verify' => false);
            }
            return $default;
        });

        // Mock get_transient
        Functions\when('get_transient')->justReturn(false);

        // Mock set_transient
        Functions\when('set_transient')->justReturn(true);

        // Mock delete_transient
        Functions\when('delete_transient')->justReturn(true);

        // Mock do_action
        Functions\when('do_action')->justReturn(null);

        // Mock current_time
        Functions\when('current_time')->justReturn('2024-01-01 12:00:00');
    }

    /**
     * Test WC_Multi_Store_Sync_Engine class exists
     */
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Sync_Engine'));
    }

    /**
     * Test sync_product method exists
     */
    public function test_sync_product_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'sync_product'));
    }

    /**
     * Test sync_product_to_store method exists
     */
    public function test_sync_product_to_store_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'sync_product_to_store'));
    }

    /**
     * Test bulk_sync_products method exists
     */
    public function test_bulk_sync_products_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'bulk_sync_products'));
    }

    /**
     * Test delete_product_from_store method exists
     */
    public function test_delete_product_from_store_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'delete_product_from_store'));
    }

    /**
     * Test restore_product_on_store method exists
     */
    public function test_restore_product_on_store_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'restore_product_on_store'));
    }

    /**
     * Test update_product_status_on_store method exists
     */
    public function test_update_product_status_on_store_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'update_product_status_on_store'));
    }

    /**
     * Test delete_variation_from_store method exists
     */
    public function test_delete_variation_from_store_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'delete_variation_from_store'));
    }

    /**
     * Test delete_orphan_product_from_store method exists
     */
    public function test_delete_orphan_product_from_store_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'delete_orphan_product_from_store'));
    }

    /**
     * Test clear_term_cache static method exists
     */
    public function test_clear_term_cache_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Sync_Engine', 'clear_term_cache'));
    }

    /**
     * Test sync_product signature has required parameters
     */
    public function test_sync_product_signature(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Sync_Engine');
        $method = $reflection->getMethod('sync_product');

        $params = $method->getParameters();
        $this->assertGreaterThanOrEqual(1, count($params));
        $this->assertEquals('product_id', $params[0]->getName());
    }

    /**
     * Test bulk_sync_products signature
     */
    public function test_bulk_sync_products_signature(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Sync_Engine');
        $method = $reflection->getMethod('bulk_sync_products');

        $params = $method->getParameters();
        $this->assertGreaterThanOrEqual(1, count($params));
        $this->assertEquals('product_ids', $params[0]->getName());
    }

    /**
     * Test delete_product_from_store signature
     */
    public function test_delete_product_from_store_signature(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Sync_Engine');
        $method = $reflection->getMethod('delete_product_from_store');

        $params = $method->getParameters();
        $this->assertGreaterThanOrEqual(4, count($params));
        $this->assertEquals('product_id', $params[0]->getName());
        $this->assertEquals('product_sku', $params[1]->getName());
        $this->assertEquals('store_url', $params[2]->getName());
        $this->assertEquals('store_config', $params[3]->getName());
    }

    /**
     * Test constructor accepts optional API client
     */
    public function test_constructor_signature(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Sync_Engine');
        $constructor = $reflection->getConstructor();

        if ($constructor) {
            $params = $constructor->getParameters();
            // If there are parameters, the first one should be optional (API client)
            if (count($params) > 0) {
                $this->assertTrue($params[0]->isOptional());
            }
        }

        $this->assertTrue(true);
    }

    /**
     * Test clear_term_cache is static
     */
    public function test_clear_term_cache_is_static(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Sync_Engine');
        $method = $reflection->getMethod('clear_term_cache');

        $this->assertTrue($method->isStatic());
    }

    /**
     * Test class has private method should_exclude_product
     */
    public function test_has_should_exclude_product_method(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Sync_Engine');
        $this->assertTrue($reflection->hasMethod('should_exclude_product'));
    }

}
