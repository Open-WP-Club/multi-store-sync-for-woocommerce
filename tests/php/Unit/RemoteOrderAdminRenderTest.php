<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput -- Test doubles throw/echo fixed strings and deliberately raw markup.

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rendering / escaping / permission tests for WC_Multi_Store_Remote_Order_Admin.
 *
 * RemoteOrderAdminTest covers format_address/format_price and trivial routing;
 * this file drives the private render_* methods (via reflection) with hostile
 * remote data and asserts on the generated HTML, plus the delete branches of
 * handle_actions() (nonce and capability checks).
 *
 * esc_html/esc_attr are stubbed with htmlspecialchars; wp_kses_post is stubbed
 * with a tag allow-list so these tests prove that remote data is neutralised
 * by the plugin's escaping calls rather than relying on a no-op stub.
 */
class RemoteOrderAdminRenderTest extends WC_Multi_Store_TestCase
{
    private const XSS = '<script>alert(1)</script>';

    private WC_Multi_Store_Remote_Order_Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        WC_Multi_Store_Settings::clear_static_cache();

        if (!class_exists('WC_Multi_Store_Remote_Order_Admin', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/remote-order-admin.php';
        }

        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(function ($text) {
            echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        });
        Functions\when('wp_kses_post')->alias(fn($v) => strip_tags((string) $v, '<br><strong><em>'));
        Functions\when('date_i18n')->alias(fn($format, $ts) => gmdate($format, (int) $ts));
        Functions\when('get_woocommerce_currency_symbol')->alias(fn($c) => ['USD' => '$', 'EUR' => '&euro;'][$c] ?? $c);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://site.test/wp-admin/' . $p);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('wp_create_nonce')->alias(fn($a) => 'nonce-' . $a);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));

        $this->admin = new WC_Multi_Store_Remote_Order_Admin();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    // ─── helpers ───────────────────────────────────

    private function render(string $method, ...$args): string
    {
        $ref = new ReflectionMethod($this->admin, $method);
        ob_start();
        try {
            $ref->invoke($this->admin, ...$args);
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function order(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 5,
            'remote_order_id' => 1001,
            'order_number' => '1001',
            'remote_store_url' => 'https://shop.example.com/store',
            'status' => 'wc-processing',
            'currency' => 'USD',
            'subtotal' => 100.0,
            'shipping_total' => 0.0,
            'discount_total' => 0.0,
            'tax_total' => 0.0,
            'total' => 100.0,
            'date_created' => '2024-03-05 10:30:00',
            'date_paid' => null,
            'date_completed' => null,
            'payment_method' => '',
            'payment_method_title' => '',
            'transaction_id' => '',
            'synced_at' => '2024-03-06 08:00:00',
            'customer_name' => '',
            'customer_email' => '',
            'billing_address' => null,
            'shipping_address' => null,
            'items' => [],
        ], $overrides);
    }

    private function item(array $overrides = []): object
    {
        return (object) array_merge([
            'product_name' => 'Widget',
            'product_sku' => 'W-1',
            'quantity' => 2,
            'subtotal' => 20.0,
            'total' => 18.0,
            'meta_data' => [],
        ], $overrides);
    }

    private function fakeWpdb(?object $order, array $items = [], array $stats = [], bool $deleteOk = true): object
    {
        return new class($order, $items, $stats, $deleteOk) extends wpdb {
            public array $deleted = [];
            public function __construct(private ?object $order, private array $items, private array $stats, private bool $deleteOk) {}
            public function get_row(string $query, string $output = OBJECT): mixed
            {
                return $output === ARRAY_A ? ($this->stats ?: null) : $this->order;
            }
            public function get_results(string $query, string $output = OBJECT): array|object|null
            {
                return $output === ARRAY_A ? [] : $this->items;
            }
            public function delete(string $table, array $where, ?array $where_format = null): int|false
            {
                $this->deleted[] = [$table, $where];
                return $this->deleteOk ? 1 : false;
            }
        };
    }

    /** Order as WC_Multi_Store_Remote_Order_Table::get() would return it (JSON columns still strings). */
    private function dbOrder(array $overrides = []): object
    {
        return $this->order(array_merge([
            'billing_address' => '[]',
            'shipping_address' => '[]',
            'line_items' => '[]',
            'order_meta' => '[]',
        ], $overrides));
    }

    private function dbItem(array $overrides = []): object
    {
        return $this->item(array_merge(['meta_data' => '[]'], $overrides));
    }

    // ─── render_order_info_box ─────────────────────

    public function test_info_box_shows_core_fields(): void
    {
        $html = $this->render('render_order_info_box', $this->order());

        $this->assertStringContainsString('Order Info', $html);
        $this->assertMatchesRegularExpression('/Remote Order ID:<\/strong>\s*1001/', $html);
        $this->assertMatchesRegularExpression('/Order Number:<\/strong>\s*1001/', $html);
        $this->assertMatchesRegularExpression('/Status:<\/strong>\s*Processing/', $html);
        $this->assertMatchesRegularExpression('/Date Created:<\/strong>\s*March 5, 2024 10:30 am/', $html);
        $this->assertMatchesRegularExpression('/Synced At:<\/strong>\s*March 6, 2024 8:00 am/', $html);
    }

    public function test_info_box_store_link_shows_host_only_and_url_as_href(): void
    {
        $html = $this->render('render_order_info_box', $this->order());

        $this->assertStringContainsString('href="https://shop.example.com/store"', $html);
        $this->assertMatchesRegularExpression('/>\s*shop\.example\.com\s*<\/a>/', $html);
    }

    public function test_info_box_url_passes_through_esc_url(): void
    {
        $seen = [];
        Functions\when('esc_url')->alias(function ($url) use (&$seen) {
            $seen[] = $url;
            return preg_match('#^(https?|mailto):#', $url) ? $url : '';
        });

        $html = $this->render('render_order_info_box', $this->order(['remote_store_url' => 'javascript:alert(1)']));

        $this->assertSame(['javascript:alert(1)'], $seen);
        $this->assertStringContainsString('<a href=""', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_info_box_hides_optional_rows_when_empty(): void
    {
        $html = $this->render('render_order_info_box', $this->order());

        $this->assertStringNotContainsString('Date Paid', $html);
        $this->assertStringNotContainsString('Date Completed', $html);
        $this->assertStringNotContainsString('Payment Method', $html);
        $this->assertStringNotContainsString('Transaction ID', $html);
    }

    public function test_info_box_shows_optional_rows_when_present(): void
    {
        $html = $this->render('render_order_info_box', $this->order([
            'date_paid' => '2024-03-05 11:00:00',
            'date_completed' => '2024-03-07 15:45:00',
            'payment_method' => 'stripe',
            'payment_method_title' => 'Credit Card',
            'transaction_id' => 'txn_123',
        ]));

        $this->assertMatchesRegularExpression('/Date Paid:<\/strong>\s*March 5, 2024 11:00 am/', $html);
        $this->assertMatchesRegularExpression('/Date Completed:<\/strong>\s*March 7, 2024 3:45 pm/', $html);
        $this->assertMatchesRegularExpression('/Payment Method:<\/strong>\s*Credit Card/', $html);
        $this->assertMatchesRegularExpression('/Transaction ID:<\/strong>\s*txn_123/', $html);
    }

    public function test_info_box_payment_method_falls_back_to_id_without_title(): void
    {
        $html = $this->render('render_order_info_box', $this->order(['payment_method' => 'bacs']));

        $this->assertMatchesRegularExpression('/Payment Method:<\/strong>\s*bacs/', $html);
    }

    #[DataProvider('statusProvider')]
    public function test_info_box_status_label(string $raw, string $expected): void
    {
        $html = $this->render('render_order_info_box', $this->order(['status' => $raw]));

        $this->assertMatchesRegularExpression('/Status:<\/strong>\s*' . preg_quote($expected, '/') . '\s*<\/li>/', $html);
    }

    public static function statusProvider(): array
    {
        return [
            'prefixed' => ['wc-completed', 'Completed'],
            'unprefixed' => ['refunded', 'Refunded'],
            'on-hold' => ['wc-on-hold', 'On-hold'],
        ];
    }

    public function test_info_box_escapes_hostile_remote_data(): void
    {
        $html = $this->render('render_order_info_box', $this->order([
            'remote_order_id' => self::XSS,
            'order_number' => '<img src=x onerror=1>',
            'status' => 'wc-' . self::XSS,
            'payment_method' => 'x',
            'payment_method_title' => '"><svg onload=1>',
            'transaction_id' => '<b>t</b>',
            'remote_store_url' => 'https://evil.test/"><script>1</script>',
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('<b>t</b>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=1&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;svg onload=1&gt;', $html);
    }

    // ─── render_customer_box ───────────────────────

    public function test_customer_box_renders_nothing_without_name_and_email(): void
    {
        $this->assertSame('', $this->render('render_customer_box', $this->order()));
    }

    public function test_customer_box_name_only_has_no_email_row(): void
    {
        $html = $this->render('render_customer_box', $this->order(['customer_name' => 'Jane Roe']));

        $this->assertMatchesRegularExpression('/Name:<\/strong>\s*Jane Roe/', $html);
        $this->assertStringNotContainsString('Email:', $html);
        $this->assertStringNotContainsString('mailto:', $html);
    }

    public function test_customer_box_email_only_has_no_name_row_and_mailto_link(): void
    {
        $html = $this->render('render_customer_box', $this->order(['customer_email' => 'jane@example.com']));

        $this->assertStringNotContainsString('Name:', $html);
        $this->assertStringContainsString('<a href="mailto:jane@example.com">', $html);
        $this->assertMatchesRegularExpression('/>\s*jane@example\.com\s*<\/a>/', $html);
    }

    public function test_customer_box_escapes_hostile_name_and_email_in_attribute_and_text(): void
    {
        $html = $this->render('render_customer_box', $this->order([
            'customer_name' => self::XSS,
            'customer_email' => '"><script>x</script>@e.com',
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="mailto:&quot;&gt;&lt;script&gt;x&lt;/script&gt;@e.com"', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ─── render_billing_box / render_shipping_box ──

    public static function addressBoxes(): array
    {
        return [
            'billing' => ['render_billing_box', 'billing_address', 'Billing Address'],
            'shipping' => ['render_shipping_box', 'shipping_address', 'Shipping Address'],
        ];
    }

    #[DataProvider('addressBoxes')]
    public function test_address_box_renders_nothing_for_empty_address(string $method, string $field, string $heading = ''): void
    {
        $this->assertSame('', $this->render($method, $this->order([$field => []])));
        $this->assertSame('', $this->render($method, $this->order([$field => null])));
    }

    #[DataProvider('addressBoxes')]
    public function test_address_box_renders_heading_and_formatted_address(string $method, string $field, string $heading): void
    {
        $html = $this->render($method, $this->order([$field => [
            'first_name' => 'Jane', 'last_name' => 'Roe', 'address_1' => '1 Main St',
            'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG',
        ]]));

        $this->assertStringContainsString($heading, $html);
        $this->assertStringContainsString('<div class="address">', $html);
        $this->assertStringContainsString('Jane Roe<br>1 Main St<br>Sofia, 1000<br>BG', $html);
    }

    #[DataProvider('addressBoxes')]
    public function test_address_box_neutralises_hostile_address_fields(string $method, string $field, string $heading = ''): void
    {
        $html = $this->render($method, $this->order([$field => [
            'first_name' => self::XSS,
            'last_name' => '<img src=x onerror=1>',
            'company' => '<iframe src=//evil></iframe>',
            'address_1' => '"><svg onload=1>',
            'city' => '<a href="javascript:1">c</a>',
            'phone' => '<script>p</script>',
            'email' => '<b onmouseover=1>e</b>',
        ]]));

        foreach (['<script', '<img', '<iframe', '<svg', '<a href', '<b onmouseover'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Unescaped {$needle} reached output");
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &lt;img src=x onerror=1&gt;', $html);
    }

    #[DataProvider('addressBoxes')]
    public function test_address_box_values_pass_through_kses(string $method, string $field, string $heading = ''): void
    {
        $seen = [];
        Functions\when('wp_kses_post')->alias(function ($v) use (&$seen) {
            $seen[] = $v;
            return 'SANITISED';
        });

        $html = $this->render($method, $this->order([$field => ['city' => 'Sofia']]));

        $this->assertSame(['Sofia'], $seen);
        $this->assertStringContainsString('<div class="address">', $html);
        $this->assertStringContainsString('SANITISED', $html);
    }

    // ─── render_order_items ────────────────────────

    public function test_items_table_with_no_items_has_headers_and_totals_only(): void
    {
        $html = $this->render('render_order_items', $this->order());

        $this->assertStringContainsString('woocommerce_order_items', $html);
        $this->assertSame(0, substr_count($html, '<tr class="item">'));
        $this->assertMatchesRegularExpression('/Subtotal:<\/td>\s*<td class="total">\$100\.00/', $html);
        $this->assertMatchesRegularExpression('/Order Total:<\/strong><\/td>\s*<td class="total"><strong>\$100\.00/', $html);
    }

    public function test_items_table_ignores_non_array_items(): void
    {
        $html = $this->render('render_order_items', $this->order(['items' => null]));

        $this->assertSame(0, substr_count($html, '<tr class="item">'));
    }

    public function test_items_row_shows_name_sku_unit_price_qty_and_line_total(): void
    {
        $html = $this->render('render_order_items', $this->order(['items' => [
            $this->item(['product_name' => 'Widget', 'product_sku' => 'W-1', 'quantity' => 4, 'subtotal' => 30.0, 'total' => 27.5]),
        ]]));

        $this->assertStringContainsString('<strong>Widget</strong>', $html);
        $this->assertStringContainsString('SKU: W-1', $html);
        $this->assertMatchesRegularExpression('/class="item_cost">\s*<div class="view">\s*\$7\.50/', $html);
        $this->assertMatchesRegularExpression('/class="quantity">\s*<div class="view">\s*4\s/', $html);
        $this->assertMatchesRegularExpression('/class="line_cost">\s*<div class="view">\s*\$27\.50/', $html);
    }

    public function test_items_row_omits_sku_block_when_empty(): void
    {
        $html = $this->render('render_order_items', $this->order(['items' => [$this->item(['product_sku' => ''])]]));

        $this->assertStringNotContainsString('wc-order-item-sku', $html);
    }

    public function test_items_row_with_zero_quantity_does_not_divide_by_zero(): void
    {
        $html = $this->render('render_order_items', $this->order(['items' => [
            $this->item(['quantity' => 0, 'subtotal' => 10.0, 'total' => 0.0]),
        ]]));

        $this->assertMatchesRegularExpression('/class="item_cost">\s*<div class="view">\s*\$0\.00/', $html);
    }

    public function test_items_row_renders_meta_pairs_and_skips_incomplete_ones(): void
    {
        $html = $this->render('render_order_items', $this->order(['items' => [
            $this->item(['meta_data' => [
                ['key' => 'Color', 'value' => 'Red'],
                ['key' => 'NoValue'],
                ['value' => 'NoKey'],
                ['key' => 'Size', 'value' => 'L'],
            ]]),
        ]]));

        $this->assertStringContainsString('<small>Color: Red</small>', $html);
        $this->assertStringContainsString('<small>Size: L</small>', $html);
        $this->assertStringNotContainsString('NoValue', $html);
        $this->assertStringNotContainsString('NoKey', $html);
    }

    public function test_items_row_without_meta_has_no_meta_block(): void
    {
        $html = $this->render('render_order_items', $this->order(['items' => [$this->item(['meta_data' => null])]]));

        $this->assertStringNotContainsString('wc-order-item-meta', $html);
    }

    public function test_items_row_escapes_hostile_name_sku_and_meta(): void
    {
        $html = $this->render('render_order_items', $this->order(['items' => [
            $this->item([
                'product_name' => self::XSS,
                'product_sku' => '"><img src=x onerror=1>',
                'meta_data' => [['key' => '<b>k</b>', 'value' => '<i onclick=1>v</i>']],
            ]),
        ]]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>k</b>', $html);
        $this->assertStringNotContainsString('<i onclick', $html);
        $this->assertStringContainsString('<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>', $html);
        $this->assertStringContainsString('&lt;b&gt;k&lt;/b&gt;: &lt;i onclick=1&gt;v&lt;/i&gt;', $html);
    }

    public function test_totals_hide_zero_shipping_discount_and_tax(): void
    {
        $html = $this->render('render_order_items', $this->order());

        $this->assertStringNotContainsString('Shipping:', $html);
        $this->assertStringNotContainsString('Discount:', $html);
        $this->assertStringNotContainsString('Tax:', $html);
    }

    public function test_totals_show_nonzero_shipping_discount_and_tax_with_discount_negated(): void
    {
        $html = $this->render('render_order_items', $this->order([
            'shipping_total' => 5.0, 'discount_total' => 10.0, 'tax_total' => 8.4, 'total' => 103.4,
        ]));

        $this->assertMatchesRegularExpression('/Shipping:<\/td>\s*<td class="total">\$5\.00/', $html);
        $this->assertMatchesRegularExpression('/Discount:<\/td>\s*<td class="total">-\$10\.00/', $html);
        $this->assertMatchesRegularExpression('/Tax:<\/td>\s*<td class="total">\$8\.40/', $html);
        $this->assertMatchesRegularExpression('/Order Total:<\/strong><\/td>\s*<td class="total"><strong>\$103\.40/', $html);
    }

    public function test_items_use_order_currency_and_thousands_separator(): void
    {
        $html = $this->render('render_order_items', $this->order([
            'currency' => 'BGN', 'subtotal' => 1234.5, 'total' => 1234.5,
        ]));

        $this->assertStringContainsString('BGN 1,234.50', $html);
    }

    // ─── render_order_details ──────────────────────

    public function test_details_dies_when_order_not_found(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        $_GET['order_id'] = '99';
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die:' . $msg);
        });

        try {
            $this->render('render_order_details');
            $this->fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die:Order not found', $e->getMessage());
        }
    }

    public function test_details_without_order_id_looks_up_id_zero_and_dies(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException($msg);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order not found');

        $this->render('render_order_details');
    }

    public function test_details_renders_all_sections_for_valid_order(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(
            $this->dbOrder([
                'customer_name' => 'Jane',
                'customer_email' => 'jane@example.com',
                'billing_address' => json_encode(['city' => 'Sofia']),
                'shipping_address' => json_encode(['city' => 'Varna']),
            ]),
            [$this->dbItem(['product_name' => 'Gadget'])]
        );
        $_GET['order_id'] = '5';

        $html = $this->render('render_order_details');

        $this->assertStringContainsString('Remote Order #1001', $html);
        $this->assertStringContainsString('Gadget', $html);
        $this->assertStringContainsString('Order Info', $html);
        $this->assertStringContainsString('Customer', $html);
        $this->assertStringContainsString('Billing Address', $html);
        $this->assertStringContainsString('Shipping Address', $html);
        $this->assertStringContainsString('Sofia', $html);
        $this->assertStringContainsString('Varna', $html);
        $this->assertStringContainsString('href="https://site.test/wp-admin/admin.php?page=wc-multi-store-remote-orders"', $html);
    }

    public function test_details_omits_empty_sidebar_boxes(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb($this->dbOrder());
        $_GET['order_id'] = '5';

        $html = $this->render('render_order_details');

        $this->assertStringContainsString('Order Info', $html);
        $this->assertStringNotContainsString('Billing Address', $html);
        $this->assertStringNotContainsString('Shipping Address', $html);
        $this->assertStringNotContainsString('<h2>Customer</h2>', $html);
    }

    public function test_details_heading_escapes_order_number(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb($this->dbOrder(['order_number' => self::XSS]));
        $_GET['order_id'] = '5';

        $html = $this->render('render_order_details');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Remote Order #&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ─── render_orders_list ────────────────────────

    private function renderList(array $stats = []): string
    {
        global $wpdb;
        if (!class_exists('WC_Multi_Store_Remote_Order_List_Table', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/remote-order-list-table.php';
        }
        Functions\when('_n')->alias(fn($single, $plural, $n) => (int) $n === 1 ? $single : $plural);
        $wpdb = $this->fakeWpdb(null, [], $stats);

        return $this->render('render_orders_list');
    }

    public function test_list_shows_statistics_boxes_with_formatting(): void
    {
        $html = $this->renderList([
            'total_orders' => 12345, 'total_revenue' => 9876.5, 'average_order_value' => 80.0, 'unique_customers' => 1200,
        ]);

        $this->assertMatchesRegularExpression('/Total Orders<\/span>\s*<span class="wc-mss-stat-value">12,345</', $html);
        $this->assertMatchesRegularExpression('/Total Revenue<\/span>\s*<span class="wc-mss-stat-value">\$9,876\.50</', $html);
        $this->assertMatchesRegularExpression('/Avg Order Value<\/span>\s*<span class="wc-mss-stat-value">\$80\.00</', $html);
        $this->assertMatchesRegularExpression('/Unique Customers<\/span>\s*<span class="wc-mss-stat-value">1,200</', $html);
    }

    public function test_list_statistics_default_to_zero_when_keys_missing(): void
    {
        $html = $this->renderList(['store_count' => 1]);

        $this->assertMatchesRegularExpression('/Total Orders<\/span>\s*<span class="wc-mss-stat-value">0</', $html);
        $this->assertMatchesRegularExpression('/Total Revenue<\/span>\s*<span class="wc-mss-stat-value">\$0\.00</', $html);
    }

    public function test_list_has_no_notice_without_deleted_param(): void
    {
        $this->assertStringNotContainsString('notice-success', $this->renderList());
    }

    public function test_list_notice_uses_singular_for_one_deleted_order(): void
    {
        $_GET['deleted'] = '1';

        $this->assertStringContainsString('<p>1 order deleted.</p>', $this->renderList());
    }

    public function test_list_notice_uses_plural_for_many_and_zero(): void
    {
        $_GET['deleted'] = '7';
        $this->assertStringContainsString('<p>7 orders deleted.</p>', $this->renderList());

        $_GET['deleted'] = '0';
        $this->assertStringContainsString('<p>0 orders deleted.</p>', $this->renderList());
    }

    public function test_list_notice_cannot_be_used_to_inject_markup(): void
    {
        $_GET['deleted'] = '<script>alert(1)</script>';

        $html = $this->renderList();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<p>0 orders deleted.</p>', $html);
    }

    // ─── render_orders_page routing ────────────────

    public function test_orders_page_routes_view_with_order_id_to_details(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb($this->dbOrder());
        $_GET = ['action' => 'view', 'order_id' => '5'];

        ob_start();
        $this->admin->render_orders_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Remote Order #1001', $html);
        $this->assertStringNotContainsString('Total Revenue', $html);
    }

    // ─── handle_actions: delete branches ───────────

    private function stubDeleteGuards(bool $nonceOk, bool $canManage): void
    {
        Functions\when('wp_verify_nonce')->justReturn($nonceOk ? 1 : false);
        Functions\when('current_user_can')->justReturn($canManage);
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die:' . $msg);
        });
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            throw new \RuntimeException('redirect:' . $url);
        });
    }

    public function test_single_delete_with_bad_nonce_dies_without_deleting(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        $this->stubDeleteGuards(false, true);
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => '5', '_wpnonce' => 'bad'];

        try {
            $this->admin->handle_actions();
            $this->fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die:Security check failed', $e->getMessage());
        }
        $this->assertSame([], $wpdb->deleted);
    }

    public function test_single_delete_verifies_nonce_bound_to_the_order_id(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        Functions\expect('wp_verify_nonce')->once()->with('tok', 'delete_remote_order_5')->andReturn(false);
        $this->stubDeleteGuardsExceptNonce();
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => '5', '_wpnonce' => 'tok'];

        $this->expectException(\RuntimeException::class);
        $this->admin->handle_actions();
    }

    private function stubDeleteGuardsExceptNonce(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die:' . $msg);
        });
    }

    public function test_single_delete_missing_nonce_dies(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        Functions\expect('wp_verify_nonce')->once()->with('', 'delete_remote_order_5')->andReturn(false);
        $this->stubDeleteGuardsExceptNonce();
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => '5'];

        $this->expectExceptionMessage('Security check failed');
        $this->admin->handle_actions();
    }

    public function test_single_delete_without_capability_dies_without_deleting(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        $this->stubDeleteGuards(true, false);
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => '5', '_wpnonce' => 'ok'];

        try {
            $this->admin->handle_actions();
            $this->fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die:You do not have permission to perform this action', $e->getMessage());
        }
        $this->assertSame([], $wpdb->deleted);
    }

    public function test_single_delete_success_deletes_items_then_order_and_redirects(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        $this->stubDeleteGuards(true, true);
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => '5', '_wpnonce' => 'ok'];

        try {
            $this->admin->handle_actions();
            $this->fail('Expected redirect');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('redirect:', $e->getMessage());
            $this->assertStringContainsString('page=wc-multi-store-remote-orders', $e->getMessage());
            $this->assertStringContainsString('deleted=1', $e->getMessage());
        }
        $this->assertCount(2, $wpdb->deleted);
        $this->assertStringEndsWith('remote_order_items', $wpdb->deleted[0][0]);
        $this->assertSame(['remote_order_id' => 5], $wpdb->deleted[0][1]);
        $this->assertSame(['id' => 5], $wpdb->deleted[1][1]);
    }

    public function test_single_delete_db_failure_does_not_redirect(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null, [], [], false);
        $this->stubDeleteGuards(true, true);
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => '5', '_wpnonce' => 'ok'];

        $this->admin->handle_actions();

        $this->assertCount(2, $wpdb->deleted, 'delete was attempted');
    }

    public function test_bulk_delete_checks_referer_then_capability(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        $checked = [];
        Functions\when('check_admin_referer')->alias(function ($action) use (&$checked) {
            $checked[] = $action;
            return 1;
        });
        $this->stubDeleteGuards(true, false);
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => ['1', '2']];

        try {
            $this->admin->handle_actions();
            $this->fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die:You do not have permission to perform this action', $e->getMessage());
        }
        $this->assertSame(['bulk-remote_orders'], $checked);
        $this->assertSame([], $wpdb->deleted);
    }

