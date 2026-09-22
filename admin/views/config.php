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
    <h2><?php esc_html_e('Export / Import Configuration', 'multi-store-sync-for-woocommerce'); ?></h2>
    <p class="description"><?php esc_html_e('Export your plugin configuration for backup or transfer to another installation.', 'multi-store-sync-for-woocommerce'); ?></p>

    <div class="wc-mss-dashboard-grid" style="display: flex; gap: 20px; margin-top: 20px;">
        <!-- Export -->
        <div class="wc-mss-card" style="flex: 1; padding: 20px;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Export Configuration', 'multi-store-sync-for-woocommerce'); ?></h3>
            <p><?php esc_html_e('Download current settings, store configurations, and all plugin options as a JSON file.', 'multi-store-sync-for-woocommerce'); ?></p>

            <label style="display: block; margin-bottom: 15px;">
                <input type="checkbox" id="wc-mss-export-include-keys">
                <?php esc_html_e('Include API keys', 'multi-store-sync-for-woocommerce'); ?>
                <span class="description" style="display: block; margin-top: 4px; color: #d63638;">
                    <?php esc_html_e('Warning: API keys will be stored in plain text in the export file.', 'multi-store-sync-for-woocommerce'); ?>
                </span>
            </label>

            <button type="button" id="wc-mss-export-btn" class="button button-primary"
                data-label-loading="<?php echo esc_attr__('Exporting...', 'multi-store-sync-for-woocommerce'); ?>"
                data-success-message="<?php echo esc_attr__('Configuration exported successfully.', 'multi-store-sync-for-woocommerce'); ?>">
                <?php esc_html_e('Export Configuration', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <div id="wc-mss-export-result" style="margin-top: 10px;"></div>
        </div>

        <!-- Import -->
        <div class="wc-mss-card" style="flex: 1; padding: 20px;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Import Configuration', 'multi-store-sync-for-woocommerce'); ?></h3>
            <p><?php esc_html_e('Upload a previously exported JSON configuration file to restore settings.', 'multi-store-sync-for-woocommerce'); ?></p>

            <div style="margin-bottom: 15px;">
                <input type="file" id="wc-mss-import-file" accept=".json" style="margin-bottom: 10px;">
                <p class="description"><?php esc_html_e('If API keys were redacted during export, existing keys will be preserved.', 'multi-store-sync-for-woocommerce'); ?></p>
            </div>

            <button type="button" id="wc-mss-import-btn" class="button" disabled
                data-label-loading="<?php echo esc_attr__('Importing...', 'multi-store-sync-for-woocommerce'); ?>"
                data-confirm="<?php echo esc_attr__('This will overwrite current settings. Are you sure?', 'multi-store-sync-for-woocommerce'); ?>">
                <?php esc_html_e('Import Configuration', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <div id="wc-mss-import-result" style="margin-top: 10px;"></div>
        </div>
    </div>

    <!-- Config Preview -->
    <div class="wc-mss-card" style="margin-top: 20px; padding: 20px;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Current Configuration Preview', 'multi-store-sync-for-woocommerce'); ?></h3>
        <p class="description"><?php esc_html_e('A read-only preview of the configuration that would be exported (API keys redacted).', 'multi-store-sync-for-woocommerce'); ?></p>
        <textarea id="wc-mss-config-preview" readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; background: #f6f7f7;"><?php
            echo esc_textarea(wp_json_encode(WC_Multi_Store_Config_Manager::export(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        ?></textarea>
    </div>
</div>
