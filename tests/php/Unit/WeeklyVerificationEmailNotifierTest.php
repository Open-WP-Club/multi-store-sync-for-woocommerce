<?php
/**
 * Unit tests for WC_Multi_Store_Weekly_Verification_Email_Notifier
 *
 * Extracted from WeeklySyncVerifierExtendedTest.php as part of splitting
 * WC_Multi_Store_Weekly_Sync_Verifier — see
 * docs/superpowers/specs/2026-08-07-weekly-verifier-report-repository-design.md
 */

use Brain\Monkey\Functions;

class WeeklyVerificationEmailNotifierTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('WC_Multi_Store_Weekly_Verification_Email_Notifier', false)) {
            require_once dirname(__DIR__, 3) . '/includes/weekly-verification-email-notifier.php';
        }
    }

    // ── format_discrepancy_message ────────────────────────────────
    // Regression coverage for the admin-view bug this method's extraction fixed:
    // ghost/tag/image/category/attribute/generic-field discrepancies never set
    // a 'message' key, so admin/views/weekly-verification.php's old per-type
    // if/elseif chain silently rendered blank for them.

    private function stubEscHtml(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
    }

    public function test_format_discrepancy_message_missing(): void
    {
        $this->assertSame(
            'Product not found',
            WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => 'missing'])
        );
    }

    public function test_format_discrepancy_message_orphan_includes_exclusion_reasons(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'orphan',
            'exclusion_reasons' => ['out of stock', 'draft'],
        ]);

        $this->assertStringContainsString('ORPHAN', $message);
        $this->assertStringContainsString('out of stock, draft', $message);
    }

    public function test_format_discrepancy_message_ghost_includes_sku(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'ghost',
            'remote_sku' => 'GHOST-1',
        ]);

        $this->assertStringContainsString('GHOST', $message);
        $this->assertStringContainsString('GHOST-1', $message);
    }

    public function test_format_discrepancy_message_stock(): void
    {
        $message = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'stock',
            'expected' => 10,
            'actual' => 7,
            'difference' => -3,
        ]);

        $this->assertStringContainsString('Expected: 10', $message);
        $this->assertStringContainsString('Actual: 7', $message);
        $this->assertStringContainsString('-3', $message);
    }

    public function test_format_discrepancy_message_price(): void
    {
        $message = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'price',
            'field' => 'regular_price',
            'expected' => '19.99',
            'actual' => '24.99',
        ]);

        $this->assertStringContainsString('Regular_price', $message);
        $this->assertStringContainsString('19.99', $message);
        $this->assertStringContainsString('24.99', $message);
    }

    public function test_format_discrepancy_message_tag_lists_missing_and_extra(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'tag',
            'missing' => ['sale'],
            'extra' => ['clearance'],
        ]);

        $this->assertStringContainsString('Tag mismatch', $message);
        $this->assertStringContainsString('missing:', $message);
        $this->assertStringContainsString('sale', $message);
        $this->assertStringContainsString('extra:', $message);
        $this->assertStringContainsString('clearance', $message);
    }

    public function test_format_discrepancy_message_attribute(): void
    {
        $this->assertSame(
            'Attribute mismatch',
            WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message(['type' => 'attribute'])
        );
    }

    public function test_format_discrepancy_message_generic_field_falls_through_to_default(): void
    {
        $this->stubEscHtml();
        $message = WC_Multi_Store_Weekly_Verification_Email_Notifier::format_discrepancy_message([
            'type' => 'weight',
            'field' => 'weight',
            'expected' => '1.5',
            'actual' => '2.0',
        ]);

        $this->assertStringContainsString('Weight', $message);
        $this->assertStringContainsString('Expected: 1.5', $message);
        $this->assertStringContainsString('Actual: 2.0', $message);
    }
}
