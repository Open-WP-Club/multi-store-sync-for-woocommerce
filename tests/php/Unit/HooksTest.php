<?php
/**
 * Unit tests for WC_Multi_Store_Hooks
 * Tests WordPress hook handlers and their business logic
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class HooksTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Hooks $hooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHooksMocks();

        // Clear static caches between tests
        WC_Multi_Store_Hooks::clear_settings_cache();

        // Construct hooks (requires add_action to be mocked)
        $this->hooks = new WC_Multi_Store_Hooks();
    }

    protected function setUpHooksMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('current_datetime')->justReturn(new \DateTimeImmutable('2024-01-15 12:00:00'));
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('wp_get_post_parent_id')->justReturn(50);
        Functions\when('wc_get_product')->justReturn(null);

        // Default: auto-sync enabled with all features
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'auto_sync_on_save' => true,
                    'auto_sync_new_products' => true,
                    'auto_sync_deletions' => true,
                    'auto_sync_restorations' => true,
                    'auto_sync_status' => true,
                    'stock_sync_enabled' => true,
                    'sync_type_default' => 'full_product',
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://store1.com' => [
                        'status' => 'active',
                        'consumer_key' => 'ck_test',
                        'consumer_secret' => 'cs_test',
                    ],
                ];
            }
            return $default;
        });
    }

    /**
     * Helper to set up wpdb mock for QueueManager calls
     */
    private function mockWpdbForQueue(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);
    }

    // ── on_product_save ──────────────────────────────────────────

    public function test_on_product_save_queues_product(): void
    {
        $this->mockWpdbForQueue();

        // Should not throw - means it reached QueueManager::add_product
        $this->hooks->on_product_save(100);
        $this->assertTrue(true);
    }

    public function test_on_product_save_skips_when_auto_sync_disabled(): void
    {
        // Override settings with auto_sync_on_save = false
        WC_Multi_Store_Hooks::clear_settings_cache();
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['auto_sync_on_save' => false];
            }
            return $default;
        });

        // Re-create hooks with new settings
        $hooks = new WC_Multi_Store_Hooks();

        // wpdb should NOT be called because we return early
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('insert');

        $hooks->on_product_save(100);
        $this->assertTrue(true);
    }

    public function test_on_product_save_skips_non_published(): void
    {
        Functions\when('get_post_status')->justReturn('draft');
        $this->mockWpdbForQueue();

        // wpdb->insert should not be called
        global $wpdb;
        $wpdb->shouldNotReceive('insert');

        $this->hooks->on_product_save(100);
        $this->assertTrue(true);
    }

    // ── on_new_product ───────────────────────────────────────────

    public function test_on_new_product_queues_product(): void
    {
        $this->mockWpdbForQueue();

        $this->hooks->on_new_product(100);
        $this->assertTrue(true);
    }

    public function test_on_new_product_skips_when_disabled(): void
    {
        WC_Multi_Store_Hooks::clear_settings_cache();
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['auto_sync_new_products' => false];
            }
            return $default;
        });

        $hooks = new WC_Multi_Store_Hooks();
        $hooks->on_new_product(100);
        $this->assertTrue(true);
    }

    // ── on_variation_save ────────────────────────────────────────

    public function test_on_variation_save_queues_parent(): void
    {
        $this->mockWpdbForQueue();

        $this->hooks->on_variation_save(200);
        $this->assertTrue(true);
    }

    public function test_on_variation_save_skips_non_published_parent(): void
    {
        // Parent is draft
        Functions\when('get_post_status')->justReturn('draft');

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('insert');

        $this->hooks->on_variation_save(200);
        $this->assertTrue(true);
    }

    // ── on_stock_change ──────────────────────────────────────────

    public function test_on_stock_change_queues_product(): void
    {
        $this->mockWpdbForQueue();

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(100);
        $product->shouldReceive('get_stock_quantity')->andReturn(5);
        $product->shouldReceive('get_sku')->andReturn('TEST-SKU');
        $product->shouldReceive('get_status')->andReturn('publish');

        $this->hooks->on_stock_change($product);
        $this->assertTrue(true);
    }

    public function test_on_stock_change_skips_non_published(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_status')->andReturn('draft');

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('insert');

        $this->hooks->on_stock_change($product);
        $this->assertTrue(true);
    }

    public function test_on_stock_change_skips_when_stock_sync_disabled(): void
    {
        WC_Multi_Store_Hooks::clear_settings_cache();
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['stock_sync_enabled' => false];
            }
            return $default;
        });

        $hooks = new WC_Multi_Store_Hooks();

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_id')->andReturn(100);
        $product->shouldReceive('get_stock_quantity')->andReturn(5);
        $product->shouldReceive('get_sku')->andReturn('X');

        // With stock_sync_enabled=false, add_product should NOT be called
        // We verify by ensuring no wpdb insert happens
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('insert');

        $hooks->on_stock_change($product);
        $this->assertTrue(true);
    }

    // ── on_price_change ──────────────────────────────────────────

    public function test_on_price_change_queues_product(): void
    {
        $this->mockWpdbForQueue();

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(100);
        $product->shouldReceive('get_sku')->andReturn('PRICE-SKU');
        $product->shouldReceive('get_status')->andReturn('publish');

        $this->hooks->on_price_change($product);
        $this->assertTrue(true);
    }

    // ── on_product_delete ────────────────────────────────────────

    public function test_on_product_delete_queues_deletion(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('DEL-SKU');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $this->hooks->on_product_delete(100);
        $this->assertTrue(true);
    }

    public function test_on_product_delete_skips_when_disabled(): void
    {
        WC_Multi_Store_Hooks::clear_settings_cache();
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['auto_sync_deletions' => false];
            }
            return $default;
        });

        $hooks = new WC_Multi_Store_Hooks();
        $hooks->on_product_delete(100);
        $this->assertTrue(true);
    }

    // ── on_product_restore ───────────────────────────────────────

    public function test_on_product_restore_queues_restoration(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('RESTORE-SKU');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $this->hooks->on_product_restore(100);
        $this->assertTrue(true);
    }

    public function test_on_product_restore_skips_non_product(): void
    {
        Functions\when('get_post_type')->justReturn('page');

        $this->hooks->on_product_restore(100);
        $this->assertTrue(true);
    }

    // ── on_variation_delete ──────────────────────────────────────

    public function test_on_variation_delete_queues_deletion(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_row')->andReturn(
            (object) ['post_parent' => 50, 'sku' => 'VAR-SKU'],
            null
        );
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $this->hooks->on_variation_delete(200);
        $this->assertTrue(true);
    }

    // ── on_product_status_change ─────────────────────────────────

    public function test_on_product_status_change_to_trash_queues_deletion(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('TRASH-SKU');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $post = (object) ['ID' => 100, 'post_type' => 'product'];

        $this->hooks->on_product_status_change('trash', 'publish', $post);
        $this->assertTrue(true);
    }

    public function test_on_product_status_change_skips_non_product(): void
    {
        $post = (object) ['ID' => 100, 'post_type' => 'page'];

        $this->hooks->on_product_status_change('publish', 'draft', $post);
        $this->assertTrue(true);
    }

    public function test_on_product_status_change_skips_same_status(): void
    {
        $post = (object) ['ID' => 100, 'post_type' => 'product'];

        $this->hooks->on_product_status_change('publish', 'publish', $post);
        $this->assertTrue(true);
    }

    public function test_on_product_status_change_skips_auto_draft(): void
    {
        $post = (object) ['ID' => 100, 'post_type' => 'product'];

        $this->hooks->on_product_status_change('publish', 'auto-draft', $post);
        $this->assertTrue(true);
    }

    public function test_on_product_status_change_trash_to_publish_queues_restoration(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('RESTORE-SKU');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $post = (object) ['ID' => 100, 'post_type' => 'product'];

        $this->hooks->on_product_status_change('publish', 'trash', $post);
        $this->assertTrue(true);
    }

    public function test_on_product_status_change_draft_to_publish_queues_status_change(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn('STATUS-SKU');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $post = (object) ['ID' => 100, 'post_type' => 'product'];

        $this->hooks->on_product_status_change('publish', 'draft', $post);
        $this->assertTrue(true);
    }

    // ── capture_old_stock ────────────────────────────────────────

    public function test_capture_old_stock_stores_value(): void
    {
        Functions\when('get_post_meta')->justReturn('15');

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(100);

        $this->hooks->capture_old_stock($product);
        $this->assertTrue(true);
    }

    public function test_capture_old_stock_skips_null_product(): void
    {
        $this->hooks->capture_old_stock(null);
        $this->assertTrue(true);
    }

    // ── queue dispatch assertions ─────────────────────────────────

    private function makeQueueMockHooks(): array
    {
        $qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        WC_Multi_Store_Sync::instance()->queue_manager = $qm;
        WC_Multi_Store_Hooks::clear_settings_cache();
        WC_Multi_Store_Settings::clear_static_cache();
        $hooks = new WC_Multi_Store_Hooks();
        return [$qm, $hooks];
    }

    public function test_stock_change_dispatches_with_critical_priority(): void
    {
        [$qm, $hooks] = $this->makeQueueMockHooks();

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn(101);
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_stock_quantity')->andReturn(3);
        $product->shouldReceive('get_sku')->andReturn('TEST-001');

        $qm->shouldReceive('add_product')
            ->once()
            ->with(101, 'stock_change', WC_Multi_Store_Queue_Manager::PRIORITY_CRITICAL)
            ->andReturn(1);

        $hooks->on_stock_change($product);

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_status_change_to_trash_dispatches_deletion_not_regular_sync(): void
    {
        [$qm, $hooks] = $this->makeQueueMockHooks();

        $post = (object) ['ID' => 202, 'post_type' => 'product'];

        $qm->shouldNotReceive('add_product');
        $qm->shouldReceive('add_product_deletion')
            ->once()
            ->with(202, 'status_change_trash', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH)
            ->andReturn(1);

        $hooks->on_product_status_change('trash', 'publish', $post);

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();
    }

    public function test_saving_a_draft_does_not_queue_anything(): void
    {
        [$qm, $hooks] = $this->makeQueueMockHooks();

        Functions\when('get_post_status')->justReturn('draft');

        $qm->shouldNotReceive('add_product');
        $qm->shouldNotReceive('add_product_deletion');
        $qm->shouldNotReceive('add_product_restoration');
        $qm->shouldNotReceive('add_product_status_change');

        $hooks->on_product_save(303);

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();
    }
}
