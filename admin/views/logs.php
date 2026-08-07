<?php
/**
 * Logs View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-mss-logs">
    <h1><?php _e('Sync Logs', 'wc-multi-store-sync'); ?></h1>

    <div class="wc-mss-card">
        <h2><?php _e('Recent Activity', 'wc-multi-store-sync'); ?></h2>

        <div class="wc-mss-log-toolbar">
            <label>
                <input type="checkbox" id="wc-mss-reverse-logs">
                <?php _e('Newest first', 'wc-multi-store-sync'); ?>
            </label>
            <label>
                <input type="checkbox" id="wc-mss-auto-scroll" checked>
                <?php _e('Auto-scroll to latest', 'wc-multi-store-sync'); ?>
            </label>
            <div class="wc-mss-log-search">
                <input type="text" id="wc-mss-log-filter" placeholder="<?php esc_attr_e('Filter logs...', 'wc-multi-store-sync'); ?>">
            </div>
        </div>

        <div class="wc-mss-log-viewer" id="wc-mss-log-viewer">
            <?php if (!empty($logs)): ?>
                <pre class="wc-mss-log-content" id="wc-mss-log-content"><?php echo esc_html($logs); ?></pre>
            <?php else: ?>
                <p style="padding: 15px; color: #888;"><?php _e('No log entries found.', 'wc-multi-store-sync'); ?></p>
            <?php endif; ?>
        </div>

        <div class="wc-mss-log-actions" style="margin-top: 15px;">
            <button type="button" class="button" onclick="location.reload();">
                <?php _e('Refresh', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" class="button" id="wc-mss-scroll-bottom">
                <?php _e('Scroll to Bottom', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" class="button" id="wc-mss-scroll-top">
                <?php _e('Scroll to Top', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" class="button button-link-delete" id="wc-mss-clear-log" style="margin-left: 10px;">
                <?php _e('Clear Logs', 'wc-multi-store-sync'); ?>
            </button>
        </div>
    </div>

    <div class="wc-mss-card" id="wc-mss-issues-card">
        <h2><?php _e('Warnings &amp; Errors', 'wc-multi-store-sync'); ?> <span id="wc-mss-issues-count" style="font-size:14px;font-weight:normal;color:#888;"></span></h2>
        <p class="description" style="margin-bottom:10px;"><?php _e('Only [WARNING] and [ERROR] entries from the current log. This is the first place to look when something seems off.', 'wc-multi-store-sync'); ?></p>

        <div id="wc-mss-issues-viewer" style="background:#1d2327;border-radius:4px;padding:12px 16px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.6;">
            <p style="color:#888;padding:0;margin:0;" id="wc-mss-issues-empty"><?php _e('No warnings or errors found — everything looks good!', 'wc-multi-store-sync'); ?></p>
        </div>

        <div style="margin-top:12px;">
            <button type="button" class="button button-link-delete" id="wc-mss-clear-warnings-errors">
                <?php _e('Clear Warnings &amp; Errors', 'wc-multi-store-sync'); ?>
            </button>
            <span id="wc-mss-clear-warnings-result" style="margin-left:10px;font-size:13px;"></span>
        </div>
    </div>

    <div class="wc-mss-card">
        <h2><?php _e('Force Full Sync by SKU', 'wc-multi-store-sync'); ?></h2>
        <p class="description"><?php _e('Enter one or more product SKUs (comma-separated) to immediately queue a full sync (with images, categories, attributes, etc.) to all active stores.', 'wc-multi-store-sync'); ?></p>

        <div style="display: flex; gap: 10px; align-items: flex-start; margin-top: 15px; flex-wrap: wrap;">
            <input type="text" id="wc-mss-test-sku"
                   placeholder="<?php esc_attr_e('SKU1, SKU2, SKU3...', 'wc-multi-store-sync'); ?>"
                   style="width: 400px;"
                   class="regular-text">
            <button type="button" class="button button-primary" id="wc-mss-force-sync-btn">
                <?php _e('Force Full Sync', 'wc-multi-store-sync'); ?>
            </button>
        </div>

        <div id="wc-mss-force-sync-result" style="margin-top: 12px; display: none;"></div>
    </div>

    <div class="wc-mss-card">
        <h2><?php _e('Force Full Sync by Category', 'wc-multi-store-sync'); ?></h2>
        <p class="description"><?php _e('Select a product category to immediately queue a full sync (with images, categories, attributes, etc.) for all published products in it to all active stores.', 'wc-multi-store-sync'); ?></p>

        <div style="display: flex; gap: 10px; align-items: flex-start; margin-top: 15px; flex-wrap: wrap;">
            <select id="wc-mss-category-select" style="min-width: 260px;">
                <option value=""><?php _e('— Select a category —', 'wc-multi-store-sync'); ?></option>
                <?php
                $product_cats = get_terms([
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => true,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ]);
                if (!is_wp_error($product_cats)) {
                    foreach ($product_cats as $cat) {
                        printf(
                            '<option value="%d">%s (%d)</option>',
                            esc_attr($cat->term_id),
                            esc_html($cat->name),
                            (int) $cat->count
                        );
                    }
                }
                ?>
            </select>
            <button type="button" class="button button-primary" id="wc-mss-force-sync-category-btn">
                <?php _e('Force Full Sync', 'wc-multi-store-sync'); ?>
            </button>
        </div>

        <div id="wc-mss-force-sync-category-result" style="margin-top: 12px; display: none;"></div>
    </div>

    <div class="wc-mss-card">
        <h3><?php _e('About Logs', 'wc-multi-store-sync'); ?></h3>
        <p><?php _e('This page shows the most recent 500 log entries, read from the WooCommerce log for this plugin. Retention is controlled by the "Log retention period" setting under WooCommerce → Status → Logs.', 'wc-multi-store-sync'); ?></p>
        <p><?php _e('Log entries include:', 'wc-multi-store-sync'); ?></p>
        <ul>
            <li><?php _e('Product sync operations (create, update, delete)', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('Connection attempts and errors', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('API communication issues', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('System warnings and errors', 'wc-multi-store-sync'); ?></li>
        </ul>
    </div>
</div>
