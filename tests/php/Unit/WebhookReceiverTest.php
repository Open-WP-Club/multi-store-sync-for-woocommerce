<?php
/**
 * Unit tests for WC_Multi_Store_Webhook_Receiver
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class WebhookReceiverTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Webhook_Receiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebhookMocks();
        $this->receiver = new WC_Multi_Store_Webhook_Receiver();
    }

    /**
     * Set up mocks specific to webhook receiver tests
     */
    protected function setUpWebhookMocks(): void
    {
        // Mock get_option for webhook settings
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return array(
                    'enabled' => true,
                    'webhook_secret' => 'test_webhook_secret_12345',
                    'trigger_statuses' => array('processing', 'completed'),
                    'allow_negative_stock' => false,
                    'auto_verify' => false,
                );
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return array(
                    array(
                        'url' => 'https://store1.com',
                        'active' => true,
                    ),
                    array(
                        'url' => 'https://store2.com',
                        'active' => true,
                    ),
                );
            }
            return $default;
        });

        // Mock get_transient
        Functions\when('get_transient')->justReturn(0);

        // Mock set_transient
        Functions\when('set_transient')->justReturn(true);

        // Mock current_time
        Functions\when('current_time')->justReturn('2024-01-01 12:00:00');

        // Mock rest_url
        Functions\when('rest_url')->alias(function ($path) {
            return 'https://main-store.com/wp-json/' . ltrim($path, '/');
        });

        // Mock add_query_arg - handles both 2-arg (array, url) and 3-arg (key, value, url) forms
        Functions\when('add_query_arg')->alias(function ($key, $value = null, $url = null) {
            // Handle 3-argument form: add_query_arg('key', 'value', 'url')
            if (is_string($key) && $url !== null) {
                $query = urlencode($key) . '=' . urlencode($value);
                return $url . (strpos($url, '?') !== false ? '&' : '?') . $query;
            }
            // Handle 2-argument form: add_query_arg(array('key' => 'value'), 'url')
            if (is_array($key)) {
                $query = http_build_query($key);
                return $value . (strpos($value, '?') !== false ? '&' : '?') . $query;
            }
            return $value ?? $key;
        });

        // Mock untrailingslashit
        Functions\when('untrailingslashit')->alias(function ($str) {
            return rtrim($str, '/');
        });

        // Mock absint
        Functions\when('absint')->alias(function ($val) {
            return abs((int) $val);
        });

        // Mock sanitize_text_field
        Functions\when('sanitize_text_field')->alias(function ($str) {
            return trim(strip_tags($str));
        });

        // Mock add_action
        Functions\when('add_action')->justReturn(true);

        // Mock register_rest_route
        Functions\when('register_rest_route')->justReturn(true);

        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wc_delete_product_transients')->justReturn(null);
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
    }

    /**
     * Test class exists
     */
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Webhook_Receiver'));
    }

    /**
     * Test rate limit constants
     */
    public function test_rate_limit_constants(): void
    {
        $this->assertEquals(100, WC_Multi_Store_Webhook_Receiver::RATE_LIMIT_MAX_REQUESTS);
        $this->assertEquals(60, WC_Multi_Store_Webhook_Receiver::RATE_LIMIT_WINDOW);
    }

    /**
     * Test register_routes method exists
     */
    public function test_register_routes_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Webhook_Receiver', 'register_routes'));
    }

    /**
     * Test verify_webhook_signature method exists
     */
    public function test_verify_webhook_signature_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Webhook_Receiver', 'verify_webhook_signature'));
    }

    /**
     * Test handle_test_webhook method exists
     */
    public function test_handle_test_webhook_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Webhook_Receiver', 'handle_test_webhook'));
    }

    /**
     * Test handle_order_webhook method exists
     */
    public function test_handle_order_webhook_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Webhook_Receiver', 'handle_order_webhook'));
    }

    /**
     * Test get_webhook_url static method exists
     */
    public function test_get_webhook_url_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Webhook_Receiver', 'get_webhook_url'));
    }

    /**
     * Test get_test_webhook_url static method exists
     */
    public function test_get_test_webhook_url_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Webhook_Receiver', 'get_test_webhook_url'));
    }

    /**
     * Test get_webhook_url generates correct URL
     */
    public function test_get_webhook_url(): void
    {
        $url = WC_Multi_Store_Webhook_Receiver::get_webhook_url('https://remote-store.com');

        $this->assertStringContainsString('wc-multi-store-sync/v1/webhook/order', $url);
        $this->assertStringContainsString('store_url=', $url);
    }

    /**
     * Test get_test_webhook_url generates correct URL
     */
    public function test_get_test_webhook_url(): void
    {
        $url = WC_Multi_Store_Webhook_Receiver::get_test_webhook_url('https://remote-store.com');

        $this->assertStringContainsString('wc-multi-store-sync/v1/webhook/test', $url);
    }

    /**
     * Test get_test_webhook_url without store URL
     */
    public function test_get_test_webhook_url_without_store(): void
    {
        $url = WC_Multi_Store_Webhook_Receiver::get_test_webhook_url();

        $this->assertStringContainsString('wc-multi-store-sync/v1/webhook/test', $url);
        $this->assertStringNotContainsString('store_url=', $url);
    }

    /**
     * Test private check_rate_limit method exists
     */
    public function test_check_rate_limit_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $this->assertTrue($reflection->hasMethod('check_rate_limit'));

        $method = $reflection->getMethod('check_rate_limit');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test private get_client_ip method exists
     */
    public function test_get_client_ip_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $this->assertTrue($reflection->hasMethod('get_client_ip'));

        $method = $reflection->getMethod('get_client_ip');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test private process_stock_deduction method exists
     */
    public function test_process_stock_deduction_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $this->assertTrue($reflection->hasMethod('process_stock_deduction'));

        $method = $reflection->getMethod('process_stock_deduction');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test private deduct_stock method exists
     */
    public function test_deduct_stock_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $this->assertTrue($reflection->hasMethod('deduct_stock'));

        $method = $reflection->getMethod('deduct_stock');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test private sync_stock_to_stores method exists
     */
    public function test_sync_stock_to_stores_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $this->assertTrue($reflection->hasMethod('sync_stock_to_stores'));

        $method = $reflection->getMethod('sync_stock_to_stores');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test private is_store_registered method exists
     */
    public function test_is_store_registered_method_exists(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $this->assertTrue($reflection->hasMethod('is_store_registered'));

        $method = $reflection->getMethod('is_store_registered');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Test static methods are properly declared
     */
    public function test_static_methods(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');

        $get_webhook_url = $reflection->getMethod('get_webhook_url');
        $this->assertTrue($get_webhook_url->isStatic());

        $get_test_webhook_url = $reflection->getMethod('get_test_webhook_url');
        $this->assertTrue($get_test_webhook_url->isStatic());
    }

    /**
     * Test verify_webhook_signature signature
     */
    public function test_verify_webhook_signature_signature(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $method = $reflection->getMethod('verify_webhook_signature');

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertEquals('request', $params[0]->getName());
    }

    /**
     * Test handle_order_webhook signature
     */
    public function test_handle_order_webhook_signature(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $method = $reflection->getMethod('handle_order_webhook');

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertEquals('request', $params[0]->getName());
    }

    /**
     * Test handle_test_webhook signature
     */
    public function test_handle_test_webhook_signature(): void
    {
        $reflection = new ReflectionClass('WC_Multi_Store_Webhook_Receiver');
        $method = $reflection->getMethod('handle_test_webhook');

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertEquals('request', $params[0]->getName());
    }

    // ── deduct_stock ─────────────────────────────────────────────

    private function makeMockStockProduct(int $id, string $sku, int $stock, bool $manageStock = true): \Mockery\MockInterface
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn($id);
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_stock_quantity')->andReturn($stock);
        $product->shouldReceive('managing_stock')->andReturn($manageStock);
        $product->shouldReceive('set_stock_status')->andReturn(null);
        $product->shouldReceive('save')->andReturn(null);
        return $product;
    }

    public function test_deduct_stock_returns_error_when_stock_not_managed(): void
    {
        $product = $this->makeMockStockProduct(100, 'SKU-100', 10, false);

        $ref = new \ReflectionClass($this->receiver);
        $method = $ref->getMethod('deduct_stock');

        $result = $method->invoke($this->receiver, $product, 5, 1001, 'https://remote.com');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('stock_not_managed', $result->get_error_code());
    }

    public function test_deduct_stock_insufficient_stock_returns_error(): void
    {
        $product = $this->makeMockStockProduct(100, 'SKU-100', 5);

        Functions\when('get_option')->alias(function ($option, $default = []) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['allow_negative_stock' => false];
            }
            return $default;
        });

        $ref = new \ReflectionClass($this->receiver);
        $method = $ref->getMethod('deduct_stock');

        $result = $method->invoke($this->receiver, $product, 10, 1001, 'https://remote.com');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('insufficient_stock', $result->get_error_code());
    }

    public function test_deduct_stock_allows_negative_when_flag_set(): void
    {
        $product = $this->makeMockStockProduct(100, 'SKU-100', 5);

        Functions\when('get_option')->alias(function ($option, $default = []) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['allow_negative_stock' => true];
            }
            return $default;
        });

        Functions\when('wc_update_product_stock')->justReturn(-5);

        $updated = $this->makeMockStockProduct(100, 'SKU-100', -5);
        Functions\when('wc_get_product')->justReturn($updated);

        $ref = new \ReflectionClass($this->receiver);
        $method = $ref->getMethod('deduct_stock');

        $result = $method->invoke($this->receiver, $product, 10, 1001, 'https://remote.com');

        $this->assertNotInstanceOf(WP_Error::class, $result);
    }

    public function test_deduct_stock_race_condition_returns_error(): void
    {
        $product = $this->makeMockStockProduct(100, 'SKU-100', 10);

        Functions\when('get_option')->alias(function ($option, $default = []) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['allow_negative_stock' => false];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(0);

        $depleted = $this->makeMockStockProduct(100, 'SKU-100', 2);
        Functions\when('wc_get_product')->justReturn($depleted);

        $ref = new \ReflectionClass($this->receiver);
        $method = $ref->getMethod('deduct_stock');

        $result = $method->invoke($this->receiver, $product, 8, 1001, 'https://remote.com');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('insufficient_stock', $result->get_error_code());
        $this->assertStringContainsString('concurrent deduction', $result->get_error_message());
    }

    public function test_deduct_stock_successful_atomic_update(): void
    {
        $product = $this->makeMockStockProduct(100, 'SKU-100', 20);

        Functions\when('get_option')->alias(function ($option, $default = []) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['allow_negative_stock' => false];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->insert_id = 1;

        $updated = $this->makeMockStockProduct(100, 'SKU-100', 15);
        Functions\when('wc_get_product')->justReturn($updated);

        $ref = new \ReflectionClass($this->receiver);
        $method = $ref->getMethod('deduct_stock');

        $result = $method->invoke($this->receiver, $product, 5, 1001, 'https://remote.com');

        $this->assertNotInstanceOf(WP_Error::class, $result);
    }

    public function test_deduct_stock_exact_amount_succeeds(): void
    {
        $product = $this->makeMockStockProduct(100, 'SKU-100', 5);

        Functions\when('get_option')->alias(function ($option, $default = []) {
            if ($option === 'wc_multi_store_sync_webhook_settings') {
                return ['allow_negative_stock' => false];
            }
            return $default;
        });

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->insert_id = 1;

        $zero = $this->makeMockStockProduct(100, 'SKU-100', 0);
        Functions\when('wc_get_product')->justReturn($zero);

        $ref = new \ReflectionClass($this->receiver);
        $method = $ref->getMethod('deduct_stock');

        $result = $method->invoke($this->receiver, $product, 5, 1001, 'https://remote.com');

        $this->assertNotInstanceOf(WP_Error::class, $result);
    }
}
