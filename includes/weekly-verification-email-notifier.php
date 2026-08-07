<?php
/**
 * Weekly Verification Email Notifier
 * Email notification + discrepancy-message formatting — extracted from
 * WC_Multi_Store_Weekly_Sync_Verifier, which delegates to this class.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Weekly_Verification_Email_Notifier {

    /**
     * Send email notification about verification results
     *
     * @param array $report Verification report
     * @return bool Success
     */
    public static function send_email_notification(array $report): bool {
        $settings = WC_Multi_Store_Weekly_Sync_Verifier::get_settings();

        if (empty($settings['email_recipients'])) {
            return false;
        }

        $to = array_map('trim', explode(',', $settings['email_recipients']));
        $subject = sprintf(
            '[%s] Weekly Sync Verification: %d discrepancies found',
            get_bloginfo('name'),
            $report['discrepancies_found']
        );

        $message = self::format_email_message($report);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $message, $headers);

        if ($sent) {
            WC_Multi_Store_Logger::write('Email notification sent to: ' . implode(', ', $to));
        } else {
            WC_Multi_Store_Logger::write('Failed to send email notification', 'error');
        }

        return $sent;
    }

    /**
     * Format email message for verification report
     *
     * @param array $report Verification report
     * @return string HTML email content
     */
    private static function format_email_message(array $report): string {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #0073aa; color: white; padding: 20px; }
                .content { padding: 20px; }
                .summary { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .summary-item { display: inline-block; margin-right: 30px; }
                .summary-label { font-weight: bold; }
                .discrepancy { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 10px 0; }
                .footer { background: #f5f5f5; padding: 20px; margin-top: 30px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Weekly Sync Verification Report</h1>
            </div>
            <div class="content">
                <div class="summary">
                    <div class="summary-item">
                        <span class="summary-label">Products Checked:</span> <?php echo $report['products_checked']; ?>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Stores Checked:</span> <?php echo $report['stores_checked']; ?>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Discrepancies Found:</span> <?php echo $report['discrepancies_found']; ?>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Duration:</span> <?php echo $report['duration_seconds']; ?>s
                    </div>
                </div>

                <h2>Breakdown</h2>
                <ul>
                    <li><strong>Missing Products:</strong> <?php echo $report['missing_products']; ?></li>
                    <li><strong>Orphan Products:</strong> <?php echo $report['orphan_products'] ?? 0; ?> <em>(exist on remote but should be excluded)</em></li>
                    <li><strong>Ghost Products:</strong> <?php echo $report['ghost_products'] ?? 0; ?> <em>(exist on remote but not in local catalogue)</em></li>
                    <li><strong>Stock Mismatches:</strong> <?php echo $report['stock_mismatches']; ?></li>
                    <li><strong>Price Mismatches:</strong> <?php echo $report['price_mismatches']; ?></li>
                    <li><strong>Category Mismatches:</strong> <?php echo $report['category_mismatches'] ?? 0; ?></li>
                    <li><strong>Field Mismatches:</strong> <?php echo $report['field_mismatches'] ?? 0; ?> <em>(name, description, tags, images, attributes, weight, dimensions, tax, status, etc.)</em></li>
                </ul>

                <?php if (!empty($report['details'])): ?>
                    <h2>Discrepancy Details (Top 10)</h2>
                    <?php
                    $count = 0;
                    foreach ($report['details'] as $product_report):
                        if ($count >= 10) break;
                        if (!empty($product_report['discrepancies'])):
                            $count++;
                    ?>
                        <div class="discrepancy">
                            <strong><?php echo esc_html($product_report['name']); ?></strong> (SKU: <?php echo esc_html($product_report['sku']); ?>)<br>
                            <ul>
                                <?php foreach ($product_report['discrepancies'] as $disc): ?>
                                    <li>
                                        <strong><?php echo esc_html($disc['store_name']); ?>:</strong>
                                        <?php echo self::format_discrepancy_message($disc); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php
                        endif;
                    endforeach;
                    ?>
                <?php endif; ?>

                <p><a href="<?php echo admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=weekly-verification'); ?>">View Full Report in Dashboard</a></p>
            </div>
            <div class="footer">
                <p>This is an automated message from WooCommerce Multi-Store Sync plugin.</p>
                <p>Site: <?php echo get_bloginfo('name'); ?> (<?php echo get_site_url(); ?>)</p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Format a single discrepancy entry into a display string (pre-escaped HTML).
     * Shared by the email report (format_email_message()) and the admin
     * dashboard's "Sample Discrepancies" view (admin/views/weekly-verification.php,
     * via WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message()).
     *
     * The admin view previously only handled missing/orphan/stock/price and fell
     * through to an unset $disc['message'] for every other type (ghost/tag/image/
     * category/attribute/generic field) — those types never set a 'message' key
     * (see verify_product()/compare_*() in weekly-sync-verifier.php), so it
     * silently rendered blank. This brings it to parity with the richer
     * formatting the email already had.
     *
     * @param array $disc Discrepancy entry
     * @return string Formatted HTML for one discrepancy line
     */
    public static function format_discrepancy_message(array $disc): string {
        return match (true) {
            $disc['type'] === 'missing' => __('Product not found', 'wc-multi-store-sync'),
            $disc['type'] === 'orphan' => self::format_orphan_discrepancy_message($disc),
            $disc['type'] === 'ghost' => self::format_ghost_discrepancy_message($disc),
            $disc['type'] === 'stock' => sprintf(
                /* translators: 1: expected stock, 2: actual stock, 3: signed difference */
                __('Stock mismatch - Expected: %1$d, Actual: %2$d (Diff: %3$+d)', 'wc-multi-store-sync'),
                $disc['expected'],
                $disc['actual'],
                $disc['difference']
            ),
            $disc['type'] === 'price' => sprintf(
                /* translators: 1: field name, 2: expected value, 3: actual value */
                __('%1$s mismatch - Expected: %2$s, Actual: %3$s', 'wc-multi-store-sync'),
                ucfirst($disc['field']),
                $disc['expected'],
                $disc['actual']
            ),
            in_array($disc['type'], ['tag', 'image', 'category'], true) => self::format_list_discrepancy_message($disc),
            $disc['type'] === 'attribute' => __('Attribute mismatch', 'wc-multi-store-sync'),
            default => self::format_generic_field_discrepancy_message($disc),
        };
    }

    private static function format_orphan_discrepancy_message(array $disc): string {
        $message = '<strong style="color: #d63638;">' . __('ORPHAN', 'wc-multi-store-sync') . '</strong> - '
            . __('Product exists but should be excluded', 'wc-multi-store-sync');

        if (!empty($disc['exclusion_reasons'])) {
            $message .= ' (' . esc_html(implode(', ', $disc['exclusion_reasons'])) . ')';
        }

        return $message;
    }

    private static function format_ghost_discrepancy_message(array $disc): string {
        $message = '<strong style="color: #8c3800;">' . __('GHOST', 'wc-multi-store-sync') . '</strong> - '
            . __('Remote product has no local counterpart', 'wc-multi-store-sync');

        if (!empty($disc['remote_sku'])) {
            $message .= ' (SKU: ' . esc_html($disc['remote_sku']) . ')';
        }

        return $message;
    }

    private static function format_list_discrepancy_message(array $disc): string {
        $message = esc_html(ucfirst($disc['type'])) . ' ' . __('mismatch', 'wc-multi-store-sync');

        if (!empty($disc['missing'])) {
            $message .= ' — ' . __('missing:', 'wc-multi-store-sync') . ' ' . esc_html(implode(', ', (array) $disc['missing']));
        }

        if (!empty($disc['extra'])) {
            $message .= ' — ' . __('extra:', 'wc-multi-store-sync') . ' ' . esc_html(implode(', ', (array) $disc['extra']));
        }

        return $message;
    }

    private static function format_generic_field_discrepancy_message(array $disc): string {
        $expected = is_scalar($disc['expected']) ? $disc['expected'] : json_encode($disc['expected']);
        $actual   = is_scalar($disc['actual'])   ? $disc['actual']   : json_encode($disc['actual']);

        return esc_html(ucfirst(str_replace('_', ' ', $disc['field'] ?? $disc['type'])))
            . ' ' . __('mismatch', 'wc-multi-store-sync') . ' — Expected: ' . esc_html(mb_substr((string) $expected, 0, 80))
            . ', Actual: ' . esc_html(mb_substr((string) $actual, 0, 80));
    }
}
