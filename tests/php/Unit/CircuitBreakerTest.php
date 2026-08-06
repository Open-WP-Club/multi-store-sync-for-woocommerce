<?php
/**
 * Unit tests for WC_Multi_Store_Circuit_Breaker
 */

use Brain\Monkey\Functions;

class CircuitBreakerTest extends WC_Multi_Store_TestCase
{
    private const STORE_URL = 'https://test-store.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->alias(function (string $opt, mixed $default = null): mixed {
            if ($opt === 'wc_multi_store_sync_settings') {
                return [];
            }
            return $default;
        });

        // record_failure() wraps its read-modify-write in a MySQL advisory
        // lock (GET_LOCK/RELEASE_LOCK) to fix the concurrent-worker race
        // where two workers could both increment from the same stale read.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_var')->andReturn('1'); // lock acquired
        $wpdb->shouldReceive('query')->andReturn(1);     // RELEASE_LOCK

        WC_Multi_Store_Settings::clear_static_cache();
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
        parent::tearDown();
    }

    // ── is_open ───────────────────────────────────────────────────────────────

    public function test_is_open_returns_false_when_no_transient_exists(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_URL));
    }

    public function test_is_open_returns_false_when_open_until_is_in_past(): void
    {
        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $state = ['consecutive_errors' => 10, 'open_until' => time() - 10, 'opened_at' => time() - 1810];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_URL));
    }

    public function test_is_open_returns_true_when_open_until_is_in_future(): void
    {
        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $state = ['consecutive_errors' => 10, 'open_until' => time() + 1800, 'opened_at' => time()];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_URL));
    }

    // ── record_failure ────────────────────────────────────────────────────────

    public function test_record_failure_increments_consecutive_errors(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);

        $this->assertNotNull($capturedState);
        $this->assertSame(1, $capturedState['consecutive_errors']);
    }

    public function test_record_failure_opens_circuit_at_threshold(): void
    {
        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $state = ['consecutive_errors' => 9, 'open_until' => 0, 'opened_at' => 0];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);

        $this->assertNotNull($capturedState);
        $this->assertGreaterThan(time(), $capturedState['open_until']);
    }

    public function test_record_failure_does_not_reopen_already_open_circuit(): void
    {
        $cbKey             = 'wc_mss_cb_' . md5(self::STORE_URL);
        $originalOpenUntil = time() + 1800;
        $state             = ['consecutive_errors' => 15, 'open_until' => $originalOpenUntil, 'opened_at' => time() - 10];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);

        $this->assertNotNull($capturedState);
        $this->assertSame($originalOpenUntil, $capturedState['open_until']);
    }

    public function test_record_failure_acquires_and_releases_advisory_lock(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);

        $lockCalls = [];
        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(\Mockery::on(function ($sql) use (&$lockCalls) {
                $lockCalls[] = 'GET_LOCK:' . $sql;
                return true;
            }))
            ->andReturn('1');
        $wpdb->shouldReceive('query')
            ->once()
            ->with(\Mockery::on(function ($sql) use (&$lockCalls) {
                $lockCalls[] = 'RELEASE_LOCK:' . $sql;
                return true;
            }))
            ->andReturn(1);

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);

        $this->assertCount(2, $lockCalls, 'Expected exactly one GET_LOCK and one RELEASE_LOCK call');
        $this->assertStringContainsString('GET_LOCK', $lockCalls[0]);
        $this->assertStringContainsString('RELEASE_LOCK', $lockCalls[1]);
    }

    public function test_record_failure_still_records_when_lock_not_acquired(): void
    {
        // Simulate lock contention: GET_LOCK returns 0 (not acquired). The
        // failure must still be recorded — losing a failure record under
        // contention would be worse than the pre-fix behavior, not better —
        // and RELEASE_LOCK must NOT be called since nothing was acquired.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('get_var')->once()->andReturn('0');
        $wpdb->shouldReceive('query')->never();

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);

        $this->assertNotNull($capturedState);
        $this->assertSame(1, $capturedState['consecutive_errors']);
    }

    // ── record_success ────────────────────────────────────────────────────────

    public function test_record_success_resets_counter(): void
    {
        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $state = ['consecutive_errors' => 5, 'open_until' => 0, 'opened_at' => 0];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_URL);

        $this->assertNotNull($capturedState);
        $this->assertSame(0, $capturedState['consecutive_errors']);
    }

    public function test_record_success_closes_open_circuit(): void
    {
        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $state = ['consecutive_errors' => 10, 'open_until' => time() + 1800, 'opened_at' => time() - 60];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_URL);

        $this->assertNotNull($capturedState);
        $this->assertSame(0, $capturedState['open_until']);
    }

    public function test_record_success_on_closed_circuit_does_not_log_closed_message(): void
    {
        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $state = ['consecutive_errors' => 0, 'open_until' => 0, 'opened_at' => 0];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_URL);

        $ref    = new \ReflectionClass(WC_Multi_Store_Logger::class);
        $inst   = $ref->getProperty('instance')->getValue();
        $buffer = $inst ? $ref->getProperty('buffer')->getValue($inst) : [];

        // Logger::write_to_file() pushes plain formatted strings into the
        // buffer (not {message: ...} structs) — array_column() against
        // 'message' silently returns [] here, which made this assertion
        // pass regardless of what was actually logged. implode() directly.
        $combined = implode(' ', $buffer);
        $this->assertStringNotContainsStringIgnoringCase('CLOSED', $combined);
    }

    // ── get_status ────────────────────────────────────────────────────────────

    public function test_get_status_returns_correct_structure(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_URL);

        $this->assertArrayHasKey('open', $status);
        $this->assertArrayHasKey('consecutive_errors', $status);
        $this->assertArrayHasKey('closes_at', $status);
        $this->assertArrayHasKey('seconds_remaining', $status);
    }

    public function test_get_status_open_circuit(): void
    {
        $cbKey     = 'wc_mss_cb_' . md5(self::STORE_URL);
        $openUntil = time() + 900;
        $state     = ['consecutive_errors' => 10, 'open_until' => $openUntil, 'opened_at' => time()];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_URL);

        $this->assertTrue($status['open']);
        $this->assertGreaterThan(0, $status['seconds_remaining']);
        $this->assertNotNull($status['closes_at']);
        $this->assertSame($openUntil, $status['closes_at']);
    }

    public function test_get_status_closed_circuit(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_URL);

        $this->assertFalse($status['open']);
        $this->assertSame(0, $status['seconds_remaining']);
        $this->assertNull($status['closes_at']);
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function test_reset_calls_delete_transient(): void
    {
        $expectedKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $deletedKey  = null;

        Functions\when('delete_transient')->alias(function (string $key) use (&$deletedKey): bool {
            $deletedKey = $key;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::reset(self::STORE_URL);

        $this->assertSame($expectedKey, $deletedKey);
    }

    // ── threshold from settings ───────────────────────────────────────────────

    public function test_threshold_read_from_settings(): void
    {
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function (string $opt, mixed $default = null): mixed {
            if ($opt === 'wc_multi_store_sync_settings') {
                return ['circuit_breaker_threshold' => 3];
            }
            return $default;
        });

        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);
        $state = ['consecutive_errors' => 2, 'open_until' => 0, 'opened_at' => 0];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);

        $this->assertNotNull($capturedState);
        $this->assertGreaterThan(time(), $capturedState['open_until'],
            'Circuit should have opened after 3rd failure with threshold=3');
    }

    // ── clear_config_cache ────────────────────────────────────────────────────

    public function test_clear_config_cache_resets_static_values(): void
    {
        $cbKey = 'wc_mss_cb_' . md5(self::STORE_URL);

        Functions\when('get_option')->alias(function (string $opt, mixed $default = null): mixed {
            if ($opt === 'wc_multi_store_sync_settings') {
                return ['circuit_breaker_threshold' => 6, 'circuit_breaker_duration' => 600];
            }
            return $default;
        });

        $state = ['consecutive_errors' => 4, 'open_until' => 0, 'opened_at' => 0];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state : false);

        $capturedState = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState): bool {
            $capturedState = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);
        $this->assertSame(0, $capturedState['open_until'], 'Should still be closed: 5 errors < threshold of 6');

        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function (string $opt, mixed $default = null): mixed {
            if ($opt === 'wc_multi_store_sync_settings') {
                return ['circuit_breaker_threshold' => 3];
            }
            return $default;
        });

        $state2 = ['consecutive_errors' => 2, 'open_until' => 0, 'opened_at' => 0];
        Functions\when('get_transient')->alias(fn(string $k) => $k === $cbKey ? $state2 : false);

        $capturedState2 = null;
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$capturedState2): bool {
            $capturedState2 = $value;
            return true;
        });

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_URL);

        $this->assertGreaterThan(time(), $capturedState2['open_until'],
            'After clear_config_cache(), the new threshold=3 should be picked up and circuit should open on 3rd failure');
    }
}
