<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * handle_manual_sync() is an admin-post endpoint that triggers requests to every
 * configured store, so its gates and request-parameter whitelisting matter.
 */
class RemoteOrderManualSyncTest extends WC_Multi_Store_TestCase
{
    /** Thrown by the redirect stub to emulate the `exit` that follows it. */
    private const REDIRECTED = 'redirected';

    private ?string $redirectUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redirectUrl = null;
        $_GET              = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('sanitize_key')->alias(fn($v) => strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $v)));
        Functions\when('admin_url')->alias(fn($path = '') => 'https://example.com/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(fn(array $args, string $url) => $url . '?' . http_build_query($args));
        Functions\when('wc_get_order_statuses')->justReturn([
            'wc-pending'    => 'Pending payment',
            'wc-processing' => 'Processing',
            'wc-completed'  => 'Completed',
        ]);
        Functions\when('wp_safe_redirect')->alias(function (string $url): void {
            $this->redirectUrl = $url;
            throw new \RuntimeException(self::REDIRECTED);
        });
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    /**
     * Runs the handler against a sync that returns $results, capturing the args it was given.
     *
     * @param array<int, array<string, int>> $results
     * @return array<string, mixed> Args passed to sync_all_stores()
     */
    private function runHandler(array $results = []): array
    {
        $captured = null;
        $sync     = \Mockery::mock(WC_Multi_Store_Remote_Order_Sync::class)->makePartial();
        $sync->shouldReceive('sync_all_stores')->once()->andReturnUsing(function (array $args) use (&$captured, $results) {
            $captured = $args;
            return $results;
        });

        try {
            $sync->handle_manual_sync();
            $this->fail('handler must end with a redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::REDIRECTED, $e->getMessage());
        }

        return $captured;
    }

    public function test_capability_failure_stops_before_any_sync(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(function (): void {
            throw new \RuntimeException('wp_die');
        });
        $sync = \Mockery::mock(WC_Multi_Store_Remote_Order_Sync::class)->makePartial();
        $sync->shouldReceive('sync_all_stores')->never();

        $this->expectExceptionMessage('wp_die');
        $sync->handle_manual_sync();
    }

    public function test_nonce_is_checked_for_the_remote_orders_action(): void
    {
        $actions = [];
        Functions\when('check_admin_referer')->alias(function (string $action) use (&$actions) {
            $actions[] = $action;
            return 1;
        });

        $this->runHandler();

        $this->assertSame(['wc_mss_sync_remote_orders'], $actions);
    }

    public function test_no_filters_means_no_args(): void
    {
        $this->assertSame([], $this->runHandler());
    }

    public static function daysProvider(): array
    {
        return [
            'normal'           => ['7', 7],
            'zero is raised'   => ['0', 1],
            'huge is capped'   => ['100000', 365],
            'negative is made positive' => ['-30', 30],
            'garbage is raised' => ['abc', 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('daysProvider')]
    public function test_days_filter_is_bounded_between_one_and_365(string $days, int $effectiveDays): void
    {
        $_GET['days'] = $days;
        $before       = time();

        $args = $this->runHandler();

        $after = strtotime($args['after']);
        $this->assertEqualsWithDelta($before - $effectiveDays * 86400, $after, 5);
    }

    public static function statusProvider(): array
    {
        return [
            'any'                  => ['any', 'any'],
            'registered status'    => ['processing', 'processing'],
            'case is normalised'   => ['Completed', 'completed'],
            'unregistered status'  => ['hacked', null],
            'sql in status'        => ["completed' OR 1=1", null],
            'empty'                => ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function test_status_filter_only_accepts_registered_order_statuses(string $input, ?string $expected): void
    {
        $_GET['status'] = $input;

        $args = $this->runHandler();

        if ($expected === null) {
            $this->assertArrayNotHasKey('status', $args);
        } else {
            $this->assertSame($expected, $args['status']);
        }
    }

    public function test_non_string_status_is_ignored(): void
    {
        $_GET['status'] = ['completed'];

        $this->assertArrayNotHasKey('status', $this->runHandler());
    }

    public function test_redirect_totals_results_across_stores_and_tolerates_missing_keys(): void
    {
        $this->runHandler([
            'https://a.example.com' => ['synced' => 3, 'updated' => 1, 'errors' => 0],
            'https://b.example.com' => ['synced' => 2, 'errors' => 4],
            'https://c.example.com' => [],
        ]);

        parse_str((string) parse_url($this->redirectUrl, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://example.com/wp-admin/admin.php?', $this->redirectUrl);
        $this->assertSame(
            ['page' => 'wc-multi-store-remote-orders', 'synced' => '5', 'updated' => '1', 'errors' => '4'],
            $query
        );
    }
}
