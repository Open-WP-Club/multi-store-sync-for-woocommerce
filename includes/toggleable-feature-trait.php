<?php
/**
 * Shared enable/disable settings + AJAX toggle handler for the plugin's
 * optional feature modules (category mapping, attribute remapping,
 * shipping class sync, downloadable files sync).
 *
 * Using classes must define `const SETTINGS_KEY` and implement
 * `default_settings()` / `feature_label()`.
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
     * Check if this feature is enabled
     */
    public static function is_enabled(): bool {
        $settings = get_option(static::SETTINGS_KEY, ['enabled' => false]);
        return !empty($settings['enabled']);
    }

    /**
     * Get settings
     */
    public static function get_settings(): array {
        return get_option(static::SETTINGS_KEY, static::default_settings());
    }

    /**
     * Update settings
     */
    public static function update_settings(array $settings): void {
        $current = static::get_settings();
        update_option(static::SETTINGS_KEY, array_merge($current, $settings));
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
