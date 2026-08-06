<?php
/**
 * Sync Profiles
 * Save and reuse sync configurations as named templates
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Sync_Profiles {

    /**
     * Option key for storing profiles
     */
    const OPTION_KEY = 'wc_mss_sync_profiles';

    /**
     * Get all saved profiles
     *
     * @return array Array of profiles: [id => profile_data]
     */
    public static function get_all(): array {
        return get_option(self::OPTION_KEY, []);
    }

    /**
     * Get a single profile by ID
     *
     * @param string $profile_id Profile ID
     * @return array|null Profile data or null
     */
    public static function get(string $profile_id): ?array {
        $profiles = self::get_all();
        return $profiles[$profile_id] ?? null;
    }

    /**
     * Save a profile
     *
     * @param string $profile_id Profile ID (auto-generated if empty)
     * @param array $profile_data Profile configuration
     * @return string The profile ID
     */
    public static function save(string $profile_id, array $profile_data): string {
        $profiles = self::get_all();

        if (empty($profile_id)) {
            $profile_id = 'profile_' . wp_generate_password(8, false);
        }

        $profile_data['updated_at'] = current_time('mysql');
        if (!isset($profiles[$profile_id])) {
            $profile_data['created_at'] = current_time('mysql');
        }

        $profiles[$profile_id] = $profile_data;
        update_option(self::OPTION_KEY, $profiles);

        WC_Multi_Store_Logger::write(sprintf(
            'Sync profile "%s" saved (ID: %s)',
            $profile_data['name'] ?? 'Unnamed',
            $profile_id
        ));

        return $profile_id;
    }

    /**
     * Delete a profile
     *
     * @param string $profile_id Profile ID to delete
     * @return bool Whether the profile was deleted
     */
    public static function delete(string $profile_id): bool {
        $profiles = self::get_all();

        if (!isset($profiles[$profile_id])) {
            return false;
        }

        $name = $profiles[$profile_id]['name'] ?? 'Unnamed';
        unset($profiles[$profile_id]);
        update_option(self::OPTION_KEY, $profiles);

        WC_Multi_Store_Logger::write(sprintf(
            'Sync profile "%s" deleted (ID: %s)',
            $name,
            $profile_id
        ));

        return true;
    }

    /**
     * Duplicate a profile
     *
     * @param string $profile_id Source profile ID
     * @return string|null New profile ID or null on failure
     */
    public static function duplicate(string $profile_id): ?string {
        $source = self::get($profile_id);
        if (!$source) {
            return null;
        }

        $source['name'] = ($source['name'] ?? 'Unnamed') . ' (Copy)';
        unset($source['created_at'], $source['updated_at']);

        return self::save('', $source);
    }

    /**
     * Create a profile from current settings
     *
     * @param string $name Profile name
     * @param string $description Profile description
     * @return string Profile ID
     */
    public static function create_from_current_settings(string $name, string $description = ''): string {
        $settings = WC_Multi_Store_Settings::get_settings();
        $scheduled = get_option('wc_multi_store_sync_scheduled', []);
        $orders = get_option('wc_multi_store_sync_orders', []);
        $email = get_option('wc_multi_store_sync_email_settings', []);

        $profile_data = [
            'name' => $name,
            'description' => $description,
            'sync_settings' => $settings,
            'scheduled_settings' => $scheduled,
            'order_settings' => $orders,
            'email_settings' => $email,
            'stores' => [], // Store configurations are NOT included for security (API keys)
        ];

        return self::save('', $profile_data);
    }

    /**
     * Apply a profile's settings
     *
     * @param string $profile_id Profile ID
     * @param bool $apply_scheduled Also apply scheduled sync settings
     * @param bool $apply_orders Also apply order sync settings
     * @param bool $apply_email Also apply email notification settings
     * @return bool Whether the profile was applied
     */
    public static function apply(
        string $profile_id,
        bool $apply_scheduled = true,
        bool $apply_orders = true,
        bool $apply_email = true
    ): bool {
        $profile = self::get($profile_id);
        if (!$profile) {
            return false;
        }

        // Apply main sync settings
        if (!empty($profile['sync_settings'])) {
            update_option('wc_multi_store_sync_settings', $profile['sync_settings']);
            WC_Multi_Store_Settings::clear_static_cache();
        }

        // Apply scheduled sync settings
        if ($apply_scheduled && !empty($profile['scheduled_settings'])) {
            update_option('wc_multi_store_sync_scheduled', $profile['scheduled_settings']);
        }

        // Apply order sync settings
        if ($apply_orders && !empty($profile['order_settings'])) {
            update_option('wc_multi_store_sync_orders', $profile['order_settings']);
        }

        // Apply email settings
        if ($apply_email && !empty($profile['email_settings'])) {
            update_option('wc_multi_store_sync_email_settings', $profile['email_settings']);
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Sync profile "%s" applied (scheduled: %s, orders: %s, email: %s)',
            $profile['name'] ?? 'Unnamed',
            $apply_scheduled ? 'yes' : 'no',
            $apply_orders ? 'yes' : 'no',
            $apply_email ? 'yes' : 'no'
        ));

        return true;
    }

    /**
     * Export a profile as JSON
     *
     * @param string $profile_id Profile ID
     * @return string|null JSON string or null
     */
    public static function export(string $profile_id): ?string {
        $profile = self::get($profile_id);
        if (!$profile) {
            return null;
        }

        // Remove sensitive data
        unset($profile['stores']);

        return wp_json_encode($profile, JSON_PRETTY_PRINT);
    }

    /**
     * Import a profile from JSON
     *
     * @param string $json JSON string
     * @return string|null Profile ID or null on failure
     */
    public static function import(string $json): ?string {
        $data = json_decode($json, true);
        if (!$data || !is_array($data)) {
            return null;
        }

        // Validate required fields
        if (empty($data['name'])) {
            $data['name'] = 'Imported Profile';
        }

        $data['name'] .= ' (Imported)';

        return self::save('', $data);
    }

    /**
     * Get a list of built-in preset profiles
     */
    public static function get_presets(): array {
        return [
            'full_sync' => [
                'name' => __('Full Product Sync', 'wc-multi-store-sync'),
                'description' => __('Complete product data synchronization with all fields', 'wc-multi-store-sync'),
                'sync_settings' => [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'stock_sync_enabled' => true,
                    'category_auto_create' => true,
                    'auto_sync_on_save' => true,
                    'auto_sync_new_products' => true,
                    'auto_sync_status' => true,
                    'auto_sync_deletions' => true,
                ],
            ],
            'price_stock_only' => [
                'name' => __('Price & Stock Only', 'wc-multi-store-sync'),
                'description' => __('Only sync prices and stock levels - fast and lightweight', 'wc-multi-store-sync'),
                'sync_settings' => [
                    'enabled' => true,
                    'sync_type_default' => 'price_quantity',
                    'stock_sync_enabled' => true,
                    'auto_sync_on_save' => false,
                    'auto_sync_new_products' => false,
                ],
            ],
            'stock_only' => [
                'name' => __('Stock Only', 'wc-multi-store-sync'),
                'description' => __('Minimal sync - only stock quantities', 'wc-multi-store-sync'),
                'sync_settings' => [
                    'enabled' => true,
                    'sync_type_default' => 'quantity',
                    'stock_sync_enabled' => true,
                    'auto_sync_on_save' => false,
                ],
            ],
            'conservative' => [
                'name' => __('Conservative (Manual Only)', 'wc-multi-store-sync'),
                'description' => __('No automatic sync - only manual triggers via admin panel', 'wc-multi-store-sync'),
                'sync_settings' => [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'auto_sync_on_save' => false,
                    'auto_sync_new_products' => false,
                    'auto_sync_status' => false,
                    'auto_sync_deletions' => false,
                ],
            ],
        ];
    }

    /**
     * Apply a built-in preset
     *
     * @param string $preset_key Preset key from get_presets()
     * @return bool Whether the preset was applied
     */
    public static function apply_preset(string $preset_key): bool {
        $presets = self::get_presets();
        if (!isset($presets[$preset_key])) {
            return false;
        }

        $preset = $presets[$preset_key];

        if (!empty($preset['sync_settings'])) {
            $current = WC_Multi_Store_Settings::get_settings();
            $merged = array_merge($current, $preset['sync_settings']);
            update_option('wc_multi_store_sync_settings', $merged);
            WC_Multi_Store_Settings::clear_static_cache();
        }

        WC_Multi_Store_Logger::write(sprintf(
            'Sync preset "%s" applied',
            $preset['name']
        ));

        return true;
    }

    /**
     * AJAX handler: Save a profile
     */
    public static function ajax_save(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $profile_id = sanitize_text_field($_POST['profile_id'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');

        if (empty($name)) {
            wp_send_json_error(['message' => __('Profile name is required', 'wc-multi-store-sync')]);
            return;
        }

        $id = self::create_from_current_settings($name, $description);

        wp_send_json_success([
            'message' => sprintf(__('Profile "%s" saved', 'wc-multi-store-sync'), $name),
            'profile_id' => $id,
        ]);
    }

    /**
     * AJAX handler: Apply a profile
     */
    public static function ajax_apply(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $profile_id = sanitize_text_field($_POST['profile_id'] ?? '');
        $preset_key = sanitize_text_field($_POST['preset_key'] ?? '');

        if ($preset_key) {
            $success = self::apply_preset($preset_key);
        } else {
            $success = self::apply($profile_id);
        }

        if ($success) {
            wp_send_json_success(['message' => __('Profile applied successfully', 'wc-multi-store-sync')]);
        } else {
            wp_send_json_error(['message' => __('Profile not found', 'wc-multi-store-sync')]);
        }
    }

    /**
     * AJAX handler: Delete a profile
     */
    public static function ajax_delete(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $profile_id = sanitize_text_field($_POST['profile_id'] ?? '');

        if (self::delete($profile_id)) {
            wp_send_json_success(['message' => __('Profile deleted', 'wc-multi-store-sync')]);
        } else {
            wp_send_json_error(['message' => __('Profile not found', 'wc-multi-store-sync')]);
        }
    }

    /**
     * AJAX handler: List all profiles
     */
    public static function ajax_list(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        wp_send_json_success([
            'profiles' => self::get_all(),
            'presets' => self::get_presets(),
        ]);
    }
}
