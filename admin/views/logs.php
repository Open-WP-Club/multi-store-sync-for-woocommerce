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
    <h1><?php esc_html_e('Sync Logs', 'multi-store-sync-for-woocommerce'); ?></h1>

    <div class="card wc-mss-card">
        <h2><?php esc_html_e('Recent Activity', 'multi-store-sync-for-woocommerce'); ?></h2>

        <div class="wc-mss-log-toolbar">
            <label>
                <input type="checkbox" id="wc-mss-reverse-logs">
                <?php esc_html_e('Newest first', 'multi-store-sync-for-woocommerce'); ?>
            </label>
            <label>
                <input type="checkbox" id="wc-mss-auto-scroll" checked>
                <?php esc_html_e('Auto-scroll to latest', 'multi-store-sync-for-woocommerce'); ?>
            </label>
            <div class="wc-mss-log-search">
                <input type="text" id="wc-mss-log-filter" placeholder="<?php esc_attr_e('Filter logs...', 'multi-store-sync-for-woocommerce'); ?>">
            </div>
        </div>

        <div class="wc-mss-log-viewer" id="wc-mss-log-viewer">
            <?php if (!empty($logs)): ?>
                <pre class="wc-mss-log-content" id="wc-mss-log-content"><?php echo esc_html($logs); ?></pre>
            <?php else: ?>
                <p class="wc-mss-log-content"><?php esc_html_e('No log entries found.', 'multi-store-sync-for-woocommerce'); ?></p>
            <?php endif; ?>
        </div>

        <div class="wc-mss-result wc-mss-log-actions">
            <button type="button" class="button" onclick="location.reload();">
                <?php esc_html_e('Refresh', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <button type="button" class="button" id="wc-mss-scroll-bottom">
                <?php esc_html_e('Scroll to Bottom', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <button type="button" class="button" id="wc-mss-scroll-top">
                <?php esc_html_e('Scroll to Top', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <button type="button" class="wc-mss-inline-result button button-link-delete" id="wc-mss-clear-log">
                <?php esc_html_e('Clear Logs', 'multi-store-sync-for-woocommerce'); ?>
            </button>
        </div>
    </div>

    <div class="card wc-mss-card" id="wc-mss-issues-card">
        <h2><?php esc_html_e('Warnings &amp; Errors', 'multi-store-sync-for-woocommerce'); ?> <span id="wc-mss-issues-count" class="description"></span></h2>
        <p class="description"><?php esc_html_e('Only [WARNING] and [ERROR] entries from the current log. This is the first place to look when something seems off.', 'multi-store-sync-for-woocommerce'); ?></p>

        <div id="wc-mss-issues-viewer" class="wc-mss-log-viewer wc-mss-log-content">
            <p id="wc-mss-issues-empty" class="description"><?php esc_html_e('No warnings or errors found — everything looks good!', 'multi-store-sync-for-woocommerce'); ?></p>
        </div>

        <div class="wc-mss-result">
            <button type="button" class="button button-link-delete" id="wc-mss-clear-warnings-errors">
                <?php esc_html_e('Clear Warnings &amp; Errors', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <div id="wc-mss-clear-warnings-result" class="wc-mss-inline-result"></div>
        </div>
    </div>

    <div class="card wc-mss-card">
        <h2><?php esc_html_e('Force Full Sync by SKU', 'multi-store-sync-for-woocommerce'); ?></h2>
        <p class="description"><?php esc_html_e('Enter one or more product SKUs (comma-separated) to immediately queue a full sync (with images, categories, attributes, etc.) to all active stores.', 'multi-store-sync-for-woocommerce'); ?></p>

        <div class="wc-mss-toolbar">
            <input type="text" id="wc-mss-test-sku"
                   placeholder="<?php esc_attr_e('SKU1, SKU2, SKU3...', 'multi-store-sync-for-woocommerce'); ?>"
                   class="regular-text">
            <button type="button" class="button button-primary" id="wc-mss-force-sync-btn">
                <?php esc_html_e('Force Full Sync', 'multi-store-sync-for-woocommerce'); ?>
            </button>
        </div>

        <div id="wc-mss-force-sync-result" style="display: none;"></div>
    </div>

    <div class="card wc-mss-card">
        <h2><?php esc_html_e('Force Full Sync by Category', 'multi-store-sync-for-woocommerce'); ?></h2>
        <p class="description"><?php esc_html_e('Select a product category to immediately queue a full sync (with images, categories, attributes, etc.) for all published products in it to all active stores.', 'multi-store-sync-for-woocommerce'); ?></p>

        <div class="wc-mss-toolbar">
            <select id="wc-mss-category-select">
                <option value=""><?php esc_html_e('— Select a category —', 'multi-store-sync-for-woocommerce'); ?></option>
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
                <?php esc_html_e('Force Full Sync', 'multi-store-sync-for-woocommerce'); ?>
            </button>
        </div>

        <div id="wc-mss-force-sync-category-result" style="display: none;"></div>
    </div>

    <div class="card wc-mss-card">
        <h3><?php esc_html_e('About Logs', 'multi-store-sync-for-woocommerce'); ?></h3>
        <p><?php esc_html_e('This page shows the most recent 500 log entries, read from the WooCommerce log for this plugin. Retention is controlled by the "Log retention period" setting under WooCommerce → Status → Logs.', 'multi-store-sync-for-woocommerce'); ?></p>
        <p><?php esc_html_e('Log entries include:', 'multi-store-sync-for-woocommerce'); ?></p>
        <ul>
            <li><?php esc_html_e('Product sync operations (create, update, delete)', 'multi-store-sync-for-woocommerce'); ?></li>
            <li><?php esc_html_e('Connection attempts and errors', 'multi-store-sync-for-woocommerce'); ?></li>
            <li><?php esc_html_e('API communication issues', 'multi-store-sync-for-woocommerce'); ?></li>
            <li><?php esc_html_e('System warnings and errors', 'multi-store-sync-for-woocommerce'); ?></li>
        </ul>
    </div>
</div>
