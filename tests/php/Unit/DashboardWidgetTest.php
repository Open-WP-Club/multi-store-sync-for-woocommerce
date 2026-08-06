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
        Functions\expect('add_action')
            ->twice();

        $widget = new WC_Multi_Store_Dashboard_Widget();
        $this->assertInstanceOf(WC_Multi_Store_Dashboard_Widget::class, $widget);
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

        $this->assertTrue(true); // Verify no exception
    }

    public function test_register_widget_adds_widget_when_capable(): void
    {
        Functions\when('add_action')->justReturn(true);

        Functions\expect('current_user_can')
            ->with('manage_woocommerce')
            ->once()
            ->andReturn(true);

        Functions\expect('wp_add_dashboard_widget')
            ->once();

        $widget = new WC_Multi_Store_Dashboard_Widget();
        $widget->register_widget();

        $this->assertTrue(true); // Verify no exception
    }
}
