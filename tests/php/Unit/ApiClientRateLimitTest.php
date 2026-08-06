<?php
/**
 * Unit tests for the transient-backed sliding-window rate limiter in
 * WC_Multi_Store_API_Client.
 *
 * Tests focus on sliding_window_record() (private) and
 * get_rate_limit_status() (public).  enforce_rate_limit() is not tested
 * directly because it calls usleep() which is a PHP built-in that
 * Brain\Monkey cannot intercept; its behaviour is fully determined by
 * sliding_window_record(), which is tested exhaustively here.
 */

use Brain\Monkey\Functions;

class ApiClientRateLimitTest extends WC_Multi_Store_TestCase
{
    private const STORE_URL = 'https://rl-store.test';
    private const WINDOW    = WC_Multi_Store_API_Client::RATE_LIMIT_WINDOW;
    private const MAX_REQ   = WC_Multi_Store_API_Client::RATE_LIMIT_REQUESTS;
    private const PAUSE     = WC_Multi_Store_API_Client::RATE_LIMIT_PAUSE;

    private WC_Multi_Store_API_Client $client;
    private ReflectionMethod          $record;   // sliding_window_record()
    private ReflectionProperty        $localProp; // $request_timestamps

    protected function setUp(): void
    {
        parent::setUp();

        // Default to "no persistent object cache" so each test starts on the
        // transient path; object-cache tests flip this on individually.
        $GLOBALS['wc_mss_test_use_object_cache']        = false;
        $GLOBALS['wc_mss_test_wp_cache_incr_return']    = null;

        // Minimal stubs required by the constructor and every API call path.
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('add_query_arg')->alias(fn($a, $u = '') => $u);
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('delete_transient')->justReturn(true);

        // Reset static in-memory timestamps so tests are isolated.
        $ref            = new ReflectionClass(WC_Multi_Store_API_Client::class);
        $this->localProp = $ref->getProperty('request_timestamps');
        $this->localProp->setValue(null, []);

        $this->client = new WC_Multi_Store_API_Client(
            self::STORE_URL,
            'ck_test',
            'cs_test',
            'query_string'
        );

        $this->record = new ReflectionMethod($this->client, 'sliding_window_record');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache']     = false;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = null;
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function storeKey(): string
    {
        return md5(self::STORE_URL);
    }

    private function transientKey(): string
    {
        return WC_Multi_Store_API_Client::RATE_LIMIT_TRANSIENT_PREFIX . $this->storeKey();
    }

    /** Call sliding_window_record() and return the float wait-time. */
    private function record(float $now, ?array $transientData = null): float
    {
        if ($transientData !== null) {
            Functions\when('get_transient')->justReturn($transientData);
        }
        Functions\when('set_transient')->justReturn(true);

        return $this->record->invoke(
            $this->client,
            $this->storeKey(),
            $this->transientKey(),
            $now
        );
    }

    /** Return the current in-memory timestamp array for this store. */
    private function localTimestamps(): array
    {
        $all = $this->localProp->getValue(null);
        return $all[$this->storeKey()] ?? [];
    }

    // ── no prior state ────────────────────────────────────────────────────────

    public function test_first_request_returns_no_wait(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $wait = $this->record(microtime(true));

        $this->assertEqualsWithDelta(0.0, $wait, 0.001);
    }

    public function test_first_request_is_recorded_in_memory(): void
    {
        Functions\when('get_transient')->justReturn(false);
        $now = microtime(true);

        $this->record($now);

        $this->assertContains($now, $this->localTimestamps());
    }

    public function test_first_request_calls_set_transient(): void
    {
        Functions\when('get_transient')->justReturn(false);

        Functions\expect('set_transient')
            ->once()
            ->with($this->transientKey(), \Mockery::type('array'), self::WINDOW + 2)
            ->andReturn(true);

        // Call the private method directly to avoid the record() helper
        // pre-registering a `when` stub for set_transient, which would conflict
        // with the `expect` registered above.
        $this->record->invoke(
            $this->client,
            $this->storeKey(),
            $this->transientKey(),
            microtime(true)
        );

        // Brain\Monkey verifies the expectation on teardown; tell PHPUnit that
        // an assertion was performed so it does not mark this test as risky.
        $this->addToAssertionCount(1);
    }

    // ── under the limit ───────────────────────────────────────────────────────

    public function test_under_limit_returns_no_wait(): void
    {
        $now        = microtime(true);
        $timestamps = array_map(fn($i) => $now - $i, range(1, self::MAX_REQ - 1));

        Functions\when('get_transient')->justReturn($timestamps);

        $wait = $this->record($now);

        $this->assertEqualsWithDelta(0.0, $wait, 0.001);
    }

    public function test_exactly_at_limit_returns_positive_wait(): void
    {
        $now        = microtime(true);
        // MAX_REQ timestamps all within the window.
        $timestamps = array_map(fn($i) => $now - $i * 0.4, range(0, self::MAX_REQ - 1));

        Functions\when('get_transient')->justReturn($timestamps);

        $wait = $this->record($now);

        $this->assertGreaterThan(0.0, $wait);
    }

    public function test_wait_time_is_at_least_rate_limit_pause(): void
    {
        $now        = microtime(true);
        $timestamps = array_map(fn($i) => $now - $i * 0.1, range(0, self::MAX_REQ - 1));

        Functions\when('get_transient')->justReturn($timestamps);

        $wait = $this->record($now);

        $this->assertGreaterThanOrEqual((float) self::PAUSE, $wait);
    }

    public function test_when_at_limit_request_not_recorded_in_memory(): void
    {
        $now        = microtime(true);
        $timestamps = array_map(fn($i) => $now - $i * 0.1, range(0, self::MAX_REQ - 1));

        Functions\when('get_transient')->justReturn($timestamps);
        Functions\when('set_transient')->justReturn(true);

        $before = count($this->localTimestamps());
        $this->record($now);
        $after  = count($this->localTimestamps());

        $this->assertEquals($before, $after, 'Should not record when at limit');
    }

    // ── expired timestamps are pruned ─────────────────────────────────────────

    public function test_expired_timestamps_are_ignored(): void
    {
        $now     = microtime(true);
        // MAX_REQ timestamps older than the window — all expired.
        $expired = array_map(fn($i) => $now - self::WINDOW - $i, range(1, self::MAX_REQ));

        Functions\when('get_transient')->justReturn($expired);

        $wait = $this->record($now);

        $this->assertEqualsWithDelta(0.0, $wait, 0.001, 'Expired timestamps should not trigger wait');
    }

    public function test_mix_of_expired_and_recent_counts_only_recent(): void
    {
        $now     = microtime(true);
        // (MAX_REQ - 1) expired + (MAX_REQ - 1) recent = still one slot free.
        $expired = array_map(fn($i) => $now - self::WINDOW - $i, range(1, self::MAX_REQ - 1));
        $recent  = array_map(fn($i) => $now - $i,                 range(1, self::MAX_REQ - 1));

        Functions\when('get_transient')->justReturn(array_merge($expired, $recent));

        $wait = $this->record($now);

        $this->assertEqualsWithDelta(0.0, $wait, 0.001);
    }

    // ── cross-process merge (main feature of the new implementation) ──────────

    public function test_transient_timestamps_from_other_processes_are_counted(): void
    {
        $now = microtime(true);
        // Simulate 15 requests from other workers, stored in the transient.
        $otherWorkers = array_map(fn($i) => $now - $i * 0.3, range(1, 15));

        // Pre-populate local memory with 4 more from this process.
        $localTs = array_map(fn($i) => $now - $i * 0.1 - 0.01, range(1, 4));
        $this->localProp->setValue(null, [$this->storeKey() => $localTs]);

        // Total = 15 + 4 = 19, one slot free → should NOT wait.
        Functions\when('get_transient')->justReturn($otherWorkers);

        $wait = $this->record($now);

        $this->assertEqualsWithDelta(0.0, $wait, 0.001);
    }

    public function test_cross_process_total_at_limit_triggers_wait(): void
    {
        $now = microtime(true);
        // 19 timestamps from other workers.
        $otherWorkers = array_map(fn($i) => $now - $i * 0.3, range(1, 19));

        // 1 from this process — total = 20 → at limit.
        $localTs = [$now - 0.05];
        $this->localProp->setValue(null, [$this->storeKey() => $localTs]);

        Functions\when('get_transient')->justReturn($otherWorkers);

        $wait = $this->record($now);

        $this->assertGreaterThan(0.0, $wait);
    }

    public function test_duplicate_timestamps_are_not_double_counted(): void
    {
        $now    = microtime(true);
        $shared = array_map(fn($i) => $now - $i * 0.5, range(1, 10));

        // Same 10 timestamps in both local and transient.
        $this->localProp->setValue(null, [$this->storeKey() => $shared]);
        Functions\when('get_transient')->justReturn($shared);

        $wait = $this->record($now);

        // Deduplicated count = 10, still under limit → no wait.
        $this->assertEqualsWithDelta(0.0, $wait, 0.001);
    }

    // ── get_rate_limit_status reflects cross-process view ────────────────────

    public function test_status_reflects_transient_timestamps(): void
    {
        $now        = microtime(true);
        $transient  = array_map(fn($i) => $now - $i * 0.5, range(1, 7));

        Functions\when('get_transient')->justReturn($transient);

        $status = $this->client->get_rate_limit_status();

        $this->assertEquals(7, $status['requests_in_window']);
        $this->assertEquals(self::MAX_REQ - 7, $status['available']);
    }

    public function test_status_merges_local_and_transient(): void
    {
        $now       = microtime(true);
        $transient = array_map(fn($i) => $now - $i * 0.5, range(1, 5));
        $local     = array_map(fn($i) => $now - $i * 0.1 - 0.01, range(1, 3));

        $this->localProp->setValue(null, [$this->storeKey() => $local]);
        Functions\when('get_transient')->justReturn($transient);

        $status = $this->client->get_rate_limit_status();

        $this->assertEquals(8, $status['requests_in_window']);
    }

    public function test_status_available_never_negative(): void
    {
        $now        = microtime(true);
        // More timestamps than MAX_REQ in transient (edge case).
        $transient  = array_map(fn($i) => $now - $i * 0.1, range(0, self::MAX_REQ + 5));

        Functions\when('get_transient')->justReturn($transient);

        $status = $this->client->get_rate_limit_status();

        $this->assertGreaterThanOrEqual(0, $status['available']);
    }

    public function test_status_structure_is_complete(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $status = $this->client->get_rate_limit_status();

        $this->assertArrayHasKey('requests_in_window', $status);
        $this->assertArrayHasKey('max_requests', $status);
        $this->assertArrayHasKey('window_seconds', $status);
        $this->assertArrayHasKey('available', $status);
        $this->assertEquals(self::MAX_REQ, $status['max_requests']);
        $this->assertEquals(self::WINDOW, $status['window_seconds']);
    }

    // ── transient constant ────────────────────────────────────────────────────

    public function test_transient_prefix_constant_exists(): void
    {
        $this->assertEquals(
            'wc_mss_rl_',
            WC_Multi_Store_API_Client::RATE_LIMIT_TRANSIENT_PREFIX
        );
    }

    public function test_transient_key_length_within_wordpress_limit(): void
    {
        // WP stores transient as '_transient_' + key.  The option_name column
        // is VARCHAR(191).  '_transient_' is 11 chars, leaving 180 for the key.
        $key = WC_Multi_Store_API_Client::RATE_LIMIT_TRANSIENT_PREFIX . md5(self::STORE_URL);
        $this->assertLessThanOrEqual(180, strlen($key));
    }

    // ── object-cache fast path ────────────────────────────────────────────────

    public function test_object_cache_path_returns_no_wait_under_limit(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache']     = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = 1;
        Functions\expect('get_transient')->never();
        Functions\expect('set_transient')->never();

        $wait = $this->record->invoke(
            $this->client,
            $this->storeKey(),
            $this->transientKey(),
            microtime(true)
        );

        $this->assertEqualsWithDelta(0.0, $wait, 0.001);
    }

    public function test_object_cache_path_returns_wait_when_over_limit(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache']     = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = self::MAX_REQ + 1;

        $wait = $this->record->invoke(
            $this->client,
            $this->storeKey(),
            $this->transientKey(),
            microtime(true)
        );

        $this->assertGreaterThan(0.0, $wait);
        $this->assertGreaterThanOrEqual((float) self::PAUSE, $wait);
    }

    public function test_object_cache_path_allows_when_incr_unsupported(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache']     = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = false;

        $wait = $this->record->invoke(
            $this->client,
            $this->storeKey(),
            $this->transientKey(),
            microtime(true)
        );

        $this->assertEqualsWithDelta(0.0, $wait, 0.001);
    }

    public function test_object_cache_path_status_reads_bucket_counter(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache'] = true;
        Functions\when('wp_cache_get')
            ->alias(fn($key, $group = '') => $group === WC_Multi_Store_API_Client::RATE_LIMIT_CACHE_GROUP ? 7 : false);

        $status = $this->client->get_rate_limit_status();

        $this->assertEquals(7, $status['requests_in_window']);
        $this->assertEquals(self::MAX_REQ - 7, $status['available']);
    }
}
