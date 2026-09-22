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
    <h2><?php esc_html_e('Dead Letter Queue', 'multi-store-sync-for-woocommerce'); ?></h2>
    <p class="description"><?php esc_html_e('Items that have permanently failed after exhausting all retry attempts. Review errors, retry, or dismiss.', 'multi-store-sync-for-woocommerce'); ?></p>

    <!-- Statistics -->
    <div class="wc-mss-dashboard-grid" style="margin-bottom: 20px;">
        <div class="wc-mss-card" style="display: inline-block; padding: 15px; margin-right: 15px;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Statistics', 'multi-store-sync-for-woocommerce'); ?></h3>
            <table class="wc-mss-status-table">
                <tr>
                    <td><?php esc_html_e('Dead Items', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td><strong style="color: <?php echo esc_attr($dlq_stats['total_dead'] > 0 ? '#d63638' : '#00a32a'); ?>;"><?php echo esc_html($dlq_stats['total_dead']); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Retried', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td><strong><?php echo esc_html($dlq_stats['total_retried']); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Resolved', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td><strong><?php echo esc_html($dlq_stats['total_resolved']); ?></strong></td>
                </tr>
                <?php if ($dlq_stats['oldest_item']): ?>
                <tr>
                    <td><?php esc_html_e('Oldest Failure', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td><?php echo esc_html($dlq_stats['oldest_item']); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <?php if ($dlq_stats['total_dead'] > 0): ?>
            <div style="margin-top: 10px;">
                <button type="button" class="button" id="wc-mss-dlq-retry-all" data-confirm="<?php echo esc_attr__('Retry all failed items?', 'multi-store-sync-for-woocommerce'); ?>"><?php esc_html_e('Retry All', 'multi-store-sync-for-woocommerce'); ?></button>
                <button type="button" class="button" id="wc-mss-dlq-clear-all" style="color: #d63638;" data-confirm="<?php echo esc_attr__('Clear all dead letter items? This cannot be undone.', 'multi-store-sync-for-woocommerce'); ?>"><?php esc_html_e('Clear All', 'multi-store-sync-for-woocommerce'); ?></button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($dlq_stats['by_error'])): ?>
        <div class="wc-mss-card" style="display: inline-block; padding: 15px; vertical-align: top;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Top Errors', 'multi-store-sync-for-woocommerce'); ?></h3>
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
                <th style="width: 50px;"><?php esc_html_e('ID', 'multi-store-sync-for-woocommerce'); ?></th>
                <th style="width: 80px;"><?php esc_html_e('Product', 'multi-store-sync-for-woocommerce'); ?></th>
                <th style="width: 100px;"><?php esc_html_e('SKU', 'multi-store-sync-for-woocommerce'); ?></th>
                <th><?php esc_html_e('Store', 'multi-store-sync-for-woocommerce'); ?></th>
                <th style="width: 100px;"><?php esc_html_e('Type', 'multi-store-sync-for-woocommerce'); ?></th>
                <th style="width: 60px;"><?php esc_html_e('Attempts', 'multi-store-sync-for-woocommerce'); ?></th>
                <th><?php esc_html_e('Error', 'multi-store-sync-for-woocommerce'); ?></th>
                <th style="width: 140px;"><?php esc_html_e('Failed At', 'multi-store-sync-for-woocommerce'); ?></th>
                <th style="width: 120px;"><?php esc_html_e('Actions', 'multi-store-sync-for-woocommerce'); ?></th>
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
                    <button type="button" class="button button-small wc-mss-dlq-retry" data-id="<?php echo esc_attr($item['id']); ?>"><?php esc_html_e('Retry', 'multi-store-sync-for-woocommerce'); ?></button>
                    <button type="button" class="button button-small wc-mss-dlq-resolve" data-id="<?php echo esc_attr($item['id']); ?>"><?php esc_html_e('Dismiss', 'multi-store-sync-for-woocommerce'); ?></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($items['total'] > 50): ?>
    <p class="description"><?php printf(esc_html__('Showing 50 of %d items.', 'multi-store-sync-for-woocommerce'), absint($items['total'])); ?></p>
    <?php endif; ?>

    <?php else: ?>
    <div class="wc-mss-empty-state" style="text-align: center; padding: 40px;">
        <p style="font-size: 16px; color: #646970;"><?php esc_html_e('No items in the dead letter queue. All syncs are processing successfully!', 'multi-store-sync-for-woocommerce'); ?></p>
    </div>
    <?php endif; ?>
</div>
