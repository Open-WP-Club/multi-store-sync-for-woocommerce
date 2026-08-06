<?php
/**
 * Shared <option> renderer for the sync-type dropdowns repeated across
 * admin/views/settings.php and admin/views/weekly-verification.php.
 *
 * Each call site keeps its own label wording/order/extra options (they're
 * genuinely different per context — e.g. weekly-verification.php has an
 * extra "Use General Settings" choice) — this only removes the repeated
 * <option value="..." <?php selected(...); ?>>label</option> boilerplate.
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync Type Options Class
 */
class WC_Multi_Store_Sync_Type_Options {

    /**
     * Render a list of <option> elements.
     *
     * @param array<string,string> $options  value => label, in caller-specified order.
     * @param string               $selected Currently selected value; pass '' to leave
     *                                        none pre-selected (browser defaults to the first option).
     */
    public static function render(array $options, string $selected = ''): void {
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }
    }
}
