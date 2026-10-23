<?php
/**
 * Tests for private WC_Multi_Store_Sync_Engine helpers that had no direct coverage:
 * initialize_sync_context, log_operation_history / log_sync_history,
 * maybe_purge_remote_cache and queue_full_sync_for_missing_product.
 *
 * The engine builds its collaborators internally, so the private methods are
 * reached through reflection and all I/O is stubbed with Brain Monkey / Mockery.
 */

use Brain\Monkey\Functions;

class SyncEngineInternalsTest extends WC_Multi_Store_TestCase
{
    // Public IP literals: is_safe_remote_url() then needs no DNS lookup.
    private const SAFE_PURGE = 'http://93.184.216.34/purge';

    private WC_Multi_Store_Sync_Engine $engine;

    /** @var list<array{string, string, array}> */
    private array $remoteCalls = [];

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
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_parse_url')->alias(fn($url, $component = -1) => parse_url($url, $component));

        $this->remoteCalls = [];
        Functions\when('wp_remote_get')->alias(function ($url, $args = []) {
            $this->remoteCalls[] = ['GET', $url, $args];
            return [];
        });
        Functions\when('wp_remote_post')->alias(function ($url, $args = []) {
            $this->remoteCalls[] = ['POST', $url, $args];
            return [];
        });

        if (!defined('WC_MSS_VERSION')) {
            define('WC_MSS_VERSION', 'test');
        }

