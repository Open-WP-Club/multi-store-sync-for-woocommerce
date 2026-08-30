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

<div class="wrap wc-mss-dashboard">
    <h1><?php _e('WooCommerce Multi-Store Sync - Dashboard', 'wc-multi-store-sync'); ?></h1>

    <div class="wc-mss-dashboard-grid">
        <!-- System Status Card (includes Scheduler) -->
        <div class="wc-mss-card">
            <h2><?php _e('System Status', 'wc-multi-store-sync'); ?></h2>
            <table class="wc-mss-status-table">
                <tr>
                    <td><?php _e('Plugin Version', 'wc-multi-store-sync'); ?></td>
                    <td><strong><?php echo esc_html(WC_MSS_VERSION); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Sync Status', 'wc-multi-store-sync'); ?></td>
                    <td>
                        <?php if ($settings['enabled']): ?>
                            <span class="status-active"><span class="wc-mss-status-dot active"></span>Active</span>
                        <?php else: ?>
                            <span class="status-inactive"><span class="wc-mss-status-dot inactive"></span>Inactive</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('Stores', 'wc-multi-store-sync'); ?></td>
                    <td><strong><?php echo $active_count; ?></strong> / <?php echo count($stores); ?> <?php _e('active', 'wc-multi-store-sync'); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Queue Processor', 'wc-multi-store-sync'); ?></td>
                    <td>
                        <?php if ($scheduler_status['queue_processor']['is_scheduled']): ?>
                            <span class="status-active"><span class="wc-mss-status-dot active"></span></span>
                            <small><?php echo esc_html($scheduler_status['queue_processor']['next_run_relative']); ?></small>
                        <?php else: ?>
                            <span class="status-inactive"><span class="wc-mss-status-dot inactive"></span>Not Scheduled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('Scheduled Sync', 'wc-multi-store-sync'); ?></td>
                    <td>
                        <?php if ($scheduler_status['scheduled_sync']['is_scheduled']): ?>
                            <span class="status-active"><span class="wc-mss-status-dot active"></span></span>
                            <small><?php echo esc_html($scheduler_status['scheduled_sync']['next_run_relative']); ?></small>
                        <?php else: ?>
                            <span class="status-inactive"><span class="wc-mss-status-dot inactive"></span>Not Scheduled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($scheduler_status['scheduler_type'] === 'Action Scheduler'): ?>
                <tr>
                    <td><?php _e('Pending / Failed', 'wc-multi-store-sync'); ?></td>
                    <td>
                        <?php echo esc_html($scheduler_status['pending_actions']); ?> /
                        <?php if ($scheduler_status['failed_actions'] > 0): ?>
                            <span style="color: #dc3232;"><?php echo esc_html($scheduler_status['failed_actions']); ?></span>
                        <?php else: ?>
                            <span style="color: #46b450;">0</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <div style="margin-top: 10px;">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('wc_mss_reschedule_actions'); ?>
                    <button type="submit" name="wc_mss_reschedule_actions" class="button button-small">
                        <?php _e('Reschedule', 'wc-multi-store-sync'); ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="wc-mss-card">
            <h2><?php _e('Quick Actions', 'wc-multi-store-sync'); ?></h2>
            <div class="wc-mss-actions">
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=stores'); ?>" class="button button-primary">
                    <?php _e('Manage Stores', 'wc-multi-store-sync'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=settings'); ?>" class="button">
                    <?php _e('Settings', 'wc-multi-store-sync'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=queue'); ?>" class="button">
                    <?php _e('View Queue', 'wc-multi-store-sync'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=history'); ?>" class="button">
                    <?php _e('Sync History', 'wc-multi-store-sync'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=logs'); ?>" class="button">
                    <?php _e('View Logs', 'wc-multi-store-sync'); ?>
                </a>
                <br/><br/>
                <button type="button" id="wc-mss-force-sync-all" class="button button-large" style="background: #2271b1; color: #fff; border-color: #2271b1;">
                    <?php _e('Force Full Sync All Products', 'wc-multi-store-sync'); ?>
                </button>
                <p class="description" style="margin-top: 10px;">
                    <?php _e('Queues all products for immediate full sync to all active stores.', 'wc-multi-store-sync'); ?>
                </p>

                <div style="margin-top: 15px;">
                    <button type="button" id="wc-mss-start-verification" class="button button-large" style="background: #d63638; color: #fff; border-color: #d63638;">
                        <?php _e('Force Weekly Verification', 'wc-multi-store-sync'); ?>
                    </button>
                    <button type="button" id="wc-mss-cancel-verification" class="button button-large" style="display: none; margin-left: 10px;">
                        <?php _e('Cancel', 'wc-multi-store-sync'); ?>
                    </button>
                </div>
                <p class="description" style="margin-top: 10px;">
                    <?php _e('Runs stock discrepancy check between all stores in the background.', 'wc-multi-store-sync'); ?>
                </p>
                <div id="wc-mss-verification-progress" style="display: none; margin-top: 15px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span id="wc-mss-verification-status"><?php _e('Starting verification...', 'wc-multi-store-sync'); ?></span>
                        <span id="wc-mss-verification-percent">0%</span>
                    </div>
                    <div class="wc-mss-progress-container">
                        <div class="wc-mss-progress-bar" id="wc-mss-verification-bar" style="width: 0%;"></div>
                    </div>
                    <div style="margin-top: 10px; font-size: 12px; color: #646970;">
                        <span id="wc-mss-verification-details"></span>
                    </div>
                </div>
                <div id="wc-mss-verification-result" style="margin-top: 10px;"></div>
                <div id="wc-mss-force-sync-result" style="margin-top: 10px;"></div>

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var startBtn = document.getElementById('wc-mss-start-verification');
                    var cancelBtn = document.getElementById('wc-mss-cancel-verification');
                    var progressDiv = document.getElementById('wc-mss-verification-progress');
                    var resultDiv = document.getElementById('wc-mss-verification-result');
                    var statusSpan = document.getElementById('wc-mss-verification-status');
                    var percentSpan = document.getElementById('wc-mss-verification-percent');
                    var progressBar = document.getElementById('wc-mss-verification-bar');
                    var detailsSpan = document.getElementById('wc-mss-verification-details');
                    var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
                    var nonce = '<?php echo esc_js(wp_create_nonce('wc_mss_admin')); ?>';
                    var pollInterval = null;
                    var verificationJustStarted = false;
                    var idleCount = 0;

                    function showResult(message, isError) {
                        resultDiv.textContent = '';
                        var notice = document.createElement('div');
                        notice.className = 'notice inline ' + (isError ? 'notice-error' : 'notice-success');
                        var p = document.createElement('p');
                        p.textContent = message;
                        notice.appendChild(p);
                        resultDiv.appendChild(notice);
                    }

                    function startVerification() {
                        startBtn.disabled = true;
                        startBtn.textContent = '<?php echo esc_js(__('Starting...', 'wc-multi-store-sync')); ?>';
                        resultDiv.textContent = '';
                        verificationJustStarted = true;
                        idleCount = 0;

                        fetch(ajaxUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=wc_mss_start_verification&nonce=' + nonce
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                progressDiv.style.display = 'block';
                                cancelBtn.style.display = 'inline-block';
                                startBtn.style.display = 'none';
                                statusSpan.textContent = data.data.message;
                                setTimeout(function() {
                                    pollProgress();
                                    pollInterval = setInterval(pollProgress, 3000);
                                }, 1000);
                            } else {
                                verificationJustStarted = false;
                                startBtn.disabled = false;
                                startBtn.textContent = '<?php echo esc_js(__('Force Weekly Verification', 'wc-multi-store-sync')); ?>';
                                showResult(data.data.message || 'Error', true);
                            }
                        })
                        .catch(function(err) {
                            verificationJustStarted = false;
                            startBtn.disabled = false;
                            startBtn.textContent = '<?php echo esc_js(__('Force Weekly Verification', 'wc-multi-store-sync')); ?>';
                            showResult('Error: ' + err.message, true);
                        });
                    }

                    function pollProgress() {
                        fetch(ajaxUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=wc_mss_get_verification_progress&nonce=' + nonce
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success && data.data) {
                                var d = data.data;
                                if (d.status === 'running') {
                                    idleCount = 0;
                                    verificationJustStarted = false;
                                    percentSpan.textContent = d.percent + '%';
                                    progressBar.style.width = d.percent + '%';
                                    statusSpan.textContent = '<?php echo esc_js(__('Verifying products...', 'wc-multi-store-sync')); ?>';
                                    detailsSpan.textContent = d.processed + '/' + d.total + ' <?php echo esc_js(__('products', 'wc-multi-store-sync')); ?> | <?php echo esc_js(__('Batch', 'wc-multi-store-sync')); ?> ' + d.current_batch + '/' + d.total_batches + ' | <?php echo esc_js(__('Discrepancies', 'wc-multi-store-sync')); ?>: ' + d.discrepancies;
                                } else if (d.status === 'completed') {
                                    clearInterval(pollInterval);
                                    verificationJustStarted = false;
                                    percentSpan.textContent = '100%';
                                    progressBar.style.width = '100%';
                                    statusSpan.textContent = '<?php echo esc_js(__('Verification completed!', 'wc-multi-store-sync')); ?>';
                                    detailsSpan.textContent = d.processed + ' <?php echo esc_js(__('products checked', 'wc-multi-store-sync')); ?>, ' + d.discrepancies + ' <?php echo esc_js(__('discrepancies found', 'wc-multi-store-sync')); ?>';
                                    setTimeout(resetUI, 3000);
                                } else if (d.status === 'cancelled') {
                                    clearInterval(pollInterval);
                                    verificationJustStarted = false;
                                    statusSpan.textContent = '<?php echo esc_js(__('Verification cancelled', 'wc-multi-store-sync')); ?>';
                                    setTimeout(resetUI, 2000);
                                } else if (d.status === 'idle') {
                                    idleCount++;
                                    if (verificationJustStarted && idleCount < 5) {
                                        statusSpan.textContent = '<?php echo esc_js(__('Waiting for background process...', 'wc-multi-store-sync')); ?>';
                                    } else if (idleCount >= 5) {
                                        clearInterval(pollInterval);
                                        verificationJustStarted = false;
                                        resetUI();
                                        showResult('<?php echo esc_js(__('Verification may have completed or failed. Check Action Scheduler.', 'wc-multi-store-sync')); ?>', true);
                                    }
                                }
                            }
                        });
                    }

                    function cancelVerification() {
                        cancelBtn.disabled = true;
                        fetch(ajaxUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=wc_mss_cancel_verification&nonce=' + nonce
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            clearInterval(pollInterval);
                            statusSpan.textContent = '<?php echo esc_js(__('Cancelling...', 'wc-multi-store-sync')); ?>';
                        });
                    }

                    function resetUI() {
                        progressDiv.style.display = 'none';
                        cancelBtn.style.display = 'none';
                        cancelBtn.disabled = false;
                        startBtn.style.display = 'inline-block';
                        startBtn.disabled = false;
                        startBtn.textContent = '<?php echo esc_js(__('Force Weekly Verification', 'wc-multi-store-sync')); ?>';
                        percentSpan.textContent = '0%';
                        progressBar.style.width = '0%';
                        detailsSpan.textContent = '';
                    }

                    startBtn.addEventListener('click', startVerification);
                    cancelBtn.addEventListener('click', cancelVerification);

                    // Check if verification is already running on page load
                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wc_mss_get_verification_progress&nonce=' + nonce
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.data && data.data.status === 'running') {
                            progressDiv.style.display = 'block';
                            cancelBtn.style.display = 'inline-block';
                            startBtn.style.display = 'none';
                            pollProgress();
                            pollInterval = setInterval(pollProgress, 3000);
                        }
                    });
                });
                </script>
            </div>
        </div>

        <!-- Sync Statistics Card -->
        <div class="wc-mss-card wc-mss-full-width">
            <h2>
                <?php _e('Sync Activity (Last 7 Days)', 'wc-multi-store-sync'); ?>
                <select id="wc-mss-chart-days" style="float: right; margin-top: -3px;">
                    <option value="7"><?php _e('Last 7 days', 'wc-multi-store-sync'); ?></option>
                    <option value="14"><?php _e('Last 14 days', 'wc-multi-store-sync'); ?></option>
                    <option value="30"><?php _e('Last 30 days', 'wc-multi-store-sync'); ?></option>
                </select>
            </h2>
            <?php
            $sync_stats = WC_Multi_Store_Sync_History::get_statistics(['days' => 7]);
            $overall = $sync_stats['overall'];
            $has_syncs = (int) ($overall['total_syncs'] ?? 0) > 0;
            $success_rate = $overall['success_rate'] ?? 0;
            $success_rate_color = $has_syncs
                ? ($success_rate >= 90 ? '#00a32a' : '#d63638')
                : '#646970';
            ?>
            <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                <div style="text-align: center; flex: 1; padding: 10px; background: #f6f7f7; border-radius: 4px;">
                    <div style="font-size: 28px; font-weight: 600;"><?php echo (int) ($overall['total_syncs'] ?? 0); ?></div>
                    <div style="font-size: 11px; color: #646970; text-transform: uppercase;"><?php _e('Total Syncs', 'wc-multi-store-sync'); ?></div>
                </div>
                <div style="text-align: center; flex: 1; padding: 10px; background: #f6f7f7; border-radius: 4px;">
                    <div style="font-size: 28px; font-weight: 600; color: #00a32a;"><?php echo (int) ($overall['successful_syncs'] ?? 0); ?></div>
                    <div style="font-size: 11px; color: #646970; text-transform: uppercase;"><?php _e('Successful', 'wc-multi-store-sync'); ?></div>
                </div>
                <div style="text-align: center; flex: 1; padding: 10px; background: #f6f7f7; border-radius: 4px;">
                    <div style="font-size: 28px; font-weight: 600; color: <?php echo ($overall['failed_syncs'] ?? 0) > 0 ? '#d63638' : '#646970'; ?>;"><?php echo (int) ($overall['failed_syncs'] ?? 0); ?></div>
                    <div style="font-size: 11px; color: #646970; text-transform: uppercase;"><?php _e('Failed', 'wc-multi-store-sync'); ?></div>
                </div>
                <div style="text-align: center; flex: 1; padding: 10px; background: #f6f7f7; border-radius: 4px;">
                    <div style="font-size: 28px; font-weight: 600; color: <?php echo $success_rate_color; ?>;"><?php echo $has_syncs ? esc_html($success_rate) . '%' : '&mdash;'; ?></div>
                    <div style="font-size: 11px; color: #646970; text-transform: uppercase;"><?php _e('Success Rate', 'wc-multi-store-sync'); ?></div>
                </div>
                <div style="text-align: center; flex: 1; padding: 10px; background: #f6f7f7; border-radius: 4px;">
                    <div style="font-size: 28px; font-weight: 600;"><?php echo round($overall['avg_duration_ms'] ?? 0); ?><span style="font-size: 14px;">ms</span></div>
                    <div style="font-size: 11px; color: #646970; text-transform: uppercase;"><?php _e('Avg Duration', 'wc-multi-store-sync'); ?></div>
                </div>
            </div>

            <!-- Chart Container -->
            <div id="wc-mss-chart-container" style="position: relative; height: 200px; background: #f9f9f9; border-radius: 4px; padding: 10px;">
                <canvas id="wc-mss-sync-chart" style="width: 100%; height: 100%;"></canvas>
            </div>

            <!-- Dead Letter Queue Alert -->
            <?php
            $dlq_stats = WC_Multi_Store_Dead_Letter_Queue::get_stats();
            if ($dlq_stats['total_dead'] > 0):
            ?>
            <div style="margin-top: 15px; padding: 10px 15px; background: #fcf0f1; border-left: 4px solid #d63638; border-radius: 2px;">
                <strong><?php echo sprintf(__('%d item(s) in the Dead Letter Queue', 'wc-multi-store-sync'), $dlq_stats['total_dead']); ?></strong>
                — <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=dead-letter-queue'); ?>"><?php _e('Review failed items', 'wc-multi-store-sync'); ?></a>
            </div>
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var nonce = '<?php echo esc_js(wp_create_nonce('wc_mss_admin')); ?>';
            var syncChart = null;

            function loadChart(days) {
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=wc_mss_dashboard_stats&nonce=' + nonce + '&days=' + days
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) return;
                    renderChart(data.data.chart);
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
                                label: '<?php echo esc_js(__('Success', 'wc-multi-store-sync')); ?>',
                                data: chartData.success,
                                backgroundColor: '#00a32a'
                            },
                            {
                                label: '<?php echo esc_js(__('Failed', 'wc-multi-store-sync')); ?>',
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
        <div class="wc-mss-card wc-mss-full-width">
            <h2><?php _e('Current Configuration', 'wc-multi-store-sync'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th><?php _e('Default Sync Type:', 'wc-multi-store-sync'); ?></th>
                        <td><?php echo esc_html($settings['sync_type_default']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Authentication Method:', 'wc-multi-store-sync'); ?></th>
                        <td><?php echo esc_html($settings['auth_method']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Match Products By:', 'wc-multi-store-sync'); ?></th>
                        <td><?php echo esc_html($settings['match_products_by']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Stock Sync Enabled:', 'wc-multi-store-sync'); ?></th>
                        <td><?php echo $settings['stock_sync_enabled'] ? __('Yes', 'wc-multi-store-sync') : __('No', 'wc-multi-store-sync'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Stores List Card -->
        <?php if (!empty($stores)): ?>
        <div class="wc-mss-card wc-mss-full-width">
            <h2><?php _e('Configured Stores', 'wc-multi-store-sync'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Store URL', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Status', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Added Date', 'wc-multi-store-sync'); ?></th>
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
                                echo '<span class="status-active"><span class="wc-mss-status-dot active"></span>Active</span>';
                            } else {
                                echo '<span class="status-inactive"><span class="wc-mss-status-dot inactive"></span>Inactive</span>';
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
        <div class="wc-mss-card wc-mss-full-width">
            <div class="wc-mss-empty-state">
                <p><?php _e('No stores configured yet.', 'wc-multi-store-sync'); ?></p>
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=stores'); ?>" class="button button-primary">
                    <?php _e('Add Your First Store', 'wc-multi-store-sync'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
