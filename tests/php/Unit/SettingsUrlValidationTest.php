<?php
/**
 * Edge case tests for WC_Multi_Store_Settings::validate_store_url()
 * Covers: ports, mixed case, trailing slashes, .test domains, empty input
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SettingsUrlValidationTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
    }

    // ── URLs with ports ──────────────────────────────────────────

    public function test_validate_url_with_https_and_port(): void
    {
        $url = 'https://store.example.com:8443';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'store.example.com', 'port' => 8443]);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertFalse(is_wp_error($result));
        $this->assertEquals($url, $result);
    }

    public function test_validate_url_with_http_and_port_non_local_fails(): void
    {
        $url = 'http://store.example.com:8080';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'http', 'host' => 'store.example.com', 'port' => 8080]);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('insecure_url', $result->get_error_code());
    }

    public function test_validate_url_localhost_with_port_allows_http(): void
    {
        $url = 'http://localhost:8080/shop';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'http', 'host' => 'localhost', 'port' => 8080]);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertFalse(is_wp_error($result));
        $this->assertEquals($url, $result);
    }

    // ── .test domains ────────────────────────────────────────────

    public function test_validate_url_allows_http_for_test_domain(): void
    {
        $url = 'http://myshop.test';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'http', 'host' => 'myshop.test']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertFalse(is_wp_error($result));
    }

    // ── 127.0.0.1 ────────────────────────────────────────────────

    public function test_validate_url_allows_http_for_127_0_0_1(): void
    {
        $url = 'http://127.0.0.1/shop';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'http', 'host' => '127.0.0.1']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertFalse(is_wp_error($result));
    }

    // ── Trailing slash normalization ─────────────────────────────

    public function test_validate_url_strips_trailing_slash(): void
    {
        $url = 'https://store.com/';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'store.com']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertEquals('https://store.com', $result);
    }

    public function test_validate_url_strips_multiple_trailing_slashes(): void
    {
        $url = 'https://store.com///';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'store.com']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertEquals('https://store.com', $result);
    }

    public function test_validate_url_keeps_path_but_strips_final_slash(): void
    {
        $url = 'https://store.com/sub/path/';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'store.com']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertEquals('https://store.com/sub/path', $result);
    }

    // ── Empty / whitespace input ─────────────────────────────────

    public function test_validate_url_rejects_empty_string(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn('');

        $result = WC_Multi_Store_Settings::validate_store_url('');

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_url', $result->get_error_code());
    }

    public function test_validate_url_rejects_whitespace_only(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn('');

        $result = WC_Multi_Store_Settings::validate_store_url('   ');

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_url', $result->get_error_code());
    }

    // ── Valid HTTPS URL ──────────────────────────────────────────

    public function test_validate_url_accepts_valid_https(): void
    {
        $url = 'https://shop.example.com';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'shop.example.com']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertFalse(is_wp_error($result));
        $this->assertEquals($url, $result);
    }

    // ── URL with path ────────────────────────────────────────────

    public function test_validate_url_with_subdirectory_path(): void
    {
        $url = 'https://example.com/woocommerce';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'example.com']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertFalse(is_wp_error($result));
        $this->assertEquals($url, $result);
    }

    // ── update_store uses validate_store_url ─────────────────────

    public function test_update_store_rejects_invalid_url(): void
    {
        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn('');

        $result = WC_Multi_Store_Settings::update_store('not-a-url', ['status' => 'active']);

        $this->assertTrue(is_wp_error($result));
    }

    public function test_update_store_normalizes_url_key(): void
    {
        $url = 'https://store.com/';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'store.com']);

        Functions\expect('get_option')
            ->once()
            ->with('wc_multi_store_sync_stores', [])
            ->andReturn([]);

        // update_option should be called with normalized URL (no trailing slash)
        Functions\expect('update_option')
            ->once()
            ->with('wc_multi_store_sync_stores', \Mockery::on(function ($stores) {
                return array_key_exists('https://store.com', $stores);
            }))
            ->andReturn(true);

        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $result = WC_Multi_Store_Settings::update_store($url, ['status' => 'active']);

        $this->assertTrue($result);
    }

    // ── SSRF guard on literal link-local IPs (persistence path) ───

    public function test_validate_url_rejects_literal_link_local_ipv4(): void
    {
        // 169.254.169.254 is the AWS/GCP/Azure cloud-metadata IP — must be
        // rejected at save/import time, not just at "Test Connection" time.
        // Uses https so this exercises the new SSRF check specifically,
        // rather than the separate (pre-existing) HTTPS-required check.
        $url = 'https://169.254.169.254/';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => '169.254.169.254']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('unsafe_url', $result->get_error_code());
    }

    public function test_validate_url_rejects_literal_link_local_ipv6(): void
    {
        $url = 'https://[fe80::1]/';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => 'fe80::1']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('unsafe_url', $result->get_error_code());
    }

    public function test_validate_url_allows_private_range_ip(): void
    {
        // Private ranges (192.168/16, 10/8) are intentionally allowed — the
        // plugin officially supports self-hosted/LAN dev environments. Only
        // link-local (169.254/16, fe80::/10) is blocked. Uses https since the
        // http-allowed exception list only covers localhost/.local/.test, not
        // arbitrary private IPs — this test isolates the SSRF check from the
        // separate HTTPS-required check.
        $url = 'https://192.168.1.50/';

        Functions\expect('esc_url_raw')
            ->once()
            ->andReturn($url);
        Functions\expect('wp_parse_url')
            ->once()
            ->andReturn(['scheme' => 'https', 'host' => '192.168.1.50']);

        $result = WC_Multi_Store_Settings::validate_store_url($url);

        $this->assertFalse(is_wp_error($result));
        // Trailing slash is stripped by validate_store_url() (see
        // test_validate_url_strips_trailing_slash above) — not related to
        // the SSRF check itself.
        $this->assertEquals('https://192.168.1.50', $result);
    }
}
