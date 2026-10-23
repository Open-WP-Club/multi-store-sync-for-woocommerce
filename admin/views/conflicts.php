<?php
/**
 * Conflict Detector Admin Page
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

$conflict_settings = WC_Multi_Store_Conflict_Detector::get_settings();
$stats = WC_Multi_Store_Conflict_Detector::get_stats();
?>

<div class="wrap wc-mss-conflicts-page wc-mss-page">
    <h1>
        <?php esc_html_e('Sync Conflicts', 'multi-store-sync-for-woocommerce'); ?>
        <?php if ($stats['unresolved'] > 0): ?>
            <span class="description">(<?php echo esc_html($stats['unresolved']); ?>)</span>
        <?php endif; ?>
    </h1>

    <p class="description">
        <?php esc_html_e('Products that were edited directly on a remote store since the last sync are flagged here before they get overwritten. This requires "Conflict Detection" to be enabled under Settings → Additional Sync Features.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <p class="description">
        <?php esc_html_e('Resolutions run through the sync queue. Overwrite sends local data; Keep Remote accepts the current remote version without changing the local product; Merge keeps remotely changed fields and syncs the rest. These decisions apply to this conflict; future syncs still follow the normal store rules. A conflict is marked resolved only after successful processing. Sync must be enabled for the queue to run.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <?php if (WC_Multi_Store_Settings::is_primary_authoritative()): ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e('Primary Site Wins is on: conflicts are resolved automatically by overwriting the remote edit with this site\'s data, and are closed as "auto_overwrite" once that sync succeeds. Nothing here needs a decision.', 'multi-store-sync-for-woocommerce'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($conflict_settings['enabled'])): ?>
        <div class="notice notice-warning inline">
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to the Settings tab */
                        __('Conflict detection is currently disabled. Enable it in %s to start tracking remote edits.', 'multi-store-sync-for-woocommerce'),
                        '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=settings')) . '">' . esc_html__('Settings', 'multi-store-sync-for-woocommerce') . '</a>'
                    ),
                    ['a' => ['href' => []]]
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter or pagination.
    $status_filter = isset($_GET['status']) && is_string($_GET['status']) && sanitize_key(wp_unslash($_GET['status'])) === 'all' ? 'all' : 'unresolved';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter or pagination.
    $store_filter = isset($_GET['store']) && is_string($_GET['store']) ? esc_url_raw(wp_unslash($_GET['store'])) : '';
    $total = WC_Multi_Store_Conflict_Detector::get_conflict_count($store_filter, $status_filter === 'all');
    $page = WC_Multi_Store_Admin_List_Table::page_number($total);
    $conflicts = WC_Multi_Store_Conflict_Detector::get_conflicts($store_filter, 50, ($page - 1) * 50, $status_filter === 'all');
    $products = [];
    if ($conflicts) {
        foreach (wc_get_products(['include' => array_unique(array_column($conflicts, 'local_product_id')), 'limit' => -1]) as $product) {
            $products[$product->get_id()] = $product;
        }
    }
    ?>
    <form method="get">
        <input type="hidden" name="page" value="wc-settings" />
        <input type="hidden" name="tab" value="multi_store_sync" />
        <input type="hidden" name="section" value="conflicts" />
        <label class="screen-reader-text" for="wc-mss-conflicts-status-filter"><?php esc_html_e('Status', 'multi-store-sync-for-woocommerce'); ?></label>
        <select name="status" id="wc-mss-conflicts-status-filter">
            <option value="unresolved" <?php selected($status_filter, 'unresolved'); ?>><?php esc_html_e('Unresolved', 'multi-store-sync-for-woocommerce'); ?></option>
            <option value="all" <?php selected($status_filter, 'all'); ?>><?php esc_html_e('All', 'multi-store-sync-for-woocommerce'); ?></option>
        </select>
        <label class="screen-reader-text" for="wc-mss-conflicts-store-filter"><?php esc_html_e('Store', 'multi-store-sync-for-woocommerce'); ?></label>
        <select name="store" id="wc-mss-conflicts-store-filter">
            <option value=""><?php esc_html_e('All Stores', 'multi-store-sync-for-woocommerce'); ?></option>
            <?php foreach ($stores as $store_url => $store_config): ?>
                <option value="<?php echo esc_attr($store_url); ?>" <?php selected($store_filter, $store_url); ?>><?php echo esc_html($store_config['name'] ?? $store_url); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e('Filter', 'multi-store-sync-for-woocommerce'); ?></button>
    </form>
    <form method="post">
        <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('conflicts', [
            'product' => __('Product', 'multi-store-sync-for-woocommerce'),
            'store' => __('Store', 'multi-store-sync-for-woocommerce'),
            'fields' => __('Changed Fields', 'multi-store-sync-for-woocommerce'),
            'detected' => __('Detected', 'multi-store-sync-for-woocommerce'),
            'status' => __('Status', 'multi-store-sync-for-woocommerce'),
            'actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
        ], $conflicts, static function (array $conflict) use ($products): void {
            $product = $products[$conflict['local_product_id']] ?? null;
            $queued = in_array($conflict['queue_status'] ?? '', ['pending', 'processing'], true);
            $status = $conflict['resolved'] ? __('Resolved', 'multi-store-sync-for-woocommerce') : match ($conflict['queue_status'] ?? '') {
                'pending' => __('Queued', 'multi-store-sync-for-woocommerce'),
                'processing' => __('Processing', 'multi-store-sync-for-woocommerce'),
                'failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
                default => __('Unresolved', 'multi-store-sync-for-woocommerce'),
            };
            ?>
            <td>
                <?php if ($product): ?>
                    <a href="<?php echo esc_url(get_edit_post_link($product->get_id())); ?>"><strong><?php echo esc_html($product->get_name()); ?></strong></a><br />
                    <code><?php echo esc_html($product->get_sku()); ?></code>
                <?php else: ?>
                    <?php esc_html_e('(Product not found)', 'multi-store-sync-for-woocommerce'); ?>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html($conflict['store_url']); ?></td>
            <td><?php echo esc_html(implode(', ', $conflict['changed_fields'])); ?></td>
            <td><?php echo esc_html($conflict['detected_at']); ?></td>
            <td><?php echo esc_html($status); ?><?php if (!empty($conflict['resolution'])) echo ' (' . esc_html($conflict['resolution']) . ')'; ?></td>
            <td>
                <?php if (!$conflict['resolved'] && !$queued): ?>
                    <?php foreach (WC_Multi_Store_Admin_List_Table::actions_for('conflicts') as $action => $label): ?>
                        <button type="submit" class="button button-small" name="wc_mss_row_action" value="<?php echo esc_attr($action . ':' . $conflict['id']); ?>"><?php echo esc_html($label); ?></button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <?php
        }, static fn(array $item): bool => !$item['resolved'] && !in_array($item['queue_status'] ?? '', ['pending', 'processing'], true), $total);
        $list_table->display();
        ?>
    </form>
</div>
