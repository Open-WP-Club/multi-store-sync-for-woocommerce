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
     * Default max log file size in bytes (10MB)
     */
    const int DEFAULT_MAX_LOG_SIZE = 10485760;

    /**
     * Default number of backup files to keep
     */
    const int DEFAULT_MAX_BACKUP_FILES = 10;

    /**
     * Default rotation interval in days
     */
    const int DEFAULT_ROTATION_DAYS = 7;

    /**
     * Singleton instance
     *
     * @var WC_Multi_Store_Logger|null
     */
    private static $instance = null;

    /**
     * Log file path
     *
     * @var string
     */
    private readonly string $log_file;

    /**
     * Debug mode
     *
     * @var bool
     */
    private readonly bool $debug_mode;

    /**
     * Log buffer for reducing file I/O
     *
     * @var array
     */
    private array $buffer = [];

    /**
     * Buffer size limit before auto-flush
     *
     * @var int
     */
    private readonly int $buffer_size;

    /**
     * Max log file size before rotation (in bytes)
     *
     * @var int
     */
    private int $max_log_size;

    /**
     * Max number of backup files to keep
     *
     * @var int
     */
    private int $max_backup_files;

    /**
     * Rotation interval in days
     *
     * @var int
     */
    private int $rotation_days;

    /**
     * Last rotation timestamp option key
     *
     * @var string
     */
    private readonly string $last_rotation_option;

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
        // Store logs in uploads directory — survives plugin updates and can be
        // centrally protected. Falls back to plugin dir if uploads is unavailable.
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/wc-mss-logs';

        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
            // Protect directory from direct web access (Apache)
            file_put_contents($logs_dir . '/.htaccess', "deny from all\n");
            // Protect directory from direct web access (Nginx — requires include in server block)
            // Users should add: include /path/to/uploads/wc-mss-logs/nginx.conf; in their Nginx config
            file_put_contents($logs_dir . '/nginx.conf', "location ~* /wc-mss-logs/ {\n    deny all;\n    return 403;\n}\n");
            // Fallback: index.php prevents directory listing
            file_put_contents($logs_dir . '/index.php', "<?php\n// Silence is golden.\n");
        }

        $this->log_file = $logs_dir . '/sync.log';
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        $this->buffer_size = 10;
        $this->last_rotation_option = 'wc_mss_last_log_rotation';

        // Load configurable rotation settings
        $this->load_rotation_settings();
    }

    /**
     * Load rotation settings from options or constants
     *
     * @return void
     */
    private function load_rotation_settings(): void {
        $settings = get_option('wc_multi_store_sync_settings', []);

        // Max log size
        if (defined('WC_MSS_MAX_LOG_SIZE')) {
            $this->max_log_size = (int) WC_MSS_MAX_LOG_SIZE;
        } else {
            $this->max_log_size = isset($settings['max_log_size'])
                ? (int) $settings['max_log_size']
                : self::DEFAULT_MAX_LOG_SIZE;
        }

        // Max backup files (default 10)
        if (defined('WC_MSS_MAX_BACKUP_FILES')) {
            $this->max_backup_files = (int) WC_MSS_MAX_BACKUP_FILES;
        } else {
            $this->max_backup_files = isset($settings['max_backup_files'])
                ? (int) $settings['max_backup_files']
                : self::DEFAULT_MAX_BACKUP_FILES;
        }

        // Rotation days (default 7)
        if (defined('WC_MSS_ROTATION_DAYS')) {
            $this->rotation_days = (int) WC_MSS_ROTATION_DAYS;
        } else {
            $this->rotation_days = isset($settings['rotation_days'])
                ? (int) $settings['rotation_days']
                : self::DEFAULT_ROTATION_DAYS;
        }

        // Enforce reasonable limits
        $this->max_log_size = max(1048576, min(104857600, $this->max_log_size)); // 1MB - 100MB
        $this->max_backup_files = max(1, min(20, $this->max_backup_files)); // 1 - 20 files
        $this->rotation_days = max(1, min(30, $this->rotation_days)); // 1 - 30 days
    }

    /**
     * Get current rotation settings
     *
     * Uses the singleton instance to avoid creating a new Logger
     * (which would trigger directory creation and settings loading).
     *
     * @return array Settings array
     */
    public static function get_rotation_settings(): array {
        $instance = self::instance();
        return [
            'max_log_size' => $instance->max_log_size,
            'max_log_size_mb' => round($instance->max_log_size / 1048576, 2),
            'max_backup_files' => $instance->max_backup_files,
            'rotation_days' => $instance->rotation_days,
        ];
    }

    /**
     * Destructor - flush any remaining buffered logs
     */
    public function __destruct() {
        $this->flush_buffer();
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
        self::instance()->do_log($message, $level, $context);
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
        $context_string = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $this->do_log((string) $message, (string) $level, $context_string);
    }

    /**
     * Internal log implementation
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     * @param string $context Additional context
     * @return void
     */
    private function do_log(string $message, string $level = 'info', string $context = ''): void {
        $timestamp = current_time('Y-m-d H:i:s');
        $level = strtoupper($level);

        $log_entry = sprintf(
            "[%s] [%s] %s",
            $timestamp,
            $level,
            $message
        );

        if (!empty($context)) {
            $log_entry .= ' | Context: ' . $context;
        }

        $log_entry .= PHP_EOL;

        // Write to file
        $this->write_to_file($log_entry);

        // Also write to WooCommerce logger if available
        if (function_exists('wc_get_logger')) {
            wc_get_logger()?->log($level, $message, ['source' => 'wc-multi-store-sync']);
        }

        // Write to error log if debug mode and error level
        if ($this->debug_mode && $level === 'ERROR') {
            error_log('WC Multi-Store Sync: ' . $message);
        }
    }

    /**
     * Write log entry to buffer (or file for critical errors)
     * PERFORMANCE FIX: Buffering reduces file I/O operations
     *
     * @param string $log_entry Log entry
     * @return void
     */
    private function write_to_file(string $log_entry): void {
        // Add to buffer
        $this->buffer[] = $log_entry;

        // Auto-flush if buffer is full
        if (count($this->buffer) >= $this->buffer_size) {
            $this->flush_buffer();
        }
    }

    /**
     * Flush buffered log entries to file
     * PERFORMANCE FIX: Reduces file I/O by writing multiple entries at once
     *
     * @return void
     */
    public function flush_buffer(): void {
        if (empty($this->buffer)) {
            return;
        }

        // Ensure logs directory exists and is writable
        $logs_dir = dirname($this->log_file);
        if (!file_exists($logs_dir)) {
            if (!wp_mkdir_p($logs_dir)) {
                // Can't create directory, clear buffer and return
                $this->buffer = [];
                return;
            }
        }

        // PERFORMANCE FIX: Check if directory is writable before attempting write
        if (!is_writable($logs_dir)) {
            $this->buffer = [];
            return;
        }

        // Check for time-based rotation before writing
        if ($this->should_rotate_by_time()) {
            $this->rotate_log('time');
        }

        // Write all buffered entries at once
        file_put_contents($this->log_file, implode('', $this->buffer), FILE_APPEND | LOCK_EX);

        // Clear buffer
        $this->buffer = [];

        // Rotate log file if too large (configurable size)
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_log_size) {
            $this->rotate_log('size');
        }
    }

    /**
     * Check if time-based rotation is needed
     *
     * @return bool True if rotation is needed
     */
    private function should_rotate_by_time(): bool {
        $last_rotation = (int) get_option($this->last_rotation_option, 0);

        if (!$last_rotation) {
            // First time - set the timestamp and don't rotate
            update_option($this->last_rotation_option, time());
            return false;
        }

        $days_since_rotation = (time() - $last_rotation) / DAY_IN_SECONDS;

        return $days_since_rotation >= $this->rotation_days;
    }

    /**
     * Rotate log file
     *
     * @param string $reason Reason for rotation: 'size' or 'time'
     * @return void
     */
    private function rotate_log(string $reason = 'size'): void {
        if (file_exists($this->log_file)) {
            $backup_file = $this->log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
            rename($this->log_file, $backup_file);

            // Keep only configured number of backup files
            $this->cleanup_old_logs();

            // If time-based rotation, also archive database history
            if ($reason === 'time') {
                update_option($this->last_rotation_option, time());

                // Schedule database history archival (runs async to avoid blocking)
                if (WC_Multi_Store_Action_Scheduler_Manager::is_available()
                    && !as_next_scheduled_action('wc_mss_archive_history', [], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP)
                ) {
                    as_schedule_single_action(time() + 5, 'wc_mss_archive_history', [], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP);
                }
            }
        }
    }

    /**
     * Archive database history to JSON file
     * This is called via scheduled event after time-based rotation
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

        // Clean up old archive files (keep only max_backup_files)
        self::cleanup_old_archives($logs_dir, self::instance()->max_backup_files);

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
     * Cleanup old log files
     * PERFORMANCE FIX: More efficient sorting with natsort instead of usort with filemtime
     *
     * @return void
     */
    private function cleanup_old_logs(): void {
        $logs_dir = dirname($this->log_file);
        $files = glob($logs_dir . '/*.bak', GLOB_NOSORT);

        if (count($files) > $this->max_backup_files) {
            // PERFORMANCE FIX: Use natsort which is faster than usort with filemtime
            // File names already contain timestamps, so natural sort works
            natsort($files);

            // Delete oldest files (keep only max_backup_files)
            $to_delete = array_slice($files, 0, count($files) - $this->max_backup_files);
            foreach ($to_delete as $file) {
                @unlink($file); // Suppress warnings if file already deleted
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
        if (file_exists($this->log_file)) {
            return unlink($this->log_file);
        }
        return true;
    }

    /**
     * Remove only [WARNING] and [ERROR] lines from the log, preserving INFO entries.
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
            if (str_contains($line, '[WARNING]') || str_contains($line, '[ERROR]')) {
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
