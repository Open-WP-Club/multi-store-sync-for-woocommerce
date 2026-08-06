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

// Get filter parameters - check both direct and urldecoded values
$filter_store = isset($_GET['filter_store']) ? sanitize_text_field(urldecode($_GET['filter_store'])) : '';
$filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
$filter_days = isset($_GET['filter_days']) ? absint($_GET['filter_days']) : 7;
$page_num = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
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

// Calculate pagination
$total_pages = ceil($history_data['total'] / $per_page);
?>

<?php
// History management data
$total_records = WC_Multi_Store_Sync_History::get_count();
$error_records = WC_Multi_Store_Sync_History::get_count('error');
$success_records = WC_Multi_Store_Sync_History::get_count('success');
$table_size = WC_Multi_Store_Sync_History::get_table_size();
$table_size_formatted = size_format($table_size, 2);
?>

<div class="wrap">
    <h1><?php _e('Sync History & Statistics', 'wc-multi-store-sync'); ?></h1>

    <!-- Statistics Overview with integrated History Management -->
    <div class="wc-mss-stats-overview" style="margin: 20px 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <h2 style="margin: 0;"><?php printf(__('Statistics (Last %d Days)', 'wc-multi-store-sync'), $filter_days); ?></h2>
            <div style="font-size: 13px; color: #666;">
                <span id="wc-mss-total-records"><?php echo number_format($total_records); ?></span> <?php _e('records', 'wc-multi-store-sync'); ?>
                (<span style="color: #46b450;"><?php echo number_format($success_records); ?></span> /
                <span style="color: #dc3232;"><?php echo number_format($error_records); ?></span>)
                &bull; <?php echo esc_html($table_size_formatted); ?>
                <button type="button" id="wc-mss-toggle-cleanup" class="button button-small" style="margin-left: 10px;">
                    <?php _e('Cleanup', 'wc-multi-store-sync'); ?>
                </button>
            </div>
        </div>

        <!-- Compact Cleanup Controls (hidden by default) -->
        <div id="wc-mss-cleanup-controls" style="display: none; margin-top: 15px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <select id="wc-mss-delete-type" style="min-width: 200px;">
                    <option value=""><?php _e('-- Select action --', 'wc-multi-store-sync'); ?></option>
                    <option value="errors"><?php _e('Delete error records only', 'wc-multi-store-sync'); ?></option>
                    <option value="successful"><?php _e('Delete successful records only', 'wc-multi-store-sync'); ?></option>
                    <option value="older_than"><?php _e('Delete records older than...', 'wc-multi-store-sync'); ?></option>
                    <?php if (!empty($stores)): ?>
                    <option value="by_store"><?php _e('Delete records for store...', 'wc-multi-store-sync'); ?></option>
                    <?php endif; ?>
                    <option value="all" style="color: #dc3232;"><?php _e('Delete ALL records', 'wc-multi-store-sync'); ?></option>
                </select>
                <select id="wc-mss-delete-days" style="display: none;">
                    <option value="7">7 <?php _e('days', 'wc-multi-store-sync'); ?></option>
                    <option value="14">14 <?php _e('days', 'wc-multi-store-sync'); ?></option>
                    <option value="30" selected>30 <?php _e('days', 'wc-multi-store-sync'); ?></option>
                    <option value="60">60 <?php _e('days', 'wc-multi-store-sync'); ?></option>
                    <option value="90">90 <?php _e('days', 'wc-multi-store-sync'); ?></option>
                </select>
                <select id="wc-mss-delete-store" style="display: none;">
                    <?php foreach ($stores as $url => $config): ?>
                        <option value="<?php echo esc_attr($url); ?>"><?php echo esc_html($url); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="wc-mss-delete-history" class="button" disabled>
                    <?php _e('Delete', 'wc-multi-store-sync'); ?>
                </button>
                <span id="wc-mss-delete-status" style="display: none;"></span>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
            <div class="wc-mss-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccc; border-radius: 4px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;"><?php _e('Total Syncs', 'wc-multi-store-sync'); ?></div>
                <div style="font-size: 28px; font-weight: bold;"><?php echo number_format($stats['overall']['total_syncs'] ?? 0); ?></div>
            </div>
            <div class="wc-mss-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccc; border-radius: 4px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;"><?php _e('Success Rate', 'wc-multi-store-sync'); ?></div>
                <div style="font-size: 28px; font-weight: bold; color: #46b450;"><?php echo $stats['overall']['success_rate'] ?? 0; ?>%</div>
            </div>
            <div class="wc-mss-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccc; border-radius: 4px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;"><?php _e('Avg Duration', 'wc-multi-store-sync'); ?></div>
                <div style="font-size: 28px; font-weight: bold;"><?php echo round($stats['overall']['avg_duration_ms'] ?? 0); ?>ms</div>
            </div>
            <div class="wc-mss-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccc; border-radius: 4px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;"><?php _e('API Calls', 'wc-multi-store-sync'); ?></div>
                <div style="font-size: 28px; font-weight: bold;"><?php echo number_format($stats['overall']['total_api_calls'] ?? 0); ?></div>
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
    <div class="wc-mss-filters" style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ccc;">
        <label><?php _e('Store:', 'wc-multi-store-sync'); ?></label>
        <select id="wc-mss-filter-store">
            <option value=""><?php _e('All Stores', 'wc-multi-store-sync'); ?></option>
            <?php foreach ($stores as $url => $config): ?>
                <option value="<?php echo esc_attr($url); ?>" <?php selected($filter_store, $url); ?>>
                    <?php echo esc_html($url); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label style="margin-left: 15px;"><?php _e('Status:', 'wc-multi-store-sync'); ?></label>
        <select id="wc-mss-filter-status">
            <option value=""><?php _e('All Statuses', 'wc-multi-store-sync'); ?></option>
            <option value="success" <?php selected($filter_status, 'success'); ?>><?php _e('Success', 'wc-multi-store-sync'); ?></option>
            <option value="error" <?php selected($filter_status, 'error'); ?>><?php _e('Error', 'wc-multi-store-sync'); ?></option>
        </select>

        <label style="margin-left: 15px;"><?php _e('Stats Period:', 'wc-multi-store-sync'); ?></label>
        <select id="wc-mss-filter-days">
            <option value="1" <?php selected($filter_days, 1); ?>>1 <?php _e('Day', 'wc-multi-store-sync'); ?></option>
            <option value="7" <?php selected($filter_days, 7); ?>>7 <?php _e('Days', 'wc-multi-store-sync'); ?></option>
            <option value="30" <?php selected($filter_days, 30); ?>>30 <?php _e('Days', 'wc-multi-store-sync'); ?></option>
            <option value="90" <?php selected($filter_days, 90); ?>>90 <?php _e('Days', 'wc-multi-store-sync'); ?></option>
        </select>

        <button type="button" id="wc-mss-apply-filters" class="button" style="margin-left: 15px;">
            <?php _e('Filter', 'wc-multi-store-sync'); ?>
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
            toggleBtn.textContent = isHidden ? '<?php echo esc_js(__('Hide', 'wc-multi-store-sync')); ?>' : '<?php echo esc_js(__('Cleanup', 'wc-multi-store-sync')); ?>';
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

            var confirmMsg = '<?php echo esc_js(__('Are you sure you want to delete these history records?', 'wc-multi-store-sync')); ?>';
            if (type === 'all') {
                confirmMsg = '<?php echo esc_js(__('WARNING: This will delete ALL history records!', 'wc-multi-store-sync')); ?>';
            }
            if (!confirm(confirmMsg)) return;

            deleteBtn.disabled = true;
            statusSpan.style.display = 'inline';
            statusSpan.style.color = '#666';
            statusSpan.textContent = '<?php echo esc_js(__('Deleting...', 'wc-multi-store-sync')); ?>';

            var formData = new FormData();
            formData.append('action', 'wc_mss_delete_history');
            formData.append('nonce', '<?php echo wp_create_nonce('wc_mss_admin'); ?>');
            formData.append('delete_type', type);

            if (type === 'older_than') {
                formData.append('days', daysSelect.value);
            } else if (type === 'by_store') {
                formData.append('store_url', storeSelect.value);
            }

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(response) {
                if (response.success) {
                    statusSpan.style.color = '#46b450';
                    statusSpan.textContent = response.data.message;
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    statusSpan.style.color = '#dc3232';
                    statusSpan.textContent = (response.data && response.data.message) || '<?php echo esc_js(__('Error', 'wc-multi-store-sync')); ?>';
                    deleteBtn.disabled = false;
                }
            })
            .catch(function() {
                statusSpan.style.color = '#dc3232';
                statusSpan.textContent = '<?php echo esc_js(__('Request failed', 'wc-multi-store-sync')); ?>';
                deleteBtn.disabled = false;
            });
        });
    });
    </script>

    <!-- History Table -->
    <h2><?php _e('Recent Sync History', 'wc-multi-store-sync'); ?></h2>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Date/Time', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Product', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Store', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Sync Type', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Source', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Status', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('Duration', 'wc-multi-store-sync'); ?></th>
                <th><?php _e('API Calls', 'wc-multi-store-sync'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($history_data['results'])): ?>
                <?php foreach ($history_data['results'] as $record): ?>
                    <tr>
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
                                <span style="color: #46b450; font-weight: bold;">✓ <?php _e('Success', 'wc-multi-store-sync'); ?></span>
                            <?php else: ?>
                                <span style="color: #dc3232; font-weight: bold;">✗ <?php _e('Error', 'wc-multi-store-sync'); ?></span><br/>
                                <small><?php echo esc_html($record['message']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($record['duration_ms']); ?>ms</td>
                        <td><?php echo esc_html($record['api_calls']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 30px;">
                        <?php _e('No sync history found.', 'wc-multi-store-sync'); ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="wc-mss-pagination">
            <div class="pagination-info">
                <?php
                $start_item = (($page_num - 1) * $per_page) + 1;
                $end_item = min($page_num * $per_page, $history_data['total']);
                printf(
                    __('Showing <strong>%d-%d</strong> of <strong>%s</strong> records', 'wc-multi-store-sync'),
                    $start_item,
                    $end_item,
                    number_format($history_data['total'])
                );
                ?>
            </div>
            <div class="pagination-links">
                <?php
                // Build base URL with all current parameters
                $base_url = add_query_arg([
                    'page' => 'wc-settings',
                    'tab' => 'multi_store_sync',
                    'section' => 'history',
                    'filter_store' => $filter_store,
                    'filter_status' => $filter_status,
                    'filter_days' => $filter_days,
                ], admin_url('admin.php'));

                $page_links = paginate_links([
                    'base' => add_query_arg('paged', '%#%', $base_url),
                    'format' => '',
                    'prev_text' => '← ' . __('Previous', 'wc-multi-store-sync'),
                    'next_text' => __('Next', 'wc-multi-store-sync') . ' →',
                    'total' => $total_pages,
                    'current' => $page_num,
                    'show_all' => false,
                    'end_size' => 1,
                    'mid_size' => 2,
                ]);

                if ($page_links) {
                    echo $page_links;
                }
                ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Stats by Type -->
    <div style="margin-top: 30px;">
        <h2><?php _e('Sync Performance by Type', 'wc-multi-store-sync'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Sync Type', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Total Syncs', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Successful', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Avg Duration', 'wc-multi-store-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($stats['by_type'])): ?>
                    <?php foreach ($stats['by_type'] as $type_stat): ?>
                        <tr>
                            <td><?php echo esc_html($type_stat['sync_type']); ?></td>
                            <td><?php echo number_format($type_stat['count']); ?></td>
                            <td><?php echo number_format($type_stat['successful']); ?></td>
                            <td><?php echo round($type_stat['avg_duration_ms']); ?>ms</td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center;"><?php _e('No data available', 'wc-multi-store-sync'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Stats by Store -->
    <div style="margin-top: 30px;">
        <h2><?php _e('Sync Performance by Store', 'wc-multi-store-sync'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Store', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Total Syncs', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Successful', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Failed', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Avg Duration', 'wc-multi-store-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($stats['by_store'])): ?>
                    <?php foreach ($stats['by_store'] as $store_stat): ?>
                        <tr>
                            <td><?php echo esc_html(parse_url($store_stat['store_url'], PHP_URL_HOST)); ?></td>
                            <td><?php echo number_format($store_stat['count']); ?></td>
                            <td style="color: #46b450;"><?php echo number_format($store_stat['successful']); ?></td>
                            <td style="color: #dc3232;"><?php echo number_format($store_stat['failed']); ?></td>
                            <td><?php echo round($store_stat['avg_duration_ms']); ?>ms</td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center;"><?php _e('No data available', 'wc-multi-store-sync'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
