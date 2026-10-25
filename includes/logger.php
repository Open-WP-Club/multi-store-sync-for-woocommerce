<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Sync-history table name is a fixed plugin table; retention period is prepared.
/**
 * WooCommerce Multi-Store Logger
 *
 * PSR-3 compliant logger for sync operations.
 * Implements Psr\Log\LoggerInterface via AbstractLogger.
 *
 * Usage:
 *   Static (fire-and-forget):  WC_Multi_Store_Logger::write('message', 'error');
 *   PSR-3 instance:            $logger->error('message');
 *                              $logger->info('message', ['sku' => '123']);
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Logger Class — PSR-3 compliant
 */
class WC_Multi_Store_Logger extends AbstractLogger {

    /**
     * Default archival cutoff in days
     */
    const int DEFAULT_ROTATION_DAYS = 7;

    /**
     * Log source handle used both for wc_get_logger() context and for
     * locating WooCommerce's own log file (WC_Log_Handler_File).
     */
    const string LOG_HANDLE = 'wc-multi-store-sync';

    /**
     * Max number of history-archive JSON files to keep
     */
    const int MAX_ARCHIVE_FILES = 10;

    /** Executing an archive must stop before any archived data or embedded PHP. */
    public const string ARCHIVE_GUARD = "<?php\nexit;\n__halt_compiler();\n";

    /**
     * Singleton instance
     *
     * @var WC_Multi_Store_Logger|null
     */
    private static $instance = null;

    /**
     * WooCommerce's own log file path for our handle
     *
     * @var string
     */
    private readonly string $log_file;

    /**
     * Archival cutoff in days
     *
     * @var int
     */
    private int $rotation_days;

