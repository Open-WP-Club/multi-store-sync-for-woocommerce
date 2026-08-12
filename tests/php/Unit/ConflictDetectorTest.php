<?php
/**
 * Unit tests for WC_Multi_Store_Conflict_Detector
 *
 * Covers: check_for_conflicts, calculate_hash (indirectly), identify_changed_fields,
 *         resolve_conflict, resolve_all, get_conflicts,
 *         get_stats, maybe_migrate_from_options, store_hash, delete_hash.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test stub for the get_product() call check_for_conflicts() makes.
 */
class TestApiClientStub extends WC_Multi_Store_API_Client
{
    /** @var mixed */
    private mixed $nextReturn;

    public function __construct(mixed $returnValue)
    {
        // Skip parent constructor — we don't need real HTTP credentials
        $this->nextReturn = $returnValue;
    }

    public function get_product(int $product_id): array|\WP_Error
    {
        return $this->nextReturn;
    }
}

class ConflictDetectorTest extends WC_Multi_Store_TestCase
{
    // ─── Fixture helpers ──────────────────────────────────────────────────────

    /**
     * A minimal remote product array containing all tracked fields.
     */
    private function makeProduct(array $overrides = []): array
    {
        return array_merge([
            'id'                => 123,
            'name'              => 'Test Product',
            'description'       => 'A description',
            'short_description' => 'Short desc',
            'regular_price'     => '19.99',
            'sale_price'        => '',
            'sku'               => 'SKU-001',
            'stock_quantity'    => 10,
            'stock_status'      => 'instock',
            'status'            => 'publish',
            'weight'            => '0.5',
            'categories'        => [['id' => 5, 'name' => 'Gadgets']],
            // non-tracked field — must NOT affect the hash
            'meta_data'         => [['key' => '_custom', 'value' => 'anything']],
        ], $overrides);
    }

    /**
     * Calculate the expected hash the same way the class does.
     * wp_json_encode is aliased to json_encode via setUp mock.
     */
    private function expectedHash(array $product): string
    {
        $tracked_fields = [
            'name', 'description', 'short_description', 'regular_price',
            'sale_price', 'sku', 'stock_quantity', 'stock_status',
            'status', 'weight', 'categories',
        ];
        $fields = [];
        foreach ($tracked_fields as $field) {
            $fields[$field] = $product[$field] ?? null;
        }
        return md5(json_encode($fields));
    }

    /**
     * Build a full snapshot array from a product (only tracked fields).
     */
    private function makeSnapshot(array $product): array
    {
        $tracked = ['name','description','short_description','regular_price',
                    'sale_price','sku','stock_quantity','stock_status',
                    'status','weight','categories'];
        $snap = [];
        foreach ($tracked as $field) {
            $snap[$field] = $product[$field] ?? null;
        }
        return $snap;
    }

    // ─── setUp / tearDown ─────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Alias wp_json_encode → json_encode (Brain Monkey can't mock built-ins)
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');

