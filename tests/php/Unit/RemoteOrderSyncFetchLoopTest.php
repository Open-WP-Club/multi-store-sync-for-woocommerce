<?php
/**
 * Tests for WC_Multi_Store_Remote_Order_Sync::sync_store_orders() — the
 * page-by-page remote order fetch-and-store loop. RemoteOrderSyncTest.php
 * already covers prepare_order_data()/convert_date() (pure helpers) and
 * sync_all_stores()'s trivial empty-store-list path, but nothing exercises
 * the actual API-fetch + WC_Multi_Store_Remote_Order_Table read/write loop.
 * This file fills that gap using real wp_remote_get() stubs (matching the
 * WC_Multi_Store_API_Client HTTP pattern used elsewhere, e.g.
 * SyncEngineFunctionalTest.php) and a Mockery $wpdb (matching the pattern in
 * RemoteOrderTableTest.php, since WC_Multi_Store_Remote_Order_Table's methods
 * are static and read/write $wpdb directly — not injectable).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class RemoteOrderSyncFetchLoopTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteOrderMocks();
    }

    protected function setUpRemoteOrderMocks(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return ['auth_method' => 'query_string'];
            }
            return $default;
        });
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_parse_args')->alias(function ($args, $defaults) {
            return array_merge($defaults, (array) $args);
        });
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 2 && is_array($args[0])) {
                return $args[1] . '?' . http_build_query($args[0]);
            }
            return $args[count($args) - 1] ?? '';
        });
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
    }

    private function mockWpdb(): \Mockery\MockInterface
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$params) {
            return $sql; // content doesn't matter, we branch on shouldReceive() args elsewhere
        });
        $wpdb->insert_id = 1;

        return $wpdb;
    }

    private function makeOrder(int $id, string $status = 'processing', string $total = '50.00'): array
    {
        return [
            'id' => $id,
            'number' => (string) (1000 + $id),
            'status' => $status,
            'currency' => 'USD',
            'total' => $total,
            'billing' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
            'line_items' => [],
            'date_created' => '2024-01-15T10:00:00',
        ];
    }

    // ── happy path: one page, all new orders → all inserted ─────────

    public function test_one_page_of_new_orders_are_all_inserted(): void
    {
        $wpdb = $this->mockWpdb();

        // order_exists() -> get_var(): no existing order for any of these.
        $wpdb->shouldReceive('get_var')->andReturn(null);
        // insert() must be called once per new order.
        $wpdb->shouldReceive('insert')->times(3)->andReturn(1);

        // Single page of 3 orders, fewer than per_page (100) -> loop stops.
        $orders = [$this->makeOrder(1), $this->makeOrder(2), $this->makeOrder(3)];
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode($orders),
        ]);

        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $result = $sync->sync_store_orders('https://store1.com', [
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['synced']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['errors']);
    }

    // ── pagination stopping condition ────────────────────────────────

    public function test_pagination_stops_when_page_returns_fewer_than_per_page(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('insert')->andReturn(1);

        // Page 1: full page of 2 (per_page=2) -> triggers another fetch.
        // Page 2: 1 order (< per_page) -> loop stops after processing it.
        $call_count = 0;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$call_count) {
            $call_count++;
            if ($call_count === 1) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([$this->makeOrder(1), $this->makeOrder(2)]),
                ];
            }
            return [
                'response' => ['code' => 200],
                'body' => json_encode([$this->makeOrder(3)]),
            ];
        });

        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $result = $sync->sync_store_orders('https://store1.com', [
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ], ['per_page' => 2]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['synced']);
        $this->assertSame(2, $call_count, 'Must fetch exactly 2 pages: a full page then a partial page that stops the loop');
    }

    public function test_pagination_stops_when_empty_page_returned(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('insert')->andReturn(1);

        $call_count = 0;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$call_count) {
            $call_count++;
            if ($call_count === 1) {
                // Full page (2 == per_page) -> would normally continue...
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([$this->makeOrder(1), $this->makeOrder(2)]),
                ];
            }
            // ...but the next page comes back empty -> loop must stop.
            return [
                'response' => ['code' => 200],
                'body' => json_encode([]),
            ];
        });

        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $result = $sync->sync_store_orders('https://store1.com', [
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ], ['per_page' => 2]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['synced']);
        $this->assertSame(2, $call_count);
    }

    // ── existing order (by remote_order_id + store_url) is updated ──

    public function test_existing_order_is_updated_not_duplicated(): void
    {
        $wpdb = $this->mockWpdb();

        // order_exists() -> get_var(): order #1 already exists locally as id=42.
        $wpdb->shouldReceive('get_var')->andReturn('42');

        // process_order() calls WC_Multi_Store_Remote_Order_Table::get(42) to
        // compare sync_hash. Compute the hash prepare_order_data() would
        // produce for this exact order so it does NOT match (forcing 'updated'
        // rather than 'skipped') by using a different stored total.
        $existing_row = (object) [
            'id' => 42,
            'sync_hash' => 'stale-hash-does-not-match-anything',
            'billing_address' => '{}',
            'shipping_address' => '{}',
            'line_items' => '[]',
            'order_meta' => 'null',
        ];
        $wpdb->shouldReceive('get_row')->andReturn($existing_row);
        $wpdb->shouldReceive('get_results')->andReturn([]); // get_order_items()

        // update() must be called for the existing order; insert() must NOT
        // be called (no duplicate row created).
        $wpdb->shouldReceive('update')->once()->andReturn(1);
        $wpdb->shouldNotReceive('insert');

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([$this->makeOrder(1, 'completed', '75.00')]),
        ]);

        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $result = $sync->sync_store_orders('https://store1.com', [
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['updated']);
    }

    public function test_existing_order_with_unchanged_hash_is_skipped(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_var')->andReturn('42');

        // Compute the exact sync_hash prepare_order_data() will produce for
        // this order, so get() returns a "no changes" match -> process_order()
        // must return 'skipped' and never call update() or insert().
        $sync = new WC_Multi_Store_Remote_Order_Sync();
        $order_data = $this->makeOrder(1, 'completed', '75.00');
        $prepare = new ReflectionMethod($sync, 'prepare_order_data');
        $prepared = $prepare->invoke($sync, $order_data, 'https://store1.com');

        $existing_row = (object) [
            'id' => 42,
            'sync_hash' => $prepared['sync_hash'],
            'billing_address' => '{}',
            'shipping_address' => '{}',
            'line_items' => '[]',
            'order_meta' => 'null',
        ];
        $wpdb->shouldReceive('get_row')->andReturn($existing_row);
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $wpdb->shouldNotReceive('update');
        $wpdb->shouldNotReceive('insert');

        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => json_encode([$order_data]),
        ]);

        $result = $sync->sync_store_orders('https://store1.com', [
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['synced']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['errors']);
    }
}
