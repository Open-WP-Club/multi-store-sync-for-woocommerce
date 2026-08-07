<?php
/**
 * Unit tests for WC_Multi_Store_Remote_Order_Admin
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class RemoteOrderAdminTest extends WC_Multi_Store_TestCase
{
    private ?WC_Multi_Store_Remote_Order_Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_submenu_page')->justReturn(true);
        Functions\when('get_option')->justReturn([]);

        WC_Multi_Store_Settings::clear_static_cache();

        if (!class_exists('WC_Multi_Store_Remote_Order_Admin', false)) {
            require_once WC_MSS_PLUGIN_DIR . 'includes/remote-order-admin.php';
        }

        $this->admin = new WC_Multi_Store_Remote_Order_Admin();
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Remote_Order_Admin'));
    }

    public function test_has_required_methods(): void
    {
        $methods = [
            'add_menu_items',
            'enqueue_scripts',
            'handle_actions',
            'render_orders_page',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_Remote_Order_Admin', $method),
                "Missing method: {$method}"
            );
        }
    }

    // ─── Constructor ───────────────────────────────

    public function test_constructor_completes_without_errors(): void
    {
        $admin = new WC_Multi_Store_Remote_Order_Admin();

        $this->assertInstanceOf(WC_Multi_Store_Remote_Order_Admin::class, $admin);
    }

    // ─── add_menu_items ────────────────────────────

    public function test_add_menu_items_does_not_throw(): void
    {
        // add_submenu_page is mocked in setUp
        $this->admin->add_menu_items();

        $this->assertTrue(true);
    }

    // ─── enqueue_scripts ───────────────────────────

    public function test_enqueue_scripts_skips_other_pages(): void
    {
        $this->expectNotToPerformAssertions();

        $this->admin->enqueue_scripts('edit.php');
    }

    public function test_enqueue_scripts_loads_on_remote_orders_page(): void
    {
        if (!defined('WC_MSS_PLUGIN_URL')) {
            define('WC_MSS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wc-multi-store-sync/');
        }
        if (!defined('WC_MSS_VERSION')) {
            define('WC_MSS_VERSION', '2.0.0');
        }

        Functions\expect('wp_enqueue_style')
            ->twice(); // custom CSS + WC admin styles

        $this->admin->enqueue_scripts('woocommerce_page_wc-multi-store-remote-orders');

        $this->assertTrue(true);
    }

    // ─── handle_actions ────────────────────────────

    public function test_handle_actions_skips_wrong_page(): void
    {
        $_GET = ['page' => 'some-other-page'];

        Functions\expect('wp_verify_nonce')->never();

        $this->admin->handle_actions();

        $this->assertTrue(true);
    }

    public function test_handle_actions_skips_when_no_action(): void
    {
        $_GET = ['page' => 'wc-multi-store-remote-orders'];

        Functions\expect('wp_verify_nonce')->never();

        $this->admin->handle_actions();

        $this->assertTrue(true);
    }

    // ─── format_address (private) ──────────────────

    public function test_format_address_with_full_address(): void
    {
        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_address');

        $address = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company' => 'Acme Inc',
            'address_1' => '123 Main St',
            'address_2' => 'Suite 100',
            'city' => 'New York',
            'state' => 'NY',
            'postcode' => '10001',
            'country' => 'US',
            'phone' => '555-1234',
            'email' => 'john@example.com',
        ];

        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $result = $method->invoke($this->admin, $address);

        $this->assertStringContainsString('John Doe', $result);
        $this->assertStringContainsString('Acme Inc', $result);
        $this->assertStringContainsString('123 Main St', $result);
        $this->assertStringContainsString('Suite 100', $result);
        $this->assertStringContainsString('New York', $result);
        $this->assertStringContainsString('US', $result);
        $this->assertStringContainsString('555-1234', $result);
        $this->assertStringContainsString('john@example.com', $result);
    }

    public function test_format_address_with_minimal_address(): void
    {
        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_address');

        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $address = [
            'city' => 'Sofia',
            'country' => 'BG',
        ];

        $result = $method->invoke($this->admin, $address);

        $this->assertStringContainsString('Sofia', $result);
        $this->assertStringContainsString('BG', $result);
    }

    public function test_format_address_with_empty_address(): void
    {
        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_address');

        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $result = $method->invoke($this->admin, []);

        $this->assertEquals('', $result);
    }

    // ─── format_price (private) ────────────────────

    private function mockCurrencySymbols(): void
    {
        // Mirrors real WooCommerce's get_woocommerce_currency_symbols(): most
        // symbols are returned as HTML entities, not literal UTF-8 characters —
        // regression guard for the double-escaping bug this replaced.
        Functions\when('get_woocommerce_currency_symbol')->alias(function ($currency) {
            return [
                'USD' => '&#36;',
                'EUR' => '&euro;',
                'GBP' => '&pound;',
            ][$currency] ?? $currency;
        });
    }

    public function test_format_price_with_usd(): void
    {
        $this->mockCurrencySymbols();

        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_price');

        $result = $method->invoke($this->admin, 99.99, 'USD');

        $this->assertEquals('$99.99', $result);
    }

    public function test_format_price_with_eur(): void
    {
        $this->mockCurrencySymbols();

        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_price');

        $result = $method->invoke($this->admin, 49.50, 'EUR');

        $this->assertEquals('€49.50', $result);
    }

    public function test_format_price_with_gbp(): void
    {
        $this->mockCurrencySymbols();

        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_price');

        $result = $method->invoke($this->admin, 25.00, 'GBP');

        $this->assertEquals('£25.00', $result);
    }

    public function test_format_price_with_unknown_currency(): void
    {
        // Real get_woocommerce_currency_symbol() falls back to the bare
        // currency code for currencies it has no symbol for.
        Functions\when('get_woocommerce_currency_symbol')->alias(fn($c) => $c);

        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_price');

        $result = $method->invoke($this->admin, 100.00, 'BGN');

        $this->assertEquals('BGN 100.00', $result);
    }

    public function test_format_price_with_zero(): void
    {
        $this->mockCurrencySymbols();

        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_price');

        $result = $method->invoke($this->admin, 0.00, 'USD');

        $this->assertEquals('$0.00', $result);
    }

    public function test_format_price_with_large_number(): void
    {
        $this->mockCurrencySymbols();

        $ref = new ReflectionClass($this->admin);
        $method = $ref->getMethod('format_price');

        $result = $method->invoke($this->admin, 1234567.89, 'USD');

        $this->assertEquals('$1,234,567.89', $result);
    }
}
