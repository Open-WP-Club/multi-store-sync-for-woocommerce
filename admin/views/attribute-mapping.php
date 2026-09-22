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
    <h1><?php esc_html_e('Attribute Mapping', 'multi-store-sync-for-woocommerce'); ?></h1>
    <p class="description">
        <?php esc_html_e('Map local attribute names and values to different names on a specific remote store — e.g. "Цвят" on your main store becomes "Color" on a store that sells in English. Leave a row unmapped to send the attribute as-is, or map it to "Skip" to leave it off that store entirely.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <?php if (!$mapping_enabled): ?>
        <div class="notice notice-warning inline">
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to the Settings tab */
                        __('Attribute Mapping is currently disabled, so mappings saved here will not be applied during sync. Enable it in %s.', 'multi-store-sync-for-woocommerce'),
                        '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'multi-store-sync-for-woocommerce') . '</a>'
                    ),
                    ['a' => ['href' => []]]
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (empty($stores)): ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e('No stores configured yet. Add a store first in the Stores tab.', 'multi-store-sync-for-woocommerce'); ?></p>
        </div>
    <?php else: ?>

        <div style="margin: 1rem 0;">
            <label for="wc-mss-attr-map-store-select"><strong><?php esc_html_e('Store:', 'multi-store-sync-for-woocommerce'); ?></strong></label>
            <select id="wc-mss-attr-map-store-select">
                <option value=""><?php esc_html_e('— Select a store —', 'multi-store-sync-for-woocommerce'); ?></option>
                <?php foreach ($stores as $url => $config): ?>
                    <option value="<?php echo esc_attr($url); ?>">
                        <?php echo esc_html($config['name'] ?? $url); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="wc-mss-attr-map-loading" style="display:none;"><p><?php esc_html_e('Loading…', 'multi-store-sync-for-woocommerce'); ?></p></div>

        <div id="wc-mss-attr-map-tables" style="display:none;">
            <h2><?php esc_html_e('Attribute Name Mapping', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-attr-map-name-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Local Attribute', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Maps To', 'multi-store-sync-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-attr-map-save-names">
                    <?php esc_html_e('Save Name Mappings', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <span class="wc-mss-attr-map-save-status" data-for="names"></span>
            </p>

            <h2><?php esc_html_e('Attribute Value Mapping', 'multi-store-sync-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Expand an attribute below to map its individual values (e.g. "Червен" → "Red").', 'multi-store-sync-for-woocommerce'); ?>
            </p>
            <div id="wc-mss-attr-map-value-groups"></div>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-attr-map-save-values">
                    <?php esc_html_e('Save Value Mappings', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <span class="wc-mss-attr-map-save-status" data-for="values"></span>
            </p>
        </div>

        <div id="wc-mss-attr-map-empty" style="display:none;">
            <p class="description"><?php esc_html_e('This store has no local product attributes to map.', 'multi-store-sync-for-woocommerce'); ?></p>
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
