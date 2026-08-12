<?php
/**
 * Conflict Detector Admin Page
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

$conflict_settings = WC_Multi_Store_Conflict_Detector::get_settings();
$stats = WC_Multi_Store_Conflict_Detector::get_stats();
?>

<div class="wrap wc-mss-conflicts-page">
    <h1>
        <?php _e('Sync Conflicts', 'wc-multi-store-sync'); ?>
        <?php if ($stats['unresolved'] > 0): ?>
            <span class="wc-mss-count-badge wc-mss-count-badge-error"><?php echo esc_html($stats['unresolved']); ?></span>
        <?php endif; ?>
    </h1>

    <p class="description">
        <?php _e('Products that were edited directly on a remote store since the last sync are flagged here before they get overwritten. This requires "Conflict Detection" to be enabled under Settings → Additional Sync Features.', 'wc-multi-store-sync'); ?>
    </p>

    <?php if (empty($conflict_settings['enabled'])): ?>
        <div class="notice notice-warning inline">
            <p>
                <?php printf(
                    /* translators: %s: link to the Settings tab */
                    __('Conflict detection is currently disabled. Enable it in %s to start tracking remote edits.', 'wc-multi-store-sync'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=settings')) . '">' . __('Settings', 'wc-multi-store-sync') . '</a>'
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="wc-mss-conflicts-filters" style="margin: 20px 0; background: #fff; padding: 0.9375rem; border: 0.0625rem solid #ccd0d4; border-radius: 0.25rem; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <select id="wc-mss-conflicts-status-filter">
            <option value="unresolved"><?php _e('Unresolved', 'wc-multi-store-sync'); ?></option>
            <option value="all"><?php _e('All', 'wc-multi-store-sync'); ?></option>
        </select>

        <select id="wc-mss-conflicts-store-filter">
            <option value=""><?php _e('All Stores', 'wc-multi-store-sync'); ?></option>
            <?php foreach ($stores as $store_url => $store_config): ?>
                <?php if (($store_config['status'] ?? '') === 'active'): ?>
                    <option value="<?php echo esc_attr($store_url); ?>">
                        <?php echo esc_html($store_config['name'] ?? $store_url); ?>
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>

        <span style="flex: 1 1 auto;"></span>

        <select id="wc-mss-conflicts-resolution">
            <option value="overwrite"><?php _e('Overwrite', 'wc-multi-store-sync'); ?></option>
            <option value="keep_remote"><?php _e('Keep Remote', 'wc-multi-store-sync'); ?></option>
            <option value="merge"><?php _e('Merge', 'wc-multi-store-sync'); ?></option>
        </select>
        <button type="button" class="button" id="wc-mss-conflicts-resolve-all-btn">
            <?php _e('Resolve All', 'wc-multi-store-sync'); ?>
        </button>
    </div>

    <div id="wc-mss-conflicts-notices"></div>

    <div id="wc-mss-conflicts-results">
        <p><?php _e('Loading…', 'wc-multi-store-sync'); ?></p>
    </div>
</div>

<style>
.wc-mss-conflicts-page .wc-mss-status-badge {
    display: inline-block;
    padding: 0.1875rem 0.625rem;
    border-radius: 0.1875rem;
    font-size: 0.6875rem;
    font-weight: bold;
    text-transform: uppercase;
}

.wc-mss-conflicts-page .wc-mss-status-unresolved {
    background: #ffb900;
    color: #000;
}

.wc-mss-conflicts-page .wc-mss-status-resolved {
    background: #46b450;
    color: #fff;
}

.wc-mss-conflicts-page .wc-mss-changed-field-tag {
    display: inline-block;
    background: #f0f0f1;
    border-radius: 0.1875rem;
    padding: 0.0625rem 0.375rem;
    margin: 0.0625rem;
    font-size: 0.75rem;
    font-family: monospace;
}
</style>