        $this->engine = new WC_Multi_Store_Sync_Engine();
    }

    private function invoke(string $method, array $args = []): mixed
    {
        $m = new ReflectionMethod($this->engine, $method);
        return $m->invokeArgs($this->engine, $args);
    }

    private function product(int $id = 42, string $sku = 'SKU-42', string $name = 'Widget'): \Mockery\MockInterface
    {
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('get_id')->andReturn($id);
        $p->shouldReceive('get_sku')->andReturn($sku);
        $p->shouldReceive('get_name')->andReturn($name);
        return $p;
    }

    // ---- initialize_sync_context -----------------------------------------

    public function test_initialize_sync_context_returns_tracking_state_and_client(): void
    {
        $before = microtime(true);
        $ctx = $this->invoke('initialize_sync_context', ['https://store1.com', ['consumer_key' => 'ck', 'consumer_secret' => 'cs']]);

        $this->assertSame(['start_time', 'start_memory', 'api_calls', 'api', 'settings'], array_keys($ctx));
        $this->assertGreaterThanOrEqual($before, $ctx['start_time']);
        $this->assertSame(0, $ctx['api_calls']);
        $this->assertGreaterThan(0, $ctx['start_memory']);
        $this->assertInstanceOf(WC_Multi_Store_API_Client::class, $ctx['api']);
        $this->assertTrue($ctx['settings']['enabled']);
    }

    // ---- maybe_purge_remote_cache ----------------------------------------

    public function test_purge_is_noop_without_configured_url(): void
    {
        $this->invoke('maybe_purge_remote_cache', [$this->product(), 'https://s.com', [], 5]);
        $this->invoke('maybe_purge_remote_cache', [$this->product(), 'https://s.com', ['cache_purge_url' => '   '], 5]);

        $this->assertSame([], $this->remoteCalls);
    }

    public function test_purge_substitutes_placeholders_and_uses_get_by_default(): void
    {
        $cfg = ['cache_purge_url' => 'http://93.184.216.34/p?id={product_id}&sku={sku}&r={remote_id}'];

        $this->invoke('maybe_purge_remote_cache', [$this->product(42, 'A B/1'), 'https://s.com', $cfg, 99]);

        $this->assertCount(1, $this->remoteCalls);
        [$method, $url, $args] = $this->remoteCalls[0];
        $this->assertSame('GET', $method);
        $this->assertSame('http://93.184.216.34/p?id=42&sku=A+B%2F1&r=99', $url);
        $this->assertFalse($args['blocking'], 'Purge must never block the sync');
        $this->assertSame(3, $args['timeout']);
        $this->assertSame('WC-Multi-Store-Sync/' . WC_MSS_VERSION, $args['headers']['User-Agent']);
    }

    public function test_purge_uses_post_when_configured_case_insensitively(): void
    {
        $cfg = ['cache_purge_url' => self::SAFE_PURGE, 'cache_purge_method' => 'post'];

        $this->invoke('maybe_purge_remote_cache', [$this->product(), 'https://s.com', $cfg, null]);

        $this->assertSame('POST', $this->remoteCalls[0][0]);
    }

    public function test_purge_null_remote_id_becomes_zero(): void
    {
        $cfg = ['cache_purge_url' => 'http://93.184.216.34/p/{remote_id}'];

        $this->invoke('maybe_purge_remote_cache', [$this->product(), 'https://s.com', $cfg, null]);

        $this->assertSame('http://93.184.216.34/p/0', $this->remoteCalls[0][1]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePurgeUrls(): array
    {
        return [
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://93.184.216.34/x'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data'],
            'ipv6 link-local' => ['http://[fe80::1]/purge'],
            'no host' => ['http:///purge'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafePurgeUrls')]
    public function test_purge_refuses_unsafe_urls(string $url): void
    {
        $this->invoke('maybe_purge_remote_cache', [$this->product(), 'https://s.com', ['cache_purge_url' => $url], 1]);

        $this->assertSame([], $this->remoteCalls, "Must not request $url");
    }

    public function test_purge_allows_private_lan_hosts_by_design(): void
    {
        // Only link-local (cloud metadata) targets are blocked; stores on a LAN are legitimate.
        $this->invoke('maybe_purge_remote_cache', [$this->product(), 'https://s.com', ['cache_purge_url' => 'http://10.0.0.5/purge'], 1]);

        $this->assertCount(1, $this->remoteCalls);
    }

    // ---- log_operation_history / log_sync_history -------------------------

    private function historyDb(?array &$captured): \Mockery\MockInterface
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 7;
        $wpdb->shouldReceive('insert')->andReturnUsing(function ($table, $data) use (&$captured) {
            $captured = ['table' => $table] + $data;
            return 1;
        });
        return $wpdb;
    }

    public function test_log_sync_history_records_product_details_and_metrics(): void
    {
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('sanitize_textarea_field')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        $row = null;
        $this->historyDb($row);

        $this->invoke('log_sync_history', [
            $this->product(), 'https://s.com', 'full_product', 'manual', 'success', 'ok', 99,
            microtime(true) - 0.5, memory_get_usage(true), 3,
        ]);

        $this->assertSame(42, $row['product_id']);
        $this->assertSame('SKU-42', $row['product_sku']);
        $this->assertSame('Widget', $row['product_name']);
        $this->assertSame('https://s.com', $row['store_url']);
        $this->assertSame('full_product', $row['sync_type']);
        $this->assertSame('manual', $row['sync_source']);
        $this->assertSame('success', $row['status']);
        $this->assertSame(99, $row['remote_product_id']);
        $this->assertSame(3, $row['api_calls']);
        $this->assertGreaterThanOrEqual(500, $row['duration_ms']);
        $this->assertLessThan(5000, $row['duration_ms']);
    }

    public function test_log_operation_history_falls_back_to_explicit_fields_without_product(): void
    {
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('sanitize_textarea_field')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        $row = null;
        $this->historyDb($row);

        $this->invoke('log_operation_history', [[
            'product_id' => 5, 'product_sku' => 'GONE', 'product_name' => 'Deleted thing',
            'store_url' => 'https://s.com', 'sync_type' => 'delete', 'sync_source' => 'hook',
            'status' => 'error', 'message' => 'boom',
            'start_time' => microtime(true), 'start_memory' => memory_get_usage(true), 'api_calls' => 1,
        ]]);

        $this->assertSame(5, $row['product_id']);
        $this->assertSame('GONE', $row['product_sku']);
        $this->assertSame('Deleted thing', $row['product_name']);
        $this->assertSame('error', $row['status']);
        $this->assertNull($row['remote_product_id']);
    }

    // ---- queue_full_sync_for_missing_product ------------------------------

    public function test_queue_full_sync_inserts_low_priority_item(): void
    {
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 77;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $inserted = null;
        $wpdb->shouldReceive('insert')->andReturnUsing(function ($table, $data) use (&$inserted) {
            $inserted = $data;
            return 1;
        });
        $wpdb->shouldReceive('query')->andReturn(1);

        $ok = $this->invoke('queue_full_sync_for_missing_product', [$this->product(), 'https://s.com', 'hook']);

        $this->assertTrue($ok);
        $this->assertSame(42, $inserted['product_id']);
        $this->assertSame('SKU-42', $inserted['product_sku']);
        $this->assertSame('full_product', $inserted['sync_type']);
        $this->assertSame(WC_Multi_Store_Queue_Manager::PRIORITY_LOW, $inserted['priority']);
        $this->assertSame('auto_create_missing_hook', $inserted['source']);
    }

    public function test_queue_full_sync_returns_false_when_enqueue_fails(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('get_var')->andReturn('0'); // advisory lock not acquired
        $wpdb->shouldReceive('query')->andReturn(1);

        $this->assertFalse($this->invoke('queue_full_sync_for_missing_product', [$this->product(), 'https://s.com', 'hook']));
    }
}
