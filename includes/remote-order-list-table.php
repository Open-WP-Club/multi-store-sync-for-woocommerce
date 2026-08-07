<?php
/**
 * WooCommerce Multi-Store Remote Order List Table
 *
 * Displays remote orders in WooCommerce-style admin interface
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load WP_List_Table if not loaded
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Remote Order List Table Class
 */
class WC_Multi_Store_Remote_Order_List_Table extends WP_List_Table {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'remote_order',
            'plural'   => 'remote_orders',
            'ajax'     => false,
        ]);
    }

    /**
     * Get columns
     *
     * @return array
     */
    #[\Override]
    public function get_columns(): array {
        return [
            'cb'              => '<input type="checkbox" />',
            'order'           => __('Order', 'wc-multi-store-sync'),
            'date'            => __('Date', 'wc-multi-store-sync'),
            'status'          => __('Status', 'wc-multi-store-sync'),
            'store'           => __('Store', 'wc-multi-store-sync'),
            'customer'        => __('Customer', 'wc-multi-store-sync'),
            'total'           => __('Total', 'wc-multi-store-sync'),
            'actions'         => __('Actions', 'wc-multi-store-sync'),
        ];
    }

    /**
     * Get sortable columns
     *
     * @return array
     */
    #[\Override]
    protected function get_sortable_columns(): array {
        return [
            'order'    => ['order_number', false],
            'date'     => ['date_created', true], // true = already sorted
            'status'   => ['status', false],
            'total'    => ['total', false],
            'customer' => ['customer_email', false],
        ];
    }

    /**
     * Get bulk actions
     *
     * @return array
     */
    #[\Override]
    protected function get_bulk_actions(): array {
        return [
            'delete' => __('Delete', 'wc-multi-store-sync'),
        ];
    }

    /**
     * Column checkbox
     *
     * @param object $item Order item
     * @return string
     */
    #[\Override]
    protected function column_cb($item): string {
        return sprintf(
            '<input type="checkbox" name="order_id[]" value="%d" />',
            $item->id
        );
    }

    /**
     * Column order
     *
     * @param object $item Order item
     * @return string
     */
    protected function column_order($item): string {
        $view_url = add_query_arg([
            'page'     => 'wc-multi-store-remote-orders',
            'action'   => 'view',
            'order_id' => $item->id,
        ], admin_url('admin.php'));

        $delete_url = add_query_arg([
            'page'     => 'wc-multi-store-remote-orders',
            'action'   => 'delete',
            'order_id' => $item->id,
            '_wpnonce' => wp_create_nonce('delete_remote_order_' . $item->id),
        ], admin_url('admin.php'));

        $actions = [
            'view'   => sprintf('<a href="%s">%s</a>', esc_url($view_url), __('View', 'wc-multi-store-sync')),
            'delete' => sprintf('<a href="%s" class="delete-order">%s</a>', esc_url($delete_url), __('Delete', 'wc-multi-store-sync')),
        ];

        return sprintf(
            '<strong><a href="%s" class="order-preview">#%s</a></strong> %s',
            esc_url($view_url),
            esc_html($item->order_number),
            $this->row_actions($actions)
        );
    }

    /**
     * Column date
     *
     * @param object $item Order item
     * @return string
     */
    protected function column_date($item): string {
        $date = strtotime($item->date_created);

        if (!$date) {
            return '—';
        }

        $date_display = date_i18n('M j, Y', $date);
        $time_display = date_i18n('g:i a', $date);

        // Check if order was created today or yesterday
        $today = strtotime('today');
        $yesterday = strtotime('yesterday');

        $date_prefix = match (true) {
            $date >= $today => __('Today at', 'wc-multi-store-sync'),
            $date >= $yesterday => __('Yesterday at', 'wc-multi-store-sync'),
            default => null,
        };

        if ($date_prefix !== null) {
            return sprintf('%s %s', $date_prefix, $time_display);
        }

        return sprintf(
            '<abbr title="%s">%s</abbr>',
            esc_attr(date_i18n('Y-m-d H:i:s', $date)),
            esc_html($date_display . ' at ' . $time_display)
        );
    }

    /**
     * Column status
     *
     * @param object $item Order item
     * @return string
     */
    protected function column_status($item): string {
        $status = $item->status;

        // Map common WooCommerce statuses to labels
        $status_labels = [
            'pending'    => __('Pending payment', 'wc-multi-store-sync'),
            'processing' => __('Processing', 'wc-multi-store-sync'),
            'on-hold'    => __('On hold', 'wc-multi-store-sync'),
            'completed'  => __('Completed', 'wc-multi-store-sync'),
            'cancelled'  => __('Cancelled', 'wc-multi-store-sync'),
            'refunded'   => __('Refunded', 'wc-multi-store-sync'),
            'failed'     => __('Failed', 'wc-multi-store-sync'),
        ];

        // Remove 'wc-' prefix if present
        $status_clean = str_replace('wc-', '', $status);
        $status_label = $status_labels[$status_clean] ?? ucfirst($status_clean);

        // Map statuses to colors (WooCommerce style)
        $status_colors = [
            'pending'    => '#ffba00',
            'processing' => '#c6e1c6',
            'on-hold'    => '#f8dda7',
            'completed'  => '#c8d7e1',
            'cancelled'  => '#e5e5e5',
            'refunded'   => '#c8d7e1',
            'failed'     => '#eba3a3',
        ];

        $color = $status_colors[$status_clean] ?? '#e5e5e5';

        return sprintf(
            '<mark class="order-status status-%s" style="background: %s; color: #000; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; white-space: nowrap;">%s</mark>',
            esc_attr($status_clean),
            esc_attr($color),
            esc_html($status_label)
        );
    }

    /**
     * Column store
     *
     * @param object $item Order item
     * @return string
     */
    protected function column_store($item): string {
        $store_url = $item->remote_store_url;
        $store_host = parse_url($store_url, PHP_URL_HOST);

        // Filter by this store link
        $filter_url = add_query_arg([
            'page'      => 'wc-multi-store-remote-orders',
            'store_url' => urlencode($store_url),
        ], admin_url('admin.php'));

        return sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($filter_url),
            esc_attr($store_url),
            esc_html($store_host)
        );
    }

    /**
     * Column customer
     *
     * @param object $item Order item
     * @return string
     */
    protected function column_customer($item): string {
        if (empty($item->customer_email) && empty($item->customer_name)) {
            return '—';
        }

        $customer_display = $item->customer_name ?? '';
        $customer_email = $item->customer_email ?? '';

        // Filter by this customer link
        $filter_url = add_query_arg([
            'page'           => 'wc-multi-store-remote-orders',
            'customer_email' => urlencode($customer_email),
        ], admin_url('admin.php'));

        if ($customer_display && $customer_email) {
            return sprintf(
                '<a href="%s">%s</a><br><small class="meta email">%s</small>',
                esc_url($filter_url),
                esc_html($customer_display),
                esc_html($customer_email)
            );
        } elseif ($customer_email) {
            return sprintf(
                '<a href="%s">%s</a>',
                esc_url($filter_url),
                esc_html($customer_email)
            );
        }

        return esc_html($customer_display);
    }

    /**
     * Column total
     *
     * @param object $item Order item
     * @return string
     */
    protected function column_total($item): string {
        $currency = $item->currency ?? 'USD';
        $total = number_format((float)$item->total, 2, '.', ',');

        // Get currency symbol
        $symbol = $this->get_currency_symbol($currency);

        return sprintf(
            '<strong>%s%s</strong>',
            esc_html($symbol),
            esc_html($total)
        );
    }

    /**
     * Column actions
     *
     * @param object $item Order item
     * @return string
     */
    protected function column_actions($item): string {
        $view_url = add_query_arg([
            'page'     => 'wc-multi-store-remote-orders',
            'action'   => 'view',
            'order_id' => $item->id,
        ], admin_url('admin.php'));

        return sprintf(
            '<a href="%s" class="button button-small">%s</a>',
            esc_url($view_url),
            __('View', 'wc-multi-store-sync')
        );
    }

    /**
     * Get currency symbol
     *
     * @param string $currency Currency code
     * @return string
     */
    private function get_currency_symbol(string $currency): string {
        $symbol = html_entity_decode(get_woocommerce_currency_symbol($currency), ENT_QUOTES, 'UTF-8');

        // get_woocommerce_currency_symbol() falls back to the bare currency code
        // for currencies it has no symbol for — add a separating space in that
        // case only, so e.g. "BGN100.00" doesn't run together.
        if ($symbol === $currency) {
            $symbol .= ' ';
        }

        return $symbol;
    }

    /**
     * Prepare items for display
     */
    #[\Override]
    public function prepare_items(): void {
        // Get parameters
        $per_page = $this->get_items_per_page('remote_orders_per_page', 20);
        $current_page = $this->get_pagenum();
        $orderby = sanitize_text_field($_GET['orderby'] ?? 'date_created');
        $order = sanitize_text_field($_GET['order'] ?? 'DESC');

        // Build query args
        $args = [
            'limit'  => $per_page,
            'offset' => ($current_page - 1) * $per_page,
            'orderby' => $orderby,
            'order'   => $order,
        ];

        // Apply filters
        if (!empty($_GET['store_url'])) {
            $args['store_url'] = sanitize_text_field($_GET['store_url']);
        }

        if (!empty($_GET['status'])) {
            $args['status'] = sanitize_text_field($_GET['status']);
        }

        if (!empty($_GET['customer_email'])) {
            $args['customer_email'] = sanitize_email($_GET['customer_email']);
        }

        if (!empty($_GET['date_from'])) {
            $args['date_from'] = sanitize_text_field($_GET['date_from']);
        }

        if (!empty($_GET['date_to'])) {
            $args['date_to'] = sanitize_text_field($_GET['date_to']);
        }

        // Get orders
        $this->items = WC_Multi_Store_Remote_Order_Table::get_orders($args);

        // Get total count
        $total_items = WC_Multi_Store_Remote_Order_Table::get_count($args);

        // Set pagination
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);

        // Set columns
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [$columns, $hidden, $sortable];
    }

    /**
     * Display extra tablenav
     *
     * @param string $which Top or bottom
     */
    #[\Override]
    protected function extra_tablenav($which): void {
        if ('top' !== $which) {
            return;
        }

        ?>
        <div class="alignleft actions">
            <?php
            // Store filter
            $stores = $this->get_stores();
            if (!empty($stores)) {
                ?>
                <select name="store_url" id="filter-by-store">
                    <option value=""><?php _e('All stores', 'wc-multi-store-sync'); ?></option>
                    <?php
                    $current_store = $_GET['store_url'] ?? '';
                    foreach ($stores as $store) {
                        printf(
                            '<option value="%s"%s>%s</option>',
                            esc_attr($store->remote_store_url),
                            selected($current_store, $store->remote_store_url, false),
                            esc_html(parse_url($store->remote_store_url, PHP_URL_HOST))
                        );
                    }
                    ?>
                </select>
                <?php
            }

            // Status filter
            ?>
            <select name="status" id="filter-by-status">
                <option value=""><?php _e('All statuses', 'wc-multi-store-sync'); ?></option>
                <?php
                $statuses = [
                    'pending'    => __('Pending payment', 'wc-multi-store-sync'),
                    'processing' => __('Processing', 'wc-multi-store-sync'),
                    'on-hold'    => __('On hold', 'wc-multi-store-sync'),
                    'completed'  => __('Completed', 'wc-multi-store-sync'),
                    'cancelled'  => __('Cancelled', 'wc-multi-store-sync'),
                    'refunded'   => __('Refunded', 'wc-multi-store-sync'),
                    'failed'     => __('Failed', 'wc-multi-store-sync'),
                ];

                $current_status = $_GET['status'] ?? '';
                foreach ($statuses as $status_key => $status_label) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr($status_key),
                        selected($current_status, $status_key, false),
                        esc_html($status_label)
                    );
                }
                ?>
            </select>

            <?php submit_button(__('Filter', 'wc-multi-store-sync'), 'button', 'filter_action', false); ?>
        </div>
        <?php
    }

    /**
     * Get unique stores from orders
     *
     * @return array
     */
    private function get_stores(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_mss_remote_orders';

        return $wpdb->get_results(
            "SELECT DISTINCT remote_store_url FROM {$table_name} ORDER BY remote_store_url"
        );
    }

    /**
     * Message to be displayed when there are no items
     */
    #[\Override]
    public function no_items(): void {
        _e('No remote orders found.', 'wc-multi-store-sync');
    }

    /**
     * Display the table
     */
    #[\Override]
    public function display(): void {
        $singular = $this->_args['singular'];

        ?>
        <form id="<?php echo esc_attr($singular); ?>-filter" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            <?php
            $this->search_box(__('Search orders', 'wc-multi-store-sync'), 'order');
            $this->views();
            parent::display();
            ?>
        </form>
        <?php
    }
}
