<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Security and input handling of the health-check AJAX endpoints.
 *
 * These handlers make the server dial admin-supplied store URLs, so the nonce,
 * capability and "store must be configured" gates must hold before any request
 * is made.
 */
class HealthCheckAjaxTest extends WC_Multi_Store_TestCase
{
    private const STORE_URL = 'https://store-a.example.com';

    /** @var array{success: bool, data: mixed}|null */
    private ?array $sent = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = null;
        $_POST      = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('esc_url_raw')->alias(fn($v) => (string) $v);
        Functions\when('wp_send_json_success')->alias(function ($data) {
            $this->sent = ['success' => true, 'data' => $data];
        });
        Functions\when('wp_send_json_error')->alias(function ($data) {
            $this->sent = ['success' => false, 'data' => $data];
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function allowRequest(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
    }

    /** Health check whose store lookups never hit the network. */
    private function healthCheckWithoutNetwork(): \Mockery\MockInterface
    {
        return \Mockery::mock(WC_Multi_Store_Health_Check::class)->makePartial();
    }

    // ── ajax_run_health_check ──────────────────────────────────────────────

    public function test_run_health_check_rejects_bad_nonce_without_checking_any_store(): void
    {
        Functions\expect('check_ajax_referer')->once()->with('wc_mss_run_health_check', 'nonce', false)->andReturn(false);
        Functions\when('current_user_can')->justReturn(true);
        $check = $this->healthCheckWithoutNetwork();
        $check->shouldReceive('run_health_check')->never();

        $check->ajax_run_health_check();

        $this->assertFalse($this->sent['success']);
        $this->assertSame(['message' => 'Invalid nonce'], $this->sent['data']);
    }

    public function test_run_health_check_rejects_users_without_capability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\expect('current_user_can')->once()->with('manage_woocommerce')->andReturn(false);
        $check = $this->healthCheckWithoutNetwork();
        $check->shouldReceive('run_health_check')->never();

        $check->ajax_run_health_check();

        $this->assertFalse($this->sent['success']);
        $this->assertSame(['message' => 'Insufficient permissions'], $this->sent['data']);
    }

    public function test_run_health_check_summarises_healthy_and_total_counts(): void
    {
        $this->allowRequest();
        $results = [
            'https://a.example.com' => ['healthy' => true],
            'https://b.example.com' => ['healthy' => false],
            'https://c.example.com' => ['healthy' => true],
        ];
        $check = $this->healthCheckWithoutNetwork();
        $check->shouldReceive('run_health_check')->once()->andReturn($results);

        $check->ajax_run_health_check();

        $this->assertTrue($this->sent['success']);
        $this->assertSame('2 of 3 stores are healthy', $this->sent['data']['message']);
        $this->assertSame(2, $this->sent['data']['healthy_count']);
        $this->assertSame(3, $this->sent['data']['total_count']);
        $this->assertSame($results, $this->sent['data']['results']);
    }

    public function test_run_health_check_with_no_stores_reports_zero_of_zero(): void
    {
        $this->allowRequest();
        $check = $this->healthCheckWithoutNetwork();
        $check->shouldReceive('run_health_check')->andReturn([]);

        $check->ajax_run_health_check();

        $this->assertTrue($this->sent['success']);
        $this->assertSame('0 of 0 stores are healthy', $this->sent['data']['message']);
    }

    // ── ajax_check_single_store ────────────────────────────────────────────

    public function test_single_store_rejects_bad_nonce_before_reading_input(): void
    {
        Functions\expect('check_ajax_referer')->once()->with('wc_mss_check_single_store', 'nonce', false)->andReturn(false);
        Functions\expect('esc_url_raw')->never();
        $_POST['store_url'] = self::STORE_URL;

        (new WC_Multi_Store_Health_Check())->ajax_check_single_store();

        $this->assertSame(['message' => 'Invalid nonce'], $this->sent['data']);
    }

    public function test_single_store_rejects_users_without_capability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        $_POST['store_url'] = self::STORE_URL;

        (new WC_Multi_Store_Health_Check())->ajax_check_single_store();

        $this->assertFalse($this->sent['success']);
        $this->assertSame(['message' => 'Insufficient permissions'], $this->sent['data']);
    }

    public static function missingStoreUrlProvider(): array
    {
        return [
            'absent'       => [null],
            'empty string' => [''],
            'array'        => [['https://evil.example.com']],
            'integer'      => [42],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingStoreUrlProvider')]
    public function test_single_store_requires_a_string_store_url(mixed $value): void
    {
        $this->allowRequest();
        if ($value !== null) {
            $_POST['store_url'] = $value;
        }

        (new WC_Multi_Store_Health_Check())->ajax_check_single_store();

        $this->assertFalse($this->sent['success']);
        $this->assertSame(['message' => 'Store URL is required'], $this->sent['data']);
    }

    public function test_single_store_refuses_urls_that_are_not_configured_stores(): void
    {
        $this->allowRequest();
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        WC_Multi_Store_Settings::clear_static_cache();
        $_POST['store_url'] = 'http://169.254.169.254/latest/meta-data';

        // A real Health_Check would dial the URL if the lookup were skipped.
        $check = $this->healthCheckWithoutNetwork();
        $check->shouldAllowMockingProtectedMethods();

        $check->ajax_check_single_store();

        $this->assertFalse($this->sent['success']);
        $this->assertSame(['message' => 'Store not found'], $this->sent['data']);
    }
}