    public function test_bulk_delete_counts_only_scalar_ids_and_absints_them(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        Functions\when('check_admin_referer')->justReturn(1);
        $this->stubDeleteGuards(true, true);
        $_GET = [
            'page' => 'wc-multi-store-remote-orders', 'action' => 'delete',
            'order_id' => ['3', ['nested'], '-4', 'x9'],
        ];

        try {
            $this->admin->handle_actions();
            $this->fail('Expected redirect');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('deleted=3', $e->getMessage());
        }
        $deletedIds = array_values(array_filter(array_map(
            fn($d) => $d[1]['id'] ?? null,
            $wpdb->deleted
        ), fn($v) => $v !== null));
        $this->assertSame([3, 4, 0], $deletedIds);
    }

    public function test_bulk_delete_with_invalid_referer_never_reaches_deletion(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        Functions\when('check_admin_referer')->alias(function () {
            throw new \RuntimeException('bad referer');
        });
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'delete', 'order_id' => ['1']];

        try {
            $this->admin->handle_actions();
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('bad referer', $e->getMessage());
        }
        $this->assertSame([], $wpdb->deleted);
    }

    public function test_non_delete_action_does_nothing(): void
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb(null);
        Functions\expect('wp_verify_nonce')->never();
        Functions\expect('check_admin_referer')->never();
        $_GET = ['page' => 'wc-multi-store-remote-orders', 'action' => 'view', 'order_id' => '5'];

        $this->admin->handle_actions();

        $this->assertSame([], $wpdb->deleted);
    }
}
