<?php
/**
 * Regression tests for the explicit nonces on the row-action forms.
 *
 * Every list form that posts wc_mss_row_action must render the
 * 'bulk-wc-mss-<type>' nonce that process_bulk_action() verifies. The forms
 * used to receive it only as a side effect of the list table's bulk toolbar;
 * each form now emits wp_nonce_field() itself and these tests pin that.
 */

use Brain\Monkey\Functions;

class AdminRowFormNonceTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/admin-list-table.php';

        // Escaping and formatting stubs shared by every admin view.
        Functions\when('esc_html')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(static function ($t, $d = 'default'): void {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_html__')->alias(static fn($t, $d = 'default') => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr_e')->alias(static function ($t, $d = 'default'): void {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_attr__')->alias(static fn($t, $d = 'default') => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_js')->alias(static fn($t) => addslashes(htmlspecialchars((string) $t, ENT_COMPAT, 'UTF-8')));
        Functions\when('esc_url')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url_raw')->alias(static fn($t) => (string) $t);
        Functions\when('wp_kses')->alias(static fn($t) => $t);
        Functions\when('wp_kses_post')->alias(static fn($t) => $t);
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('_n')->alias(static fn($single, $plural, $number) => ((int) $number === 1) ? $single : $plural);
        Functions\when('number_format_i18n')->alias(static fn($n, $decimals = 0) => number_format((float) $n, (int) $decimals));
        Functions\when('human_time_diff')->alias(static fn($from, $to = 0) => '1 min');
        Functions\when('mysql2date')->alias(static fn($format, $date) => (string) $date);
        Functions\when('maybe_unserialize')->alias(static fn($data) => is_string($data) ? unserialize($data) : $data);
        Functions\when('selected')->alias(static function ($selected, $current = true, $echo = true) {
            $out = ((string) $selected === (string) $current) ? " selected='selected'" : '';
            if ($echo) {
                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
            }
            return $out;
        });
        Functions\when('checked')->alias(static function ($checked, $current = true, $echo = true) {
            $out = ((string) $checked === (string) $current) ? " checked='checked'" : '';
            if ($echo) {
                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
            }
            return $out;
        });
        Functions\when('disabled')->alias(static function ($disabled, $current = true, $echo = true) {
            $out = ((string) $disabled === (string) $current) ? " disabled='disabled'" : '';
            if ($echo) {
                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
            }
            return $out;
        });

        // Nonce field rendered as a recognizable marker so tests can assert on
        // the exact action a form carries.
        Functions\when('wp_nonce_field')->alias(static function ($action = -1, $name = '_wpnonce', $referer = true, $echo = true): void {
            echo '<input type="hidden" name="' . esc_attr((string) $name) . '" value="nonce:' . esc_attr((string) $action) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });

        // Routing and request context.
        Functions\when('admin_url')->alias(static fn($path = '') => 'https://primary.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(static fn(...$args) => 'https://primary.test/wp-admin/admin.php');
        Functions\when('remove_query_arg')->alias(static fn(...$args) => 'https://primary.test/wp-admin/admin.php');
        Functions\when('settings_errors')->justReturn(null);
        Functions\when('current_time')->alias(static fn($type = 'mysql') => $type === 'timestamp' ? 1767225600 : '2026-01-01 00:00:00');
        Functions\when('sanitize_sql_orderby')->alias(
            static fn($orderby) => preg_match('/^[a-z_]+ (ASC|DESC)$/i', (string) $orderby) ? $orderby : ''
        );
        Functions\when('get_terms')->justReturn([]);
        // Non-false with a term shape: ensure_term_cache() must not hit the API
        // while the view iterates the cached categories/tags.
        Functions\when('get_transient')->justReturn([['id' => 1, 'name' => 'Shoes', 'slug' => 'shoes']]);
        Functions\when('wc_get_products')->justReturn([]);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 0]);
        Functions\when('get_site_url')->justReturn('https://primary.test');
        Functions\when('get_bloginfo')->justReturn('Primary Test');

        $this->options = [
            'wc_multi_store_sync_settings' => [
                'enabled' => true,
                'auto_sync_on_save' => true,
                'category_match_by' => 'slug',
            ],
            'wc_multi_store_sync_stores' => [
                'https://a.example.com' => [
                    'status' => 'active',
                    'name' => 'Store A',
                    'consumer_key' => 'ck',
                    'consumer_secret' => 'cs',
                ],
            ],
            'wc_mss_conflict_settings' => ['enabled' => true, 'action_on_conflict' => 'warn'],
        ];
        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        WC_Multi_Store_Settings::clear_static_cache();

        // Permissive wpdb double: views only need the queries to succeed.
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql, ...$args) => $sql);
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static fn($text) => $text);
        $wpdb->shouldReceive('get_var')->andReturn(1);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_results')->andReturn([
            [
                'id' => 1,
                'product_id' => 42,
                'local_product_id' => 42,
                'product_sku' => 'SKU-1',
                'product_name' => 'Widget',
                'store_url' => 'https://a.example.com',
                'sync_type' => 'full_product',
                'status' => 'dead',
                'attempts' => 3,
                'priority' => 5,
                'last_error' => 'boom',
                'error' => 'boom',
                'error_summary' => 'boom',
                'count' => 1,
                'failed_at' => '2026-01-01 00:00:00',
                'created_at' => '2026-01-01 00:00:00',
                'expected_stock' => 5,
                'actual_stock' => 3,
                'difference' => -2,
                'detected_at' => '2026-01-01 00:00:00',
                'changed_fields' => '["price"]',
                'resolved' => 0,
                'queue_status' => 'unresolved',
                'resolution' => '',
            ],
        ]);
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->shouldReceive('insert')->andReturn(1);

        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    /** Render one admin view with the given caller-supplied variables. */
    private function renderView(string $view, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include WC_MSS_PLUGIN_DIR . 'admin/views/' . $view . '.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** Assert the rendered form carries the explicit nonce for its list type. */
    private function assertFormNonce(string $html, string $type): void
    {
        $this->assertStringContainsString(
            'value="nonce:bulk-wc-mss-' . $type . '"',
            $html,
            "the {$type} row-action form must emit its own 'bulk-wc-mss-{$type}' nonce"
        );
    }

    public function test_queue_row_form_carries_its_bulk_nonce(): void
    {
        $html = $this->renderView('queue', [
            'queue_stats' => ['pending' => 1, 'processing' => 0, 'completed' => 0, 'failed' => 1, 'total' => 2],
            'queue_status_filter' => 'all',
            'queue_items' => [['id' => 9]],
        ]);
        $this->assertFormNonce($html, 'queue');
    }

    public function test_conflicts_row_form_carries_its_bulk_nonce(): void
    {
        // The view fetches its own rows/stats from the conflict tables and
        // expects the store map for its filter dropdown.
        $html = $this->renderView('conflicts', ['stores' => []]);
        $this->assertFormNonce($html, 'conflicts');
    }

    public function test_dead_letter_row_form_carries_its_bulk_nonce(): void
    {
        $html = $this->renderView('dead-letter-queue');
        $this->assertFormNonce($html, 'dead-letter-queue');
    }

    public function test_discrepancies_row_form_carries_its_bulk_nonce(): void
    {
        $html = $this->renderView('discrepancies', [
            'stores' => [],
        ]);
        $this->assertFormNonce($html, 'discrepancies');
    }

    public function test_verification_row_form_carries_its_bulk_nonce(): void
    {
        // Same caller-supplied variables as
        // WC_Multi_Store_Settings_Integration::output_weekly_verification().
        $html = $this->renderView('weekly-verification', [
            'settings' => [
                'enabled' => true,
                'day_of_week' => 0,
                'time_of_day' => '03:00',
                'check_stock' => true,
                'check_prices' => true,
                'sample_mode' => 'all',
                'product_limit' => 0,
                'batch_size' => 20,
                'auto_correct' => false,
                'auto_correct_limit' => 500,
                'weekly_sync_type' => 'use_default',
                'weekly_stock_sync' => 'use_default',
                'weekly_category_auto_create' => 'use_default',
                'email_enabled' => false,
                'email_recipients' => '',
            ],
            'reports' => [],
            'next_run' => 1767225600,
            'latest_report' => [
                'started_at' => '2026-01-01 00:00:00',
                'products_checked' => 10,
                'stores_checked' => 1,
                'discrepancies_found' => 2,
                'duration_seconds' => 1.5,
                'missing_products' => 0,
                'stock_mismatches' => 2,
                'price_mismatches' => 0,
                'report_data' => [
                    'details' => [
                        [
                            'product_id' => 42,
                            'name' => 'Widget',
                            'sku' => 'SKU-1',
                            'discrepancies' => [['store_name' => 'Store A']],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertFormNonce($html, 'verification');
    }

    public function test_category_scan_row_form_carries_its_bulk_nonce(): void
    {
        $html = $this->renderView('category-scan-results', [
            'scan_result' => ['items' => [['product_id' => 42]]],
        ]);
        $this->assertFormNonce($html, 'category-scan');
    }

    public function test_process_bulk_action_verifies_the_action_the_forms_render(): void
    {
        Functions\when('check_admin_referer')->alias(function ($action = -1) use (&$captured): int {
            $captured = (string) $action;
            return 1;
        });
        Functions\when('current_user_can')->justReturn(true);

        // An unknown row action stops right after the nonce check, so the
        // handler can be probed without touching queue state.
        $_POST = [
            'wc_mss_list' => 'queue',
            'wc_mss_row_action' => 'bogus:1',
        ];
        ob_start();
        try {
            WC_Multi_Store_Admin_List_Table::process_bulk_action('queue');
        } finally {
            ob_end_clean();
        }

        $this->assertSame('bulk-wc-mss-queue', $captured);
    }
}
