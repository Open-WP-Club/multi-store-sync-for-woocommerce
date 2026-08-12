<?php
/**
 * WooCommerce Multi-Store Email Notifications
 *
 * Handles email notifications for sync events, failures, and alerts
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email Notifications Class
 */
class WC_Multi_Store_Email_Notifications {

    use WC_Multi_Store_Email_Shell;

    /**
     * Per-notification-type rate limit — matches the pattern used by
     * circuit-breaker.php's DEFAULT_THRESHOLD/DEFAULT_OPEN_DURATION and
     * dead-letter-queue.php's NOTIFICATION_THRESHOLD/NOTIFICATION_COOLDOWN.
     */
    const int NOTIFICATION_RATE_LIMIT = 12 * HOUR_IN_SECONDS;

    /**
     * Initialize email notifications
     */
    public function __construct() {
        // Schedule daily summary if enabled
        add_action('init', $this->schedule_daily_summary(...));
        add_action('wc_mss_send_daily_summary', $this->send_daily_summary(...));

        // Hook into sync events
        add_action('wc_mss_sync_failed', $this->send_failed_sync_notification(...), 10, 3);
        add_action('wc_mss_api_error', $this->send_api_error_notification(...), 10, 2);
        add_action('wc_mss_low_stock_detected', $this->send_low_stock_notification(...), 10, 3);
        add_action('wc_mss_conflict_detected', $this->send_conflict_notification(...), 10, 4);
    }

    /**
     * Get email notification settings
     *
     * @return array Email settings
     */
    public static function get_settings(): array {
        $defaults = [
            'enabled' => false,
            'recipient_email' => get_option('admin_email'),
            'failed_sync_enabled' => true,
            'daily_summary_enabled' => false,
            'low_stock_enabled' => false,
            'low_stock_threshold' => 10,
            'api_error_enabled' => true,
            'daily_summary_time' => '08:00',
        ];

        $settings = get_option('wc_multi_store_sync_email_settings', []);
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Update email notification settings
     *
     * @param array $settings Email settings
     * @return bool Success
     */
    public static function update_settings(array $settings): bool {
        return update_option('wc_multi_store_sync_email_settings', $settings);
    }

    /**
     * Check if notifications are enabled
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $settings = self::get_settings();
        return !empty($settings['enabled']);
    }

    /**
     * Schedule daily summary email
     */
    public function schedule_daily_summary(): void {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return;
        }

        $hook = 'wc_mss_send_daily_summary';
        $group = WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP;
        $settings = self::get_settings();

        if (!empty($settings['daily_summary_enabled']) && self::is_enabled()) {
            if (!as_next_scheduled_action($hook, [], $group)) {
                // Parse time from settings
                $time_parts = explode(':', $settings['daily_summary_time']);
                $hour = isset($time_parts[0]) ? intval($time_parts[0]) : 8;
                $minute = isset($time_parts[1]) ? intval($time_parts[1]) : 0;

                // Calculate timestamp for next run
                $timestamp = strtotime("today {$hour}:{$minute}:00");
                if ($timestamp < time()) {
                    $timestamp = strtotime("tomorrow {$hour}:{$minute}:00");
                }

                as_schedule_recurring_action($timestamp, DAY_IN_SECONDS, $hook, [], $group);
            }
        } else {
            as_unschedule_all_actions($hook, [], $group);
        }
    }

    /**
     * Check and claim a send slot for the given notification type.
     * Returns true (and marks the slot used) only if no email of this type was sent in the last 12 hours.
     *
     * @param string $type Notification type key (e.g. 'failed_sync', 'api_error', 'low_stock')
     * @return bool
     */
    private function claim_daily_slot(string $type): bool {
        $transient_key = 'wc_mss_email_sent_' . $type;
        if (get_transient($transient_key)) {
            return false;
        }
        set_transient($transient_key, 1, self::NOTIFICATION_RATE_LIMIT);
        return true;
    }

