<?php
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
     * @return void
     */
    public static function write(string $message, string $level = 'info', string $context = ''): void {
        self::instance()->do_log($message, $level, $context !== '' ? ['context' => $context] : []);
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
            wc_get_logger()?->log(strtolower($level), $message, array_merge($context, ['source' => self::LOG_HANDLE]));
        }
    }

    /**
     * Archive database history to JSON file
     * Called via the recurring wc_mss_archive_history scheduled action (see schedule_archival())
     *
     * @return array Result with success status and message
     */
    public static function archive_database_history(): array {
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/wc-mss-logs/';

        // Ensure directory exists
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
            file_put_contents($logs_dir . '.htaccess', "deny from all\n");
        }

        // Get history records older than rotation_days
        $days_to_keep = self::instance()->rotation_days;

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_mss_sync_history';

        // Get records to archive (older than $days_to_keep days)
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
        $archive_file = $logs_dir . 'history-archive-' . $oldest_date . '-to-' . $newest_date . '.json';

        // Write records to JSON file
        $json_data = [
            'archived_at' => current_time('mysql'),
            'date_range' => [
                'from' => $oldest_date,
                'to' => $newest_date,
            ],
            'total_records' => count($records),
            'records' => $records,
        ];

        $write_result = file_put_contents($archive_file, json_encode($json_data, JSON_PRETTY_PRINT));

        if ($write_result === false) {
            return [
                'success' => false,
                'message' => 'Failed to write archive file',
                'archived' => 0,
            ];
        }

        // Delete archived records from database
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

    /**
     * Clean up old archive files
     *
     * @param string $logs_dir Logs directory path
     * @param int $max_files Maximum number of files to keep
     * @return void
     */
    private static function cleanup_old_archives(string $logs_dir, int $max_files): void {
        $files = glob($logs_dir . 'history-archive-*.json', GLOB_NOSORT);

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
     * @param int $lines Number of lines to read (0 = all)
     * @return string Log contents
     */
    public function get_log(int $lines = 100): string {
        if (!file_exists($this->log_file)) {
            return '';
        }

        if ($lines === 0) {
            return file_get_contents($this->log_file);
        }

        // Read last N lines
        $file = new SplFileObject($this->log_file, 'r');
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

    /**
     * Log product sync
     *
     * @param int $product_id Product ID
     * @param string $action Action (created, updated, deleted)
     * @param string $store_url Store URL
     * @param bool $success Success status
     * @param string $message Additional message
     * @return void
     */
    public function log_product_sync(int $product_id, string $action, string $store_url, bool $success, string $message = ''): void {
        $level = $success ? 'info' : 'error';
        $status = $success ? 'SUCCESS' : 'FAILED';

        $sku = wc_get_product($product_id)?->get_sku() ?: 'N/A';

        $log_message = sprintf(
            'SKU %s %s to %s - %s',
            $sku ?: 'no-sku',
            $action,
            $store_url,
            $status
        );

        if (!empty($message)) {
            $log_message .= ' - ' . $message;
        }

        self::write($log_message, $level);
    }
}
