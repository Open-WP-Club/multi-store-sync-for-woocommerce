<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

class DashboardWidgetTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
    }

    // ─── class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Dashboard_Widget'));
    }

    public function test_has_register_widget_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Dashboard_Widget', 'register_widget'));
    }

    public function test_has_render_widget_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Dashboard_Widget', 'render_widget'));
    }

    public function test_has_ajax_dashboard_stats_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Dashboard_Widget', 'ajax_dashboard_stats'));
    }

    // ─── constructor ───────────────────────────────

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

    // ─── register_widget ───────────────────────────

    public function test_register_widget_skips_when_no_capability(): void
    {
        Functions\when('add_action')->justReturn(true);

        Functions\expect('current_user_can')
            ->with('manage_woocommerce')
            ->once()
            ->andReturn(false);

        Functions\expect('wp_add_dashboard_widget')->never();

        $widget = new WC_Multi_Store_Dashboard_Widget();
        $widget->register_widget();

        // The never() expectation above is the proof: no dashboard widget without the capability.
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_register_widget_adds_widget_when_capable(): void
    {
        Functions\when('add_action')->justReturn(true);

        Functions\expect('current_user_can')
            ->with('manage_woocommerce')
            ->once()
            ->andReturn(true);

        $widget = new WC_Multi_Store_Dashboard_Widget();

        Functions\expect('wp_add_dashboard_widget')
            ->once()
            ->with('wc_mss_dashboard_widget', 'Multi-Store Sync', [$widget, 'render_widget']);

        $widget->register_widget();

        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    // ─── render_widget: duplicate SKU warning ──────

    private function renderWidget(): string
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_var')->andReturn(0);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => $v);

        Functions\when('add_action')->justReturn(true);
        Functions\when('admin_url')->alias(fn($path = '') => 'https://example.com/wp-admin/' . $path);

        Functions\stubs([
            'esc_html' => null,
            'esc_attr' => null,
            'esc_url' => null,
            'esc_html__' => null,
            'esc_html_e' => function ($text) {
                echo $text;
            },
            'number_format_i18n' => fn($n) => (string) $n,
            'absint' => fn($n) => abs((int) $n),
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

    public function test_render_widget_shows_duplicate_sku_warning_when_products_are_blocked(): void
    {
        $options = [
            WC_Multi_Store_Sku_Conflicts::OPTION => [
                '7:abc' => ['product_id' => 7, 'store_url' => 'https://a.com', 'sku' => 'S1', 'remote_id' => 1, 'owner_remote_id' => 2, 'time' => 1],
            ],
        ];
        Functions\when('get_option')->alias(fn($key, $default = false) => $options[$key] ?? $default);

        $this->assertStringContainsString('1 product(s) blocked by a duplicate SKU', $this->renderWidget());
    }

    public function test_render_widget_hides_duplicate_sku_warning_when_nothing_is_blocked(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);

        $this->assertStringNotContainsString('duplicate SKU', $this->renderWidget());
    }
}
