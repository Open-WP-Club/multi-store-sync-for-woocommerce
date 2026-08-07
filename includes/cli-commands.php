<?php
/**
 * WP-CLI Commands for WooCommerce Multi-Store Sync
 *
 * Provides CLI interface for managing product synchronization
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manage WooCommerce Multi-Store product synchronization.
 *
 * ## EXAMPLES
 *
 *     # Sync all products to all stores
 *     $ wp mss sync --all
 *
 *     # Sync a specific product
 *     $ wp mss sync --product=123
 *
 *     # View queue status
 *     $ wp mss queue status
 *
 *     # List configured stores
 *     $ wp mss stores list
 */
class WC_Multi_Store_CLI_Commands extends WP_CLI_Command {

    /**
     * Sync products to remote stores.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Sync all published products.
     *
     * [--product=<id>]
     * : Sync a specific product by ID.
     *
     * [--sku=<sku>]
     * : Sync a specific product by SKU.
     *
     * [--category=<id_or_slug>]
     * : Sync all products in a category (and its children) by ID or slug.
     *
     * [--no-children]
     * : When using --category, skip child categories.
     *
     * [--store=<url>]
     * : Sync only to a specific store URL.
     *
     * [--type=<sync_type>]
     * : Sync type: full_product, price_quantity, price_quantity_categories, quantity.
     * ---
     * default: full_product
     * options:
     *   - full_product
     *   - price_quantity
     *   - price_quantity_categories
     *   - quantity
     * ---
     *
     * [--dry-run]
     * : Preview what would be synced without actually syncing.
     *
     * ## EXAMPLES
     *
     *     wp mss sync --all
     *     wp mss sync --product=123
     *     wp mss sync --sku=ABC-001 --type=quantity
     *     wp mss sync --category=shoes
     *     wp mss sync --category=42 --type=price_quantity --no-children
     *     wp mss sync --all --store=https://shop2.example.com
     *     wp mss sync --all --dry-run
     *
     * @subcommand sync
     */
    public function sync($args, $assoc_args) {
        $settings = WC_Multi_Store_Settings::get_settings();

        if (!$settings['enabled']) {
            WP_CLI::warning(__('Sync is currently disabled in settings. Proceeding anyway via CLI.', 'wc-multi-store-sync'));
        }

        $sync_type = $assoc_args['type'] ?? 'full_product';
        $dry_run = isset($assoc_args['dry-run']);
        $store_filter = $assoc_args['store'] ?? null;

        // Determine products to sync
        $product_ids = [];

        if (isset($assoc_args['product'])) {
            $product = wc_get_product((int) $assoc_args['product']);
            if (!$product) {
                WP_CLI::error(sprintf(__('Product ID %d not found.', 'wc-multi-store-sync'), $assoc_args['product']));
            }
            $product_ids[] = $product->get_id();
        } elseif (isset($assoc_args['sku'])) {
            $product_id = wc_get_product_id_by_sku($assoc_args['sku']);
            if (!$product_id) {
                WP_CLI::error(sprintf(__('Product with SKU "%s" not found.', 'wc-multi-store-sync'), $assoc_args['sku']));
            }
            $product_ids[] = $product_id;
        } elseif (isset($assoc_args['category'])) {
            $id_or_slug = $assoc_args['category'];
            $term = WC_Multi_Store_Category_Sync::resolve_term($id_or_slug);
            if (!$term) {
                WP_CLI::error(sprintf(__('Category "%s" not found.', 'wc-multi-store-sync'), $id_or_slug));
            }
            $include_children = !isset($assoc_args['no-children']);
            $product_ids = WC_Multi_Store_Category_Sync::get_product_ids($term->term_id, $include_children);
            if (empty($product_ids)) {
                WP_CLI::error(sprintf(__('No published products found in category "%s".', 'wc-multi-store-sync'), $term->name));
            }
            WP_CLI::log(sprintf(
                __('Category "%s" — %d product(s)%s', 'wc-multi-store-sync'),
                $term->name,
                count($product_ids),
                $include_children ? __(' (incl. children)', 'wc-multi-store-sync') : ''
            ));
        } elseif (isset($assoc_args['all'])) {
            $product_ids = wc_get_products([
                'status' => 'publish',
                'limit' => -1,
                'return' => 'ids',
            ]);

            if (empty($product_ids)) {
                WP_CLI::error(__('No published products found.', 'wc-multi-store-sync'));
            }
        } else {
            WP_CLI::error(__('Please specify --all, --product=<id>, --sku=<sku>, or --category=<id_or_slug>.', 'wc-multi-store-sync'));
        }

        $count = count($product_ids);

        if ($dry_run) {
            WP_CLI::log(sprintf(__('Dry run: Would sync %d product(s) with type "%s".', 'wc-multi-store-sync'), $count, $sync_type));

            $stores = WC_Multi_Store_Settings::get_active_stores();
            if ($store_filter) {
                $stores = array_filter($stores, fn($url) => $url === rtrim($store_filter, '/'), ARRAY_FILTER_USE_KEY);
            }

            foreach ($stores as $url => $config) {
                WP_CLI::log(sprintf('  -> %s', $url));
            }
            WP_CLI::success(sprintf(__('Dry run complete. %d product(s) would be queued to %d store(s).', 'wc-multi-store-sync'), $count, count($stores)));
            return;
        }

        WP_CLI::log(sprintf(__('Queuing %d product(s) for sync...', 'wc-multi-store-sync'), $count));

        $progress = \WP_CLI\Utils\make_progress_bar(__('Queuing products', 'wc-multi-store-sync'), $count);
        $queued = 0;

        foreach ($product_ids as $product_id) {
            $added = WC_MSS()->queue_manager->add_product(
                $product_id,
                'wp_cli',
                WC_Multi_Store_Queue_Manager::PRIORITY_NORMAL,
                $sync_type
            );
            $queued += $added;
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success(sprintf(__('Queued %d item(s) for %d product(s).', 'wc-multi-store-sync'), $queued, $count));
    }

    /**
     * Manage the sync queue.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform.
     * ---
     * options:
     *   - status
     *   - process
     *   - clear
     *   - retry
     * ---
     *
     * [--batch-size=<size>]
     * : Number of items to process per batch (for 'process' action).
     * ---
     * default: 30
     * ---
     *
     * [--verbose]
     * : Show per-item progress while processing.
     *
     * ## EXAMPLES
     *
     *     wp mss queue status
     *     wp mss queue process --batch-size=50
     *     wp mss queue process --verbose
     *     wp mss queue clear
     *     wp mss queue retry
     *
     * @subcommand queue
     */
    public function queue($args, $assoc_args) {
        $action = $args[0];

        switch ($action) {
            case 'status':
                $stats = WC_Multi_Store_Queue_Table::get_stats();
                WP_CLI::log(__('Queue Status:', 'wc-multi-store-sync'));
                $table = [];
                foreach ($stats as $status => $count) {
                    $table[] = ['Status' => ucfirst($status), 'Count' => $count];
                }
                WP_CLI\Utils\format_items('table', $table, ['Status', 'Count']);

                $stores = WC_Multi_Store_Settings::get_active_stores();
                if (!empty($stores)) {
                    $cb_rows = [];
                    foreach ($stores as $url => $config) {
                        $cb = WC_Multi_Store_Circuit_Breaker::get_status($url);
                        $host = wp_parse_url($url, PHP_URL_HOST) ?: $url;
                        $cb_rows[] = [
                            'Store'              => $host,
                            'Circuit'            => $cb['open'] ? WP_CLI::colorize('%ROPEN%n') : WP_CLI::colorize('%GCLOSED%n'),
                            'Consecutive Errors' => $cb['consecutive_errors'],
                            'Resumes In'         => $cb['open'] ? ceil($cb['seconds_remaining'] / 60) . ' min' : '-',
                        ];
                    }
                    WP_CLI::log('');
                    WP_CLI::log(__('Circuit Breaker Status:', 'wc-multi-store-sync'));
                    WP_CLI\Utils\format_items('table', $cb_rows, ['Store', 'Circuit', 'Consecutive Errors', 'Resumes In']);
                }
                break;

            case 'process':
                $batch_size = (int) ($assoc_args['batch-size'] ?? 30);
                $verbose    = isset($assoc_args['verbose']);
                WP_CLI::log(sprintf(__('Processing queue (batch size: %d)...', 'wc-multi-store-sync'), $batch_size));

                $ok   = 0;
                $fail = 0;
                $item_callback = static function (array $info) use ($verbose, &$ok, &$fail): void {
                    if ($info['status'] === 'success') {
                        $ok++;
                    } elseif ($info['status'] === 'error') {
                        $fail++;
                    }

                    if ($verbose) {
                        $sku   = $info['sku'] ?: "ID:{$info['product_id']}";
                        $store = wp_parse_url($info['store_url'], PHP_URL_HOST) ?: $info['store_url'];
                        $pfx   = sprintf('[%3d/%3d]', $info['index'], $info['total']);

                        switch ($info['status']) {
                            case 'success':
                                WP_CLI::log(WP_CLI::colorize("%G{$pfx} ✓ {$sku} → {$store}%n"));
                                break;
                            case 'error':
                                WP_CLI::log(WP_CLI::colorize("%R{$pfx} ✗ {$sku} → {$store}  {$info['message']}%n"));
                                break;
                            case 'skipped':
                                WP_CLI::log(WP_CLI::colorize("%Y{$pfx} ~ {$sku} → {$store}  {$info['message']}%n"));
                                break;
                        }
                    } elseif ($info['index'] % 15 === 0 || $info['index'] === $info['total']) {
                        WP_CLI::log(sprintf(
                            '[%d/%d] %d success, %d errors',
                            $info['index'],
                            $info['total'],
                            $ok,
                            $fail
                        ));
                    }
                };

                $result = WC_MSS()->queue_manager->process_queue($batch_size, $item_callback);

                if ($result['status'] === 'empty') {
                    WP_CLI::success(__('Queue is empty, nothing to process.', 'wc-multi-store-sync'));
                } elseif ($result['status'] === 'skipped') {
                    WP_CLI::warning(__('Queue processor is already running.', 'wc-multi-store-sync'));
                } else {
                    WP_CLI::success(sprintf(
                        __('Processed %d item(s): %d success, %d errors, %d remaining.', 'wc-multi-store-sync'),
                        $result['processed'],
                        $result['success'],
                        $result['errors'],
                        $result['remaining']
                    ));
                }
                break;

            case 'clear':
                WP_CLI::confirm(__('Are you sure you want to clear the entire queue?', 'wc-multi-store-sync'));
                $cleared = WC_MSS()->queue_manager->clear_queue();
                WP_CLI::success(sprintf(__('Queue cleared. %s item(s) removed.', 'wc-multi-store-sync'), $cleared ?: 0));
                break;

            case 'retry':
                $retried = WC_Multi_Store_Queue_Table::retry_failed_items();
                WP_CLI::success(sprintf(__('%s failed item(s) reset to pending.', 'wc-multi-store-sync'), $retried ?: 0));
                break;
        }
    }

    /**
     * Manage configured stores.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform.
     * ---
     * options:
     *   - list
     *   - test
     *   - reset
     * ---
     *
     * [--store=<url>]
     * : Store URL (required for 'test' and 'reset' actions).
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp mss stores list
     *     wp mss stores list --format=json
     *     wp mss stores test --store=https://shop2.example.com
     *     wp mss stores reset --store=https://shop2.example.com
     *
     * @subcommand stores
     */
    public function stores($args, $assoc_args) {
        $action = $args[0];

        switch ($action) {
            case 'list':
                $stores = WC_Multi_Store_Settings::get_stores();
                $format = $assoc_args['format'] ?? 'table';

                if (empty($stores)) {
                    WP_CLI::log(__('No stores configured.', 'wc-multi-store-sync'));
                    return;
                }

                $table = [];
                foreach ($stores as $url => $config) {
                    $table[] = [
                        'URL' => $url,
                        'Status' => $config['status'] ?? 'inactive',
                        'Auth' => $config['auth_method'] ?? 'basic_auth',
                        'Excluded Categories' => count($config['exclude_categories'] ?? []),
                        'Excluded Tags' => count($config['exclude_tags'] ?? []),
                    ];
                }

                WP_CLI\Utils\format_items($format, $table, ['URL', 'Status', 'Auth', 'Excluded Categories', 'Excluded Tags']);
                break;

            case 'test':
                $store_url = $assoc_args['store'] ?? null;
                if (!$store_url) {
                    WP_CLI::error(__('Please specify --store=<url>.', 'wc-multi-store-sync'));
                }

                $store_url = rtrim($store_url, '/');
                $store = WC_Multi_Store_Settings::get_store($store_url);

                if (!$store) {
                    WP_CLI::error(sprintf(__('Store "%s" not found.', 'wc-multi-store-sync'), $store_url));
                }

                WP_CLI::log(sprintf(__('Testing connection to %s...', 'wc-multi-store-sync'), $store_url));

                $result = WC_Multi_Store_Health_Check::check_store_connection($store_url, $store);
                if (is_wp_error($result)) {
                    WP_CLI::error(sprintf(__('Connection failed: %s', 'wc-multi-store-sync'), $result->get_error_message()));
                } else {
                    WP_CLI::success(sprintf(__('Connection to %s successful.', 'wc-multi-store-sync'), $store_url));
                }
                break;

            case 'reset':
                $store_url = $assoc_args['store'] ?? null;
                if (!$store_url) {
                    WP_CLI::error(__('Please specify --store=<url>.', 'wc-multi-store-sync'));
                }
                $store_url = rtrim($store_url, '/');
                WC_Multi_Store_Circuit_Breaker::reset($store_url);
                WP_CLI::success(sprintf(__('Circuit breaker reset for %s. Requests will resume immediately.', 'wc-multi-store-sync'), $store_url));
                break;
        }
    }

    /**
     * Run stock verification against remote stores.
     *
     * [--store=<url>]
     * : Verify only a specific store.
     *
     * [--limit=<number>]
     * : Limit number of products to verify.
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     wp mss verify
     *     wp mss verify --store=https://shop2.example.com
     *     wp mss verify --limit=100
     *
     * @subcommand verify
     */
    public function verify($args, $assoc_args) {
        $limit = (int) ($assoc_args['limit'] ?? 0);
        $store_url = isset($assoc_args['store']) ? rtrim($assoc_args['store'], '/') : null;

        WP_CLI::log(__('Starting stock verification...', 'wc-multi-store-sync'));

        $result = WC_Multi_Store_Weekly_Sync_Verifier::run_verification($limit ?: null, $store_url);

        if (isset($result['error'])) {
            WP_CLI::error($result['error']);
        }

        WP_CLI::success(sprintf(
            __('Verification complete. %d product(s) checked, %d discrepancy(ies) found.', 'wc-multi-store-sync'),
            $result['products_checked'] ?? 0,
            $result['discrepancies_found'] ?? 0
        ));
    }

    /**
     * View sync history and statistics.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Show stats for last N days.
     * ---
     * default: 7
     * ---
     *
     * [--status=<status>]
     * : Filter by status: success, error.
     *
     * [--limit=<limit>]
     * : Number of recent records to show.
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp mss history
     *     wp mss history --days=30
     *     wp mss history --status=error --limit=50
     *
     * @subcommand history
     */
    public function history($args, $assoc_args) {
        $days = (int) ($assoc_args['days'] ?? 7);
        $limit = (int) ($assoc_args['limit'] ?? 20);
        $status = $assoc_args['status'] ?? null;
        $format = $assoc_args['format'] ?? 'table';

        // Show statistics summary
        $stats = WC_Multi_Store_Sync_History::get_statistics(['days' => $days]);
        $overall = $stats['overall'];

        WP_CLI::log(sprintf(__('Sync Statistics (last %d days):', 'wc-multi-store-sync'), $days));
        WP_CLI::log(sprintf('  Total syncs: %s', $overall['total_syncs']));
        WP_CLI::log(sprintf('  Successful: %s', $overall['successful_syncs']));
        WP_CLI::log(sprintf('  Failed: %s', $overall['failed_syncs']));
        WP_CLI::log(sprintf('  Success rate: %s%%', $overall['success_rate']));
        WP_CLI::log(sprintf('  Avg duration: %sms', round($overall['avg_duration_ms'] ?? 0)));
        WP_CLI::log('');

        // Show recent history
        $history_args = [
            'limit' => $limit,
            'offset' => 0,
        ];
        if ($status) {
            $history_args['status'] = $status;
        }

        $history = WC_Multi_Store_Sync_History::get_history($history_args);

        if (empty($history['results'])) {
            WP_CLI::log(__('No sync history found.', 'wc-multi-store-sync'));
            return;
        }

        $table = [];
        foreach ($history['results'] as $record) {
            $table[] = [
                'Date' => $record['created_at'],
                'SKU' => $record['product_sku'] ?: '-',
                'Store' => wp_parse_url($record['store_url'], PHP_URL_HOST) ?: $record['store_url'],
                'Type' => $record['sync_type'],
                'Status' => $record['status'],
                'Duration' => ($record['duration_ms'] ?? 0) . 'ms',
            ];
        }

        WP_CLI\Utils\format_items($format, $table, ['Date', 'SKU', 'Store', 'Type', 'Status', 'Duration']);
    }

    /**
     * Export or import plugin configuration.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform.
     * ---
     * options:
     *   - export
     *   - import
     * ---
     *
     * [--file=<path>]
     * : File path for export/import.
     *
     * [--include-keys]
     * : Include API keys in export (they are excluded by default for security).
     *
     * ## EXAMPLES
     *
     *     wp mss config export --file=config.json
     *     wp mss config export --file=config.json --include-keys
     *     wp mss config import --file=config.json
     *
     * @subcommand config
     */
    public function config($args, $assoc_args) {
        $action = $args[0];
        $file = $assoc_args['file'] ?? null;

        if (!$file) {
            WP_CLI::error(__('Please specify --file=<path>.', 'wc-multi-store-sync'));
        }

        if ($action === 'export') {
            $include_keys = isset($assoc_args['include-keys']);
            $config = WC_Multi_Store_Config_Manager::export($include_keys);
            $json = wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (file_put_contents($file, $json) === false) {
                WP_CLI::error(sprintf(__('Failed to write to %s.', 'wc-multi-store-sync'), $file));
            }

            WP_CLI::success(sprintf(__('Configuration exported to %s.', 'wc-multi-store-sync'), $file));

            if (!$include_keys) {
                WP_CLI::log(__('Note: API keys were excluded. Use --include-keys to include them.', 'wc-multi-store-sync'));
            }
        } else {
            if (!file_exists($file)) {
                WP_CLI::error(sprintf(__('File not found: %s', 'wc-multi-store-sync'), $file));
            }

            $json = file_get_contents($file);
            if ($json === false) {
                WP_CLI::error(sprintf(__('Failed to read file: %s', 'wc-multi-store-sync'), $file));
            }
            $config = json_decode($json, true);

            if ($config === null) {
                WP_CLI::error(__('Invalid JSON in config file.', 'wc-multi-store-sync'));
            }

            WP_CLI::confirm(__('This will overwrite current settings. Continue?', 'wc-multi-store-sync'));

            $result = WC_Multi_Store_Config_Manager::import($config);

            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            WP_CLI::success(__('Configuration imported successfully.', 'wc-multi-store-sync'));
        }
    }

    /**
     * View and manage the dead letter queue (permanently failed items).
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform.
     * ---
     * options:
     *   - list
     *   - retry
     *   - clear
     *   - stats
     * ---
     *
     * [--id=<id>]
     * : Specific dead letter item ID (for 'retry' action).
     *
     * [--limit=<limit>]
     * : Number of items to show.
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp mss dlq list
     *     wp mss dlq stats
     *     wp mss dlq retry --id=42
     *     wp mss dlq retry
     *     wp mss dlq clear
     *
     * @subcommand dlq
     */
    public function dlq($args, $assoc_args) {
        $action = $args[0];
        $format = $assoc_args['format'] ?? 'table';

        switch ($action) {
            case 'list':
                $limit = (int) ($assoc_args['limit'] ?? 20);
                $items = WC_Multi_Store_Dead_Letter_Queue::get_items(['limit' => $limit]);

                if (empty($items['results'])) {
                    WP_CLI::log(__('Dead letter queue is empty.', 'wc-multi-store-sync'));
                    return;
                }

                $table = [];
                foreach ($items['results'] as $item) {
                    $table[] = [
                        'ID' => $item['id'],
                        'Product ID' => $item['product_id'],
                        'SKU' => $item['product_sku'] ?: '-',
                        'Store' => wp_parse_url($item['store_url'], PHP_URL_HOST) ?: $item['store_url'],
                        'Type' => $item['sync_type'],
                        'Attempts' => $item['attempts'],
                        'Error' => mb_substr($item['last_error'] ?? '', 0, 60),
                        'Failed At' => $item['failed_at'],
                    ];
                }

                WP_CLI\Utils\format_items($format, $table, ['ID', 'Product ID', 'SKU', 'Store', 'Type', 'Attempts', 'Error', 'Failed At']);
                WP_CLI::log(sprintf(__('Total: %d item(s)', 'wc-multi-store-sync'), $items['total']));
                break;

            case 'retry':
                $id = $assoc_args['id'] ?? null;
                if ($id) {
                    $result = WC_Multi_Store_Dead_Letter_Queue::retry_item((int) $id);
                    if ($result) {
                        WP_CLI::success(sprintf(__('Item %d re-queued for processing.', 'wc-multi-store-sync'), $id));
                    } else {
                        WP_CLI::error(sprintf(__('Failed to retry item %d.', 'wc-multi-store-sync'), $id));
                    }
                } else {
                    WP_CLI::confirm(__('Retry all items in the dead letter queue?', 'wc-multi-store-sync'));
                    $count = WC_Multi_Store_Dead_Letter_Queue::retry_all();
                    WP_CLI::success(sprintf(__('%d item(s) re-queued for processing.', 'wc-multi-store-sync'), $count));
                }
                break;

            case 'clear':
                WP_CLI::confirm(__('Are you sure you want to clear the dead letter queue?', 'wc-multi-store-sync'));
                $cleared = WC_Multi_Store_Dead_Letter_Queue::clear_all();
                WP_CLI::success(sprintf(__('Cleared %d item(s) from dead letter queue.', 'wc-multi-store-sync'), $cleared));
                break;

            case 'stats':
                $stats = WC_Multi_Store_Dead_Letter_Queue::get_stats();
                WP_CLI::log(__('Dead Letter Queue Statistics:', 'wc-multi-store-sync'));
                $table = [];
                foreach ($stats as $key => $value) {
                    $table[] = ['Metric' => ucwords(str_replace('_', ' ', $key)), 'Value' => $value];
                }
                WP_CLI\Utils\format_items('table', $table, ['Metric', 'Value']);
                break;
        }
    }
}
