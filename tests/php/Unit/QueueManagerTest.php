<?php
/**
 * Unit tests for WC_Multi_Store_Queue_Manager
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class QueueManagerTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQueueManagerMocks();
    }

    /**
     * Set up mocks specific to queue manager tests
     */
    protected function setUpQueueManagerMocks(): void
    {
        // Mock get_option for settings
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return array(
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                );
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return array();
            }
            return $default;
        });

        // Mock get_transient
        Functions\when('get_transient')->justReturn(false);

        // Mock set_transient
        Functions\when('set_transient')->justReturn(true);

        // Mock delete_transient
        Functions\when('delete_transient')->justReturn(true);
    }

    /**
     * Test class exists
     */
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Queue_Manager'));
    }

    /**
     * Test priority constants are defined correctly
     */
    public function test_priority_constants(): void
    {
        $this->assertEquals(1, WC_Multi_Store_Queue_Manager::PRIORITY_CRITICAL);
        $this->assertEquals(2, WC_Multi_Store_Queue_Manager::PRIORITY_HIGH);
        $this->assertEquals(3, WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL);
        $this->assertEquals(10, WC_Multi_Store_Queue_Manager::PRIORITY_LOW);
    }

    /**
     * Test add_product method exists
     */
    public function test_add_product_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'add_product'));
    }

    /**
     * Test add_products method exists
     */
    public function test_add_products_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'add_products'));
    }

    /**
     * Test add_product_deletion method exists
     */
    public function test_add_product_deletion_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'add_product_deletion'));
    }

    /**
     * Test add_product_restoration method exists
     */
    public function test_add_product_restoration_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'add_product_restoration'));
    }

    /**
     * Test add_product_status_change method exists
     */
    public function test_add_product_status_change_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'add_product_status_change'));
    }

    /**
     * Test add_variation_deletion method exists
     */
    public function test_add_variation_deletion_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'add_variation_deletion'));
    }

    /**
     * Test add_remote_orphan_deletion method exists
     */
    public function test_add_remote_orphan_deletion_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'add_remote_orphan_deletion'));
    }

    /**
     * Test process_queue method exists
     */
    public function test_process_queue_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'process_queue'));
    }

    /**
     * Test get_queue_count method exists
     */
    public function test_get_queue_count_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'get_queue_count'));
    }

    /**
     * Test clear_queue method exists
     */
    public function test_clear_queue_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'clear_queue'));
    }

    /**
     * Test remove_product method exists
     */
    public function test_remove_product_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'remove_product'));
    }

    /**
     * Test is_queued method exists
     */
    public function test_is_queued_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'is_queued'));
    }

    /**
     * Test get_statistics method exists
     */
    public function test_get_statistics_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'get_statistics'));
    }

    /**
     * Test cleanup_old_items method exists
     */
    public function test_cleanup_old_items_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Queue_Manager', 'cleanup_old_items'));
    }

    /**
     * Test add_product signature with custom priority
     */
    public function test_add_product_accepts_custom_priority(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $method = $reflection->getMethod('add_product');

        $params = $method->getParameters();
        $this->assertCount(4, $params); // product_id, trigger, priority, sync_type_override

        // Verify priority parameter has default value
        $this->assertEquals(WC_Multi_Store_Queue_Manager::PRIORITY_LOW, $params[2]->getDefaultValue());
    }

    /**
     * Test add_product with sync_type_override
     */
    public function test_add_product_accepts_sync_type_override(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $method = $reflection->getMethod('add_product');

        $params = $method->getParameters();

        // Fourth parameter should be sync_type_override
        $this->assertEquals('sync_type_override', $params[3]->getName());
        $this->assertTrue($params[3]->isOptional());
        $this->assertNull($params[3]->getDefaultValue());
    }

    /**
     * Test add_product_deletion with specific stores parameter
     */
    public function test_add_product_deletion_accepts_specific_stores(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $method = $reflection->getMethod('add_product_deletion');

        $params = $method->getParameters();

        // Fourth parameter should be specific_stores
        $this->assertEquals('specific_stores', $params[3]->getName());
        $this->assertTrue($params[3]->isOptional());
        $this->assertNull($params[3]->getDefaultValue());
    }

    /**
     * Test add_product_deletion with audit_id parameter
     */
    public function test_add_product_deletion_accepts_audit_id(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $method = $reflection->getMethod('add_product_deletion');

        $params = $method->getParameters();

        // Fifth parameter should be audit_id
        $this->assertEquals('audit_id', $params[4]->getName());
        $this->assertTrue($params[4]->isOptional());
        $this->assertNull($params[4]->getDefaultValue());
    }

    /**
     * Test queue_product_operation private method exists
     */
    public function test_queue_product_operation_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $this->assertTrue($reflection->hasMethod('queue_product_operation'));

        $method = $reflection->getMethod('queue_product_operation');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test get_product_queue_data private method exists
     */
    public function test_get_product_queue_data_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $this->assertTrue($reflection->hasMethod('get_product_queue_data'));

        $method = $reflection->getMethod('get_product_queue_data');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test get_product_terms public method exists
     */
    public function test_get_product_terms_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $this->assertTrue($reflection->hasMethod('get_product_terms'));

        $method = $reflection->getMethod('get_product_terms');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test do_process_queue private method exists
     */
    public function test_do_process_queue_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $this->assertTrue($reflection->hasMethod('do_process_queue'));

        $method = $reflection->getMethod('do_process_queue');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test all public methods are instance methods (not static)
     */
    public function test_all_public_methods_are_instance_methods(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');

        $public_methods = array(
            'add_product',
            'add_products',
            'add_product_deletion',
            'add_product_restoration',
            'add_product_status_change',
            'add_variation_deletion',
            'add_remote_orphan_deletion',
            'process_queue',
            'get_queue_count',
            'clear_queue',
            'remove_product',
            'is_queued',
            'get_statistics',
            'cleanup_old_items',
        );

        foreach ($public_methods as $method_name) {
            $method = $reflection->getMethod($method_name);
            $this->assertFalse(
                $method->isStatic(),
                "Method $method_name should be an instance method, not static"
            );
        }
    }

    /**
     * Test cleanup_old_items default days parameter
     */
    public function test_cleanup_old_items_default_days(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Queue_Manager');
        $method = $reflection->getMethod('cleanup_old_items');

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertEquals(7, $params[0]->getDefaultValue());
    }
}
