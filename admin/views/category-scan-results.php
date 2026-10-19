<?php
/**
 * Native list table for the category scan's per-store results.
 *
 * @package WC_Multi_Store_Sync
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=discrepancies')); ?>">
<?php
$scan_items = array_map(static fn(array $item): array => ['id' => $item['product_id']] + $item, $scan_result['items']);
$list_table = new WC_Multi_Store_Admin_List_Table('category-scan', [
    'product' => __('Product', 'multi-store-sync-for-woocommerce'),
    'sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
    'missing' => __('Missing on remote', 'multi-store-sync-for-woocommerce'),
    'extra' => __('Extra on remote', 'multi-store-sync-for-woocommerce'),
    'actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
], $scan_items, static function (array $item): void {
    ?>
    <td><a href="<?php echo esc_url($item['edit_link']); ?>"><strong><?php echo esc_html($item['product_name']); ?></strong></a></td>
    <td><code><?php echo esc_html($item['sku']); ?></code></td>
    <td><?php echo esc_html(implode(', ', $item['missing']) ?: '—'); ?></td>
    <td><?php echo esc_html(implode(', ', $item['extra']) ?: '—'); ?></td>
    <td><button type="submit" class="button button-small" name="wc_mss_row_action" value="resync:<?php echo esc_attr($item['id']); ?>"><?php esc_html_e('Re-sync to all stores', 'multi-store-sync-for-woocommerce'); ?></button></td>
    <?php
}, static fn(array $item): bool => $item['id'] > 0);
$list_table->display();
?>
</form>
