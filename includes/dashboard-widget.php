<?php
/**
 * WordPress Dashboard Widget for WooCommerce Multi-Store Sync
 *
 * Provides a quick overview widget on the WordPress dashboard
 * and sync statistics/reporting for the admin pages.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Dashboard_Widget {

    /**
     * Initialize dashboard widget
     */
    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
        add_action('wp_ajax_wc_mss_dashboard_stats', [$this, 'ajax_dashboard_stats']);
    }

    /**
     * Register the WordPress dashboard widget
     */
    public function register_widget(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'wc_mss_dashboard_widget',
            __('Multi-Store Sync', 'wc-multi-store-sync'),
            [$this, 'render_widget']
        );
    }

    /**
     * Render the dashboard widget
     */
    public function render_widget(): void {
        $settings = WC_Multi_Store_Settings::get_settings();
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $queue_stats = WC_Multi_Store_Queue_Table::get_stats();
        $dlq_stats = WC_Multi_Store_Dead_Letter_Queue::get_stats();
        $sync_stats = WC_Multi_Store_Sync_History::get_statistics(['days' => 1]);
        $overall = $sync_stats['overall'];

        $admin_url = admin_url('admin.php?page=wc-settings&tab=multi_store_sync');
        ?>
        <div class="wc-mss-widget">
            <style>
                .wc-mss-widget .wc-mss-w-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
                .wc-mss-widget .wc-mss-w-stat { background: #f6f7f7; padding: 12px; border-radius: 4px; text-align: center; }
                .wc-mss-widget .wc-mss-w-stat .wc-mss-w-num { font-size: 24px; font-weight: 600; line-height: 1.2; }
                .wc-mss-widget .wc-mss-w-stat .wc-mss-w-label { font-size: 11px; color: #646970; text-transform: uppercase; }
                .wc-mss-widget .wc-mss-w-status { display: flex; align-items: center; gap: 6px; padding: 4px 0; }
                .wc-mss-widget .wc-mss-w-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
                .wc-mss-widget .wc-mss-w-dot.active { background: #00a32a; }
                .wc-mss-widget .wc-mss-w-dot.inactive { background: #d63638; }
                .wc-mss-widget .wc-mss-w-dot.warning { background: #dba617; }
                .wc-mss-widget .wc-mss-w-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e0e0e0; }
                .wc-mss-widget .wc-mss-w-bar { height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; margin-top: 8px; }
                .wc-mss-widget .wc-mss-w-bar-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
            </style>

            <!-- Status -->
            <div class="wc-mss-w-status">
                <span class="wc-mss-w-dot <?php echo $settings['enabled'] ? 'active' : 'inactive'; ?>"></span>
                <strong><?php echo $settings['enabled'] ? __('Sync Active', 'wc-multi-store-sync') : __('Sync Disabled', 'wc-multi-store-sync'); ?></strong>
                <span style="margin-left: auto; color: #646970;"><?php echo count($stores); ?> <?php _e('store(s)', 'wc-multi-store-sync'); ?></span>
            </div>

            <!-- Stats Grid -->
            <div class="wc-mss-w-grid">
                <div class="wc-mss-w-stat">
                    <div class="wc-mss-w-num"><?php echo (int) ($overall['total_syncs'] ?? 0); ?></div>
                    <div class="wc-mss-w-label"><?php _e('Syncs Today', 'wc-multi-store-sync'); ?></div>
                </div>
                <div class="wc-mss-w-stat">
                    <div class="wc-mss-w-num" style="color: <?php echo ($overall['success_rate'] ?? 0) >= 90 ? '#00a32a' : '#d63638'; ?>;">
                        <?php echo $overall['success_rate'] ?? 0; ?>%
                    </div>
                    <div class="wc-mss-w-label"><?php _e('Success Rate', 'wc-multi-store-sync'); ?></div>
                </div>
                <div class="wc-mss-w-stat">
                    <div class="wc-mss-w-num"><?php echo $queue_stats['pending']; ?></div>
                    <div class="wc-mss-w-label"><?php _e('Queue Pending', 'wc-multi-store-sync'); ?></div>
                </div>
                <div class="wc-mss-w-stat">
                    <div class="wc-mss-w-num" style="color: <?php echo $dlq_stats['total_dead'] > 0 ? '#d63638' : '#646970'; ?>;">
                        <?php echo $dlq_stats['total_dead']; ?>
                    </div>
                    <div class="wc-mss-w-label"><?php _e('Dead Letters', 'wc-multi-store-sync'); ?></div>
                </div>
            </div>

            <!-- Success Rate Bar -->
            <?php if (($overall['total_syncs'] ?? 0) > 0): ?>
            <div class="wc-mss-w-bar">
                <div class="wc-mss-w-bar-fill" style="width: <?php echo $overall['success_rate']; ?>%; background: <?php echo ($overall['success_rate'] ?? 0) >= 90 ? '#00a32a' : (($overall['success_rate'] ?? 0) >= 70 ? '#dba617' : '#d63638'); ?>;"></div>
            </div>
            <?php endif; ?>

            <!-- Queue warnings -->
            <?php if ($queue_stats['failed'] > 0): ?>
            <div class="wc-mss-w-status" style="margin-top: 8px;">
                <span class="wc-mss-w-dot warning"></span>
                <span><?php echo sprintf(__('%d failed item(s) in queue', 'wc-multi-store-sync'), $queue_stats['failed']); ?></span>
            </div>
            <?php endif; ?>

            <!-- Quick Links -->
            <div class="wc-mss-w-links">
                <a href="<?php echo esc_url($admin_url); ?>" class="button button-small button-primary"><?php _e('Dashboard', 'wc-multi-store-sync'); ?></a>
                <a href="<?php echo esc_url($admin_url . '&section=queue'); ?>" class="button button-small"><?php _e('Queue', 'wc-multi-store-sync'); ?></a>
                <a href="<?php echo esc_url($admin_url . '&section=history'); ?>" class="button button-small"><?php _e('History', 'wc-multi-store-sync'); ?></a>
                <?php if ($dlq_stats['total_dead'] > 0): ?>
                <a href="<?php echo esc_url($admin_url . '&section=dead-letter-queue'); ?>" class="button button-small" style="color: #d63638;"><?php _e('Dead Letters', 'wc-multi-store-sync'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for dashboard statistics (for charts)
     */
    public function ajax_dashboard_stats(): void {
        check_ajax_referer('wc_mss_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wc-multi-store-sync')]);
            return;
        }

        $days = (int) ($_POST['days'] ?? 7);
        $stats = WC_Multi_Store_Sync_History::get_statistics(['days' => $days]);

        // Format daily stats for chart
        $chart_data = [
            'labels' => [],
            'success' => [],
            'failed' => [],
        ];

        // Fill in all days (including days with no data)
        if ($days > 0) {
            $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
            $recurrences = $days - 1;
            $period = new DatePeriod($today->sub(new DateInterval("P{$recurrences}D")), new DateInterval('P1D'), $recurrences);

            foreach ($period as $day) {
                $date = $day->format('Y-m-d');
                $chart_data['labels'][] = $day->format('M j');

                $day_data = null;
                foreach ($stats['daily'] as $daily) {
                    if ($daily['date'] === $date) {
                        $day_data = $daily;
                        break;
                    }
                }

                $chart_data['success'][] = (int) ($day_data['successful'] ?? 0);
                $chart_data['failed'][] = (int) ($day_data['failed'] ?? 0);
            }
        }

        wp_send_json_success([
            'overall' => $stats['overall'],
            'by_store' => $stats['by_store'],
            'by_type' => $stats['by_type'],
            'chart' => $chart_data,
        ]);
    }
}
