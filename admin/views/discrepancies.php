<?php
/**
 * Stock Discrepancies Admin Page
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle bulk cleanup
if (isset($_POST['cleanup_old']) && check_admin_referer('wc_mss_cleanup_discrepancies')) {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'multi-store-sync-for-woocommerce'));
    }

    $days = isset($_POST['cleanup_days']) && is_string($_POST['cleanup_days']) ? absint(wp_unslash($_POST['cleanup_days'])) : 30;
    $days = max(1, min(365, $days));
    $deleted = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies($days);
    /* translators: %d: number of deleted discrepancies. */
    echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Cleaned up %d old discrepancies.', 'multi-store-sync-for-woocommerce'), absint($deleted)) . '</p></div>';
}

// Get filter parameters
$status_filter = isset($_GET['status']) && is_string($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'pending';
if (!in_array($status_filter, ['pending', 'resolving', 'resolved', 'ignored', 'all'], true)) {
    $status_filter = 'pending';
}
$store_filter = isset($_GET['store']) && is_string($_GET['store']) ? sanitize_text_field(wp_unslash($_GET['store'])) : '';

$total = WC_Multi_Store_Stock_Verifier::get_discrepancy_count($status_filter, $store_filter);

// Get discrepancies
$args = [
    'status' => $status_filter === 'all' ? '' : $status_filter,
    'limit' => 50,
    'offset' => (WC_Multi_Store_Admin_List_Table::page_number($total) - 1) * 50,
    'orderby' => 'detected_at',
    'order' => 'DESC',
];

if (!empty($store_filter)) {
    $args['store_url'] = $store_filter;
}

$discrepancies = WC_Multi_Store_Stock_Verifier::get_discrepancies($args);
$pending_count = WC_Multi_Store_Stock_Verifier::get_discrepancy_count('pending');

// Get all stores for filter
$stores = get_option('wc_multi_store_sync_stores', []);
?>

<div class="wrap wc-mss-discrepancies-page">
    <h1>
        <?php esc_html_e('Stock Discrepancies', 'multi-store-sync-for-woocommerce'); ?>
        <?php if ($pending_count > 0): ?>
            <span class="description">(<?php echo esc_html($pending_count); ?>)</span>
        <?php endif; ?>
    </h1>

    <p class="description">
        <?php esc_html_e('This page shows stock level discrepancies detected between your main store and remote stores. Discrepancies occur when the actual stock on a remote store does not match the expected stock after synchronization.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <div class="wc-mss-toolbar">
        <form method="get">
            <input type="hidden" name="page" value="wc-settings" />
            <input type="hidden" name="tab" value="multi_store_sync" />
            <input type="hidden" name="section" value="discrepancies" />

            <label class="screen-reader-text" for="status-filter"><?php esc_html_e('Status', 'multi-store-sync-for-woocommerce'); ?></label>
            <select name="status" id="status-filter">
                <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="resolving" <?php selected($status_filter, 'resolving'); ?>><?php esc_html_e('Resolving', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="resolved" <?php selected($status_filter, 'resolved'); ?>><?php esc_html_e('Resolved', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="ignored" <?php selected($status_filter, 'ignored'); ?>><?php esc_html_e('Ignored', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="all" <?php selected($status_filter, 'all'); ?>><?php esc_html_e('All', 'multi-store-sync-for-woocommerce'); ?></option>
            </select>

            <label class="screen-reader-text" for="store-filter"><?php esc_html_e('Store', 'multi-store-sync-for-woocommerce'); ?></label>
            <select name="store" id="store-filter">
                <option value=""><?php esc_html_e('All Stores', 'multi-store-sync-for-woocommerce'); ?></option>
                <?php foreach ($stores as $store_url => $store_config): ?>
                    <?php if (($store_config['status'] ?? '') === 'active'): ?>
                        <option value="<?php echo esc_attr($store_url); ?>" <?php selected($store_filter, $store_url); ?>>
                            <?php echo esc_html($store_config['name'] ?? $store_url); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filter', 'multi-store-sync-for-woocommerce'); ?></button>
        </form>

        <form method="post">
            <?php wp_nonce_field('wc_mss_cleanup_discrepancies'); ?>
            <input type="number" class="small-text" aria-label="<?php esc_attr_e('Days to retain', 'multi-store-sync-for-woocommerce'); ?>" name="cleanup_days" value="30" min="1" max="365" />
            <button type="submit" name="cleanup_old" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete old resolved/ignored discrepancies?', 'multi-store-sync-for-woocommerce'); ?>');">
                <?php esc_html_e('Cleanup Old Records', 'multi-store-sync-for-woocommerce'); ?>
            </button>
        </form>
    </div>

    <?php if (empty($discrepancies)): ?>
        <div class="notice notice-info">
            <p>
                <?php if ($status_filter === 'pending'): ?>
                    <strong><?php esc_html_e('No pending discrepancies found.', 'multi-store-sync-for-woocommerce'); ?></strong><br>
                    <?php esc_html_e('All stock levels are synchronized correctly!', 'multi-store-sync-for-woocommerce'); ?>
                <?php else: ?>
                    <?php esc_html_e('No discrepancies found matching your filters.', 'multi-store-sync-for-woocommerce'); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
            <form method="post">
            <?php
            $list_table = new WC_Multi_Store_Admin_List_Table('discrepancies', [
                'product' => __('Product', 'multi-store-sync-for-woocommerce'),
                'sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
                'store' => __('Store', 'multi-store-sync-for-woocommerce'),
                'expected' => __('Expected Stock', 'multi-store-sync-for-woocommerce'),
                'actual' => __('Actual Stock', 'multi-store-sync-for-woocommerce'),
                'difference' => __('Difference', 'multi-store-sync-for-woocommerce'),
                'detected' => __('Detected', 'multi-store-sync-for-woocommerce'),
                'status' => __('Status', 'multi-store-sync-for-woocommerce'),
                'actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
            ], $discrepancies, static function (array $discrepancy) use ($stores): void {
                $product = wc_get_product($discrepancy['product_id']);
                $product_name = $product ? $product->get_name() : __('(Product not found)', 'multi-store-sync-for-woocommerce');
                $edit_link = $product ? get_edit_post_link($discrepancy['product_id']) : '';

                // Find store name — stores are keyed by URL
                $store_name = parse_url($discrepancy['store_url'], PHP_URL_HOST);
                $norm_disc_url = untrailingslashit($discrepancy['store_url']);
                foreach ($stores as $s_url => $s_config) {
                    if (untrailingslashit($s_url) === $norm_disc_url) {
                        $store_name = $s_config['name'] ?? $s_url;
                        break;
                    }
                }

                $difference = $discrepancy['difference'];
                $diff_text = sprintf('%+d', $difference);

                $status_labels = [
                    'pending' => __('Pending', 'multi-store-sync-for-woocommerce'),
                    'resolving' => __('Resolving', 'multi-store-sync-for-woocommerce'),
                    'resolved' => __('Resolved', 'multi-store-sync-for-woocommerce'),
                    'ignored' => __('Ignored', 'multi-store-sync-for-woocommerce'),
                ];
                ?>
                    <td>
                        <?php if ($edit_link): ?>
                            <a href="<?php echo esc_url($edit_link); ?>" target="_blank">
                                <strong><?php echo esc_html($product_name); ?></strong>
                            </a>
                        <?php else: ?>
                            <strong><?php echo esc_html($product_name); ?></strong>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($discrepancy['sku']); ?></code></td>
                    <td><?php echo esc_html($store_name); ?></td>
                    <td><?php echo esc_html($discrepancy['expected_stock']); ?></td>
                    <td><?php echo esc_html($discrepancy['actual_stock']); ?></td>
                    <td>
                        <strong><?php echo esc_html($diff_text); ?></strong>
                    </td>
                    <td>
                        <?php echo esc_html(human_time_diff(strtotime($discrepancy['detected_at']), current_time('timestamp'))); ?> <?php esc_html_e('ago', 'multi-store-sync-for-woocommerce'); ?>
                    </td>
                    <td>
                        <span>
                            <?php echo esc_html($status_labels[$discrepancy['status']] ?? $discrepancy['status']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (in_array($discrepancy['status'], ['pending', 'resolving'], true)): ?>

                                <button type="submit" name="wc_mss_row_action" value="auto_correct:<?php echo esc_attr($discrepancy['id']); ?>" class="button button-primary button-small">
                                    <?php esc_html_e('Auto-Correct', 'multi-store-sync-for-woocommerce'); ?>
                                </button>
                                <button type="submit" name="wc_mss_row_action" value="mark_resolved:<?php echo esc_attr($discrepancy['id']); ?>" class="button button-small">
                                    <?php esc_html_e('Mark Resolved', 'multi-store-sync-for-woocommerce'); ?>
                                </button>
                                <button type="submit" name="wc_mss_row_action" value="mark_ignored:<?php echo esc_attr($discrepancy['id']); ?>" class="button button-small">
                                    <?php esc_html_e('Ignore', 'multi-store-sync-for-woocommerce'); ?>
                                </button>

                        <?php endif; ?>
                    </td>
            <?php
            }, static fn(array $item): bool => in_array($item['status'], ['pending', 'resolving'], true), $total);
            $list_table->display();
            ?>
            </form>
    <?php endif; ?>

    <hr / class="wc-mss-section-divider">

    <h2><?php esc_html_e('Category Scan', 'multi-store-sync-for-woocommerce'); ?></h2>
    <p class="description">
        <?php esc_html_e('Compares product categories between your main store and a remote store. Shows products where categories are missing or differ.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <div class="wc-mss-toolbar">
        <select id="wc-mss-cat-store-select">
            <option value=""><?php esc_html_e('All Stores', 'multi-store-sync-for-woocommerce'); ?></option>
            <?php foreach ($stores as $url => $config): ?>
                <option value="<?php echo esc_attr($url); ?>">
                    <?php echo esc_html($config['name'] ?? $url); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-primary" id="wc-mss-scan-categories-btn">
            <?php esc_html_e('Scan Categories', 'multi-store-sync-for-woocommerce'); ?>
        </button>
    </div>

    <div id="wc-mss-cat-progress" style="display:none;">
        <progress id="wc-mss-cat-bar" max="100" value="0" aria-labelledby="wc-mss-cat-progress-label"></progress>
        <p id="wc-mss-cat-progress-label" class="description"></p>
    </div>

    <div id="wc-mss-cat-results"></div>
</div>
