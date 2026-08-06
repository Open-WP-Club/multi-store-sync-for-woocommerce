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
    <h1><?php _e('Deletion Audit Log', 'wc-multi-store-sync'); ?></h1>

    <p class="description">
        <?php _e('View detailed logs of all product deletions synced to remote stores.', 'wc-multi-store-sync'); ?>
    </p>

    <!-- Filters -->
    <div class="tablenav top">
        <form method="get" action="">
            <input type="hidden" name="page" value="wc-multi-store-sync-deletion-audit">

            <select name="status" id="status-filter">
                <option value=""><?php _e('All Statuses', 'wc-multi-store-sync'); ?></option>
                <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'wc-multi-store-sync'); ?></option>
                <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php _e('Completed', 'wc-multi-store-sync'); ?></option>
                <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('Failed', 'wc-multi-store-sync'); ?></option>
            </select>

            <select name="type" id="type-filter">
                <option value=""><?php _e('All Types', 'wc-multi-store-sync'); ?></option>
                <option value="manual" <?php selected($type_filter, 'manual'); ?>><?php _e('Manual', 'wc-multi-store-sync'); ?></option>
                <option value="bulk" <?php selected($type_filter, 'bulk'); ?>><?php _e('Bulk', 'wc-multi-store-sync'); ?></option>
                <option value="category_deletion" <?php selected($type_filter, 'category_deletion'); ?>><?php _e('Category Deletion', 'wc-multi-store-sync'); ?></option>
                <option value="tag_deletion" <?php selected($type_filter, 'tag_deletion'); ?>><?php _e('Tag Deletion', 'wc-multi-store-sync'); ?></option>
            </select>

            <button type="submit" class="button"><?php _e('Filter', 'wc-multi-store-sync'); ?></button>
        </form>
    </div>

    <!-- Audit Log Table -->
    <?php if (empty($logs)): ?>
        <div class="notice notice-info">
            <p><?php _e('No deletion audit logs found.', 'wc-multi-store-sync'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('ID', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Product', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('SKU', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('User', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Type', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Stores', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Status', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Deleted At', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Actions', 'wc-multi-store-sync'); ?></th>
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
                            echo count($stores) . ' ' . _n('store', 'stores', count($stores), 'wc-multi-store-sync');
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
                                <?php _e('View Details', 'wc-multi-store-sync'); ?>
                            </button>
                        </td>
                    </tr>

                    <!-- Hidden row for details -->
                    <tr id="details-<?php echo esc_attr($log['id']); ?>" class="audit-details" style="display: none;">
                        <td colspan="9">
                            <div class="audit-details-content">
                                <h3><?php _e('Deletion Details', 'wc-multi-store-sync'); ?></h3>

                                <div class="details-section">
                                    <h4><?php _e('Affected Stores', 'wc-multi-store-sync'); ?></h4>
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
                                        <h4><?php _e('Error Message', 'wc-multi-store-sync'); ?></h4>
                                        <pre><?php echo esc_html($log['error_message']); ?></pre>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($log['product_data_before'])): ?>
                                    <div class="details-section">
                                        <h4><?php _e('Product Data (Before Deletion)', 'wc-multi-store-sync'); ?></h4>
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
                        'prev_text' => __('&laquo;', 'wc-multi-store-sync'),
                        'next_text' => __('&raquo;', 'wc-multi-store-sync'),
                        'total' => $total_pages,
                        'current' => $page_num,
                    ]);

                    if ($page_links) {
                        echo '<span class="pagination-links">' . $page_links . '</span>';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
