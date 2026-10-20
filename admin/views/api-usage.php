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

// Read-only GET filters; they only select dashboard data and do not change state.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard filter.
$days = isset($_GET['days']) ? absint(wp_unslash($_GET['days'])) : 30;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard filter.
$store_filter = isset($_GET['store_url']) ? sanitize_text_field(wp_unslash($_GET['store_url'])) : '';

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
$recent_errors = WC_Multi_Store_API_Usage_Tracker::get_recent_errors(10);

?>

<div class="wrap wc-mss-api-usage">
    <h1><?php esc_html_e('API Usage Dashboard', 'multi-store-sync-for-woocommerce'); ?></h1>

    <!-- Filters -->
    <div class="wc-mss-filters wc-mss-toolbar">
        <form method="get" action="">
            <input type="hidden" name="page" value="wc-settings">
            <input type="hidden" name="tab" value="multi_store_sync">
            <input type="hidden" name="section" value="api-usage">

            <label>
                <?php esc_html_e('Time Period:', 'multi-store-sync-for-woocommerce'); ?>
                <select name="days">
                    <option value="7" <?php selected($days, 7); ?>><?php esc_html_e('Last 7 days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="30" <?php selected($days, 30); ?>><?php esc_html_e('Last 30 days', 'multi-store-sync-for-woocommerce'); ?></option>
                    <option value="90" <?php selected($days, 90); ?>><?php esc_html_e('Last 90 days', 'multi-store-sync-for-woocommerce'); ?></option>
                </select>
            </label>

            <label>
                <?php esc_html_e('Store:', 'multi-store-sync-for-woocommerce'); ?>
                <select name="store_url">
                    <option value=""><?php esc_html_e('All Stores', 'multi-store-sync-for-woocommerce'); ?></option>
                    <?php foreach ($stores as $url => $config): ?>
                        <option value="<?php echo esc_attr($url); ?>" <?php selected($store_filter, $url); ?>>
                            <?php echo esc_html(isset($config['name']) ? $config['name'] : $url); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button type="submit" class="button"><?php esc_html_e('Apply Filters', 'multi-store-sync-for-woocommerce'); ?></button>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=api-usage&action=export_csv&days=' . $days), 'wc_mss_export_api_usage')); ?>" class="button">
                <?php esc_html_e('Export CSV', 'multi-store-sync-for-woocommerce'); ?>
            </a>
        </form>

        <?php if ($stats['total_requests'] > 0): ?>
        <form method="post" action="">
            <?php wp_nonce_field('wc_mss_clear_api_usage'); ?>
            <button type="submit" name="wc_mss_clear_api_usage" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete all API usage data? This action cannot be undone.', 'multi-store-sync-for-woocommerce'); ?>');">
                <?php esc_html_e('Clear All Data', 'multi-store-sync-for-woocommerce'); ?>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Statistics Cards -->
    <div class="wc-mss-toolbar">
        <div class="card wc-mss-stat-card">
            <h3><?php esc_html_e('Total Requests', 'multi-store-sync-for-woocommerce'); ?></h3>
            <p><?php echo esc_html(number_format($stats['total_requests'])); ?></p>
        </div>

        <div class="card wc-mss-stat-card">
            <h3><?php esc_html_e('Success Rate', 'multi-store-sync-for-woocommerce'); ?></h3>
            <p>
                <?php echo esc_html(number_format($stats['success_rate'], 2)); ?>%
            </p>
        </div>

        <div class="card wc-mss-stat-card">
            <h3><?php esc_html_e('Avg Response Time', 'multi-store-sync-for-woocommerce'); ?></h3>
            <p><?php echo esc_html(number_format($stats['avg_response_time'])); ?> <span>ms</span></p>
        </div>

        <div class="card wc-mss-stat-card">
            <h3><?php esc_html_e('Data Transferred', 'multi-store-sync-for-woocommerce'); ?></h3>
            <p>
                <?php echo esc_html(size_format($stats['total_data_transferred'], 2)); ?>
            </p>
        </div>
    </div>

    <!-- Daily Trend Chart -->
    <div class="card wc-mss-card">
        <h2><?php esc_html_e('Daily Request Trend', 'multi-store-sync-for-woocommerce'); ?></h2>
        <canvas id="wc-mss-trend-chart"></canvas>
    </div>

    <div class="wc-mss-toolbar">
        <!-- Usage by Store -->
        <div class="card wc-mss-card">
            <h2><?php esc_html_e('Usage by Store', 'multi-store-sync-for-woocommerce'); ?></h2>
            <?php if (!empty($usage_by_store)): ?>
                <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('api-stores', [
            'api-stores-store' => __('Store', 'multi-store-sync-for-woocommerce'),
            'api-stores-requests' => __('Requests', 'multi-store-sync-for-woocommerce'),
            'api-stores-success-rate' => __('Success Rate', 'multi-store-sync-for-woocommerce'),
            'api-stores-avg-time' => __('Avg Time', 'multi-store-sync-for-woocommerce')
        ], $usage_by_store, static function (array $store): void {

            ?>
            <td><?php echo esc_html($store['store_url']); ?></td>
            <td><?php echo esc_html(number_format($store['total_requests'])); ?></td>
            <td>
                <span>
                    <?php echo esc_html(number_format($store['success_rate'], 1)); ?>%
                </span>
            </td>
            <td><?php echo esc_html(number_format($store['avg_response_time'])); ?> ms</td>
            <?php
        });
        $list_table->display();
        ?>
            <?php else: ?>
                <p><?php esc_html_e('No data available.', 'multi-store-sync-for-woocommerce'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Usage by Endpoint -->
        <div class="card wc-mss-card">
            <h2><?php esc_html_e('Top Endpoints', 'multi-store-sync-for-woocommerce'); ?></h2>
            <?php if (!empty($usage_by_endpoint)): ?>
                <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('api-endpoints', [
            'api-endpoints-endpoint' => __('Endpoint', 'multi-store-sync-for-woocommerce'),
            'api-endpoints-method' => __('Method', 'multi-store-sync-for-woocommerce'),
            'api-endpoints-requests' => __('Requests', 'multi-store-sync-for-woocommerce'),
            'api-endpoints-avg-time' => __('Avg Time', 'multi-store-sync-for-woocommerce')
        ], $usage_by_endpoint, static function (array $endpoint): void {

            ?>
            <td><code><?php echo esc_html($endpoint['endpoint']); ?></code></td>
            <td><code><?php echo esc_html($endpoint['method']); ?></code></td>
            <td><?php echo esc_html(number_format($endpoint['total_requests'])); ?></td>
            <td><?php echo esc_html(number_format($endpoint['avg_response_time'])); ?> ms</td>
            <?php
        });
        $list_table->display();
        ?>
            <?php else: ?>
                <p><?php esc_html_e('No data available.', 'multi-store-sync-for-woocommerce'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Errors -->
    <?php if (!empty($recent_errors)): ?>
        <div class="card wc-mss-card">
            <h2><?php esc_html_e('Recent Errors', 'multi-store-sync-for-woocommerce'); ?></h2>
            <?php
        $list_table = new WC_Multi_Store_Admin_List_Table('api-errors', [
            'api-errors-time' => __('Time', 'multi-store-sync-for-woocommerce'),
            'api-errors-store' => __('Store', 'multi-store-sync-for-woocommerce'),
            'api-errors-endpoint' => __('Endpoint', 'multi-store-sync-for-woocommerce'),
            'api-errors-error' => __('Error', 'multi-store-sync-for-woocommerce')
        ], $recent_errors, static function (array $error): void {

            ?>
            <td><?php echo esc_html(date('M j, Y H:i', strtotime($error['created_at']))); ?></td>
            <td><?php echo esc_html($error['store_url']); ?></td>
            <td><code><?php echo esc_html($error['endpoint']); ?></code></td>
            <td><?php echo esc_html($error['error_message']); ?></td>
            <?php
        });
        $list_table->display();
        ?>
        </div>
    <?php endif; ?>

</div>
