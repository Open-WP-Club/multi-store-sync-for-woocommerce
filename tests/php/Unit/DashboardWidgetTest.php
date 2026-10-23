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
}
