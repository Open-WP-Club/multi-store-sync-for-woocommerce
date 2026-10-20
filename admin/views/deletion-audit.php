<?php
/**
 * Deletion Audit View
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Read-only GET filters; they only select audit-log rows and do not change state.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only audit-log filter.
$status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only audit-log filter.
$type_filter = isset($_GET['type']) ? sanitize_text_field(wp_unslash($_GET['type'])) : '';
$page_num = WC_Multi_Store_Admin_List_Table::page_number();
$per_page = 50;

// Build query args
$args = [
    'limit' => $per_page,
    'offset' => ($page_num - 1) * $per_page,
    'orderby' => 'deleted_at',
    'order' => 'DESC',
];

if ($status_filter) {
    $args['status'] = $status_filter;
}

if ($type_filter) {
    $args['deletion_type'] = $type_filter;
}

// Get audit logs
$logs = WC_Multi_Store_Deletion_Audit::get_logs($args);
$total_logs = WC_Multi_Store_Deletion_Audit::get_total_count($args);

?>
<div class="wrap wc-mss-deletion-audit">
    <h1><?php esc_html_e('Deletion Audit Log', 'multi-store-sync-for-woocommerce'); ?></h1>

    <p class="description">
        <?php esc_html_e('View detailed logs of all product deletions synced to remote stores.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <!-- Filters -->
    <div class="tablenav top">
        <form method="get" action="">
            <input type="hidden" name="page" value="wc-settings">
            <input type="hidden" name="tab" value="multi_store_sync">
            <input type="hidden" name="section" value="deletion-audit">

            <select name="status" id="status-filter">
                <option value=""><?php esc_html_e('All Statuses', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php esc_html_e('Completed', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php esc_html_e('Failed', 'multi-store-sync-for-woocommerce'); ?></option>
            </select>

            <select name="type" id="type-filter">
                <option value=""><?php esc_html_e('All Types', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="manual" <?php selected($type_filter, 'manual'); ?>><?php esc_html_e('Manual', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="bulk" <?php selected($type_filter, 'bulk'); ?>><?php esc_html_e('Bulk', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="category_deletion" <?php selected($type_filter, 'category_deletion'); ?>><?php esc_html_e('Category Deletion', 'multi-store-sync-for-woocommerce'); ?></option>
                <option value="tag_deletion" <?php selected($type_filter, 'tag_deletion'); ?>><?php esc_html_e('Tag Deletion', 'multi-store-sync-for-woocommerce'); ?></option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filter', 'multi-store-sync-for-woocommerce'); ?></button>
        </form>
    </div>

    <?php
    $list_table = new WC_Multi_Store_Admin_List_Table('deletion-audit', [
        'deletion-audit-id' => __('ID', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-product' => __('Product', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-user' => __('User', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-type' => __('Type', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-stores' => __('Stores', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-status' => __('Status', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-deleted-at' => __('Deleted At', 'multi-store-sync-for-woocommerce'),
        'deletion-audit-actions' => __('Actions', 'multi-store-sync-for-woocommerce')
    ], $logs, static function (array $log): void {
        ?>
                        <td><?php echo esc_html($log['id']); ?></td>
                        <td>
                            <strong><?php echo esc_html($log['product_name']); ?></strong>
                            <br>
                            <small>ID: <?php echo esc_html($log['product_id']); ?></small>
                        </td>
                        <td><?php echo esc_html($log['product_sku']); ?></td>
                        <td>
                            <?php echo esc_html($log['user_name']); ?>
                            <br>
                            <small>ID: <?php echo esc_html($log['user_id']); ?></small>
                        </td>
                        <td>
                            <span >
                                <?php echo esc_html(match ($log['deletion_type']) {
                                    'manual' => __('Manual', 'multi-store-sync-for-woocommerce'),
                                    'bulk' => __('Bulk', 'multi-store-sync-for-woocommerce'),
                                    'category_deletion' => __('Category Deletion', 'multi-store-sync-for-woocommerce'),
                                    'tag_deletion' => __('Tag Deletion', 'multi-store-sync-for-woocommerce'),
                                    default => ucfirst(str_replace('_', ' ', $log['deletion_type'])),
                                }); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $stores = is_array($log['stores_affected']) ? $log['stores_affected'] : [];
                            echo esc_html(count($stores) . ' ' . _n('store', 'stores', count($stores), 'multi-store-sync-for-woocommerce'));
                            ?>
                        </td>
                        <td>
                            <span>
                                <?php echo esc_html(match ($log['status']) {
                                    'pending' => __('Pending', 'multi-store-sync-for-woocommerce'),
                                    'completed' => __('Completed', 'multi-store-sync-for-woocommerce'),
                                    'failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
                                    default => ucfirst($log['status']),
                                }); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo esc_html(mysql2date('Y-m-d H:i:s', $log['deleted_at'])); ?>
                        </td>
                        <td><details><summary><?php esc_html_e('View Details', 'multi-store-sync-for-woocommerce'); ?></summary>
                                <h3><?php esc_html_e('Deletion Details', 'multi-store-sync-for-woocommerce'); ?></h3>

                                <div>
                                    <h4><?php esc_html_e('Affected Stores', 'multi-store-sync-for-woocommerce'); ?></h4>
                                    <ul>
                                        <?php foreach ($stores as $store_url => $store_config): ?>
                                            <li>
                                                <strong><?php echo isset($store_config['name']) ? esc_html($store_config['name']) : esc_html($store_url); ?></strong>
                                                <br>
                                                <small><?php echo esc_html($store_url); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>

                                <?php if (!empty($log['error_message'])): ?>
                                    <div>
                                        <h4><?php esc_html_e('Error Message', 'multi-store-sync-for-woocommerce'); ?></h4>
                                        <pre><?php echo esc_html($log['error_message']); ?></pre>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['product_data_before'])): ?>
                                    <div>
                                        <h4><?php esc_html_e('Product Data (Before Deletion)', 'multi-store-sync-for-woocommerce'); ?></h4>
                                        <pre><?php echo esc_html(json_encode($log['product_data_before'], JSON_PRETTY_PRINT)); ?></pre>
                                    </div>
                                <?php endif; ?></details></td>

        <?php
    }, null, (int) $total_logs);
    $list_table->display();
    ?>
</div>
