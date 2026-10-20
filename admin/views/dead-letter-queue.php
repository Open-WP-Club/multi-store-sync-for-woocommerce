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
$items = WC_Multi_Store_Dead_Letter_Queue::get_items(['limit' => 50, 'offset' => (WC_Multi_Store_Admin_List_Table::page_number() - 1) * 50]);
if (empty($items['results']) && $items['total'] > 0) {
    $items = WC_Multi_Store_Dead_Letter_Queue::get_items(['limit' => 50, 'offset' => (WC_Multi_Store_Admin_List_Table::page_number($items['total']) - 1) * 50]);
}
?>

<div class="wrap">
    <h2><?php esc_html_e('Dead Letter Queue', 'multi-store-sync-for-woocommerce'); ?></h2>
    <p class="description"><?php esc_html_e('Items that have permanently failed after exhausting all retry attempts. Review errors, retry, or dismiss.', 'multi-store-sync-for-woocommerce'); ?></p>

    <!-- Statistics -->
    <div class="wc-mss-dashboard-grid">
        <div class="card wc-mss-card">
            <h3><?php esc_html_e('Statistics', 'multi-store-sync-for-woocommerce'); ?></h3>
            <table class="widefat striped">
                <tr>
                    <td><?php esc_html_e('Dead Items', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td><strong><?php echo esc_html($dlq_stats['total_dead']); ?></strong></td>
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
            <div class="wc-mss-result">
                <button type="button" class="button" id="wc-mss-dlq-retry-all" data-confirm="<?php echo esc_attr__('Retry all failed items?', 'multi-store-sync-for-woocommerce'); ?>"><?php esc_html_e('Retry All', 'multi-store-sync-for-woocommerce'); ?></button>
                <button type="button" class="button button-link-delete" id="wc-mss-dlq-clear-all" data-confirm="<?php echo esc_attr__('Clear all dead letter items? This cannot be undone.', 'multi-store-sync-for-woocommerce'); ?>"><?php esc_html_e('Clear All', 'multi-store-sync-for-woocommerce'); ?></button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($dlq_stats['by_error'])): ?>
        <div class="card wc-mss-card">
            <h3><?php esc_html_e('Top Errors', 'multi-store-sync-for-woocommerce'); ?></h3>
            <table class="widefat striped">
                <?php foreach (array_slice($dlq_stats['by_error'], 0, 5) as $error): ?>
                <tr>
                    <td class="wc-mss-error-details" title="<?php echo esc_attr($error['error_summary']); ?>">
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
            <form method="post">
            <?php
            $list_table = new WC_Multi_Store_Admin_List_Table('dead-letter-queue', [
                'id' => __('ID', 'multi-store-sync-for-woocommerce'),
                'product' => __('Product', 'multi-store-sync-for-woocommerce'),
                'sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
                'store' => __('Store', 'multi-store-sync-for-woocommerce'),
                'sync_type' => __('Type', 'multi-store-sync-for-woocommerce'),
                'attempts' => __('Attempts', 'multi-store-sync-for-woocommerce'),
                'error' => __('Error', 'multi-store-sync-for-woocommerce'),
                'failed' => __('Failed At', 'multi-store-sync-for-woocommerce'),
                'actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
            ], $items['results'], static function (array $item): void {
                ?>
                <td><?php echo esc_html($item['id']); ?></td>
                <td>
                    <a href="<?php echo esc_url(get_edit_post_link($item['product_id'])); ?>">#<?php echo esc_html($item['product_id']); ?></a>
                </td>
                <td><?php echo esc_html($item['product_sku'] ?: '-'); ?></td>
                <td><?php echo esc_html(wp_parse_url($item['store_url'], PHP_URL_HOST) ?: $item['store_url']); ?></td>
                <td><?php echo esc_html($item['sync_type']); ?></td>
                <td><?php echo esc_html($item['attempts']); ?></td>
                <td class="wc-mss-error-details" title="<?php echo esc_attr($item['last_error']); ?>">
                    <?php echo esc_html($item['last_error'] ?: '-'); ?>
                </td>
                <td><?php echo esc_html($item['failed_at']); ?></td>
                <td>
                    <button type="submit" class="button button-small" name="wc_mss_row_action" value="retry:<?php echo esc_attr($item['id']); ?>"><?php esc_html_e('Retry', 'multi-store-sync-for-woocommerce'); ?></button>
                    <button type="submit" class="button button-small" name="wc_mss_row_action" value="dismiss:<?php echo esc_attr($item['id']); ?>"><?php esc_html_e('Dismiss', 'multi-store-sync-for-woocommerce'); ?></button>
                </td>
                <?php
            }, static fn(array $item): bool => true, $items['total']);
            $list_table->display();
            ?>
            </form>

    <?php else: ?>
    <div class="notice notice-info inline">
        <p><?php esc_html_e('No items in the dead letter queue. All syncs are processing successfully!', 'multi-store-sync-for-woocommerce'); ?></p>
    </div>
    <?php endif; ?>
</div>
