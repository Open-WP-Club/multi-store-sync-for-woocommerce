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

    public function test_read_only_table_has_no_selection_and_preserves_row_action_target(): void {
        $table = new WC_Multi_Store_Admin_List_Table('saved-profiles', ['name' => 'Name'], [],
            static function (array $row): void { echo '<td>Profile</td>'; },
            null, null, static fn(array $row): string => 'wc-mss-profile-row-' . $row['id']
        );
        ob_start();
        $table->single_row(['id' => 'profile-42']);
        $row = ob_get_clean();
        $this->assertSame(['name' => 'Name'], $table->get_columns());
        $this->assertSame('<tr id="wc-mss-profile-row-profile-42"><td>Profile</td></tr>', $row);
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve the simulated pagination request in this unit test.
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

    public function test_readonly_pagination_preserves_route_and_filters_without_nonces(): void {
        $query = $_GET;
        $_GET = ['page' => 'wc-settings', 'tab' => 'multi_store_sync', 'section' => 'history', 'filter_store' => 'https://store.test?a=1&b=2', '_wpnonce' => 'omit-me', 'paged' => '2'];
        try {
            $table = new WC_Multi_Store_Admin_List_Table('history', ['product' => 'Product'], [], static function (): void {}, null, 51);
            ob_start();
            $table->display();
            $html = ob_get_clean();
            $this->assertStringContainsString('<form method="get">', $html);
            $this->assertStringContainsString('name="section" value="history"', $html);
            $this->assertStringContainsString('name="filter_store" value="https://store.test?a=1&amp;b=2"', $html);
            $this->assertStringNotContainsString('omit-me', $html);
            $this->assertStringNotContainsString('name="paged"', $html);
        } finally {
            $_GET = $query;
        }
    }

    public function test_exception_in_one_record_does_not_block_the_rest_and_is_escaped(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        foreach ([7, 8] as $id) {
            $wpdb->shouldReceive('prepare')->once()->with(Mockery::type('string'), $id)->andReturn('ROW' . $id);
        }
        $wpdb->shouldReceive('get_var')->with('ROW7')->andThrow(new RuntimeException('<script>remote error</script>'));
        $wpdb->shouldReceive('get_var')->with('ROW8')->andReturn('pending');
        $wpdb->shouldReceive('update')->once()->with('wp_wc_multi_store_stock_discrepancies', ['status' => 'ignored'], ['id' => 8], ['%s'], ['%d'])->andReturn(1);
        Functions\expect('check_admin_referer')->once()->with('bulk-wc-mss-discrepancies')->andReturn(1);
        $_POST = ['wc_mss_list' => 'discrepancies', 'action' => 'mark_ignored', 'wc_mss_ids' => [7, 8]];
        ob_start();
        try {
            WC_Multi_Store_Admin_List_Table::process_bulk_action('discrepancies');
            $notice = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Processed: 1. Failed: 1.', $notice);
        $this->assertStringContainsString('&lt;script&gt;remote error&lt;/script&gt;', $notice);
        $this->assertStringNotContainsString('<script>', $notice);
    }

    public function test_auto_correction_error_is_reported_without_counting_it_as_processed(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->twice()->with(Mockery::type('string'), 7)->andReturn('ROW7');
        $wpdb->shouldReceive('get_var')->with('ROW7')->andReturn('pending');
        $wpdb->shouldReceive('get_row')->with('ROW7', ARRAY_A)->andReturn(null);
        $wpdb->shouldReceive('update')->never();
        Functions\expect('check_admin_referer')->once()->with('bulk-wc-mss-discrepancies')->andReturn(1);
        $_POST = ['wc_mss_list' => 'discrepancies', 'action' => 'auto_correct', 'wc_mss_ids' => [7]];
        ob_start();
        try {
            WC_Multi_Store_Admin_List_Table::process_bulk_action('discrepancies');
            $notice = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Processed: 0. Failed: 1.', $notice);
        $this->assertStringContainsString('Discrepancy not found', $notice);
    }

    public function test_empty_selection_and_unknown_action_do_not_mutate_records(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('update')->never();
        $wpdb->shouldReceive('get_var')->never();
        Functions\expect('check_admin_referer')->twice()->with('bulk-wc-mss-discrepancies')->andReturn(1);
        foreach ([['action' => 'mark_ignored', 'wc_mss_ids' => []], ['action' => 'delete', 'wc_mss_ids' => [7]]] as $input) {
            $_POST = ['wc_mss_list' => 'discrepancies'] + $input;
            ob_start();
            try {
                WC_Multi_Store_Admin_List_Table::process_bulk_action('discrepancies');
                $notice = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertStringContainsString('notice-warning', $notice);
            $this->assertStringNotContainsString('Processed:', $notice);
        }
    }

}
