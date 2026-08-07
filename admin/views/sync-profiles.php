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
    <h2><?php esc_html_e('Sync Profiles', 'wc-multi-store-sync'); ?></h2>
    <p class="description">
        <?php esc_html_e('Save your current settings as a named profile to quickly switch between configurations. Built-in presets are always available.', 'wc-multi-store-sync'); ?>
    </p>

    <div id="wc-mss-profiles-notices"></div>

    <!-- Save current settings as profile -->
    <div class="wc-mss-card" style="padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Save Current Settings as Profile', 'wc-multi-store-sync'); ?></h3>
        <table class="form-table" style="max-width: 600px;">
            <tr>
                <th><label for="wc-mss-profile-name"><?php esc_html_e('Profile Name', 'wc-multi-store-sync'); ?></label></th>
                <td><input type="text" id="wc-mss-profile-name" class="regular-text" placeholder="<?php esc_attr_e('e.g. Peak Season Config', 'wc-multi-store-sync'); ?>"></td>
            </tr>
            <tr>
                <th><label for="wc-mss-profile-description"><?php esc_html_e('Description', 'wc-multi-store-sync'); ?></label></th>
                <td><textarea id="wc-mss-profile-description" class="large-text" rows="2" placeholder="<?php esc_attr_e('Optional description', 'wc-multi-store-sync'); ?>"></textarea></td>
            </tr>
        </table>
        <button type="button" id="wc-mss-save-profile-btn" class="button button-primary">
            <?php esc_html_e('Save Profile', 'wc-multi-store-sync'); ?>
        </button>
    </div>

    <!-- Built-in Presets -->
    <div class="wc-mss-card" style="padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Built-in Presets', 'wc-multi-store-sync'); ?></h3>
        <p class="description"><?php esc_html_e('Apply a preset to instantly configure the plugin for a common use case. This will overwrite current settings.', 'wc-multi-store-sync'); ?></p>

        <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Preset', 'wc-multi-store-sync'); ?></th>
                    <th><?php esc_html_e('Description', 'wc-multi-store-sync'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('Actions', 'wc-multi-store-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($presets as $key => $preset) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($preset['name']); ?></strong></td>
                        <td><?php echo esc_html($preset['description']); ?></td>
                        <td>
                            <button type="button"
                                class="button wc-mss-apply-preset"
                                data-preset="<?php echo esc_attr($key); ?>"
                                data-name="<?php echo esc_attr($preset['name']); ?>">
                                <?php esc_html_e('Apply', 'wc-multi-store-sync'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Saved Profiles -->
    <div class="wc-mss-card" style="padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Saved Profiles', 'wc-multi-store-sync'); ?></h3>

        <?php if (empty($profiles)) : ?>
            <p class="description"><?php esc_html_e('No saved profiles yet. Save your current settings above to create one.', 'wc-multi-store-sync'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-profiles-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'wc-multi-store-sync'); ?></th>
                        <th><?php esc_html_e('Description', 'wc-multi-store-sync'); ?></th>
                        <th><?php esc_html_e('Saved', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 180px;"><?php esc_html_e('Actions', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profiles as $id => $profile) : ?>
                        <tr id="wc-mss-profile-row-<?php echo esc_attr($id); ?>">
                            <td><strong><?php echo esc_html($profile['name'] ?? __('Unnamed', 'wc-multi-store-sync')); ?></strong></td>
                            <td><?php echo esc_html($profile['description'] ?? ''); ?></td>
                            <td><?php echo esc_html($profile['updated_at'] ?? $profile['created_at'] ?? '—'); ?></td>
                            <td>
                                <button type="button"
                                    class="button wc-mss-apply-profile"
                                    data-id="<?php echo esc_attr($id); ?>"
                                    data-name="<?php echo esc_attr($profile['name'] ?? ''); ?>">
                                    <?php esc_html_e('Apply', 'wc-multi-store-sync'); ?>
                                </button>
                                <button type="button"
                                    class="button wc-mss-export-profile"
                                    data-id="<?php echo esc_attr($id); ?>">
                                    <?php esc_html_e('Export', 'wc-multi-store-sync'); ?>
                                </button>
                                <button type="button"
                                    class="button button-link-delete wc-mss-delete-profile"
                                    data-id="<?php echo esc_attr($id); ?>"
                                    data-name="<?php echo esc_attr($profile['name'] ?? ''); ?>"
                                    style="color: #d63638;">
                                    <?php esc_html_e('Delete', 'wc-multi-store-sync'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
