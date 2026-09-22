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
        <div class="wc-mss-card wc-mss-full-width">
            <!-- Queue Stats -->
            <div class="wc-mss-queue-stats" style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                <div class="wc-mss-stat-box" style="background: #f0f0f1; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo esc_html($queue_stats['pending']); ?></div>
                    <div style="font-size: 12px; color: #646970;"><?php esc_html_e('Pending', 'multi-store-sync-for-woocommerce'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #fff3cd; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #856404;"><?php echo esc_html($queue_stats['processing']); ?></div>
                    <div style="font-size: 12px; color: #856404;"><?php esc_html_e('Processing', 'multi-store-sync-for-woocommerce'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #d4edda; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo esc_html($queue_stats['completed']); ?></div>
                    <div style="font-size: 12px; color: #155724;"><?php esc_html_e('Completed', 'multi-store-sync-for-woocommerce'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #f8d7da; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #721c24;"><?php echo esc_html($queue_stats['failed']); ?></div>
                    <div style="font-size: 12px; color: #721c24;"><?php esc_html_e('Failed', 'multi-store-sync-for-woocommerce'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #e2e3e5; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #383d41;"><?php echo esc_html($queue_stats['total']); ?></div>
                    <div style="font-size: 12px; color: #383d41;"><?php esc_html_e('Total', 'multi-store-sync-for-woocommerce'); ?></div>
                </div>
            </div>

            <!-- Queue Filter -->
            <?php
            $filter_base_url = esc_url_raw(add_query_arg([
                'page' => 'wc-settings',
                'tab' => 'multi_store_sync',
                'section' => 'queue',
            ], admin_url('admin.php')));
            ?>
            <div id="wc-mss-queue-filter" style="margin-bottom: 15px;" data-base-url="<?php echo esc_attr($filter_base_url); ?>">
                <label for="queue_status"><?php esc_html_e('Filter by status:', 'multi-store-sync-for-woocommerce'); ?></label>
                <select id="queue_status">
                    <option value="all" <?php selected($queue_status_filter, 'all'); ?>><?php esc_html_e('All', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="pending" <?php selected($queue_status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="processing" <?php selected($queue_status_filter, 'processing'); ?>><?php esc_html_e('Processing', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="completed" <?php selected($queue_status_filter, 'completed'); ?>><?php esc_html_e('Completed', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="failed" <?php selected($queue_status_filter, 'failed'); ?>><?php esc_html_e('Failed', 'multi-store-sync-for-woocommerce'); ?></option>
                </select>
                <button type="button" id="wc-mss-refresh-queue" class="button" style="margin-left: 10px;">
                    <?php esc_html_e('Refresh', 'multi-store-sync-for-woocommerce'); ?>
                </button>
            </div>

            <!-- Queue Table -->
            <?php if (!empty($queue_items)): ?>
            <table class="wp-list-table widefat fixed striped"
                data-label-retry="<?php echo esc_attr__('Retry', 'multi-store-sync-for-woocommerce'); ?>"
                data-label-retrying="<?php echo esc_attr__('Retrying…', 'multi-store-sync-for-woocommerce'); ?>"
                data-label-pending="<?php echo esc_attr__('Pending', 'multi-store-sync-for-woocommerce'); ?>"
                data-label-error="<?php echo esc_attr__('Error', 'multi-store-sync-for-woocommerce'); ?>">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php esc_html_e('ID', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th style="width: 120px;"><?php esc_html_e('SKU', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Product', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Store', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Sync Type', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Status', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th style="width: 60px;"><?php esc_html_e('Priority', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th style="width: 70px;"><?php esc_html_e('Attempts', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th style="width: 140px;"><?php esc_html_e('Created', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th style="width: 80px;"><?php esc_html_e('Actions', 'multi-store-sync-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue_items as $item): ?>
                    <tr id="queue-row-<?php echo esc_attr($item['id']); ?>">
                        <td><?php echo esc_html($item['id']); ?></td>
                        <td><code><?php echo esc_html($item['product_sku'] ?: '-'); ?></code></td>
                        <td>
                            <?php if ($item['product_name'] !== 'Deleted'): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($item['product_id'])); ?>" target="_blank">
                                    <?php echo esc_html($item['product_name']); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #999;"><?php esc_html_e('Deleted', 'multi-store-sync-for-woocommerce'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo esc_html($item['store_url']); ?></small></td>
                        <td><small><?php echo esc_html($item['sync_type']); ?></small></td>
                        <td>
                            <?php
                            $status_label = $item['status'];
                            $status_class = match ($item['status']) {
                                'pending' => 'color: #2271b1;',
                                'processing' => 'color: #856404; background: #fff3cd; padding: 2px 6px; border-radius: 3px;',
                                'completed' => 'color: #155724; background: #d4edda; padding: 2px 6px; border-radius: 3px;',
                                'failed' => 'color: #721c24; background: #f8d7da; padding: 2px 6px; border-radius: 3px;',
                                default => '',
                            };
                            ?>
                            <span style="<?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($status_label)); ?></span>
                            <?php if ($item['status'] === 'failed' && !empty($item['last_error'])): ?>
                                <br><small style="color: #dc3232;" title="<?php echo esc_attr($item['last_error']); ?>">
                                    <?php echo esc_html(substr($item['last_error'], 0, 50)); ?><?php echo strlen($item['last_error']) > 50 ? '...' : ''; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php
                            $priority_label = $item['priority'];
                            if ($item['priority'] <= 2) {
                                echo '<span style="color: #dc3232; font-weight: bold;" title="' . esc_attr__('Critical', 'multi-store-sync-for-woocommerce') . '">' . esc_html($priority_label) . '</span>';
                            } elseif ($item['priority'] <= 4) {
                                echo '<span style="color: #dba617; font-weight: bold;" title="' . esc_attr__('High', 'multi-store-sync-for-woocommerce') . '">' . esc_html($priority_label) . '</span>';
                            } else {
                                echo '<span title="' . esc_attr__('Normal', 'multi-store-sync-for-woocommerce') . '">' . esc_html($priority_label) . '</span>';
                            }
                            ?>
                        </td>
                        <td style="text-align: center;"><?php echo esc_html($item['attempts']); ?>/3</td>
                        <td><small><?php echo esc_html($item['created_at']); ?></small></td>
                        <td>
                            <?php if ($item['status'] === 'failed'): ?>
                                <button type="button"
                                    class="button button-small wc-mss-queue-retry"
                                    data-id="<?php echo esc_attr($item['id']); ?>"
                                    title="<?php esc_attr_e('Reset to pending for retry', 'multi-store-sync-for-woocommerce'); ?>">
                                    <?php esc_html_e('Retry', 'multi-store-sync-for-woocommerce'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top: 10px;">
                <?php esc_html_e('Showing up to 50 most recent queue items. Priority: 1-2 = Critical (red), 3-4 = High (yellow), 5+ = Normal.', 'multi-store-sync-for-woocommerce'); ?>
            </p>
            <?php else: ?>
            <div class="wc-mss-empty-state" style="text-align: center; padding: 30px; background: #f9f9f9; border-radius: 4px;">
                <p style="color: #646970; margin: 0;"><?php esc_html_e('No queue items found.', 'multi-store-sync-for-woocommerce'); ?></p>
            </div>
            <?php endif; ?>

            <!-- Queue Actions -->
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <?php if ($queue_stats['failed'] > 0): ?>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_retry_failed_queue'); ?>
                        <button type="submit" name="wc_mss_retry_failed_queue" class="button button-primary" onclick="return confirm('<?php esc_attr_e('Retry all failed items? They will be reset to pending for re-processing.', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Retry Failed', 'multi-store-sync-for-woocommerce'); ?> (<?php echo esc_html($queue_stats['failed']); ?>)
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_clear_pending_queue'); ?>
                        <button type="submit" name="wc_mss_clear_pending_queue" class="button button-primary" style="background: #dc3232; border-color: #dc3232;" onclick="return confirm('<?php esc_attr_e('WARNING: This will clear ALL pending items from the queue!\n\nProducts waiting to sync will NOT be synced.\n\nAre you sure?', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Clear Pending', 'multi-store-sync-for-woocommerce'); ?> (<?php echo esc_html($queue_stats['pending']); ?>)
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_clear_completed_queue'); ?>
                        <button type="submit" name="wc_mss_clear_completed_queue" class="button" onclick="return confirm('<?php esc_attr_e('Clear all completed queue items?', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Clear Completed', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_clear_failed_queue'); ?>
                        <button type="submit" name="wc_mss_clear_failed_queue" class="button" onclick="return confirm('<?php esc_attr_e('Clear all failed queue items?', 'multi-store-sync-for-woocommerce'); ?>');">
                            <?php esc_html_e('Clear Failed', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_reset_stuck_queue'); ?>
                        <button type="submit" name="wc_mss_reset_stuck_queue" class="button">
                            <?php esc_html_e('Reset Stuck', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                    </form>
                </div>
                <p class="description" style="margin-top: 10px;">
                    <?php esc_html_e('Retry Failed: Re-queue all failed items for another attempt. Clear Pending: Removes all waiting items. Reset Stuck: Resets items in "processing" for more than 10 minutes.', 'multi-store-sync-for-woocommerce'); ?>
                </p>
            </div>
        </div>
    </div>
</div>
