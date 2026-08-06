<?php
/**
 * Circuit Breaker for remote store requests
 *
 * Opens after THRESHOLD consecutive errors per store and stays open for
 * OPEN_DURATION seconds so the remote server gets time to recover.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Circuit_Breaker {

    const int DEFAULT_THRESHOLD     = 10;   // consecutive errors before opening
    const int DEFAULT_OPEN_DURATION = 1800; // seconds to stay open (30 min)

    const string TRANSIENT_PREFIX = 'wc_mss_cb_';

    private static ?int $cached_threshold = null;
    private static ?int $cached_duration  = null;

    private static function threshold(): int {
        if (self::$cached_threshold === null) {
            self::$cached_threshold = (int) WC_Multi_Store_Settings::get('circuit_breaker_threshold', self::DEFAULT_THRESHOLD);
        }
        return self::$cached_threshold;
    }

    private static function open_duration(): int {
        if (self::$cached_duration === null) {
            self::$cached_duration = (int) WC_Multi_Store_Settings::get('circuit_breaker_duration', self::DEFAULT_OPEN_DURATION);
        }
        return self::$cached_duration;
    }

    public static function clear_config_cache(): void {
        self::$cached_threshold = null;
        self::$cached_duration  = null;
    }

    /**
     * Returns true if the circuit is open (requests should be skipped).
     */
    public static function is_open(string $store_url): bool {
        $state = self::get_state($store_url);
        return $state['open_until'] > time();
    }

    /**
     * Record a failed request. Opens the circuit when the threshold is reached.
     *
     * Wrapped in a per-store MySQL advisory lock (same GET_LOCK/RELEASE_LOCK
     * pattern as queue-manager.php's process_queue()) because this is a
     * read-modify-write on a transient: without it, concurrent Action
     * Scheduler workers hitting the same failing store can each read the
     * same consecutive_errors value and one worker's increment silently
     * overwrites the other's — undercounting failures and defeating the
     * breaker exactly under the concurrent-worker load it exists to guard
     * against. A short blocking wait (not the queue lock's non-blocking
     * GET_LOCK(...,0)) is used since this is a quick get/set, not a
     * long-running process — dropping a failure record on lock contention
     * would be worse than briefly waiting for it.
     */
    public static function record_failure(string $store_url): void {
        global $wpdb;

        $lock_name     = 'wc_mss_cb_' . md5($store_url);
        $lock_acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock_name)) === 1;

        try {
            $state = self::get_state($store_url);
            $state['consecutive_errors']++;

            $threshold     = self::threshold();
            $open_duration = self::open_duration();

            if ($state['consecutive_errors'] >= $threshold && $state['open_until'] <= time()) {
                $state['open_until'] = time() + $open_duration;
                $state['opened_at']  = time();

                WC_Multi_Store_Logger::write(sprintf(
                    'Circuit breaker OPENED for %s after %d consecutive errors — pausing requests for %d minutes.',
                    $store_url,
                    $state['consecutive_errors'],
                    (int) round($open_duration / 60)
                ), 'warning');
            }

            set_transient(self::get_key($store_url), $state, $open_duration * 2);
        } finally {
            if ($lock_acquired) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
        }
    }

    /**
     * Record a successful request. Resets the error counter and closes the circuit.
     */
    public static function record_success(string $store_url): void {
        $state    = self::get_state($store_url);
        $was_open = $state['open_until'] > time();

        $state['consecutive_errors'] = 0;
        $state['open_until']         = 0;
        $state['opened_at']          = 0;

        if ($was_open) {
            WC_Multi_Store_Logger::write(sprintf(
                'Circuit breaker CLOSED for %s — requests resuming.',
                $store_url
            ));
        }

        set_transient(self::get_key($store_url), $state, self::open_duration() * 2);
    }

    /**
     * Returns human-readable status for a store.
     *
     * @return array{open: bool, consecutive_errors: int, closes_at: int|null, seconds_remaining: int}
     */
    public static function get_status(string $store_url): array {
        $state   = self::get_state($store_url);
        $is_open = $state['open_until'] > time();

        return [
            'open'               => $is_open,
            'consecutive_errors' => $state['consecutive_errors'],
            'closes_at'          => $is_open ? $state['open_until'] : null,
            'seconds_remaining'  => $is_open ? max(0, $state['open_until'] - time()) : 0,
        ];
    }

    /**
     * Manually reset the circuit for a store (e.g. via WP-CLI after fixing a config issue).
     */
    public static function reset(string $store_url): void {
        delete_transient(self::get_key($store_url));
        WC_Multi_Store_Logger::write(sprintf('Circuit breaker manually reset for %s.', $store_url));
    }

    private static function get_state(string $store_url): array {
        $state = get_transient(self::get_key($store_url));
        if (!is_array($state)) {
            return ['consecutive_errors' => 0, 'open_until' => 0, 'opened_at' => 0];
        }
        return $state;
    }

    private static function get_key(string $store_url): string {
        return self::TRANSIENT_PREFIX . md5($store_url);
    }
}
