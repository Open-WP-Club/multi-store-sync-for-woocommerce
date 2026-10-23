<?php
/**
 * Tests for WC_Multi_Store_Admin_List_Table: action maps per list type,
 * the read-only tablenav, display() wrapper markup, page_number() and
 * process_item() guard clauses. Complements AdminListTableTest (bulk processing).
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminListTableViewTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/admin-list-table.php';
        Functions\when('esc_html')->alias(static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(static function ($v) {
            echo htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    /**
     * Table whose pagination() is provided (the bootstrap WP_List_Table stub lacks it).
     */
    private function table(?callable $selectable = null, ?int $total = null, array $items = []): WC_Multi_Store_Admin_List_Table
    {
        return new class ('queue', ['product' => 'Product', 'status' => 'Status'], $items, static function (array $row): void {
            echo '<td>' . esc_html($row['id']) . '</td>';
        }, $selectable, $total, null) extends WC_Multi_Store_Admin_List_Table {
            public function pagination($which): void
            {
                echo '[pagination]'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test marker.
            }

            public function tablenav(string $which): void
            {
                $this->display_tablenav($which);
            }

            public function bulk(): array
            {
                return $this->get_bulk_actions();
            }
        };
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

    // ─── actions_for ───────────────────────────────

    public static function actions_provider(): array
    {
        return [
            'conflicts'         => ['conflicts', ['overwrite' => 'Overwrite', 'keep_remote' => 'Keep Remote', 'merge' => 'Merge']],
            'discrepancies'     => ['discrepancies', ['auto_correct' => 'Auto-Correct', 'mark_resolved' => 'Mark Resolved', 'mark_ignored' => 'Ignore']],
            'queue'             => ['queue', ['retry' => 'Retry']],
            'dead letter queue' => ['dead-letter-queue', ['retry' => 'Retry', 'dismiss' => 'Dismiss']],
            'category scan'     => ['category-scan', ['resync' => 'Re-sync to all stores']],
            'verification'      => ['verification', ['resync' => 'Re-sync to all stores']],
            'unknown type'      => ['logs', []],
            'empty type'        => ['', []],
            'case sensitive'    => ['Queue', []],
        ];
    }

    #[DataProvider('actions_provider')]
    public function test_actions_for_returns_exact_action_map_per_type(string $type, array $expected): void
    {
        $this->assertSame($expected, WC_Multi_Store_Admin_List_Table::actions_for($type));
    }

    public function test_bulk_actions_follow_the_list_type(): void
    {
        $this->assertSame(['retry' => 'Retry'], $this->table()->bulk());
    }

    // ─── constructor ───────────────────────────────

    public function test_selectable_table_prepends_checkbox_column_while_read_only_does_not(): void
    {
        $selectable = $this->table(static fn(array $r): bool => true);
        $readOnly = $this->table();

        $this->assertSame(['cb', 'product', 'status'], array_keys($selectable->get_columns()));
        $this->assertSame('<input type="checkbox" />', $selectable->get_columns()['cb']);
        $this->assertSame(['product', 'status'], array_keys($readOnly->get_columns()));
    }

    public function test_constructor_sets_pagination_only_when_total_is_given(): void
    {
        $this->assertNull($this->table()->get_pagination_arg('total_items'));

        $paged = $this->table(null, 120);
        $this->assertSame(120, $paged->get_pagination_arg('total_items'));
        $this->assertSame(50, $paged->get_pagination_arg('per_page'));
    }

    // ─── display_tablenav ──────────────────────────

    public function test_read_only_tablenav_is_empty_without_items(): void
    {
        $this->assertSame('', $this->capture(fn() => $this->table(null, 0)->tablenav('top')));
        $this->assertSame('', $this->capture(fn() => $this->table(null, null)->tablenav('bottom')));
    }

    public function test_read_only_tablenav_renders_pagination_wrapper_for_each_position(): void
    {
        $table = $this->table(null, 75);

        $this->assertSame(
            '<div class="tablenav top">[pagination]<br class="clear" /></div>',
            $this->capture(fn() => $table->tablenav('top'))
        );
        $this->assertSame(
            '<div class="tablenav bottom">[pagination]<br class="clear" /></div>',
            $this->capture(fn() => $table->tablenav('bottom'))
        );
    }

    public function test_read_only_tablenav_escapes_position_in_class_attribute(): void
    {
        $html = $this->capture(fn() => $this->table(null, 10)->tablenav('"><script>x</script>'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('class="tablenav &quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html);
    }

    // ─── display ───────────────────────────────────

    public function test_display_read_only_with_pagination_wraps_in_get_form_and_keeps_filters(): void
    {
        $_GET = [
            'page'    => 'wc-settings',
            'tab'     => 'multi_store_sync',
            'paged'   => '3',
            '_wpnonce' => 'abc',
            '_wp_http_referer' => '/x',
            'status'  => '"><script>alert(1)</script>',
            'array'   => ['nested'],
        ];

        $html = $this->capture(fn() => $this->table(null, 200)->display());

        $this->assertStringStartsWith('<form method="get">', $html);
        $this->assertStringEndsWith('</form>', $html);
        $this->assertStringContainsString('<input type="hidden" name="page" value="wc-settings" />', $html);
        $this->assertStringContainsString('<input type="hidden" name="tab" value="multi_store_sync" />', $html);
        $this->assertStringNotContainsString('name="paged"', $html);
        $this->assertStringNotContainsString('_wpnonce', $html);
        $this->assertStringNotContainsString('_wp_http_referer', $html);
        $this->assertStringNotContainsString('name="array"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('name="status" value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('wc_mss_list', $html);
    }

    public function test_display_read_only_without_total_has_no_form_and_no_output(): void
    {
        $_GET = ['page' => 'wc-settings'];

        $this->assertSame('', $this->capture(fn() => $this->table()->display()));
    }

    public function test_display_selectable_adds_list_type_field_and_select_all_hint_without_get_form(): void
    {
        $html = $this->capture(fn() => $this->table(static fn(array $r): bool => true, 5)->display());

        $this->assertStringContainsString('<input type="hidden" name="wc_mss_list" value="queue" />', $html);
        $this->assertStringContainsString('Select all selects the actionable records on this page.', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    // ─── single_row ────────────────────────────────

    public function test_single_row_uses_row_id_callback_and_escapes_it(): void
    {
        $table = new WC_Multi_Store_Admin_List_Table(
            'queue',
            ['product' => 'Product'],
            [],
            static function (array $row): void {
                echo '<td>cell</td>';
            },
            null,
            null,
            static fn(array $row): string => 'row-"><b>' . $row['id']
        );

        $html = $this->capture(fn() => $table->single_row(['id' => 9]));

        $this->assertSame('<tr id="row-&quot;&gt;&lt;b&gt;9"><td>cell</td></tr>', $html);
    }

    public function test_single_row_checkbox_has_numeric_value_and_item_label_for_hostile_id(): void
    {
        $table = $this->table(static fn(array $r): bool => true);

        $html = $this->capture(fn() => $table->single_row(['id' => '12abc']));

        $this->assertStringContainsString('value="12"', $html);
        $this->assertStringContainsString('aria-label="Select item 12"', $html);
        $this->assertStringContainsString('<th scope="row" class="check-column">', $html);
    }

    // ─── page_number ───────────────────────────────

    public static function page_provider(): array
    {
        return [
            'no param'                => [[], null, 1],
            'plain'                   => [['paged' => '4'], null, 4],
            'zero clamps to 1'        => [['paged' => '0'], null, 1],
            'negative becomes abs'    => [['paged' => '-3'], null, 3],
            'garbage clamps to 1'     => [['paged' => 'abc'], null, 1],
            'array ignored'           => [['paged' => ['9']], null, 1],
            'clamped to last page'    => [['paged' => '99'], 120, 3],
            'exact page boundary'     => [['paged' => '99'], 100, 2],
            'zero total gives page 1' => [['paged' => '5'], 0, 1],
            'within range untouched'  => [['paged' => '2'], 120, 2],
        ];
    }

    #[DataProvider('page_provider')]
    public function test_page_number(array $request, ?int $total, int $expected): void
    {
        $_REQUEST = $request;

        $this->assertSame($expected, WC_Multi_Store_Admin_List_Table::page_number($total));
    }

    // ─── process_item guards ───────────────────────

    public static function invalid_item_provider(): array
    {
        return [
            'zero id'              => ['queue', 'retry', 0],
            'negative id'          => ['queue', 'retry', -4],
            'unknown action'       => ['queue', 'delete', 5],
            'action of other type' => ['queue', 'dismiss', 5],
            'unknown type'         => ['logs', 'retry', 5],
            'empty strings'        => ['', '', 5],
        ];
    }

    #[DataProvider('invalid_item_provider')]
    public function test_process_item_rejects_invalid_requests_without_touching_the_database(string $type, string $action, int $id): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('get_var');
        $wpdb->shouldNotReceive('query');
        $wpdb->shouldNotReceive('update');

        $this->assertFalse(WC_Multi_Store_Admin_List_Table::process_item($type, $action, $id));
    }

    public function test_process_item_skips_discrepancies_that_are_no_longer_actionable(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->andReturnUsing(fn($q, ...$a) => $q);
        $wpdb->shouldReceive('get_var')->once()->andReturn('resolved');

        $this->assertFalse(WC_Multi_Store_Admin_List_Table::process_item('discrepancies', 'mark_resolved', 8));
    }
}
