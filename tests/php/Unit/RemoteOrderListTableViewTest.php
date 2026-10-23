<?php
/**
 * Tests for WC_Multi_Store_Remote_Order_List_Table: column_actions, extra_tablenav,
 * no_items, display and prepare_items (filters, paging, SQL handed to $wpdb).
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class RemoteOrderListTableViewTest extends WC_Multi_Store_TestCase
{
    private WC_Multi_Store_Remote_Order_List_Table $table;

    /** @var list<array{0: string, 1: array<int, mixed>}> prepare() calls as [query, args]. */
    private array $prepared = [];

    /** @var list<string> Queries executed through get_results()/get_var(). */
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/remote-order-list-table.php';
        require_once WC_MSS_PLUGIN_DIR . 'includes/remote-order-table.php';

        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(function ($v, $d = 'default') {
            echo htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_url')->alias(fn($v) => str_replace(['"', "'", '<', '>'], '', (string) $v));
        Functions\when('esc_url_raw')->alias(fn($v) => filter_var($v, FILTER_VALIDATE_URL) ? $v : '');
        Functions\when('admin_url')->alias(fn($p = '') => 'https://local.test/wp-admin/' . $p);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('selected')->alias(function ($selected, $current = true, $echo = true) {
            return ((string) $selected === (string) $current) ? " selected='selected'" : '';
        });
        Functions\when('submit_button')->alias(function ($text) {
            echo '<input type="submit" name="filter_action" value="' . htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8') . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });

        $this->table = new WC_Multi_Store_Remote_Order_List_Table();
        $this->prepared = [];
        $this->executed = [];
        $_GET = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($this->table, $method))->invoke($this->table, ...$args);
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

    /**
     * @param list<object> $stores  Rows returned by the DISTINCT store query.
     * @param list<object> $orders  Rows returned by the orders query.
     */
    private function db(array $stores = [], array $orders = [], int $count = 0): void
    {
        global $wpdb;
        $test = $this;
        $wpdb = new class ($test, $stores, $orders, $count) extends wpdb {
            public function __construct(
                private readonly RemoteOrderListTableViewTest $test,
                private readonly array $stores,
                private readonly array $orders,
                private readonly int $count,
            ) {}

            public function prepare(string $query, mixed ...$args): string
            {
                $this->test->recordPrepare($query, isset($args[0]) && is_array($args[0]) ? $args[0] : $args);
                return $query;
            }

            public function get_results(string $query, string $output = OBJECT): array|object|null
            {
                $this->test->recordExecuted($query);
                return str_contains($query, 'DISTINCT') ? $this->stores : $this->orders;
            }

            public function get_var(string $query): mixed
            {
                $this->test->recordExecuted($query);
                return (string) $this->count;
            }
        };
    }

    /** @internal */
    public function recordPrepare(string $query, array $args): void
    {
        $this->prepared[] = [$query, $args];
    }

    /** @internal */
    public function recordExecuted(string $query): void
    {
        $this->executed[] = $query;
    }

    // ─── column_actions ────────────────────────────

    public function test_column_actions_links_to_view_page_for_the_order(): void
    {
        $html = $this->call('column_actions', (object) ['id' => 77]);

        $this->assertSame(
            '<a href="https://local.test/wp-admin/admin.php?page=wc-multi-store-remote-orders&action=view&order_id=77" class="button button-small">View</a>',
            $html
        );
    }

    public function test_column_actions_never_emits_a_delete_action(): void
    {
        $html = $this->call('column_actions', (object) ['id' => 5]);

        $this->assertStringNotContainsString('delete', strtolower($html));
        $this->assertStringNotContainsString('_wpnonce', $html);
    }

    public function test_column_actions_url_encodes_hostile_id_and_cannot_break_out_of_href(): void
    {
        $html = $this->call('column_actions', (object) ['id' => '1"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('order_id=1%22%3E%3Cscript%3E', $html);
        $this->assertSame(1, substr_count($html, 'href="'));
    }

    // ─── no_items ──────────────────────────────────

    public function test_no_items_prints_message(): void
    {
        $this->assertSame('No remote orders found.', $this->capture(fn() => $this->table->no_items()));
    }

    // ─── extra_tablenav ────────────────────────────

    public function test_extra_tablenav_prints_nothing_for_bottom(): void
    {
        $this->db([(object) ['remote_store_url' => 'https://a.test']]);

        $this->assertSame('', $this->capture(fn() => $this->call('extra_tablenav', 'bottom')));
        $this->assertSame([], $this->executed, 'The store lookup must not run for the bottom tablenav');
    }

    public function test_extra_tablenav_top_renders_status_filter_with_all_statuses_and_filter_button(): void
    {
        $this->db();

        $html = $this->capture(fn() => $this->call('extra_tablenav', 'top'));

        $this->assertStringContainsString('id="filter-by-status"', $html);
        $this->assertStringContainsString('<option value="">All statuses</option>', $html);
        foreach (['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'] as $status) {
            $this->assertStringContainsString('<option value="' . $status . '">', $html);
        }
        $this->assertStringContainsString('Pending payment', $html);
        $this->assertStringContainsString('name="filter_action" value="Filter"', $html);
        $this->assertStringNotContainsString('filter-by-store', $html, 'No store dropdown without any remote orders');
        $this->assertStringNotContainsString("selected='selected'", $html);
    }

    public function test_extra_tablenav_top_selects_current_status_only(): void
    {
        $this->db();
        $_GET['status'] = 'on-hold';

        $html = $this->capture(fn() => $this->call('extra_tablenav', 'top'));

        $this->assertSame(1, substr_count($html, "selected='selected'"));
        $this->assertStringContainsString("<option value=\"on-hold\" selected='selected'>On hold</option>", $html);
    }

    public function test_extra_tablenav_top_ignores_unknown_status_value(): void
    {
        $this->db();
        $_GET['status'] = '"><script>x</script>';

        $html = $this->capture(fn() => $this->call('extra_tablenav', 'top'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString("selected='selected'", $html);
    }

    public function test_extra_tablenav_top_lists_stores_by_host_and_selects_current_one(): void
    {
        $this->db([
            (object) ['remote_store_url' => 'https://alpha.example.com/shop'],
            (object) ['remote_store_url' => 'https://beta.example.org'],
        ]);
        $_GET['store_url'] = 'https://beta.example.org';

        $html = $this->capture(fn() => $this->call('extra_tablenav', 'top'));

        $this->assertStringContainsString('id="filter-by-store"', $html);
        $this->assertStringContainsString('<option value="">All stores</option>', $html);
        $this->assertStringContainsString('<option value="https://alpha.example.com/shop">alpha.example.com</option>', $html);
        $this->assertStringContainsString("<option value=\"https://beta.example.org\" selected='selected'>beta.example.org</option>", $html);
        $this->assertCount(1, $this->executed);
        $this->assertStringContainsString('SELECT DISTINCT remote_store_url FROM wp_wc_mss_remote_orders', $this->executed[0]);
    }

    public function test_extra_tablenav_top_escapes_hostile_store_url_in_attribute_and_label(): void
    {
        $this->db([(object) ['remote_store_url' => 'https://evil.test"><script>alert(1)</script>']]);

        $html = $this->capture(fn() => $this->call('extra_tablenav', 'top'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('value="https://evil.test&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
    }

    // ─── display ───────────────────────────────────

    public function test_display_wraps_table_in_get_form_with_escaped_page_field(): void
    {
        $_REQUEST['page'] = 'wc-multi-store-remote-orders" onfocus="alert(1)';

        $html = $this->capture(fn() => $this->table->display());

        $this->assertStringContainsString('<form id="remote_order-filter" method="get">', $html);
        $this->assertStringContainsString('name="page" value="wc-multi-store-remote-orders&quot; onfocus=&quot;alert(1)"', $html);
        $this->assertStringNotContainsString('onfocus="alert', $html);
        $this->assertStringEndsWith('</form>', trim($html));
    }

    public function test_display_without_page_request_emits_empty_page_value(): void
    {
        $html = $this->capture(fn() => $this->table->display());

        $this->assertStringContainsString('name="page" value=""', $html);
    }

    // ─── prepare_items ─────────────────────────────

    public function test_prepare_items_without_filters_uses_defaults_and_sets_pagination(): void
    {
        $order = (object) ['id' => 1, 'billing_address' => '{"a":1}', 'shipping_address' => 'null', 'line_items' => '[]', 'order_meta' => '{}'];
        $this->db([], [$order], 45);

        $this->table->prepare_items();

        $this->assertCount(1, $this->table->items);
        $this->assertSame(['a' => 1], $this->table->items[0]->billing_address, 'JSON columns are decoded');
        $this->assertSame(45, $this->table->get_pagination_arg('total_items'));
        $this->assertSame(20, $this->table->get_pagination_arg('per_page'));
        $this->assertSame(3.0, (float) $this->table->get_pagination_arg('total_pages'));
        $this->assertSame(
            ['cb', 'order', 'date', 'status', 'store', 'customer', 'total', 'actions'],
            array_keys($this->table->_column_headers[0])
        );
        $this->assertSame([], $this->table->_column_headers[1]);
        $this->assertSame(['order', 'date', 'status', 'total', 'customer'], array_keys($this->table->_column_headers[2]));
        $this->assertSame([' LIMIT %d OFFSET %d', [20, 0]], $this->prepared[0]);
        $this->assertStringContainsString('WHERE 1=1 ORDER BY date_created DESC', $this->executed[0]);
    }

    public function test_prepare_items_passes_all_filters_as_prepared_values(): void
    {
        $this->db();
        $_GET = [
            'store_url'      => 'https://shop.example.com',
            'status'         => 'completed',
            'customer_email' => 'buyer@example.com',
            'date_from'      => '2024-01-01',
            'date_to'        => '2024-01-31',
        ];

        $this->table->prepare_items();

        $filterQueries = array_values(array_filter($this->prepared, fn($p) => str_contains($p[0], 'remote_store_url = %s')));
        $this->assertNotEmpty($filterQueries);
        foreach ($filterQueries as [$query, $values]) {
            $this->assertStringContainsString(
                'remote_store_url = %s AND status = %s AND customer_email = %s AND date_created >= %s AND date_created <= %s',
                $query
            );
            $this->assertSame(
                ['https://shop.example.com', 'completed', 'buyer@example.com', '2024-01-01', '2024-01-31 23:59:59'],
                $values
            );
        }
    }

    public static function dropped_filter_provider(): array
    {
        return [
            'invalid store url'   => ['store_url', 'not a url', 'remote_store_url'],
            'empty status'        => ['status', '', 'status = %s'],
            'html-only status'    => ['status', '<b></b>', 'status = %s'],
            'empty email'         => ['customer_email', '', 'customer_email'],
            'empty date from'     => ['date_from', '', 'date_created >='],
            'empty date to'       => ['date_to', '', 'date_created <='],
        ];
    }

    #[DataProvider('dropped_filter_provider')]
    public function test_prepare_items_drops_empty_or_invalid_filters(string $key, string $value, string $forbiddenFragment): void
    {
        $this->db();
        $_GET[$key] = $value;

        $this->table->prepare_items();

        foreach ($this->executed as $query) {
            $this->assertStringNotContainsString($forbiddenFragment, $query);
        }
        foreach ($this->prepared as [$query]) {
            $this->assertStringNotContainsString($forbiddenFragment, $query);
        }
    }

    public function test_prepare_items_strips_markup_from_text_filters(): void
    {
        $this->db();
        $_GET = ['status' => '<script>alert(1)</script>completed', 'date_from' => '<i>2024-01-01</i>'];

        $this->table->prepare_items();

        $values = [];
        foreach ($this->prepared as [$query, $args]) {
            if (str_contains($query, 'status = %s')) {
                $values = $args;
            }
        }
        $this->assertSame(['alert(1)completed', '2024-01-01'], $values);
    }

    public function test_prepare_items_sanitises_sort_direction_and_column_via_order_table_allowlist(): void
    {
        $this->db();
        $_GET = ['orderby' => 'total; DROP TABLE wp_users', 'order' => 'asc'];

        $this->table->prepare_items();

        $this->assertStringContainsString('ORDER BY date_created ASC', $this->executed[0]);
        $this->assertStringNotContainsString('DROP TABLE', $this->executed[0]);
    }

    public function test_prepare_items_honours_allowed_orderby_values(): void
    {
        $this->db();
        $_GET = ['orderby' => 'total', 'order' => 'DESC'];

        $this->table->prepare_items();

        $this->assertStringContainsString('ORDER BY total DESC', $this->executed[0]);
    }

    public function test_prepare_items_with_no_orders_yields_zero_pages(): void
    {
        $this->db([], [], 0);

        $this->table->prepare_items();

        $this->assertSame([], $this->table->items);
        $this->assertSame(0, $this->table->get_pagination_arg('total_items'));
        $this->assertSame(0.0, (float) $this->table->get_pagination_arg('total_pages'));
    }
}
