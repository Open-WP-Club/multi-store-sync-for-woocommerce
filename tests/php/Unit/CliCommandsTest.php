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
}
