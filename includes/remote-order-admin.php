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
            __('Remote Orders', 'multi-store-sync-for-woocommerce'),
            __('Remote Orders', 'multi-store-sync-for-woocommerce'),
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
                wp_die(esc_html__('You do not have permission to perform this action', 'multi-store-sync-for-woocommerce'));
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
                wp_die(esc_html__('Security check failed', 'multi-store-sync-for-woocommerce'));
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have permission to perform this action', 'multi-store-sync-for-woocommerce'));
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
                    esc_html(_n('%d order deleted.', '%d orders deleted.', $deleted, 'multi-store-sync-for-woocommerce')),
                    absint($deleted)
                )
            );
        }

        ?>
        <div class="wrap woocommerce">
            <h1 class="wp-heading-inline"><?php esc_html_e('Remote Orders', 'multi-store-sync-for-woocommerce'); ?></h1>

            <?php
            // Get statistics
            $stats = WC_Multi_Store_Remote_Order_Table::get_statistics();
            ?>

            <div class="wc-mss-stats-boxes">
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php esc_html_e('Total Orders', 'multi-store-sync-for-woocommerce'); ?></span>
                    <span class="wc-mss-stat-value"><?php echo esc_html(number_format($stats['total_orders'] ?? 0)); ?></span>
                </div>
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php esc_html_e('Total Revenue', 'multi-store-sync-for-woocommerce'); ?></span>
                    <span class="wc-mss-stat-value">$<?php echo esc_html(number_format($stats['total_revenue'] ?? 0, 2)); ?></span>
                </div>
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php esc_html_e('Avg Order Value', 'multi-store-sync-for-woocommerce'); ?></span>
                    <span class="wc-mss-stat-value">$<?php echo esc_html(number_format($stats['average_order_value'] ?? 0, 2)); ?></span>
                </div>
                <div class="wc-mss-stat-box">
                    <span class="wc-mss-stat-label"><?php esc_html_e('Unique Customers', 'multi-store-sync-for-woocommerce'); ?></span>
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
            wp_die(esc_html__('Order not found', 'multi-store-sync-for-woocommerce'));
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
                <?php printf(esc_html__('Remote Order #%s', 'multi-store-sync-for-woocommerce'), esc_html($order->order_number)); ?>
            </h1>

            <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                ← <?php esc_html_e('Back to orders', 'multi-store-sync-for-woocommerce'); ?>
            </a>

            <hr class="wp-header-end">

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <!-- Main column -->
                    <div id="post-body-content">
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2><?php esc_html_e('Order Details', 'multi-store-sync-for-woocommerce'); ?></h2>
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
                        <th class="item" colspan="2"><?php esc_html_e('Item', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th class="item_cost"><?php esc_html_e('Cost', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th class="quantity"><?php esc_html_e('Qty', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th class="line_cost"><?php esc_html_e('Total', 'multi-store-sync-for-woocommerce'); ?></th>
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
                                            <small><?php printf(esc_html__('SKU: %s', 'multi-store-sync-for-woocommerce'), esc_html($item->product_sku)); ?></small>
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
                                        <?php echo esc_html($this->format_price($unit_price, $order->currency)); ?>
                                    </div>
                                </td>
                                <td class="quantity">
                                    <div class="view">
                                        <?php echo esc_html($item->quantity); ?>
                                    </div>
                                </td>
                                <td class="line_cost">
                                    <div class="view">
                                        <?php echo esc_html($this->format_price($item->total, $order->currency)); ?>
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
                        <td colspan="4" class="label"><?php esc_html_e('Subtotal:', 'multi-store-sync-for-woocommerce'); ?></td>
                        <td class="total"><?php echo esc_html($this->format_price($order->subtotal, $order->currency)); ?></td>
                    </tr>
                    <?php if ($order->shipping_total > 0): ?>
                        <tr>
                            <td colspan="4" class="label"><?php esc_html_e('Shipping:', 'multi-store-sync-for-woocommerce'); ?></td>
                            <td class="total"><?php echo esc_html($this->format_price($order->shipping_total, $order->currency)); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($order->discount_total > 0): ?>
                        <tr>
                            <td colspan="4" class="label"><?php esc_html_e('Discount:', 'multi-store-sync-for-woocommerce'); ?></td>
                            <td class="total">-<?php echo esc_html($this->format_price($order->discount_total, $order->currency)); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($order->tax_total > 0): ?>
                        <tr>
                            <td colspan="4" class="label"><?php esc_html_e('Tax:', 'multi-store-sync-for-woocommerce'); ?></td>
                            <td class="total"><?php echo esc_html($this->format_price($order->tax_total, $order->currency)); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="4" class="label"><strong><?php esc_html_e('Order Total:', 'multi-store-sync-for-woocommerce'); ?></strong></td>
                        <td class="total"><strong><?php echo esc_html($this->format_price($order->total, $order->currency)); ?></strong></td>
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
                <h2><?php esc_html_e('Order Info', 'multi-store-sync-for-woocommerce'); ?></h2>
            </div>
            <div class="inside">
                <ul class="order_data">
                    <li>
                        <strong><?php esc_html_e('Remote Order ID:', 'multi-store-sync-for-woocommerce'); ?></strong>
                        <?php echo esc_html($order->remote_order_id); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Order Number:', 'multi-store-sync-for-woocommerce'); ?></strong>
                        <?php echo esc_html($order->order_number); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Store:', 'multi-store-sync-for-woocommerce'); ?></strong>
                        <a href="<?php echo esc_url($order->remote_store_url); ?>" target="_blank">
                            <?php echo esc_html(parse_url($order->remote_store_url, PHP_URL_HOST)); ?>
                        </a>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Status:', 'multi-store-sync-for-woocommerce'); ?></strong>
                        <?php echo esc_html(ucfirst(str_replace('wc-', '', $order->status))); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Date Created:', 'multi-store-sync-for-woocommerce'); ?></strong>
                        <?php echo esc_html(date_i18n('F j, Y g:i a', strtotime($order->date_created))); ?>
                    </li>
                    <?php if ($order->date_paid): ?>
                        <li>
                            <strong><?php esc_html_e('Date Paid:', 'multi-store-sync-for-woocommerce'); ?></strong>
                            <?php echo esc_html(date_i18n('F j, Y g:i a', strtotime($order->date_paid))); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->date_completed): ?>
                        <li>
                            <strong><?php esc_html_e('Date Completed:', 'multi-store-sync-for-woocommerce'); ?></strong>
                            <?php echo esc_html(date_i18n('F j, Y g:i a', strtotime($order->date_completed))); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->payment_method): ?>
                        <li>
                            <strong><?php esc_html_e('Payment Method:', 'multi-store-sync-for-woocommerce'); ?></strong>
                            <?php echo esc_html($order->payment_method_title ? $order->payment_method_title : $order->payment_method); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->transaction_id): ?>
                        <li>
                            <strong><?php esc_html_e('Transaction ID:', 'multi-store-sync-for-woocommerce'); ?></strong>
                            <?php echo esc_html($order->transaction_id); ?>
                        </li>
                    <?php endif; ?>
                    <li>
                        <strong><?php esc_html_e('Synced At:', 'multi-store-sync-for-woocommerce'); ?></strong>
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
                <h2><?php esc_html_e('Customer', 'multi-store-sync-for-woocommerce'); ?></h2>
            </div>
            <div class="inside">
                <ul class="order_data">
                    <?php if ($order->customer_name): ?>
                        <li>
                            <strong><?php esc_html_e('Name:', 'multi-store-sync-for-woocommerce'); ?></strong>
                            <?php echo esc_html($order->customer_name); ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($order->customer_email): ?>
                        <li>
                            <strong><?php esc_html_e('Email:', 'multi-store-sync-for-woocommerce'); ?></strong>
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
                <h2><?php esc_html_e('Billing Address', 'multi-store-sync-for-woocommerce'); ?></h2>
            </div>
            <div class="inside">
                <div class="address">
                    <?php echo wp_kses_post($this->format_address($billing)); ?>
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
                <h2><?php esc_html_e('Shipping Address', 'multi-store-sync-for-woocommerce'); ?></h2>
            </div>
            <div class="inside">
                <div class="address">
                    <?php echo wp_kses_post($this->format_address($shipping)); ?>
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
            $parts[] = __('Phone:', 'multi-store-sync-for-woocommerce') . ' ' . $address['phone'];
        }

        if (!empty($address['email'])) {
            $parts[] = __('Email:', 'multi-store-sync-for-woocommerce') . ' ' . $address['email'];
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
        $symbol = html_entity_decode(get_woocommerce_currency_symbol($currency), ENT_QUOTES, 'UTF-8');

        // get_woocommerce_currency_symbol() falls back to the bare currency code
        // for currencies it has no symbol for — add a separating space in that
        // case only, so e.g. "BGN100.00" doesn't run together.
        if ($symbol === $currency) {
            $symbol .= ' ';
        }

        return $symbol . number_format($price, 2, '.', ',');
    }
}
