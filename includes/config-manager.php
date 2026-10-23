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

    /** Placeholder that stands in for a credential in a redacted export. */
    private const string REDACTED = '***REDACTED***';

    /** Store fields that are credentials and are redacted/restored together. */
    private const array SECRET_STORE_FIELDS = ['consumer_key', 'consumer_secret', 'wp_app_password'];

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

        // Export stores with optional credential redaction
        $stores = WC_Multi_Store_Settings::get_stores();
        foreach ($stores as $url => $store_config) {
            $store_export = $store_config;

            if (!$include_keys) {
                foreach (self::SECRET_STORE_FIELDS as $field) {
                    if (isset($store_export[$field]) || in_array($field, ['consumer_key', 'consumer_secret'], true)) {
                        $store_export[$field] = self::REDACTED;
                    }
                }
            }

            $config['stores'][$url] = $store_export;
        }

        // Redact webhook secret unless keys are included
        if (!$include_keys && isset($config['webhook_settings']['webhook_secret'])) {
            $config['webhook_settings']['webhook_secret'] = self::REDACTED;
        }

        return $config;
    }

    /**
     * Import plugin configuration
     *
     * Everything is validated first and written afterwards, so a bad store or section cannot
     * leave the site half-imported.
     *
     * @param array $config Configuration data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function import(array $config): bool|\WP_Error {
        // Validate config structure
        if (empty($config['plugin_version'])) {
            return new \WP_Error('invalid_config', __('Invalid configuration file: missing plugin_version.', 'multi-store-sync-for-woocommerce'));
        }

        // ---- Validate and normalise (nothing is written in this phase) ----
        $stores_to_save = [];
        if (!empty($config['stores']) && is_array($config['stores'])) {
            $existing_stores = WC_Multi_Store_Settings::get_stores();

            foreach ($config['stores'] as $url => $store_config) {
                $url = (string) $url;
                if (!is_array($store_config)) {
                    return new \WP_Error('invalid_store', __('Invalid configuration file: a store entry is not an object.', 'multi-store-sync-for-woocommerce'));
                }

                foreach (['consumer_key', 'consumer_secret', 'wp_username', 'wp_app_password', 'name', 'status'] as $text_field) {
                    if (isset($store_config[$text_field]) && !is_string($store_config[$text_field])) {
                        return new \WP_Error('invalid_store', __('Invalid configuration file: a store field has the wrong type.', 'multi-store-sync-for-woocommerce'));
                    }
                }

                // Redacted credentials keep whatever is already stored for that store.
                $has_redacted = false;
                // The file's URL may differ from the stored one only by a trailing slash.
                $existing_key = isset($existing_stores[$url]) ? $url : (isset($existing_stores[untrailingslashit($url)]) ? untrailingslashit($url) : (isset($existing_stores[trailingslashit($url)]) ? trailingslashit($url) : $url));
                foreach (self::SECRET_STORE_FIELDS as $field) {
                    if (($store_config[$field] ?? null) === self::REDACTED) {
                        $has_redacted = true;
                        if (isset($existing_stores[$existing_key][$field])) {
                            $store_config[$field] = $existing_stores[$existing_key][$field];
                        } else {
                            unset($store_config[$field]);
                        }
                    }
                }
                if ($has_redacted && !isset($existing_stores[$existing_key])) {
                    WC_Multi_Store_Logger::write(sprintf(
                        'Config import: Store %s has redacted keys and no existing config. Skipping.',
                        $url
                    ), 'warning');
                    continue;
                }

                $validated_url = WC_Multi_Store_Settings::validate_store_url($url);
                if (is_wp_error($validated_url)) {
                    return $validated_url;
                }

                $stores_to_save[$url] = $store_config;
            }
        }

        $sections = [];
        if (!empty($config['settings']) && is_array($config['settings'])) {
            $sections['settings'] = self::conform($config['settings'], WC_Multi_Store_Settings::get_settings(false));
        }
        if (!empty($config['scheduled_settings']) && is_array($config['scheduled_settings'])) {
            $sections['scheduled_settings'] = self::sanitize_scheduled_settings($config['scheduled_settings']);
        }
        if (!empty($config['email_settings']) && is_array($config['email_settings'])) {
            $sections['email_settings'] = self::conform($config['email_settings'], WC_Multi_Store_Email_Notifications::get_settings());
        }
        if (!empty($config['webhook_settings']) && is_array($config['webhook_settings'])) {
            $existing_webhook = WC_Multi_Store_Settings::get_webhook_settings(false);
            $webhook = self::conform($config['webhook_settings'], $existing_webhook + [
                'enabled' => false,
                'webhook_secret' => '',
                'trigger_statuses' => [],
                'allow_negative_stock' => false,
                'auto_verify' => false,
                'webhook_log_retention_days' => 90,
            ]);
            if (($config['webhook_settings']['webhook_secret'] ?? null) === self::REDACTED || !isset($webhook['webhook_secret'])) {
                $webhook['webhook_secret'] = $existing_webhook['webhook_secret'] ?? wp_generate_password(32, false);
            }
            if (isset($webhook['webhook_log_retention_days'])) {
                $webhook['webhook_log_retention_days'] = max(30, min(180, (int) $webhook['webhook_log_retention_days']));
            }
            $sections['webhook_settings'] = $webhook;
        }
        if (!empty($config['weekly_verification']) && is_array($config['weekly_verification'])) {
            $sections['weekly_verification'] = self::conform($config['weekly_verification'], WC_Multi_Store_Weekly_Verification_Scheduler::get_settings());
        }
        if (!empty($config['order_settings']) && is_array($config['order_settings'])) {
            $current_orders = get_option('wc_multi_store_sync_orders', []);
            $sections['order_settings'] = self::conform($config['order_settings'], is_array($current_orders) ? $current_orders : []);
        }

        // A section whose keys were all unknown conforms to nothing: skip it rather than overwrite
        // the stored settings with an empty array.
        $sections = array_filter($sections, static fn(array $section): bool => $section !== []);

        // ---- Write ----
        if (isset($sections['settings'])) {
            WC_Multi_Store_Settings::update_all($sections['settings']);
            WC_Multi_Store_Logger::write('Configuration imported: general settings');
        }
        if (isset($sections['scheduled_settings'])) {
            update_option('wc_multi_store_sync_scheduled', $sections['scheduled_settings']);
            WC_Multi_Store_Logger::write('Configuration imported: scheduled settings');
        }

        if ($stores_to_save) {
            foreach ($stores_to_save as $url => $store_config) {
                $result = WC_Multi_Store_Settings::update_store($url, $store_config);
                if (is_wp_error($result)) {
                    return $result;
                }
                if (!$result) {
                    return new \WP_Error('store_import_failed', __('Could not save an imported store. Some settings may already have been saved. Please retry the import.', 'multi-store-sync-for-woocommerce'));
                }
            }
            WC_Multi_Store_Logger::write('Configuration imported: store settings');
        }

        if (isset($sections['email_settings'])) {
            update_option('wc_multi_store_sync_email_settings', $sections['email_settings']);
            WC_Multi_Store_Logger::write('Configuration imported: email settings');
        }
        if (isset($sections['webhook_settings'])) {
            update_option('wc_multi_store_sync_webhook_settings', $sections['webhook_settings']);
            WC_Multi_Store_Logger::write('Configuration imported: webhook settings');
        }
        if (isset($sections['weekly_verification'])) {
            update_option('wc_multi_store_sync_weekly_verification', $sections['weekly_verification']);
            WC_Multi_Store_Logger::write('Configuration imported: weekly verification settings');
        }
        if (isset($sections['order_settings'])) {
            update_option('wc_multi_store_sync_orders', $sections['order_settings']);
            WC_Multi_Store_Logger::write('Configuration imported: order settings');
        }

        // Clear all caches
        WC_Multi_Store_Settings::clear_static_cache();
        if (!WC_Multi_Store_Cache_Manager::clear_all()) {
            return new \WP_Error('cache_invalidation_failed', __('Configuration settings were saved, but the cache could not be cleared. Please retry the import.', 'multi-store-sync-for-woocommerce'));
        }

        WC_Multi_Store_Logger::write('Configuration import completed successfully');

        return true;
    }

    /**
     * Keep only the keys the plugin knows about (those of $reference) and give each the
     * reference value's type, so an imported file cannot add options or change a flag into text.
     *
     * @param array $incoming  Untrusted values
     * @param array $reference Current/default settings that define the allowed keys and types
     * @return array
     */
    private static function conform(array $incoming, array $reference): array {
        if ($reference === []) {
            return array_filter($incoming, 'is_scalar');
        }

        $clean = [];
        foreach ($reference as $key => $default) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];
            if (is_array($default)) {
                if (is_array($value)) {
                    $clean[$key] = $value;
                }
            } elseif (is_bool($default)) {
                if (is_scalar($value)) {
                    $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
            } elseif (is_int($default)) {
                if (is_numeric($value)) {
                    $clean[$key] = (int) $value;
                }
            } elseif (is_scalar($value)) {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }

    /** Same limits as the Settings screen, so an import cannot set a zero batch size or hour 99. */
    private static function sanitize_scheduled_settings(array $incoming): array {
        $clean = self::conform($incoming, WC_Multi_Store_Settings::get_scheduled_settings() + [
            'peak_start_hour' => 9,
            'peak_end_hour' => 17,
        ]);

        foreach (['batch_size_peak', 'batch_size_offpeak'] as $key) {
            if (isset($clean[$key])) {
                $clean[$key] = max(1, min(500, (int) $clean[$key]));
            }
        }
        foreach (['peak_start_hour', 'peak_end_hour'] as $key) {
            if (isset($clean[$key])) {
                $clean[$key] = max(0, min(23, (int) $clean[$key]));
            }
        }
        if (isset($clean['sync_modified_hours'])) {
            $clean['sync_modified_hours'] = max(1, min(168, (int) $clean['sync_modified_hours']));
        }
        if (isset($clean['scheduled_sync_interval']) && !in_array($clean['scheduled_sync_interval'], ['10min', '30min', 'hourly', 'daily'], true)) {
            $clean['scheduled_sync_interval'] = '10min';
        }
        foreach (['scheduled_sync_type' => ['use_default', 'full_product', 'price_quantity_categories', 'price_quantity', 'quantity'],
                  'scheduled_stock_sync' => ['use_default', 'enabled', 'disabled'],
                  'scheduled_category_auto_create' => ['use_default', 'enabled', 'disabled']] as $key => $allowed) {
            if (isset($clean[$key]) && !in_array($clean[$key], $allowed, true)) {
                $clean[$key] = 'use_default';
            }
        }

        return $clean;
    }

    /**
     * AJAX handler for export
     */
    public static function ajax_export(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        $include_keys = isset($_POST['include_keys']) && filter_var(wp_unslash($_POST['include_keys']), FILTER_VALIDATE_BOOLEAN);
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
            wp_send_json_error(['message' => __('Unauthorized', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        if (!isset($_POST['config']) || !is_string($_POST['config']) || $_POST['config'] === '') {
            wp_send_json_error(['message' => __('No configuration data provided.', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- config import intentionally accepts JSON; it is unslashed, decoded, and type-validated before privileged import.
        $config = json_decode(wp_unslash($_POST['config']), true);
        if (!is_array($config)) {
            wp_send_json_error(['message' => __('Invalid JSON data.', 'multi-store-sync-for-woocommerce')]);
            return;
        }

        $result = self::import($config);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['message' => __('Configuration imported successfully.', 'multi-store-sync-for-woocommerce')]);
    }
}
