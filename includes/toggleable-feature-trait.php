<?php
/**
 * Shared enable/disable settings + AJAX toggle handler for the plugin's
 * optional feature modules (category mapping, attribute remapping,
 * shipping class sync, downloadable files sync).
 *
 * Settings live in the central WC_Multi_Store_Settings store, as
 * `"{central_settings_prefix()}_{key}"` entries (e.g.
 * `shipping_class_sync_enabled`), consistent with other feature flags
 * there (`stock_sync_enabled`, `image_proxy_enabled`, ...). Using classes
 * must implement `default_settings()` / `feature_label()` /
 * `central_settings_prefix()`.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

trait WC_Multi_Store_Toggleable_Feature {

    /**
     * Default settings, including at least an 'enabled' key
     *
     * @return array
     */
    abstract public static function default_settings(): array;

    /**
     * Human-readable feature name used in the AJAX toggle success message,
     * e.g. "Shipping class sync"
     *
     * @return string
     */
    abstract public static function feature_label(): string;

    /**
     * Prefix used to namespace this feature's keys in the central
     * WC_Multi_Store_Settings store, e.g. "shipping_class_sync" for a
     * `default_settings()` key of 'enabled' → central key
     * 'shipping_class_sync_enabled'.
     *
     * @return string
     */
    abstract public static function central_settings_prefix(): string;

    /**
     * Check if this feature is enabled
     */
    public static function is_enabled(): bool {
        return (bool) WC_Multi_Store_Settings::get(static::central_settings_prefix() . '_enabled', false);
    }

    /**
     * Get settings
     */
    public static function get_settings(): array {
        $settings = [];
        foreach (static::default_settings() as $key => $default) {
            $settings[$key] = WC_Multi_Store_Settings::get(static::central_settings_prefix() . '_' . $key, $default);
        }
        return $settings;
    }

    /**
     * Update settings
     */
    public static function update_settings(array $settings): void {
        foreach ($settings as $key => $value) {
            WC_Multi_Store_Settings::update(static::central_settings_prefix() . '_' . $key, $value);
        }
    }

    /**
     * AJAX handler: Toggle this feature on/off
     */
    public static function ajax_toggle(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $enabled = !empty($_POST['enabled']);
        static::update_settings(['enabled' => $enabled]);

        wp_send_json_success([
            'message' => $enabled
                /* translators: %s: feature name, e.g. "Shipping class sync" */
                ? sprintf(__('%s enabled', 'wc-multi-store-sync'), static::feature_label())
                /* translators: %s: feature name, e.g. "Shipping class sync" */
                : sprintf(__('%s disabled', 'wc-multi-store-sync'), static::feature_label()),
            'enabled' => $enabled,
        ]);
    }
}
