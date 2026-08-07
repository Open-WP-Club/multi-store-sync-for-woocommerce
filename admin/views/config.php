<?php
/**
 * Configuration Export/Import View
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h2><?php _e('Export / Import Configuration', 'wc-multi-store-sync'); ?></h2>
    <p class="description"><?php _e('Export your plugin configuration for backup or transfer to another installation.', 'wc-multi-store-sync'); ?></p>

    <div class="wc-mss-dashboard-grid" style="display: flex; gap: 20px; margin-top: 20px;">
        <!-- Export -->
        <div class="wc-mss-card" style="flex: 1; padding: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Export Configuration', 'wc-multi-store-sync'); ?></h3>
            <p><?php _e('Download current settings, store configurations, and all plugin options as a JSON file.', 'wc-multi-store-sync'); ?></p>

            <label style="display: block; margin-bottom: 15px;">
                <input type="checkbox" id="wc-mss-export-include-keys">
                <?php _e('Include API keys', 'wc-multi-store-sync'); ?>
                <span class="description" style="display: block; margin-top: 4px; color: #d63638;">
                    <?php _e('Warning: API keys will be stored in plain text in the export file.', 'wc-multi-store-sync'); ?>
                </span>
            </label>

            <button type="button" id="wc-mss-export-btn" class="button button-primary"
                data-label-loading="<?php echo esc_attr__('Exporting...', 'wc-multi-store-sync'); ?>"
                data-success-message="<?php echo esc_attr__('Configuration exported successfully.', 'wc-multi-store-sync'); ?>">
                <?php _e('Export Configuration', 'wc-multi-store-sync'); ?>
            </button>
            <div id="wc-mss-export-result" style="margin-top: 10px;"></div>
        </div>

        <!-- Import -->
        <div class="wc-mss-card" style="flex: 1; padding: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Import Configuration', 'wc-multi-store-sync'); ?></h3>
            <p><?php _e('Upload a previously exported JSON configuration file to restore settings.', 'wc-multi-store-sync'); ?></p>

            <div style="margin-bottom: 15px;">
                <input type="file" id="wc-mss-import-file" accept=".json" style="margin-bottom: 10px;">
                <p class="description"><?php _e('If API keys were redacted during export, existing keys will be preserved.', 'wc-multi-store-sync'); ?></p>
            </div>

            <button type="button" id="wc-mss-import-btn" class="button" disabled
                data-label-loading="<?php echo esc_attr__('Importing...', 'wc-multi-store-sync'); ?>"
                data-confirm="<?php echo esc_attr__('This will overwrite current settings. Are you sure?', 'wc-multi-store-sync'); ?>">
                <?php _e('Import Configuration', 'wc-multi-store-sync'); ?>
            </button>
            <div id="wc-mss-import-result" style="margin-top: 10px;"></div>
        </div>
    </div>

    <!-- Config Preview -->
    <div class="wc-mss-card" style="margin-top: 20px; padding: 20px;">
        <h3 style="margin-top: 0;"><?php _e('Current Configuration Preview', 'wc-multi-store-sync'); ?></h3>
        <p class="description"><?php _e('A read-only preview of the configuration that would be exported (API keys redacted).', 'wc-multi-store-sync'); ?></p>
        <textarea id="wc-mss-config-preview" readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; background: #f6f7f7;"><?php
            echo esc_textarea(wp_json_encode(WC_Multi_Store_Config_Manager::export(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        ?></textarea>
    </div>
</div>
