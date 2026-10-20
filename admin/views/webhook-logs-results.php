<?php
/**
 * Native, read-only webhook log results for the asynchronous log viewer.
 *
 * @var array $webhook_logs Enriched rows from the webhook log AJAX handler.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

$list_table = new WC_Multi_Store_Admin_List_Table('webhook-logs', [
    'created' => __('Date', 'multi-store-sync-for-woocommerce'),
    'type' => __('Type', 'multi-store-sync-for-woocommerce'),
    'sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
    'store' => __('Store', 'multi-store-sync-for-woocommerce'),
    'stock' => __('Stock Change', 'multi-store-sync-for-woocommerce'),
    'reason' => __('Reason', 'multi-store-sync-for-woocommerce'),
    'status' => __('Status', 'multi-store-sync-for-woocommerce'),
    'actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
], $webhook_logs, static function (array $log): void {
    $stock_change = '-';
    if (isset($log['old_stock'], $log['new_stock'])) {
        $stock_change = (string) $log['old_stock'] . ' → ' . (string) $log['new_stock'];
        if (!empty($log['quantity_changed'])) {
            $stock_change .= sprintf(' (%+d)', (int) $log['quantity_changed']);
        }
    }
    $status_labels = [
        'success' => __('Success', 'multi-store-sync-for-woocommerce'),
        'failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
    ];
    $status = (string) ($log['status'] ?? '');
    ?>
    <td><?php echo esc_html($log['created_at'] ?? ''); ?></td>
    <td><?php echo esc_html($log['type_label'] ?? $log['log_type'] ?? ''); ?></td>
    <td><code><?php echo esc_html(($log['product_sku'] ?? '') ?: '-'); ?></code></td>
    <td><?php echo esc_html(($log['store_url'] ?? '') ?: '-'); ?></td>
    <td><?php echo esc_html($stock_change); ?></td>
    <td title="<?php echo esc_attr($log['change_reason'] ?? ''); ?>"><?php echo esc_html(($log['change_reason'] ?? '') ?: '-'); ?></td>
    <td><?php echo esc_html($status_labels[$status] ?? $status); ?></td>
    <td>
        <button type="button" class="button button-small view-webhook-detail" data-id="<?php echo esc_attr($log['id']); ?>">
            <?php esc_html_e('View', 'multi-store-sync-for-woocommerce'); ?>
        </button>
    </td>
    <?php
});
$list_table->display();
