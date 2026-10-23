<?php
/**
 * Strict dispatch tests for WC_Multi_Store_Hooks.
 *
 * Every handler is checked against a Mockery queue manager with explicit
 * expectations (method, trigger, priority) - and, for the skip paths, with
 * shouldNotReceive() on every queue entry point - so a handler that silently
 * stops queueing, or queues the wrong operation, fails here.
 */

use Brain\Monkey\Functions;

class HooksDispatchTest extends WC_Multi_Store_TestCase
{
    private \Mockery\MockInterface $qm;
    private mixed $originalQueueManager;

    private const DEFAULT_SETTINGS = [
        'enabled' => true,
        'auto_sync_on_save' => true,
        'auto_sync_new_products' => true,
        'auto_sync_deletions' => true,
        'auto_sync_restorations' => true,
        'auto_sync_status' => true,
        'stock_sync_enabled' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalQueueManager = WC_Multi_Store_Sync::instance()->queue_manager ?? null;
        $this->qm = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        WC_Multi_Store_Sync::instance()->queue_manager = $this->qm;

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('wp_get_post_parent_id')->justReturn(50);
        Functions\when('wc_get_product')->justReturn(false); // deletion audit finds nothing; queueing must carry on
        Functions\when('get_post_meta')->justReturn('');
        $_REQUEST = [];
        // Static per-process state: never let one test's captured stock leak into the next.
        (new ReflectionProperty(WC_Multi_Store_Hooks::class, 'old_stock_values'))->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        WC_Multi_Store_Sync::instance()->queue_manager = $this->originalQueueManager;
        $_REQUEST = [];
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function hooks(array $overrides = []): WC_Multi_Store_Hooks
    {
        $settings = array_merge(self::DEFAULT_SETTINGS, $overrides);
        WC_Multi_Store_Hooks::clear_settings_cache();
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($option, $default = false) use ($settings) {
            return match ($option) {
                'wc_multi_store_sync_settings' => $settings,
                'wc_multi_store_sync_stores' => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']],
                default => $default,
            };
        });
        return new WC_Multi_Store_Hooks();
    }

    private function nothingQueued(): void
    {
        foreach (['add_product', 'add_product_deletion', 'add_product_restoration', 'add_product_status_change', 'add_variation_deletion'] as $method) {
            $this->qm->shouldNotReceive($method);
        }
    }

    private function product(int $id = 100, string $status = 'publish'): \Mockery\MockInterface
    {
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('get_id')->andReturn($id);
        $p->shouldReceive('get_status')->andReturn($status);
        $p->shouldReceive('get_sku')->andReturn('SKU-' . $id);
        $p->shouldReceive('get_stock_quantity')->andReturn(5);
        return $p;
    }

    // ---- on_product_save / on_new_product --------------------------------

    public function test_product_save_queues_with_normal_priority(): void
    {
        $this->qm->shouldReceive('add_product')->once()
            ->with(100, 'product_save', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL)->andReturn(1);

        $this->hooks()->on_product_save(100);
    }

    public function test_new_product_queues_with_high_priority(): void
    {
        $this->qm->shouldReceive('add_product')->once()
            ->with(100, 'new_product', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH)->andReturn(1);

        $this->hooks()->on_new_product(100);
    }

    public function test_save_and_new_product_skip_when_their_settings_are_off(): void
    {
        $this->nothingQueued();

        $this->hooks(['auto_sync_on_save' => false])->on_product_save(100);
        $this->hooks(['auto_sync_new_products' => false])->on_new_product(100);
    }

    public function test_save_and_new_product_skip_non_published_posts(): void
    {
        Functions\when('get_post_status')->justReturn('draft');
        $this->nothingQueued();
        $hooks = $this->hooks();

        $hooks->on_product_save(100);
        $hooks->on_new_product(100);
    }

    public function test_save_and_new_product_skip_during_webhook_processing(): void
    {
        WC_Multi_Store_Webhook_Receiver::$is_processing_webhook = true;
        $this->nothingQueued();
        $hooks = $this->hooks();

        $hooks->on_product_save(100);
        $hooks->on_new_product(100);
        $hooks->on_variation_save(200);
        $hooks->on_stock_change($this->product());
    }

    public function test_save_handlers_skip_during_native_bulk_edit(): void
    {
        $_REQUEST['bulk_edit'] = 'Update';
        $this->nothingQueued();
        $hooks = $this->hooks();

        $hooks->on_product_save(100);
        $hooks->on_new_product(100);
        $hooks->on_variation_save(200);
        $hooks->on_product_delete(100);
        $hooks->on_product_restore(100);
        $hooks->on_variation_delete(200);
        $hooks->on_product_status_change('draft', 'publish', (object) ['ID' => 100, 'post_type' => 'product']);
    }

