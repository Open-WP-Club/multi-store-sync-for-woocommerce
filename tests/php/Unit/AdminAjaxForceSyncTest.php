<?php
/**
 * Tests for WC_Multi_Store_Admin_Ajax::ajax_force_sync_by_sku()
 *
 * Covers: empty input, single SKU, multiple SKUs, missing SKU, excluded product,
 *         mixed valid/invalid SKUs, legacy single-sku param fallback.
 */

use Brain\Monkey\Functions;

if (!class_exists('WP_Term')) {
    // Kept in sync with the other guarded `WP_Term` stubs in this suite
    // (ShippingClassSyncTest.php, CategorySyncTest.php, CategoryMapperTest.php)
    // — whichever test file's stub loads first wins the class_exists() race
    // for the whole PHPUnit process, so a property missing here causes a PHP
    // 8.5 "dynamic property" deprecation in unrelated files that expect it
    // (e.g. $term->description, $term->count).
    class WP_Term {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $taxonomy = 'product_cat';
        public string $description = '';
        public int $parent = 0;
        public int $count = 0;
    }
}

class AdminAjaxForceSyncTest extends WC_Multi_Store_TestCase
{
    private static bool $classLoaded = false;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->justReturn(false);

        // Ensure a fresh real queue_manager instance.
        WC_Multi_Store_Sync::instance()->queue_manager = new WC_Multi_Store_Queue_Manager();

        if (!self::$classLoaded) {
            // Stub all classes referenced via first-class callable syntax in the
            // WC_Multi_Store_Admin_Ajax constructor so that require_once succeeds
            // regardless of test execution order.
            // NOTE: WC_Multi_Store_Weekly_Sync_Verifier and WC_Multi_Store_Queue_Table
            // are both `require_once`'d as their REAL classes by tests/php/bootstrap.php
            // (for other test suites), so class_exists() below is already true for them
            // by the time this runs — the eval stub for those two entries is a no-op.
            // Tests exercising ajax_start_verification / ajax_cancel_verification /
            // ajax_queue_orphans_for_deletion / ajax_queue_retry_item therefore drive
            // the REAL static methods via their genuine WP function / $wpdb dependencies
            // (get_transient, $wpdb->get_row, etc.) rather than a configurable stub.
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
            require_once dirname(__DIR__, 3) . '/includes/class-admin-ajax.php';
            self::$classLoaded = true;
        }

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeAjax(): WC_Multi_Store_Admin_Ajax
    {
        // Skip the constructor — it wires up add_action() for many classes
        // that are not relevant to the method under test.
        return (new ReflectionClass('WC_Multi_Store_Admin_Ajax'))
            ->newInstanceWithoutConstructor();
    }

    private function captureJsonResponse(): array
    {
        $captured = ['success' => null, 'data' => null];

        Functions\when('wp_send_json_success')->alias(function ($data) use (&$captured) {
            $captured = ['success' => true, 'data' => $data];
        });

        Functions\when('wp_send_json_error')->alias(function ($data) use (&$captured) {
            $captured = ['success' => false, 'data' => $data];
        });

        return $captured;
    }

