<?php
/**
 * Dashboard View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Calculate active stores count
$active_count = 0;
foreach ($stores as $store) {
    if (isset($store['status']) && $store['status'] === 'active') {
        $active_count++;
    }
}
?>

<div class="wrap wc-mss-dashboard wc-mss-page">
    <h1><?php esc_html_e('WooCommerce Multi-Store Sync - Dashboard', 'multi-store-sync-for-woocommerce'); ?></h1>

    <div class="wc-mss-dashboard-grid">
        <!-- System Status Card (includes Scheduler) -->
        <div class="card wc-mss-card">
            <h2><?php esc_html_e('System Status', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="widefat striped">
                <tr>
                    <td><?php esc_html_e('Plugin Version', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td><strong><?php echo esc_html(WC_MSS_VERSION); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Sync Status', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td>
                        <?php if ($settings['enabled']): ?>
                            <?php esc_html_e('Active', 'multi-store-sync-for-woocommerce'); ?>
                        <?php else: ?>
                            <?php esc_html_e('Inactive', 'multi-store-sync-for-woocommerce'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Stores', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td><strong><?php echo esc_html($active_count); ?></strong> / <?php echo esc_html(count($stores)); ?> <?php esc_html_e('active', 'multi-store-sync-for-woocommerce'); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Queue Processor', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td>
                        <?php if ($scheduler_status['queue_processor']['is_scheduled']): ?>

                            <small><?php echo esc_html($scheduler_status['queue_processor']['next_run_relative']); ?></small>
                        <?php else: ?>
                            <?php esc_html_e('Not Scheduled', 'multi-store-sync-for-woocommerce'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Scheduled Sync', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td>
                        <?php if ($scheduler_status['scheduled_sync']['is_scheduled']): ?>

                            <small><?php echo esc_html($scheduler_status['scheduled_sync']['next_run_relative']); ?></small>
                        <?php else: ?>
                            <?php esc_html_e('Not Scheduled', 'multi-store-sync-for-woocommerce'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($scheduler_status['scheduler_type'] === 'Action Scheduler'): ?>
                <tr>
                    <td><?php esc_html_e('Pending / Failed', 'multi-store-sync-for-woocommerce'); ?></td>
                    <td>
                        <?php echo esc_html($scheduler_status['pending_actions']); ?> /
                        <?php if ($scheduler_status['failed_actions'] > 0): ?>
                            <span><?php echo esc_html($scheduler_status['failed_actions']); ?></span>
                        <?php else: ?>
                            <span>0</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <div>
                <form method="post">
                    <?php wp_nonce_field('wc_mss_reschedule_actions'); ?>
                    <button type="submit" name="wc_mss_reschedule_actions" class="button button-small">
                        <?php esc_html_e('Reschedule', 'multi-store-sync-for-woocommerce'); ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="card wc-mss-card">
            <h2><?php esc_html_e('Quick Actions', 'multi-store-sync-for-woocommerce'); ?></h2>
            <div class="wc-mss-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=stores')); ?>" class="button button-primary">
                    <?php esc_html_e('Manage Stores', 'multi-store-sync-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=settings')); ?>" class="button">
                    <?php esc_html_e('Settings', 'multi-store-sync-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=queue')); ?>" class="button">
                    <?php esc_html_e('View Queue', 'multi-store-sync-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=history')); ?>" class="button">
                    <?php esc_html_e('Sync History', 'multi-store-sync-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=logs')); ?>" class="button">
                    <?php esc_html_e('View Logs', 'multi-store-sync-for-woocommerce'); ?>
                </a>
                <br/><br/>
                <button type="button" id="wc-mss-force-sync-all" class="button button-primary button-large">
                    <?php esc_html_e('Force Full Sync All Products', 'multi-store-sync-for-woocommerce'); ?>
                </button>
                <p class="description">
                    <?php esc_html_e('Queues all products for immediate full sync to all active stores.', 'multi-store-sync-for-woocommerce'); ?>
                </p>

                <div>
                    <button type="button" id="wc-mss-start-verification" class="button button-secondary button-large">
                        <?php esc_html_e('Force Weekly Verification', 'multi-store-sync-for-woocommerce'); ?>
                    </button>
                    <button type="button" id="wc-mss-cancel-verification" class="button button-large" hidden>
                        <?php esc_html_e('Cancel', 'multi-store-sync-for-woocommerce'); ?>
                    </button>
                </div>
                <p class="description">
                    <?php esc_html_e('Runs stock discrepancy check between all stores in the background.', 'multi-store-sync-for-woocommerce'); ?>
                </p>
                <div id="wc-mss-verification-progress" class="notice notice-info inline" role="status" hidden>
                    <p><span id="wc-mss-verification-status"></span> <span id="wc-mss-verification-percent">0%</span></p>
                    <progress id="wc-mss-verification-bar" max="100" value="0"><?php esc_html_e('Verification progress', 'multi-store-sync-for-woocommerce'); ?></progress>
                    <p id="wc-mss-verification-details"></p>
                </div>
                <div id="wc-mss-verification-result" aria-live="polite"></div>
                <div id="wc-mss-force-sync-result"></div>

            </div>
        </div>

        <!-- Sync Statistics Card -->
        <div class="card wc-mss-card wc-mss-full-width">
            <h2>
                <?php esc_html_e('Sync Activity (Last 7 Days)', 'multi-store-sync-for-woocommerce'); ?>
                <select id="wc-mss-chart-days">
                    <option value="7"><?php esc_html_e('Last 7 days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="14"><?php esc_html_e('Last 14 days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="30"><?php esc_html_e('Last 30 days', 'multi-store-sync-for-woocommerce'); ?></option>
                </select>
            </h2>
            <?php
            $sync_stats = WC_Multi_Store_Sync_History::get_statistics(['days' => 7]);
            $overall = $sync_stats['overall'];
            $has_syncs = (int) ($overall['total_syncs'] ?? 0) > 0;
            $success_rate = $overall['success_rate'] ?? 0;
            ?>
            <table class="widefat striped">
                <tbody>
                    <tr><th scope="row"><?php esc_html_e('Total Syncs', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html((int) ($overall['total_syncs'] ?? 0)); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Successful', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html((int) ($overall['successful_syncs'] ?? 0)); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Failed', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html((int) ($overall['failed_syncs'] ?? 0)); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Success Rate', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo $has_syncs ? esc_html($success_rate) . '%' : '&mdash;'; ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Avg Duration (ms)', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html(round($overall['avg_duration_ms'] ?? 0)); ?></td></tr>
                </tbody>
            </table>

            <!-- Chart Container -->
            <div id="wc-mss-chart-container">
                <canvas id="wc-mss-sync-chart"></canvas>
            </div>

            <!-- Duplicate SKU warning -->
            <?php $sku_conflicts = WC_Multi_Store_Sku_Conflicts::get_all(); ?>
            <?php if ($sku_conflicts): ?>
            <div class="notice notice-warning inline">
                <p><strong><?php /* translators: %d: number of products blocked by a duplicate SKU. */ echo sprintf(esc_html__('%d product(s) cannot sync because their SKU is already used on the remote store', 'multi-store-sync-for-woocommerce'), absint(count($sku_conflicts))); ?></strong>
                — <?php esc_html_e('Fix or remove the duplicate product on the remote store; retrying will not help.', 'multi-store-sync-for-woocommerce'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach (array_slice(array_reverse($sku_conflicts), 0, 10) as $sku_conflict): ?>
                    <li>
                        <?php
                        $conflict_product = wc_get_product((int) $sku_conflict['product_id']);
                        $conflict_title = $conflict_product ? $conflict_product->get_name() : '#' . (int) $sku_conflict['product_id'];
                        $conflict_edit = get_edit_post_link((int) $sku_conflict['product_id']);
                        ?>
                        <strong><?php echo esc_html($conflict_title); ?></strong>
                        <?php if ($conflict_edit): ?>(<a href="<?php echo esc_url($conflict_edit); ?>"><?php esc_html_e('edit', 'multi-store-sync-for-woocommerce'); ?></a>)<?php endif; ?>
                        — SKU <code><?php echo esc_html($sku_conflict['sku']); ?></code>
                        — <?php echo esc_html($sku_conflict['store_url']); ?>
                        <br>
                        <?php if ($sku_conflict['remote_id'] > 0): ?>
                            <a href="<?php echo esc_url(WC_Multi_Store_Sku_Conflicts::remote_edit_url($sku_conflict['store_url'], (int) $sku_conflict['remote_id'])); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: %d: remote product ID that could not be updated. */ echo sprintf(esc_html__('Remote product #%d (cannot be saved)', 'multi-store-sync-for-woocommerce'), absint($sku_conflict['remote_id'])); ?></a>
                        <?php endif; ?>
                        <?php if ($sku_conflict['owner_remote_id'] > 0): ?>
                            &middot; <a href="<?php echo esc_url(WC_Multi_Store_Sku_Conflicts::remote_edit_url($sku_conflict['store_url'], (int) $sku_conflict['owner_remote_id'])); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: %d: remote product ID that already holds the SKU. */ echo sprintf(esc_html__('Remote product #%d already has this SKU', 'multi-store-sync-for-woocommerce'), absint($sku_conflict['owner_remote_id'])); ?></a>
                            <?php if (!empty($sku_conflict['owner_name'])): ?>(<?php echo esc_html($sku_conflict['owner_name']); ?>)<?php endif; ?>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Dead Letter Queue Alert -->
            <?php
            $dlq_stats = WC_Multi_Store_Dead_Letter_Queue::get_stats();
            if ($dlq_stats['total_dead'] > 0):
            ?>
            <div class="notice notice-error inline"><p>
                <strong><?php /* translators: %d: number of items in the Dead Letter Queue. */ echo sprintf(esc_html__('%d item(s) in the Dead Letter Queue', 'multi-store-sync-for-woocommerce'), absint($dlq_stats['total_dead'])); ?></strong>
                — <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=dead-letter-queue')); ?>"><?php esc_html_e('Review failed items', 'multi-store-sync-for-woocommerce'); ?></a>
            </p></div>
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var syncChart = null;

            function loadChart(days) {
                window.wcMssRequest('wc_mss_dashboard_stats', {days: days})
                    .then(function(data) { renderChart(data.chart); })
                    .catch(function(error) {
                        window.wcMssNotice(document.getElementById('wc-mss-force-sync-result'), error.message, 'error');
                    });
            }

            function renderChart(chartData) {
                var canvas = document.getElementById('wc-mss-sync-chart');
                if (typeof Chart === 'undefined') return;

                if (syncChart) {
                    syncChart.data.labels = chartData.labels;
                    syncChart.data.datasets[0].data = chartData.success;
                    syncChart.data.datasets[1].data = chartData.failed;
                    syncChart.update();
                    return;
                }

                syncChart = new Chart(canvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: '<?php echo esc_js(__('Success', 'multi-store-sync-for-woocommerce')); ?>',
                                data: chartData.success,
                                backgroundColor: '#00a32a'
                            },
                            {
                                label: '<?php echo esc_js(__('Failed', 'multi-store-sync-for-woocommerce')); ?>',
                                data: chartData.failed,
                                backgroundColor: '#d63638'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'top' } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            }

            // Initial load
            loadChart(7);

            // Period selector
            document.getElementById('wc-mss-chart-days').addEventListener('change', function() {
                loadChart(this.value);
            });
        });
        </script>

        <!-- Configuration Summary Card -->
        <div class="card wc-mss-card wc-mss-full-width">
            <h2><?php esc_html_e('Current Configuration', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Default Sync Type:', 'multi-store-sync-for-woocommerce'); ?></th>
                        <td><?php echo esc_html($settings['sync_type_default']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Authentication Method:', 'multi-store-sync-for-woocommerce'); ?></th>
                        <td><?php echo esc_html($settings['auth_method']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Match Products By:', 'multi-store-sync-for-woocommerce'); ?></th>
                        <td><?php echo esc_html($settings['match_products_by']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Stock Sync Enabled:', 'multi-store-sync-for-woocommerce'); ?></th>
                        <td><?php echo esc_html($settings['stock_sync_enabled'] ? __('Yes', 'multi-store-sync-for-woocommerce') : __('No', 'multi-store-sync-for-woocommerce')); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Stores List Card -->
        <?php if (!empty($stores)): ?>
        <div class="card wc-mss-card wc-mss-full-width">
            <h2><?php esc_html_e('Configured Stores', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Store URL', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Status', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Added Date', 'multi-store-sync-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stores as $url => $store): ?>
                    <tr>
                        <td><?php echo esc_html($url); ?></td>
                        <td>
                            <?php
                            $status = isset($store['status']) ? $store['status'] : 'inactive';
                            if ($status === 'active') {
                                esc_html_e('Active', 'multi-store-sync-for-woocommerce');
                            } else {
                                esc_html_e('Inactive', 'multi-store-sync-for-woocommerce');
                            }
                            ?>
                        </td>
                        <td><?php echo isset($store['added_date']) ? esc_html($store['added_date']) : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="card wc-mss-card wc-mss-full-width">
            <div class="wc-mss-empty-state">
                <p><?php esc_html_e('No stores configured yet.', 'multi-store-sync-for-woocommerce'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=stores')); ?>" class="button button-primary">
                    <?php esc_html_e('Add Your First Store', 'multi-store-sync-for-woocommerce'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
