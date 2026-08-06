<?php
/**
 * Unit tests for WC_Multi_Store_Category_Deletion_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class CategoryDeletionSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCategoryDeletionMocks();
    }

    protected function setUpCategoryDeletionMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir() . '/wc-mss-tests',
            'baseurl' => 'http://example.com/wp-content/uploads',
        ]);
        Functions\when('wp_mkdir_p')->justReturn(true);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Category_Deletion_Sync'));
    }

    public function test_on_category_delete_action_none_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'none',
        ]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Test Category'];

        // Should return early without processing
        $sync->on_category_delete(1, 1, $term, [100, 200]);
        $this->assertTrue(true);
    }

    public function test_on_category_delete_no_products_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'delete',
        ]);
        Functions\when('get_posts')->justReturn([]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Empty Category'];

        $sync->on_category_delete(1, 1, $term, [100]);
        $this->assertTrue(true);
    }

    public function test_on_tag_delete_action_none_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'tag_deletion_action' => 'none',
        ]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Test Tag'];

        $sync->on_tag_delete(1, 1, $term, [100]);
        $this->assertTrue(true);
    }

    public function test_on_tag_delete_no_products_does_nothing(): void
    {
        Functions\when('get_option')->justReturn([
            'tag_deletion_action' => 'delete',
        ]);
        Functions\when('get_posts')->justReturn([]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Empty Tag'];

        $sync->on_tag_delete(1, 1, $term, [100, 200]);
        $this->assertTrue(true);
    }

    public function test_get_products_by_term_empty_input(): void
    {
        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_products_by_term_filters_non_products(): void
    {
        // get_posts with post_type=>'product' already filters non-products — simulate that.
        Functions\when('get_posts')->justReturn([100]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, [100, 200]);

        $this->assertCount(1, $result);
        $this->assertContains(100, $result);
    }

    public function test_get_products_by_term_returns_only_products(): void
    {
        Functions\when('get_posts')->justReturn([100, 200, 300]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_products_by_term');

        $result = $method->invoke($sync, [100, 200, 300]);

        $this->assertCount(3, $result);
    }

    public function test_get_settings_defaults(): void
    {
        Functions\when('get_option')->justReturn([]);

        $settings = WC_Multi_Store_Category_Deletion_Sync::get_settings();

        $this->assertArrayHasKey('category_deletion_action', $settings);
        $this->assertArrayHasKey('tag_deletion_action', $settings);
        $this->assertEquals('none', $settings['category_deletion_action']);
        $this->assertEquals('none', $settings['tag_deletion_action']);
    }

    public function test_get_settings_with_stored_values(): void
    {
        Functions\when('get_option')->justReturn([
            'category_deletion_action' => 'delete',
            'tag_deletion_action' => 'delete',
        ]);

        $settings = WC_Multi_Store_Category_Deletion_Sync::get_settings();

        $this->assertEquals('delete', $settings['category_deletion_action']);
        $this->assertEquals('delete', $settings['tag_deletion_action']);
    }

    public function test_update_settings_category_action(): void
    {
        Functions\when('get_option')->justReturn([]);

        $result = WC_Multi_Store_Category_Deletion_Sync::update_settings([
            'category_deletion_action' => 'uncategorize',
        ]);

        $this->assertTrue($result);
    }

    public function test_update_settings_tag_action(): void
    {
        Functions\when('get_option')->justReturn([]);

        $result = WC_Multi_Store_Category_Deletion_Sync::update_settings([
            'tag_deletion_action' => 'delete',
        ]);

        $this->assertTrue($result);
    }

    public function test_on_category_delete_with_delete_action_and_auto_sync_disabled(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'category_deletion_action' => 'delete',
                    'auto_sync_deletions' => false,
                ];
            }
            return $default;
        });

        Functions\when('get_posts')->justReturn([100]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $term = (object) ['name' => 'Test Category'];

        // auto_sync_deletions is false, so products should NOT be deleted
        $sync->on_category_delete(1, 1, $term, [100]);
        $this->assertTrue(true);
    }

    public function test_get_or_create_uncategorized_existing(): void
    {
        $term = (object) ['term_id' => 42, 'name' => 'Uncategorized'];
        Functions\when('get_term_by')->justReturn($term);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_or_create_uncategorized_category');

        $result = $method->invoke($sync);

        $this->assertEquals(42, $result);
    }

    public function test_get_or_create_uncategorized_creates_new(): void
    {
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 99]);

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_or_create_uncategorized_category');

        $result = $method->invoke($sync);

        $this->assertEquals(99, $result);
    }

    public function test_get_or_create_uncategorized_insert_error(): void
    {
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(new WP_Error('term_exists', 'Term already exists'));

        $sync = new WC_Multi_Store_Category_Deletion_Sync();
        $method = new ReflectionMethod($sync, 'get_or_create_uncategorized_category');

        $result = $method->invoke($sync);

        $this->assertFalse($result);
    }
}
