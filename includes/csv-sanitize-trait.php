<?php
/**
 * Shared CSV formula-injection guard for classes that export rows to CSV.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

trait WC_Multi_Store_Csv_Sanitizer {

    /**
     * Prefix CSV cells that begin with =, +, -, @, or \t with a single quote
     * to defuse formula execution when the file is opened in Excel/LibreOffice.
     */
    private static function csv_cell_sanitize(string $value): string {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
