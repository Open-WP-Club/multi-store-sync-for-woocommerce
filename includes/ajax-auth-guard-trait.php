<?php
/**
 * Shared nonce + capability guard for wp_ajax_wc_mss_* handlers.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

trait WC_Multi_Store_Ajax_Auth_Guard {

    /**
     * Verify the AJAX request's nonce and that the current user can manage
     * the plugin. Sends a JSON error and returns false if either check fails.
     *
     * @param string $nonce_action Nonce action name passed to check_ajax_referer()
     * @param string|null $error_message Custom error message; defaults to "Permission denied"
     * @return bool True if the request is authorized
     */
    private static function verify_admin_request(string $nonce_action = 'wc_mss_admin', ?string $error_message = null): bool {
        check_ajax_referer($nonce_action, 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => $error_message ?? __('Permission denied', 'wc-multi-store-sync')]);
            return false;
        }

        return true;
    }
}
