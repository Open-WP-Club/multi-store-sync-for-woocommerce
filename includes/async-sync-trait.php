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

    /**
     * Build an API client for a store
     *
     * @param array $store Store configuration (must include 'store_url')
     */
    private static function get_api_client(array $store): WC_Multi_Store_API_Client {
        return WC_Multi_Store_API_Client::for_store($store['store_url'], $store);
    }
}
