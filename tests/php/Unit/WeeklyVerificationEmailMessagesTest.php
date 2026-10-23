<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the email sending and discrepancy-message formatting in
 * WC_Multi_Store_Weekly_Verification_Email_Notifier that
 * WeeklyVerificationEmailNotifierTest does not cover: recipients, subject,
 * headers, return value, empty-report behaviour, the HTML body, and escaping
 * of attacker-controllable remote data in every format_* branch.
 */
class WeeklyVerificationEmailMessagesTest extends WC_Multi_Store_TestCase
{
    private const XSS = '<script>alert(1)</script>';

    /** @var array<int, array{0:mixed,1:string,2:string,3:array}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = [];
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        Functions\when('wp_kses_post')->alias(fn($v) => strip_tags((string) $v, '<strong><em><br><ul><li><span>'));
        Functions\when('admin_url')->alias(fn($p = '') => 'https://site.test/wp-admin/' . $p);
        Functions\when('get_site_url')->justReturn('https://site.test');
        Functions\when('get_bloginfo')->justReturn('My <Shop>');
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
    }

    private function useRecipients(?string $recipients, string $adminEmail = 'admin@site.test'): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) use ($recipients, $adminEmail) {
            if ($option === 'admin_email') {
                return $adminEmail;
            }
            if ($option === 'wc_multi_store_sync_weekly_verification') {
                return $recipients === null ? [] : ['email_recipients' => $recipients];
            }
            return $default;
        });
    }

    private function captureMail(bool $result = true): void
    {
        Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use ($result) {
            $this->mails[] = [$to, $subject, $message, $headers];
            return $result;
        });
    }

    private function report(array $overrides = []): array
    {
        return array_merge([
            'products_checked' => 120,
            'stores_checked' => 3,
            'discrepancies_found' => 5,
            'duration_seconds' => 42,
            'missing_products' => 1,
            'stock_mismatches' => 2,
            'price_mismatches' => 3,
            'details' => [],
        ], $overrides);
    }

    private function product(string $name, string $sku, array $discrepancies): array
    {
        return ['name' => $name, 'sku' => $sku, 'discrepancies' => $discrepancies];
    }

    private function body(array $report): string
    {
        $this->useRecipients('ops@site.test');
        $this->captureMail();
        WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($report);
        $this->assertCount(1, $this->mails);
        return $this->mails[0][2];
    }

    // ─── send_email_notification ───────────────────

    public function test_returns_false_and_sends_nothing_when_no_recipients(): void
    {
        $this->useRecipients('');
        Functions\expect('wp_mail')->never();

        $this->assertFalse(WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report()));
    }

    public function test_falls_back_to_admin_email_when_recipients_not_configured(): void
    {
        $this->useRecipients(null, 'owner@site.test');
        $this->captureMail();

        $this->assertTrue(WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report()));
        $this->assertSame(['owner@site.test'], $this->mails[0][0]);
    }

    public function test_splits_and_trims_multiple_recipients(): void
    {
        $this->useRecipients(' a@x.test ,b@x.test,   c@x.test');
        $this->captureMail();

        WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report());

        $this->assertSame(['a@x.test', 'b@x.test', 'c@x.test'], $this->mails[0][0]);
    }

    public function test_trailing_and_empty_recipient_entries_are_dropped(): void
    {
        $this->useRecipients('a@x.test, ,b@x.test,');
        $this->captureMail();

        WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report());

        $this->assertSame(['a@x.test', 'b@x.test'], $this->mails[0][0]);
    }

    public function test_recipients_made_only_of_separators_send_nothing(): void
    {
        $this->useRecipients(' , ,');
        Functions\expect('wp_mail')->never();

        $this->assertFalse(WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report()));
    }

    public function test_price_message_escapes_field_and_values(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES));

        $message = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'price', 'field' => '<b>regular</b>', 'expected' => '<script>1</script>', 'actual' => '"x"',
        ]);

        $this->assertStringNotContainsString('<script>', $message);
        $this->assertStringNotContainsString('<b>', $message);
        $this->assertStringContainsString('&lt;script&gt;', $message);
        $this->assertStringContainsString('&quot;x&quot;', $message);
    }

    public function test_subject_contains_site_name_and_discrepancy_count(): void
    {
        $this->useRecipients('a@x.test');
        $this->captureMail();

        WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report(['discrepancies_found' => 17]));

        $this->assertSame('[My <Shop>] Weekly Sync Verification: 17 discrepancies found', $this->mails[0][1]);
    }

    public function test_sends_as_utf8_html(): void
    {
        $this->useRecipients('a@x.test');
        $this->captureMail();

        WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report());

        $this->assertSame(['Content-Type: text/html; charset=UTF-8'], $this->mails[0][3]);
    }

    public function test_returns_wp_mail_result_true(): void
    {
        $this->useRecipients('a@x.test');
        $this->captureMail(true);

        $this->assertTrue(WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report()));
    }

    public function test_returns_false_when_wp_mail_fails(): void
    {
        $this->useRecipients('a@x.test');
        $this->captureMail(false);

        $this->assertFalse(WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report()));
    }

    public function test_logs_success_with_recipient_list_and_failure_as_error(): void
    {
        $lines = [];
        $logger = new class($lines) {
            public function __construct(public array &$lines) {}
            public function log($level, $message, $context = []): void { $this->lines[] = [$level, $message]; }
        };
        Functions\when('wc_get_logger')->justReturn($logger);
        $this->useRecipients('a@x.test, b@x.test');

        $this->captureMail(true);
        WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report());
        $this->captureMail(false);
        WC_Multi_Store_Weekly_Verification_Email_Notifier::send_email_notification($this->report());

        $this->assertCount(2, $lines);
        $this->assertSame('info', $lines[0][0]);
        $this->assertSame('Email notification sent to: a@x.test, b@x.test', $lines[0][1]);
        $this->assertSame(['error', 'Failed to send email notification'], $lines[1]);
    }

    // ─── email body ────────────────────────────────

    public function test_body_shows_summary_numbers_and_breakdown(): void
    {
        $body = $this->body($this->report(['orphan_products' => 4, 'ghost_products' => 6, 'category_mismatches' => 7, 'field_mismatches' => 8]));

        $this->assertMatchesRegularExpression('/Products Checked:<\/span>\s*120/', $body);
        $this->assertMatchesRegularExpression('/Stores Checked:<\/span>\s*3/', $body);
        $this->assertMatchesRegularExpression('/Discrepancies Found:<\/span>\s*5/', $body);
        $this->assertMatchesRegularExpression('/Duration:<\/span>\s*42s/', $body);
        $this->assertStringContainsString('<strong>Missing Products:</strong> 1', $body);
        $this->assertStringContainsString('<strong>Orphan Products:</strong> 4', $body);
        $this->assertStringContainsString('<strong>Ghost Products:</strong> 6', $body);
        $this->assertStringContainsString('<strong>Stock Mismatches:</strong> 2', $body);
        $this->assertStringContainsString('<strong>Price Mismatches:</strong> 3', $body);
        $this->assertStringContainsString('<strong>Category Mismatches:</strong> 7', $body);
        $this->assertStringContainsString('<strong>Field Mismatches:</strong> 8', $body);
    }

    public function test_body_defaults_optional_counters_to_zero(): void
    {
        $body = $this->body($this->report());

        foreach (['Orphan Products', 'Ghost Products', 'Category Mismatches', 'Field Mismatches'] as $label) {
            $this->assertStringContainsString("<strong>{$label}:</strong> 0", $body);
        }
    }

    public function test_body_with_zero_discrepancies_and_no_details_has_no_details_section(): void
    {
        $body = $this->body($this->report([
            'discrepancies_found' => 0, 'missing_products' => 0, 'stock_mismatches' => 0, 'price_mismatches' => 0,
        ]));

        $this->assertStringNotContainsString('Discrepancy Details', $body);
        $this->assertStringNotContainsString('class="discrepancy"', $body);
        $this->assertStringContainsString('View Full Report in Dashboard', $body);
    }

    public function test_body_links_to_dashboard_and_names_site(): void
    {
        $body = $this->body($this->report());

        $this->assertStringContainsString(
            'href="https://site.test/wp-admin/admin.php?page=wc-settings&amp;tab=multi_store_sync&amp;section=weekly-verification"',
            $body
        );
        $this->assertStringContainsString('Site: My &lt;Shop&gt; (https://site.test)', $body);
    }

    public function test_body_renders_product_discrepancies_with_store_names(): void
    {
        $body = $this->body($this->report(['details' => [
            $this->product('Blue Shirt', 'BS-1', [
                ['store_name' => 'Store A', 'type' => 'missing'],
                ['store_name' => 'Store B', 'type' => 'attribute'],
            ]),
        ]]));

        $this->assertStringContainsString('Discrepancy Details (Top 10)', $body);
        $this->assertStringContainsString('<strong>Blue Shirt</strong> (SKU: BS-1)', $body);
        $this->assertMatchesRegularExpression('/<strong>Store A:<\/strong>\s*Product not found/', $body);
        $this->assertMatchesRegularExpression('/<strong>Store B:<\/strong>\s*Attribute mismatch/', $body);
    }

    public function test_body_limits_details_to_ten_products_with_discrepancies(): void
    {
        $details = [];
        for ($i = 1; $i <= 15; $i++) {
            $details[] = $this->product("Prod{$i}x", "SKU{$i}x", [['store_name' => 'S', 'type' => 'missing']]);
        }

        $body = $this->body($this->report(['details' => $details]));

        $this->assertSame(10, substr_count($body, 'class="discrepancy"'));
        $this->assertStringContainsString('Prod10x', $body);
        $this->assertStringNotContainsString('Prod11x', $body);
    }

    public function test_body_skips_products_without_discrepancies_and_they_do_not_count_toward_limit(): void
    {
        $details = [$this->product('Clean0', 'C0', [])];
        for ($i = 1; $i <= 10; $i++) {
            $details[] = $this->product("Dirty{$i}x", "D{$i}", [['store_name' => 'S', 'type' => 'missing']]);
        }
        $details[] = $this->product('Clean1', 'C1', []);

        $body = $this->body($this->report(['details' => $details]));

        $this->assertStringNotContainsString('Clean0', $body);
        $this->assertStringNotContainsString('Clean1', $body);
        $this->assertSame(10, substr_count($body, 'class="discrepancy"'));
        $this->assertStringContainsString('Dirty10x', $body);
    }

    public function test_body_details_with_only_clean_products_prints_heading_but_no_entries(): void
    {
        $body = $this->body($this->report(['details' => [$this->product('Clean', 'C', [])]]));

        $this->assertStringContainsString('Discrepancy Details (Top 10)', $body);
        $this->assertStringNotContainsString('class="discrepancy"', $body);
    }

    public function test_body_escapes_hostile_product_name_sku_and_store_name(): void
    {
        $body = $this->body($this->report(['details' => [
            $this->product(self::XSS, '"><img src=x onerror=alert(1)>', [
                ['store_name' => '<b onmouseover=1>Evil</b>', 'type' => 'missing'],
            ]),
        ]]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringNotContainsString('<b onmouseover', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $body);
        $this->assertStringContainsString('&lt;b onmouseover=1&gt;Evil&lt;/b&gt;', $body);
    }

    public function test_body_escapes_hostile_values_in_summary_counters(): void
    {
        $body = $this->body($this->report(['products_checked' => self::XSS, 'orphan_products' => self::XSS]));

        $this->assertStringNotContainsString(self::XSS, $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function test_body_discrepancy_message_goes_through_kses_so_script_in_raw_values_is_stripped(): void
    {
        $body = $this->body($this->report(['details' => [
            $this->product('P', 'S', [
                ['store_name' => 'S', 'type' => 'price', 'field' => 'regular_price', 'expected' => '1', 'actual' => self::XSS],
            ]),
        ]]));

        $this->assertStringNotContainsString('<script', $body);
    }

    // ─── format_discrepancy_message: orphan ────────

    public function test_orphan_without_reasons_has_no_parentheses(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => 'orphan']);

        $this->assertSame(
            '<strong style="color: #d63638;">ORPHAN</strong> - Product exists but should be excluded',
            $msg
        );
    }

    public function test_orphan_with_empty_reason_list_has_no_parentheses(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => 'orphan', 'exclusion_reasons' => []]);

        $this->assertStringEndsWith('excluded', $msg);
    }

    public function test_orphan_reasons_are_joined_in_parentheses(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'orphan', 'exclusion_reasons' => ['category: Sale', 'tag: hidden'],
        ]);

        $this->assertStringEndsWith(' (category: Sale, tag: hidden)', $msg);
    }

    public function test_orphan_reasons_are_html_escaped(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'orphan', 'exclusion_reasons' => [self::XSS, 'a & b'],
        ]);

        $this->assertStringNotContainsString('<script>', $msg);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;, a &amp; b', $msg);
    }

    // ─── ghost ─────────────────────────────────────

    public function test_ghost_without_sku_omits_sku_suffix(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => 'ghost']);

        $this->assertSame(
            '<strong style="color: #8c3800;">GHOST</strong> - Remote product has no local counterpart',
            $msg
        );
    }

    public function test_ghost_with_sku_appends_sku(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => 'ghost', 'remote_sku' => 'R-9']);

        $this->assertStringEndsWith(' (SKU: R-9)', $msg);
    }

    public function test_ghost_sku_is_escaped(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'ghost', 'remote_sku' => '"><script>x</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $msg);
        $this->assertStringEndsWith('(SKU: &quot;&gt;&lt;script&gt;x&lt;/script&gt;)', $msg);
    }

    public function test_ghost_sku_of_zero_string_is_treated_as_empty(): void
    {
        // empty('0') is true, so a literal "0" SKU is not shown. Documents current behaviour.
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => 'ghost', 'remote_sku' => '0']);

        $this->assertStringNotContainsString('SKU:', $msg);
    }

    // ─── list (tag / image / category) ─────────────

    #[DataProvider('listTypes')]
    public function test_list_type_label_is_capitalised(string $type, string $label): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => $type]);

        $this->assertSame($label . ' mismatch', $msg);
    }

    public static function listTypes(): array
    {
        return [
            'tag' => ['tag', 'Tag'],
            'image' => ['image', 'Image'],
            'category' => ['category', 'Category'],
        ];
    }

    public function test_list_only_missing(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'category', 'missing' => ['Shoes', 'Hats'],
        ]);

        $this->assertSame('Category mismatch — missing: Shoes, Hats', $msg);
    }

    public function test_list_only_extra(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'image', 'extra' => ['a.jpg'],
        ]);

        $this->assertSame('Image mismatch — extra: a.jpg', $msg);
    }

    public function test_list_missing_precedes_extra(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'tag', 'missing' => ['m'], 'extra' => ['e'],
        ]);

        $this->assertSame('Tag mismatch — missing: m — extra: e', $msg);
    }

    public function test_list_accepts_scalar_missing_value_via_array_cast(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'tag', 'missing' => 'single',
        ]);

        $this->assertSame('Tag mismatch — missing: single', $msg);
    }

    public function test_list_escapes_missing_and_extra_entries(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'tag', 'missing' => [self::XSS], 'extra' => ['<img src=x onerror=1>'],
        ]);

        $this->assertStringNotContainsString('<script>', $msg);
        $this->assertStringNotContainsString('<img', $msg);
        $this->assertStringContainsString('&lt;script&gt;', $msg);
        $this->assertStringContainsString('&lt;img src=x onerror=1&gt;', $msg);
    }

    // ─── stock / price ─────────────────────────────

    public function test_stock_positive_difference_has_explicit_plus_sign(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'stock', 'expected' => 5, 'actual' => 8, 'difference' => 3,
        ]);

        $this->assertSame('Stock mismatch - Expected: 5, Actual: 8 (Diff: +3)', $msg);
    }

    public function test_stock_negative_difference(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'stock', 'expected' => 8, 'actual' => 5, 'difference' => -3,
        ]);

        $this->assertSame('Stock mismatch - Expected: 8, Actual: 5 (Diff: -3)', $msg);
    }

    public function test_price_message_capitalises_field_name(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'price', 'field' => 'sale_price', 'expected' => '', 'actual' => '9.99',
        ]);

        $this->assertSame('Sale_price mismatch - Expected: , Actual: 9.99', $msg);
    }

    // ─── generic field ─────────────────────────────

    public function test_generic_field_formats_underscores_as_spaces(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => 'short_description', 'expected' => 'a', 'actual' => 'b',
        ]);

        $this->assertSame('Short description mismatch — Expected: a, Actual: b', $msg);
    }

    public function test_generic_field_falls_back_to_type_when_field_missing(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'tax_status', 'expected' => 'taxable', 'actual' => 'none',
        ]);

        $this->assertSame('Tax status mismatch — Expected: taxable, Actual: none', $msg);
    }

    public function test_generic_field_truncates_each_side_to_80_characters(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => 'name',
            'expected' => str_repeat('a', 100), 'actual' => str_repeat('b', 81),
        ]);

        $this->assertStringContainsString('Expected: ' . str_repeat('a', 80) . ',', $msg);
        $this->assertStringNotContainsString(str_repeat('a', 81), $msg);
        $this->assertStringEndsWith('Actual: ' . str_repeat('b', 80), $msg);
    }

    public function test_generic_field_truncation_is_multibyte_safe(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => 'name', 'expected' => str_repeat('ж', 90), 'actual' => 'x',
        ]);

        $this->assertStringContainsString(str_repeat('ж', 80) . ',', $msg);
        $this->assertTrue(mb_check_encoding($msg, 'UTF-8'));
    }

    public function test_generic_field_json_encodes_array_values(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => 'dimensions',
            'expected' => ['length' => '1', 'width' => '2'], 'actual' => ['length' => '3'],
        ]);

        $this->assertStringContainsString('Expected: {&quot;length&quot;:&quot;1&quot;,&quot;width&quot;:&quot;2&quot;}', $msg);
        $this->assertStringContainsString('Actual: {&quot;length&quot;:&quot;3&quot;}', $msg);
    }

    public function test_generic_field_renders_null_as_json_null(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => 'weight', 'expected' => null, 'actual' => '2',
        ]);

        $this->assertSame('Weight mismatch — Expected: null, Actual: 2', $msg);
    }

    public function test_generic_field_renders_scalars_booleans_and_numbers(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => 'virtual', 'expected' => true, 'actual' => 12.5,
        ]);

        $this->assertSame('Virtual mismatch — Expected: 1, Actual: 12.5', $msg);
    }

    public function test_generic_field_escapes_expected_actual_and_field(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => '<i>f</i>', 'expected' => self::XSS, 'actual' => '"><svg onload=1>',
        ]);

        $this->assertStringNotContainsString('<script>', $msg);
        $this->assertStringNotContainsString('<svg', $msg);
        $this->assertStringNotContainsString('<i>', $msg);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $msg);
        $this->assertStringContainsString('&quot;&gt;&lt;svg onload=1&gt;', $msg);
    }

    public function test_generic_field_truncation_happens_before_escaping(): void
    {
        // 80 '&' characters must become 80 "&amp;" entities, never a half-cut entity.
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'field', 'field' => 'name', 'expected' => str_repeat('&', 120), 'actual' => 'x',
        ]);

        $this->assertSame(80, substr_count($msg, '&amp;'));
        $this->assertDoesNotMatchRegularExpression('/&(?!amp;|quot;|lt;|gt;|#)/', $msg);
    }

    public function test_unknown_type_dispatches_to_generic_formatter(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'brand_new_type', 'expected' => 1, 'actual' => 2,
        ]);

        $this->assertSame('Brand new type mismatch — Expected: 1, Actual: 2', $msg);
    }

    // ─── dispatch precedence ───────────────────────

    public function test_attribute_ignores_extra_data(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'attribute', 'expected' => self::XSS, 'actual' => self::XSS,
        ]);

        $this->assertSame('Attribute mismatch', $msg);
    }

    public function test_missing_ignores_extra_data(): void
    {
        $msg = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'missing', 'expected' => self::XSS,
        ]);

        $this->assertSame('Product not found', $msg);
    }
}
