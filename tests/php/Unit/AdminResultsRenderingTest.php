<?php
/** Regression tests for data rendered by asynchronous admin result tables. */
use Brain\Monkey\Functions;

class AdminResultsRenderingTest extends WC_Multi_Store_TestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/admin-list-table.php';
        Functions\when('esc_html')->alias(static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES));
        Functions\when('esc_html_e')->alias(static function ($value): void { echo htmlspecialchars((string) $value, ENT_QUOTES); });
        Functions\when('esc_url')->alias(static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES));
        Functions\when('admin_url')->alias(static fn($path) => 'https://primary.test/wp-admin/' . $path);
        Functions\when('absint')->alias(static fn($value) => abs((int) $value));
    }

    /** Include the real view and render through its registered column callback. */
    private function renderRow(string $view, array $row): array {
        $webhook_logs = [$row];
        $scan_result = ['items' => [$row]];
        ob_start();
        try {
            include WC_MSS_PLUGIN_DIR . 'admin/views/' . $view . '.php';
            ob_clean();
            $list_table->single_row($list_table->items[0]);
            return [ob_get_contents(), $list_table];
        } finally {
            ob_end_clean();
        }
    }

    public function test_webhook_remote_fields_are_escaped_and_legacy_badge_html_is_not_rendered(): void {
        $payload = '<img src=x onerror=alert(1)>';
        [$html, $table] = $this->renderRow('webhook-logs-results', [
            'id' => 17, 'created_at' => $payload, 'type_label' => $payload,
            'product_sku' => $payload, 'store_url' => $payload, 'change_reason' => $payload,
            'status' => $payload, 'status_badge' => '<script>legacy badge</script>',
        ]);
        $this->assertSame(7, substr_count($html, '&lt;img src=x onerror=alert(1)&gt;'));
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('legacy badge', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('data-id="17"', $html);
        $this->assertArrayNotHasKey('cb', $table->get_columns());
    }

    public function test_webhook_stock_zero_is_preserved_and_unknown_stock_is_not_presented_as_zero(): void {
        [$html] = $this->renderRow('webhook-logs-results', [
            'id' => 18, 'old_stock' => 2, 'new_stock' => 0, 'quantity_changed' => -2, 'status' => 'success',
        ]);
        $this->assertStringContainsString('2 → 0 (-2)', $html);
        $this->assertStringContainsString('Success', $html);
        [$unknown] = $this->renderRow('webhook-logs-results', [
            'id' => 19, 'old_stock' => null, 'new_stock' => 0, 'status' => 'failed',
        ]);
        $this->assertStringNotContainsString('→', $unknown);
        $this->assertStringContainsString('Failed', $unknown);
    }

    public function test_category_results_escape_remote_terms_and_select_the_product_id(): void {
        [$html] = $this->renderRow('category-scan-results', [
            'product_id' => 42, 'id' => 999, 'edit_link' => 'https://primary.test/wp-admin/post.php?post=42&action=edit',
            'product_name' => '<script>name</script>', 'sku' => '<b>sku</b>',
            'missing' => ['<img src=x>', 'Books & Music'], 'extra' => ['<svg onload=alert(1)>'],
        ]);
        $this->assertStringContainsString('name="wc_mss_ids[]" value="42"', $html);
        $this->assertStringContainsString('value="resync:42"', $html);
        $this->assertStringNotContainsString('resync:999', $html);
        $this->assertStringContainsString('&lt;script&gt;name&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;sku&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x&gt;, Books &amp; Music', $html);
        $this->assertStringContainsString('&lt;svg onload=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }
}