    /**
     * Send failed sync notification
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param string $error_message Error message
     */
    public function send_failed_sync_notification(int $product_id, string $store_url, string $error_message): void {
        $settings = self::get_settings();

        if (!self::is_enabled() || empty($settings['failed_sync_enabled'])) {
            return;
        }

        if (!$this->claim_daily_slot('failed_sync')) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $subject = sprintf(
            __('[%s] Product Sync Failed', 'wc-multi-store-sync'),
            get_bloginfo('name')
        );

        $data = [
            'product_id'    => $product_id,
            'product_name'  => $product->get_name(),
            'product_sku'   => $product->get_sku(),
            'store_url'     => $store_url,
            'error_message' => $error_message,
            'timestamp'     => current_time('mysql'),
            'edit_url'      => function_exists('admin_url') ? admin_url("post.php?post={$product_id}&action=edit") : '',
            'logs_url'      => function_exists('admin_url') ? admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=logs') : '',
        ];

        $message = $this->load_template('failed-sync', $data);

        $this->send_email($settings['recipient_email'], $subject, $message);
    }

    /**
     * Send API error notification
     *
     * @param string $store_url Store URL
     * @param string $error_message Error message
     */
    public function send_api_error_notification(string $store_url, string $error_message): void {
        $settings = self::get_settings();

        if (!self::is_enabled() || empty($settings['api_error_enabled'])) {
            return;
        }

        if (!$this->claim_daily_slot('api_error')) {
            return;
        }

        $subject = sprintf(
            __('[%s] API Error Alert', 'wc-multi-store-sync'),
            get_bloginfo('name')
        );

        $data = [
            'store_url'    => $store_url,
            'error_message' => $error_message,
            'timestamp'    => current_time('mysql'),
            'settings_url' => function_exists('admin_url') ? admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=stores') : '',
        ];

        $message = $this->load_template('api-error', $data);

        $this->send_email($settings['recipient_email'], $subject, $message);
    }

    /**
     * Send low stock notification
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param int $stock_quantity Current stock quantity
     */
    public function send_low_stock_notification(int $product_id, string $store_url, int $stock_quantity): void {
        $settings = self::get_settings();

        if (!self::is_enabled() || empty($settings['low_stock_enabled'])) {
            return;
        }

        if (!$this->claim_daily_slot('low_stock')) {
            return;
        }

        // Check threshold
        $threshold = isset($settings['low_stock_threshold']) ? intval($settings['low_stock_threshold']) : 10;
        if ($stock_quantity > $threshold) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $subject = sprintf(
            __('[%s] Low Stock Alert', 'wc-multi-store-sync'),
            get_bloginfo('name')
        );

        $data = [
            'product_id'    => $product_id,
            'product_name'  => $product->get_name(),
            'product_sku'   => $product->get_sku(),
            'store_url'     => $store_url,
            'stock_quantity' => $stock_quantity,
            'threshold'     => $threshold,
            'timestamp'     => current_time('mysql'),
            'edit_url'      => function_exists('admin_url') ? admin_url("post.php?post={$product_id}&action=edit") : '',
        ];

        $message = $this->load_template('low-stock', $data);

        $this->send_email($settings['recipient_email'], $subject, $message);
    }

    /**
     * Send conflict detected notification
     *
     * Gated on both the global Email Notifications 'enabled' toggle and the
     * Conflict Detector's own 'notify_email' setting — matches the two-key
     * gating already used by check_for_conflicts() itself (feature toggle +
     * this notification toggle are independent knobs).
     *
     * @param int $local_product_id Local product ID
     * @param int $remote_product_id Remote product ID
     * @param string $store_url Remote store URL
     * @param array $changed_fields Fields that changed on the remote product
     */
    public function send_conflict_notification(int $local_product_id, int $remote_product_id, string $store_url, array $changed_fields): void {
        $settings = self::get_settings();

        if (!self::is_enabled() || empty(WC_Multi_Store_Conflict_Detector::get_settings()['notify_email'])) {
            return;
        }

        if (!$this->claim_daily_slot('conflict_detected')) {
            return;
        }

        $product = wc_get_product($local_product_id);
        if (!$product) {
            return;
        }

        $subject = sprintf(
            __('[%s] Sync Conflict Detected', 'wc-multi-store-sync'),
            get_bloginfo('name')
        );

        $data = [
            'product_id'       => $local_product_id,
            'product_name'     => $product->get_name(),
            'product_sku'      => $product->get_sku(),
            'remote_product_id' => $remote_product_id,
            'store_url'        => $store_url,
            'changed_fields'   => implode(', ', $changed_fields),
            'timestamp'        => current_time('mysql'),
            'edit_url'         => function_exists('admin_url') ? admin_url("post.php?post={$local_product_id}&action=edit") : '',
            'conflicts_url'    => function_exists('admin_url') ? admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=conflicts') : '',
        ];

        $message = $this->load_template('conflict-detected', $data);

        $this->send_email($settings['recipient_email'], $subject, $message);
    }

