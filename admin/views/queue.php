<?php
/**
 * Queue View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-mss-queue">
    <h1><?php esc_html_e('Sync Queue', 'multi-store-sync-for-woocommerce'); ?></h1>

    <div class="wc-mss-dashboard-grid">
        <div class="card wc-mss-card wc-mss-full-width">
            <!-- Queue Stats -->
            <table class="widefat striped wc-mss-summary-table">
                <caption class="screen-reader-text"><?php esc_html_e('Queue statistics', 'multi-store-sync-for-woocommerce'); ?></caption>
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Pending', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th scope="col"><?php esc_html_e('Processing', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th scope="col"><?php esc_html_e('Completed', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th scope="col"><?php esc_html_e('Failed', 'multi-store-sync-for-woocommerce'); ?></th>
                    <th scope="col"><?php esc_html_e('Total', 'multi-store-sync-for-woocommerce'); ?></th>
                </tr></thead>
                <tbody><tr>
                    <td><?php echo esc_html($queue_stats['pending']); ?></td>
                    <td><?php echo esc_html($queue_stats['processing']); ?></td>
                    <td><?php echo esc_html($queue_stats['completed']); ?></td>
                    <td><?php echo esc_html($queue_stats['failed']); ?></td>
                    <td><?php echo esc_html($queue_stats['total']); ?></td>
                </tr></tbody>
            </table>

            <!-- Queue Filter -->
            <?php
            $filter_base_url = esc_url_raw(add_query_arg([
                'page' => 'wc-settings',
                'tab' => 'multi_store_sync',
                'section' => 'queue',
            ], admin_url('admin.php')));
            ?>
            <div class="wc-mss-field-group" id="wc-mss-queue-filter" data-base-url="<?php echo esc_attr($filter_base_url); ?>">
                <label for="queue_status"><?php esc_html_e('Filter by status:', 'multi-store-sync-for-woocommerce'); ?></label>
                <select id="queue_status">
                    <option value="all" <?php selected($queue_status_filter, 'all'); ?>><?php esc_html_e('All', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="pending" <?php selected($queue_status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="processing" <?php selected($queue_status_filter, 'processing'); ?>><?php esc_html_e('Processing', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="completed" <?php selected($queue_status_filter, 'completed'); ?>><?php esc_html_e('Completed', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="failed" <?php selected($queue_status_filter, 'failed'); ?>><?php esc_html_e('Failed', 'multi-store-sync-for-woocommerce'); ?></option>
                </select>
                <button type="button" id="wc-mss-refresh-queue" class="wc-mss-inline-result button">
                    <?php esc_html_e('Refresh', 'multi-store-sync-for-woocommerce'); ?>
                </button>
            </div>

            <!-- Queue Table -->
            <?php if (!empty($queue_items)): ?>
            <form method="post">
            <?php
            $list_table = new WC_Multi_Store_Admin_List_Table('queue', [
                'id' => __('ID', 'multi-store-sync-for-woocommerce'),
                'sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
                'product' => __('Product', 'multi-store-sync-for-woocommerce'),
                'store' => __('Store', 'multi-store-sync-for-woocommerce'),
                'sync_type' => __('Sync Type', 'multi-store-sync-for-woocommerce'),
                'status' => __('Status', 'multi-store-sync-for-woocommerce'),
                'priority' => __('Priority', 'multi-store-sync-for-woocommerce'),
                'attempts' => __('Attempts', 'multi-store-sync-for-woocommerce'),
                'created' => __('Created', 'multi-store-sync-for-woocommerce'),
                'actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
            ], $queue_items, static function (array $item): void {
                ?>
                        <td><?php echo esc_html($item['id']); ?></td>
                        <td><code><?php echo esc_html($item['product_sku'] ?: '-'); ?></code></td>
                        <td>
                            <?php if ($item['product_name'] !== 'Deleted'): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($item['product_id'])); ?>" target="_blank">
                                    <?php echo esc_html($item['product_name']); ?>
                                </a>
                            <?php else: ?>
                                <span class="description"><?php esc_html_e('Deleted', 'multi-store-sync-for-woocommerce'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo esc_html($item['store_url']); ?></small></td>
                        <td><small><?php echo esc_html($item['sync_type']); ?></small></td>
                        <td>
                            <?php
                            $status_labels = [
                                'pending' => __('Pending', 'multi-store-sync-for-woocommerce'),
                                'processing' => __('Processing', 'multi-store-sync-for-woocommerce'),
                                'completed' => __('Completed', 'multi-store-sync-for-woocommerce'),
                                'failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
                            ];
                            ?>
                            <span><?php echo esc_html($status_labels[$item['status']] ?? $item['status']); ?></span>
                            <?php if ($item['status'] === 'failed' && !empty($item['last_error'])): ?>
                                <br><small class="wc-mss-error-details">
                                    <?php echo esc_html($item['last_error']); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $priority_label = $item['priority'];
                            if ($item['priority'] <= 2) {
                                echo '<strong title="' . esc_attr__('Critical', 'multi-store-sync-for-woocommerce') . '">' . esc_html($priority_label) . '</strong>';
                            } elseif ($item['priority'] <= 4) {
                                echo '<strong title="' . esc_attr__('High', 'multi-store-sync-for-woocommerce') . '">' . esc_html($priority_label) . '</strong>';
                            } else {
                                echo '<span title="' . esc_attr__('Normal', 'multi-store-sync-for-woocommerce') . '">' . esc_html($priority_label) . '</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($item['attempts']); ?>/3</td>
                        <td><small><?php echo esc_html($item['created_at']); ?></small></td>
                        <td>
                            <?php if ($item['status'] === 'failed'): ?>
                                <button type="submit" name="wc_mss_row_action" value="retry:<?php echo esc_attr($item['id']); ?>"
                                    class="button button-small"
                                    title="<?php esc_attr_e('Reset to pending for retry', 'multi-store-sync-for-woocommerce'); ?>">
                                    <?php esc_html_e('Retry', 'multi-store-sync-for-woocommerce'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                <?php
            }, static fn(array $item): bool => $item['status'] === 'failed', (int) ($queue_stats[$queue_status_filter] ?? $queue_stats['total']));
            $list_table->display();
            ?>
            </form>

            <p class="wc-mss-result description">
                <?php esc_html_e('Showing 50 queue items per page. Priority: 1-2 = Critical, 3-4 = High, 5+ = Normal.', 'multi-store-sync-for-woocommerce'); ?>
            </p>
            <?php else: ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e('No queue items found.', 'multi-store-sync-for-woocommerce'); ?></p>
            </div>
            <?php endif; ?>

            <!-- Queue Actions -->
            <div class="wc-mss-section-actions">
                <div class="wc-mss-toolbar">
                    <?php if ($queue_stats['failed'] > 0): ?>
                    <form method="post">
                        <?php wp_nonce_field('wc_mss_retry_failed_queue'); ?>
                        <button type="submit" name="wc_mss_retry_failed_queue" class="button button-primary" onclick="return confirm('<?php esc_attr_e('Retry all failed items? They will be reset to pending for re-processing.', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Retry Failed', 'multi-store-sync-for-woocommerce'); ?> (<?php echo esc_html($queue_stats['failed']); ?>)
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="post">
                        <?php wp_nonce_field('wc_mss_clear_pending_queue'); ?>
                        <button type="submit" name="wc_mss_clear_pending_queue" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e('WARNING: This will clear ALL pending items from the queue!\n\nProducts waiting to sync will NOT be synced.\n\nAre you sure?', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Clear Pending', 'multi-store-sync-for-woocommerce'); ?> (<?php echo esc_html($queue_stats['pending']); ?>)
                        </button>
                    </form>
                    <form method="post">
                        <?php wp_nonce_field('wc_mss_clear_completed_queue'); ?>
                        <button type="submit" name="wc_mss_clear_completed_queue" class="button" onclick="return confirm('<?php esc_attr_e('Clear all completed queue items?', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Clear Completed', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                    </form>
                    <form method="post">
                        <?php wp_nonce_field('wc_mss_clear_failed_queue'); ?>
                        <button type="submit" name="wc_mss_clear_failed_queue" class="button" onclick="return confirm('<?php esc_attr_e('Clear all failed queue items?', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Clear Failed', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                    </form>
                    <form method="post">
                        <?php wp_nonce_field('wc_mss_reset_stuck_queue'); ?>
                        <button type="submit" name="wc_mss_reset_stuck_queue" class="button">
                            <?php esc_html_e('Reset Stuck', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                    </form>
                </div>
                <p class="wc-mss-result description">
                    <?php esc_html_e('Retry Failed: Re-queue all failed items for another attempt. Clear Pending: Removes all waiting items. Reset Stuck: Resets items in "processing" for more than 10 minutes.', 'multi-store-sync-for-woocommerce'); ?>
                </p>
            </div>
        </div>
    </div>
</div>
