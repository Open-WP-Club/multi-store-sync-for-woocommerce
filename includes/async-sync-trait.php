<?php
/**
 * Shared Action Scheduler queuing + per-store API client creation for the
 * simple per-taxonomy sync classes (coupons, shipping classes, ...).
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

trait WC_Multi_Store_Async_Sync {

    /**
     * Queue an Action Scheduler action, logging (not failing) if Action
     * Scheduler isn't available.
     *
     * @param string $hook Action hook name
     * @param array $args Action arguments
     */
    private function schedule_async(string $hook, array $args): void {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            WC_Multi_Store_Logger::write(
                "Action Scheduler unavailable — skipped queuing {$hook}",
                'warning'
            );
            return;
        }

        as_schedule_single_action(time(), $hook, $args, WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
    }

    /** Mark failed jobs as failed and retry outages a bounded number of times. */
    private function finish_async(array $results, string $hook, array $args, int $attempt): void {
        $failed = array_keys($results, false, true);
        if (!$failed) {
            return;
        }

        $retry_id = 0;
        if ($attempt < 3 && function_exists('as_schedule_single_action')) {
            $args[] = $attempt + 1;
            $args[] = $failed;
            $retry_id = as_schedule_single_action(time() + 60 * (2 ** $attempt), $hook, $args, WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
        }
        $message = $hook . ' failed for ' . implode(', ', $failed)
            . ($retry_id ? '; retry scheduled.' : '; no further retry scheduled.');
        WC_Multi_Store_Logger::write($message, 'error');
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Action Scheduler records this as a failed action.
        throw new \RuntimeException($message);
    }

    /** Restrict retries to failed stores that are still active. */
    private static function get_sync_stores(?array $store_urls): array {
        $stores = WC_Multi_Store_Settings::get_active_stores();
        return $store_urls === null ? $stores : array_intersect_key($stores, array_flip($store_urls));
    }

    /**
     * Build an API client for a store
     *
     * @param array $store Store configuration (must include 'store_url')
     */
    private static function get_api_client(array $store): WC_Multi_Store_API_Client {
        return WC_Multi_Store_API_Client::for_store($store['store_url'], $store);
    }
}
