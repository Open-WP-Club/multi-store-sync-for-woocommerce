<?php
/**
 * Tests for WC_Multi_Store_Circuit_Breaker.
 *
 * Uses an in-memory transient store (instead of canned return values) so that
 * sequences of failures/successes are exercised end to end: closed -> open ->
 * half-open (open window elapsed) -> re-open / closed, plus per-store isolation.
 */

use Brain\Monkey\Functions;

class CircuitBreakerTest extends WC_Multi_Store_TestCase
{
    private const STORE_A = 'https://store-a.example.com';
    private const STORE_B = 'https://store-b.example.com';

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<string, int> key => ttl of the last set_transient() */
    private array $ttls = [];

    /** @var list<string> */
    private array $queries = [];

    /** @var list<array{0:string,1:string}> [message, level] */
    private array $logged = [];

    private int $threshold = 3;
    private int $duration = 600;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->ttls       = [];
        $this->queries    = [];
        $this->logged     = [];
        $this->threshold  = 3;
        $this->duration   = 600;

        Functions\when('get_transient')->alias(fn(string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, mixed $v, int $ttl = 0): bool {
            $this->transients[$k] = $v;
            $this->ttls[$k]       = $ttl;
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $k): bool {
            unset($this->transients[$k]);
            return true;
        });
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->alias(function (string $opt, mixed $default = null): mixed {
            if ($opt === 'wc_multi_store_sync_settings') {
                return [
                    'circuit_breaker_threshold' => $this->threshold,
                    'circuit_breaker_duration'  => $this->duration,
                ];
            }
            return $default;
        });
        Functions\when('wc_get_logger')->justReturn(new class ($this->logged) {
            /** @param list<array{0:string,1:string}> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function log(string $level, string $message, array $context = []): void
            {
                $this->sink[] = [$message, $level];
            }
        });

        $test = $this;
        global $wpdb;
        $wpdb = new class ($test) extends wpdb {
            public function __construct(private object $test)
            {
            }
            public function prepare(string $query, mixed ...$args): string
            {
                return str_replace('%s', "'" . addslashes((string) ($args[0] ?? '')) . "'", $query);
            }
            public function get_var(string $query): mixed
            {
                $this->test->recordQuery($query);
                return '1';
            }
            public function query(string $query): int|false
            {
                $this->test->recordQuery($query);
                return 1;
            }
        };

        WC_Multi_Store_Settings::clear_static_cache();
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
    }

    protected function tearDown(): void
    {
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
        parent::tearDown();
    }

    /** Public so the anonymous wpdb can reach it. */
    public function recordQuery(string $q): void
    {
        $this->queries[] = $q;
    }

    private function key(string $url): string
    {
        return 'wc_mss_cb_' . md5($url);
    }

    private function trip(string $url, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            WC_Multi_Store_Circuit_Breaker::record_failure($url);
        }
    }

    /** Simulate the open window having elapsed. */
    private function expireOpenWindow(string $url): void
    {
        $this->transients[$this->key($url)]['open_until'] = time() - 1;
    }

    // ── closed -> open ─────────────────────────────────────────────────────

    public function test_circuit_stays_closed_until_exact_threshold(): void
    {
        $this->trip(self::STORE_A, $this->threshold - 1);
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));

