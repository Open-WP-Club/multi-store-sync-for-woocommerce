<?php
/**
 * Tests for WC_Multi_Store_Product_Edit metabox rendering, selective-deletion
 * saving and the preview AJAX endpoint. Complements ProductEditTest.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class ProductEditViewTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Product_Edit $editor;

    /** @var list<array{0: int, 1: string, 2: mixed}> update_post_meta() calls. */
    private array $meta = [];

    /** @var array{success: ?bool, data: mixed} */
    private array $response = ['success' => null, 'data' => null];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_meta_box')->justReturn(true);
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(function ($v, $d = 'default') {
            echo htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_url')->alias(fn($v) => (string) $v);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://local.test/wp-admin/' . $p);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('checked')->alias(function ($checked, $current = true, $echo = true) {
            $out = ((string) $checked === (string) $current) ? " checked='checked'" : '';
            if ($echo) {
                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
            }
            return $out;
        });
        Functions\when('wp_nonce_field')->alias(function ($action, $name) {
            echo '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });

        if (!class_exists('WC_Multi_Store_Product_Edit', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/product-edit.php';
        }
        $this->editor = new WC_Multi_Store_Product_Edit();

        $this->meta = [];
        $this->response = ['success' => null, 'data' => null];
        $_POST = [];
        $this->setActiveStores([]);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();
        $_POST = [];
        parent::tearDown();
    }

    private function setActiveStores(array $stores): void
    {
        $prop = new ReflectionProperty('WC_Multi_Store_Settings', 'active_stores_cache');
        $prop->setValue(null, $stores);
    }

    private function queue(bool $isQueued): void
    {
        $queue = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $queue->shouldReceive('is_queued')->andReturn($isQueued);
        WC_Multi_Store_Sync::instance()->queue_manager = $queue;
    }

    private function post(int $id): WP_Post
    {
        return new WP_Post(['ID' => $id, 'post_type' => 'product']);
    }

    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function stub_meta_writes(): void
    {
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[] = [$id, $key, $value];
            return true;
        });
    }

    // ─── add_*_metabox ─────────────────────────────

    public function test_metaboxes_are_registered_on_the_product_side_column(): void
    {
        $registered = [];
        Functions\when('add_meta_box')->alias(function (...$args) use (&$registered) {
            $registered[$args[0]] = $args;
        });

        $this->editor->add_sync_metabox();
        $this->editor->add_store_deletion_metabox();

        $this->assertSame(['wc_multi_store_sync_actions', 'wc_multi_store_deletion_settings'], array_keys($registered));
        $this->assertSame(['product', 'side', 'high'], array_slice($registered['wc_multi_store_sync_actions'], 3));
        $this->assertSame(['product', 'side', 'default'], array_slice($registered['wc_multi_store_deletion_settings'], 3));
        $this->assertSame('Multi-Store Sync', $registered['wc_multi_store_sync_actions'][1]);
        $this->assertIsCallable($registered['wc_multi_store_sync_actions'][2]);
        $this->assertIsCallable($registered['wc_multi_store_deletion_settings'][2]);
    }

    // ─── render_sync_metabox ───────────────────────

    public function test_sync_metabox_without_active_stores_links_to_configuration_and_hides_buttons(): void
    {
        $this->queue(false);

        $html = $this->capture(fn() => $this->editor->render_sync_metabox($this->post(10)));

        $this->assertStringContainsString('No active stores configured.', $html);
        $this->assertStringContainsString('href="https://local.test/wp-admin/admin.php?page=wc-multi-store-sync-stores"', $html);
        $this->assertStringNotContainsString('wc-mss-sync-btn', $html);
        $this->assertStringNotContainsString('wc-mss-preview-btn', $html);
    }

    public function test_sync_metabox_with_stores_renders_three_sync_buttons_and_preview_for_the_product(): void
    {
        $this->queue(false);
        $this->setActiveStores(['https://a.test' => ['status' => 'active'], 'https://b.test' => ['status' => 'active']]);

        $html = $this->capture(fn() => $this->editor->render_sync_metabox($this->post(321)));

        $this->assertStringContainsString('Sync this product to 2 active store(s).', $html);
        $this->assertSame(3, substr_count($html, 'wc-mss-sync-btn'));
        foreach (['full_product', 'price_quantity', 'quantity'] as $type) {
            $this->assertMatchesRegularExpression('/data-product-id="321" data-sync-type="' . $type . '"/', $html);
        }
        $this->assertStringContainsString('class="button wc-mss-preview-btn" data-product-id="321"', $html);
        $this->assertStringNotContainsString('No active stores', $html);
        $this->assertStringNotContainsString('Queued for sync', $html);
    }

    public function test_sync_metabox_shows_queue_notice_only_when_product_is_queued(): void
    {
        $this->setActiveStores(['https://a.test' => ['status' => 'active']]);

        $this->queue(true);
        $queued = $this->capture(fn() => $this->editor->render_sync_metabox($this->post(5)));
        $this->queue(false);
        $idle = $this->capture(fn() => $this->editor->render_sync_metabox($this->post(5)));

        $this->assertStringContainsString('wc-mss-queue-notice', $queued);
        $this->assertStringContainsString('This product is in the sync queue', $queued);
        $this->assertStringNotContainsString('wc-mss-queue-notice', $idle);
    }

    public function test_sync_metabox_passes_the_post_id_to_the_queue_lookup(): void
    {
        $this->setActiveStores(['https://a.test' => ['status' => 'active']]);
        $queue = \Mockery::mock('WC_Multi_Store_Queue_Manager');
        $asked = [];
        $queue->shouldReceive('is_queued')->andReturnUsing(function ($id) use (&$asked) {
            $asked[] = $id;
            return false;
        });
        WC_Multi_Store_Sync::instance()->queue_manager = $queue;

        $this->capture(fn() => $this->editor->render_sync_metabox($this->post(987)));

        $this->assertSame([987], $asked);
    }

    // ─── render_store_deletion_metabox ─────────────

    public function test_deletion_metabox_without_stores_prints_notice_and_no_nonce_or_checkboxes(): void
    {
        $html = $this->capture(fn() => $this->editor->render_store_deletion_metabox($this->post(1)));

        $this->assertStringContainsString('No active stores configured.', $html);
        $this->assertStringNotContainsString('wc_mss_store_deletion_nonce', $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    public function test_deletion_metabox_defaults_every_store_to_checked(): void
    {
        $this->setActiveStores(['https://a.test' => ['status' => 'active'], 'https://b.test' => ['status' => 'active']]);
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->capture(fn() => $this->editor->render_store_deletion_metabox($this->post(1)));

        $this->assertSame(2, substr_count($html, 'type="checkbox"'));
        $this->assertSame(2, substr_count($html, "checked='checked'"));
        $this->assertStringContainsString('name="_wc_mss_delete_from_stores[' . md5('https://a.test') . ']"', $html);
        $this->assertStringContainsString('name="_wc_mss_delete_from_stores[' . md5('https://b.test') . ']"', $html);
        $this->assertStringContainsString('name="wc_mss_store_deletion_nonce" value="nonce-for-wc_mss_store_deletion_nonce"', $html);
        $this->assertStringContainsString('<span>https://a.test</span>', $html);
    }

    public function test_deletion_metabox_honours_saved_per_store_choice(): void
    {
        $this->setActiveStores(['https://a.test' => ['status' => 'active'], 'https://b.test' => ['status' => 'active']]);
        Functions\when('get_post_meta')->justReturn([md5('https://a.test') => false, md5('https://b.test') => true]);

        $html = $this->capture(fn() => $this->editor->render_store_deletion_metabox($this->post(1)));

        $this->assertSame(1, substr_count($html, "checked='checked'"));
        $this->assertMatchesRegularExpression(
            '/\[' . md5('https://b.test') . '\]"\s+value="1"\s+ checked=/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\[' . md5('https://a.test') . '\]"\s+value="1"\s+ checked=/',
            $html
        );
    }

    public function test_deletion_metabox_ignores_non_array_saved_meta(): void
    {
        $this->setActiveStores(['https://a.test' => ['status' => 'active']]);
        Functions\when('get_post_meta')->justReturn('corrupt-string');

        $html = $this->capture(fn() => $this->editor->render_store_deletion_metabox($this->post(1)));

        $this->assertSame(1, substr_count($html, "checked='checked'"));
    }

    public function test_deletion_metabox_prefers_store_name_and_escapes_it(): void
    {
        $this->setActiveStores([
            'https://a.test' => ['status' => 'active', 'name' => '<img src=x onerror=alert(1)>'],
            'https://"><script>alert(2)</script>' => ['status' => 'active'],
        ]);
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->capture(fn() => $this->editor->render_store_deletion_metabox($this->post(1)));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<span>&lt;img src=x onerror=alert(1)&gt;</span>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $html);
    }

    // ─── save_store_deletion_settings ──────────────

    private function valid_nonce(): void
    {
        $_POST['wc_mss_store_deletion_nonce'] = 'good';
        Functions\when('wp_verify_nonce')->alias(fn($nonce, $action) => $nonce === 'good' && $action === 'wc_mss_store_deletion_nonce' ? 1 : false);
    }

    public function test_save_deletion_settings_is_skipped_when_user_cannot_edit_the_product(): void
    {
        $this->valid_nonce();
        $this->setActiveStores(['https://a.test' => ['status' => 'active']]);
        $checked = null;
        Functions\when('current_user_can')->alias(function ($cap, $id = null) use (&$checked) {
            $checked = [$cap, $id];
            return false;
        });
        $this->stub_meta_writes();

        $this->editor->save_store_deletion_settings(44);

        $this->assertSame(['edit_product', 44], $checked);
        $this->assertSame([], $this->meta);
    }

    public function test_save_deletion_settings_records_checked_and_unchecked_stores_and_url_map(): void
    {
        $this->valid_nonce();
        $this->setActiveStores(['https://a.test' => ['status' => 'active'], 'https://b.test' => ['status' => 'active']]);
        Functions\when('current_user_can')->justReturn(true);
        $_POST['_wc_mss_delete_from_stores'] = [md5('https://a.test') => '1'];
        $this->stub_meta_writes();

        $this->editor->save_store_deletion_settings(44);

        $this->assertSame([
            [44, '_wc_mss_selective_deletion', [md5('https://a.test') => true, md5('https://b.test') => false]],
            [44, '_wc_mss_deletion_stores_map', [md5('https://a.test') => 'https://a.test', md5('https://b.test') => 'https://b.test']],
        ], $this->meta);
    }

    public function test_save_deletion_settings_unticking_every_store_is_saved_as_none_selected(): void
    {
        $this->valid_nonce();
        $this->setActiveStores(['https://a.test' => ['status' => 'active'], 'https://b.test' => ['status' => 'active']]);
        Functions\when('current_user_can')->justReturn(true);
        // Browsers send no key at all when every checkbox is unticked.
        unset($_POST['_wc_mss_delete_from_stores']);
        $this->stub_meta_writes();

        $this->editor->save_store_deletion_settings(44);

        $this->assertSame(
            [md5('https://a.test') => false, md5('https://b.test') => false],
            $this->meta[0][2]
        );
    }

    public function test_save_deletion_settings_ignores_posted_keys_for_unknown_stores(): void
    {
        $this->valid_nonce();
        $this->setActiveStores(['https://a.test' => ['status' => 'active']]);
        Functions\when('current_user_can')->justReturn(true);
        $_POST['_wc_mss_delete_from_stores'] = [md5('https://a.test') => '1', md5('https://attacker.test') => '1', 'junk' => '1'];
        $this->stub_meta_writes();

        $this->editor->save_store_deletion_settings(9);

        $this->assertSame([md5('https://a.test') => true], $this->meta[0][2]);
    }

    public function test_save_deletion_settings_rejects_wrong_nonce_without_writing(): void
    {
        $_POST['wc_mss_store_deletion_nonce'] = 'forged';
        Functions\when('wp_verify_nonce')->alias(fn($nonce, $action) => $nonce === 'good' ? 1 : false);
        Functions\when('current_user_can')->justReturn(true);
        $this->stub_meta_writes();

        $this->editor->save_store_deletion_settings(9);

        $this->assertSame([], $this->meta);
    }

    // ─── ajax_preview_sync ─────────────────────────

    /** Make wp_send_json_* terminate like the real functions. */
    private function halting_json(): void
    {
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->response = ['success' => true, 'data' => $data];
            throw new RuntimeException('json_sent');
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            $this->response = ['success' => false, 'data' => $data];
            throw new RuntimeException('json_sent');
        });
    }

    private function preview(): void
    {
        try {
            $this->editor->ajax_preview_sync();
        } catch (RuntimeException $e) {
            $this->assertSame('json_sent', $e->getMessage());
        }
    }

    public function test_preview_checks_the_preview_nonce_first(): void
    {
        $seen = null;
        Functions\when('check_ajax_referer')->alias(function ($action, $field) use (&$seen) {
            $seen = [$action, $field];
            throw new RuntimeException('json_sent');
        });
        $this->halting_json();

        $this->preview();

        $this->assertSame(['wc_mss_preview_sync', 'nonce'], $seen);
        $this->assertNull($this->response['success']);
    }

    public function test_preview_denies_users_without_edit_products(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->alias(fn($cap) => $cap !== 'edit_products');
        $this->halting_json();
        $_POST = ['product_id' => '5'];

        $this->preview();

        $this->assertFalse($this->response['success']);
        $this->assertSame('You do not have permission to preview sync.', $this->response['data']['message']);
    }

    public static function bad_product_id_provider(): array
    {
        return [
            'missing'  => [[]],
            'zero'     => [['product_id' => '0']],
            'garbage'  => [['product_id' => 'abc']],
            'array'    => [['product_id' => ['5']]],
        ];
    }

    #[DataProvider('bad_product_id_provider')]
    public function test_preview_rejects_missing_or_invalid_product_id(array $post): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $this->halting_json();
        $_POST = $post;

        $this->preview();

        $this->assertFalse($this->response['success']);
        $this->assertSame('Invalid product ID.', $this->response['data']['message']);
    }

    public function test_preview_checks_per_product_capability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        $asked = [];
        Functions\when('current_user_can')->alias(function ($cap, $id = null) use (&$asked) {
            $asked[] = [$cap, $id];
            return $cap === 'edit_products';
        });
        $this->halting_json();
        $_POST = ['product_id' => '77'];

        $this->preview();

        $this->assertSame([['edit_products', null], ['edit_post', 77]], $asked);
        $this->assertSame('You do not have permission to view this product.', $this->response['data']['message']);
    }

    public function test_preview_reports_missing_product(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(false);
        $this->halting_json();
        $_POST = ['product_id' => '77'];

        $this->preview();

        $this->assertSame('Product not found.', $this->response['data']['message']);
    }

    public function test_preview_reports_no_active_stores(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(\Mockery::mock('WC_Product'));
        $this->halting_json();
        $_POST = ['product_id' => '77'];

        $this->preview();

        $this->assertFalse($this->response['success']);
        $this->assertSame('No active stores configured.', $this->response['data']['message']);
    }

    public static function sync_type_provider(): array
    {
        return [
            'full'               => ['full_product', 'full_product'],
            'price qty cats'     => ['price_quantity_categories', 'price_quantity_categories'],
            'price qty'          => ['price_quantity', 'price_quantity'],
            'quantity'           => ['quantity', 'quantity'],
            'uppercase accepted' => ['QUANTITY', 'quantity'],
            'unknown'            => ['everything', 'full_product'],
            'injection'          => ['quantity"><script>', 'full_product'],
        ];
    }

    #[DataProvider('sync_type_provider')]
    public function test_preview_success_normalises_sync_type_and_renders_proceed_button(string $submitted, string $expected): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_sku')->andReturn('W-1');
        Functions\when('wc_get_product')->justReturn($product);
        // An inactive store is skipped by the previewer, so no remote API call is made.
        $this->setActiveStores(['https://paused.test' => ['status' => 'inactive']]);
        $this->halting_json();
        $_POST = ['product_id' => '77', 'sync_type' => $submitted];

        $this->preview();

        $this->assertTrue($this->response['success']);
        $html = $this->response['data']['html'];
        $this->assertStringStartsWith('<div class="wc-mss-preview-container"', $html);
        $this->assertStringContainsString('class="button button-primary wc-mss-execute-sync" data-product-id="77" data-sync-type="' . $expected . '"', $html);
        $this->assertStringContainsString('wc-mss-cancel-preview', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame($expected, $this->response['data']['result']['sync_type']);
        $this->assertSame(77, $this->response['data']['result']['product_id']);
        $this->assertSame('Widget', $this->response['data']['result']['product_name']);
        $this->assertSame([], $this->response['data']['result']['previews']);
    }
}