    /**
     * Get singleton instance
     *
     * @return WC_Multi_Store_Logger
     */
    public static function instance(): WC_Multi_Store_Logger {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset singleton instance (for testing only)
     *
     * @return void
     */
    public static function reset_instance(): void {
        self::$instance = null;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->log_file = WC_Log_Handler_File::get_log_file_path(self::LOG_HANDLE);

        // Load configurable archival cutoff
        $this->load_rotation_settings();
    }

    /**
     * Load the archival cutoff (in days) from options or a constant override
     *
     * @return void
     */
    private function load_rotation_settings(): void {
        $settings = get_option('wc_multi_store_sync_settings', []);

        if (defined('WC_MSS_ROTATION_DAYS')) {
            $this->rotation_days = (int) WC_MSS_ROTATION_DAYS;
        } else {
            $this->rotation_days = isset($settings['rotation_days'])
                ? (int) $settings['rotation_days']
                : self::DEFAULT_ROTATION_DAYS;
        }

        // Enforce reasonable limits
        $this->rotation_days = max(1, min(30, $this->rotation_days)); // 1 - 30 days
    }

    /**
     * Ensure the recurring database-history archival job is scheduled.
     * Independent of WooCommerce's own log retention — archival is a
     * database-cleanup concern, not a logging concern.
     *
     * @return void
     */
    public static function schedule_archival(): void {
        if (!WC_Multi_Store_Action_Scheduler_Manager::is_available()) {
            return;
        }

        $hook = 'wc_mss_archive_history';
        $group = WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP;

        if (!as_next_scheduled_action($hook, [], $group)) {
            as_schedule_recurring_action(time(), DAY_IN_SECONDS, $hook, [], $group);
        }
    }

    /**
     * Static convenience method for fire-and-forget logging.
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error, debug)
     * @param string $context Additional context string
     * @param string $channel Optional separate log file: written to "{LOG_HANDLE}-{channel}" (own source in WooCommerce > Status > Logs)
     * @return void
     */
    public static function write(string $message, string $level = 'info', string $context = '', string $channel = ''): void {
        $context_data = $context !== '' ? ['context' => $context] : [];
        if ($channel !== '') {
            $context_data['source'] = self::LOG_HANDLE . '-' . sanitize_key($channel);
        }
        self::instance()->do_log($message, $level, $context_data);
    }

    /**
     * PSR-3 log method — the single method required by AbstractLogger.
     * All PSR-3 convenience methods (info, error, warning, debug, etc.)
     * are provided by AbstractLogger and delegate to this method.
     *
     * @param mixed $level PSR-3 log level
     * @param string|\Stringable $message Log message
     * @param array $context Context array (PSR-3 style)
     * @return void
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void {
        $this->do_log((string) $message, (string) $level, $context);
    }

    /**
     * Internal log implementation — delegates to WooCommerce's own logger
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     * @param array $context PSR-3 context array
     * @return void
     */
    private function do_log(string $message, string $level = 'info', array $context = []): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()?->log(strtolower($level), $message, array_merge(['source' => self::LOG_HANDLE], $context));
        }
    }

    /**
     * Archive database history to a guarded PHP file
     * Called via the recurring wc_mss_archive_history scheduled action (see schedule_archival())
     *
     * @return array Result with success status and message
     */
    public static function archive_database_history(): array {
        $logs_dir = self::prepare_archive_directory();
        if ($logs_dir === false) {
            return ['success' => false, 'message' => 'Failed to protect archive directory', 'archived' => 0];
        }

        // Get history records older than rotation_days
        $days_to_keep = self::instance()->rotation_days;

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_mss_sync_history';

        // Get records to archive (older than $days_to_keep days)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Archive and remove current records from the custom history table; cached rows could omit newly completed syncs. Table name is the trusted WordPress prefix plus a fixed plugin suffix; runtime values are passed separately to prepare().
        $records = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY created_at ASC",
                $days_to_keep
            ),
            ARRAY_A
        );

        if (empty($records)) {
            return [
                'success' => true,
                'message' => 'No records to archive',
                'archived' => 0,
            ];
        }

        // Create archive file with date range
        $oldest_date = date('Y-m-d', strtotime($records[0]['created_at']));
        $newest_date = date('Y-m-d', strtotime($records[count($records) - 1]['created_at']));
        $archive_file = $logs_dir . 'history-archive-' . $oldest_date . '-to-' . $newest_date . '.php';

        // PHP guard protects data even when the server ignores .htaccess.
        $json_data = [
            'archived_at' => current_time('mysql'),
            'date_range' => [
                'from' => $oldest_date,
                'to' => $newest_date,
            ],
            'total_records' => count($records),
            'records' => $records,
        ];

        if (!self::write_protected_archive($archive_file, $json_data)) {
            return [
                'success' => false,
                'message' => 'Failed to write archive file',
                'archived' => 0,
            ];
        }

        // Delete archived records from database
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Archive and remove current records from the custom history table; cached rows could omit newly completed syncs. Table name is the trusted WordPress prefix plus a fixed plugin suffix; runtime values are passed separately to prepare().
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_to_keep
            )
        );

        // Clean up old archive files (keep only MAX_ARCHIVE_FILES)
        self::cleanup_old_archives($logs_dir, self::MAX_ARCHIVE_FILES);

        self::write(sprintf(
            'History archived: %d records to %s, deleted from database',
            count($records),
            basename($archive_file)
        ));

        return [
            'success' => true,
            'message' => sprintf('Archived %d records', count($records)),
            'archived' => count($records),
            'deleted' => $deleted,
            'file' => $archive_file,
        ];
    }

    /** Protect archives from previous plugin versions on the first admin request. */
    public static function migrate_archive_storage(): void {
        if ((int) get_option('wc_mss_archive_storage_version', 0) >= 1) {
            return;
        }
        if (self::prepare_archive_directory() !== false) {
            update_option('wc_mss_archive_storage_version', 1, false);
        }
    }

    /**
     * Prepare storage and protect legacy archives, including on no-record runs.
     * PHP files must be executed or denied by the web server, never served as source.
     *
     * @return string|false Archive directory, or false if protection failed.
     */
    public static function prepare_archive_directory(): string|false {
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/wc-mss-logs/';
        if (!is_dir($logs_dir) && !wp_mkdir_p($logs_dir)) {
            return false;
        }
        // Additional Apache protection; confidentiality does not depend on this file.
        if (!file_exists($logs_dir . '.htaccess')) {
            file_put_contents($logs_dir . '.htaccess', "deny from all\n");
        }
        foreach (glob($logs_dir . '*-archive-*.json') ?: [] as $legacy_file) {
            $json = file_get_contents($legacy_file);
            if ($json === false) {
                return false;
            }
            // Keep migrated data separate from new batches with the same date range.
            $protected_file = substr($legacy_file, 0, -5) . '-legacy-' . hash('sha256', $json) . '.php';
            $protected_content = self::ARCHIVE_GUARD . $json;
            if (!self::write_archive_contents($protected_file, $protected_content) || !unlink($legacy_file)) {
                return false;
            }
        }
        return $logs_dir;
    }

    /**
     * Write an archive without ever exposing an unguarded partial file.
     *
     * @param string $archive_file Destination PHP file.
     * @param array $data Archive data.
     * @return bool Whether the complete archive was installed.
     */
    public static function write_protected_archive(string $archive_file, array $data): bool {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        return $json !== false && self::write_archive_contents($archive_file, self::ARCHIVE_GUARD . $json);
    }

    /**
     * Atomically install the complete guarded contents before any source is deleted.
     *
     * @param string $archive_file Destination file.
     * @param string $contents Guard and payload.
     * @return bool Whether the entire file was committed.
     */
    private static function write_archive_contents(string $archive_file, string $contents): bool {
        $temporary_file = dirname($archive_file) . '/.wc-mss-archive-' . bin2hex(random_bytes(8)) . '.php';
        $written = file_put_contents($temporary_file, $contents, LOCK_EX);
        if ($written !== strlen($contents) || !rename($temporary_file, $archive_file)) {
            if (file_exists($temporary_file)) {
                unlink($temporary_file);
            }
            return false;
        }
        return true;
    }

    /**
     * Clean up old archive files
     *
     * @param string $logs_dir Logs directory path
     * @param int $max_files Maximum number of files to keep
     * @return void
     */
    private static function cleanup_old_archives(string $logs_dir, int $max_files): void {
        $files = glob($logs_dir . 'history-archive-*.php', GLOB_NOSORT) ?: [];

        if (count($files) > $max_files) {
            natsort($files);
            $to_delete = array_slice($files, 0, count($files) - $max_files);
            foreach ($to_delete as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Get log contents
     *
     * WooCommerce's file logger rotates to a new file every day, so
     * $this->log_file (today's file) is legitimately empty/missing until
     * the first entry of the day is written. Fall back to the most recent
     * existing log file so the admin page doesn't look broken first thing
     * each day.
     *
     * @param int $lines Number of lines to read (0 = all)
     * @return string Log contents
     */
    public function get_log(int $lines = 100): string {
        $log_file = file_exists($this->log_file) ? $this->log_file : $this->find_most_recent_log_file();

        if ($log_file === null) {
            return '';
        }

        if ($lines === 0) {
            return file_get_contents($log_file);
        }

        // Read last N lines
        $file = new SplFileObject($log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();

        $start_line = max(0, $total_lines - $lines);
        $file->seek($start_line);

        $log_content = '';
        while (!$file->eof()) {
            $log_content .= $file->current();
            $file->next();
        }

        return $log_content;
    }

    /**
     * Find the most recently dated log file for this handle (WC names them
     * "{handle}-{Y-m-d}-{hash}.log", so lexicographic sort matches date order).
     *
     * @return string|null
     */
    private function find_most_recent_log_file(): ?string {
        $files = glob(dirname($this->log_file) . '/' . self::LOG_HANDLE . '-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-*.log');

        if (empty($files)) {
            return null;
        }

        rsort($files);

        return $files[0];
    }

    /**
     * Clear log file
     *
     * @return bool Success
     */
    public function clear_log(): bool {
        if (!file_exists($this->log_file)) {
            return true;
        }
        return WC_Log_Handler_File::remove(self::LOG_HANDLE);
    }

    /**
     * Remove only WARNING and ERROR lines from the log, preserving INFO entries.
     * Matches WooCommerce's own log line format: "{ISO8601} {LEVEL} {message} ...".
     *
     * @return array{removed: int, kept: int}
     */
    public function clear_warnings_and_errors(): array {
        if (!file_exists($this->log_file)) {
            return ['removed' => 0, 'kept' => 0];
        }

        $lines   = file($this->log_file, FILE_IGNORE_NEW_LINES);
        $kept    = [];
        $removed = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\S+\s+(WARNING|ERROR)\b/', $line)) {
                $removed++;
            } else {
                $kept[] = $line;
            }
        }

        if ($removed === 0) {
            return ['removed' => 0, 'kept' => count($kept)];
        }

        $content = implode("\n", $kept);
        if ($content !== '') {
            $content .= "\n";
        }

        file_put_contents($this->log_file, $content, LOCK_EX);

        return ['removed' => $removed, 'kept' => count($kept)];
    }
}
