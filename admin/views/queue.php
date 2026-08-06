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
    <h1><?php _e('Sync Queue', 'wc-multi-store-sync'); ?></h1>

    <div class="wc-mss-dashboard-grid">
        <div class="wc-mss-card wc-mss-full-width">
            <!-- Queue Stats -->
            <div class="wc-mss-queue-stats" style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                <div class="wc-mss-stat-box" style="background: #f0f0f1; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo esc_html($queue_stats['pending']); ?></div>
                    <div style="font-size: 12px; color: #646970;"><?php _e('Pending', 'wc-multi-store-sync'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #fff3cd; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #856404;"><?php echo esc_html($queue_stats['processing']); ?></div>
                    <div style="font-size: 12px; color: #856404;"><?php _e('Processing', 'wc-multi-store-sync'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #d4edda; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo esc_html($queue_stats['completed']); ?></div>
                    <div style="font-size: 12px; color: #155724;"><?php _e('Completed', 'wc-multi-store-sync'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #f8d7da; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #721c24;"><?php echo esc_html($queue_stats['failed']); ?></div>
                    <div style="font-size: 12px; color: #721c24;"><?php _e('Failed', 'wc-multi-store-sync'); ?></div>
                </div>
                <div class="wc-mss-stat-box" style="background: #e2e3e5; padding: 15px 20px; border-radius: 4px; min-width: 100px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #383d41;"><?php echo esc_html($queue_stats['total']); ?></div>
                    <div style="font-size: 12px; color: #383d41;"><?php _e('Total', 'wc-multi-store-sync'); ?></div>
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
            <div style="margin-bottom: 15px;">
                <label for="queue_status"><?php _e('Filter by status:', 'wc-multi-store-sync'); ?></label>
                <select id="queue_status">
                    <option value="all" <?php selected($queue_status_filter, 'all'); ?>><?php _e('All', 'wc-multi-store-sync'); ?></option>
                    <option value="pending" <?php selected($queue_status_filter, 'pending'); ?>><?php _e('Pending', 'wc-multi-store-sync'); ?></option>
                    <option value="processing" <?php selected($queue_status_filter, 'processing'); ?>><?php _e('Processing', 'wc-multi-store-sync'); ?></option>
                    <option value="completed" <?php selected($queue_status_filter, 'completed'); ?>><?php _e('Completed', 'wc-multi-store-sync'); ?></option>
                    <option value="failed" <?php selected($queue_status_filter, 'failed'); ?>><?php _e('Failed', 'wc-multi-store-sync'); ?></option>
                </select>
                <button type="button" id="wc-mss-refresh-queue" class="button" style="margin-left: 10px;">
                    <?php _e('Refresh', 'wc-multi-store-sync'); ?>
                </button>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var baseUrl = <?php echo wp_json_encode($filter_base_url); ?>;
                var select = document.getElementById('queue_status');
                var refreshBtn = document.getElementById('wc-mss-refresh-queue');

                function applyFilter() {
                    var status = select.value;
                    var url = baseUrl;
                    if (status && status !== 'all') {
                        url += '&queue_status=' + encodeURIComponent(status);
                    }
                    wcMssSafeNavigate(url);
                }

                select.addEventListener('change', applyFilter);
                refreshBtn.addEventListener('click', function() {
                    wcMssSafeNavigate(window.location.href);
                });
            });
            </script>

            <!-- Queue Table -->
            <?php if (!empty($queue_items)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php _e('ID', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 120px;"><?php _e('SKU', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Product', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Store', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 100px;"><?php _e('Sync Type', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 100px;"><?php _e('Status', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 60px;"><?php _e('Priority', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 70px;"><?php _e('Attempts', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 140px;"><?php _e('Created', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 80px;"><?php _e('Actions', 'wc-multi-store-sync'); ?></th>
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
                                <span style="color: #999;"><?php _e('Deleted', 'wc-multi-store-sync'); ?></span>
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
                                echo '<span style="color: #dc3232; font-weight: bold;" title="' . esc_attr__('Critical', 'wc-multi-store-sync') . '">' . esc_html($priority_label) . '</span>';
                            } elseif ($item['priority'] <= 4) {
                                echo '<span style="color: #dba617; font-weight: bold;" title="' . esc_attr__('High', 'wc-multi-store-sync') . '">' . esc_html($priority_label) . '</span>';
                            } else {
                                echo '<span title="' . esc_attr__('Normal', 'wc-multi-store-sync') . '">' . esc_html($priority_label) . '</span>';
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
                                    title="<?php esc_attr_e('Reset to pending for retry', 'wc-multi-store-sync'); ?>">
                                    <?php _e('Retry', 'wc-multi-store-sync'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
                var nonce = '<?php echo esc_js(wp_create_nonce('wc_mss_admin')); ?>';

                document.querySelectorAll('.wc-mss-queue-retry').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.dataset.id;
                        var row = document.getElementById('queue-row-' + id);
                        this.disabled = true;
                        this.textContent = '<?php echo esc_js(__('Retrying…', 'wc-multi-store-sync')); ?>';

                        fetch(ajaxUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({action: 'wc_mss_queue_retry_item', nonce: nonce, item_id: id}).toString()
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(result) {
                            if (result.success) {
                                if (row) {
                                    var statusCell = row.querySelector('td:nth-child(6) span');
                                    if (statusCell) {
                                        statusCell.style.cssText = 'color: #2271b1;';
                                        statusCell.textContent = '<?php echo esc_js(__('Pending', 'wc-multi-store-sync')); ?>';
                                    }
                                    var errorNote = row.querySelector('td:nth-child(6) small');
                                    if (errorNote) errorNote.remove();
                                    var actionCell = row.querySelector('td:last-child');
                                    if (actionCell) actionCell.innerHTML = '';
                                    var attemptsCell = row.querySelector('td:nth-child(8)');
                                    if (attemptsCell) attemptsCell.textContent = '0/3';
                                }
                            } else {
                                alert(result.data.message || '<?php echo esc_js(__('Error', 'wc-multi-store-sync')); ?>');
                                this.disabled = false;
                                this.textContent = '<?php echo esc_js(__('Retry', 'wc-multi-store-sync')); ?>';
                            }
                        }.bind(this))
                        .catch(function() {
                            alert('<?php echo esc_js(__('Request failed', 'wc-multi-store-sync')); ?>');
                            this.disabled = false;
                            this.textContent = '<?php echo esc_js(__('Retry', 'wc-multi-store-sync')); ?>';
                        }.bind(this));
                    });
                });
            });
            </script>
            <p class="description" style="margin-top: 10px;">
                <?php _e('Showing up to 50 most recent queue items. Priority: 1-2 = Critical (red), 3-4 = High (yellow), 5+ = Normal.', 'wc-multi-store-sync'); ?>
            </p>
            <?php else: ?>
            <div class="wc-mss-empty-state" style="text-align: center; padding: 30px; background: #f9f9f9; border-radius: 4px;">
                <p style="color: #646970; margin: 0;"><?php _e('No queue items found.', 'wc-multi-store-sync'); ?></p>
            </div>
            <?php endif; ?>

            <!-- Queue Actions -->
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <?php if ($queue_stats['failed'] > 0): ?>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_retry_failed_queue'); ?>
                        <button type="submit" name="wc_mss_retry_failed_queue" class="button button-primary" onclick="return confirm('<?php esc_attr_e('Retry all failed items? They will be reset to pending for re-processing.', 'wc-multi-store-sync'); ?>');">
                            <?php _e('Retry Failed', 'wc-multi-store-sync'); ?> (<?php echo esc_html($queue_stats['failed']); ?>)
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_clear_pending_queue'); ?>
                        <button type="submit" name="wc_mss_clear_pending_queue" class="button button-primary" style="background: #dc3232; border-color: #dc3232;" onclick="return confirm('<?php esc_attr_e('WARNING: This will clear ALL pending items from the queue!\n\nProducts waiting to sync will NOT be synced.\n\nAre you sure?', 'wc-multi-store-sync'); ?>');">
                            <?php _e('Clear Pending', 'wc-multi-store-sync'); ?> (<?php echo esc_html($queue_stats['pending']); ?>)
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_clear_completed_queue'); ?>
                        <button type="submit" name="wc_mss_clear_completed_queue" class="button" onclick="return confirm('<?php esc_attr_e('Clear all completed queue items?', 'wc-multi-store-sync'); ?>');">
                            <?php _e('Clear Completed', 'wc-multi-store-sync'); ?>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_clear_failed_queue'); ?>
                        <button type="submit" name="wc_mss_clear_failed_queue" class="button" onclick="return confirm('<?php esc_attr_e('Clear all failed queue items?', 'wc-multi-store-sync'); ?>');">
                            <?php _e('Clear Failed', 'wc-multi-store-sync'); ?>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('wc_mss_reset_stuck_queue'); ?>
                        <button type="submit" name="wc_mss_reset_stuck_queue" class="button">
                            <?php _e('Reset Stuck', 'wc-multi-store-sync'); ?>
                        </button>
                    </form>
                </div>
                <p class="description" style="margin-top: 10px;">
                    <?php _e('Retry Failed: Re-queue all failed items for another attempt. Clear Pending: Removes all waiting items. Reset Stuck: Resets items in "processing" for more than 10 minutes.', 'wc-multi-store-sync'); ?>
                </p>
            </div>
        </div>
    </div>
</div>