    private function mockWpdb(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy      = 'wp_term_taxonomy';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($query, ...$args) => $query);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_var')->andReturnUsing(
            fn($query) => str_contains((string) $query, 'GET_LOCK') ? 1 : null
        );
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->insert_id = 1;
    }

    /**
     * Like mockWpdb(), but with a configurable get_var() return value.
     * mockWpdb() pins get_var() to null and Mockery resolves unconstrained
     * shouldReceive() calls in first-match order, so a second shouldReceive()
     * on the same method can't override it — build the mock fresh instead.
     */
    private function mockWpdbWithGetVar(int|false $query_return, int $get_var_return): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts    = 'wp_posts';
        $wpdb->term_relationships = 'wp_term_relationships';
        $wpdb->term_taxonomy      = 'wp_term_taxonomy';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($query, ...$args) => $query);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_var')->andReturn($get_var_return);
        $wpdb->shouldReceive('query')->andReturn($query_return);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->insert_id = 1;
    }

    /** Build a mock WC_Product */
    private function mockProduct(int $id, string $name, string $sku): object
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn($id);
        $product->shouldReceive('get_name')->andReturn($name);
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_category_ids')->andReturn([]);
        $product->shouldReceive('get_tag_ids')->andReturn([]);
        return $product;
    }

    // ── empty / missing input ─────────────────────────────────────────────────

    public function test_empty_skus_array_returns_error(): void
    {
        $_POST = ['skus' => []];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('SKU', $error['message']);
    }

    public function test_missing_skus_and_sku_returns_error(): void
    {
        $_POST = [];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($error);
    }

    public function test_whitespace_only_skus_returns_error(): void
    {
        $_POST = ['skus' => ['   ', '  ']];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($error);
    }

    // ── single SKU ────────────────────────────────────────────────────────────

    public function test_single_valid_sku_queued_successfully(): void
    {
        $this->mockWpdb();

        $product = $this->mockProduct(10, 'Test Product', 'SKU-001');

        Functions\when('wc_get_product_id_by_sku')->alias(fn($s) => $s === 'SKU-001' ? 10 : 0);
        Functions\when('wc_get_product')->alias(fn($id) => $id === 10 ? $product : null);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $_POST = ['skus' => ['SKU-001']];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($success);
        $this->assertCount(1, $success['results']);
        $this->assertTrue($success['results'][0]['success']);
        $this->assertEquals('SKU-001', $success['results'][0]['sku']);
        $this->assertGreaterThan(0, $success['total_added']);
    }

    public function test_single_unknown_sku_returns_success_with_failure_result(): void
    {
        $this->mockWpdb();

        Functions\when('wc_get_product_id_by_sku')->justReturn(0);
        Functions\when('wc_get_product')->justReturn(null);

        // No active stores → nothing to queue
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') return [];
            if ($option === 'wc_multi_store_sync_settings') return ['enabled' => true];
            return false;
        });

        $_POST = ['skus' => ['UNKNOWN-SKU']];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($success);
        $this->assertCount(1, $success['results']);
        $this->assertTrue($success['results'][0]['success']);
        $this->assertTrue($success['results'][0]['deleted']);
        $this->assertEquals(0, $success['results'][0]['queued_count']);
        $this->assertStringContainsString('UNKNOWN-SKU', $success['results'][0]['message']);
    }

    // ── multiple SKUs ─────────────────────────────────────────────────────────

    public function test_multiple_valid_skus_all_queued(): void
    {
        $this->mockWpdb();

        $p1 = $this->mockProduct(11, 'Product One', 'SKU-A');
        $p2 = $this->mockProduct(12, 'Product Two', 'SKU-B');

        Functions\when('wc_get_product_id_by_sku')->alias(fn($s) => match($s) {
            'SKU-A' => 11,
            'SKU-B' => 12,
            default => 0,
        });
        Functions\when('wc_get_product')->alias(fn($id) => match($id) {
            11 => $p1,
            12 => $p2,
            default => null,
        });

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $_POST = ['skus' => ['SKU-A', 'SKU-B']];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($success);
        $this->assertCount(2, $success['results']);
        $this->assertTrue($success['results'][0]['success']);
        $this->assertTrue($success['results'][1]['success']);
        $this->assertEquals(2, $success['total_added']);
    }

    public function test_mixed_valid_and_invalid_skus_processes_all(): void
    {
        $this->mockWpdb();

        $product = $this->mockProduct(20, 'Real Product', 'REAL-SKU');

        Functions\when('wc_get_product_id_by_sku')->alias(fn($s) => $s === 'REAL-SKU' ? 20 : 0);
        Functions\when('wc_get_product')->alias(fn($id) => $id === 20 ? $product : null);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $_POST = ['skus' => ['REAL-SKU', 'FAKE-SKU']];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($success);
        $this->assertCount(2, $success['results']);

        $bySkuOk   = array_filter($success['results'], fn($r) => $r['sku'] === 'REAL-SKU');
        $bySkuFail = array_filter($success['results'], fn($r) => $r['sku'] === 'FAKE-SKU');

        $this->assertTrue(array_values($bySkuOk)[0]['success']);
        // FAKE-SKU not found locally → queued for deletion from child sites
        $this->assertTrue(array_values($bySkuFail)[0]['success']);
        $this->assertTrue(array_values($bySkuFail)[0]['deleted']);
        $this->assertArrayHasKey('queued_count', array_values($bySkuFail)[0]);
        $this->assertEquals(1, $success['total_added']);
    }

    // ── excluded product ──────────────────────────────────────────────────────

    public function test_sku_excluded_from_all_stores_reports_failure(): void
    {
        $product = $this->mockProduct(30, 'Excluded Product', 'EXCL-SKU');

        Functions\when('wc_get_product_id_by_sku')->justReturn(30);
        Functions\when('wc_get_product')->justReturn($product);

        // No active stores → queue_manager->add_product() returns 0.
        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return []; // no stores
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $_POST = ['skus' => ['EXCL-SKU']];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($success);
        $this->assertCount(1, $success['results']);
        $this->assertFalse($success['results'][0]['success']);
        $this->assertEquals(0, $success['total_added']);
    }

    // ── legacy single 'sku' param ─────────────────────────────────────────────

    public function test_legacy_sku_param_fallback_works(): void
    {
        $this->mockWpdb();

        $product = $this->mockProduct(40, 'Legacy Product', 'LEGACY-001');

        Functions\when('wc_get_product_id_by_sku')->alias(fn($s) => $s === 'LEGACY-001' ? 40 : 0);
        Functions\when('wc_get_product')->alias(fn($id) => $id === 40 ? $product : null);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        // Old-style single 'sku' key, no 'skus' array
        $_POST = ['sku' => 'LEGACY-001'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($success);
        $this->assertCount(1, $success['results']);
        $this->assertTrue($success['results'][0]['success']);
        $this->assertEquals('LEGACY-001', $success['results'][0]['sku']);
    }

    // ── ajax_force_sync_product ───────────────────────────────────────────────

    public function test_force_sync_product_missing_id_returns_error(): void
    {
        $_POST = [];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_product();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid', $error['message']);
    }

    public function test_force_sync_product_zero_id_returns_error(): void
    {
        $_POST = ['product_id' => '0'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_product();

        $this->assertNotNull($error);
    }

    public function test_force_sync_product_valid_id_with_store_queues_successfully(): void
    {
        $this->mockWpdb();

        $product = $this->mockProduct(99, 'Resync Product', 'RESYNC-001');
        Functions\when('wc_get_product')->alias(fn($id) => $id === 99 ? $product : null);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $_POST = ['product_id' => '99'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_product();

        $this->assertNotNull($success);
        $this->assertArrayHasKey('queued', $success);
        $this->assertGreaterThan(0, $success['queued']);
        $this->assertArrayHasKey('message', $success);
    }

    public function test_force_sync_product_with_no_active_stores_returns_error(): void
    {
        $this->mockWpdb();

        $product = $this->mockProduct(88, 'No-Store Product', 'NOSTORE-001');
        Functions\when('wc_get_product')->alias(fn($id) => $id === 88 ? $product : null);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') return [];
            if ($option === 'wc_multi_store_sync_settings') return ['enabled' => true];
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $_POST = ['product_id' => '88'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_product();

        $this->assertNotNull($error);
        $this->assertArrayHasKey('message', $error);
    }

    public function test_force_sync_product_excluded_from_all_stores_returns_error(): void
    {
        // Product is in category 5; the store excludes category 5.
        // Configure $wpdb so the exclusion-filter's term query returns cat 5.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix              = 'wp_';
        $wpdb->postmeta            = 'wp_postmeta';
        $wpdb->posts               = 'wp_posts';
        $wpdb->term_relationships  = 'wp_term_relationships';
        $wpdb->term_taxonomy       = 'wp_term_taxonomy';
        $wpdb->insert_id           = 1;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($query, ...$args) => $query);
        $catRow              = new stdClass();
        $catRow->term_id    = 5;
        $catRow->taxonomy   = 'product_cat';
        $wpdb->shouldReceive('get_results')->andReturn([$catRow]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('insert')->andReturn(1);

        $product = $this->mockProduct(77, 'Excluded Product', 'EXCL-PROD');
        Functions\when('wc_get_product')->alias(fn($id) => $id === 77 ? $product : null);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => [
                    'status'             => 'active',
                    'consumer_key'       => 'ck',
                    'consumer_secret'    => 'cs',
                    'exclude_categories' => [5],
                ]];
            }
            if ($option === 'wc_multi_store_sync_settings') return ['enabled' => true];
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $_POST = ['product_id' => '77'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_product();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('excluded', $error['message']);
    }

    // ── summary message ───────────────────────────────────────────────────────

    public function test_success_message_contains_queued_count(): void
    {
        $this->mockWpdb();

        $product = $this->mockProduct(50, 'A Product', 'MSG-SKU');

        Functions\when('wc_get_product_id_by_sku')->justReturn(50);
        Functions\when('wc_get_product')->justReturn($product);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        $_POST = ['skus' => ['MSG-SKU']];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_sku();

        $this->assertNotNull($success);
        $this->assertStringContainsString('1', $success['message']);
    }

    // ── ajax_delete_history ───────────────────────────────────────────────────

    public function test_delete_history_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $_POST = ['delete_type' => 'all'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Unauthorized', $error['message']);
    }

    public function test_delete_history_missing_delete_type_returns_error(): void
    {
        $_POST = [];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid deletion type', $error['message']);
    }

    public function test_delete_history_empty_delete_type_returns_error(): void
    {
        $_POST = ['delete_type' => ''];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid deletion type', $error['message']);
    }

    public function test_delete_history_unknown_type_returns_error(): void
    {
        $_POST = ['delete_type' => 'bogus'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Unknown deletion type', $error['message']);
    }

    public function test_delete_history_all_returns_success(): void
    {
        $this->mockWpdb();
        global $wpdb;
        // WC_Multi_Store_Sync_History::clear_all() -> TRUNCATE query, truthy result.
        $wpdb->shouldReceive('query')->andReturn(1);
        // WC_Multi_Store_Logger::write() lazily constructs the singleton, which
        // reads rotation settings via get_option().
        Functions\when('get_option')->justReturn([]);

        $_POST = ['delete_type' => 'all'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($success);
        $this->assertStringContainsStringIgnoringCase('All history records deleted', $success['message']);
        $this->assertEquals(0, $success['remaining']);
    }

    public function test_delete_history_all_failure_returns_error(): void
    {
        $this->mockWpdb();
        global $wpdb;
        // clear_all() -> TRUNCATE query returns false -> clear_all() returns false -> deleted = 0.
        $wpdb->shouldReceive('query')->andReturn(false);

        $_POST = ['delete_type' => 'all'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('No records deleted', $error['message']);
    }

    public function test_delete_history_errors_returns_success_with_count(): void
    {
        // delete_errors() -> delete_by_status('error') -> delete_by_criteria() -> DELETE query.
        // get_count() -> SELECT COUNT(*) via get_var() for the 'remaining' figure.
        $this->mockWpdbWithGetVar(query_return: 3, get_var_return: 7);
        Functions\when('get_option')->justReturn([]);

        $_POST = ['delete_type' => 'errors'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($success);
        $this->assertEquals(3, $success['deleted']);
        $this->assertEquals(7, $success['remaining']);
        $this->assertStringContainsString('3', $success['message']);
    }

    public function test_delete_history_successful_returns_success_with_count(): void
    {
        // delete_successful() -> delete_by_status('success') -> delete_by_criteria() -> DELETE query.
        $this->mockWpdbWithGetVar(query_return: 5, get_var_return: 2);
        Functions\when('get_option')->justReturn([]);

        $_POST = ['delete_type' => 'successful'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($success);
        $this->assertEquals(5, $success['deleted']);
        $this->assertEquals(2, $success['remaining']);
    }

    public function test_delete_history_older_than_uses_days_and_returns_success(): void
    {
        // cleanup_old_records($days) -> DELETE ... INTERVAL %d DAY.
        $this->mockWpdbWithGetVar(query_return: 4, get_var_return: 10);
        Functions\when('get_option')->justReturn([]);

        $_POST = ['delete_type' => 'older_than', 'days' => '15'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($success);
        $this->assertEquals(4, $success['deleted']);
        $this->assertEquals(10, $success['remaining']);
    }

    public function test_delete_history_by_store_with_url_returns_success(): void
    {
        // delete_by_store($store_url) -> delete_by_criteria(['store_url' => ...]) -> DELETE query.
        $this->mockWpdbWithGetVar(query_return: 6, get_var_return: 1);
        Functions\when('get_option')->justReturn([]);

        $_POST = ['delete_type' => 'by_store', 'store_url' => 'https://store1.com'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($success);
        $this->assertEquals(6, $success['deleted']);
        $this->assertEquals(1, $success['remaining']);
    }

    public function test_delete_history_by_store_with_empty_url_returns_no_records_deleted_error(): void
    {
        $this->mockWpdb();

        // Empty store_url -> match arm evaluates to 0 (not the WC_Multi_Store_Sync_History
        // call at all), which is the "0 deleted" branch (message: "No records deleted"),
        // distinct from the "unknown type" (null) branch.
        $_POST = ['delete_type' => 'by_store', 'store_url' => ''];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('No records deleted', $error['message']);
        $this->assertStringNotContainsStringIgnoringCase('Unknown deletion type', $error['message']);
    }

    public function test_delete_history_zero_matches_returns_no_records_deleted_error(): void
    {
        $this->mockWpdb();
        global $wpdb;
        // delete_errors() finds nothing to delete -> DELETE query returns 0.
        $wpdb->shouldReceive('query')->andReturn(0);

        $_POST = ['delete_type' => 'errors'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_delete_history();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('No records deleted', $error['message']);
    }

    // ── ajax_clear_sync_log ───────────────────────────────────────────────────

    private function useTempLogDir(): string
    {
        $log_file = WC_Log_Handler_File::get_log_file_path(WC_Multi_Store_Logger::LOG_HANDLE);
        @unlink($log_file);

        Functions\when('get_option')->justReturn([]);

        WC_Multi_Store_Logger::reset_instance();

        return dirname($log_file);
    }

    private function removeTempLogDir(string $dir): void
    {
        @unlink(WC_Log_Handler_File::get_log_file_path(WC_Multi_Store_Logger::LOG_HANDLE));
        WC_Multi_Store_Logger::reset_instance();
    }

    public function test_clear_sync_log_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_clear_sync_log();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_clear_sync_log_success_when_no_file(): void
    {
        $dir = $this->useTempLogDir();

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_clear_sync_log();

        $this->assertNotNull($success);
        $this->assertStringContainsStringIgnoringCase('Log cleared successfully', $success['message']);

        $this->removeTempLogDir($dir);
    }

    public function test_clear_sync_log_success_removes_existing_file(): void
    {
        $dir = $this->useTempLogDir();
        $log_file = WC_Log_Handler_File::get_log_file_path(WC_Multi_Store_Logger::LOG_HANDLE);
        file_put_contents($log_file, "2024-01-15T12:00:00+00:00 INFO hello\n");
        $this->assertFileExists($log_file);

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_clear_sync_log();

        $this->assertNotNull($success);
        $this->assertFileDoesNotExist($log_file);

        $this->removeTempLogDir($dir);
    }

    // ── ajax_clear_warnings_errors ────────────────────────────────────────────

    public function test_clear_warnings_errors_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_clear_warnings_errors();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_clear_warnings_errors_no_file_returns_zero_removed(): void
    {
        $dir = $this->useTempLogDir();

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_clear_warnings_errors();

        $this->assertNotNull($success);
        $this->assertEquals(0, $success['removed']);
        $this->assertStringContainsString('0', $success['message']);

        $this->removeTempLogDir($dir);
    }

    public function test_clear_warnings_errors_removes_only_warning_and_error_lines(): void
    {
        $dir = $this->useTempLogDir();
        $log_file = WC_Log_Handler_File::get_log_file_path(WC_Multi_Store_Logger::LOG_HANDLE);
        file_put_contents(
            $log_file,
            "2024-01-15T12:00:00+00:00 INFO all good\n"
            . "2024-01-15T12:01:00+00:00 WARNING careful\n"
            . "2024-01-15T12:02:00+00:00 ERROR boom\n"
            . "2024-01-15T12:03:00+00:00 INFO still good\n"
        );

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_clear_warnings_errors();

        $this->assertNotNull($success);
        $this->assertEquals(2, $success['removed']);
        $this->assertStringContainsString('2', $success['message']);

        $remaining = file_get_contents($log_file);
        $this->assertStringContainsString('INFO all good', $remaining);
        $this->assertStringContainsString('INFO still good', $remaining);
        $this->assertStringNotContainsString('WARNING', $remaining);
        $this->assertStringNotContainsString('ERROR', $remaining);

        $this->removeTempLogDir($dir);
    }

    // ── ajax_stop_scheduled_sync ──────────────────────────────────────────────

    public function test_stop_scheduled_sync_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);
        Functions\when('update_option')->justReturn(true);

        $this->makeAjax()->ajax_stop_scheduled_sync();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_stop_scheduled_sync_disables_scheduled_flag_and_returns_success(): void
    {
        Functions\when('get_option')->alias(fn($opt) => $opt === 'wc_multi_store_sync_scheduled'
            ? ['scheduled_sync_enabled' => true]
            : []);

        $updated_with = null;
        Functions\when('update_option')->alias(function ($opt, $value) use (&$updated_with) {
            if ($opt === 'wc_multi_store_sync_scheduled') {
                $updated_with = $value;
            }
            return true;
        });

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_stop_scheduled_sync();

        $this->assertNotNull($success);
        $this->assertStringContainsStringIgnoringCase('Scheduled sync has been stopped', $success['message']);
        $this->assertIsArray($updated_with);
        $this->assertFalse($updated_with['scheduled_sync_enabled']);
    }

    // ── ajax_stop_all_sync ────────────────────────────────────────────────────

    public function test_stop_all_sync_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_stop_all_sync();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_stop_all_sync_truncates_queue_and_returns_success(): void
    {
        $this->mockWpdb();
        global $wpdb;

        $truncated_table = null;
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$truncated_table) {
            if (str_contains($sql, 'TRUNCATE TABLE')) {
                $truncated_table = $sql;
            }
            return true;
        });

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_stop_all_sync();

        $this->assertNotNull($success);
        $this->assertStringContainsStringIgnoringCase('Queue cleared', $success['message']);
        $this->assertTrue($success['details']['queue_cleared']);
        // Action Scheduler is unavailable in the test environment (as_* functions
        // don't exist), so the 'actions_unscheduled' key is never set.
        $this->assertArrayNotHasKey('actions_unscheduled', $success['details']);
        $this->assertNotNull($truncated_table);
        $this->assertStringContainsString('wp_wc_mss_queue', $truncated_table);
    }

    // ── ajax_stop_weekly_verification ─────────────────────────────────────────

    public function test_stop_weekly_verification_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);
        Functions\when('update_option')->justReturn(true);

        $this->makeAjax()->ajax_stop_weekly_verification();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_stop_weekly_verification_disables_flag_and_returns_success(): void
    {
        Functions\when('get_option')->alias(fn($opt) => $opt === 'wc_multi_store_sync_weekly_verification'
            ? ['enabled' => true]
            : []);
        // cancel_async_verification() reads a transient; no running verification.
        Functions\when('get_transient')->justReturn(false);
        // unschedule_verification() unconditionally calls as_unschedule_all_actions(),
        // which doesn't exist as a WP/Action-Scheduler function in the test env.
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        $updated_with = null;
        Functions\when('update_option')->alias(function ($opt, $value) use (&$updated_with) {
            if ($opt === 'wc_multi_store_sync_weekly_verification') {
                $updated_with = $value;
            }
            return true;
        });

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_stop_weekly_verification();

        $this->assertNotNull($success);
        $this->assertStringContainsStringIgnoringCase('Weekly verification has been stopped', $success['message']);
        $this->assertIsArray($updated_with);
        $this->assertFalse($updated_with['enabled']);
    }

    // ── ajax_force_sync_by_category ───────────────────────────────────────────

    public function test_force_sync_by_category_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $_POST = ['category_id' => '5'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_force_sync_by_category_missing_category_id_returns_error(): void
    {
        $_POST = [];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('category is required', $error['message']);
    }

    public function test_force_sync_by_category_zero_category_id_returns_error(): void
    {
        $_POST = ['category_id' => '0'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('category is required', $error['message']);
    }

    public function test_force_sync_by_category_invalid_term_returns_error(): void
    {
        Functions\when('get_term')->justReturn(new WP_Error('invalid_term_id', 'Invalid term ID'));

        $_POST = ['category_id' => '999'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid category', $error['message']);
    }

    public function test_force_sync_by_category_false_term_returns_error(): void
    {
        Functions\when('get_term')->justReturn(false);

        $_POST = ['category_id' => '7'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid category', $error['message']);
    }

    public function test_force_sync_by_category_empty_products_returns_error_with_category_name(): void
    {
        $term = new WP_Term();
        $term->term_id = 12;
        $term->name = 'Empty Widgets';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);
        // WP_Query::$resultsQueue stays null -> stub WP_Query defaults to posts=[].

        $_POST = ['category_id' => '12'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Empty Widgets', $error['message']);
        $this->assertStringContainsStringIgnoringCase('No published products found', $error['message']);
    }

    public function test_force_sync_by_category_happy_path_queues_all_products(): void
    {
        $this->mockWpdb();

        $term = new WP_Term();
        $term->term_id = 20;
        $term->name = 'Gadgets';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);

        // Drive WC_Multi_Store_Category_Sync::get_product_ids()'s single internal
        // WP_Query call (per_page=5000 comfortably covers 3 IDs in one page).
        WP_Query::$resultsQueue = [[101, 102, 103]];

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));

        $_POST = ['category_id' => '20'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        WP_Query::$resultsQueue = null;

        $this->assertNotNull($success);
        $this->assertEquals(3, $success['products_found']);
        $this->assertEquals(3, $success['total_queued']);
        $this->assertEquals(0, $success['skipped']);
        $this->assertStringContainsString('Gadgets', $success['message']);
        $this->assertStringContainsString('3', $success['message']);
    }

    public function test_force_sync_by_category_no_active_stores_all_skipped(): void
    {
        $this->mockWpdb();

        $term = new WP_Term();
        $term->term_id = 21;
        $term->name = 'No Store Category';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);

        WP_Query::$resultsQueue = [[201, 202]];

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') return [];
            if ($option === 'wc_multi_store_sync_settings') return ['enabled' => true];
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $_POST = ['category_id' => '21'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_force_sync_by_category();

        WP_Query::$resultsQueue = null;

        $this->assertNotNull($success);
        $this->assertEquals(2, $success['products_found']);
        $this->assertEquals(0, $success['total_queued']);
        $this->assertEquals(2, $success['skipped']);
    }

    // ── ajax_sync_by_category ─────────────────────────────────────────────────

    public function test_sync_by_category_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $_POST = ['category_id' => '5'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_sync_by_category_missing_category_id_returns_error(): void
    {
        $_POST = [];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_sync_by_category();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid category', $error['message']);
    }

    public function test_sync_by_category_unknown_category_returns_error(): void
    {
        Functions\when('get_term')->justReturn(null);

        $_POST = ['category_id' => '404'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_sync_by_category();

        $this->assertNotNull($error);
        $this->assertEquals('Category not found', $error['message']);
    }

    public function test_sync_by_category_zero_products_returns_error(): void
    {
        $term = new WP_Term();
        $term->term_id = 30;
        $term->name = 'Barren Category';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);
        // WP_Query::$resultsQueue stays null -> stub WP_Query defaults to posts=[].

        $_POST = ['category_id' => '30'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_sync_by_category();

        // Aligned with ajax_force_sync_by_category(): an empty category is
        // reported as an error by both handlers now, even though the
        // underlying WC_Multi_Store_Category_Sync::queue_sync() itself still
        // returns a normal (non-error) zero-product result for other callers
        // like WP-CLI, where halting a batch on one empty category would be
        // the wrong behavior.
        $this->assertNotNull($error);
        $this->assertStringContainsString('Barren Category', $error['message']);
    }

    public function test_sync_by_category_happy_path_uses_queue_sync_and_reports_counts(): void
    {
        $this->mockWpdb();

        $term = new WP_Term();
        $term->term_id = 31;
        $term->name = 'Sync Category';

        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_children')->justReturn([]);

        WP_Query::$resultsQueue = [[301, 302]];

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true];
            }
            return false;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));

        $_POST = [
            'category_id'       => '31',
            'sync_type'         => 'price_quantity',
            'include_children'  => '0',
        ];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_sync_by_category();

        WP_Query::$resultsQueue = null;

        $this->assertNotNull($success);
        $this->assertEquals(2, $success['products']);
        // queue_sync() uses add_products() (plural, PRIORITY_LOW, trigger
        // 'category_sync') — a distinct code path from ajax_force_sync_by_category's
        // per-item add_product() loop with PRIORITY_HIGH / 'manual_test'.
        $this->assertGreaterThan(0, $success['queued']);
        $this->assertEquals('Sync Category', $success['category']);
        $this->assertStringContainsString('Sync Category', $success['message']);
    }

    // ── ajax_queue_retry_item ─────────────────────────────────────────────────

    public function test_queue_retry_item_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $_POST = ['item_id' => '5'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_queue_retry_item();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_queue_retry_item_missing_item_id_returns_error(): void
    {
        $_POST = [];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_queue_retry_item();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid item ID', $error['message']);
    }

    public function test_queue_retry_item_zero_item_id_returns_error(): void
    {
        $_POST = ['item_id' => '0'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_queue_retry_item();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Invalid item ID', $error['message']);
    }

    public function test_queue_retry_item_not_found_returns_error(): void
    {
        // WC_Multi_Store_Queue_Table::retry_item() is the REAL class (bootstrap.php
        // require_once's includes/queue-table.php unconditionally). mockWpdb()'s
        // default get_row() => null means "no failed item with this id" is found.
        $this->mockWpdb();

        $_POST = ['item_id' => '999'];

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_queue_retry_item();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('not found', $error['message']);
    }

    public function test_queue_retry_item_happy_path_returns_success(): void
    {
        // Built manually (not via mockWpdb()) because Mockery matches the FIRST
        // shouldReceive() defined for a method — mockWpdb()'s default get_row() => null
        // would otherwise win over a get_row() override added afterwards.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        // get_row() finds a failed queue item -> retry_item() proceeds to update().
        $wpdb->shouldReceive('get_row')->andReturn([
            'id'         => 42,
            'status'     => 'failed',
            'product_id' => 7,
            'store_url'  => 'https://store1.com',
        ]);
        $wpdb->shouldReceive('update')->andReturn(1);
        // retry_item() also tries to mark a corresponding DLQ entry via a raw query().
        $wpdb->shouldReceive('query')->andReturn(0);

        // WC_Multi_Store_Logger::write() (called at the end of retry_item()) reads
        // logging-related options via get_option() on first use.
        Functions\when('get_option')->justReturn(false);

        $_POST = ['item_id' => '42'];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_queue_retry_item();

        $this->assertNotNull($success);
        $this->assertStringContainsStringIgnoringCase('queued for retry', $success['message']);
    }

    // ── ajax_queue_orphans_for_deletion ───────────────────────────────────────

    public function test_queue_orphans_for_deletion_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_queue_orphans_for_deletion();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Unauthorized', $error['message']);
    }

    /**
     * WC_Multi_Store_Weekly_Sync_Verifier::get_orphan_products_from_report() (called
     * with no args by the handler) is the REAL class — bootstrap.php require_once's
     * includes/weekly-sync-verifier.php unconditionally, so it cannot be stubbed via
     * eval(). It reads the latest verification report from $wpdb: table_exists()
     * checks `get_var("SHOW TABLES LIKE ...")` equals the table name, then
     * get_latest_report() reads the row via get_row(). We drive both through $wpdb.
     *
     * @param array<int,array<string,mixed>> $discrepancies Per-product discrepancy
     *        lists (each entry needs type => 'orphan' plus store_url/remote_product_id).
     */
    private function mockLatestOrphanReport(array $discrepancies): void
    {
        // Built manually (not via mockWpdb()) because Mockery matches the FIRST
        // shouldReceive() defined for a method — mockWpdb()'s default get_var()/
        // get_row() => null would otherwise win over the overrides below.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($query, ...$args) => $query);

        // get_var() is used both by table_exists() (SHOW TABLES ...) and elsewhere;
        // discriminate on the literal SQL text so other get_var() callers still get null.
        $wpdb->shouldReceive('get_var')->andReturnUsing(
            fn($sql) => str_contains($sql, 'SHOW TABLES')
                ? 'wp_wc_multi_store_weekly_verifications'
                : (str_contains($sql, 'GET_LOCK') ? 1 : null)
        );

        $report_data = ['details' => []];
        foreach ($discrepancies as $i => $disc) {
            $report_data['details'][] = [
                'product_id'     => 900 + $i,
                'sku'            => $disc['sku'] ?? '',
                'name'           => $disc['name'] ?? 'Orphan Product',
                'discrepancies'  => [array_merge(['type' => 'orphan'], $disc)],
            ];
        }

        // get_row() is used both by get_latest_report() (raw SQL string, no prepare())
        // and by Queue_Table::add()'s "already queued?" check (via prepare(), which
        // mockWpdb() stubs to return ''). Discriminate on the first arg so add()'s
        // lookup still gets null ("not already queued") and only the literal
        // "weekly verification" report query gets the fake report row.
        $wpdb->shouldReceive('get_row')->andReturnUsing(
            fn($query) => str_contains((string) $query, 'weekly_verifications') ? [
                'id'          => 1,
                'started_at'  => '2024-01-15 00:00:00',
                'report_data' => $report_data,
            ] : null
        );

        // WC_Multi_Store_Logger::write() (called throughout the handler and
        // add_remote_orphan_deletion()) reads logging-related options via get_option().
        Functions\when('get_option')->justReturn(false);
        // get_latest_report() unconditionally passes report_data through
        // maybe_unserialize(), even though our fixture already stores it as an array.
        Functions\when('maybe_unserialize')->alias(fn($data) => is_string($data) ? @unserialize($data) : $data);
    }

    public function test_queue_orphans_for_deletion_empty_orphans_returns_error(): void
    {
        $this->mockLatestOrphanReport([]);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_queue_orphans_for_deletion();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('No orphan products found', $error['message']);
    }

    public function test_queue_orphans_for_deletion_happy_path_queues_all(): void
    {
        $this->mockLatestOrphanReport([
            ['store_url' => 'https://store1.com', 'remote_product_id' => 501],
            ['store_url' => 'https://store1.com', 'remote_product_id' => 502],
        ]);
        global $wpdb;
        // add_remote_orphan_deletion() -> Queue_Table::add(): no existing item, insert succeeds.
        $wpdb->shouldReceive('insert')->andReturn(1);

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_queue_orphans_for_deletion();

        $this->assertNotNull($success);
        $this->assertEquals(2, $success['queued']);
        $this->assertEquals(0, $success['failed']);
        $this->assertStringContainsString('2', $success['message']);
    }

    public function test_queue_orphans_for_deletion_partial_failure_reports_counts(): void
    {
        // Second orphan is missing both remote_product_id and sku -> add_remote_orphan_deletion()
        // bails out before touching $wpdb and returns false, so it's counted as failed
        // while the first (which has a remote_product_id) still queues.
        $this->mockLatestOrphanReport([
            ['store_url' => 'https://store1.com', 'remote_product_id' => 601],
            ['store_url' => 'https://store1.com'],
        ]);
        global $wpdb;
        $wpdb->shouldReceive('insert')->andReturn(1);

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_queue_orphans_for_deletion();

        $this->assertNotNull($success);
        $this->assertEquals(1, $success['queued']);
        $this->assertEquals(1, $success['failed']);
    }

    // ── ajax_start_verification ───────────────────────────────────────────────

    public function test_start_verification_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_start_verification();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_start_verification_success_result_returns_success(): void
    {
        // WC_Multi_Store_Weekly_Sync_Verifier::schedule_async_verification() is the
        // REAL class (loaded unconditionally by bootstrap.php). Drive its happy path:
        // no verification already running, Action Scheduler available, active
        // stores present, and a non-empty product list from its internal WP_Query.
        $this->mockWpdb();
        // WC_Multi_Store_Settings caches get_active_stores() in a static in-memory
        // property that survives across tests in the same PHP process — clear it so
        // an earlier test's (e.g. "no active stores") result can't leak in here.
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('as_schedule_single_action')->justReturn(123);
        // get_products_to_verify() logs a "filtered from N total" count via this.
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 3]);

        Functions\when('get_option')->alias(function ($option) {
            if ($option === 'wc_multi_store_sync_stores') {
                return ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']];
            }
            return false;
        });

        // get_products_to_verify() issues a single WP_Query for product IDs.
        WP_Query::$resultsQueue = [[701, 702, 703]];

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_start_verification();

        WP_Query::$resultsQueue = null;

        $this->assertNotNull($success);
        $this->assertTrue($success['success']);
        $this->assertEquals(3, $success['total_products']);
        $this->assertArrayHasKey('total_batches', $success);
        $this->assertStringContainsString('3', $success['message']);
    }

    public function test_start_verification_failure_result_returns_error(): void
    {
        // No active stores -> schedule_async_verification() returns success => false
        // with a real, predictable message — before it ever needs products/WP_Query.
        // get_settings() (called before the active-stores check) round-trips its
        // result through the cache manager, which needs set_transient() too.
        // Also clear WC_Multi_Store_Settings' static get_active_stores() cache (see
        // the happy-path test above for why this matters across the whole suite).
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('as_schedule_single_action')->justReturn(123);
        Functions\when('get_option')->alias(fn($option) => $option === 'wc_multi_store_sync_stores' ? [] : false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_start_verification();

        $this->assertNotNull($error);
        $this->assertFalse($error['success']);
        $this->assertStringContainsStringIgnoringCase('No active stores found', $error['message']);
    }

    // ── ajax_cancel_verification ──────────────────────────────────────────────

    public function test_cancel_verification_unauthorized_returns_error(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_cancel_verification();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('Permission denied', $error['message']);
    }

    public function test_cancel_verification_true_result_returns_success(): void
    {
        // WC_Multi_Store_Weekly_Sync_Verifier::cancel_async_verification() is the
        // REAL class (loaded unconditionally by bootstrap.php). It reads a transient
        // for the running progress and, if running, cancels it.
        Functions\when('get_transient')->justReturn(['status' => 'running']);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        // Once any earlier test in the suite mocks as_unschedule_all_actions() via
        // Brain Monkey, function_exists() reports it as permanently defined for the
        // rest of the process — so it must be re-mocked here too, or the real
        // cancel_async_verification() call throws "function not mocked".
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) { $success = $data; });
        Functions\when('wp_send_json_error')->justReturn(null);

        $this->makeAjax()->ajax_cancel_verification();

        $this->assertNotNull($success);
        $this->assertStringContainsStringIgnoringCase('Verification cancelled', $success['message']);
    }

    public function test_cancel_verification_false_result_returns_error(): void
    {
        // No running verification -> get_transient() returns false -> cancel_async_verification() returns false.
        Functions\when('get_transient')->justReturn(false);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) { $error = $data; });
        Functions\when('wp_send_json_success')->justReturn(null);

        $this->makeAjax()->ajax_cancel_verification();

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('No running verification to cancel', $error['message']);
    }
}
