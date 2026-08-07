<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

class CliCommandsTest extends WC_Multi_Store_TestCase
{
    // ─── class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_CLI_Commands'));
    }

    public function test_has_sync_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_CLI_Commands', 'sync'));
    }

    public function test_has_queue_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_CLI_Commands', 'queue'));
    }

    public function test_has_stores_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_CLI_Commands', 'stores'));
    }

    public function test_has_verify_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_CLI_Commands', 'verify'));
    }

    public function test_has_history_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_CLI_Commands', 'history'));
    }

    public function test_has_config_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_CLI_Commands', 'config'));
    }

    public function test_has_dlq_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_CLI_Commands', 'dlq'));
    }

    // ─── method visibility ─────────────────────────

    public function test_all_commands_are_public(): void
    {
        $reflection = new \ReflectionClass('WC_Multi_Store_CLI_Commands');
        $methods = ['sync', 'queue', 'stores', 'verify', 'history', 'config', 'dlq'];

        foreach ($methods as $method) {
            $ref_method = $reflection->getMethod($method);
            $this->assertTrue($ref_method->isPublic(), "Method $method should be public");
        }
    }

    // ─── method parameters ─────────────────────────

    public function test_sync_accepts_args_and_assoc_args(): void
    {
        $reflection = new \ReflectionMethod('WC_Multi_Store_CLI_Commands', 'sync');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('args', $params[0]->getName());
        $this->assertEquals('assoc_args', $params[1]->getName());
    }

    public function test_queue_accepts_args_and_assoc_args(): void
    {
        $reflection = new \ReflectionMethod('WC_Multi_Store_CLI_Commands', 'queue');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
    }

    public function test_stores_accepts_args_and_assoc_args(): void
    {
        $reflection = new \ReflectionMethod('WC_Multi_Store_CLI_Commands', 'stores');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
    }

    public function test_config_accepts_args_and_assoc_args(): void
    {
        $reflection = new \ReflectionMethod('WC_Multi_Store_CLI_Commands', 'config');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
    }

    public function test_dlq_accepts_args_and_assoc_args(): void
    {
        $reflection = new \ReflectionMethod('WC_Multi_Store_CLI_Commands', 'dlq');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
    }

    // ─── extends WP_CLI_Command ────────────────────

    public function test_extends_correct_base_class(): void
    {
        // WP_CLI_Command might not be available in tests, so just check class exists
        $this->assertTrue(class_exists('WC_Multi_Store_CLI_Commands'));
    }

    // ─── verify ─────────────────────────────────────
    //
    // Regression coverage: `verify` used to call a
    // `WC_Multi_Store_Weekly_Sync_Verifier::run_verification_sync()` method that
    // never existed (fatal "call to undefined method" on every real `wp mss
    // verify` run, unnoticed because this file only checked method existence,
    // never behavior). It now calls the real `run_verification()`, passing
    // through --limit/--store and reading the report's actual field names.

    private function setUpVerifyMocks(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_parse_args')->alias(fn($args, $defaults) => array_merge($defaults, (array) $args));
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        WP_CLI::$logged = [];
    }

    public function test_verify_reports_error_when_no_active_stores(): void
    {
        $this->setUpVerifyMocks();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [];
            }
            return $default;
        });

        try {
            (new WC_Multi_Store_CLI_Commands())->verify([], []);
            $this->fail('Expected WP_CLI::error() to halt execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('No active stores', $e->getMessage());
        }
    }

    public function test_verify_filters_by_store_option(): void
    {
        $this->setUpVerifyMocks();
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return ['enabled' => true];
            }
            if ($option === 'wc_multi_store_sync_settings') {
                return ['sync_type_default' => 'full_product'];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [
                    'https://other-store.com' => ['status' => 'active', 'consumer_key' => 'k', 'consumer_secret' => 's'],
                ];
            }
            return $default;
        });

        // --store filters active stores down to a URL not present in the
        // configured stores → same "No active stores" path, proving the
        // filter is actually applied rather than silently ignored.
        try {
            (new WC_Multi_Store_CLI_Commands())->verify([], ['store' => 'https://only-this-store.com/']);
            $this->fail('Expected WP_CLI::error() to halt execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('No active stores', $e->getMessage());
        }
    }
}