        // Default stubs — individual tests override as needed
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    // ─── Smoke test ───────────────────────────────────────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Conflict_Detector'));
    }

    // =========================================================================
    // check_for_conflicts
    // =========================================================================

    public function test_check_returns_no_conflict_when_detection_disabled(): void
    {
        // Override the default get_option stub so settings come back disabled
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => false];
                }
                return $default ?? [];
            });

        // Use a stub whose get_product() would fail if called (it would throw)
        $client = new class extends WC_Multi_Store_API_Client {
            public function __construct() {} // skip parent
            public function get_product(int $product_id): array|\WP_Error
            {
                throw new \RuntimeException('client->get_product() must NOT be called when disabled');
            }
        };

        $result = WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertFalse($result['has_conflict']);
        $this->assertSame([], $result['changed_fields']);
        $this->assertNull($result['remote_data']);
    }

    public function test_check_first_visit_stores_hash_and_returns_no_conflict(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $product = $this->makeProduct();
        $client  = new TestApiClientStub($product);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        // get_stored_hash → no stored hash yet (first visit)
        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(null);

        // store_hash → INSERT … ON DUPLICATE KEY UPDATE
        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        $result = WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertFalse($result['has_conflict']);
        $this->assertSame([], $result['changed_fields']);
        $this->assertSame($product, $result['remote_data']);
    }

    public function test_check_no_conflict_when_hash_unchanged(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $product    = $this->makeProduct();
        $storedHash = $this->expectedHash($product);
        $client     = new TestApiClientStub($product);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        // Stored hash matches current hash → no conflict
        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn($storedHash);

        $result = WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertFalse($result['has_conflict']);
        $this->assertSame([], $result['changed_fields']);
    }

    public function test_check_detects_conflict_when_hash_changed(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $product   = $this->makeProduct(['name' => 'Modified Name']);
        $staleHash = md5('intentionally-stale-hash');
        $client    = new TestApiClientStub($product);

        // Snapshot records the OLD name so 'name' will appear in changed_fields
        $oldSnapshot = $this->makeSnapshot($this->makeProduct(['name' => 'Original Name']));

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        // First call: get_stored_hash → stale hash (triggers conflict)
        // Second call: get_stored_snapshot → old snapshot JSON
        $wpdb->shouldReceive('get_var')
            ->twice()
            ->andReturn($staleHash, json_encode($oldSnapshot));

        // log_conflict → insert
        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        $result = WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertTrue($result['has_conflict']);
        $this->assertNotEmpty($result['changed_fields']);
        $this->assertContains('name', $result['changed_fields']);
        $this->assertSame($product, $result['remote_data']);
    }

    public function test_check_detects_conflict_fires_conflict_detected_action(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $product   = $this->makeProduct(['name' => 'Modified Name']);
        $staleHash = md5('intentionally-stale-hash');
        $client    = new TestApiClientStub($product);

        $oldSnapshot = $this->makeSnapshot($this->makeProduct(['name' => 'Original Name']));

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        $wpdb->shouldReceive('get_var')
            ->twice()
            ->andReturn($staleHash, json_encode($oldSnapshot));

        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $triggeredArgs = null;
        Functions\when('do_action')
            ->alias(function ($hook, ...$args) use (&$triggeredArgs) {
                if ($hook === 'wc_mss_conflict_detected') {
                    $triggeredArgs = $args;
                }
            });

        WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertNotNull($triggeredArgs, 'wc_mss_conflict_detected was not fired');
        $this->assertSame(1, $triggeredArgs[0]);
        $this->assertSame(123, $triggeredArgs[1]);
        $this->assertSame('https://remote.store', $triggeredArgs[2]);
        $this->assertContains('name', $triggeredArgs[3]);
    }

    public function test_check_no_conflict_does_not_fire_conflict_detected_action(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $product    = $this->makeProduct();
        $storedHash = $this->expectedHash($product);
        $client     = new TestApiClientStub($product);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn($storedHash);

        $fired = false;
        Functions\when('do_action')
            ->alias(function ($hook) use (&$fired) {
                if ($hook === 'wc_mss_conflict_detected') {
                    $fired = true;
                }
            });

        WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertFalse($fired);
    }

    public function test_check_returns_no_conflict_on_api_error(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $wpError = new WP_Error('api_error', 'Connection refused');
        $client  = new TestApiClientStub($wpError);

        $result = WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertFalse($result['has_conflict']);
        $this->assertSame([], $result['changed_fields']);
        $this->assertNull($result['remote_data']);
    }

    // =========================================================================
    // get_settings
    // =========================================================================

    public function test_get_settings_defaults_when_option_missing(): void
    {
        Functions\when('get_option')->justReturn([]);

        $settings = WC_Multi_Store_Conflict_Detector::get_settings();

        $this->assertFalse($settings['enabled']);
        $this->assertSame('warn', $settings['action_on_conflict']);
        $this->assertTrue($settings['notify_email']);
    }

    public function test_get_settings_merges_partial_stored_values(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true, 'action_on_conflict' => 'block'];
                }
                return $default ?? [];
            });

        $settings = WC_Multi_Store_Conflict_Detector::get_settings();

        $this->assertTrue($settings['enabled']);
        $this->assertSame('block', $settings['action_on_conflict']);
        // notify_email wasn't stored — default must still be present
        $this->assertTrue($settings['notify_email']);
    }

    // =========================================================================
    // calculate_hash (tested indirectly via helper)
    // =========================================================================

    public function test_hash_only_covers_tracked_fields(): void
    {
        // Two products identical in tracked fields but different in meta_data
        $productA = $this->makeProduct(['meta_data' => [['key' => '_foo', 'value' => 'bar']]]);
        $productB = $this->makeProduct(['meta_data' => [['key' => '_foo', 'value' => 'CHANGED']]]);

        $hashA = $this->expectedHash($productA);
        $hashB = $this->expectedHash($productB);

        // Non-tracked field difference must NOT affect hash
        $this->assertSame($hashA, $hashB);

        // Tracked field difference MUST affect hash
        $productC = $this->makeProduct(['name' => 'Completely Different Name']);
        $hashC    = $this->expectedHash($productC);

        $this->assertNotSame($hashA, $hashC);
    }

    // =========================================================================
    // identify_changed_fields (via check_for_conflicts path)
    // =========================================================================

    public function test_identify_changed_fields_returns_unknown_without_snapshot(): void
    {
        // Trigger the conflict path where snapshot row is NULL
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $product   = $this->makeProduct();
        $staleHash = md5('stale');
        $client    = new TestApiClientStub($product);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        // First get_var → stored hash (stale), second get_var → snapshot = null
        $wpdb->shouldReceive('get_var')
            ->twice()
            ->andReturn($staleHash, null);

        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertTrue($result['has_conflict']);
        $this->assertSame(['unknown'], $result['changed_fields']);
    }

    public function test_identify_changed_fields_detects_name_change(): void
    {
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_settings') {
                    return ['enabled' => true];
                }
                return $default ?? [];
            });

        $currentProduct = $this->makeProduct(['name' => 'New Name']);
        $staleHash      = md5('stale-differs');
        $client         = new TestApiClientStub($currentProduct);

        // Snapshot has the OLD name — 'name' must appear in changed_fields
        $oldSnapshot = $this->makeSnapshot($this->makeProduct(['name' => 'Old Name']));
        $snapshotJson = json_encode($oldSnapshot);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        $wpdb->shouldReceive('get_var')
            ->twice()
            ->andReturn($staleHash, $snapshotJson);

        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');

        $this->assertTrue($result['has_conflict']);
        $this->assertContains('name', $result['changed_fields']);
        // Other tracked fields are identical — only 'name' should appear
        $this->assertNotContains('sku', $result['changed_fields']);
        $this->assertNotContains('description', $result['changed_fields']);
    }

    // =========================================================================
    // resolve_conflict
    // =========================================================================

    public function test_resolve_conflict_returns_true_on_success(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $result = WC_Multi_Store_Conflict_Detector::resolve_conflict(7, 'overwrite');

        $this->assertTrue($result);
    }

    public function test_resolve_conflict_returns_false_if_not_found(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        // 0 rows affected → conflict not found (already resolved or missing)
        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(0);

        $result = WC_Multi_Store_Conflict_Detector::resolve_conflict(999, 'overwrite');

        $this->assertFalse($result);
    }

    public function test_resolve_conflict_validates_resolved_0_condition(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $capturedWhere = null;
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) use (&$capturedWhere) {
                $capturedWhere = $where;
                return true;
            })
            ->andReturn(1);

        WC_Multi_Store_Conflict_Detector::resolve_conflict(42, 'keep_remote');

        $this->assertIsArray($capturedWhere);
        $this->assertArrayHasKey('resolved', $capturedWhere);
        $this->assertSame(0, $capturedWhere['resolved']);
        $this->assertArrayHasKey('id', $capturedWhere);
        $this->assertSame(42, $capturedWhere['id']);
    }

    // =========================================================================
    // resolve_all
    // =========================================================================

    public function test_resolve_all_without_store_url_runs_global_update(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $capturedSql = null;
        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(function ($sql, ...$args) use (&$capturedSql) {
                $capturedSql = $sql;
                return $sql;
            });

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(8);

        $result = WC_Multi_Store_Conflict_Detector::resolve_all('', 'overwrite');

        // Global update must NOT filter by store_url
        $this->assertStringNotContainsString('store_url', (string) $capturedSql);
        $this->assertSame(8, $result);
    }

    public function test_resolve_all_with_store_url_filters_by_store(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $capturedSql = null;
        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(function ($sql, ...$args) use (&$capturedSql) {
                $capturedSql = $sql;
                return $sql;
            });

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(3);

        WC_Multi_Store_Conflict_Detector::resolve_all('https://shop.example.com', 'overwrite');

        $this->assertStringContainsString('store_url', (string) $capturedSql);
    }

    public function test_resolve_all_returns_row_count(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(5);

        $result = WC_Multi_Store_Conflict_Detector::resolve_all();

        $this->assertSame(5, $result);
    }

    // =========================================================================
    // get_conflicts
    // =========================================================================

    public function test_get_conflicts_returns_empty_without_rows(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturn('SELECT * FROM wp_wc_mss_conflict_log ORDER BY detected_at DESC LIMIT 50 OFFSET 0');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $result = WC_Multi_Store_Conflict_Detector::get_conflicts();

        $this->assertSame([], $result);
    }

    public function test_get_conflicts_decodes_changed_fields_json(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturn('SELECT * FROM wp_wc_mss_conflict_log ORDER BY detected_at DESC LIMIT 50 OFFSET 0');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                [
                    'id'               => 1,
                    'local_product_id' => 10,
                    'store_url'        => 'https://store.com',
                    'changed_fields'   => '["name","sku"]',
                    'resolved'         => '0',
                    'detected_at'      => '2024-01-15 12:00:00',
                ],
            ]);

        $result = WC_Multi_Store_Conflict_Detector::get_conflicts();

        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]['changed_fields']);
        $this->assertContains('name', $result[0]['changed_fields']);
        $this->assertContains('sku', $result[0]['changed_fields']);
    }

    public function test_get_conflicts_casts_resolved_to_bool(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturn('SELECT * FROM wp_wc_mss_conflict_log ORDER BY detected_at DESC LIMIT 50 OFFSET 0');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                [
                    'id'               => 2,
                    'local_product_id' => 20,
                    'store_url'        => 'https://store.com',
                    'changed_fields'   => '[]',
                    'resolved'         => '1',
                    'detected_at'      => '2024-01-15 12:00:00',
                ],
            ]);

        $result = WC_Multi_Store_Conflict_Detector::get_conflicts();

        $this->assertCount(1, $result);
        $this->assertIsBool($result[0]['resolved']);
        $this->assertTrue($result[0]['resolved']);
    }

    // =========================================================================
    // get_stats
    // =========================================================================

    public function test_get_stats_returns_correct_structure(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        // Called three times: total, unresolved, stores_affected
        $wpdb->shouldReceive('get_var')
            ->times(3)
            ->andReturn('10', '3', '2');

        $stats = WC_Multi_Store_Conflict_Detector::get_stats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('unresolved', $stats);
        $this->assertArrayHasKey('resolved', $stats);
        $this->assertArrayHasKey('stores_affected', $stats);
    }

    public function test_get_stats_calculates_resolved_correctly(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        // total=10, unresolved=3, stores=4
        $wpdb->shouldReceive('get_var')
            ->times(3)
            ->andReturn('10', '3', '4');

        $stats = WC_Multi_Store_Conflict_Detector::get_stats();

        $this->assertSame(10, $stats['total']);
        $this->assertSame(3, $stats['unresolved']);
        $this->assertSame(7, $stats['resolved']);    // 10 - 3
        $this->assertSame(4, $stats['stores_affected']);
    }

    // =========================================================================
    // ajax_get_conflicts
    // =========================================================================

    public function test_ajax_get_conflicts_enriches_rows_with_product_data(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            [
                'id'               => 1,
                'local_product_id' => 10,
                'store_url'        => 'https://store.com',
                'changed_fields'   => '["name"]',
                'resolved'         => '0',
                'detected_at'      => '2024-01-15 12:00:00',
            ],
        ]);
        $wpdb->shouldReceive('get_var')->andReturn('1', '0', '1');

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_sku')->andReturn('WDG-1');
        Functions\when('wc_get_product')->justReturn($product);
        Functions\when('get_edit_post_link')->justReturn('https://site.test/wp-admin/post.php?post=10&action=edit');

        $sent = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent) {
            $sent = $data;
        });

        WC_Multi_Store_Conflict_Detector::ajax_get_conflicts();

        $this->assertSame('Widget', $sent['conflicts'][0]['product_name']);
        $this->assertSame('WDG-1', $sent['conflicts'][0]['product_sku']);
        $this->assertStringContainsString('post=10', $sent['conflicts'][0]['edit_url']);
        $this->assertArrayHasKey('stats', $sent);
    }

    public function test_ajax_get_conflicts_marks_missing_product(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            [
                'id'               => 1,
                'local_product_id' => 999,
                'store_url'        => 'https://store.com',
                'changed_fields'   => '["name"]',
                'resolved'         => '0',
                'detected_at'      => '2024-01-15 12:00:00',
            ],
        ]);
        $wpdb->shouldReceive('get_var')->andReturn('1', '1', '1');

        Functions\when('wc_get_product')->justReturn(false);

        $sent = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent) {
            $sent = $data;
        });

        WC_Multi_Store_Conflict_Detector::ajax_get_conflicts();

        $this->assertSame('(Product not found)', $sent['conflicts'][0]['product_name']);
        $this->assertSame('', $sent['conflicts'][0]['product_sku']);
        $this->assertSame('', $sent['conflicts'][0]['edit_url']);
    }

    public function test_ajax_get_conflicts_defaults_to_unresolved_only(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->justReturn(null);
        Functions\when('wc_get_product')->justReturn(false);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $capturedSql = null;
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$capturedSql) {
            $capturedSql = $sql;
            return $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn('0', '0', '0');

        WC_Multi_Store_Conflict_Detector::ajax_get_conflicts();

        $this->assertStringContainsString('resolved = 0', (string) $capturedSql);
    }

    public function test_ajax_get_conflicts_status_all_includes_resolved(): void
    {
        $_GET['status'] = 'all';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->justReturn(null);
        Functions\when('wc_get_product')->justReturn(false);

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $capturedSql = null;
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$capturedSql) {
            $capturedSql = $sql;
            return $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn('0', '0', '0');

        WC_Multi_Store_Conflict_Detector::ajax_get_conflicts();

        unset($_GET['status']);

        $this->assertStringNotContainsString('resolved = 0', (string) $capturedSql);
    }

    // =========================================================================
    // maybe_migrate_from_options
    // =========================================================================

    public function test_migration_skipped_if_already_done(): void
    {
        // Swap out the default justReturn stub to return true for the guard flag
        Functions\when('get_option')
            ->alias(function ($key, $default = null) {
                if ($key === 'wc_mss_conflict_db_migrated') {
                    return true;
                }
                return $default ?? [];
            });

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        // No DB activity must occur when migration is already flagged
        $wpdb->shouldNotReceive('query');
        $wpdb->shouldNotReceive('insert');
        $wpdb->shouldNotReceive('prepare');

        WC_Multi_Store_Conflict_Detector::maybe_migrate_from_options();

        // Mockery assertions checked in tearDown via \Mockery::close()
        $this->assertTrue(true); // explicit assertion to avoid risky test warning
    }

    public function test_migration_runs_once_and_marks_done(): void
    {
        $legacyHashes = [
            '42_abc123def456' => [
                'hash'      => md5('old-hash'),
                'snapshot'  => ['name' => 'Old Prod'],
                'timestamp' => 1700000000,
            ],
        ];

        Functions\when('get_option')
            ->alias(function ($key, $default = null) use ($legacyHashes) {
                if ($key === 'wc_mss_conflict_db_migrated') {
                    return false;
                }
                if ($key === WC_Multi_Store_Conflict_Detector::LEGACY_HASH_OPTION_KEY) {
                    return $legacyHashes;
                }
                if ($key === WC_Multi_Store_Conflict_Detector::LEGACY_CONFLICT_LOG_KEY) {
                    return []; // no old conflict log
                }
                return $default ?? [];
            });

        $markedDone = false;
        Functions\when('update_option')
            ->alias(function ($key, $value, $autoload = null) use (&$markedDone) {
                if ($key === 'wc_mss_conflict_db_migrated' && $value === true && $autoload === false) {
                    $markedDone = true;
                }
                return true;
            });

        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(fn($sql, ...$args) => $sql);

        // At least one INSERT IGNORE for the legacy hash row
        $wpdb->shouldReceive('query')
            ->atLeast()->once()
            ->andReturn(1);

        WC_Multi_Store_Conflict_Detector::maybe_migrate_from_options();

        $this->assertTrue($markedDone, 'update_option("wc_mss_conflict_db_migrated", true, false) was not called');
    }

    // =========================================================================
    // store_hash
    // =========================================================================

    public function test_store_hash_calls_insert_or_update_query(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $capturedSql = null;
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql, ...$args) use (&$capturedSql) {
                $capturedSql = $sql;
                return $sql;
            });

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        WC_Multi_Store_Conflict_Detector::store_hash(1, 'https://remote.store', $this->makeProduct());

        // Must use the INSERT … ON DUPLICATE KEY UPDATE idiom
        $this->assertStringContainsString('INSERT INTO', (string) $capturedSql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', (string) $capturedSql);
    }

    // =========================================================================
    // delete_hash
    // =========================================================================

    public function test_delete_hash_calls_wpdb_delete(): void
    {
        global $wpdb;
        $wpdb             = \Mockery::mock('wpdb');
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $capturedTable = null;
        $capturedWhere = null;

        $wpdb->shouldReceive('delete')
            ->once()
            ->withArgs(function ($table, $where) use (&$capturedTable, &$capturedWhere) {
                $capturedTable = $table;
                $capturedWhere = $where;
                return true;
            })
            ->andReturn(1);

        WC_Multi_Store_Conflict_Detector::delete_hash(42, 'https://remote.store');

        $this->assertSame('wp_wc_mss_conflict_hashes', $capturedTable);
        $this->assertArrayHasKey('local_product_id', $capturedWhere);
        $this->assertSame(42, $capturedWhere['local_product_id']);
        $this->assertArrayHasKey('store_url_hash', $capturedWhere);
        $this->assertSame(md5('https://remote.store'), $capturedWhere['store_url_hash']);
    }
}