    // ---- on_variation_save -----------------------------------------------

    public function test_variation_save_queues_the_parent_not_the_variation(): void
    {
        $this->qm->shouldReceive('add_product')->once()
            ->with(50, 'variation_save', WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL)->andReturn(1);

        $this->hooks()->on_variation_save(200);
    }

    public function test_variation_save_skips_orphans_and_unpublished_parents(): void
    {
        $this->nothingQueued();
        $hooks = $this->hooks();

        Functions\when('wp_get_post_parent_id')->justReturn(0);
        $hooks->on_variation_save(200);

        Functions\when('wp_get_post_parent_id')->justReturn(50);
        Functions\when('wc_get_product')->justReturn(false); // deletion audit finds nothing; queueing must carry on
        Functions\when('get_post_status')->justReturn('draft');
        $hooks->on_variation_save(200);

        $this->hooks(['auto_sync_on_save' => false])->on_variation_save(200);
    }

    // ---- stock / price ----------------------------------------------------

    public function test_stock_change_queues_critical_and_forgets_captured_old_stock(): void
    {
        Functions\when('get_post_meta')->justReturn('15');
        $this->qm->shouldReceive('add_product')->once()
            ->with(100, 'stock_change', WC_Multi_Store_Queue_Manager::PRIORITY_CRITICAL)->andReturn(1);
        $hooks = $this->hooks();
        $product = $this->product();

        $hooks->capture_old_stock($product);
        $captured = new ReflectionProperty(WC_Multi_Store_Hooks::class, 'old_stock_values');
        $this->assertSame([100 => 15], $captured->getValue());

        $hooks->on_stock_change($product);
        $this->assertSame([], $captured->getValue(), 'Captured value must be consumed');
    }

    public function test_capture_old_stock_stores_null_when_no_stock_meta_and_ignores_missing_products(): void
    {
        $captured = new ReflectionProperty(WC_Multi_Store_Hooks::class, 'old_stock_values');
        $captured->setValue(null, []);
        $hooks = $this->hooks();

        Functions\when('get_post_meta')->justReturn('');
        $hooks->capture_old_stock($this->product(7));
        $hooks->capture_old_stock(null);
        $zero = \Mockery::mock('WC_Product');
        $zero->shouldReceive('get_id')->andReturn(0);
        $hooks->capture_old_stock($zero);

        $this->assertSame([7 => null], $captured->getValue());
    }

    public function test_stock_change_skips_unpublished_and_when_stock_sync_disabled(): void
    {
        $this->nothingQueued();

        $this->hooks()->on_stock_change($this->product(100, 'draft'));
        $this->hooks(['stock_sync_enabled' => false])->on_stock_change($this->product());
    }

    public function test_price_change_queues_high_priority_for_published_products_only(): void
    {
        $this->qm->shouldReceive('add_product')->once()
            ->with(100, 'price_change', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH)->andReturn(1);
        $hooks = $this->hooks();

        $hooks->on_price_change($this->product());
        $hooks->on_price_change($this->product(101, 'private'));
        $this->hooks(['auto_sync_on_save' => false])->on_price_change($this->product(102));
    }

    // ---- delete / restore -------------------------------------------------

    public function test_product_delete_queues_high_priority_deletion_for_all_active_stores(): void
    {
        $this->qm->shouldReceive('add_product_deletion')->once()
            ->with(100, 'product_delete', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH, \Mockery::on(
                fn($stores) => array_keys($stores) === ['https://store1.com']
            ), null, null)->andReturn(1);

        $this->hooks()->on_product_delete(100);
    }

    public function test_product_delete_honours_selective_deletion_map(): void
    {
        // Selective settings that select no store must result in nothing being queued.
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === '_wc_mss_selective_deletion' ? [md5('https://other.com') => true] : '');
        $this->nothingQueued();

