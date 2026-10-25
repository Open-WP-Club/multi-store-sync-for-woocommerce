<?php
/**
 * Archive-before-delete for DB cleanup
 *
 * Shared JSON-archiving mechanics used by cleanup routines that export
 * rows to a file before deleting them, mirroring the pattern already
 * established by WC_Multi_Store_Logger::archive_database_history().
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

trait WC_Multi_Store_Archive_Before_Delete {

    /**
     * Number of archive files to keep per prefix before pruning the oldest
     */
    private static function archive_max_files(): int {
        return 10;
    }

    /**
     * Archive DB records to a guarded PHP file in the shared wc-mss-logs directory.
     *
     * @param string $archive_prefix Filename prefix identifying the source table
     * @param array $records Records to archive (associative rows, oldest first)
     * @param string $date_column Column used to compute the date range in the filename
     * @return string|false Path to the written archive file, or false if nothing was written
     */
    private static function archive_records_to_json(string $archive_prefix, array $records, string $date_column): string|false {
        if (empty($records)) {
            return false;
        }

        $logs_dir = WC_Multi_Store_Logger::prepare_archive_directory();
        if ($logs_dir === false) {
            return false;
        }

        $oldest_date = date('Y-m-d', strtotime($records[0][$date_column]));
        $newest_date = date('Y-m-d', strtotime($records[count($records) - 1][$date_column]));
        $archive_file = $logs_dir . $archive_prefix . '-archive-' . $oldest_date . '-to-' . $newest_date . '.php';

        $json_data = [
            'archived_at' => current_time('mysql'),
            'date_range' => [
                'from' => $oldest_date,
                'to' => $newest_date,
            ],
            'total_records' => count($records),
            'records' => $records,
        ];

        if (!WC_Multi_Store_Logger::write_protected_archive($archive_file, $json_data)) {
            return false;
        }

        self::prune_old_archives($logs_dir, $archive_prefix, self::archive_max_files());

        return $archive_file;
    }

    /**
     * Delete oldest archive files for a given prefix beyond the retention count
     */
    private static function prune_old_archives(string $logs_dir, string $archive_prefix, int $max_files): void {
        $files = glob($logs_dir . $archive_prefix . '-archive-*.php', GLOB_NOSORT);

        if ($files === false || count($files) <= $max_files) {
            return;
        }

        natsort($files);
        $to_delete = array_slice($files, 0, count($files) - $max_files);
        foreach ($to_delete as $file) {
            @unlink($file);
        }
    }
}
