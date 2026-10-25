<?php

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class BulkActionsAuthorizationTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('absint')->alias(static fn($id) => abs((int) $id));
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('add_query_arg')->alias(static fn($key, $value, $url) => $url . '&' . $key . '=' . $value);
        Functions\when('current_user_can')->justReturn(true);
        $_REQUEST['_wpnonce'] = 'valid-own-session-nonce';
        WC_MSS()->queue_manager = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
    }

    protected function tearDown(): void
    {
        unset($_REQUEST['_wpnonce']);
        WC_Multi_Store_Sync::reset_instance();
        parent::tearDown();
    }

    public static function actions(): array
    {
        return array_map(static fn($action) => [$action], [
            'mss_sync_full', 'mss_sync_prices', 'mss_sync_stock', 'mss_queue_high', 'mss_bulk_delete',
        ]);
    }

    public function test_product_editor_is_not_offered_management_actions(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->assertSame(['edit' => 'Edit'], (new WC_Multi_Store_Bulk_Actions())->add_bulk_actions(['edit' => 'Edit']));
    }

    #[DataProvider('actions')]
    public function test_valid_nonce_does_not_authorize_a_product_editor(string $action): void
    {
        Functions\when('current_user_can')->alias(static fn($capability) => $capability !== 'manage_woocommerce');
        WC_MSS()->queue_manager->shouldNotReceive('add_products');
        WC_MSS()->queue_manager->shouldNotReceive('add_product_deletion');

        $this->assertSame('/products', (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('/products', $action, [101]));
    }

    public function test_bulk_queue_only_accepts_existing_authorized_products(): void
    {
        Functions\when('get_post_type')->alias(static fn($id) => match ($id) {
            101, 102 => 'product',
            103 => 'page',
            default => false,
        });
        Functions\when('current_user_can')->alias(static fn($capability, $id = null) =>
            $capability === 'manage_woocommerce' || ($capability === 'edit_post' && $id === 101)
        );
        WC_MSS()->queue_manager->shouldReceive('add_products')->once()->with([101], 'bulk_action', 1)->andReturn(1);

        $result = (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions(
            '/products', 'mss_queue_high', ['101', 101, 102, 103, 999, 0, ['101']]
        );

        $this->assertSame('/products&mss_queued=1', $result);
    }

    public function test_no_authorized_products_leaves_queue_untouched(): void
    {
        Functions\when('current_user_can')->alias(static fn($capability) => $capability === 'manage_woocommerce');
        WC_MSS()->queue_manager->shouldNotReceive('add_products');

        $this->assertSame('/products', (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('/products', 'mss_queue_high', [101]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_remote_deletion_requires_delete_permission_even_with_edit_permission(): void
    {
        Functions\when('current_user_can')->alias(static fn($capability, $id = null) =>
            $capability === 'manage_woocommerce' || $capability === 'edit_post' || ($capability === 'delete_post' && $id === 102)
        );
        // Logger dependencies for the successful authorized deletion.
        Functions\when('current_time')->justReturn('2026-10-25 12:00:00');
        Functions\when('get_option')->alias(static fn($option, $default = false) => $default);
        WC_MSS()->queue_manager->shouldReceive('add_product_deletion')->once()->with(102, 'bulk_delete', 5)->andReturn(1);

        $result = (new WC_Multi_Store_Bulk_Actions())->handle_bulk_actions('/products', 'mss_bulk_delete', [101, 102]);

        $this->assertSame('/products&mss_bulk_deleted=1', $result);
    }
}
