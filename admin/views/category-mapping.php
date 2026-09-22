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
    <h1><?php esc_html_e('Category & Tag Mapping', 'multi-store-sync-for-woocommerce'); ?></h1>
    <p class="description">
        <?php esc_html_e('Map local categories and tags to different names on a specific remote store — e.g. "Дрехи" on your main store becomes "Clothing" on a store that sells in English. Leave a row unmapped to send the category/tag as-is, or map it to "Skip" to leave it off that store entirely.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <?php if (!$mapping_enabled): ?>
        <div class="notice notice-warning inline">
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to the Settings tab */
                        __('Category Mapping is currently disabled, so mappings saved here will not be applied during sync. Enable it in %s.', 'multi-store-sync-for-woocommerce'),
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
            <label for="wc-mss-map-store-select"><strong><?php esc_html_e('Store:', 'multi-store-sync-for-woocommerce'); ?></strong></label>
            <select id="wc-mss-map-store-select">
                <option value=""><?php esc_html_e('— Select a store —', 'multi-store-sync-for-woocommerce'); ?></option>
                <?php foreach ($stores as $url => $config): ?>
                    <option value="<?php echo esc_attr($url); ?>">
                        <?php echo esc_html($config['name'] ?? $url); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="wc-mss-map-loading" style="display:none;"><p><?php esc_html_e('Loading…', 'multi-store-sync-for-woocommerce'); ?></p></div>

        <div id="wc-mss-map-tables" style="display:none;">
            <h2><?php esc_html_e('Category Mapping', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-map-category-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Local Category', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Maps To', 'multi-store-sync-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-map-save-categories">
                    <?php esc_html_e('Save Category Mappings', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <span class="wc-mss-map-save-status" data-for="categories"></span>
            </p>

            <h2><?php esc_html_e('Tag Mapping', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-map-tag-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Local Tag', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Maps To', 'multi-store-sync-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p>
                <button type="button" class="button button-primary" id="wc-mss-map-save-tags">
                    <?php esc_html_e('Save Tag Mappings', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <span class="wc-mss-map-save-status" data-for="tags"></span>
            </p>
        </div>

        <div id="wc-mss-map-empty" style="display:none;">
            <p class="description"><?php esc_html_e('This store has no local categories or tags to map.', 'multi-store-sync-for-woocommerce'); ?></p>
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
