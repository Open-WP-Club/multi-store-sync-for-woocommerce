<?php
/**
 * Attribute Name/Value Mapping Admin View
 *
 * Lets the admin map local attribute names and values to different remote
 * names/values per store, since apply_mappings()/apply_variation_mappings()/
 * apply_default_attribute_mappings() (called from product-transformer.php on
 * every sync) already read whatever is saved here — this page is just the
 * missing UI for entering that data.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

$stores = WC_Multi_Store_Settings::get_stores();
$mapping_enabled = WC_Multi_Store_Attribute_Remapper::is_enabled();
$settings_url = admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=settings');
?>

<div class="wrap wc-mss-attribute-mapping-page">
    <h1><?php _e('Attribute Mapping', 'wc-multi-store-sync'); ?></h1>
    <p class="description">
        <?php _e('Map local attribute names and values to different names on a specific remote store — e.g. "Цвят" on your main store becomes "Color" on a store that sells in English. Leave a row unmapped to send the attribute as-is, or map it to "Skip" to leave it off that store entirely.', 'wc-multi-store-sync'); ?>
    </p>

    <?php if (!$mapping_enabled): ?>
        <div class="notice notice-warning inline">
            <p>
                <?php printf(
                    /* translators: %s: link to the Settings tab */
                    __('Attribute Mapping is currently disabled, so mappings saved here will not be applied during sync. Enable it in %s.', 'wc-multi-store-sync'),
                    '<a href="' . esc_url($settings_url) . '">' . __('Settings', 'wc-multi-store-sync') . '</a>'
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (empty($stores)): ?>
        <div class="notice notice-info inline">
            <p><?php _e('No stores configured yet. Add a store first in the Stores tab.', 'wc-multi-store-sync'); ?></p>
        </div>
    <?php else: ?>

        <div style="margin: 1rem 0;">
            <label for="wc-mss-attr-map-store-select"><strong><?php _e('Store:', 'wc-multi-store-sync'); ?></strong></label>
            <select id="wc-mss-attr-map-store-select">
                <option value=""><?php _e('— Select a store —', 'wc-multi-store-sync'); ?></option>
                <?php foreach ($stores as $url => $config): ?>
                    <option value="<?php echo esc_attr($url); ?>">
                        <?php echo esc_html($config['name'] ?? $url); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="wc-mss-attr-map-loading" style="display:none;"><p><?php _e('Loading…', 'wc-multi-store-sync'); ?></p></div>

        <div id="wc-mss-attr-map-tables" style="display:none;">
            <h2><?php _e('Attribute Name Mapping', 'wc-multi-store-sync'); ?></h2>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-attr-map-name-table">
                <thead>
                    <tr>
                        <th><?php _e('Local Attribute', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Maps To', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-attr-map-save-names">
                    <?php _e('Save Name Mappings', 'wc-multi-store-sync'); ?>
                </button>
                <span class="wc-mss-attr-map-save-status" data-for="names"></span>
            </p>

            <h2><?php _e('Attribute Value Mapping', 'wc-multi-store-sync'); ?></h2>
            <p class="description">
                <?php _e('Expand an attribute below to map its individual values (e.g. "Червен" → "Red").', 'wc-multi-store-sync'); ?>
            </p>
            <div id="wc-mss-attr-map-value-groups"></div>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-attr-map-save-values">
                    <?php _e('Save Value Mappings', 'wc-multi-store-sync'); ?>
                </button>
                <span class="wc-mss-attr-map-save-status" data-for="values"></span>
            </p>
        </div>

        <div id="wc-mss-attr-map-empty" style="display:none;">
            <p class="description"><?php _e('This store has no local product attributes to map.', 'wc-multi-store-sync'); ?></p>
        </div>

    <?php endif; ?>
</div>

<style>
.wc-mss-attribute-mapping-page select.wc-mss-attr-map-remote-select {
    width: 100%;
    max-width: 25rem;
}

.wc-mss-attribute-mapping-page input.wc-mss-attr-map-value-input {
    width: 100%;
    max-width: 20rem;
}

.wc-mss-attr-map-save-status {
    margin-left: 0.75rem;
    font-weight: 600;
}

.wc-mss-attr-map-save-status.success {
    color: #00a32a;
}

.wc-mss-attr-map-save-status.error {
    color: #d63638;
}

.wc-mss-attribute-mapping-page details.wc-mss-attr-map-value-group {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    margin-bottom: 0.75rem;
    padding: 0.75rem 1rem;
}

.wc-mss-attribute-mapping-page details.wc-mss-attr-map-value-group summary {
    cursor: pointer;
    font-weight: 600;
}

.wc-mss-attribute-mapping-page table.wc-mss-attr-map-value-table {
    margin-top: 0.75rem;
}
</style>
