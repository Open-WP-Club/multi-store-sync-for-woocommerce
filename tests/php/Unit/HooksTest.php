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
