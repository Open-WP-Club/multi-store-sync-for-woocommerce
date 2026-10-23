<?php
/**
 * Tests for the thin history-logging wrappers in WC_Multi_Store_Sync_Engine
 * (deletion, orphan deletion, restoration, variation deletion, status change).
 *
 * Each wrapper must map its arguments onto the right sync_type / product_name
 * and persist the row through WC_Multi_Store_Sync_History::log_sync().
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class SyncEngineHistoryLoggingTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Sync_Engine $engine;

    /** @var array<string, mixed>|null */
    private ?array $row = null;

    private int $insertCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['enabled' => true, 'auth_method' => 'query_string', 'match_products_by' => 'sku'];
            }
            return $default;
        });
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('sanitize_textarea_field')->returnArg();
        Functions\when('esc_url_raw')->returnArg();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 5;
        $this->row = null;
        $this->insertCount = 0;
        $wpdb->shouldReceive('insert')->andReturnUsing(function ($table, $data) {
            $this->row = ['table' => $table] + $data;
            $this->insertCount++;
            return 1;
        });

        $this->engine = new WC_Multi_Store_Sync_Engine();
    }

    private function invoke(string $method, array $args): void
    {
        (new ReflectionMethod($this->engine, $method))->invokeArgs($this->engine, $args);
    }

    public function test_log_deletion_history_maps_fields(): void
    {
        $this->invoke('log_deletion_history', [
            12, 'SKU-12', 'https://s.com', 'hook', 'success', 'Deleted OK', 345,
            microtime(true) - 0.2, memory_get_usage(true), 4,
        ]);

        $this->assertSame(1, $this->insertCount);
        $this->assertStringEndsWith('wc_mss_sync_history', $this->row['table']);
        $this->assertSame(12, $this->row['product_id']);
        $this->assertSame('SKU-12', $this->row['product_sku']);
        $this->assertSame('Deleted Product', $this->row['product_name']);
        $this->assertSame('delete_product', $this->row['sync_type']);
        $this->assertSame('hook', $this->row['sync_source']);
        $this->assertSame('success', $this->row['status']);
        $this->assertSame('Deleted OK', $this->row['message']);
        $this->assertSame(345, $this->row['remote_product_id']);
        $this->assertSame(4, $this->row['api_calls']);
        $this->assertGreaterThanOrEqual(200, $this->row['duration_ms']);
    }

    public function test_log_orphan_deletion_history_uses_orphan_type_and_name(): void
    {
        $this->invoke('log_orphan_deletion_history', [
            0, '', 'https://s.com', 'orphan_cleanup', 'error', 'Remote refused', null,
            microtime(true), memory_get_usage(true), 1,
        ]);

        $this->assertSame('delete_orphan', $this->row['sync_type']);
        $this->assertSame('Orphan Product', $this->row['product_name']);
        $this->assertSame('orphan_cleanup', $this->row['sync_source']);
        $this->assertSame('error', $this->row['status']);
        $this->assertSame('Remote refused', $this->row['message']);
        $this->assertNull($this->row['remote_product_id']);
    }

    public function test_log_restoration_history_uses_restore_type(): void
    {
        $this->invoke('log_restoration_history', [
            77, 'R-77', 'https://s.com', 'manual', 'success', 'Restored', 900,
            microtime(true), memory_get_usage(true), 2,
        ]);

        $this->assertSame('restore_product', $this->row['sync_type']);
        $this->assertSame('Restored Product', $this->row['product_name']);
        $this->assertSame(77, $this->row['product_id']);
        $this->assertSame('R-77', $this->row['product_sku']);
        $this->assertSame(900, $this->row['remote_product_id']);
    }

    public function test_log_variation_deletion_records_variation_not_parent_id(): void
    {
        // Signature: ..., $remote_parent_id, $remote_variation_id, ...
        $this->invoke('log_variation_deletion_history', [
            301, 'VAR-301', 'https://s.com', 'webhook', 'success', 'Variation removed', 555, 666,
            microtime(true), memory_get_usage(true), 3,
        ]);

        $this->assertSame('delete_variation', $this->row['sync_type']);
        $this->assertSame('Deleted Variation', $this->row['product_name']);
        $this->assertSame(301, $this->row['product_id']);
        $this->assertSame('VAR-301', $this->row['product_sku']);
        $this->assertSame(666, $this->row['remote_product_id'], 'Remote variation id, not the parent id, must be recorded');
        $this->assertSame(3, $this->row['api_calls']);
    }

    #[DataProvider('statuses')]
    public function test_log_status_change_history_appends_new_status_to_message(string $newStatus): void
    {
        $this->invoke('log_status_change_history', [
            9, 'S-9', $newStatus, 'https://s.com', 'manual', 'success', 'Status updated', 123,
            microtime(true), memory_get_usage(true), 1,
        ]);

        $this->assertSame('status_change', $this->row['sync_type']);
        $this->assertSame('Status Change', $this->row['product_name']);
        $this->assertSame('Status updated (to: ' . $newStatus . ')', $this->row['message']);
        $this->assertSame(123, $this->row['remote_product_id']);
    }

    /** @return array<string, array{string}> */
    public static function statuses(): array
    {
        return [
            'draft' => ['draft'],
            'publish' => ['publish'],
            'trash' => ['trash'],
            'private' => ['private'],
        ];
    }

    public function test_wrappers_compute_memory_and_duration_metrics(): void
    {
        $this->invoke('log_deletion_history', [
            1, 'A', 'https://s.com', 'manual', 'success', 'm', 1,
            microtime(true) - 1.0, memory_get_usage(true), 0,
        ]);

        $this->assertGreaterThanOrEqual(1000, $this->row['duration_ms']);
        $this->assertLessThan(10000, $this->row['duration_ms']);
        $this->assertEqualsWithDelta(0.0, (float) $this->row['memory_mb'], 50.0);
        $this->assertSame(0, $this->row['api_calls']);
    }
}
