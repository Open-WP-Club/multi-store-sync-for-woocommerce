<?php
/**
 * Tests for:
 *   1. scan_categories_for_store — category comparison logic in WC_Multi_Store_Admin_Ajax
 *   2. handle_save_settings      — delete_orphan_variations is persisted correctly
 */

use Brain\Monkey\Functions;

class CategoryScanAndSettingsSaveTest extends WC_Multi_Store_TestCase
{


    // ══════════════════════════════════════════════════════════════════
    // Part 1 — scan_categories_for_store
    // ══════════════════════════════════════════════════════════════════
    //
    // The method is the core of ajax_scan_categories:
    //   1. Calls $client->get_all_products() → builds SKU → remote-categories map.
    //   2. Runs SQL queries to get local products + their categories.
    //   3. Compares with array_diff, returns mismatches.
    //
    // We call it directly (it's protected) via a thin subclass so we can
    // inject a Mockery API client and a mocked $wpdb — no HTTP or DB needed.

    private function makeAjax(): WC_Multi_Store_Admin_Ajax
    {
        Functions\when('add_action')->justReturn(true);
        if (!class_exists('WC_Multi_Store_Admin_Ajax', false)) {
            // Stub dependency classes referenced via first-class callable syntax in the constructor.
            // PHP resolves these at instantiation time, so all referenced static methods must exist.
            // The method list was derived from: grep 'ClassName::method' class-admin-ajax.php
            $shared_stub = 'public static function ajax_export(){} public static function ajax_import(){} public static function ajax_sync_all(){} public static function ajax_toggle(){} public static function ajax_get_mappings(){} public static function ajax_save_mappings(){} public static function ajax_retry_item(){} public static function ajax_retry_all(){} public static function ajax_resolve_item(){} public static function ajax_clear_all(){} public static function ajax_get_items(){} public static function ajax_get_conflicts(){} public static function ajax_resolve_conflict(){} public static function ajax_resolve_all(){} public static function ajax_save(){} public static function ajax_apply(){} public static function ajax_delete(){} public static function ajax_list(){} public static function is_available(){return false;} public static function instance(){return new static();} public static function write(){} public static function get_logs(){return[];} public static function get_log(){return[];} public static function get_count(){return 0;} public static function get_stats(){return[];} public static function export_csv(){} public static function delete_all(){} public static function delete_by_status(){} public static function delete_by_type(){} public static function delete_older_than(){} public static function get_status_badge(){return "";} public static function get_type_label(){return "";} public static function cleanup_old_records(){} public static function clear_all(){} public static function delete_by_store(){} public static function delete_errors(){} public static function delete_successful(){} public static function cancel_async_verification(){} public static function get_orphan_products_from_report(){return[];} public static function get_verification_progress(){return[];} public static function schedule_async_verification(){} public static function unschedule_verification(){} public static function get_settings(){return[];} public static function get_store(){return null;}';
            foreach ([
                'WC_Multi_Store_Config_Manager',
                'WC_Multi_Store_Shipping_Class_Sync',
                'WC_Multi_Store_Coupon_Sync',
                'WC_Multi_Store_Downloadable_Files_Sync',
                'WC_Multi_Store_Category_Mapper',
                'WC_Multi_Store_Dead_Letter_Queue',
                'WC_Multi_Store_Sync_Profiles',
                'WC_Multi_Store_Conflict_Detector',
                'WC_Multi_Store_Attribute_Remapper',
                'WC_Multi_Store_Sync_History',
                'WC_Multi_Store_Action_Scheduler_Manager',
                'WC_Multi_Store_Weekly_Sync_Verifier',
                'WC_Multi_Store_Queue_Table',
                'WC_Multi_Store_Webhook_Logger',
            ] as $cls) {
                if (!class_exists($cls, false)) {
                    eval("class {$cls} { {$shared_stub} }");
                }
            }
            require_once WC_MSS_PLUGIN_DIR . 'includes/class-admin-ajax.php';
        }
        return new WC_Multi_Store_Admin_Ajax();
    }

    private function mockWpdb(array $local_rows, array $cat_rows, array $name_rows = []): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->posts             = 'wp_posts';
        $wpdb->postmeta          = 'wp_postmeta';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy     = 'wp_term_taxonomy';
        $wpdb->terms             = 'wp_terms';

