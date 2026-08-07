<?php
/**
 * Dead Letter Queue Admin View
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

$dlq_stats = WC_Multi_Store_Dead_Letter_Queue::get_stats();
$items = WC_Multi_Store_Dead_Letter_Queue::get_items(['limit' => 50]);
?>

<div class="wrap">
    <h2><?php _e('Dead Letter Queue', 'wc-multi-store-sync'); ?></h2>
    <p class="description"><?php _e('Items that have permanently failed after exhausting all retry attempts. Review errors, retry, or dismiss.', 'wc-multi-store-sync'); ?></p>

    <!-- Statistics -->
    <div class="wc-mss-dashboard-grid" style="margin-bottom: 20px;">
        <div class="wc-mss-card" style="display: inline-block; padding: 15px; margin-right: 15px;">
            <h3 style="margin-top: 0;"><?php _e('Statistics', 'wc-multi-store-sync'); ?></h3>
            <table class="wc-mss-status-table">
                <tr>
                    <td><?php _e('Dead Items', 'wc-multi-store-sync'); ?></td>
                    <td><strong style="color: <?php echo $dlq_stats['total_dead'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo $dlq_stats['total_dead']; ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Retried', 'wc-multi-store-sync'); ?></td>
                    <td><strong><?php echo $dlq_stats['total_retried']; ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Resolved', 'wc-multi-store-sync'); ?></td>
                    <td><strong><?php echo $dlq_stats['total_resolved']; ?></strong></td>
                </tr>
                <?php if ($dlq_stats['oldest_item']): ?>
                <tr>
                    <td><?php _e('Oldest Failure', 'wc-multi-store-sync'); ?></td>
                    <td><?php echo esc_html($dlq_stats['oldest_item']); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <?php if ($dlq_stats['total_dead'] > 0): ?>
            <div style="margin-top: 10px;">
                <button type="button" class="button" id="wc-mss-dlq-retry-all" data-confirm="<?php echo esc_attr__('Retry all failed items?', 'wc-multi-store-sync'); ?>"><?php _e('Retry All', 'wc-multi-store-sync'); ?></button>
                <button type="button" class="button" id="wc-mss-dlq-clear-all" style="color: #d63638;" data-confirm="<?php echo esc_attr__('Clear all dead letter items? This cannot be undone.', 'wc-multi-store-sync'); ?>"><?php _e('Clear All', 'wc-multi-store-sync'); ?></button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($dlq_stats['by_error'])): ?>
        <div class="wc-mss-card" style="display: inline-block; padding: 15px; vertical-align: top;">
            <h3 style="margin-top: 0;"><?php _e('Top Errors', 'wc-multi-store-sync'); ?></h3>
            <table class="wc-mss-status-table">
                <?php foreach (array_slice($dlq_stats['by_error'], 0, 5) as $error): ?>
                <tr>
                    <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($error['error_summary']); ?>">
                        <?php echo esc_html($error['error_summary']); ?>
                    </td>
                    <td><strong><?php echo esc_html($error['count']); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Items Table -->
    <?php if (!empty($items['results'])): ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 50px;"><?php _e('ID', 'wc-multi-store-sync'); ?></th>
                <th style="width: 80px;"><?php _e('Product', 'wc-multi-store-sync'); ?></th>
                <th style="width: 100px;"><?php _e('SKU', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Store', 'wc-multi-store-sync'); ?></th>
                <th style="width: 100px;"><?php _e('Type', 'wc-multi-store-sync'); ?></th>
                <th style="width: 60px;"><?php _e('Attempts', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Error', 'wc-multi-store-sync'); ?></th>
                <th style="width: 140px;"><?php _e('Failed At', 'wc-multi-store-sync'); ?></th>
                <th style="width: 120px;"><?php _e('Actions', 'wc-multi-store-sync'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items['results'] as $item): ?>
            <tr id="dlq-row-<?php echo esc_attr($item['id']); ?>">
                <td><?php echo esc_html($item['id']); ?></td>
                <td>
                    <a href="<?php echo esc_url(get_edit_post_link($item['product_id'])); ?>">#<?php echo esc_html($item['product_id']); ?></a>
                </td>
                <td><?php echo esc_html($item['product_sku'] ?: '-'); ?></td>
                <td><?php echo esc_html(wp_parse_url($item['store_url'], PHP_URL_HOST) ?: $item['store_url']); ?></td>
                <td><?php echo esc_html($item['sync_type']); ?></td>
                <td><?php echo esc_html($item['attempts']); ?></td>
                <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($item['last_error']); ?>">
                    <?php echo esc_html($item['last_error'] ?: '-'); ?>
                </td>
                <td><?php echo esc_html($item['failed_at']); ?></td>
                <td>
                    <button type="button" class="button button-small wc-mss-dlq-retry" data-id="<?php echo esc_attr($item['id']); ?>"><?php _e('Retry', 'wc-multi-store-sync'); ?></button>
                    <button type="button" class="button button-small wc-mss-dlq-resolve" data-id="<?php echo esc_attr($item['id']); ?>"><?php _e('Dismiss', 'wc-multi-store-sync'); ?></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($items['total'] > 50): ?>
    <p class="description"><?php echo sprintf(__('Showing 50 of %d items.', 'wc-multi-store-sync'), $items['total']); ?></p>
    <?php endif; ?>

    <?php else: ?>
    <div class="wc-mss-empty-state" style="text-align: center; padding: 40px;">
        <p style="font-size: 16px; color: #646970;"><?php _e('No items in the dead letter queue. All syncs are processing successfully!', 'wc-multi-store-sync'); ?></p>
    </div>
    <?php endif; ?>
</div>