    /**
     * Send daily summary email
     */
    public function send_daily_summary(): void {
        $settings = self::get_settings();

        if (!self::is_enabled() || empty($settings['daily_summary_enabled'])) {
            return;
        }

        global $wpdb;

        // Get yesterday's sync statistics
        $yesterday_date = (new DateTimeImmutable())->sub(new DateInterval('P1D'));
        $yesterday = $yesterday_date->format('Y-m-d');
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $table_name = $wpdb->prefix . 'wc_mss_sync_history';

        // Single aggregation query instead of 5 separate queries
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS total_syncs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful_syncs,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS failed_syncs,
                AVG(CASE WHEN status = 'success' THEN duration_ms ELSE NULL END) AS avg_duration,
                SUM(api_calls) AS total_api_calls
            FROM {$table_name}
            WHERE created_at >= %s AND created_at < %s",
            $yesterday,
            $today
        ));

        $total_syncs = (int) ($stats->total_syncs ?? 0);
        $successful_syncs = (int) ($stats->successful_syncs ?? 0);
        $failed_syncs = (int) ($stats->failed_syncs ?? 0);
        $avg_duration = $stats->avg_duration;
        $total_api_calls = (int) ($stats->total_api_calls ?? 0);

        // Syncs by store
        $syncs_by_store = $wpdb->get_results($wpdb->prepare(
            "SELECT store_url, COUNT(*) as total,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed
            FROM {$table_name}
            WHERE created_at >= %s AND created_at < %s
            GROUP BY store_url",
            $yesterday,
            $today
        ), ARRAY_A);

        $subject = sprintf(
            __('[%s] Daily Sync Summary - %s', 'wc-multi-store-sync'),
            get_bloginfo('name'),
            $yesterday_date->format('F j, Y')
        );

        $data = [
            'date'            => $yesterday_date->format('F j, Y'),
            'total_syncs'     => $total_syncs,
            'successful_syncs' => $successful_syncs,
            'failed_syncs'    => $failed_syncs,
            'success_rate'    => $total_syncs > 0 ? round(($successful_syncs / $total_syncs) * 100, 2) : 0,
            'avg_duration'    => $avg_duration ? round($avg_duration) : 0,
            'total_api_calls' => $total_api_calls,
            'syncs_by_store'  => $syncs_by_store,
            'dashboard_url'   => function_exists('admin_url') ? admin_url('admin.php?page=wc-settings&tab=multi_store_sync') : '',
        ];

        $message = $this->load_template('daily-summary', $data);

        $this->send_email($settings['recipient_email'], $subject, $message);
    }

    /**
     * Load email template
     *
     * @param string $template Template name
     * @param array $data Template data
     * @return string Email content
     */
    private function load_template(string $template, array $data = []): string {
        $template_path = WC_MSS_PLUGIN_DIR . "templates/emails/{$template}.php";

        if (!file_exists($template_path)) {
            // Use default template
            return $this->get_default_template($template, $data);
        }

        ob_start();
        extract($data);
        include $template_path;
        return ob_get_clean();
    }

    /**
     * Get default template content
     *
     * @param string $template Template name
     * @param array $data Template data
     * @return string Email content
     */
    private function get_default_template(string $template, array $data): string {
        return match ($template) {
            'failed-sync' => $this->get_failed_sync_template($data),
            'api-error' => $this->get_api_error_template($data),
            'low-stock' => $this->get_low_stock_template($data),
            'daily-summary' => $this->get_daily_summary_template($data),
            'conflict-detected' => $this->get_conflict_detected_template($data),
            default => '',
        };
    }

    /**
     * Build one labelled data row for detail tables inside emails.
     *
     * @param string $label       Left-column label
     * @param string $value       Right-column value (may contain safe HTML)
     * @param string $value_color Optional hex colour for the value cell
     */
    private function data_row(string $label, string $value, string $value_color = ''): string {
        $value_style = $value_color
            ? "color:{$value_color};font-weight:600;"
            : 'color:#1d2327;';

        return '<tr>'
            . '<td style="padding:10px 14px;background:#f6f7f7;border-bottom:1px solid #dcdcde;width:38%;font-size:13px;font-weight:600;color:#3c434a;vertical-align:top;">'
            . $label . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #dcdcde;font-size:13px;vertical-align:top;' . $value_style . '">'
            . $value . '</td>'
            . '</tr>';
    }

    /**
     * Render a CTA button suitable for email HTML.
     *
     * @param string $url   Destination URL
     * @param string $label Button text
     * @param string $color Background colour (hex)
     */
    private function action_button(string $url, string $label, string $color = '#0070a7'): string {
        return '<table cellpadding="0" cellspacing="0" border="0" style="margin:0 8px 0 0;display:inline-table;">'
            . '<tr><td style="border-radius:5px;background:' . esc_attr($color) . ';">'
            . '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;font-size:13px;font-weight:600;color:#ffffff;text-decoration:none;line-height:1;">'
            . esc_html($label) . '</a></td></tr></table>';
    }

    /**
     * Get failed sync template
     *
     * @param array $data Template data
     * @return string Email content
     */
    private function get_failed_sync_template(array $data): string {
        $lead = '<p style="margin:0 0 20px;font-size:14px;color:#3c434a;line-height:1.6;">'
            . __('A product sync operation has <strong>failed</strong>. Review the details below and check the sync logs for more context.', 'wc-multi-store-sync')
            . '</p>';

        $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;margin:0 0 24px;border-radius:6px;overflow:hidden;border:1px solid #dcdcde;">'
            . $this->data_row(__('Product', 'wc-multi-store-sync'), esc_html($data['product_name']))
            . $this->data_row('SKU',
                '<code style="font-family:monospace;background:#f6f7f7;padding:2px 6px;border-radius:3px;font-size:12px;">'
                . esc_html($data['product_sku']) . '</code>')
            . $this->data_row(__('Product ID', 'wc-multi-store-sync'), '#' . esc_html($data['product_id']))
            . $this->data_row(__('Remote Store', 'wc-multi-store-sync'), esc_html($data['store_url']))
            . $this->data_row(__('Error', 'wc-multi-store-sync'), esc_html($data['error_message']), '#d63638')
            . $this->data_row(__('Detected at', 'wc-multi-store-sync'), esc_html($data['timestamp']))
            . '</table>';

        $buttons = '';
        if (!empty($data['edit_url']) || !empty($data['logs_url'])) {
            $buttons = '<p style="margin:0 0 8px;font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.5px;">'
                . __('Quick Actions', 'wc-multi-store-sync') . '</p><p style="margin:0;">';
            if (!empty($data['edit_url'])) {
                $buttons .= $this->action_button($data['edit_url'], __('Edit Product', 'wc-multi-store-sync'), '#2271b1');
            }
            if (!empty($data['logs_url'])) {
                $buttons .= $this->action_button($data['logs_url'], __('View Logs', 'wc-multi-store-sync'), '#646970');
            }
            $buttons .= '</p>';
        }

        return $this->wrap_email(
            __('Product Sync Failed', 'wc-multi-store-sync'),
            __('Sync Alert', 'wc-multi-store-sync'),
            '#b32d2e',
            $lead . $table . $buttons
        );
    }

    /**
     * Get API error template
     *
     * @param array $data Template data
     * @return string Email content
     */
    private function get_api_error_template(array $data): string {
        $lead = '<p style="margin:0 0 20px;font-size:14px;color:#3c434a;line-height:1.6;">'
            . __('An <strong>API error</strong> occurred while communicating with a remote store. The circuit breaker may pause requests to this store temporarily.', 'wc-multi-store-sync')
            . '</p>';

        $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;margin:0 0 24px;border-radius:6px;overflow:hidden;border:1px solid #dcdcde;">'
            . $this->data_row(__('Remote Store', 'wc-multi-store-sync'), esc_html($data['store_url']))
            . $this->data_row(__('Error', 'wc-multi-store-sync'), esc_html($data['error_message']), '#d63638')
            . $this->data_row(__('Detected at', 'wc-multi-store-sync'), esc_html($data['timestamp']))
            . '</table>';

        $tip = '<p style="margin:0 0 20px;font-size:13px;color:#646970;line-height:1.5;padding:12px 16px;background:#fff8e5;border-left:3px solid #dba617;border-radius:0 4px 4px 0;">'
            . '<strong>' . __('Suggested action:', 'wc-multi-store-sync') . '</strong> '
            . __('Check the store\'s API credentials and connection. If the store is temporarily down, sync will resume automatically once it is back online.', 'wc-multi-store-sync')
            . '</p>';

        $buttons = '';
        if (!empty($data['settings_url'])) {
            $buttons = '<p style="margin:0;">' . $this->action_button($data['settings_url'], __('Store Settings', 'wc-multi-store-sync'), '#2271b1') . '</p>';
        }

        return $this->wrap_email(
            __('API Error Alert', 'wc-multi-store-sync'),
            __('Connectivity Alert', 'wc-multi-store-sync'),
            '#9a5001',
            $lead . $table . $tip . $buttons
        );
    }

    /**
     * Get low stock template
     *
     * @param array $data Template data
     * @return string Email content
     */
    private function get_low_stock_template(array $data): string {
        $stock     = (int) $data['stock_quantity'];
        $threshold = (int) $data['threshold'];

        if ($stock === 0) {
            $stock_label = '<span style="color:#d63638;font-weight:700;">0 &mdash; ' . __('Out of Stock', 'wc-multi-store-sync') . '</span>';
        } else {
            $pct = $threshold > 0 ? round(($stock / $threshold) * 100) : 0;
            $stock_label = '<span style="color:#d63638;font-weight:700;">' . esc_html($stock) . '</span>'
                . ' <span style="font-size:12px;color:#646970;">(' . $pct . '% ' . __('of threshold', 'wc-multi-store-sync') . ')</span>';
        }

        $lead = '<p style="margin:0 0 20px;font-size:14px;color:#3c434a;line-height:1.6;">'
            . __('A product on a remote store has fallen <strong>below the stock threshold</strong>. Consider restocking soon.', 'wc-multi-store-sync')
            . '</p>';

        $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;margin:0 0 24px;border-radius:6px;overflow:hidden;border:1px solid #dcdcde;">'
            . $this->data_row(__('Product', 'wc-multi-store-sync'), esc_html($data['product_name']))
            . $this->data_row('SKU',
                '<code style="font-family:monospace;background:#f6f7f7;padding:2px 6px;border-radius:3px;font-size:12px;">'
                . esc_html($data['product_sku']) . '</code>')
            . $this->data_row(__('Remote Store', 'wc-multi-store-sync'), esc_html($data['store_url']))
            . $this->data_row(__('Current Stock', 'wc-multi-store-sync'), $stock_label)
            . $this->data_row(__('Alert Threshold', 'wc-multi-store-sync'), esc_html($threshold) . ' ' . __('units', 'wc-multi-store-sync'))
            . $this->data_row(__('Detected at', 'wc-multi-store-sync'), esc_html($data['timestamp']))
            . '</table>';

        $buttons = '';
        if (!empty($data['edit_url'])) {
            $buttons = '<p style="margin:0;">' . $this->action_button($data['edit_url'], __('Edit Product / Restock', 'wc-multi-store-sync'), '#d97706') . '</p>';
        }

        return $this->wrap_email(
            __('Low Stock Alert', 'wc-multi-store-sync'),
            __('Inventory Alert', 'wc-multi-store-sync'),
            '#d97706',
            $lead . $table . $buttons
        );
    }

    /**
     * Get conflict detected template
     *
     * @param array $data Template data
     * @return string Email content
     */
    private function get_conflict_detected_template(array $data): string {
        $lead = '<p style="margin:0 0 20px;font-size:14px;color:#3c434a;line-height:1.6;">'
            . __('A product was <strong>modified directly on a remote store</strong> since our last sync. Review the change before it gets overwritten.', 'wc-multi-store-sync')
            . '</p>';

        $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;margin:0 0 24px;border-radius:6px;overflow:hidden;border:1px solid #dcdcde;">'
            . $this->data_row(__('Product', 'wc-multi-store-sync'), esc_html($data['product_name']))
            . $this->data_row('SKU',
                '<code style="font-family:monospace;background:#f6f7f7;padding:2px 6px;border-radius:3px;font-size:12px;">'
                . esc_html($data['product_sku']) . '</code>')
            . $this->data_row(__('Remote Store', 'wc-multi-store-sync'), esc_html($data['store_url']))
            . $this->data_row(__('Changed Fields', 'wc-multi-store-sync'), esc_html($data['changed_fields']), '#d63638')
            . $this->data_row(__('Detected at', 'wc-multi-store-sync'), esc_html($data['timestamp']))
            . '</table>';

        $buttons = '';
        if (!empty($data['edit_url']) || !empty($data['conflicts_url'])) {
            $buttons = '<p style="margin:0 0 8px;font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.5px;">'
                . __('Quick Actions', 'wc-multi-store-sync') . '</p><p style="margin:0;">';
            if (!empty($data['conflicts_url'])) {
                $buttons .= $this->action_button($data['conflicts_url'], __('Review Conflict', 'wc-multi-store-sync'), '#2271b1');
            }
            if (!empty($data['edit_url'])) {
                $buttons .= $this->action_button($data['edit_url'], __('Edit Product', 'wc-multi-store-sync'), '#646970');
            }
            $buttons .= '</p>';
        }

        return $this->wrap_email(
            __('Sync Conflict Detected', 'wc-multi-store-sync'),
            __('Conflict Alert', 'wc-multi-store-sync'),
            '#9a5001',
            $lead . $table . $buttons
        );
    }

    /**
     * Get daily summary template
     *
     * @param array $data Template data
     * @return string Email content
     */
    private function get_daily_summary_template(array $data): string {
        $total      = (int) $data['total_syncs'];
        $successful = (int) $data['successful_syncs'];
        $failed     = (int) $data['failed_syncs'];
        $rate       = (float) $data['success_rate'];

        // Health status badge
        if ($total === 0) {
            $health_color = '#646970';
            $health_label = __('No Activity', 'wc-multi-store-sync');
        } elseif ($rate >= 99) {
            $health_color = '#0a7227';
            $health_label = __('Excellent', 'wc-multi-store-sync');
        } elseif ($rate >= 90) {
            $health_color = '#2271b1';
            $health_label = __('Good', 'wc-multi-store-sync');
        } elseif ($rate >= 70) {
            $health_color = '#d97706';
            $health_label = __('Warning', 'wc-multi-store-sync');
        } else {
            $health_color = '#d63638';
            $health_label = __('Critical', 'wc-multi-store-sync');
        }

        $health_badge = '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;color:#fff;background:' . $health_color . ';">'
            . $health_label . '</span>';

        // Overview stat boxes (single-row table)
        $stat_cell = 'style="width:25%;padding:16px 12px;text-align:center;border-right:1px solid #dcdcde;"';
        $stat_num  = 'style="display:block;font-size:28px;font-weight:700;line-height:1;margin-bottom:4px;"';
        $stat_lbl  = 'style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#646970;"';

        $stats_row = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;margin:0 0 24px;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;">'
            . '<tr>'
            . '<td ' . $stat_cell . '><span ' . $stat_num . ' style="color:#1d2327;">' . esc_html($total) . '</span><span ' . $stat_lbl . '>' . __('Total Syncs', 'wc-multi-store-sync') . '</span></td>'
            . '<td ' . $stat_cell . '><span ' . $stat_num . ' style="color:#0a7227;">' . esc_html($successful) . '</span><span ' . $stat_lbl . '>' . __('Successful', 'wc-multi-store-sync') . '</span></td>'
            . '<td ' . $stat_cell . '><span ' . $stat_num . ' style="color:' . ($failed > 0 ? '#d63638' : '#1d2327') . ';">' . esc_html($failed) . '</span><span ' . $stat_lbl . '>' . __('Failed', 'wc-multi-store-sync') . '</span></td>'
            . '<td style="width:25%;padding:16px 12px;text-align:center;"><span ' . $stat_num . ' style="color:' . $health_color . ';">' . esc_html($rate) . '%</span><span ' . $stat_lbl . '>' . __('Success Rate', 'wc-multi-store-sync') . '</span></td>'
            . '</tr></table>';

        // Details table
        $details = '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;margin:0 0 24px;border-radius:6px;overflow:hidden;border:1px solid #dcdcde;">'
            . $this->data_row(__('Date', 'wc-multi-store-sync'), esc_html($data['date']))
            . $this->data_row(__('Health', 'wc-multi-store-sync'), $health_badge)
            . $this->data_row(__('Avg. Sync Duration', 'wc-multi-store-sync'), esc_html($data['avg_duration']) . ' ms')
            . $this->data_row(__('Total API Calls', 'wc-multi-store-sync'), esc_html($data['total_api_calls']))
            . '</table>';

        // Per-store breakdown
        $store_section = '';
        if (!empty($data['syncs_by_store'])) {
            $store_section = '<p style="margin:0 0 8px;font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.5px;">'
                . __('By Store', 'wc-multi-store-sync') . '</p>'
                . '<table width="100%" cellpadding="0" cellspacing="0" border="0" '
                . 'style="border-collapse:collapse;margin:0 0 24px;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;">'
                . '<thead><tr>'
                . '<th style="padding:9px 14px;background:#f6f7f7;font-size:12px;font-weight:600;color:#3c434a;text-align:left;border-bottom:1px solid #dcdcde;">' . __('Store', 'wc-multi-store-sync') . '</th>'
                . '<th style="padding:9px 14px;background:#f6f7f7;font-size:12px;font-weight:600;color:#3c434a;text-align:center;border-bottom:1px solid #dcdcde;">' . __('Total', 'wc-multi-store-sync') . '</th>'
                . '<th style="padding:9px 14px;background:#f6f7f7;font-size:12px;font-weight:600;color:#0a7227;text-align:center;border-bottom:1px solid #dcdcde;">' . __('OK', 'wc-multi-store-sync') . '</th>'
                . '<th style="padding:9px 14px;background:#f6f7f7;font-size:12px;font-weight:600;color:#d63638;text-align:center;border-bottom:1px solid #dcdcde;">' . __('Fail', 'wc-multi-store-sync') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($data['syncs_by_store'] as $store) {
                $row_fail = (int) $store['failed'];
                $store_section .= '<tr>'
                    . '<td style="padding:10px 14px;font-size:13px;color:#1d2327;border-bottom:1px solid #dcdcde;">' . esc_html($store['store_url']) . '</td>'
                    . '<td style="padding:10px 14px;font-size:13px;color:#1d2327;text-align:center;border-bottom:1px solid #dcdcde;">' . esc_html($store['total']) . '</td>'
                    . '<td style="padding:10px 14px;font-size:13px;color:#0a7227;font-weight:600;text-align:center;border-bottom:1px solid #dcdcde;">' . esc_html($store['successful']) . '</td>'
                    . '<td style="padding:10px 14px;font-size:13px;' . ($row_fail > 0 ? 'color:#d63638;font-weight:600;' : 'color:#646970;') . 'text-align:center;border-bottom:1px solid #dcdcde;">' . esc_html($store['failed']) . '</td>'
                    . '</tr>';
            }

            $store_section .= '</tbody></table>';
        }

        $buttons = '';
        if (!empty($data['dashboard_url'])) {
            $buttons = '<p style="margin:0;">' . $this->action_button($data['dashboard_url'], __('Open Dashboard', 'wc-multi-store-sync'), '#0070a7') . '</p>';
        }

        return $this->wrap_email(
            sprintf(__('Daily Sync Summary — %s', 'wc-multi-store-sync'), $data['date']),
            __('Daily Report', 'wc-multi-store-sync'),
            '#0070a7',
            $stats_row . $details . $store_section . $buttons
        );
    }

    /**
     * Send email
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email message
     * @return bool Success
     */
    private function send_email(string $to, string $subject, string $message): bool {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        return wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Trigger sync failed notification
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param string $error_message Error message
     */
    public static function trigger_sync_failed(int $product_id, string $store_url, string $error_message): void {
        do_action('wc_mss_sync_failed', $product_id, $store_url, $error_message);
    }

    /**
     * Trigger API error notification
     *
     * @param string $store_url Store URL
     * @param string $error_message Error message
     */
    public static function trigger_api_error(string $store_url, string $error_message): void {
        do_action('wc_mss_api_error', $store_url, $error_message);
    }

    /**
     * Trigger low stock notification
     *
     * @param int $product_id Product ID
     * @param string $store_url Store URL
     * @param int $stock_quantity Current stock quantity
     */
    public static function trigger_low_stock(int $product_id, string $store_url, int $stock_quantity): void {
        do_action('wc_mss_low_stock_detected', $product_id, $store_url, $stock_quantity);
    }

    /**
     * Trigger conflict detected notification
     *
     * @param int $local_product_id Local product ID
     * @param int $remote_product_id Remote product ID
     * @param string $store_url Remote store URL
     * @param array $changed_fields Fields that changed on the remote product
     */
    public static function trigger_conflict_detected(int $local_product_id, int $remote_product_id, string $store_url, array $changed_fields): void {
        do_action('wc_mss_conflict_detected', $local_product_id, $remote_product_id, $store_url, $changed_fields);
    }
}
