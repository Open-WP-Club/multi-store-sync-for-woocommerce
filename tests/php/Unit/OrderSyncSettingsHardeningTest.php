<?php
/**
 * Corrupted-option, boundary and trigger tests for WC_Multi_Store_Order_Sync.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class OrderSyncSettingsHardeningTest extends WC_Multi_Store_TestCase
{
    private mixed $orderSettings = [];
    /** @var array<int, array{0: array, 1: string, 2: int}> */
    private array $queued = [];
    /** @var array<string, array{0: mixed, 1: int}> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queued = [];
        $this->transients = [];
        $this->orderSettings = ['auto_sync_enabled' => true, 'sync_statuses' => ['processing', 'completed'], 'sync_on_new' => true];

        if (!class_exists('WC_Multi_Store_Order_Sync', false)) {
            require_once dirname(__DIR__, 3) . '/includes/order-sync.php';
        }

        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-03-10 12:00:00');
        Functions\when('get_option')->alias(fn($k, $d = false) => $k === 'wc_multi_store_sync_orders' ? $this->orderSettings : $d);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k][0] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v, $exp = 0) {
            $this->transients[$k] = [$v, $exp];
            return true;
        });
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });

        $queue = \Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('add_products')->andReturnUsing(function ($ids, $trigger, $prio) {
            $this->queued[] = [$ids, $trigger, $prio];
            return count($ids);
        });
        WC_Multi_Store_Sync::instance()->queue_manager = $queue;
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Sync::reset_instance();
        parent::tearDown();
    }

    private function order(array $productIds, array $variationIds = []): \Mockery\MockInterface
    {
        $items = [];
        foreach ($productIds as $i => $pid) {
            $item = \Mockery::mock('WC_Order_Item_Product');
            $item->shouldReceive('get_product_id')->andReturn($pid);
            $item->shouldReceive('get_variation_id')->andReturn($variationIds[$i] ?? 0);
            $items[] = $item;
        }
        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn($items);
        return $order;
    }

    // ── trigger gating ────────────────────────────────────────────

    public static function disabledProvider(): array
    {
        return [
            'missing' => [[]],
            'false' => [['auto_sync_enabled' => false]],
            'zero' => [['auto_sync_enabled' => 0]],
            'empty string' => [['auto_sync_enabled' => '']],
            'null' => [['auto_sync_enabled' => null]],
        ];
    }

    #[DataProvider('disabledProvider')]
    public function test_nothing_is_queued_unless_auto_sync_is_enabled(array $settings): void
    {
        $this->orderSettings = $settings + ['sync_statuses' => ['processing'], 'sync_on_new' => true];
        $sync = new WC_Multi_Store_Order_Sync();

        $sync->on_order_status_changed(1, 'pending', 'processing', $this->order([5]));
        $sync->on_new_order(1);

        $this->assertSame([], $this->queued);
    }

    public function test_only_configured_statuses_trigger_a_sync(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        foreach (['pending', 'cancelled', 'refunded', 'failed', 'wc-processing', 'Processing', ''] as $status) {
            $sync->on_order_status_changed(1, 'x', $status, $this->order([5]));
        }
        $this->assertSame([], $this->queued);

        $sync->on_order_status_changed(1, 'pending', 'completed', $this->order([5]));
        $this->assertCount(1, $this->queued);
    }

    public function test_default_statuses_apply_when_none_are_configured(): void
    {
        $this->orderSettings = ['auto_sync_enabled' => true];
        $sync = new WC_Multi_Store_Order_Sync();

        $sync->on_order_status_changed(1, 'pending', 'processing', $this->order([5]));
        $sync->on_order_status_changed(2, 'pending', 'on-hold', $this->order([6]));

        $this->assertCount(1, $this->queued);
    }

    public function test_new_order_requires_the_sync_on_new_flag(): void
    {
        $this->orderSettings['sync_on_new'] = false;
        Functions\expect('wc_get_order')->never();

        (new WC_Multi_Store_Order_Sync())->on_new_order(9);

        $this->assertSame([], $this->queued);
    }

    public function test_new_order_that_cannot_be_loaded_is_ignored(): void
    {
        Functions\when('wc_get_order')->justReturn(false);

        (new WC_Multi_Store_Order_Sync())->on_new_order(9);

        $this->assertSame([], $this->queued);
    }

    // ── product extraction ────────────────────────────────────────

    public function test_variation_ids_win_and_duplicates_collapse(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        $sync->on_order_status_changed(1, 'pending', 'processing', $this->order([10, 10, 20, 30], [101, 101, 0, 0]));

        $ids = array_values($this->queued[0][0]);
        sort($ids);
        $this->assertSame([20, 30, 101], $ids);
        $this->assertSame('order_1', $this->queued[0][1]);
        $this->assertSame(10, $this->queued[0][2], 'order syncs must be low priority');
    }

    public function test_items_without_any_product_reference_queue_nothing(): void
    {
        (new WC_Multi_Store_Order_Sync())->on_order_status_changed(1, 'pending', 'processing', $this->order([0, 0, 0]));

        $this->assertSame([], $this->queued);
    }

    // ── debouncing ────────────────────────────────────────────────

    public function test_nine_unique_products_queue_immediately_ten_are_debounced(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        $sync->on_order_status_changed(1, 'a', 'processing', $this->order(range(1, 9)));
        $this->assertCount(1, $this->queued);
        $this->assertSame([], $this->transients);

        $sync->on_order_status_changed(2, 'a', 'processing', $this->order(range(1, 10)));
        $this->assertCount(1, $this->queued, 'the large order must not be queued directly');
        $this->assertArrayHasKey('wc_mss_order_debounce_2', $this->transients);
    }

    public function test_duplicates_do_not_push_a_small_order_over_the_threshold(): void
    {
        (new WC_Multi_Store_Order_Sync())->on_order_status_changed(1, 'a', 'processing', $this->order(array_fill(0, 30, 5)));

        $this->assertCount(1, $this->queued);
        $this->assertSame([], $this->transients);
    }

    public function test_debounce_uses_the_configured_timeout_and_default_otherwise(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        $sync->on_order_status_changed(1, 'a', 'processing', $this->order(range(1, 10)));
        $this->assertSame(WC_Multi_Store_Order_Sync::DEBOUNCE_TIMEOUT, $this->transients['wc_mss_order_debounce_1'][1]);

        $this->orderSettings['debounce_timeout'] = '120';
        $sync->on_order_status_changed(2, 'a', 'processing', $this->order(range(1, 10)));
        $this->assertSame(120, $this->transients['wc_mss_order_debounce_2'][1]);
    }

    public function test_repeat_events_for_one_order_merge_without_losing_or_duplicating_products(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();

        $sync->on_order_status_changed(7, 'a', 'processing', $this->order(range(1, 10)));
        $sync->on_order_status_changed(7, 'processing', 'completed', $this->order(range(5, 14)));

        $stored = $this->transients['wc_mss_order_debounce_7'][0];
        $this->assertCount(14, array_unique($stored));
        $this->assertCount(14, $stored);
        $this->assertSame([], $this->queued);
    }

    public function test_process_debounced_order_queues_once_and_clears_the_transient(): void
    {
        $this->transients['wc_mss_order_debounce_7'] = [[1, 2, 3], 30];

        WC_Multi_Store_Order_Sync::process_debounced_order(7);
        WC_Multi_Store_Order_Sync::process_debounced_order(7);

        $this->assertCount(1, $this->queued, 'a second run (duplicate action) must find nothing');
        $this->assertSame('order_7_debounced', $this->queued[0][1]);
        $this->assertArrayNotHasKey('wc_mss_order_debounce_7', $this->transients);
    }

    public function test_process_debounced_order_ignores_expired_or_empty_transients(): void
    {
        WC_Multi_Store_Order_Sync::process_debounced_order(1);
        $this->transients['wc_mss_order_debounce_2'] = [[], 30];
        WC_Multi_Store_Order_Sync::process_debounced_order(2);

        $this->assertSame([], $this->queued);
    }

    public function test_debounce_keys_are_per_order(): void
    {
        $sync = new WC_Multi_Store_Order_Sync();
        $sync->on_order_status_changed(1, 'a', 'processing', $this->order(range(1, 10)));
        $sync->on_order_status_changed(2, 'a', 'processing', $this->order(range(101, 110)));

        WC_Multi_Store_Order_Sync::process_debounced_order(1);

        $this->assertArrayHasKey('wc_mss_order_debounce_2', $this->transients);
        $this->assertSame(10, count($this->queued[0][0]));
    }

    // ── regressions: corrupted options and strict status matching ──

    public static function corruptOrderOptionProvider(): array
    {
        return [['', 'string'], [false, 'false'], [null, 'null'], [5, 'int'], ['{"auto_sync_enabled":true}', 'json string']];
    }

    #[DataProvider('corruptOrderOptionProvider')]
    public function test_a_non_array_order_option_is_treated_as_disabled(mixed $corrupt, string $label): void
    {
        $this->orderSettings = $corrupt;
        $sync = new WC_Multi_Store_Order_Sync();

        $sync->on_new_order(1);
        $sync->on_order_status_changed(1, 'pending', 'processing', $this->order([5]));

        $this->assertSame([], $this->queued, $label);
    }

    public static function badStatusListProvider(): array
    {
        return [['processing'], [5], [true], [null]];
    }

    #[DataProvider('badStatusListProvider')]
    public function test_a_non_array_sync_statuses_value_does_not_crash_or_match_everything(mixed $statuses): void
    {
        $this->orderSettings = ['auto_sync_enabled' => true, 'sync_statuses' => $statuses];

        (new WC_Multi_Store_Order_Sync())->on_order_status_changed(1, 'pending', 'processing', $this->order([5]));

        $this->assertTrue(true);
        $this->assertLessThanOrEqual(1, count($this->queued));
    }

    public function test_status_matching_is_strict(): void
    {
        $this->orderSettings = ['auto_sync_enabled' => true, 'sync_statuses' => [0, true, 'Processing']];

        (new WC_Multi_Store_Order_Sync())->on_order_status_changed(1, 'pending', 'processing', $this->order([5]));

        $this->assertSame([], $this->queued, 'a string status must not match 0, true or a differently-cased entry');
    }
}
