<?php
/**
 * Tests for the cross-cutting plumbing in WC_Multi_Store_Admin_Ajax:
 * run_ajax_handler() exception path and the product_cat row-action helpers
 * (add_category_row_action, enqueue_category_assets, category_row_action_script).
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

if (!class_exists('WP_Term')) {
    // Same shape as the guarded stubs in AdminAjaxForceSyncTest and friends.
    class WP_Term {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $taxonomy = 'product_cat';
        public string $description = '';
        public int $parent = 0;
        public int $count = 0;
    }
}

class AdminAjaxHandlerWrapperTest extends WC_Multi_Store_TestCase
{
    /** @var array{success: ?bool, data: mixed} */
    private array $response = ['success' => null, 'data' => null];

    /** @var list<array{0: string, 1: string}> level/message pairs sent to wc_get_logger(). */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/class-admin-ajax.php';

        $this->response = ['success' => null, 'data' => null];
        $this->logged = [];
        $_POST = [];

        Functions\when('get_option')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->response = ['success' => true, 'data' => $data];
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            $this->response = ['success' => false, 'data' => $data];
        });

        $logger = new class ($this) {
            public function __construct(private readonly AdminAjaxHandlerWrapperTest $test) {}

            public function log(string $level, string $message, array $context = []): void
            {
                $this->test->record($level, $message);
            }
        };
        Functions\when('wc_get_logger')->justReturn($logger);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /** @internal Used by the anonymous logger above. */
    public function record(string $level, string $message): void
    {
        $this->logged[] = [$level, $message];
    }

    private function ajax(): WC_Multi_Store_Admin_Ajax
    {
        return (new ReflectionClass(WC_Multi_Store_Admin_Ajax::class))->newInstanceWithoutConstructor();
    }

    private function run_wrapper(callable $handler, string $prefix = 'unit_prefix', string $message = 'Friendly failure'): void
    {
        $method = new ReflectionMethod(WC_Multi_Store_Admin_Ajax::class, 'run_ajax_handler');
        $method->invoke($this->ajax(), $prefix, $message, $handler);
    }

    private function term(int $id, string $name): WP_Term
    {
        $term = new WP_Term();
        $term->term_id = $id;
        $term->name = $name;
        return $term;
    }

    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    // ─── run_ajax_handler ──────────────────────────

    public function test_run_ajax_handler_runs_body_and_sends_nothing_itself_on_success(): void
    {
        $ran = 0;

        $this->run_wrapper(function () use (&$ran) {
            $ran++;
            return 'ignored return value';
        });

        $this->assertSame(1, $ran);
        $this->assertNull($this->response['success'], 'The wrapper must not send a response when the handler succeeds');
        $this->assertSame([], $this->logged);
    }

    public function test_run_ajax_handler_leaves_handler_sent_response_untouched(): void
    {
        $this->run_wrapper(function () {
            wp_send_json_success(['message' => 'done']);
        });

        $this->assertTrue($this->response['success']);
        $this->assertSame(['message' => 'done'], $this->response['data']);
    }

    public function test_run_ajax_handler_converts_exception_to_json_error_and_logs_it(): void
    {
        $this->run_wrapper(function () {
            throw new RuntimeException('database exploded');
        }, 'delete_history', 'Could not delete history');

        $this->assertFalse($this->response['success']);
        $this->assertSame('Could not delete history', $this->response['data']['message']);
        $this->assertSame(
            ['message', 'error'],
            array_keys($this->response['data'])
        );
        $this->assertSame([['error', 'AJAX delete_history error: database exploded']], $this->logged);
    }

    public function test_run_ajax_handler_hides_exception_detail_from_client_when_wp_debug_is_off(): void
    {
        $this->assertFalse(WP_DEBUG, 'Test environment must run with WP_DEBUG disabled');

        $this->run_wrapper(function () {
            throw new RuntimeException('secret path /var/www/db-password.php');
        });

        $this->assertNull($this->response['data']['error']);
        $this->assertStringNotContainsString('secret', (string) json_encode($this->response['data']));
    }

    public function test_run_ajax_handler_also_catches_php_errors_not_just_exceptions(): void
    {
        $this->run_wrapper(function () {
            $value = null;
            $value->method();
        }, 'type_error', 'Failed hard');

        $this->assertFalse($this->response['success']);
        $this->assertSame('Failed hard', $this->response['data']['message']);
        $this->assertCount(1, $this->logged);
        $this->assertSame('error', $this->logged[0][0]);
        $this->assertStringStartsWith('AJAX type_error error: ', $this->logged[0][1]);
    }

    public function test_run_ajax_handler_does_not_swallow_a_response_sent_before_the_exception(): void
    {
        $this->run_wrapper(function () {
            wp_send_json_success(['partial' => true]);
            throw new LogicException('after send');
        });

        // The error response replaces the earlier one (last write wins in the stub),
        // proving the exception branch ran after the first send.
        $this->assertFalse($this->response['success']);
        $this->assertCount(1, $this->logged);
    }

    public function test_real_handler_reports_generic_error_when_report_lookup_throws(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SELECT 1');
        $wpdb->shouldReceive('get_row')->andThrow(new RuntimeException('connection lost'));
        $wpdb->shouldReceive('get_results')->andThrow(new RuntimeException('connection lost'));
        $wpdb->shouldReceive('get_var')->andThrow(new RuntimeException('connection lost'));
        Functions\when('get_option')->justReturn(false);

        $this->ajax()->ajax_queue_orphans_for_deletion();

        $this->assertFalse($this->response['success']);
        $this->assertSame('An error occurred while processing orphan deletion', $this->response['data']['message']);
        $this->assertNull($this->response['data']['error']);
        $this->assertSame('error', $this->logged[0][0] ?? null);
        $this->assertStringContainsString('AJAX queue_orphans_for_deletion error: connection lost', $this->logged[0][1] ?? '');
    }

    // ─── add_category_row_action ───────────────────

    private function stub_url_helpers(): void
    {
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('admin_url')->alias(fn($path = '') => 'https://example.test/wp-admin/' . $path);
        Functions\when('esc_html__')->alias(fn($t, $d = 'default') => htmlspecialchars($t, ENT_QUOTES, 'UTF-8'));
    }

    public function test_add_category_row_action_appends_sync_link_and_keeps_existing_actions(): void
    {
        $this->stub_url_helpers();
        $existing = ['edit' => '<a href="#">Edit</a>', 'delete' => '<a href="#">Delete</a>'];

        $actions = $this->ajax()->add_category_row_action($existing, $this->term(42, 'Shoes'));

        $this->assertSame(['edit', 'delete', 'wc_mss_sync'], array_keys($actions));
        $this->assertSame($existing['edit'], $actions['edit']);
        $this->assertSame(
            '<a href="#" class="wc-mss-cat-row-sync" data-category-id="42" data-category-name="Shoes">Sync to stores</a>',
            $actions['wc_mss_sync']
        );
    }

    public function test_add_category_row_action_escapes_hostile_category_name(): void
    {
        $this->stub_url_helpers();

        $actions = $this->ajax()->add_category_row_action([], $this->term(7, '"><script>alert(1)</script>'));

        $this->assertStringNotContainsString('<script>', $actions['wc_mss_sync']);
        $this->assertStringContainsString(
            'data-category-name="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"',
            $actions['wc_mss_sync']
        );
    }

    public function test_add_category_row_action_overwrites_a_previous_wc_mss_sync_entry(): void
    {
        $this->stub_url_helpers();

        $actions = $this->ajax()->add_category_row_action(['wc_mss_sync' => 'stale'], $this->term(1, 'A'));

        $this->assertCount(1, $actions);
        $this->assertStringContainsString('data-category-id="1"', $actions['wc_mss_sync']);
    }

    // ─── enqueue_category_assets ───────────────────

    /** @var list<string> Names of asset functions invoked. */
    private array $assetCalls = [];

    private function track_asset_functions(): void
    {
        $this->assetCalls = [];
        foreach (['wp_enqueue_script', 'wp_localize_script', 'wp_set_script_translations'] as $fn) {
            Functions\when($fn)->alias(function () use ($fn) {
                $this->assetCalls[] = $fn;
            });
        }
    }

    public static function skipped_screen_provider(): array
    {
        return [
            'wrong hook, right taxonomy'  => ['edit.php', 'product_cat'],
            'right hook, other taxonomy'  => ['edit-tags.php', 'product_tag'],
            'right hook, post taxonomy'   => ['edit-tags.php', 'category'],
            'settings page'               => ['woocommerce_page_wc-settings', 'product_cat'],
        ];
    }

    #[DataProvider('skipped_screen_provider')]
    public function test_enqueue_category_assets_does_nothing_outside_product_cat_list(string $hook, string $taxonomy): void
    {
        Functions\when('get_current_screen')->justReturn((object) ['taxonomy' => $taxonomy]);
        $this->track_asset_functions();

        $this->ajax()->enqueue_category_assets($hook);

        $this->assertSame([], $this->assetCalls);
    }

    public function test_enqueue_category_assets_does_nothing_without_a_current_screen(): void
    {
        Functions\when('get_current_screen')->justReturn(null);
        $this->track_asset_functions();

        $this->ajax()->enqueue_category_assets('edit-tags.php');

        $this->assertSame([], $this->assetCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_enqueue_category_assets_registers_admin_ui_script_with_nonce_on_product_cat_screen(): void
    {
        define('WC_MSS_PLUGIN_URL', 'https://example.test/wp-content/plugins/mss/');
        $calls = [];
        Functions\when('get_current_screen')->justReturn((object) ['taxonomy' => 'product_cat']);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.test/wp-admin/' . $p);
        Functions\when('wp_create_nonce')->alias(fn($action) => 'nonce-for-' . $action);
        Functions\when('wp_enqueue_script')->alias(function (...$args) use (&$calls) {
            $calls['enqueue'] = $args;
        });
        Functions\when('wp_set_script_translations')->alias(function (...$args) use (&$calls) {
            $calls['translations'] = $args;
        });
        Functions\when('wp_localize_script')->alias(function (...$args) use (&$calls) {
            $calls['localize'] = $args;
        });

        $this->ajax()->enqueue_category_assets('edit-tags.php');

        $this->assertSame(
            ['wc-mss-admin-ui', 'https://example.test/wp-content/plugins/mss/admin/js/admin-ui.js', ['wp-util', 'wp-i18n'], WC_MSS_VERSION, true],
            $calls['enqueue']
        );
        $this->assertSame('wc-mss-admin-ui', $calls['translations'][0]);
        $this->assertSame('multi-store-sync-for-woocommerce', $calls['translations'][1]);
        $this->assertSame(WC_MSS_PLUGIN_DIR . 'languages', $calls['translations'][2]);
        $this->assertSame('wc-mss-admin-ui', $calls['localize'][0]);
        $this->assertSame('wcMssAdmin', $calls['localize'][1]);
        $this->assertSame('https://example.test/wp-admin/admin-ajax.php', $calls['localize'][2]['ajax_url']);
        $this->assertSame('nonce-for-wc_mss_admin', $calls['localize'][2]['nonce']);
        $this->assertSame(['request_failed', 'dismiss_notice'], array_keys($calls['localize'][2]['i18n']));
    }

    // ─── category_row_action_script ────────────────

    public static function script_skipped_provider(): array
    {
        return [
            'no screen'      => [null],
            'tag taxonomy'   => [(object) ['taxonomy' => 'product_tag']],
            'other taxonomy' => [(object) ['taxonomy' => 'category']],
        ];
    }

    #[DataProvider('script_skipped_provider')]
    public function test_category_row_action_script_prints_nothing_outside_product_cat(?object $screen): void
    {
        Functions\when('get_current_screen')->justReturn($screen);

        $html = $this->capture(fn() => $this->ajax()->category_row_action_script());

        $this->assertSame('', $html);
    }

    public function test_category_row_action_script_prints_inline_script_with_escaped_nonce(): void
    {
        Functions\when('get_current_screen')->justReturn((object) ['taxonomy' => 'product_cat']);
        Functions\when('wp_create_nonce')->justReturn("ab'c</script>");
        Functions\when('esc_js')->alias(fn($t) => str_replace(["\\", "'", '</'], ["\\\\", "\\'", '<\\/'], (string) $t));

        $html = $this->capture(fn() => $this->ajax()->category_row_action_script());

        $this->assertStringStartsWith('<script>', trim($html));
        $this->assertStringEndsWith('</script>', trim($html));
        $this->assertSame(1, substr_count($html, '</script>'), 'A hostile nonce must not close the script block early');
        $this->assertStringContainsString("var nonce = 'ab\\'c<\\/script>';", $html);
        $this->assertStringContainsString("'.wc-mss-cat-row-sync'", $html);
        $this->assertStringContainsString("window.wcMssRequest('wc_mss_sync_by_category'", $html);
        $this->assertStringContainsString("sync_type:        'full_product'", $html);
        $this->assertStringContainsString('include_children: 1', $html);
        $this->assertStringContainsString('Queuing…', $html);
        $this->assertStringContainsString('Sync to stores', $html);
    }
}
