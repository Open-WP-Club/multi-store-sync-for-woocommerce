<?php
/**
 * Shared email HTML shell for WC Multi-Store Sync notification emails.
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wraps notification body content in WooCommerce's themed, filterable
 * email header/footer instead of a hand-rolled HTML shell.
 */
trait WC_Multi_Store_Email_Shell {

    /**
     * Wrap email body content in a coloured badge (per-notification-type
     * accent) plus WooCommerce's own mailer header/footer.
     *
     * @param string $heading     Heading shown in WooCommerce's email header (H1)
     * @param string $badge_label Small uppercase label identifying the notification type
     * @param string $badge_color Background colour of the badge (hex)
     * @param string $body_html   Pre-built HTML for the content area
     */
    protected function wrap_email(string $heading, string $badge_label, string $badge_color, string $body_html): string {
        $badge = '<p style="margin:0 0 20px;">'
            . '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;'
            . 'letter-spacing:.5px;text-transform:uppercase;color:#ffffff;background:' . esc_attr($badge_color) . ';">'
            . esc_html($badge_label) . '</span></p>';

        return WC()->mailer()->wrap_message($heading, $badge . $body_html);
    }
}
