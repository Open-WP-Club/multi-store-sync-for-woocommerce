<?php
/**
 * API Usage Dashboard View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get stores
$stores = WC_Multi_Store_Settings::get_active_stores();

// Get date range from request
$days = isset($_GET['days']) ? absint($_GET['days']) : 30;
$store_filter = isset($_GET['store_url']) ? sanitize_text_field($_GET['store_url']) : '';

$args = [
    'start_date' => date('Y-m-d', strtotime("-{$days} days")),
    'end_date' => date('Y-m-d'),
];

if ($store_filter) {
    $args['store_url'] = $store_filter;
}

// Get statistics
$stats = WC_Multi_Store_API_Usage_Tracker::get_statistics($args);
$usage_by_store = WC_Multi_Store_API_Usage_Tracker::get_usage_by_store($args);
$usage_by_endpoint = WC_Multi_Store_API_Usage_Tracker::get_usage_by_endpoint($args);
$daily_trend = WC_Multi_Store_API_Usage_Tracker::get_daily_trend(array_merge($args, ['days' => $days]));
$recent_errors = WC_Multi_Store_API_Usage_Tracker::get_recent_errors(10);
$cost_estimates = WC_Multi_Store_API_Usage_Tracker::get_cost_estimates($args);

?>

<div class="wrap wc-mss-api-usage">
    <h1><?php _e('API Usage Dashboard', 'wc-multi-store-sync'); ?></h1>

    <!-- Filters -->
    <div class="wc-mss-filters" style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
        <form method="get" action="">
            <input type="hidden" name="page" value="wc-settings">
            <input type="hidden" name="tab" value="multi_store_sync">
            <input type="hidden" name="section" value="api-usage">

            <label style="margin-right: 15px;">
                <?php _e('Time Period:', 'wc-multi-store-sync'); ?>
                <select name="days">
                    <option value="7" <?php selected($days, 7); ?>><?php _e('Last 7 days', 'wc-multi-store-sync'); ?></option>
                    <option value="30" <?php selected($days, 30); ?>><?php _e('Last 30 days', 'wc-multi-store-sync'); ?></option>
                    <option value="90" <?php selected($days, 90); ?>><?php _e('Last 90 days', 'wc-multi-store-sync'); ?></option>
                </select>
            </label>

            <label style="margin-right: 15px;">
                <?php _e('Store:', 'wc-multi-store-sync'); ?>
                <select name="store_url">
                    <option value=""><?php _e('All Stores', 'wc-multi-store-sync'); ?></option>
                    <?php foreach ($stores as $url => $config): ?>
                        <option value="<?php echo esc_attr($url); ?>" <?php selected($store_filter, $url); ?>>
                            <?php echo esc_html(isset($config['name']) ? $config['name'] : $url); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button type="submit" class="button"><?php _e('Apply Filters', 'wc-multi-store-sync'); ?></button>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=api-usage&action=export_csv&days=' . $days), 'wc_mss_export_api_usage'); ?>" class="button">
                <?php _e('Export CSV', 'wc-multi-store-sync'); ?>
            </a>
        </form>

        <?php if ($stats['total_requests'] > 0): ?>
        <form method="post" action="" style="display: inline; margin-left: 10px;">
            <?php wp_nonce_field('wc_mss_clear_api_usage'); ?>
            <button type="submit" name="wc_mss_clear_api_usage" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete all API usage data? This action cannot be undone.', 'wc-multi-store-sync'); ?>');">
                <?php _e('Clear All Data', 'wc-multi-store-sync'); ?>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Statistics Cards -->
    <div class="wc-mss-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 20px 0;">
        <div class="wc-mss-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php _e('Total Requests', 'wc-multi-store-sync'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo number_format($stats['total_requests']); ?></p>
        </div>

        <div class="wc-mss-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php _e('Success Rate', 'wc-multi-store-sync'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: <?php echo $stats['success_rate'] >= 95 ? '#46b450' : ($stats['success_rate'] >= 80 ? '#ffb900' : '#dc3545'); ?>;">
                <?php echo number_format($stats['success_rate'], 2); ?>%
            </p>
        </div>

        <div class="wc-mss-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php _e('Avg Response Time', 'wc-multi-store-sync'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo number_format($stats['avg_response_time']); ?> <span style="font-size: 16px;">ms</span></p>
        </div>

        <div class="wc-mss-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php _e('Data Transferred', 'wc-multi-store-sync'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #2271b1;">
                <?php echo size_format($stats['total_data_transferred'], 2); ?>
            </p>
        </div>
    </div>

    <!-- Daily Trend Chart -->
    <div class="wc-mss-card" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
        <h2><?php _e('Daily Request Trend', 'wc-multi-store-sync'); ?></h2>
        <canvas id="wc-mss-trend-chart" style="max-height: 300px;"></canvas>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- Usage by Store -->
        <div class="wc-mss-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h2><?php _e('Usage by Store', 'wc-multi-store-sync'); ?></h2>
            <?php if (!empty($usage_by_store)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Store', 'wc-multi-store-sync'); ?></th>
                            <th><?php _e('Requests', 'wc-multi-store-sync'); ?></th>
                            <th><?php _e('Success Rate', 'wc-multi-store-sync'); ?></th>
                            <th><?php _e('Avg Time', 'wc-multi-store-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usage_by_store as $store): ?>
                            <tr>
                                <td><?php echo esc_html($store['store_url']); ?></td>
                                <td><?php echo number_format($store['total_requests']); ?></td>
                                <td>
                                    <span style="color: <?php echo $store['success_rate'] >= 95 ? '#46b450' : ($store['success_rate'] >= 80 ? '#ffb900' : '#dc3545'); ?>;">
                                        <?php echo number_format($store['success_rate'], 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo number_format($store['avg_response_time']); ?> ms</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No data available.', 'wc-multi-store-sync'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Usage by Endpoint -->
        <div class="wc-mss-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h2><?php _e('Top Endpoints', 'wc-multi-store-sync'); ?></h2>
            <?php if (!empty($usage_by_endpoint)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Endpoint', 'wc-multi-store-sync'); ?></th>
                            <th><?php _e('Method', 'wc-multi-store-sync'); ?></th>
                            <th><?php _e('Requests', 'wc-multi-store-sync'); ?></th>
                            <th><?php _e('Avg Time', 'wc-multi-store-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usage_by_endpoint as $endpoint): ?>
                            <tr>
                                <td><code><?php echo esc_html($endpoint['endpoint']); ?></code></td>
                                <td><span class="wc-mss-method-<?php echo strtolower($endpoint['method']); ?>"><?php echo esc_html($endpoint['method']); ?></span></td>
                                <td><?php echo number_format($endpoint['total_requests']); ?></td>
                                <td><?php echo number_format($endpoint['avg_response_time']); ?> ms</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No data available.', 'wc-multi-store-sync'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Errors -->
    <?php if (!empty($recent_errors)): ?>
        <div class="wc-mss-card" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <h2><?php _e('Recent Errors', 'wc-multi-store-sync'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Time', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Store', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Endpoint', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Error', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_errors as $error): ?>
                        <tr>
                            <td><?php echo esc_html(date('M j, Y H:i', strtotime($error['created_at']))); ?></td>
                            <td><?php echo esc_html($error['store_url']); ?></td>
                            <td><code><?php echo esc_html($error['endpoint']); ?></code></td>
                            <td style="color: #d63638;"><?php echo esc_html($error['error_message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Cost Estimates (if configured) -->
    <?php if ($cost_estimates['cost_per_1000_requests'] > 0 || $cost_estimates['cost_per_gb_transferred'] > 0): ?>
        <div class="wc-mss-card" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <h2><?php _e('Cost Estimates', 'wc-multi-store-sync'); ?></h2>
            <table class="wp-list-table widefat fixed">
                <tr>
                    <td style="padding: 10px;"><strong><?php _e('Request Cost:', 'wc-multi-store-sync'); ?></strong></td>
                    <td style="padding: 10px;">$<?php echo number_format($cost_estimates['request_cost'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px;"><strong><?php _e('Data Transfer Cost:', 'wc-multi-store-sync'); ?></strong></td>
                    <td style="padding: 10px;">$<?php echo number_format($cost_estimates['data_transfer_cost'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; background: #f5f5f5;"><strong><?php _e('Total Estimated Cost:', 'wc-multi-store-sync'); ?></strong></td>
                    <td style="padding: 10px; background: #f5f5f5;"><strong>$<?php echo number_format($cost_estimates['total_estimated_cost'], 2); ?></strong></td>
                </tr>
            </table>
        </div>
    <?php endif; ?>
</div>
