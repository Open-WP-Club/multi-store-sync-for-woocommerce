<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

class DashboardWidgetTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed>|null Row returned for the sync-history overall query. */
    private ?array $historyOverall = null;

    /** @var list<array<string, mixed>> */
    private array $historyDaily = [];

    /** @var array<string, mixed>|null Row returned for the queue stats query. */
    private ?array $queueRow = null;

    private int $deadLetters = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options        = [];
        $this->historyOverall = null;
        $this->historyDaily   = [];
        $this->queueRow       = null;
        $this->deadLetters    = 0;
        $_POST                = [];

        Functions\when('current_time')->justReturn('2026-01-10 12:00:00');
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('wp_parse_args')->alias(fn($args, $defaults = []) => array_merge($defaults, (array) $args));
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function mockDatabase(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => $v);
        $wpdb->shouldReceive('get_row')->andReturnUsing(function (string $sql) {
            return str_contains($sql, 'total_syncs') ? $this->historyOverall : $this->queueRow;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(function (string $sql) {
            $isDeadCount = str_contains($sql, "WHERE status = 'dead'") && str_contains($sql, 'SELECT COUNT(*)');

            return $isDeadCount ? $this->deadLetters : 0;
        });
        $wpdb->shouldReceive('get_results')->andReturnUsing(function (string $sql) {
            return str_contains($sql, 'GROUP BY DATE(created_at)') ? $this->historyDaily : [];
        });
    }

    private function overall(int $total, int $successful): array
    {
        return [
            'total_syncs'      => $total,
            'successful_syncs' => $successful,
            'failed_syncs'     => $total - $successful,
        ];
    }

    // ── constructor / register_widget ──────────────────────────────────────

    public function test_constructor_registers_hooks(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(function ($hook, $callback) use (&$hooks) {
            $hooks[$hook] = $callback;
            return true;
        });

        $widget = new WC_Multi_Store_Dashboard_Widget();

        $this->assertSame([$widget, 'register_widget'], $hooks['wp_dashboard_setup']);
        $this->assertSame([$widget, 'ajax_dashboard_stats'], $hooks['wp_ajax_wc_mss_dashboard_stats']);
        $this->assertCount(2, $hooks);
    }

    public function test_register_widget_skips_when_no_capability(): void
    {
        Functions\expect('current_user_can')->with('manage_woocommerce')->once()->andReturn(false);
        Functions\expect('wp_add_dashboard_widget')->never();

        (new WC_Multi_Store_Dashboard_Widget())->register_widget();

        // The never() expectation above is the proof: no dashboard widget without the capability.
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_register_widget_adds_widget_when_capable(): void
    {
        Functions\expect('current_user_can')->with('manage_woocommerce')->once()->andReturn(true);

        $widget = new WC_Multi_Store_Dashboard_Widget();

        Functions\expect('wp_add_dashboard_widget')
            ->once()
            ->with('wc_mss_dashboard_widget', 'Multi-Store Sync', [$widget, 'render_widget']);

        $widget->register_widget();

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    // ── render_widget ──────────────────────────────────────────────────────

    private function renderWidget(): string
    {
        $this->mockDatabase();

        Functions\when('admin_url')->alias(fn($path = '') => 'https://example.com/wp-admin/' . $path);
        Functions\stubs([
            'esc_html'           => null,
            'esc_attr'           => null,
            'esc_url'            => null,
            'esc_html__'         => null,
            'esc_html_e'         => function ($text) {
                echo $text;
            },
            'number_format_i18n' => fn($n) => (string) $n,
            'absint'             => fn($n) => abs((int) $n),
        ]);

        $level = ob_get_level();
        ob_start();
        try {
            (new WC_Multi_Store_Dashboard_Widget())->render_widget();

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function test_render_shows_sync_active_only_when_enabled(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['enabled' => true];
        $html = $this->renderWidget();
        $this->assertStringContainsString('Sync Active', $html);
        $this->assertStringContainsString('wc-mss-w-dot active', $html);
        $this->assertStringNotContainsString('Sync Disabled', $html);

        WC_Multi_Store_Settings::clear_static_cache();
        $this->options['wc_multi_store_sync_settings'] = ['enabled' => false];
        $html = $this->renderWidget();
        $this->assertStringContainsString('Sync Disabled', $html);
        $this->assertStringContainsString('wc-mss-w-dot inactive', $html);
        $this->assertStringNotContainsString('Sync Active', $html);
    }

    public function test_render_without_syncs_today_shows_dash_and_no_progress_bar(): void
    {
        $this->historyOverall = $this->overall(0, 0);

        $html = $this->renderWidget();

        $this->assertStringContainsString('&mdash;', $html);
        $this->assertStringNotContainsString('class="wc-mss-w-bar-fill"', $html);
        $this->assertStringContainsString('color: #646970;', $html);
    }

    public function test_render_healthy_success_rate_is_green(): void
    {
        $this->historyOverall = $this->overall(10, 9);

        $html = $this->renderWidget();

        $this->assertStringContainsString('90%', $html);
        $this->assertStringContainsString('color: #00a32a;', $html);
        $this->assertStringContainsString('width: 90%; background: #00a32a;', $html);
    }

    public function test_render_middling_success_rate_has_red_number_but_amber_bar(): void
    {
        $this->historyOverall = $this->overall(10, 7);

        $html = $this->renderWidget();

        $this->assertStringContainsString('70%', $html);
        $this->assertStringContainsString('color: #d63638;', $html);
        $this->assertStringContainsString('width: 70%; background: #dba617;', $html);
    }

    public function test_render_poor_success_rate_bar_is_red(): void
    {
        $this->historyOverall = $this->overall(10, 2);

        $this->assertStringContainsString('width: 20%; background: #d63638;', $this->renderWidget());
    }

    public function test_render_shows_failed_queue_warning_only_when_items_failed(): void
    {
        $this->queueRow = ['pending' => 4, 'failed' => 3, 'total' => 7];
        $html = $this->renderWidget();
        $this->assertStringContainsString('3 failed item(s) in queue', $html);
        $this->assertMatchesRegularExpression('/Queue Pending/', $html);

        $this->queueRow = ['pending' => 4, 'failed' => 0, 'total' => 4];
        $this->assertStringNotContainsString('failed item(s)', $this->renderWidget());
    }

    public function test_render_dead_letters_are_highlighted_and_linked_only_when_present(): void
    {
        $this->deadLetters = 5;
        $html = $this->renderWidget();
        $this->assertStringContainsString('section=dead-letter-queue', $html);

        $this->deadLetters = 0;
        $this->assertStringNotContainsString('section=dead-letter-queue', $this->renderWidget());
    }

    public function test_render_always_links_to_dashboard_queue_and_history(): void
    {
        $html = $this->renderWidget();

        $base = 'https://example.com/wp-admin/admin.php?page=wc-settings&tab=multi_store_sync';
        $this->assertStringContainsString('href="' . $base . '"', $html);
        $this->assertStringContainsString($base . '&section=queue', $html);
        $this->assertStringContainsString($base . '&section=history', $html);
    }

    public function test_render_shows_duplicate_sku_warning_when_products_are_blocked(): void
    {
        $this->options[WC_Multi_Store_Sku_Conflicts::OPTION] = [
            '7:abc' => ['product_id' => 7, 'store_url' => 'https://a.com', 'sku' => 'S1', 'remote_id' => 1, 'owner_remote_id' => 2, 'time' => 1],
        ];

        $this->assertStringContainsString('1 product(s) blocked by a duplicate SKU', $this->renderWidget());
    }

    public function test_render_hides_duplicate_sku_warning_when_nothing_is_blocked(): void
    {
        $this->assertStringNotContainsString('duplicate SKU', $this->renderWidget());
    }

    // ── ajax_dashboard_stats ───────────────────────────────────────────────

    /**
     * Runs the AJAX handler and returns what it sent.
     *
     * @return array{success: bool, data: mixed}|null
     */
    private function runAjax(?array $post = null): ?array
    {
        $this->mockDatabase();
        $_POST = $post ?? [];

        $sent = null;
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent) {
            $sent = ['success' => true, 'data' => $data];
        });
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$sent) {
            $sent = ['success' => false, 'data' => $data];
        });

        (new WC_Multi_Store_Dashboard_Widget())->ajax_dashboard_stats();

        return $sent;
    }

    public function test_ajax_verifies_nonce_before_anything_else(): void
    {
        Functions\expect('check_ajax_referer')->once()->with('wc_mss_admin', 'nonce')->andReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->runAjax(['days' => '7']);

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_ajax_rejects_users_without_capability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->with('manage_woocommerce')->once()->andReturn(false);

        $sent = $this->runAjax(['days' => '7']);

        $this->assertFalse($sent['success']);
        $this->assertSame(['message' => 'Unauthorized'], $sent['data']);
    }

    public function test_ajax_defaults_to_seven_days_of_chart_data(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $sent = $this->runAjax();

        $this->assertTrue($sent['success']);
        $this->assertCount(7, $sent['data']['chart']['labels']);
        $this->assertSame('Jan 4', $sent['data']['chart']['labels'][0]);
        $this->assertSame('Jan 10', $sent['data']['chart']['labels'][6]);
    }

    public static function dayClampProvider(): array
    {
        return [
            'zero becomes one'      => ['0', 1],
            'negative is absolute'  => ['-3', 3],
            'garbage becomes one'   => ['abc', 1],
            'huge is capped at 365' => ['9999', 365],
            'in range is kept'      => ['30', 30],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dayClampProvider')]
    public function test_ajax_clamps_requested_days(string $days, int $expected): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $sent = $this->runAjax(['days' => $days]);

        $this->assertCount($expected, $sent['data']['chart']['labels']);
        $this->assertCount($expected, $sent['data']['chart']['success']);
        $this->assertCount($expected, $sent['data']['chart']['failed']);
    }

    public function test_ajax_fills_days_without_data_with_zeros_and_places_data_on_its_day(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->historyDaily = [
            ['date' => '2026-01-10', 'total' => 5, 'successful' => '4', 'failed' => '1'],
            ['date' => '2026-01-08', 'total' => 2, 'successful' => '2', 'failed' => '0'],
        ];

        $sent = $this->runAjax(['days' => '4']);

        $this->assertSame(['Jan 7', 'Jan 8', 'Jan 9', 'Jan 10'], $sent['data']['chart']['labels']);
        $this->assertSame([0, 2, 0, 4], $sent['data']['chart']['success']);
        $this->assertSame([0, 0, 0, 1], $sent['data']['chart']['failed']);
    }

    public function test_ajax_ignores_daily_rows_outside_the_requested_window(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->historyDaily = [['date' => '2025-12-01', 'total' => 9, 'successful' => '9', 'failed' => '0']];

        $sent = $this->runAjax(['days' => '3']);

        $this->assertSame([0, 0, 0], $sent['data']['chart']['success']);
    }

    public function test_ajax_returns_overall_and_breakdowns_alongside_the_chart(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->historyOverall = $this->overall(4, 3);

        $sent = $this->runAjax(['days' => '2']);

        $this->assertSame(['overall', 'by_store', 'by_type', 'chart'], array_keys($sent['data']));
        $this->assertSame(75.0, (float) $sent['data']['overall']['success_rate']);
        $this->assertSame([], $sent['data']['by_store']);
        $this->assertSame([], $sent['data']['by_type']);
    }
}