        $this->trip(self::STORE_A);
        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
    }

    public function test_open_window_matches_configured_duration(): void
    {
        $before = time();
        $this->trip(self::STORE_A, $this->threshold);
        $after = time();

        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A);
        $this->assertTrue($status['open']);
        $this->assertGreaterThanOrEqual($before + $this->duration, $status['closes_at']);
        $this->assertLessThanOrEqual($after + $this->duration, $status['closes_at']);
        $this->assertLessThanOrEqual($this->duration, $status['seconds_remaining']);
        $this->assertGreaterThan($this->duration - 5, $status['seconds_remaining']);
    }

    public function test_state_transient_outlives_the_open_window(): void
    {
        $this->trip(self::STORE_A, $this->threshold);

        // If the transient expired together with the window, the error counter
        // would be forgotten and the half-open probe would not re-open instantly.
        $this->assertSame($this->duration * 2, $this->ttls[$this->key(self::STORE_A)]);
    }

    public function test_opening_is_logged_once_with_warning_level(): void
    {
        $this->trip(self::STORE_A, $this->threshold + 4);

        $opened = array_filter($this->logged, fn($l) => str_contains($l[0], 'OPENED'));
        $this->assertCount(1, $opened);
        $this->assertSame('warning', array_values($opened)[0][1]);
        $this->assertStringContainsString(self::STORE_A, array_values($opened)[0][0]);
    }

    public function test_failures_while_open_do_not_extend_open_window(): void
    {
        $this->trip(self::STORE_A, $this->threshold);
        $firstUntil = $this->transients[$this->key(self::STORE_A)]['open_until'];

        $this->trip(self::STORE_A, 5);

        $state = $this->transients[$this->key(self::STORE_A)];
        $this->assertSame($firstUntil, $state['open_until']);
        $this->assertSame($this->threshold + 5, $state['consecutive_errors']);
    }

    public function test_status_of_unknown_store_is_closed_with_no_history(): void
    {
        $this->assertSame(
            ['open' => false, 'consecutive_errors' => 0, 'closes_at' => null, 'seconds_remaining' => 0],
            WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A)
        );
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
    }

    public function test_status_reports_error_count_while_still_closed(): void
    {
        $this->trip(self::STORE_A, $this->threshold - 1);

        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A);
        $this->assertFalse($status['open']);
        $this->assertSame($this->threshold - 1, $status['consecutive_errors']);
        $this->assertNull($status['closes_at']);
        $this->assertSame([], array_filter($this->logged, fn($l) => str_contains($l[0], 'OPENED')));
    }

    // ── per-store isolation ────────────────────────────────────────────────

    public function test_failures_on_one_store_never_open_another(): void
    {
        $this->trip(self::STORE_A, $this->threshold * 3);

        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_B));
        $this->assertSame(0, WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_B)['consecutive_errors']);
    }

    public function test_interleaved_failures_are_counted_per_store(): void
    {
        for ($i = 0; $i < $this->threshold - 1; $i++) {
            $this->trip(self::STORE_A);
            $this->trip(self::STORE_B);
        }
        // Together 2*(threshold-1) failures, but neither store reached the threshold.
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_B));

        $this->trip(self::STORE_B);
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_B));
    }

    public function test_success_on_one_store_does_not_reset_another(): void
    {
        $this->trip(self::STORE_A, $this->threshold - 1);
        $this->trip(self::STORE_B, $this->threshold - 1);

        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_B);

        $this->assertSame($this->threshold - 1, WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A)['consecutive_errors']);
        $this->assertSame(0, WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_B)['consecutive_errors']);
    }

    public function test_reset_only_clears_the_given_store(): void
    {
        $this->trip(self::STORE_A, $this->threshold);
        $this->trip(self::STORE_B, $this->threshold);

        WC_Multi_Store_Circuit_Breaker::reset(self::STORE_A);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_B));
        $this->assertArrayNotHasKey($this->key(self::STORE_A), $this->transients);
    }

    public function test_urls_that_differ_by_one_character_use_different_keys(): void
    {
        $this->trip('https://store.example.com/a', $this->threshold);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open('https://store.example.com/b'));
        $this->assertCount(1, $this->transients);
    }

    public function test_hostile_store_url_is_hashed_into_the_transient_key(): void
    {
        $evil = "https://x.test/'; DROP TABLE wp_options; --\n";
        $this->trip($evil);

        $keys = array_keys($this->transients);
        $this->assertSame([$this->key($evil)], $keys);
        $this->assertMatchesRegularExpression('/^wc_mss_cb_[0-9a-f]{32}$/', $keys[0]);
    }

    public function test_advisory_lock_name_is_per_store_and_within_mysql_limit(): void
    {
        $this->trip(self::STORE_A);
        $this->trip(self::STORE_B);

        $locks = array_values(array_filter($this->queries, fn($q) => str_contains($q, 'GET_LOCK')));
        $this->assertCount(2, $locks);
        $this->assertNotSame($locks[0], $locks[1]);
        $this->assertStringContainsString($this->key(self::STORE_A), $locks[0]);
        // MySQL 5.7+ limits lock names to 64 characters.
        $this->assertLessThanOrEqual(64, strlen($this->key(self::STORE_A)));
    }

    public function test_failure_takes_lock_before_writing_and_releases_it_after(): void
    {
        $events = [];
        Functions\when('set_transient')->alias(function () use (&$events): bool {
            $events[] = 'WRITE';
            return true;
        });

        $this->trip(self::STORE_A);

        $this->assertCount(2, $this->queries, 'one GET_LOCK and one RELEASE_LOCK');
        $this->assertStringContainsString('GET_LOCK', $this->queries[0]);
        $this->assertStringContainsString('RELEASE_LOCK', $this->queries[1]);
        $this->assertSame(['WRITE'], $events);
    }

    // ── open -> half-open -> closed / re-open ──────────────────────────────

    public function test_circuit_becomes_half_open_after_window_elapses(): void
    {
        $this->trip(self::STORE_A, $this->threshold);
        $this->expireOpenWindow(self::STORE_A);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A);
        $this->assertFalse($status['open']);
        $this->assertNull($status['closes_at']);
        $this->assertSame(0, $status['seconds_remaining']);
        // Error history is retained so a failed probe re-opens at once.
        $this->assertSame($this->threshold, $status['consecutive_errors']);
    }

    public function test_single_failed_probe_in_half_open_reopens_for_full_duration(): void
    {
        $this->trip(self::STORE_A, $this->threshold);
        $this->expireOpenWindow(self::STORE_A);

        $this->trip(self::STORE_A);

        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A);
        $this->assertGreaterThan($this->duration - 5, $status['seconds_remaining']);
        $opened = array_filter($this->logged, fn($l) => str_contains($l[0], 'OPENED'));
        $this->assertCount(2, $opened);
    }

    public function test_successful_probe_in_half_open_fully_resets_state(): void
    {
        $this->trip(self::STORE_A, $this->threshold + 2);
        $this->expireOpenWindow(self::STORE_A);

        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_A);

        $this->assertSame(
            ['consecutive_errors' => 0, 'open_until' => 0, 'opened_at' => 0],
            $this->transients[$this->key(self::STORE_A)]
        );
        // The window had already elapsed, so no misleading "CLOSED" message.
        $closed = array_filter($this->logged, fn($l) => str_contains($l[0], 'CLOSED'));
        $this->assertCount(0, $closed);

        // A fresh run of failures needs the whole threshold again.
        $this->trip(self::STORE_A, $this->threshold - 1);
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
    }

    public function test_success_while_open_closes_immediately_and_logs_once(): void
    {
        $this->trip(self::STORE_A, $this->threshold);
        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));

        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_A);
        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_A);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $closed = array_filter($this->logged, fn($l) => str_contains($l[0], 'CLOSED'));
        $this->assertCount(1, $closed);
    }

    public function test_success_on_closed_circuit_does_not_log(): void
    {
        $this->trip(self::STORE_A, $this->threshold - 1);

        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_A);

        $this->assertSame([], $this->logged);
    }

    public function test_intermittent_success_resets_the_consecutive_counter(): void
    {
        $this->trip(self::STORE_A, $this->threshold - 1);
        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_A);
        $this->trip(self::STORE_A, $this->threshold - 1);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
    }

    public function test_manual_reset_closes_open_circuit_and_logs(): void
    {
        $this->trip(self::STORE_A, $this->threshold);

        WC_Multi_Store_Circuit_Breaker::reset(self::STORE_A);

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $this->assertSame(0, WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A)['consecutive_errors']);
        $reset = array_filter($this->logged, fn($l) => str_contains($l[0], 'manually reset'));
        $this->assertCount(1, $reset);
    }

    // ── configuration ──────────────────────────────────────────────────────

    public function test_threshold_of_one_opens_on_first_failure(): void
    {
        $this->threshold = 1;
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
        WC_Multi_Store_Settings::clear_static_cache();

        $this->trip(self::STORE_A);

        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
    }

    public function test_threshold_and_duration_are_read_from_settings(): void
    {
        $this->threshold = 2;
        $this->duration  = 120;

        $this->trip(self::STORE_A, 2);

        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A);
        $this->assertTrue($status['open']);
        $this->assertLessThanOrEqual(120, $status['seconds_remaining']);
        $this->assertGreaterThan(115, $status['seconds_remaining']);
        $this->assertSame(240, $this->ttls[$this->key(self::STORE_A)]);
    }

    public function test_config_is_cached_until_cleared(): void
    {
        $this->trip(self::STORE_A);          // primes the cached threshold (3)
        $this->threshold = 1;
        WC_Multi_Store_Settings::clear_static_cache();

        $this->trip(self::STORE_B);          // still uses cached threshold 3
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_B));

        WC_Multi_Store_Circuit_Breaker::clear_config_cache();
        $this->trip(self::STORE_B);          // 2nd failure, new threshold 1
        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_B));
    }

    public function test_default_threshold_applies_when_setting_is_absent(): void
    {
        Functions\when('get_option')->alias(fn(string $o, mixed $d = null) => $d);
        WC_Multi_Store_Settings::clear_static_cache();
        WC_Multi_Store_Circuit_Breaker::clear_config_cache();

        $this->trip(self::STORE_A, WC_Multi_Store_Circuit_Breaker::DEFAULT_THRESHOLD - 1);
        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));

        $this->trip(self::STORE_A);
        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $this->assertSame(
            WC_Multi_Store_Circuit_Breaker::DEFAULT_OPEN_DURATION * 2,
            $this->ttls[$this->key(self::STORE_A)]
        );
    }

    // ── robustness ─────────────────────────────────────────────────────────

    public function test_non_array_transient_is_treated_as_fresh_closed_state(): void
    {
        foreach (['garbage', 42, true, null] as $junk) {
            $this->transients[$this->key(self::STORE_A)] = $junk;

            $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
            $this->assertSame(0, WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A)['consecutive_errors']);
        }

        $this->transients[$this->key(self::STORE_A)] = 'garbage';
        $this->trip(self::STORE_A);
        $this->assertSame(1, $this->transients[$this->key(self::STORE_A)]['consecutive_errors']);
    }

    public function test_advisory_lock_is_released_even_if_persisting_state_throws(): void
    {
        Functions\when('set_transient')->alias(function (): bool {
            throw new RuntimeException('cache backend down');
        });

        try {
            WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_A);
            self::fail('exception expected');
        } catch (RuntimeException $e) {
            $this->assertSame('cache backend down', $e->getMessage());
        }

        $released = array_filter($this->queries, fn($q) => str_contains($q, 'RELEASE_LOCK'));
        $this->assertCount(1, $released);
    }

    public function test_lock_is_not_released_when_it_was_never_acquired(): void
    {
        global $wpdb;
        $test = $this;
        $wpdb = new class ($test) extends wpdb {
            public function __construct(private object $test)
            {
            }
            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }
            public function get_var(string $query): mixed
            {
                return '0'; // lock timeout
            }
            public function query(string $query): int|false
            {
                $this->test->recordQuery($query);
                return 1;
            }
        };

        WC_Multi_Store_Circuit_Breaker::record_failure(self::STORE_A);

        $this->assertSame([], $this->queries, 'must not release a lock held by another worker');
        $this->assertSame(1, $this->transients[$this->key(self::STORE_A)]['consecutive_errors']);
    }

    // ── regression: partial state arrays ───────────────────────────────────

    public static function partialStates(): array
    {
        return [
            'only errors'   => [['consecutive_errors' => 3]],
            'only open'     => [['open_until' => 0]],
            'empty array'   => [[]],
            'unrelated key' => [['foo' => 'bar']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('partialStates')]
    public function test_partial_state_arrays_are_filled_without_warnings(array $partial): void
    {
        $this->transients[$this->key(self::STORE_A)] = $partial;

        $this->assertFalse(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
        $status = WC_Multi_Store_Circuit_Breaker::get_status(self::STORE_A);
        $this->assertSame($partial['consecutive_errors'] ?? 0, $status['consecutive_errors']);
        $this->assertFalse($status['open']);

        $this->trip(self::STORE_A);
        $this->assertSame(($partial['consecutive_errors'] ?? 0) + 1, $this->transients[$this->key(self::STORE_A)]['consecutive_errors']);
        WC_Multi_Store_Circuit_Breaker::record_success(self::STORE_A);
        $this->assertSame(0, $this->transients[$this->key(self::STORE_A)]['consecutive_errors']);
    }

    public function test_partial_state_with_errors_at_threshold_opens_on_next_failure(): void
    {
        $this->transients[$this->key(self::STORE_A)] = ['consecutive_errors' => $this->threshold - 1];

        $this->trip(self::STORE_A);

        $this->assertTrue(WC_Multi_Store_Circuit_Breaker::is_open(self::STORE_A));
    }
}
