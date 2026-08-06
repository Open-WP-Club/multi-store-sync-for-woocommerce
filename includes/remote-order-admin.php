<?php
/**
 * WooCommerce Multi-Store Remote Order Admin
 *
 * Handles admin pages for remote orders
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remote Order Admin Class
 */
class WC_Multi_Store_Remote_Order_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', $this->add_menu_items(...));
        add_action('admin_enqueue_scripts', $this->enqueue_scripts(...));
        add_action('admin_init', $this->handle_actions(...));
    }

    /**
     * Add admin menu items
     */
    public function add_menu_items(): void {
        $webhook = WC_Multi_Store_Settings::get_webhook_settings();
        if (empty($webhook['enabled']) || empty($webhook['webhook_secret'])) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            __('Remote Orders', 'wc-multi-store-sync'),
            __('Remote Orders', 'wc-multi-store-sync'),
            'manage_woocommerce',
            'wc-multi-store-remote-orders',
            $this->render_orders_page(...)
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Page hook
     */
    public function enqueue_scripts(string $hook): void {
        // Only load on our pages
        if ('woocommerce_page_wc-multi-store-remote-orders' !== $hook) {
            return;
        }

        // Enqueue custom CSS
        wp_enqueue_style(
            'wc-mss-remote-orders',
            WC_MSS_PLUGIN_URL . 'assets/css/remote-orders.css',
            [],
            WC_MSS_VERSION
        );

        // Enqueue WooCommerce admin styles for consistency
        wp_enqueue_style('woocommerce_admin_styles');
    }

    /**
     * Handle admin actions (delete, sync, etc.)
     */
    public function handle_actions(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-multi-store-remote-orders') {
            return;
        }

        if (!isset($_GET['action'])) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);

        // Handle bulk delete — check is_array FIRST to avoid the single-delete branch catching arrays
        if ($action === 'delete' && isset($_GET['order_id']) && is_array($_GET['order_id'])) {
            check_admin_referer('bulk-remote_orders');

            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('You do not have permission to perform this action', 'wc-multi-store-sync'));
            }

            $deleted = 0;
            foreach ($_GET['order_id'] as $order_id) {
                if (WC_Multi_Store_Remote_Order_Table::delete(absint($order_id))) {
                    $deleted++;
                }
            }

            wp_redirect(add_query_arg([
                'page'    => 'wc-multi-store-remote-orders',
                'deleted' => $deleted,
            ], admin_url('admin.php')));
            exit;
        }

        // Handle single delete (scalar order_id)
        if ($action === 'delete' && isset($_GET['order_id']) && !is_array($_GET['order_id'])) {
            $order_id = absint($_GET['order_id']);
            $nonce = $_GET['_wpnonce'] ?? '';

            if (!wp_verify_nonce($nonce, 'delete_remote_order_' . $order_id)) {
                wp_die(__('Security check failed', 'wc-multi-store-sync'));
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('You do not have permission to perform this action', 'wc-multi-store-sync'));
            }

            if (WC_Multi_Store_Remote_Order_Table::delete($order_id)) {
                wp_redirect(add_query_arg([
                    'page'    => 'wc-multi-store-remote-orders',
                    'deleted' => '1',
                ], admin_url('admin.php')));
                exit;
            }
        }
    }

    /**
     * Render orders page
     */
    public function render_orders_page(): void {
        // Check for action parameter
        $action = sanitize_text_field($_GET['action'] ?? '');

        // Show order details if viewing a specific order
        if ($action === 'view' && isset($_GET['order_id'])) {
            $this->render_order_details();
            return;
        }

        // Show orders list
        $this->render_orders_list();
    }

    /**
     * Render orders list
     */
    private function render_orders_list(): void {
        // Show success message
        if (isset($_GET['deleted'])) {
            $deleted = absint($_GET['deleted']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    _n('%d order deleted.', '%d orders deleted.', $deleted, 'wc-multi-store-sync'),
                    $deleted
                )
            );
        }

        ?>
        <div class="wrap woocommerce">
            <h1 class="wp-heading-inline"><?php _e('Remote Orders', 'wc-multi-store-sync'); ?></h1>

            <?php
            // Get statistics
            $stats = WC_Multi_Store_Remote_Order_Table::get_statistics();
            ?>

            <div class="wc-mss-stats-boxes">
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php _e('Total Orders', 'wc-multi-store-sync'); ?></span>
                    <span class="wc-mss-stat-value"><?php echo esc_html(number_format($stats['total_orders'] ?? 0)); ?></span>
                </div>
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php _e('Total Revenue', 'wc-multi-store-sync'); ?></span>
                    <span class="wc-mss-stat-value">$<?php echo esc_html(number_format($stats['total_revenue'] ?? 0, 2)); ?></span>
                </div>
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php _e('Avg Order Value', 'wc-multi-store-sync'); ?></span>
                    <span class="wc-mss-stat-value">$<?php echo esc_html(number_format($stats['average_order_value'] ?? 0, 2)); ?></span>
                </div>
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php _e('Unique Customers', 'wc-multi-store-sync'); ?></span>
                    <span class="wc-mss-stat-value"><?php echo esc_html(number_format($stats['unique_customers'] ?? 0)); ?></span>
                </div>
            </div>

            <?php
            // Create list table
            $list_table = new WC_Multi_Store_Remote_Order_List_Table();
            $list_table->prepare_items();
            $list_table->display();
            ?>
        </div>
        <?php
    }

    /**
     * Render order details
     */
    private function render_order_details(): void {
        $order_id = absint($_GET['order_id']);
        $order = WC_Multi_Store_Remote_Order_Table::get($order_id);

        if (!$order) {
            wp_die(__('Order not found', 'wc-multi-store-sync'));
        }

        $back_url = add_query_arg(['page' => 'wc-multi-store-remote-orders'], admin_url('admin.php'));
        $delete_url = add_query_arg([
            'page'     => 'wc-multi-store-remote-orders',
            'action'   => 'delete',
            'order_id' => $order_id,
            '_wpnonce' => wp_create_nonce('delete_remote_order_' . $order_id),
        ], admin_url('admin.php'));

        ?>
        <div class="wrap woocommerce">
            <h1 class="wp-heading-inline">
                <?php printf(__('Remote Order #%s', 'wc-multi-store-sync'), esc_html($order->order_number)); ?>
            </h1>

            <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                ← <?php _e('Back to orders', 'wc-multi-store-sync'); ?>
            </a>

            <hr class="wp-header-end">

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <!-- Main column -->
                    <div id="post-body-content">
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2><?php _e('Order Details', 'wc-multi-store-sync'); ?></h2>
                            </div>
                            <div class="inside">
                                <?php $this->render_order_items($order); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div id="postbox-container-1" class="postbox-container">
                        <?php $this->render_order_info_box($order); ?>
                        <?php $this->render_customer_box($order); ?>
                        <?php $this->render_billing_box($order); ?>
                        <?php $this->render_shipping_box($order); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render order items table
     *
     * @param object $order Order object
     */
    private function render_order_items(object $order): void {
        ?>
        <div class="woocommerce_order_items_wrapper">
            <table class="woocommerce_order_items">
                <thead>
                    <tr>
                        <th class="item" colspan="2"><?php _e('Item', 'wc-multi-store-sync'); ?></th>
                        <th class="item_cost"><?php _e('Cost', 'wc-multi-store-sync'); ?></th>
                        <th class="quantity"><?php _e('Qty', 'wc-multi-store-sync'); ?></th>
                        <th class="line_cost"><?php _e('Total', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!empty($order->items) && is_array($order->items)) {
                        foreach ($order->items as $item) {
                            $unit_price = $item->quantity > 0 ? $item->subtotal / $item->quantity : 0;
                            ?>
                            <tr class="item">
                                <td class="thumb">
                                    <div class="wc-order-item-thumbnail"></div>
                                </td>
                                <td class="name">
                                    <strong><?php echo esc_html($item->product_name); ?></strong>
                                    <?php if ($item->product_sku): ?>
                                        <div class="wc-order-item-sku">
                                            <small><?php printf(__('SKU: %s', 'wc-multi-store-sync'), esc_html($item->product_sku)); ?></small>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item->meta_data) && is_array($item->meta_data)): ?>
                                        <div class="wc-order-item-meta">
                                            <?php foreach ($item->meta_data as $meta): ?>
                                                <?php if (isset($meta['key']) && isset($meta['value'])): ?>
                                                    <small><?php echo esc_html($meta['key']); ?>: <?php echo esc_html($meta['value']); ?></small><br>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="item_cost">
                                    <div class="view">
                                        <?php echo $this->format_price($unit_price, $order->currency); ?>
                                    </div>
                                </td>
                                <td class="quantity">
                                    <div class="view">
                                        <?php echo esc_html($item->quantity); ?>
                                    </div>
                                </td>
                                <td class="line_cost">
                                    <div class="view">
                                        <?php echo $this->format_price($item->total, $order->currency); ?>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="label"><?php _e('Subtotal:', 'wc-multi-store-sync'); ?></td>
                        <td class="total"><?php echo $this->format_price($order->subtotal, $order->currency); ?></td>
                    </tr>
                    <?php if ($order->shipping_total > 0): ?>
                        <tr>
                            <td colspan="4" class="label"><?php _e('Shipping:', 'wc-multi-store-sync'); ?></td>
                            <td class="total"><?php echo $this->format_price($order->shipping_total, $order->currency); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($order->discount_total > 0): ?>
                        <tr>
                            <td colspan="4" class="label"><?php _e('Discount:', 'wc-multi-store-sync'); ?></td>
                            <td class="total">-<?php echo $this->format_price($order->discount_total, $order->currency); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($order->tax_total > 0): ?>
                        <tr>
                            <td colspan="4" class="label"><?php _e('Tax:', 'wc-multi-store-sync'); ?></td>
                            <td class="total"><?php echo $this->format_price($order->tax_total, $order->currency); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="4" class="label"><strong><?php _e('Order Total:', 'wc-multi-store-sync'); ?></strong></td>
                        <td class="total"><strong><?php echo $this->format_price($order->total, $order->currency); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }

    /**
     * Render order info box
     *
     * @param object $order Order object
     */
    private function render_order_info_box(object $order): void {
        ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2><?php _e('Order Info', 'wc-multi-store-sync'); ?></h2>
            </div>
            <div class="inside">
                <ul class="order_data">
                    <li>
                        <strong><?php _e('Remote Order ID:', 'wc-multi-store-sync'); ?></strong>
                        <?php echo esc_html($order->remote_order_id); ?>
                    </li>
                    <li>
                        <strong><?php _e('Order Number:', 'wc-multi-store-sync'); ?></strong>
                        <?php echo esc_html($order->order_number); ?>
                    </li>
                    <li>
                        <strong><?php _e('Store:', 'wc-multi-store-sync'); ?></strong>
                        <a href="<?php echo esc_url($order->remote_store_url); ?>" target="_blank">
                            <?php echo esc_html(parse_url($order->remote_store_url, PHP_URL_HOST)); ?>
                        </a>
                    </li>
                    <li>
                        <strong><?php _e('Status:', 'wc-multi-store-sync'); ?></strong>
                        <?php echo esc_html(ucfirst(str_replace('wc-', '', $order->status))); ?>
                    </li>
                    <li>
                        <strong><?php _e('Date Created:', 'wc-multi-store-sync'); ?></strong>
                        <?php echo esc_html(date_i18n('F j, Y g:i a', strtotime($order->date_created))); ?>
                    </li>
                    <?php if ($order->date_paid): ?>
                        <li>
                            <strong><?php _e('Date Paid:', 'wc-multi-store-sync'); ?></strong>
                            <?php echo esc_html(date_i18n('F j, Y g:i a', strtotime($order->date_paid))); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->date_completed): ?>
                        <li>
                            <strong><?php _e('Date Completed:', 'wc-multi-store-sync'); ?></strong>
                            <?php echo esc_html(date_i18n('F j, Y g:i a', strtotime($order->date_completed))); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->payment_method): ?>
                        <li>
                            <strong><?php _e('Payment Method:', 'wc-multi-store-sync'); ?></strong>
                            <?php echo esc_html($order->payment_method_title ? $order->payment_method_title : $order->payment_method); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->transaction_id): ?>
                        <li>
                            <strong><?php _e('Transaction ID:', 'wc-multi-store-sync'); ?></strong>
                            <?php echo esc_html($order->transaction_id); ?>
                        </li>
                    <?php endif; ?>
                    <li>
                        <strong><?php _e('Synced At:', 'wc-multi-store-sync'); ?></strong>
                        <?php echo esc_html(date_i18n('F j, Y g:i a', strtotime($order->synced_at))); ?>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render customer box
     *
     * @param object $order Order object
     */
    private function render_customer_box(object $order): void {
        if (empty($order->customer_email) && empty($order->customer_name)) {
            return;
        }

        ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2><?php _e('Customer', 'wc-multi-store-sync'); ?></h2>
            </div>
            <div class="inside">
                <ul class="order_data">
                    <?php if ($order->customer_name): ?>
                        <li>
                            <strong><?php _e('Name:', 'wc-multi-store-sync'); ?></strong>
                            <?php echo esc_html($order->customer_name); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->customer_email): ?>
                        <li>
                            <strong><?php _e('Email:', 'wc-multi-store-sync'); ?></strong>
                            <a href="mailto:<?php echo esc_attr($order->customer_email); ?>">
                                <?php echo esc_html($order->customer_email); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render billing address box
     *
     * @param object $order Order object
     */
    private function render_billing_box(object $order): void {
        if (empty($order->billing_address)) {
            return;
        }

        $billing = $order->billing_address;
        ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2><?php _e('Billing Address', 'wc-multi-store-sync'); ?></h2>
            </div>
            <div class="inside">
                <div class="address">
                    <?php echo $this->format_address($billing); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render shipping address box
     *
     * @param object $order Order object
     */
    private function render_shipping_box(object $order): void {
        if (empty($order->shipping_address)) {
            return;
        }

        $shipping = $order->shipping_address;
        ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2><?php _e('Shipping Address', 'wc-multi-store-sync'); ?></h2>
            </div>
            <div class="inside">
                <div class="address">
                    <?php echo $this->format_address($shipping); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Format address for display
     *
     * @param array $address Address data
     * @return string
     */
    private function format_address(array $address): string {
        $parts = [];

        if (!empty($address['first_name']) || !empty($address['last_name'])) {
            $parts[] = trim($address['first_name'] . ' ' . $address['last_name']);
        }

        if (!empty($address['company'])) {
            $parts[] = $address['company'];
        }

        if (!empty($address['address_1'])) {
            $parts[] = $address['address_1'];
        }

        if (!empty($address['address_2'])) {
            $parts[] = $address['address_2'];
        }

        $city_parts = array_filter([
            $address['city'] ?? '',
            $address['state'] ?? '',
            $address['postcode'] ?? '',
        ]);

        if (!empty($city_parts)) {
            $parts[] = implode(', ', $city_parts);
        }

        if (!empty($address['country'])) {
            $parts[] = $address['country'];
        }

        if (!empty($address['phone'])) {
            $parts[] = __('Phone:', 'wc-multi-store-sync') . ' ' . $address['phone'];
        }

        if (!empty($address['email'])) {
            $parts[] = __('Email:', 'wc-multi-store-sync') . ' ' . $address['email'];
        }

        return implode('<br>', array_map('esc_html', $parts));
    }

    /**
     * Format price with currency
     *
     * @param float  $price    Price
     * @param string $currency Currency code
     * @return string
     */
    private function format_price(float $price, string $currency = 'USD'): string {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'AUD' => 'A$',
            'CAD' => 'C$',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format($price, 2, '.', ',');
    }
}
