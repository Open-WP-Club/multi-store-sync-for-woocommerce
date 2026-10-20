<?php
/**
 * Sync Profiles View
 *
 * @package WC_Multi_Store_Sync
 * @var array $profiles  Saved profiles from WC_Multi_Store_Sync_Profiles::get_all()
 * @var array $presets   Built-in presets from WC_Multi_Store_Sync_Profiles::get_presets()
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-mss-sync-profiles">
    <h2><?php esc_html_e('Sync Profiles', 'multi-store-sync-for-woocommerce'); ?></h2>
    <p class="description">
        <?php esc_html_e('Save your current settings as a named profile to quickly switch between configurations. Built-in presets are always available.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <div id="wc-mss-profiles-notices"></div>

    <!-- Save current settings as profile -->
    <div class="card wc-mss-card">
        <h3><?php esc_html_e('Save Current Settings as Profile', 'multi-store-sync-for-woocommerce'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="wc-mss-profile-name"><?php esc_html_e('Profile Name', 'multi-store-sync-for-woocommerce'); ?></label></th>
                <td><input type="text" id="wc-mss-profile-name" class="regular-text" placeholder="<?php esc_attr_e('e.g. Peak Season Config', 'multi-store-sync-for-woocommerce'); ?>"></td>
            </tr>
            <tr>
                <th><label for="wc-mss-profile-description"><?php esc_html_e('Description', 'multi-store-sync-for-woocommerce'); ?></label></th>
                <td><textarea id="wc-mss-profile-description" class="large-text" rows="2" placeholder="<?php esc_attr_e('Optional description', 'multi-store-sync-for-woocommerce'); ?>"></textarea></td>
            </tr>
        </table>
        <button type="button" id="wc-mss-save-profile-btn" class="button button-primary">
            <?php esc_html_e('Save Profile', 'multi-store-sync-for-woocommerce'); ?>
        </button>
    </div>

    <!-- Built-in Presets -->
    <div class="card wc-mss-card">
        <h3><?php esc_html_e('Built-in Presets', 'multi-store-sync-for-woocommerce'); ?></h3>
        <p class="description"><?php esc_html_e('Apply a preset to instantly configure the plugin for a common use case. This will overwrite current settings.', 'multi-store-sync-for-woocommerce'); ?></p>

        <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('presets', [
            'presets-preset' => __('Preset', 'multi-store-sync-for-woocommerce'),
            'presets-description' => __('Description', 'multi-store-sync-for-woocommerce'),
            'presets-actions' => __('Actions', 'multi-store-sync-for-woocommerce')
        ], array_map(static fn($key, $value): array => ['key' => $key, 'value' => $value], array_keys($presets), array_values($presets)), static function (array $row): void {
            $key = $row['key'];
            $preset = $row['value'];
            ?>
            <td><strong><?php echo esc_html($preset['name']); ?></strong></td>
            <td><?php echo esc_html($preset['description']); ?></td>
            <td>
                <button type="button"
                    class="button wc-mss-apply-preset"
                    data-preset="<?php echo esc_attr($key); ?>"
                    data-name="<?php echo esc_attr($preset['name']); ?>">
                    <?php esc_html_e('Apply', 'multi-store-sync-for-woocommerce'); ?>
                </button>
            </td>
            <?php
        });
        $list_table->display();
        ?>
    </div>

    <!-- Saved Profiles -->
    <div class="card wc-mss-card">
        <h3><?php esc_html_e('Saved Profiles', 'multi-store-sync-for-woocommerce'); ?></h3>

        <?php if (empty($profiles)) : ?>
            <p class="description"><?php esc_html_e('No saved profiles yet. Save your current settings above to create one.', 'multi-store-sync-for-woocommerce'); ?></p>
        <?php else : ?>
            <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('saved-profiles', [
            'saved-profiles-name' => __('Name', 'multi-store-sync-for-woocommerce'),
            'saved-profiles-description' => __('Description', 'multi-store-sync-for-woocommerce'),
            'saved-profiles-saved' => __('Saved', 'multi-store-sync-for-woocommerce'),
            'saved-profiles-actions' => __('Actions', 'multi-store-sync-for-woocommerce')
        ], array_map(static fn($key, $value): array => ['key' => $key, 'value' => $value], array_keys($profiles), array_values($profiles)), static function (array $row): void {
            $id = $row['key'];
            $profile = $row['value'];
            ?>
            <td><strong><?php echo esc_html($profile['name'] ?? __('Unnamed', 'multi-store-sync-for-woocommerce')); ?></strong></td>
            <td><?php echo esc_html($profile['description'] ?? ''); ?></td>
            <td><?php echo esc_html($profile['updated_at'] ?? $profile['created_at'] ?? '—'); ?></td>
            <td>
                <button type="button"
                    class="button wc-mss-apply-profile"
                    data-id="<?php echo esc_attr($id); ?>"
                    data-name="<?php echo esc_attr($profile['name'] ?? ''); ?>">
                    <?php esc_html_e('Apply', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <button type="button"
                    class="button wc-mss-export-profile"
                    data-id="<?php echo esc_attr($id); ?>">
                    <?php esc_html_e('Export', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <button type="button"
                    class="button button-link-delete wc-mss-delete-profile"
                    data-id="<?php echo esc_attr($id); ?>"
                    data-name="<?php echo esc_attr($profile['name'] ?? ''); ?>">
                    <?php esc_html_e('Delete', 'multi-store-sync-for-woocommerce'); ?>
                </button>
            </td>
            <?php
        }, null, null, static fn(array $row): string => 'wc-mss-profile-row-' . $row['key']);
        $list_table->display();
        ?>
        <?php endif; ?>
    </div>
</div>
