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

            <button type="button" id="wc-mss-export-btn" class="button button-primary">
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

            <button type="button" id="wc-mss-import-btn" class="button" disabled>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo esc_js(wp_create_nonce('wc_mss_admin')); ?>';

    // Export
    document.getElementById('wc-mss-export-btn').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.textContent = '<?php echo esc_js(__('Exporting...', 'wc-multi-store-sync')); ?>';

        var includeKeys = document.getElementById('wc-mss-export-include-keys').checked;

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=wc_mss_export_config&nonce=' + nonce + '&include_keys=' + (includeKeys ? '1' : '0')
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                // Download as file
                var blob = new Blob([JSON.stringify(data.data.config, null, 2)], {type: 'application/json'});
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = data.data.filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                document.getElementById('wc-mss-export-result').innerHTML = '<div class="notice inline notice-success"><p><?php echo esc_js(__('Configuration exported successfully.', 'wc-multi-store-sync')); ?></p></div>';
            } else {
                document.getElementById('wc-mss-export-result').innerHTML = '<div class="notice inline notice-error"><p>' + (data.data.message || 'Error') + '</p></div>';
            }
            btn.disabled = false;
            btn.textContent = '<?php echo esc_js(__('Export Configuration', 'wc-multi-store-sync')); ?>';
        });
    });

    // File selection
    document.getElementById('wc-mss-import-file').addEventListener('change', function() {
        document.getElementById('wc-mss-import-btn').disabled = !this.files.length;
    });

    // Import
    document.getElementById('wc-mss-import-btn').addEventListener('click', function() {
        if (!confirm('<?php echo esc_js(__('This will overwrite current settings. Are you sure?', 'wc-multi-store-sync')); ?>')) return;

        var btn = this;
        var fileInput = document.getElementById('wc-mss-import-file');
        var file = fileInput.files[0];
        if (!file) return;

        btn.disabled = true;
        btn.textContent = '<?php echo esc_js(__('Importing...', 'wc-multi-store-sync')); ?>';

        var reader = new FileReader();
        reader.onload = function(e) {
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=wc_mss_import_config&nonce=' + nonce + '&config=' + encodeURIComponent(e.target.result)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.getElementById('wc-mss-import-result').innerHTML = '<div class="notice inline notice-success"><p>' + data.data.message + '</p></div>';
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    document.getElementById('wc-mss-import-result').innerHTML = '<div class="notice inline notice-error"><p>' + (data.data.message || 'Error') + '</p></div>';
                }
                btn.disabled = false;
                btn.textContent = '<?php echo esc_js(__('Import Configuration', 'wc-multi-store-sync')); ?>';
            });
        };
        reader.readAsText(file);
    });
});
</script>
