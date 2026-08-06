<?php
/**
 * Configuration Export/Import Manager
 *
 * Handles exporting and importing plugin configuration
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Config_Manager {

    /**
     * Export plugin configuration
     *
     * @param bool $include_keys Whether to include API keys
     * @return array Configuration data
     */
    public static function export(bool $include_keys = false): array {
        $config = [
            'plugin_version' => WC_MSS_VERSION,
            'exported_at' => current_time('mysql'),
            'settings' => WC_Multi_Store_Settings::get_settings(false),
            'scheduled_settings' => WC_Multi_Store_Settings::get_scheduled_settings(),
            'stores' => [],
            'email_settings' => WC_Multi_Store_Email_Notifications::get_settings(),
            'webhook_settings' => get_option('wc_multi_store_sync_webhook_settings', []),
            'weekly_verification' => get_option('wc_multi_store_sync_weekly_verification', []),
            'order_settings' => get_option('wc_multi_store_sync_orders', []),
        ];

        // Export stores with optional key redaction
        $stores = WC_Multi_Store_Settings::get_stores();
        foreach ($stores as $url => $store_config) {
            $store_export = $store_config;

            if (!$include_keys) {
                $store_export['consumer_key'] = '***REDACTED***';
                $store_export['consumer_secret'] = '***REDACTED***';
            }

            $config['stores'][$url] = $store_export;
        }

        // Redact webhook secret unless keys are included
        if (!$include_keys && isset($config['webhook_settings']['webhook_secret'])) {
            $config['webhook_settings']['webhook_secret'] = '***REDACTED***';
        }

        return $config;
    }

    /**
     * Import plugin configuration
     *
     * @param array $config Configuration data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function import(array $config): bool|\WP_Error {
        // Validate config structure
        if (empty($config['plugin_version'])) {
            return new \WP_Error('invalid_config', __('Invalid configuration file: missing plugin_version.', 'wc-multi-store-sync'));
        }

        // Import general settings
        if (!empty($config['settings']) && is_array($config['settings'])) {
            WC_Multi_Store_Settings::update_all($config['settings']);
            WC_Multi_Store_Logger::write('Configuration imported: general settings');
        }

        // Import scheduled settings
        if (!empty($config['scheduled_settings']) && is_array($config['scheduled_settings'])) {
            update_option('wc_multi_store_sync_scheduled', $config['scheduled_settings']);
            WC_Multi_Store_Logger::write('Configuration imported: scheduled settings');
        }

        // Import stores (skip redacted keys)
        if (!empty($config['stores']) && is_array($config['stores'])) {
            $existing_stores = WC_Multi_Store_Settings::get_stores();

            foreach ($config['stores'] as $url => $store_config) {
                // If keys are redacted, preserve existing keys
                if (isset($store_config['consumer_key']) && $store_config['consumer_key'] === '***REDACTED***') {
                    if (isset($existing_stores[$url])) {
                        $store_config['consumer_key'] = $existing_stores[$url]['consumer_key'];
                        $store_config['consumer_secret'] = $existing_stores[$url]['consumer_secret'];
                    } else {
                        WC_Multi_Store_Logger::write(sprintf(
                            'Config import: Store %s has redacted keys and no existing config. Skipping.',
                            $url
                        ), 'warning');
                        continue;
                    }
                }

                WC_Multi_Store_Settings::update_store($url, $store_config);
            }
            WC_Multi_Store_Logger::write('Configuration imported: store settings');
        }

        // Import email settings
        if (!empty($config['email_settings']) && is_array($config['email_settings'])) {
            update_option('wc_multi_store_sync_email_settings', $config['email_settings']);
            WC_Multi_Store_Logger::write('Configuration imported: email settings');
        }

        // Import webhook settings (skip redacted secret)
        if (!empty($config['webhook_settings']) && is_array($config['webhook_settings'])) {
            $webhook = $config['webhook_settings'];
            if (isset($webhook['webhook_secret']) && $webhook['webhook_secret'] === '***REDACTED***') {
                $existing = get_option('wc_multi_store_sync_webhook_settings', []);
                $webhook['webhook_secret'] = $existing['webhook_secret'] ?? wp_generate_password(32, false);
            }
            update_option('wc_multi_store_sync_webhook_settings', $webhook);
            WC_Multi_Store_Logger::write('Configuration imported: webhook settings');
        }

        // Import weekly verification settings
        if (!empty($config['weekly_verification']) && is_array($config['weekly_verification'])) {
            update_option('wc_multi_store_sync_weekly_verification', $config['weekly_verification']);
            WC_Multi_Store_Logger::write('Configuration imported: weekly verification settings');
        }

        // Import order settings
        if (!empty($config['order_settings']) && is_array($config['order_settings'])) {
            update_option('wc_multi_store_sync_orders', $config['order_settings']);
            WC_Multi_Store_Logger::write('Configuration imported: order settings');
        }

        // Clear all caches
        WC_Multi_Store_Settings::clear_static_cache();
        WC_Multi_Store_Cache_Manager::clear_all();

        WC_Multi_Store_Logger::write('Configuration import completed successfully');

        return true;
    }

    /**
     * AJAX handler for export
     */
    public static function ajax_export(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $include_keys = !empty($_POST['include_keys']);
        $config = self::export($include_keys);

        wp_send_json_success([
            'config' => $config,
            'filename' => 'wc-mss-config-' . gmdate('Y-m-d-His') . '.json',
        ]);
    }

    /**
     * AJAX handler for import
     */
    public static function ajax_import(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        if (empty($_POST['config'])) {
            wp_send_json_error(['message' => __('No configuration data provided.', 'wc-multi-store-sync')]);
            return;
        }

        $config = json_decode(wp_unslash($_POST['config']), true);
        if ($config === null) {
            wp_send_json_error(['message' => __('Invalid JSON data.', 'wc-multi-store-sync')]);
            return;
        }

        $result = self::import($config);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['message' => __('Configuration imported successfully.', 'wc-multi-store-sync')]);
    }
}
