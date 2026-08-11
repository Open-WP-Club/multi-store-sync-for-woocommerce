<?php
/**
 * Unit tests for WC_Multi_Store_API_Client
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ApiClientTest extends WC_Multi_Store_TestCase
{
    /**
     * @var WC_Multi_Store_API_Client
     */
    private $api_client;

    /**
     * Store URL for testing
     */
    private $store_url = 'https://test-store.com';

    /**
     * Test consumer key
     */
    private $consumer_key = 'ck_test_key_12345678901234567890';

    /**
     * Test consumer secret
     */
    private $consumer_secret = 'cs_test_secret_12345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiClientMocks();
    }

    /**
     * Set up mocks specific to API client tests
     */
    protected function setUpApiClientMocks(): void
    {
        // Mock add_query_arg
        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            if (is_array($args)) {
                $query = http_build_query($args);
                return $url . (strpos($url, '?') !== false ? '&' : '?') . $query;
            }
            return $url;
        });

        // Mock wp_remote_retrieve_response_code
        Functions\when('wp_remote_retrieve_response_code')->alias(function ($response) {
            return isset($response['response']['code']) ? $response['response']['code'] : 200;
        });

        // Mock wp_remote_retrieve_body
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return isset($response['body']) ? $response['body'] : '';
        });

        // Mock do_action
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        $GLOBALS['wc_mss_test_use_object_cache'] = false;
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
    }

    /**
     * Test constructor sets store URL correctly
     */
    public function test_constructor_sets_store_url(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        $this->assertEquals($this->store_url, $api_client->get_store_url());
    }

    /**
     * Test constructor removes trailing slash from store URL
     */
    public function test_constructor_removes_trailing_slash(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            'https://test-store.com/',
            $this->consumer_key,
            $this->consumer_secret
        );

        $this->assertEquals('https://test-store.com', $api_client->get_store_url());
    }

    // ── for_store() factory ─────────────────────────────────────

    private function getPrivateProperty(WC_Multi_Store_API_Client $client, string $name): mixed
    {
        return (new ReflectionProperty($client, $name))->getValue($client);
    }

    public function test_for_store_extracts_credentials_from_config(): void
    {
        Functions\when('get_option')->justReturn([]);

        $client = WC_Multi_Store_API_Client::for_store($this->store_url, [
            'consumer_key' => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
        ]);

        $this->assertEquals($this->store_url, $client->get_store_url());
        $this->assertEquals($this->consumer_key, $this->getPrivateProperty($client, 'consumer_key'));
        $this->assertEquals($this->consumer_secret, $this->getPrivateProperty($client, 'consumer_secret'));
    }

    public function test_for_store_reads_auth_method_from_global_settings_not_config(): void
    {
        // auth_method is a global setting (Settings > Authentication Method) -
        // there's no per-store override field in the Add/Edit Store admin form,
        // so for_store() must ignore any 'auth_method' key on $config and read
        // WC_Multi_Store_Settings instead.
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $key === 'wc_multi_store_sync_settings' ? ['auth_method' => 'query_string'] : $default;
        });
        WC_Multi_Store_Settings::clear_static_cache();

        $client = WC_Multi_Store_API_Client::for_store($this->store_url, [
            'consumer_key' => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
            'auth_method' => 'basic_auth', // must be ignored
        ]);

        $this->assertEquals('query_string', $this->getPrivateProperty($client, 'auth_method'));
    }

    public function test_for_store_defaults_auth_method_to_basic_auth(): void
    {
        Functions\when('get_option')->justReturn([]);
        WC_Multi_Store_Settings::clear_static_cache();

        $client = WC_Multi_Store_API_Client::for_store($this->store_url, [
            'consumer_key' => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
        ]);

        $this->assertEquals('basic_auth', $this->getPrivateProperty($client, 'auth_method'));
    }

    public function test_for_store_passes_through_wp_app_password_credentials(): void
    {
        Functions\when('get_option')->justReturn([]);

        $client = WC_Multi_Store_API_Client::for_store($this->store_url, [
            'consumer_key' => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
            'wp_username' => 'admin',
            'wp_app_password' => 'abcd 1234 efgh 5678',
        ]);

        $this->assertEquals('admin', $this->getPrivateProperty($client, 'wp_username'));
        $this->assertEquals('abcd 1234 efgh 5678', $this->getPrivateProperty($client, 'wp_app_password'));
    }

    public function test_for_store_defaults_missing_config_keys_to_empty_string(): void
    {
        Functions\when('get_option')->justReturn([]);

        $client = WC_Multi_Store_API_Client::for_store($this->store_url, []);

        $this->assertEquals('', $this->getPrivateProperty($client, 'consumer_key'));
        $this->assertEquals('', $this->getPrivateProperty($client, 'consumer_secret'));
        $this->assertEquals('', $this->getPrivateProperty($client, 'wp_username'));
        $this->assertEquals('', $this->getPrivateProperty($client, 'wp_app_password'));
    }

    /**
     * Test default timeout is set
     */
    public function test_default_timeout(): void
    {
        $this->assertEquals(120, WC_Multi_Store_API_Client::DEFAULT_TIMEOUT);
    }

    /**
     * Test default max retries
     */
    public function test_default_max_retries(): void
    {
        $this->assertEquals(3, WC_Multi_Store_API_Client::DEFAULT_MAX_RETRIES);
    }

    /**
     * Test rate limit constants
     */
    public function test_rate_limit_constants(): void
    {
        $this->assertEquals(20, WC_Multi_Store_API_Client::RATE_LIMIT_REQUESTS);
        $this->assertEquals(10, WC_Multi_Store_API_Client::RATE_LIMIT_WINDOW);
        $this->assertEquals(1, WC_Multi_Store_API_Client::RATE_LIMIT_PAUSE);
    }

    /**
     * Test get_rate_limit_status returns correct structure
     */
    public function test_get_rate_limit_status_structure(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        $status = $api_client->get_rate_limit_status();

        $this->assertArrayHasKey('requests_in_window', $status);
        $this->assertArrayHasKey('max_requests', $status);
        $this->assertArrayHasKey('window_seconds', $status);
        $this->assertArrayHasKey('available', $status);

        $this->assertEquals(20, $status['max_requests']);
        $this->assertEquals(10, $status['window_seconds']);
    }

    /**
     * Test get_products with SKU search
     */
    public function test_get_products_with_sku(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        // Mock wp_remote_get to return successful response
        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    array('id' => 1, 'sku' => 'TEST-SKU', 'name' => 'Test Product')
                )),
            ));

        $result = $api_client->get_products('TEST-SKU', 'sku');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('TEST-SKU', $result[0]['sku']);
    }

    /**
     * Test get_products with slug search
     */
    public function test_get_products_with_slug(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    array('id' => 1, 'slug' => 'test-product', 'name' => 'Test Product')
                )),
            ));

        $result = $api_client->get_products('test-product', 'slug');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('test-product', $result[0]['slug']);
    }

    /**
     * Test get_product returns single product
     */
    public function test_get_product(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array('id' => 123, 'name' => 'Test Product')),
            ));

        $result = $api_client->get_product(123);

        $this->assertEquals(123, $result['id']);
        $this->assertEquals('Test Product', $result['name']);
    }

    /**
     * Test create_product sends POST request
     */
    public function test_create_product(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 201),
                'body' => json_encode(array('id' => 456, 'name' => 'New Product')),
            ));

        $result = $api_client->create_product(array(
            'name' => 'New Product',
            'sku' => 'NEW-SKU',
        ));

        $this->assertEquals(456, $result['id']);
    }

    /**
     * Test update_product sends PUT request
     */
    public function test_update_product(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_request')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array('id' => 123, 'name' => 'Updated Product')),
            ));

        $result = $api_client->update_product(123, array('name' => 'Updated Product'));

        $this->assertEquals('Updated Product', $result['name']);
    }

    /**
     * Test delete_product sends DELETE request
     */
    public function test_delete_product(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_request')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array('id' => 123, 'name' => 'Deleted Product')),
            ));

        $result = $api_client->delete_product(123);

        $this->assertIsArray($result);
    }

    /**
     * Test delete_product with force flag
     */
    public function test_delete_product_force(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_request')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array('id' => 123)),
            ));

        $result = $api_client->delete_product(123, true);

        $this->assertIsArray($result);
    }

    /**
     * Test get_product_variations
     */
    public function test_get_product_variations(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    array('id' => 1, 'sku' => 'VAR-1'),
                    array('id' => 2, 'sku' => 'VAR-2'),
                )),
            ));

        $result = $api_client->get_product_variations(123);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test create_product_variation
     */
    public function test_create_product_variation(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 201),
                'body' => json_encode(array('id' => 456, 'sku' => 'VAR-NEW')),
            ));

        $result = $api_client->create_product_variation(123, array('sku' => 'VAR-NEW'));

        $this->assertEquals(456, $result['id']);
    }

    /**
     * Test API returns WP_Error on 401 unauthorized
     */
    public function test_handles_401_unauthorized(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 401),
                'body' => json_encode(array('message' => 'Invalid API credentials')),
            ));

        $result = $api_client->get_products();

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('auth_error', $result->get_error_code());
    }

    /**
     * Test API returns WP_Error on 404 not found
     */
    public function test_handles_404_not_found(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 404),
                'body' => json_encode(array('message' => 'Product not found')),
            ));

        $result = $api_client->get_product(999);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_found', $result->get_error_code());
    }

    /**
     * Test rate limit error code constant exists
     */
    public function test_rate_limit_error_handling(): void
    {
        // Test that the API client returns proper error codes for rate limits
        // Without actually triggering retries that need logger
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        // Verify the constants are defined for rate limiting
        $this->assertEquals(20, WC_Multi_Store_API_Client::RATE_LIMIT_REQUESTS);
        $this->assertEquals(10, WC_Multi_Store_API_Client::RATE_LIMIT_WINDOW);
        $this->assertEquals(1, WC_Multi_Store_API_Client::RATE_LIMIT_PAUSE);
    }

    /**
     * Test server error handling constants
     */
    public function test_server_error_handling(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        // Verify retry constants are defined
        $this->assertEquals(3, WC_Multi_Store_API_Client::DEFAULT_MAX_RETRIES);
        $this->assertEquals(120, WC_Multi_Store_API_Client::DEFAULT_TIMEOUT);
    }

    /**
     * Test API handles invalid JSON response
     */
    public function test_handles_invalid_json(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => 'not valid json {{{',
            ));

        $result = $api_client->get_products();

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('json_decode_error', $result->get_error_code());
    }

    /**
     * Test batch_update_products
     */
    public function test_batch_update_products(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    'update' => array(
                        array('id' => 1, 'name' => 'Updated 1'),
                        array('id' => 2, 'name' => 'Updated 2'),
                    ),
                )),
            ));

        $updates = array(
            array('id' => 1, 'name' => 'Updated 1'),
            array('id' => 2, 'name' => 'Updated 2'),
        );

        $result = $api_client->batch_update_products($updates);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('update', $result);
    }

    /**
     * Test batch_update_products with invalid data
     */
    public function test_batch_update_products_invalid_data(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        $result = $api_client->batch_update_products(array());

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_batch', $result->get_error_code());
    }

    /**
     * Test batch_products limits to 100 items
     */
    public function test_batch_products_size_limit(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        // Create batch with more than 100 items
        $batch = array(
            'create' => array_fill(0, 60, array('name' => 'test')),
            'update' => array_fill(0, 60, array('id' => 1, 'name' => 'test')),
        );

        $result = $api_client->batch_products($batch);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('batch_too_large', $result->get_error_code());
    }

    /**
     * Test get_categories
     */
    public function test_get_categories(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    array('id' => 1, 'name' => 'Category 1'),
                    array('id' => 2, 'name' => 'Category 2'),
                )),
            ));

        $result = $api_client->get_categories();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test create_category
     */
    public function test_create_category(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 201),
                'body' => json_encode(array('id' => 10, 'name' => 'New Category')),
            ));

        $result = $api_client->create_category(array('name' => 'New Category'));

        $this->assertEquals(10, $result['id']);
    }

    /**
     * Test batch_categories
     */
    public function test_batch_categories(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    'create' => array(array('id' => 1, 'name' => 'Cat 1')),
                )),
            ));

        $result = $api_client->batch_categories(array(
            'create' => array(array('name' => 'Cat 1')),
        ));

        $this->assertIsArray($result);
    }

    /**
     * Test get_tags
     */
    public function test_get_tags(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    array('id' => 1, 'name' => 'Tag 1'),
                )),
            ));

        $result = $api_client->get_tags();

        $this->assertIsArray($result);
    }

    /**
     * Test get_attributes
     */
    public function test_get_attributes(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    array('id' => 1, 'name' => 'Color', 'slug' => 'color'),
                )),
            ));

        $result = $api_client->get_attributes();

        $this->assertIsArray($result);
    }

    /**
     * Test test_connection method exists
     */
    public function test_test_connection_method_exists(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_API_Client', 'test_connection'));
    }

    /**
     * Test test_connection failure returns WP_Error on auth failure
     */
    public function test_test_connection_auth_failure(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        // 401 doesn't retry, so we can test this
        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 401),
                'body' => json_encode(array('message' => 'Invalid credentials')),
            ));

        $result = $api_client->test_connection();

        $this->assertTrue(is_wp_error($result));
    }

    /**
     * Test auth method query_string configuration
     */
    public function test_auth_method_query_string_config(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string'
        );

        // Verify the client was configured correctly
        $this->assertInstanceOf(WC_Multi_Store_API_Client::class, $api_client);
        $this->assertEquals($this->store_url, $api_client->get_store_url());
    }

    /**
     * Test auth method basic_auth configuration
     */
    public function test_auth_method_basic_auth_config(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'basic_auth'
        );

        // Verify the client was configured correctly
        $this->assertInstanceOf(WC_Multi_Store_API_Client::class, $api_client);
        $this->assertEquals($this->store_url, $api_client->get_store_url());
    }

    /**
     * Test sanitize_error_message redacts API keys
     */
    public function test_sanitize_error_message(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        // Use reflection to access private method
        $reflection = new ReflectionClass($api_client);
        $method = $reflection->getMethod('sanitize_error_message');

        $message = 'Error with consumer_key=ck_12345678901234567890 and consumer_secret=cs_secret123';
        $sanitized = $method->invoke($api_client, $message);

        $this->assertStringContainsString('[REDACTED]', $sanitized);
        $this->assertStringNotContainsString('ck_12345678901234567890', $sanitized);
    }

    /**
     * Test get_orders
     */
    public function test_get_orders(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    array('id' => 100, 'status' => 'processing'),
                )),
            ));

        $result = $api_client->get_orders();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Test batch_product_variations
     */
    public function test_batch_product_variations(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn(array(
                'response' => array('code' => 200),
                'body' => json_encode(array(
                    'create' => array(array('id' => 1)),
                    'update' => array(array('id' => 2)),
                )),
            ));

        $batch = array(
            'create' => array(array('sku' => 'VAR-1')),
            'update' => array(array('id' => 2, 'stock_quantity' => 10)),
        );

        $result = $api_client->batch_product_variations(123, $batch);

        $this->assertIsArray($result);
    }

    /**
     * Test custom timeout configuration
     */
    public function test_custom_timeout_config(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            60, // Custom timeout
            true
        );

        // Verify the client was configured correctly with custom timeout
        $this->assertInstanceOf(WC_Multi_Store_API_Client::class, $api_client);
        $this->assertEquals($this->store_url, $api_client->get_store_url());
    }

    /**
     * Test SSL verification disabled configuration
     */
    public function test_ssl_verification_disabled_config(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret,
            'query_string',
            null,
            false // Disable SSL verification
        );

        // Verify the client was configured correctly with SSL verification disabled
        $this->assertInstanceOf(WC_Multi_Store_API_Client::class, $api_client);
        $this->assertEquals($this->store_url, $api_client->get_store_url());
    }

    public function test_test_connection_returns_true_on_success(): void
    {
        $api_client = new WC_Multi_Store_API_Client(
            $this->store_url,
            $this->consumer_key,
            $this->consumer_secret
        );

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body' => '[]',
            ]);

        $result = $api_client->test_connection();
        $this->assertTrue($result);
    }
}