        $this->hooks()->on_product_delete(100);
    }

    public function test_product_delete_with_selection_targets_only_selected_store(): void
    {
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === '_wc_mss_selective_deletion' ? [md5('https://store1.com') => true] : '');
        $this->qm->shouldReceive('add_product_deletion')->once()->andReturn(1);

        $this->hooks()->on_product_delete(100);
    }

    public function test_product_delete_skips_when_disabled(): void
    {
        $this->nothingQueued();

        $this->hooks(['auto_sync_deletions' => false])->on_product_delete(100);
    }

    public function test_before_delete_post_caches_sku_and_terms_for_the_following_delete(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('get_var')->andReturnUsing(
            fn($q) => str_contains($q, 'post_type') ? 'product' : 'GONE-SKU'
        );
        $this->qm->shouldReceive('get_product_terms')->once()->with(100)
            ->andReturn(['categories' => [['slug' => 'c']], 'tags' => [['slug' => 't']]]);
        $cacheOnDelete = [
            'sku' => 'GONE-SKU', 'categories' => [['slug' => 'c']], 'tags' => [['slug' => 't']],
        ];
        $this->qm->shouldReceive('add_product_deletion')->once()
            ->with(100, 'product_delete', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH, \Mockery::type('array'), null, $cacheOnDelete)
            ->andReturn(1);
        $hooks = $this->hooks();

        $hooks->on_before_delete_post(100);
        $hooks->on_product_delete(100);
    }

    public function test_before_delete_post_ignores_non_product_post_types(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('get_var')->once()->andReturn('page');
        $this->qm->shouldNotReceive('get_product_terms');

        $this->hooks()->on_before_delete_post(5);
    }

    public function test_restore_queues_high_priority_for_products_only(): void
    {
        $this->qm->shouldReceive('add_product_restoration')->once()
            ->with(100, 'product_restore', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH)->andReturn(1);
        $hooks = $this->hooks();

        $hooks->on_product_restore(100);

        Functions\when('get_post_type')->justReturn('page');
        $hooks->on_product_restore(101);
        $this->hooks(['auto_sync_restorations' => false])->on_product_restore(102);
    }

    public function test_variation_delete_queues_high_priority_unless_deletions_disabled(): void
    {
        $this->qm->shouldReceive('add_variation_deletion')->once()
            ->with(200, 'variation_delete', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH)->andReturn(1);

        $this->hooks()->on_variation_delete(200);
        $this->hooks(['auto_sync_deletions' => false])->on_variation_delete(201);
    }

    // ---- status transitions ----------------------------------------------

    private function post(int $id = 100, string $type = 'product'): object
    {
        return (object) ['ID' => $id, 'post_type' => $type];
    }

    public function test_status_trash_restore_and_visibility_transitions_dispatch_correct_operation(): void
    {
        $high = WC_Multi_Store_Queue_Manager::PRIORITY_HIGH;
        $this->qm->shouldNotReceive('add_product');
        $this->qm->shouldReceive('add_product_deletion')->once()->with(100, 'status_change_trash', $high)->andReturn(1);
        $this->qm->shouldReceive('add_product_restoration')->once()->with(100, 'status_change_restore', $high)->andReturn(1);
        $this->qm->shouldReceive('add_product_status_change')->once()->with(100, 'publish', 'draft', 'status_change', $high)->andReturn(1);
        $this->qm->shouldReceive('add_product_status_change')->once()->with(100, 'draft', 'publish', 'status_change', $high)->andReturn(1);
        $this->qm->shouldReceive('add_product_status_change')->once()->with(100, 'publish', 'private', 'status_change', $high)->andReturn(1);
        $hooks = $this->hooks();

        $hooks->on_product_status_change('trash', 'publish', $this->post());
        $hooks->on_product_status_change('publish', 'trash', $this->post());
        $hooks->on_product_status_change('draft', 'publish', $this->post());
        $hooks->on_product_status_change('publish', 'draft', $this->post());
        $hooks->on_product_status_change('private', 'publish', $this->post());
    }

    public function test_status_change_skips_irrelevant_transitions(): void
    {
        $this->nothingQueued();
        $hooks = $this->hooks();

        $hooks->on_product_status_change('publish', 'draft', $this->post(100, 'page'));
        $hooks->on_product_status_change('publish', 'publish', $this->post());
        $hooks->on_product_status_change('publish', 'auto-draft', $this->post());
        $hooks->on_product_status_change('publish', 'new', $this->post());
        $hooks->on_product_status_change('pending', 'publish', $this->post());
        $this->hooks(['auto_sync_status' => false])->on_product_status_change('draft', 'publish', $this->post());
    }

    public function test_trash_and_restore_transitions_respect_their_own_settings(): void
    {
        $this->nothingQueued();

        $this->hooks(['auto_sync_deletions' => false])->on_product_status_change('trash', 'publish', $this->post());
        $this->hooks(['auto_sync_restorations' => false])->on_product_status_change('publish', 'trash', $this->post());
    }
}
