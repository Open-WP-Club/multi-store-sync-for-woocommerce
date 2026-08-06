<?php
/**
 * Action Scheduler Manager Class
 * Manages scheduled actions using WooCommerce Action Scheduler
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Action_Scheduler_Manager {

    /**
     * Action hook for queue processing
     */
    const string ACTION_HOOK_QUEUE = 'wc_multi_store_sync_process_queue';

    /**
     * Action hook for scheduled sync check
     */
    const string ACTION_HOOK_SCHEDULED_SYNC = 'wc_multi_store_sync_scheduled_check';

    /**
     * Action hook for debounced order processing
     */
    const string ACTION_HOOK_DEBOUNCED_ORDER = 'wc_multi_store_sync_process_debounced_order';

    /**
     * Action group for all sync actions
     */
    const string ACTION_GROUP = 'wc_multi_store_sync';

    /**
     * Check if Action Scheduler is available
     *
     * @return bool
     */
    public static function is_available(): bool {
        return function_exists('as_schedule_recurring_action') &&
               function_exists('as_schedule_single_action') &&
               class_exists('ActionScheduler');
    }

    /**
     * Action hook for force sync batch processing
     */
    const string ACTION_HOOK_FORCE_SYNC_BATCH = 'wc_multi_store_sync_force_sync_batch';

    /**
     * Action hook for daily maintenance (cleanup old data)
     */
    const string ACTION_HOOK_MAINTENANCE = 'wc_multi_store_sync_daily_maintenance';

    /**
     * Initialize the Action Scheduler manager
     */
    public function __construct() {
        // Register action hooks
        add_action(self::ACTION_HOOK_QUEUE, $this->process_queue_action(...));
        add_action(self::ACTION_HOOK_SCHEDULED_SYNC, $this->scheduled_sync_action(...));
        add_action(self::ACTION_HOOK_DEBOUNCED_ORDER, $this->process_debounced_order_action(...), 10, 1);
        add_action(self::ACTION_HOOK_FORCE_SYNC_BATCH, $this->process_force_sync_batch(...), 10, 1);
        add_action(self::ACTION_HOOK_MAINTENANCE, $this->daily_maintenance_action(...));

        // Schedule recurring actions after Action Scheduler is loaded
        // Action Scheduler is available after 'init' with priority 1, so we use priority 10
        add_action('init', $this->ensure_scheduled(...), 10);
    }

    /**
     * Process force sync batch action callback
     *
     * @param int $page Page number to process
     */
    public function process_force_sync_batch(int $page = 1): void {
        try {
            WC_Multi_Store_Settings_Integration::process_force_sync_batch($page);
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'Force sync batch failed for page %d: %s in %s:%d',
                $page,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');
        }
    }

    /**
     * Ensure recurring actions are scheduled
     * Throttled to run at most once every 5 minutes to avoid DB queries on every page load
     */
    public function ensure_scheduled(): void {
        // Verify Action Scheduler is available
        if (!self::is_available()) {
            return;
        }

        // Throttle: only check once every 5 minutes (avoids 4+ DB queries per page load)
        $throttle_key = 'wc_mss_ensure_scheduled';
        if (get_transient($throttle_key)) {
            return;
        }
        set_transient($throttle_key, 1, 5 * MINUTE_IN_SECONDS);

        // Schedule queue processor (every 1 minute)
        if (!as_next_scheduled_action(self::ACTION_HOOK_QUEUE, [], self::ACTION_GROUP)) {
            $this->schedule_queue_processor();
        }

        // Schedule sync check (every 10 minutes)
        if (!as_next_scheduled_action(self::ACTION_HOOK_SCHEDULED_SYNC, [], self::ACTION_GROUP)) {
            $this->schedule_sync_check();
        }

        // Schedule daily maintenance (every 24 hours)
        if (!as_next_scheduled_action(self::ACTION_HOOK_MAINTENANCE, [], self::ACTION_GROUP)) {
            $this->schedule_maintenance();
        }

        // Schedule weekly verification if enabled (check if not already scheduled)
        $weekly_next = as_next_scheduled_action('wc_multi_store_weekly_verification', [], self::ACTION_GROUP);
        if (!$weekly_next) {
            if (class_exists('WC_Multi_Store_Weekly_Sync_Verifier')) {
                $settings = WC_Multi_Store_Weekly_Sync_Verifier::get_settings();
                if ($settings['enabled']) {
                    WC_Multi_Store_Logger::write('Weekly verification not scheduled but enabled, scheduling now');
                    WC_Multi_Store_Weekly_Sync_Verifier::schedule_verification();
                }
            }
        }
    }

    /**
     * Schedule daily maintenance action
     * Runs once per day to clean up old data
     */
    public function schedule_maintenance(): void {
        if (!self::is_available()) {
            return;
        }

        // Schedule to run daily (every 24 hours)
        as_schedule_recurring_action(
            strtotime('tomorrow 3:00am'), // Start at 3 AM to avoid peak hours
            DAY_IN_SECONDS,
            self::ACTION_HOOK_MAINTENANCE,
            [],
            self::ACTION_GROUP
        );

        WC_Multi_Store_Logger::write('Daily maintenance action scheduled');
    }

    /**
     * Daily maintenance action callback
     * Cleans up old data from various tables to prevent database bloat
     */
    public function daily_maintenance_action(): array {
        $start_time = microtime(true);
        $results = [];

        try {
            WC_Multi_Store_Logger::write('Starting daily maintenance');

            // 1. Clean up old queue items (completed/failed > 7 days)
            if (class_exists('WC_Multi_Store_Queue_Table')) {
                $queue_cleaned = WC_Multi_Store_Queue_Table::cleanup(7);
                $results['queue'] = $queue_cleaned;
            }

            // 2. Clean up old sync history (> 30 days)
            if (class_exists('WC_Multi_Store_Sync_History')) {
                $history_cleaned = WC_Multi_Store_Sync_History::cleanup_old_records(30);
                $results['history'] = $history_cleaned;
            }

            // 3. Clean up old webhook logs (> 30 days)
            if (class_exists('WC_Multi_Store_Webhook_Logger')) {
                $webhook_cleaned = WC_Multi_Store_Webhook_Logger::cleanup_old_logs(30);
                $results['webhook_logs'] = $webhook_cleaned;
            }

            // 4. Clean up old verification reports (> 90 days)
            if (class_exists('WC_Multi_Store_Weekly_Sync_Verifier')) {
                $reports_cleaned = WC_Multi_Store_Weekly_Sync_Verifier::cleanup_old_reports(90);
                $results['verification_reports'] = $reports_cleaned;
            }

            // 5. Clean up old stock discrepancies (> 30 days)
            if (class_exists('WC_Multi_Store_Stock_Verifier')) {
                $stock_cleaned = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies(30);
                $results['stock_discrepancies'] = $stock_cleaned;
            }

            // 6. Clean up old deletion audit logs (> 90 days)
            if (class_exists('WC_Multi_Store_Deletion_Audit')) {
                $audit_cleaned = WC_Multi_Store_Deletion_Audit::cleanup_old_logs(90);
                $results['deletion_audit'] = $audit_cleaned;
            }

            // 7. Clean up expired plugin transients
            $transients_cleaned = $this->cleanup_expired_transients();
            $results['transients'] = $transients_cleaned;

            // 8. Refresh remote term caches (categories/tags)
            // This ensures fresh data for the next sync cycle
            if (class_exists('WC_Multi_Store_Sync_Engine')) {
                WC_Multi_Store_Sync_Engine::clear_term_cache();
                $results['term_cache'] = 'cleared';
            }

            $duration = round(microtime(true) - $start_time, 2);

            WC_Multi_Store_Logger::write(sprintf(
                'Daily maintenance completed in %ss: Queue=%d, History=%d, Webhooks=%d, Reports=%d, Stock=%d, Audit=%d, Transients=%d, TermCache=%s',
                $duration,
                $results['queue'] ?? 0,
                $results['history'] ?? 0,
                $results['webhook_logs'] ?? 0,
                $results['verification_reports'] ?? 0,
                $results['stock_discrepancies'] ?? 0,
                $results['deletion_audit'] ?? 0,
                $results['transients'] ?? 0,
                $results['term_cache'] ?? 'skipped'
            ));
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'Daily maintenance failed with exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');
        }

        return $results;
    }

    /**
     * Clean up expired plugin transients from wp_options.
     *
     * Two-step approach instead of a single DELETE … LEFT JOIN: the join
     * predicate (CONCAT + SUBSTRING) defeats the wp_options index, so on
     * sites with a large options table the join scan + DELETE row-locks can
     * spike P99 latency for unrelated writes. SELECTing option_ids first
     * (read-only), then deleting in 500-row chunks, keeps locks short.
     *
     * @return int Number of transient option rows deleted
     */
    private function cleanup_expired_transients(): int {
        global $wpdb;

        // Step 1: find expired timeout rows for our prefix (read-only).
        $expired = $wpdb->get_results(
            "SELECT t.option_id  AS timeout_id,
                    v.option_id  AS value_id,
                    v.option_name AS value_name
             FROM {$wpdb->options} t
             INNER JOIN {$wpdb->options} v
                 ON v.option_name = CONCAT('_transient_', SUBSTRING(t.option_name, 20))
             WHERE t.option_name LIKE '_transient_timeout_wc_mss_%'
               AND t.option_value < UNIX_TIMESTAMP()"
        );

        if (empty($expired)) {
            return 0;
        }

        $ids = [];
        foreach ($expired as $row) {
            $ids[] = (int) $row->timeout_id;
            $ids[] = (int) $row->value_id;
        }

        // Step 2: delete in chunks of 500 to keep each lock window short.
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $rows         = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_id IN ($placeholders)",
                $chunk
            ));
            if ($rows) {
                $deleted += (int) $rows;
            }
        }

        return $deleted;
    }

    /**
     * Schedule the queue processor
     * Runs every 1 minute
     */
    public function schedule_queue_processor(): void {
        if (!self::is_available()) {
            return;
        }

        as_schedule_recurring_action(
            time(),
            1 * MINUTE_IN_SECONDS,
            self::ACTION_HOOK_QUEUE,
            [],
            self::ACTION_GROUP
        );

        WC_Multi_Store_Logger::write('Queue processor scheduled via Action Scheduler (every 1 minute)');
    }

    /**
     * Schedule the sync check based on settings
     */
    public function schedule_sync_check(): void {
        if (!self::is_available()) {
            return;
        }

        $settings = get_option('wc_multi_store_sync_scheduled', []);
        $enabled = $settings['scheduled_sync_enabled'] ?? true;

        // If disabled, unschedule and return
        if (!$enabled) {
            as_unschedule_all_actions(self::ACTION_HOOK_SCHEDULED_SYNC, [], self::ACTION_GROUP);
            WC_Multi_Store_Logger::write('Scheduled sync disabled');
            return;
        }

        $interval = $settings['scheduled_sync_interval'] ?? '10min';
        $interval_seconds = self::get_interval_seconds($interval);

        as_schedule_recurring_action(
            time(),
            $interval_seconds,
            self::ACTION_HOOK_SCHEDULED_SYNC,
            [],
            self::ACTION_GROUP
        );

        WC_Multi_Store_Logger::write(sprintf('Scheduled sync check via Action Scheduler (interval: %s)', $interval));
    }

    /**
     * Get interval in seconds from setting value
     *
     * @param string $interval Interval setting value
     * @return int Seconds
     */
    private static function get_interval_seconds(string $interval): int {
        return match ($interval) {
            '30min' => 30 * MINUTE_IN_SECONDS,
            'hourly' => HOUR_IN_SECONDS,
            'daily' => DAY_IN_SECONDS,
            default => 10 * MINUTE_IN_SECONDS,
        };
    }

    /**
     * Reschedule sync check with current settings
     * Called when settings are saved
     */
    public static function reschedule_sync_check(): void {
        if (!self::is_available()) {
            return;
        }

        // Unschedule existing
        as_unschedule_all_actions(self::ACTION_HOOK_SCHEDULED_SYNC, [], self::ACTION_GROUP);

        // Schedule with new settings
        $manager = new self();
        $manager->schedule_sync_check();
    }

    /**
     * Schedule a single debounced order action
     *
     * @param int $order_id Order ID
     * @param int $delay Delay in seconds
     */
    public static function schedule_debounced_order(int $order_id, int $delay = 60): void {
        if (!self::is_available()) {
            return;
        }

        // Cancel any existing scheduled action for this order
        as_unschedule_all_actions(
            self::ACTION_HOOK_DEBOUNCED_ORDER,
            ['order_id' => $order_id],
            self::ACTION_GROUP
        );

        // Schedule new action
        as_schedule_single_action(
            time() + $delay,
            self::ACTION_HOOK_DEBOUNCED_ORDER,
            ['order_id' => $order_id],
            self::ACTION_GROUP
        );

        WC_Multi_Store_Logger::write(sprintf('Debounced order action scheduled for order #%d (delay: %ds)', $order_id, $delay));
    }

    /**
     * Unschedule all actions
     */
    public static function unschedule_all(): void {
        if (!self::is_available()) {
            return;
        }

        // Core recurring actions
        as_unschedule_all_actions(self::ACTION_HOOK_QUEUE, [], self::ACTION_GROUP);
        as_unschedule_all_actions(self::ACTION_HOOK_SCHEDULED_SYNC, [], self::ACTION_GROUP);
        as_unschedule_all_actions(self::ACTION_HOOK_MAINTENANCE, [], self::ACTION_GROUP);

        // Migrated recurring actions (formerly WP Cron)
        as_unschedule_all_actions('wc_multi_store_sync_remote_orders', [], self::ACTION_GROUP);
        as_unschedule_all_actions('wc_multi_store_health_check', [], self::ACTION_GROUP);
        as_unschedule_all_actions('wc_mss_send_daily_summary', [], self::ACTION_GROUP);

        // Note: Don't unschedule debounced orders as they may be in progress

        WC_Multi_Store_Logger::write('All scheduled actions unscheduled');
    }

    /**
     * Process queue action callback
     */
    public function process_queue_action(): void {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        try {
            // Log queue stats for debugging
            $stats = WC_Multi_Store_Queue_Table::get_stats();
            if ($stats['pending'] > 0) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Queue processor triggered: %d pending, %d processing',
                    $stats['pending'],
                    $stats['processing']
                ));
            }

            // Check if sync is enabled (main settings)
            $settings = WC_Multi_Store_Settings::get_settings();
            $enabled = $settings['enabled'] ?? false;

            if (!$enabled) {
                WC_Multi_Store_Logger::write('Queue processor: Sync is disabled, skipping');
                return;
            }

            // Process the queue
            $result = WC_MSS()->queue_manager->process_queue();

            // Log the result based on status
            if (isset($result['status'])) {
                if ($result['status'] === 'completed' && isset($result['processed']) && $result['processed'] > 0) {
                    WC_Multi_Store_Logger::write(sprintf(
                        'Queue processor completed: %d processed, %d successful, %d failed',
                        $result['processed'] ?? 0,
                        $result['success'] ?? 0,
                        $result['failed'] ?? 0
                    ));
                } elseif ($result['status'] === 'empty') {
                    // Don't log empty queue to avoid spam
                } elseif ($result['status'] === 'skipped') {
                    WC_Multi_Store_Logger::write('Queue processor: ' . ($result['message'] ?? 'Skipped'));
                }
            }

            // Update last run time
            update_option('wc_multi_store_sync_last_queue_run', time(), false);
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'Queue processor failed with exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');
        }
    }

    /**
     * Scheduled sync check action callback
     * Uses batched processing to avoid memory issues with large catalogs
     */
    public function scheduled_sync_action(): void {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        try {
            // Check if sync is enabled (main settings)
            $settings = WC_Multi_Store_Settings::get_settings();
            $enabled = $settings['enabled'] ?? false;

            if (!$enabled) {
                return;
            }

            // Check if scheduled sync is enabled
            $scheduled_settings = get_option('wc_multi_store_sync_scheduled', []);
            $scheduled_enabled = $scheduled_settings['scheduled_sync_enabled'] ?? true;

            if (!$scheduled_enabled) {
                return;
            }

            // Determine sync type for scheduled sync
            $sync_type_override = null;
            $scheduled_sync_type = $scheduled_settings['scheduled_sync_type'] ?? 'use_default';

            if ($scheduled_sync_type !== 'use_default') {
                $sync_type_override = $scheduled_sync_type;
            }

            WC_Multi_Store_Logger::write(sprintf(
                'Scheduled sync check started (Action Scheduler) - Sync type: %s',
                $sync_type_override ?: 'default from settings'
            ));

            // Process products in batches to avoid memory issues
            $batch_size = 500;
            $page = 1;
            $total_added = 0;

            do {
                $products = $this->get_products_for_scheduled_sync_batch($page, $batch_size);

                if (!empty($products)) {
                    $added = WC_MSS()->queue_manager->add_products(
                        $products,
                        'scheduled_sync',
                        5, // Medium priority
                        $sync_type_override
                    );
                    $total_added += $added;
                }

                $page++;

                // Free memory between batches — only clear WooCommerce product cache,
                // not the entire WordPress object cache (wp_cache_flush() destroys
                // cached data for ALL plugins on the site)
                if (function_exists('wc_reset_product_cache')) {
                    foreach ($products as $pid) {
                        wc_reset_product_cache($pid);
                    }
                }

            } while (count($products) === $batch_size);

            if ($total_added === 0) {
                WC_Multi_Store_Logger::write('Scheduled sync: No products to sync');
            } else {
                WC_Multi_Store_Logger::write(sprintf(
                    'Scheduled sync: %d product(s) added to queue (Type: %s)',
                    $total_added,
                    $sync_type_override ?: 'default'
                ));
            }

            // Update last run time
            update_option('wc_multi_store_sync_last_scheduled_run', time(), false);
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'Scheduled sync failed with exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');
        }
    }

    /**
     * Process debounced order action callback
     *
     * @param int $order_id Order ID
     */
    public function process_debounced_order_action(int $order_id): void {
        try {
            if (!class_exists('WC_Multi_Store_Order_Sync')) {
                WC_Multi_Store_Logger::write('Order sync class not found', 'error');
                return;
            }

            WC_Multi_Store_Order_Sync::process_debounced_order($order_id);
        } catch (\Throwable $e) {
            WC_Multi_Store_Logger::write(sprintf(
                'Debounced order sync failed for order #%d: %s in %s:%d',
                $order_id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'error');
        }
    }

    /**
     * Get products for scheduled sync in batches
     * Memory-efficient version that loads products page by page
     *
     * @param int $page Page number (1-indexed)
     * @param int $batch_size Number of products per batch
     * @return array Product IDs
     */
    private function get_products_for_scheduled_sync_batch(int $page = 1, int $batch_size = 500): array {
        $settings = get_option('wc_multi_store_sync_scheduled', []);

        $sync_all = $settings['sync_all_products'] ?? true;

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'paged' => $page,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true, // Skip counting for better performance
        ];

        if (!$sync_all) {
            $hours = (int) ($settings['sync_modified_hours'] ?? 24);
            $args['date_query'] = [
                [
                    'column' => 'post_modified',
                    'after' => $hours . ' hours ago',
                ],
            ];
        }

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Get action status information
     *
     * @return array Status information
     */
    public static function get_status(): array {
        $last_queue_run = get_option('wc_multi_store_sync_last_queue_run');
        $last_scheduled_run = get_option('wc_multi_store_sync_last_scheduled_run');

        // Return limited info if Action Scheduler not available
        if (!self::is_available()) {
            return [
                'scheduler_type' => 'Not Available',
                'queue_processor' => [
                    'is_scheduled' => false,
                    'next_run' => null,
                    'next_run_relative' => null,
                    'last_run' => $last_queue_run ? date('Y-m-d H:i:s', $last_queue_run) : null,
                    'last_run_relative' => $last_queue_run ? human_time_diff($last_queue_run, current_time('timestamp')) . ' ago' : null,
                ],
                'scheduled_sync' => [
                    'is_scheduled' => false,
                    'next_run' => null,
                    'next_run_relative' => null,
                    'last_run' => $last_scheduled_run ? date('Y-m-d H:i:s', $last_scheduled_run) : null,
                    'last_run_relative' => $last_scheduled_run ? human_time_diff($last_scheduled_run, current_time('timestamp')) . ' ago' : null,
                ],
                'pending_actions' => 0,
                'failed_actions' => 0,
            ];
        }

        $queue_next = as_next_scheduled_action(self::ACTION_HOOK_QUEUE, [], self::ACTION_GROUP);
        $sync_next = as_next_scheduled_action(self::ACTION_HOOK_SCHEDULED_SYNC, [], self::ACTION_GROUP);

        // Use time() for comparison since Action Scheduler returns UTC timestamps
        $now = time();

        return [
            'scheduler_type' => 'Action Scheduler',
            'queue_processor' => [
                'is_scheduled' => (bool) $queue_next,
                'next_run' => $queue_next ? wp_date('Y-m-d H:i:s', $queue_next) : null,
                'next_run_relative' => $queue_next ? human_time_diff($queue_next, $now) : null,
                'last_run' => $last_queue_run ? wp_date('Y-m-d H:i:s', $last_queue_run) : null,
                'last_run_relative' => $last_queue_run ? human_time_diff($last_queue_run, $now) . ' ago' : null,
            ],
            'scheduled_sync' => [
                'is_scheduled' => (bool) $sync_next,
                'next_run' => $sync_next ? wp_date('Y-m-d H:i:s', $sync_next) : null,
                'next_run_relative' => $sync_next ? human_time_diff($sync_next, $now) : null,
                'last_run' => $last_scheduled_run ? wp_date('Y-m-d H:i:s', $last_scheduled_run) : null,
                'last_run_relative' => $last_scheduled_run ? human_time_diff($last_scheduled_run, $now) . ' ago' : null,
            ],
            'pending_actions' => self::get_pending_count(),
            'failed_actions' => self::get_failed_count(),
        ];
    }

    /**
     * Get count of pending actions
     *
     * @return int
     */
    public static function get_pending_count(): int {
        if (!self::is_available()) {
            return 0;
        }

        return ActionScheduler::store()->query_actions([
            'group' => self::ACTION_GROUP,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1,
        ], 'count');
    }

    /**
     * Get count of failed actions
     *
     * @return int
     */
    public static function get_failed_count(): int {
        if (!self::is_available()) {
            return 0;
        }

        return ActionScheduler::store()->query_actions([
            'group' => self::ACTION_GROUP,
            'status' => ActionScheduler_Store::STATUS_FAILED,
            'per_page' => -1,
        ], 'count');
    }

    /**
     * Reschedule all actions
     */
    public static function reschedule_all(): void {
        if (!self::is_available()) {
            return;
        }

        self::unschedule_all();

        $manager = new self();
        $manager->schedule_queue_processor();
        $manager->schedule_sync_check();

        WC_Multi_Store_Logger::write('All actions rescheduled via Action Scheduler');
    }

    /**
     * Clean up duplicate scheduled actions
     *
     * This fixes issues where multiple recurring actions exist for the same hook
     * (e.g., from code changes or failed unschedule operations)
     *
     * @return array Cleanup results
     */
    public static function cleanup_duplicate_actions(): array {
        if (!self::is_available()) {
            return ['cleaned' => 0, 'error' => 'Action Scheduler not available'];
        }

        $hooks_to_check = [
            self::ACTION_HOOK_QUEUE,
            self::ACTION_HOOK_SCHEDULED_SYNC,
        ];

        $total_cleaned = 0;
        $details = [];

        foreach ($hooks_to_check as $hook) {
            // Get all pending actions for this hook
            $actions = ActionScheduler::store()->query_actions([
                'hook' => $hook,
                'group' => self::ACTION_GROUP,
                'status' => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
                'orderby' => 'date',
                'order' => 'ASC',
            ]);

            $action_count = count($actions);

            // If more than one action exists, keep only the earliest one
            if ($action_count > 1) {
                $cleaned = 0;
                // Skip the first one (keep it), delete the rest
                for ($i = 1; $i < $action_count; $i++) {
                    try {
                        ActionScheduler::store()->delete_action($actions[$i]);
                        $cleaned++;
                    } catch (Exception $e) {
                        WC_Multi_Store_Logger::write(sprintf(
                            'Failed to delete duplicate action %d: %s',
                            $actions[$i],
                            $e->getMessage()
                        ), 'warning');
                    }
                }

                if ($cleaned > 0) {
                    $total_cleaned += $cleaned;
                    $details[$hook] = sprintf('Removed %d duplicate(s)', $cleaned);
                    WC_Multi_Store_Logger::write(sprintf(
                        'Cleaned up %d duplicate action(s) for hook: %s',
                        $cleaned,
                        $hook
                    ));
                }
            }
        }

        if ($total_cleaned > 0) {
            WC_Multi_Store_Logger::write(sprintf(
                'Action cleanup completed: %d duplicate action(s) removed',
                $total_cleaned
            ));
        }

        return [
            'cleaned' => $total_cleaned,
            'details' => $details,
        ];
    }

    /**
     * Ensure clean scheduled state (cleanup + ensure scheduled)
     * Call this on plugin activation to fix any scheduling issues
     */
    public static function ensure_clean_schedule(): ?array {
        if (!self::is_available()) {
            return null;
        }

        // First, clean up any duplicates
        $cleanup_result = self::cleanup_duplicate_actions();

        // Then ensure actions are scheduled
        $manager = new self();

        // Check queue processor
        if (!as_next_scheduled_action(self::ACTION_HOOK_QUEUE, [], self::ACTION_GROUP)) {
            $manager->schedule_queue_processor();
        }

        // Check sync check
        if (!as_next_scheduled_action(self::ACTION_HOOK_SCHEDULED_SYNC, [], self::ACTION_GROUP)) {
            $manager->schedule_sync_check();
        }

        return $cleanup_result;
    }
}