        // Call order: local products → names → categories
        $wpdb->shouldReceive('get_results')
            ->andReturn($local_rows, $name_rows, $cat_rows);
    }

    private function callScan(
        WC_Multi_Store_Admin_Ajax $ajax,
        WC_Multi_Store_API_Client $client,
        string $match_by = 'slug'
    ): array|\WP_Error {
        // Access the protected method without Reflection by using a closure bind
        $fn = \Closure::bind(
            fn() => $this->scan_categories_for_store(
                $client,
                'https://child.example.com',
                ['name' => 'Child Store', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
                $match_by
            ),
            $ajax,
            WC_Multi_Store_Admin_Ajax::class
        );
        return $fn();
    }

    // ── 1a: identical categories → no mismatches ──────────────────────────────────

    public function test_scan_returns_no_mismatches_when_categories_match(): void
    {
        Functions\when('get_edit_post_link')->alias(fn($id) => 'https://example.com/?p=' . $id);

        $client = \Mockery::mock(WC_Multi_Store_API_Client::class);
        $client->shouldReceive('get_all_products')->once()->andReturn([
            ['sku' => 'SKU-001', 'categories' => [['slug' => 'shirts', 'id' => 10]]],
        ]);

        $this->mockWpdb(
            [['ID' => '1', 'sku' => 'SKU-001']],
            [['object_id' => '1', 'cat_value' => 'shirts']],
            [['ID' => '1', 'post_title' => 'T-Shirt']]
        );

        $result = $this->callScan($this->makeAjax(), $client);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['count']);
        $this->assertEmpty($result['items']);
    }

    // ── 1b: category missing on remote ───────────────────────────────────────────

    public function test_scan_detects_missing_category_on_remote(): void
    {
        Functions\when('get_edit_post_link')->alias(fn($id) => 'https://example.com/?p=' . $id);

        $client = \Mockery::mock(WC_Multi_Store_API_Client::class);
        $client->shouldReceive('get_all_products')->once()->andReturn([
            ['sku' => 'SKU-002', 'categories' => [['slug' => 'tops', 'id' => 5]]],
        ]);

        $this->mockWpdb(
            [['ID' => '2', 'sku' => 'SKU-002']],
            [
                ['object_id' => '2', 'cat_value' => 'tops'],
                ['object_id' => '2', 'cat_value' => 'sale'],
            ],
            [['ID' => '2', 'post_title' => 'Sale Top']]
        );

        $result = $this->callScan($this->makeAjax(), $client);

        $this->assertSame(1, $result['count']);
        $this->assertSame('SKU-002', $result['items'][0]['sku']);
        $this->assertContains('sale', $result['items'][0]['missing']);
        $this->assertEmpty($result['items'][0]['extra']);
    }

    // ── 1c: extra category on remote ─────────────────────────────────────────────

    public function test_scan_detects_extra_category_on_remote(): void
    {
        Functions\when('get_edit_post_link')->alias(fn($id) => 'https://example.com/?p=' . $id);

        $client = \Mockery::mock(WC_Multi_Store_API_Client::class);
        $client->shouldReceive('get_all_products')->once()->andReturn([
            ['sku' => 'SKU-003', 'categories' => [
                ['slug' => 'tops',      'id' => 5],
                ['slug' => 'clearance', 'id' => 99],
            ]],
        ]);

        $this->mockWpdb(
            [['ID' => '3', 'sku' => 'SKU-003']],
            [['object_id' => '3', 'cat_value' => 'tops']],
            [['ID' => '3', 'post_title' => 'Plain Top']]
        );

        $result = $this->callScan($this->makeAjax(), $client);

        $this->assertSame(1, $result['count']);
        $this->assertContains('clearance', $result['items'][0]['extra']);
        $this->assertEmpty($result['items'][0]['missing']);
    }

    // ── 1d: API error is propagated as WP_Error ───────────────────────────────────

    public function test_scan_returns_wp_error_when_api_fails(): void
    {
        $client = \Mockery::mock(WC_Multi_Store_API_Client::class);
        $client->shouldReceive('get_all_products')->once()
            ->andReturn(new WP_Error('http_error', 'Connection refused'));

        $result = $this->callScan($this->makeAjax(), $client);

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ── 1e: products missing on remote are skipped (not a category issue) ─────────

    public function test_scan_skips_products_not_present_on_remote(): void
    {
        Functions\when('get_edit_post_link')->alias(fn($id) => 'https://example.com/?p=' . $id);

        $client = \Mockery::mock(WC_Multi_Store_API_Client::class);
        // Remote store has no products at all
        $client->shouldReceive('get_all_products')->once()->andReturn([]);

        $this->mockWpdb(
            [['ID' => '4', 'sku' => 'SKU-004']],
            [['object_id' => '4', 'cat_value' => 'hats']],
            [['ID' => '4', 'post_title' => 'Hat']]
        );

        $result = $this->callScan($this->makeAjax(), $client);

        $this->assertSame(0, $result['count'], 'Missing remote products should not be reported as category mismatches');
    }

    // ══════════════════════════════════════════════════════════════════
    // Part 1b — ajax_get_remote_terms (category/attribute mapping UI)
    // ══════════════════════════════════════════════════════════════════

    public function test_ajax_get_remote_terms_returns_remote_categories(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($opt, $default = false) {
            return $opt === 'wc_multi_store_sync_stores'
                ? ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']]
                : $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([
                ['id' => 1, 'name' => 'Clothing', 'slug' => 'clothing', 'parent' => 0, 'count' => 5],
            ]),
        ]);

        $_POST['store_url'] = 'https://store1.com';
        $_POST['taxonomy'] = 'category';

        $sent = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent) {
            $sent = $data;
        });

        $this->makeAjax()->ajax_get_remote_terms();

        $this->assertCount(1, $sent['terms']);
        $this->assertSame('Clothing', $sent['terms'][0]['name']);
    }

    public function test_ajax_get_remote_terms_returns_remote_attributes(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(function ($opt, $default = false) {
            return $opt === 'wc_multi_store_sync_stores'
                ? ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']]
                : $default;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([
                ['id' => 1, 'name' => 'Color', 'slug' => 'color'],
            ]),
        ]);

        $_POST['store_url'] = 'https://store1.com';
        $_POST['taxonomy'] = 'attribute';

        $sent = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent) {
            $sent = $data;
        });

        $this->makeAjax()->ajax_get_remote_terms();

        $this->assertCount(1, $sent['terms']);
        $this->assertSame('Color', $sent['terms'][0]['name']);

        unset($_POST['store_url'], $_POST['taxonomy']);
    }

    public function test_ajax_get_remote_terms_requires_store_url(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) {
            $error = $data;
        });

        $this->makeAjax()->ajax_get_remote_terms();

        $this->assertNotNull($error);
    }

    public function test_ajax_get_remote_terms_errors_when_store_not_found(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn([]);

        $_POST['store_url'] = 'https://nonexistent.com';

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) {
            $error = $data;
        });

        $this->makeAjax()->ajax_get_remote_terms();

        $this->assertNotNull($error);
    }

    // ══════════════════════════════════════════════════════════════════
    // Part 2 — handle_save_settings persists delete_orphan_variations
    // ══════════════════════════════════════════════════════════════════
    //
    // Bug: the field was absent from the $settings array in handle_save_settings,
    // so update_all() overwrote it with the default (false) on every save.

    private function loadSettingsIntegration(): void
    {
        if (!class_exists('WC_Multi_Store_Settings_Integration', false)) {
            $content = file_get_contents(WC_MSS_PLUGIN_DIR . 'includes/wc-settings-integration.php');
            $content = preg_replace('/return new WC_Multi_Store_Settings_Integration\(\);/', '', $content);
            $content = preg_replace('/<\?php/', '', $content, 1);
            $content = preg_replace('/if \(!defined\(\'ABSPATH\'\)\) \{\s*exit;\s*\}/', '', $content);
            eval($content);
        }
    }

    private function callHandleSaveSettings(array $post, array $priorSettings = [], array $stores = []): ?array
    {
        $_POST = $post;

        $saved = null;
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('sanitize_url')->alias(fn($v) => $v);
        Functions\when('sanitize_email')->alias(fn($v) => $v);
        Functions\when('add_action')->justReturn(true);
        Functions\when('update_option')->alias(function ($key, $value) use (&$saved) {
            if ($key === 'wc_multi_store_sync_settings') {
                $saved = $value;
            }
            return true;
        });
        Functions\when('get_option')->alias(function ($key, $default = false) use ($priorSettings, $stores) {
            if ($key === 'wc_multi_store_sync_settings') {
                return $priorSettings;
            }
            if ($key === 'wc_multi_store_sync_stores') {
                return $stores;
            }
            return [];
        });
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('absint')->alias(fn($v) => (int) $v);
        Functions\when('wp_kses_post')->alias(fn($v) => $v);

        $this->loadSettingsIntegration();
        $integration = new WC_Multi_Store_Settings_Integration();
        $fn = \Closure::bind(
            fn() => $this->handle_save_settings(),
            $integration,
            WC_Multi_Store_Settings_Integration::class
        );
        $fn();

        return $saved;
    }

    public function test_save_settings_persists_delete_orphan_variations_when_checked(): void
    {
        $saved = $this->callHandleSaveSettings([
            'enabled'                  => '1',
            'sync_type_default'        => 'full_product',
            'auth_method'              => 'basic_auth',
            'match_products_by'        => 'sku',
            'deletion_mode'            => 'trash',
            'delete_orphan_variations' => '1',
            'webhook_secret'           => '',
        ]);

        $this->assertNotNull($saved);
        $this->assertTrue($saved['delete_orphan_variations']);
    }

    public function test_save_settings_persists_delete_orphan_variations_when_unchecked(): void
    {
        $saved = $this->callHandleSaveSettings([
            'enabled'           => '1',
            'sync_type_default' => 'full_product',
            'auth_method'       => 'basic_auth',
            'match_products_by' => 'sku',
            'deletion_mode'     => 'trash',
            'webhook_secret'    => '',
            // delete_orphan_variations absent = unchecked
        ]);

        $this->assertNotNull($saved);
        $this->assertFalse($saved['delete_orphan_variations']);
    }

    // ══════════════════════════════════════════════════════════════════
    // Part 3 — handle_save_settings warns on category/tag match-key changes
    // ══════════════════════════════════════════════════════════════════
    //
    // Changing category_match_by/category_match_mode after stores already
    // have categories/tags synced under the OLD rule can make the next sync
    // create duplicates on remote instead of reusing the existing terms —
    // ensure_terms_exist() only ever matches-or-creates, it doesn't know the
    // match key itself moved. This doesn't block the save, just warns.

    public function test_save_settings_warns_when_category_match_by_changes_with_existing_stores(): void
    {
        WC_Admin_Settings::reset();

        $this->callHandleSaveSettings(
            [
                'enabled' => '1',
                'sync_type_default' => 'full_product',
                'auth_method' => 'basic_auth',
                'match_products_by' => 'sku',
                'deletion_mode' => 'trash',
                'webhook_secret' => '',
                'category_match_by' => 'name', // changed from the stored 'slug'
            ],
            priorSettings: ['category_match_by' => 'slug', 'category_match_mode' => 'full_path'],
            stores: ['https://store1.com' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs']]
        );

        $messages = implode(' ', WC_Admin_Settings::get_messages());
        $this->assertStringContainsString('duplicate', $messages);
    }

    public function test_save_settings_no_warning_when_category_match_by_unchanged(): void
    {
        WC_Admin_Settings::reset();

        $this->callHandleSaveSettings(
            [
                'enabled' => '1',
                'sync_type_default' => 'full_product',
                'auth_method' => 'basic_auth',
                'match_products_by' => 'sku',
                'deletion_mode' => 'trash',
                'webhook_secret' => '',
                'category_match_by' => 'slug', // same as stored
            ],
            priorSettings: ['category_match_by' => 'slug', 'category_match_mode' => 'full_path'],
            stores: ['https://store1.com' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs']]
        );

        $messages = implode(' ', WC_Admin_Settings::get_messages());
        $this->assertStringNotContainsString('duplicate', $messages);
    }

    public function test_save_settings_no_warning_when_category_match_by_changes_but_no_stores_yet(): void
    {
        WC_Admin_Settings::reset();

        $this->callHandleSaveSettings(
            [
                'enabled' => '1',
                'sync_type_default' => 'full_product',
                'auth_method' => 'basic_auth',
                'match_products_by' => 'sku',
                'deletion_mode' => 'trash',
                'webhook_secret' => '',
                'category_match_by' => 'name',
            ],
            priorSettings: ['category_match_by' => 'slug', 'category_match_mode' => 'full_path'],
            stores: [] // nothing configured yet — nothing to reconcile
        );

        $messages = implode(' ', WC_Admin_Settings::get_messages());
        $this->assertStringNotContainsString('duplicate', $messages);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        WC_Admin_Settings::reset();
        parent::tearDown();
    }
}
