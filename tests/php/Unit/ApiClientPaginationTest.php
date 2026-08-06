<?php
/**
 * Tests for API Client pagination edge cases
 *
 * Covers: get_all_paginated() with missing headers, malformed total pages,
 * JSON errors on later pages, empty first page, max_pages safety limit.
 * api-client.php lines 1167-1234
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ApiClientPaginationTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_API_Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            if (is_array($args)) {
                $query = http_build_query($args);
                return $url . (strpos($url, '?') !== false ? '&' : '?') . $query;
            }
            return $url;
        });

        Functions\when('wp_remote_retrieve_response_code')->alias(function ($response) {
            return $response['response']['code'] ?? 200;
        });

        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '';
        });

        Functions\when('wp_remote_retrieve_header')->alias(function ($response, $header) {
            return $response['headers'][$header] ?? '';
        });

        Functions\when('do_action')->justReturn(null);
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $this->client = new WC_Multi_Store_API_Client(
            'https://test-store.com',
            'ck_test_key',
            'cs_test_secret'
        );
    }

    /**
     * Helper to create a mock HTTP response
     */
    private function makeResponse(array $data, int $code = 200, array $headers = []): array
    {
        return [
            'response' => ['code' => $code],
            'body' => json_encode($data),
            'headers' => $headers,
        ];
    }

    // ── get_all_products (uses get_all_paginated) ────────────────

    public function test_pagination_stops_on_empty_first_page(): void
    {
        Functions\when('wp_remote_get')->justReturn(
            $this->makeResponse([], 200, ['x-wp-totalpages' => '1'])
        );

        $result = $this->client->get_all_products();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_pagination_with_missing_totalpages_header(): void
    {
        // First page returns less than per_page items = no more pages
        $items = array_map(fn($i) => ['id' => $i, 'name' => "Product $i"], range(1, 5));

        Functions\when('wp_remote_get')->justReturn(
            $this->makeResponse($items, 200, []) // No x-wp-totalpages header
        );

        $result = $this->client->get_all_products();

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
    }

    public function test_pagination_with_totalpages_header_fetches_all_pages(): void
    {
        $page1 = array_map(fn($i) => ['id' => $i], range(1, 100));
        $page2 = array_map(fn($i) => ['id' => $i], range(101, 150));

        $callCount = 0;
        Functions\when('wp_remote_get')->alias(function () use (&$callCount, $page1, $page2) {
            $callCount++;
            if ($callCount === 1) {
                return $this->makeResponse($page1, 200, ['x-wp-totalpages' => '2']);
            }
            return $this->makeResponse($page2, 200, ['x-wp-totalpages' => '2']);
        });

        $result = $this->client->get_all_products();

        $this->assertIsArray($result);
        $this->assertCount(150, $result);
    }

    public function test_pagination_returns_wp_error_on_network_failure(): void
    {
        Functions\when('wp_remote_get')->justReturn(
            new WP_Error('http_request_failed', 'cURL error: connection timeout')
        );

        $result = $this->client->get_all_products();

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_pagination_returns_error_on_http_error(): void
    {
        Functions\when('wp_remote_get')->justReturn(
            $this->makeResponse(['message' => 'Unauthorized'], 401)
        );

        $result = $this->client->get_all_products();

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_pagination_handles_json_error_on_later_page(): void
    {
        $page1 = array_map(fn($i) => ['id' => $i], range(1, 100));

        $callCount = 0;
        Functions\when('wp_remote_get')->alias(function () use (&$callCount, $page1) {
            $callCount++;
            if ($callCount === 1) {
                return $this->makeResponse($page1, 200, ['x-wp-totalpages' => '3']);
            }
            // Page 2 returns invalid JSON
            return [
                'response' => ['code' => 200],
                'body' => '{invalid json',
                'headers' => ['x-wp-totalpages' => '3'],
            ];
        });

        $result = $this->client->get_all_products();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('json_decode_error', $result->get_error_code());
    }

    public function test_pagination_respects_max_pages_safety_limit(): void
    {
        // Always return full page to keep pagination going
        $fullPage = array_map(fn($i) => ['id' => $i], range(1, 100));

        Functions\when('wp_remote_get')->justReturn(
            $this->makeResponse($fullPage, 200, ['x-wp-totalpages' => '100'])
        );

        // Use reflection to call get_all_paginated with max_pages=2
        $ref = new \ReflectionClass($this->client);
        $method = $ref->getMethod('get_all_paginated');

        $result = $method->invoke($this->client, 'products', [], 2);

        $this->assertIsArray($result);
        // Should have fetched exactly 2 pages worth
        $this->assertCount(200, $result);
    }

    public function test_pagination_with_malformed_totalpages_header(): void
    {
        // x-wp-totalpages = "invalid" → (int) "invalid" = 0
        // With 5 items < 100 per_page, should stop after first page
        $items = array_map(fn($i) => ['id' => $i], range(1, 5));

        Functions\when('wp_remote_get')->justReturn(
            $this->makeResponse($items, 200, ['x-wp-totalpages' => 'invalid'])
        );

        $result = $this->client->get_all_products();

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
    }

    public function test_pagination_single_page_exact_per_page_count(): void
    {
        // Returns exactly per_page items but totalpages=1
        $items = array_map(fn($i) => ['id' => $i], range(1, 100));

        Functions\when('wp_remote_get')->justReturn(
            $this->makeResponse($items, 200, ['x-wp-totalpages' => '1'])
        );

        $result = $this->client->get_all_products();

        $this->assertIsArray($result);
        $this->assertCount(100, $result);
    }
}
