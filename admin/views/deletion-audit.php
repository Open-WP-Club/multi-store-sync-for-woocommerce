<?php
/**
 * Deletion Audit View
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get filters from request
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$page_num = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
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
$total_pages = ceil($total_logs / $per_page);

?>
<div class="wrap wc-mss-deletion-audit">
    <h1><?php esc_html_e('Deletion Audit Log', 'multi-store-sync-for-woocommerce'); ?></h1>

    <p class="description">
        <?php esc_html_e('View detailed logs of all product deletions synced to remote stores.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <!-- Filters -->
    <div class="tablenav top">
        <form method="get" action="">
            <input type="hidden" name="page" value="wc-multi-store-sync-deletion-audit">

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

    <!-- Audit Log Table -->
    <?php if (empty($logs)): ?>
        <div class="notice notice-info">
            <p><?php esc_html_e('No deletion audit logs found.', 'multi-store-sync-for-woocommerce'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Product', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('SKU', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('User', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Type', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Stores', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Status', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Deleted At', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Actions', 'multi-store-sync-for-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
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
                            <span class="deletion-type">
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $log['deletion_type']))); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $stores = is_array($log['stores_affected']) ? $log['stores_affected'] : [];
                            echo esc_html(count($stores) . ' ' . _n('store', 'stores', count($stores), 'multi-store-sync-for-woocommerce'));
                            ?>
                        </td>
                        <td>
                            <?php
                            $status_class = match ($log['status']) {
                                'completed' => 'status-completed',
                                'failed' => 'status-failed',
                                'pending' => 'status-pending',
                                default => '',
                            };
                            ?>
                            <span class="status-badge <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html(ucfirst($log['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo esc_html(mysql2date('Y-m-d H:i:s', $log['deleted_at'])); ?>
                        </td>
                        <td>
                            <button type="button" class="button button-small view-details" data-log-id="<?php echo esc_attr($log['id']); ?>">
                                <?php esc_html_e('View Details', 'multi-store-sync-for-woocommerce'); ?>
                            </button>
                        </td>
                    </tr>

                    <!-- Hidden row for details -->
                    <tr id="details-<?php echo esc_attr($log['id']); ?>" class="audit-details" style="display: none;">
                        <td colspan="9">
                            <div class="audit-details-content">
                                <h3><?php esc_html_e('Deletion Details', 'multi-store-sync-for-woocommerce'); ?></h3>

                                <div class="details-section">
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
                                    <div class="details-section error-message">
                                        <h4><?php esc_html_e('Error Message', 'multi-store-sync-for-woocommerce'); ?></h4>
                                        <pre><?php echo esc_html($log['error_message']); ?></pre>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['product_data_before'])): ?>
                                    <div class="details-section">
                                        <h4><?php esc_html_e('Product Data (Before Deletion)', 'multi-store-sync-for-woocommerce'); ?></h4>
                                        <pre><?php echo esc_html(json_encode($log['product_data_before'], JSON_PRETTY_PRINT)); ?></pre>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $page_links = paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;', 'multi-store-sync-for-woocommerce'),
                        'next_text' => __('&raquo;', 'multi-store-sync-for-woocommerce'),
                        'total' => $total_pages,
                        'current' => $page_num,
                    ]);

                    if ($page_links) {
                        echo wp_kses_post('<span class="pagination-links">' . $page_links . '</span>');
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
