<?php
/**
 * Category/Tag Mapping Admin View
 *
 * Lets the admin map local categories/tags to different remote
 * categories/tags per store, since apply_mappings()/apply_tag_mappings()
 * (called from product-transformer.php on every sync) already read whatever
 * is saved here — this page is just the missing UI for entering that data.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

$stores = WC_Multi_Store_Settings::get_stores();
$mapping_enabled = WC_Multi_Store_Category_Mapper::is_enabled();
$settings_url = admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=settings');
?>

<div class="wrap wc-mss-category-mapping-page">
    <h1><?php _e('Category & Tag Mapping', 'wc-multi-store-sync'); ?></h1>
    <p class="description">
        <?php _e('Map local categories and tags to different names on a specific remote store — e.g. "Дрехи" on your main store becomes "Clothing" on a store that sells in English. Leave a row unmapped to send the category/tag as-is, or map it to "Skip" to leave it off that store entirely.', 'wc-multi-store-sync'); ?>
    </p>

    <?php if (!$mapping_enabled): ?>
        <div class="notice notice-warning inline">
            <p>
                <?php printf(
                    /* translators: %s: link to the Settings tab */
                    __('Category Mapping is currently disabled, so mappings saved here will not be applied during sync. Enable it in %s.', 'wc-multi-store-sync'),
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
            <label for="wc-mss-map-store-select"><strong><?php _e('Store:', 'wc-multi-store-sync'); ?></strong></label>
            <select id="wc-mss-map-store-select">
                <option value=""><?php _e('— Select a store —', 'wc-multi-store-sync'); ?></option>
                <?php foreach ($stores as $url => $config): ?>
                    <option value="<?php echo esc_attr($url); ?>">
                        <?php echo esc_html($config['name'] ?? $url); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="wc-mss-map-loading" style="display:none;"><p><?php _e('Loading…', 'wc-multi-store-sync'); ?></p></div>

        <div id="wc-mss-map-tables" style="display:none;">
            <h2><?php _e('Category Mapping', 'wc-multi-store-sync'); ?></h2>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-map-category-table">
                <thead>
                    <tr>
                        <th><?php _e('Local Category', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Maps To', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-map-save-categories">
                    <?php _e('Save Category Mappings', 'wc-multi-store-sync'); ?>
                </button>
                <span class="wc-mss-map-save-status" data-for="categories"></span>
            </p>

            <h2><?php _e('Tag Mapping', 'wc-multi-store-sync'); ?></h2>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-map-tag-table">
                <thead>
                    <tr>
                        <th><?php _e('Local Tag', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Maps To', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-map-save-tags">
                    <?php _e('Save Tag Mappings', 'wc-multi-store-sync'); ?>
                </button>
                <span class="wc-mss-map-save-status" data-for="tags"></span>
            </p>
        </div>

        <div id="wc-mss-map-empty" style="display:none;">
            <p class="description"><?php _e('This store has no local categories or tags to map.', 'wc-multi-store-sync'); ?></p>
        </div>

    <?php endif; ?>
</div>

<style>
.wc-mss-category-mapping-page select.wc-mss-map-remote-select {
    width: 100%;
    max-width: 25rem;
}

.wc-mss-map-save-status {
    margin-left: 0.75rem;
    font-weight: 600;
}

.wc-mss-map-save-status.success {
    color: #00a32a;
}

.wc-mss-map-save-status.error {
    color: #d63638;
}
</style>
