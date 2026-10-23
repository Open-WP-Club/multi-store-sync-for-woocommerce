<?php
/**
 * Sync History View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Read-only GET filters; they only select history/statistics data and do not change state.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only history filter.
$filter_store = isset($_GET['filter_store']) ? sanitize_text_field(wp_unslash($_GET['filter_store'])) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only history filter.
$filter_status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only history filter.
$filter_days = isset($_GET['filter_days']) ? absint(wp_unslash($_GET['filter_days'])) : 7;
$page_num = WC_Multi_Store_Admin_List_Table::page_number();
$per_page = 50;

// Get history
$history_args = [
    'limit' => $per_page,
    'offset' => ($page_num - 1) * $per_page,
];

if (!empty($filter_store)) {
    $history_args['store_url'] = $filter_store;
}

if (!empty($filter_status)) {
    $history_args['status'] = $filter_status;
}

$history_data = WC_Multi_Store_Sync_History::get_history($history_args);

// Get statistics
$stats = WC_Multi_Store_Sync_History::get_statistics([
    'days' => $filter_days,
    'store_url' => $filter_store,
]);

// Get all stores for filter dropdown
$stores = WC_Multi_Store_Settings::get_stores();

?>

<?php
// History management data
$total_records = WC_Multi_Store_Sync_History::get_count();
$error_records = WC_Multi_Store_Sync_History::get_count('error');
$success_records = WC_Multi_Store_Sync_History::get_count('success');
$table_size = WC_Multi_Store_Sync_History::get_table_size();
$table_size_formatted = size_format($table_size, 2);
?>

<div class="wrap wc-mss-page">
    <h1><?php esc_html_e('Sync History & Statistics', 'multi-store-sync-for-woocommerce'); ?></h1>

    <!-- Statistics Overview with integrated History Management -->
    <div class="wc-mss-stats-overview">
        <div class="wc-mss-toolbar">
            <h2><?php /* translators: %d: number of days in the statistics period. */ printf(esc_html__('Statistics (Last %d Days)', 'multi-store-sync-for-woocommerce'), absint($filter_days)); ?></h2>
            <div>
                <span id="wc-mss-total-records"><?php echo esc_html(number_format($total_records)); ?></span> <?php esc_html_e('records', 'multi-store-sync-for-woocommerce'); ?>
                (<span><?php echo esc_html(number_format($success_records)); ?></span> /
                <span><?php echo esc_html(number_format($error_records)); ?></span>)
                &bull; <?php echo esc_html($table_size_formatted); ?>
                <button type="button" id="wc-mss-toggle-cleanup" class="button button-small">
                    <?php esc_html_e('Cleanup', 'multi-store-sync-for-woocommerce'); ?>
                </button>
            </div>
        </div>

        <!-- Compact Cleanup Controls (hidden by default) -->
        <div id="wc-mss-cleanup-controls" style="display: none;">
            <div class="wc-mss-toolbar">
                <select id="wc-mss-delete-type">
                    <option value=""><?php esc_html_e('-- Select action --', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="errors"><?php esc_html_e('Delete error records only', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="successful"><?php esc_html_e('Delete successful records only', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="older_than"><?php esc_html_e('Delete records older than...', 'multi-store-sync-for-woocommerce'); ?></option>
                    <?php if (!empty($stores)): ?>
                    <option value="by_store"><?php esc_html_e('Delete records for store...', 'multi-store-sync-for-woocommerce'); ?></option>
                    <?php endif; ?>
                    <option value="all"><?php esc_html_e('Delete ALL records', 'multi-store-sync-for-woocommerce'); ?></option>
                </select>
                <select id="wc-mss-delete-days" style="display: none;">
                    <option value="7">7 <?php esc_html_e('days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="14">14 <?php esc_html_e('days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="30" selected>30 <?php esc_html_e('days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="60">60 <?php esc_html_e('days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="90">90 <?php esc_html_e('days', 'multi-store-sync-for-woocommerce'); ?></option>
                </select>
                <select id="wc-mss-delete-store" style="display: none;">
                    <?php foreach ($stores as $url => $config): ?>
                        <option value="<?php echo esc_attr($url); ?>"><?php echo esc_html($url); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="wc-mss-delete-history" class="button" disabled>
                    <?php esc_html_e('Delete', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <div id="wc-mss-delete-status" aria-live="polite"></div>
            </div>
        </div>

        <div class="wc-mss-toolbar">
            <div class="card wc-mss-stat-card">
                <div><?php esc_html_e('Total Syncs', 'multi-store-sync-for-woocommerce'); ?></div>
                <div><?php echo number_format($stats['overall']['total_syncs'] ?? 0); ?></div>
            </div>
            <div class="card wc-mss-stat-card">
                <div><?php esc_html_e('Success Rate', 'multi-store-sync-for-woocommerce'); ?></div>
                <div><?php echo esc_html($stats['overall']['success_rate'] ?? 0); ?>%</div>
            </div>
            <div class="card wc-mss-stat-card">
                <div><?php esc_html_e('Avg Duration', 'multi-store-sync-for-woocommerce'); ?></div>
                <div><?php echo esc_html(round($stats['overall']['avg_duration_ms'] ?? 0)); ?>ms</div>
            </div>
            <div class="card wc-mss-stat-card">
                <div><?php esc_html_e('API Calls', 'multi-store-sync-for-woocommerce'); ?></div>
                <div><?php echo number_format($stats['overall']['total_api_calls'] ?? 0); ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <?php
    // Build base URL for filters (using JavaScript navigation to avoid "Leave site?" prompt)
    // Use esc_url_raw to avoid HTML entity encoding of & characters
    $filter_base_url = esc_url_raw(add_query_arg([
        'page' => 'wc-settings',
        'tab' => 'multi_store_sync',
        'section' => 'history',
    ], admin_url('admin.php')));
    ?>
    <div class="wc-mss-filters wc-mss-toolbar">
        <label><?php esc_html_e('Store:', 'multi-store-sync-for-woocommerce'); ?></label>
        <select id="wc-mss-filter-store">
            <option value=""><?php esc_html_e('All Stores', 'multi-store-sync-for-woocommerce'); ?></option>
            <?php foreach ($stores as $url => $config): ?>
                <option value="<?php echo esc_attr($url); ?>" <?php selected($filter_store, $url); ?>>
                    <?php echo esc_html($url); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label><?php esc_html_e('Status:', 'multi-store-sync-for-woocommerce'); ?></label>
        <select id="wc-mss-filter-status">
            <option value=""><?php esc_html_e('All Statuses', 'multi-store-sync-for-woocommerce'); ?></option>
            <option value="success" <?php selected($filter_status, 'success'); ?>><?php esc_html_e('Success', 'multi-store-sync-for-woocommerce'); ?></option>
            <option value="error" <?php selected($filter_status, 'error'); ?>><?php esc_html_e('Error', 'multi-store-sync-for-woocommerce'); ?></option>
        </select>

        <label><?php esc_html_e('Stats Period:', 'multi-store-sync-for-woocommerce'); ?></label>
        <select id="wc-mss-filter-days">
            <option value="1" <?php selected($filter_days, 1); ?>>1 <?php esc_html_e('Day', 'multi-store-sync-for-woocommerce'); ?></option>
            <option value="7" <?php selected($filter_days, 7); ?>>7 <?php esc_html_e('Days', 'multi-store-sync-for-woocommerce'); ?></option>
            <option value="30" <?php selected($filter_days, 30); ?>>30 <?php esc_html_e('Days', 'multi-store-sync-for-woocommerce'); ?></option>
            <option value="90" <?php selected($filter_days, 90); ?>>90 <?php esc_html_e('Days', 'multi-store-sync-for-woocommerce'); ?></option>
        </select>

        <button type="button" id="wc-mss-apply-filters" class="button">
            <?php esc_html_e('Filter', 'multi-store-sync-for-woocommerce'); ?>
        </button>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var baseUrl = <?php echo wp_json_encode($filter_base_url); ?>;

        function applyFilters() {
            var store = document.getElementById('wc-mss-filter-store').value;
            var status = document.getElementById('wc-mss-filter-status').value;
            var days = document.getElementById('wc-mss-filter-days').value;

            var url = baseUrl;
            if (store) url += '&filter_store=' + encodeURIComponent(store);
            if (status) url += '&filter_status=' + encodeURIComponent(status);
            if (days) url += '&filter_days=' + encodeURIComponent(days);

            wcMssSafeNavigate(url);
        }

        // Auto-apply on change
        document.getElementById('wc-mss-filter-store').addEventListener('change', applyFilters);
        document.getElementById('wc-mss-filter-status').addEventListener('change', applyFilters);
        document.getElementById('wc-mss-filter-days').addEventListener('change', applyFilters);
        document.getElementById('wc-mss-apply-filters').addEventListener('click', applyFilters);
    });
    </script>

    <!-- Cleanup Controls Script -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle cleanup controls
        var toggleBtn = document.getElementById('wc-mss-toggle-cleanup');
        var controls = document.getElementById('wc-mss-cleanup-controls');

        toggleBtn.addEventListener('click', function() {
            var isHidden = controls.style.display === 'none';
            controls.style.display = isHidden ? 'block' : 'none';
            toggleBtn.textContent = isHidden ? '<?php echo esc_js(__('Hide', 'multi-store-sync-for-woocommerce')); ?>' : '<?php echo esc_js(__('Cleanup', 'multi-store-sync-for-woocommerce')); ?>';
        });

        // Delete type change handler
        var deleteType = document.getElementById('wc-mss-delete-type');
        var deleteBtn = document.getElementById('wc-mss-delete-history');
        var daysSelect = document.getElementById('wc-mss-delete-days');
        var storeSelect = document.getElementById('wc-mss-delete-store');
        var statusSpan = document.getElementById('wc-mss-delete-status');

        deleteType.addEventListener('change', function() {
            var value = this.value;
            deleteBtn.disabled = !value;
            daysSelect.style.display = value === 'older_than' ? 'inline-block' : 'none';
            storeSelect.style.display = value === 'by_store' ? 'inline-block' : 'none';
        });

        // Delete handler
        deleteBtn.addEventListener('click', function() {
            var type = deleteType.value;
            if (!type) return;

            var confirmMsg = '<?php echo esc_js(__('Are you sure you want to delete these history records?', 'multi-store-sync-for-woocommerce')); ?>';
            if (type === 'all') {
                confirmMsg = '<?php echo esc_js(__('WARNING: This will delete ALL history records!', 'multi-store-sync-for-woocommerce')); ?>';
            }
            if (!confirm(confirmMsg)) return;

            deleteBtn.disabled = true;
            window.wcMssNotice(statusSpan, '<?php echo esc_js(__('Deleting...', 'multi-store-sync-for-woocommerce')); ?>', 'info');

            var data = {delete_type: type};
            if (type === 'older_than') {
                data.days = daysSelect.value;
            } else if (type === 'by_store') {
                data.store_url = storeSelect.value;
            }

            window.wcMssRequest('wc_mss_delete_history', data).then(function(result) {
                window.wcMssNotice(statusSpan, result.message, 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
            }).catch(function(error) {
                window.wcMssNotice(statusSpan, error.message, 'error');
                deleteBtn.disabled = false;
            });
        });
    });
    </script>

    <!-- History Table -->
    <h2><?php esc_html_e('Recent Sync History', 'multi-store-sync-for-woocommerce'); ?></h2>

    <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('history', [
            'history-date-time' => __('Date/Time', 'multi-store-sync-for-woocommerce'),
            'history-product' => __('Product', 'multi-store-sync-for-woocommerce'),
            'history-store' => __('Store', 'multi-store-sync-for-woocommerce'),
            'history-sync-type' => __('Sync Type', 'multi-store-sync-for-woocommerce'),
            'history-source' => __('Source', 'multi-store-sync-for-woocommerce'),
            'history-status' => __('Status', 'multi-store-sync-for-woocommerce'),
            'history-duration' => __('Duration', 'multi-store-sync-for-woocommerce'),
            'history-api-calls' => __('API Calls', 'multi-store-sync-for-woocommerce')
        ], $history_data['results'], static function (array $record): void {

            ?>
            <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($record['created_at']))); ?></td>
            <td>
                <strong><?php echo esc_html($record['product_name']); ?></strong><br/>
                <small>ID: <?php echo esc_html($record['product_id']); ?> | SKU: <?php echo esc_html($record['product_sku']); ?></small>
            </td>
            <td><?php echo esc_html(parse_url($record['store_url'], PHP_URL_HOST)); ?></td>
            <td><?php echo esc_html($record['sync_type']); ?></td>
            <td><?php echo esc_html($record['sync_source']); ?></td>
            <td>
                <?php if ($record['status'] === 'success'): ?>
                    <strong><?php esc_html_e('Success', 'multi-store-sync-for-woocommerce'); ?></strong>
                <?php else: ?>
                    <strong><?php esc_html_e('Error', 'multi-store-sync-for-woocommerce'); ?></strong><br/>
                    <small><?php echo esc_html($record['message']); ?></small>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html($record['duration_ms']); ?>ms</td>
            <td><?php echo esc_html($record['api_calls']); ?></td>
            <?php
        }, null, (int) $history_data['total']);
        $list_table->display();
        ?>


    <!-- Stats by Type -->
    <div>
        <h2><?php esc_html_e('Sync Performance by Type', 'multi-store-sync-for-woocommerce'); ?></h2>
        <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('history-types', [
            'history-types-sync-type' => __('Sync Type', 'multi-store-sync-for-woocommerce'),
            'history-types-total-syncs' => __('Total Syncs', 'multi-store-sync-for-woocommerce'),
            'history-types-successful' => __('Successful', 'multi-store-sync-for-woocommerce'),
            'history-types-avg-duration' => __('Avg Duration', 'multi-store-sync-for-woocommerce')
        ], $stats['by_type'], static function (array $type_stat): void {

            ?>
            <td><?php echo esc_html($type_stat['sync_type']); ?></td>
            <td><?php echo number_format($type_stat['count']); ?></td>
            <td><?php echo number_format($type_stat['successful']); ?></td>
            <td><?php echo esc_html(round($type_stat['avg_duration_ms'])); ?>ms</td>
            <?php
        });
        $list_table->display();
        ?>
    </div>

    <!-- Stats by Store -->
    <div>
        <h2><?php esc_html_e('Sync Performance by Store', 'multi-store-sync-for-woocommerce'); ?></h2>
        <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('history-stores', [
            'history-stores-store' => __('Store', 'multi-store-sync-for-woocommerce'),
            'history-stores-total-syncs' => __('Total Syncs', 'multi-store-sync-for-woocommerce'),
            'history-stores-successful' => __('Successful', 'multi-store-sync-for-woocommerce'),
            'history-stores-failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
            'history-stores-avg-duration' => __('Avg Duration', 'multi-store-sync-for-woocommerce')
        ], $stats['by_store'], static function (array $store_stat): void {

            ?>
            <td><?php echo esc_html(parse_url($store_stat['store_url'], PHP_URL_HOST)); ?></td>
            <td><?php echo number_format($store_stat['count']); ?></td>
            <td><?php echo number_format($store_stat['successful']); ?></td>
            <td><?php echo number_format($store_stat['failed']); ?></td>
            <td><?php echo esc_html(round($store_stat['avg_duration_ms'])); ?>ms</td>
            <?php
        });
        $list_table->display();
        ?>
    </div>
</div>
