<?php
/** Tests for native list selection and authorized bulk processing. */
use Brain\Monkey\Functions;

class AdminListTableTest extends WC_Multi_Store_TestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/admin-list-table.php';
        Functions\when('esc_html')->alias(static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES));
        Functions\when('absint')->alias(static fn($value) => abs((int) $value));
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-10-19 12:00:00');
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
        parent::tearDown();
    }

    public function test_only_actionable_rows_get_selection(): void {
        $table = new WC_Multi_Store_Admin_List_Table('queue', ['product' => 'Product'], [],
            static function (array $row): void { echo '<td>Product</td>'; },
            static fn(array $row): bool => $row['status'] === 'failed'
        );
        ob_start();
        $table->single_row(['id' => 42, 'status' => 'failed']);
        $selectable = ob_get_clean();
        ob_start();
        $table->single_row(['id' => 43, 'status' => 'processing']);
        $processing = ob_get_clean();
        $this->assertInstanceOf(WP_List_Table::class, $table);
        $this->assertStringContainsString('name="wc_mss_ids[]" value="42"', $selectable);
        $this->assertStringNotContainsString('type="checkbox"', $processing);
    }

    public function test_bulk_uses_nonce_selected_ids_and_reports_partial_failure(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->with(Mockery::type('string'), 7)->andReturn('ROW7');
        $wpdb->shouldReceive('prepare')->once()->with(Mockery::type('string'), 8)->andReturn('ROW8');
        $wpdb->shouldReceive('get_var')->with('ROW7')->andReturn('pending');
        $wpdb->shouldReceive('get_var')->with('ROW8')->andReturn('resolved');
        $wpdb->shouldReceive('update')->once()->with('wp_wc_multi_store_stock_discrepancies', ['status' => 'ignored'], ['id' => 7], ['%s'], ['%d'])->andReturn(1);
        Functions\expect('check_admin_referer')->once()->with('bulk-wc-mss-discrepancies')->andReturn(1);
        $_POST = ['wc_mss_list' => 'discrepancies', 'action' => 'mark_resolved', 'action2' => 'mark_ignored', 'wc_mss_bulk_bottom' => 'Apply', 'wc_mss_ids' => ['7', '7', '8', ['bad']]];
        ob_start();
        WC_Multi_Store_Admin_List_Table::process_bulk_action('discrepancies');
        $notice = ob_get_clean();
        $this->assertStringContainsString('Processed: 1. Failed: 1.', $notice);
    }

    public function test_row_action_targets_only_its_record(): void {
        Functions\when('check_admin_referer')->justReturn(1);
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->with(Mockery::type('string'), 9)->andReturn('ROW9');
        $wpdb->shouldReceive('get_var')->with('ROW9')->andReturn('pending');
        $wpdb->shouldReceive('update')->once()->andReturn(1);
        $_POST = ['wc_mss_list' => 'discrepancies', 'wc_mss_row_action' => 'mark_resolved:9', 'wc_mss_ids' => ['10']];
        ob_start();
        WC_Multi_Store_Admin_List_Table::process_bulk_action('discrepancies');
        $this->assertStringContainsString('Processed: 1. Failed: 0.', ob_get_clean());
    }

    public function test_permission_denied_does_not_process_records(): void {
        Functions\when('check_admin_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(static function () { throw new RuntimeException('denied'); });
        $_POST = ['wc_mss_list' => 'queue', 'action' => 'retry', 'wc_mss_ids' => ['1']];
        $this->expectExceptionMessage('denied');
        WC_Multi_Store_Admin_List_Table::process_bulk_action('queue');
    }

    public function test_invalid_action_and_wrong_section_do_not_process_records(): void {
        $this->assertFalse(WC_Multi_Store_Admin_List_Table::process_item('queue', 'dismiss', 1));
        $this->assertFalse(WC_Multi_Store_Admin_List_Table::process_item('discrepancies', 'mark_ignored', 0));
        Functions\expect('check_admin_referer')->never();
        $_POST = ['wc_mss_list' => 'queue', 'action' => 'retry', 'wc_mss_ids' => ['1']];
        WC_Multi_Store_Admin_List_Table::process_bulk_action('conflicts');
    }

    public function test_invalid_nonce_stops_before_processing(): void {
        Functions\when('check_admin_referer')->alias(static function () { throw new RuntimeException('invalid nonce'); });
        $_POST = ['wc_mss_list' => 'queue', 'action' => 'retry', 'wc_mss_ids' => ['1']];
        $this->expectExceptionMessage('invalid nonce');
        WC_Multi_Store_Admin_List_Table::process_bulk_action('queue');
    }
    public function test_discrepancy_count_keeps_store_and_status_filters(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->with(
            Mockery::on(static fn(string $sql): bool => str_contains($sql, 'status = %s AND store_url = %s')),
            'pending', 'https://store.test'
        )->andReturn('COUNT');
        $wpdb->shouldReceive('get_var')->with('COUNT')->andReturn(53);
        $this->assertSame(53, WC_Multi_Store_Stock_Verifier::get_discrepancy_count('pending', 'https://store.test'));
    }

    public function test_conflict_count_keeps_unresolved_and_store_filters(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->with(
            Mockery::on(static fn(string $sql): bool => str_contains($sql, 'resolved = 0 AND store_url = %s')),
            'https://store.test'
        )->andReturn('COUNT');
        $wpdb->shouldReceive('get_var')->with('COUNT')->andReturn(51);
        $this->assertSame(51, WC_Multi_Store_Conflict_Detector::get_conflict_count('https://store.test', false));
    }

    public function test_page_input_is_clamped_after_records_are_processed(): void {
        $request = $_REQUEST;
        try {
            $_REQUEST = ['paged' => '3'];
            $this->assertSame(3, WC_Multi_Store_Admin_List_Table::page_number(101));
            $this->assertSame(2, WC_Multi_Store_Admin_List_Table::page_number(51));
            $this->assertSame(1, WC_Multi_Store_Admin_List_Table::page_number(0));
            $_REQUEST = ['paged' => ['invalid']];
            $this->assertSame(1, WC_Multi_Store_Admin_List_Table::page_number(101));
        } finally {
            $_REQUEST = $request;
        }
    }

}
