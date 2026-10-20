<?php
/**
 * Weekly Verification View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<?php
// =========================================
// System Health Check - Category/Tag Status (computed at top for sidebar)
// =========================================
$active_stores = WC_Multi_Store_Settings::get_active_stores();
$sync_settings = WC_Multi_Store_Settings::get_settings();
$match_by = $sync_settings['category_match_by'] ?? 'slug';

// Get local categories (only those assigned to products)
$local_categories = get_terms([
    'taxonomy' => 'product_cat',
    'hide_empty' => true,
    'fields' => 'all',
]);
$local_cat_count = is_array($local_categories) ? count($local_categories) : 0;

// Build local category lookup
$local_cat_identifiers = [];
if (is_array($local_categories)) {
    foreach ($local_categories as $cat) {
        $key = ($match_by === 'name') ? mb_strtolower($cat->name) : strtolower($cat->slug);
        $local_cat_identifiers[$key] = $cat->name;
    }
}

// Get local tags
$local_tags = get_terms([
    'taxonomy' => 'product_tag',
    'hide_empty' => true,
    'fields' => 'all',
]);
$local_tag_count = is_array($local_tags) ? count($local_tags) : 0;

$local_tag_identifiers = [];
if (is_array($local_tags)) {
    foreach ($local_tags as $tag) {
        $key = ($match_by === 'name') ? mb_strtolower($tag->name) : strtolower($tag->slug);
        $local_tag_identifiers[$key] = $tag->name;
    }
}

// Check each store's cached categories
// Pre-populate term cache if it doesn't exist (lazy loading)
$store_health = [];
foreach ($active_stores as $store_url => $store_config) {
    $store_hash = md5($store_url);
    $store_name = parse_url($store_url, PHP_URL_HOST);

    // Ensure term cache exists for this store (will fetch from API if missing)
    if (class_exists('WC_Multi_Store_Sync_Engine')) {
        WC_Multi_Store_Sync_Engine::ensure_term_cache($store_url, $store_config);
    }

    $cached_cats = get_transient('wc_mss_remote_categories_' . $store_hash);
    $cached_tags = get_transient('wc_mss_remote_tags_' . $store_hash);

    $health = [
        'name' => $store_name,
        'url' => $store_url,
        'categories' => [
            'cached' => $cached_cats !== false,
            'remote_count' => is_array($cached_cats) ? count($cached_cats) : 0,
            'missing' => [],
        ],
        'tags' => [
            'cached' => $cached_tags !== false,
            'remote_count' => is_array($cached_tags) ? count($cached_tags) : 0,
            'missing' => [],
        ],
    ];

    if (is_array($cached_cats)) {
        $remote_cat_keys = [];
        foreach ($cached_cats as $cat) {
            $key = ($match_by === 'name') ? mb_strtolower($cat['name']) : strtolower($cat['slug']);
            $remote_cat_keys[$key] = true;
        }
        foreach ($local_cat_identifiers as $key => $name) {
            if (!isset($remote_cat_keys[$key])) {
                $health['categories']['missing'][] = $name;
            }
        }
    }

    if (is_array($cached_tags)) {
        $remote_tag_keys = [];
        foreach ($cached_tags as $tag) {
            $key = ($match_by === 'name') ? mb_strtolower($tag['name']) : strtolower($tag['slug']);
            $remote_tag_keys[$key] = true;
        }
        foreach ($local_tag_identifiers as $key => $name) {
            if (!isset($remote_tag_keys[$key])) {
                $health['tags']['missing'][] = $name;
            }
        }
    }

    $store_health[] = $health;
}
?>

<div class="wrap wc-mss-weekly-verification">
    <h1><?php esc_html_e('Weekly Sync Verification', 'multi-store-sync-for-woocommerce'); ?></h1>

    <?php settings_errors('wc_mss_weekly_verification'); ?>

    <p class="description">
        <?php esc_html_e('Automatically verify that all products are correctly synchronized across all stores on a weekly basis.', 'multi-store-sync-for-woocommerce'); ?>
    </p>

    <!-- Two-column layout -->
    <div class="wc-mss-two-column-layout">
        <!-- Left column: Main content -->
        <div class="wc-mss-main-content">

    <div class="card wc-mss-card">
        <h2><?php esc_html_e('Verification Settings', 'multi-store-sync-for-woocommerce'); ?></h2>

        <form method="post" action="">
            <?php wp_nonce_field('wc_mss_save_weekly_verification'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="verification_enabled"><?php esc_html_e('Enable Weekly Verification', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="verification_enabled" id="verification_enabled" value="1"
                                   <?php checked($settings['enabled'], true); ?>>
                            <?php esc_html_e('Enable automatic weekly sync verification', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, the system will automatically verify product sync across all stores on a weekly schedule.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="verification_day"><?php esc_html_e('Verification Day', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="verification_day" id="verification_day">
                            <option value="0" <?php selected($settings['day_of_week'], 0); ?>><?php esc_html_e('Sunday', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="1" <?php selected($settings['day_of_week'], 1); ?>><?php esc_html_e('Monday', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="2" <?php selected($settings['day_of_week'], 2); ?>><?php esc_html_e('Tuesday', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="3" <?php selected($settings['day_of_week'], 3); ?>><?php esc_html_e('Wednesday', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="4" <?php selected($settings['day_of_week'], 4); ?>><?php esc_html_e('Thursday', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="5" <?php selected($settings['day_of_week'], 5); ?>><?php esc_html_e('Friday', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="6" <?php selected($settings['day_of_week'], 6); ?>><?php esc_html_e('Saturday', 'multi-store-sync-for-woocommerce'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Which day of the week to run verification', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="verification_time"><?php esc_html_e('Verification Time', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="time" name="verification_time" id="verification_time" value="<?php echo esc_attr($settings['time_of_day']); ?>">
                        <p class="description"><?php esc_html_e('What time to run verification (server time). Recommended: during low traffic hours.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('What to Check', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="check_stock" value="1" <?php checked($settings['check_stock'], true); ?>>
                            <?php esc_html_e('Stock Quantities', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="check_prices" value="1" <?php checked($settings['check_prices'], true); ?>>
                            <?php esc_html_e('Prices (Regular & Sale)', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Select what fields to verify during the check', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sample_mode"><?php esc_html_e('Products to Check', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="sample_mode" id="sample_mode">
                            <option value="all" <?php selected($settings['sample_mode'], 'all'); ?>><?php esc_html_e('All Products', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="recent" <?php selected($settings['sample_mode'], 'recent'); ?>><?php esc_html_e('Most Recent Products', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="modified" <?php selected($settings['sample_mode'], 'modified'); ?>><?php esc_html_e('Recently Modified Products', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="random" <?php selected($settings['sample_mode'], 'random'); ?>><?php esc_html_e('Random Sample', 'multi-store-sync-for-woocommerce'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Which products to include in verification', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="product_limit"><?php esc_html_e('Product Limit', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="product_limit" id="product_limit" value="<?php echo esc_attr($settings['product_limit']); ?>" min="0" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('Maximum number of products to check (0 = unlimited). Recommended: 100-500 for large catalogs to avoid timeouts.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="batch_size"><?php esc_html_e('Batch Size', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="batch_size" id="batch_size" value="<?php echo esc_attr(isset($settings['batch_size']) ? $settings['batch_size'] : 20); ?>" min="1" max="100" step="1" class="small-text">
                        <span><?php esc_html_e('products per batch', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description"><?php esc_html_e('Number of products to verify per batch. Higher values = faster verification but more server load. Recommended: 10-30.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_correct"><?php esc_html_e('Auto-Correct Discrepancies', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_correct" id="auto_correct" value="1"
                                   <?php checked($settings['auto_correct'], true); ?>>
                            <?php esc_html_e('Automatically queue products for re-sync when discrepancies are found', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, products with discrepancies will be automatically added to the sync queue for correction.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr id="auto_correct_limit_row" <?php echo !$settings['auto_correct'] ? 'hidden' : ''; ?>>
                    <th scope="row">
                        <label for="auto_correct_limit"><?php esc_html_e('Auto-Correct Limit', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <?php $auto_correct_limit = isset($settings['auto_correct_limit']) ? (int) $settings['auto_correct_limit'] : 500; ?>
                        <input type="number" name="auto_correct_limit" id="auto_correct_limit"
                               value="<?php echo esc_attr($auto_correct_limit); ?>"
                               min="0" max="10000" step="50" class="small-text">
                        <span><?php esc_html_e('products max', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Maximum number of products to auto-correct per verification run. Set to 0 for unlimited (not recommended). Default: 500.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <?php
                // Auto-correct sync settings
                $weekly_sync_type = isset($settings['weekly_sync_type']) ? $settings['weekly_sync_type'] : 'use_default';
                $weekly_stock_sync = isset($settings['weekly_stock_sync']) ? $settings['weekly_stock_sync'] : 'use_default';
                $weekly_category_auto_create = isset($settings['weekly_category_auto_create']) ? $settings['weekly_category_auto_create'] : 'use_default';
                ?>

                <tr id="auto_correct_sync_type_row" class="auto-correct-option" <?php echo !$settings['auto_correct'] ? 'hidden' : ''; ?>>
                    <th scope="row">
                        <label for="weekly_sync_type"><?php esc_html_e('Auto-Correct Sync Type', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="weekly_sync_type" id="weekly_sync_type">
                            <?php
                            WC_Multi_Store_Sync_Type_Options::render([
                                'use_default' => __('Use General Settings', 'multi-store-sync-for-woocommerce'),
                                'full_product' => __('Full Product (all data)', 'multi-store-sync-for-woocommerce'),
                                'price_quantity_categories' => __('Price, Quantity & Categories', 'multi-store-sync-for-woocommerce'),
                                'price_quantity' => __('Price & Quantity', 'multi-store-sync-for-woocommerce'),
                                'quantity' => __('Quantity Only (fastest)', 'multi-store-sync-for-woocommerce'),
                            ], $weekly_sync_type);
                            ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What type of data to sync when auto-correcting discrepancies.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr id="auto_correct_stock_sync_row" class="auto-correct-option" <?php echo !$settings['auto_correct'] ? 'hidden' : ''; ?>>
                    <th scope="row">
                        <label for="weekly_stock_sync"><?php esc_html_e('Stock Synchronization', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="weekly_stock_sync" id="weekly_stock_sync">
                            <option value="use_default" <?php selected($weekly_stock_sync, 'use_default'); ?>>
                                <?php esc_html_e('Use General Settings', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="enabled" <?php selected($weekly_stock_sync, 'enabled'); ?>>
                                <?php esc_html_e('Enabled', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="disabled" <?php selected($weekly_stock_sync, 'disabled'); ?>>
                                <?php esc_html_e('Disabled', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Whether to sync stock quantities when auto-correcting.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr id="auto_correct_category_row" class="auto-correct-option" <?php echo !$settings['auto_correct'] ? 'hidden' : ''; ?>>
                    <th scope="row">
                        <label for="weekly_category_auto_create"><?php esc_html_e('Auto-create Categories', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="weekly_category_auto_create" id="weekly_category_auto_create">
                            <option value="use_default" <?php selected($weekly_category_auto_create, 'use_default'); ?>>
                                <?php esc_html_e('Use General Settings', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="enabled" <?php selected($weekly_category_auto_create, 'enabled'); ?>>
                                <?php esc_html_e('Enabled', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="disabled" <?php selected($weekly_category_auto_create, 'disabled'); ?>>
                                <?php esc_html_e('Disabled', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Whether to auto-create missing categories on remote stores when auto-correcting.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="email_enabled"><?php esc_html_e('Email Notifications', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-field-group">
                            <input type="checkbox" name="email_enabled" id="email_enabled" value="1"
                                   <?php checked($settings['email_enabled'], true); ?>>
                            <?php esc_html_e('Send email when discrepancies are found', 'multi-store-sync-for-woocommerce'); ?>
                        </label>

                        <label for="email_recipients"><?php esc_html_e('Email Recipients:', 'multi-store-sync-for-woocommerce'); ?></label><br>
                        <input type="text" name="email_recipients" id="email_recipients" value="<?php echo esc_attr($settings['email_recipients']); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Comma-separated list of email addresses to notify when discrepancies are found.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="wc_mss_save_weekly_verification" class="button button-primary">
                    <?php esc_html_e('Save Settings', 'multi-store-sync-for-woocommerce'); ?>
                </button>
            </p>
        </form>
    </div>

    <div class="card wc-mss-card">
        <h2><?php esc_html_e('Manual Verification & Sync Control', 'multi-store-sync-for-woocommerce'); ?></h2>

        <?php if ($next_run): ?>
            <p>
                <strong><?php esc_html_e('Next Scheduled Run:', 'multi-store-sync-for-woocommerce'); ?></strong>
                <?php echo esc_html(date('l, F j, Y \a\t g:i A', $next_run)); ?>
                (<?php echo esc_html(human_time_diff($next_run, current_time('timestamp'))); ?>)
            </p>
        <?php else: ?>
            <p><em><?php esc_html_e('No verification scheduled. Enable weekly verification above to schedule.', 'multi-store-sync-for-woocommerce'); ?></em></p>
        <?php endif; ?>

        <div class="wc-mss-toolbar">
            <button type="button" id="wc-mss-start-verification-page" class="button button-secondary">
                <?php esc_html_e('Run Verification Now', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <button type="button" id="wc-mss-cancel-verification-page" class="button" hidden>
                <?php esc_html_e('Cancel', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <span class="description"><?php esc_html_e('Runs verification in the background.', 'multi-store-sync-for-woocommerce'); ?></span>
        </div>

        <h3><?php esc_html_e('Stop Synchronization', 'multi-store-sync-for-woocommerce'); ?></h3>
        <p class="description"><?php esc_html_e('Use these buttons to stop sync operations. Products queued by auto-correct will be removed.', 'multi-store-sync-for-woocommerce'); ?></p>
        <div class="wc-mss-toolbar">
            <button type="button" id="stop-weekly-sync" class="button button-secondary">
                <span class="dashicons dashicons-controls-pause"></span>
                <?php esc_html_e('Stop Weekly Verification', 'multi-store-sync-for-woocommerce'); ?>
            </button>
            <button type="button" id="stop-all-sync" class="button button-secondary">
                <span class="dashicons dashicons-no"></span>
                <?php esc_html_e('Stop All Sync & Clear Queue', 'multi-store-sync-for-woocommerce'); ?>
            </button>
        </div>
        <div id="sync-stop-status" aria-live="polite"></div>
        <div id="wc-mss-verification-progress-page" class="notice notice-info inline" role="status" hidden>
            <p><span id="wc-mss-verification-status-page"></span> <span id="wc-mss-verification-percent-page">0%</span></p>
            <progress id="wc-mss-verification-bar-page" max="100" value="0"><?php esc_html_e('Verification progress', 'multi-store-sync-for-woocommerce'); ?></progress>
            <p id="wc-mss-verification-details-page"></p>
        </div>
        <div id="wc-mss-verification-result-page" aria-live="polite"></div>

    </div>

    <?php if ($latest_report): ?>
        <div class="card wc-mss-card">
            <h2><?php esc_html_e('Latest Verification Report', 'multi-store-sync-for-woocommerce'); ?></h2>

            <div class="wc-mss-report-summary">
                <p>
                    <strong><?php esc_html_e('Run Date:', 'multi-store-sync-for-woocommerce'); ?></strong>
                    <?php echo esc_html(date('F j, Y \a\t g:i A', strtotime($latest_report['started_at']))); ?>
                </p>

                <table class="widefat striped">
                    <tbody>
                        <tr><th scope="row"><?php esc_html_e('Products Checked', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html(number_format_i18n($latest_report['products_checked'])); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Stores Checked', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html(number_format_i18n($latest_report['stores_checked'])); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Discrepancies Found', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html(number_format_i18n($latest_report['discrepancies_found'])); ?></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Duration (seconds)', 'multi-store-sync-for-woocommerce'); ?></th><td><?php echo esc_html(number_format_i18n($latest_report['duration_seconds'], 1)); ?></td></tr>
                    </tbody>
                </table>

                <?php if ($latest_report['discrepancies_found'] > 0): ?>
                    <div>
                        <h3><?php esc_html_e('Breakdown', 'multi-store-sync-for-woocommerce'); ?></h3>
                        <ul>
                            <li><strong><?php esc_html_e('Missing Products:', 'multi-store-sync-for-woocommerce'); ?></strong> <?php echo number_format($latest_report['missing_products']); ?></li>
                            <?php
                            $report_data_temp = maybe_unserialize($latest_report['report_data']);
                            $orphan_count = isset($report_data_temp['orphan_products']) ? $report_data_temp['orphan_products'] : 0;
                            if ($orphan_count > 0):
                            ?>
                            <li>
                                <strong><?php esc_html_e('Orphan Products:', 'multi-store-sync-for-woocommerce'); ?></strong> <?php echo number_format($orphan_count); ?>
                                <em>(<?php esc_html_e('exist on remote but should be excluded', 'multi-store-sync-for-woocommerce'); ?>)</em>
                                <button type="button" id="wc-mss-queue-orphans" class="button button-small">
                                    <?php esc_html_e('Queue for Deletion', 'multi-store-sync-for-woocommerce'); ?>
                                </button>
                                <div id="wc-mss-orphan-status" aria-live="polite"></div>
                            </li>
                            <?php endif; ?>
                            <li><strong><?php esc_html_e('Stock Mismatches:', 'multi-store-sync-for-woocommerce'); ?></strong> <?php echo number_format($latest_report['stock_mismatches']); ?></li>
                            <li><strong><?php esc_html_e('Price Mismatches:', 'multi-store-sync-for-woocommerce'); ?></strong> <?php echo number_format($latest_report['price_mismatches']); ?></li>
                        </ul>

                        <?php
                        $report_data = maybe_unserialize($latest_report['report_data']);
                        if (!empty($report_data['details'])):
                            $report_items = array_map(
                                static fn(array $item): array => ['id' => $item['product_id']] + $item,
                                array_filter($report_data['details'], static fn(array $item): bool => !empty($item['discrepancies']))
                            );
                        ?>
                            <h3><?php esc_html_e('Reported Discrepancies', 'multi-store-sync-for-woocommerce'); ?></h3>
                            <form method="post">
                            <?php
                            $list_table = new WC_Multi_Store_Admin_List_Table('verification', [
                                'product' => __('Product', 'multi-store-sync-for-woocommerce'),
                                'sku' => __('SKU', 'multi-store-sync-for-woocommerce'),
                                'discrepancies' => __('Discrepancies', 'multi-store-sync-for-woocommerce'),
                                'actions' => __('Actions', 'multi-store-sync-for-woocommerce'),
                            ], $report_items, static function (array $item): void {
                                ?>
                                <td><strong><?php echo esc_html($item['name']); ?></strong> (#<?php echo esc_html($item['id']); ?>)</td>
                                <td><code><?php echo esc_html($item['sku']); ?></code></td>
                                <td><ul>
                                    <?php foreach ($item['discrepancies'] as $disc): ?>
                                        <li><strong><?php echo esc_html($disc['store_name']); ?>:</strong>
                                            <?php echo wp_kses_post(WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message($disc)); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul></td>
                                <td>
                                    <?php if ($item['id'] > 0): ?>
                                        <button type="submit" class="button button-small" name="wc_mss_row_action" value="resync:<?php echo esc_attr($item['id']); ?>"><?php esc_html_e('Re-sync to all stores', 'multi-store-sync-for-woocommerce'); ?></button>
                                    <?php endif; ?>
                                </td>
                                <?php
                            }, static fn(array $item): bool => $item['id'] > 0);
                            $list_table->display();
                            ?>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="notice notice-success inline">
                        <strong><?php esc_html_e('All products are in sync!', 'multi-store-sync-for-woocommerce'); ?></strong>
                        <p><?php esc_html_e('No discrepancies were found during this verification.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($reports)): ?>
        <div class="card wc-mss-card">
            <h2><?php esc_html_e('Recent Verification Reports', 'multi-store-sync-for-woocommerce'); ?></h2>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Products Checked', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Stores', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Discrepancies', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Duration', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Status', 'multi-store-sync-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?php echo esc_html(date('M j, Y g:i A', strtotime($report['started_at']))); ?></td>
                            <td><?php echo number_format($report['products_checked']); ?></td>
                            <td><?php echo number_format($report['stores_checked']); ?></td>
                            <td>
                                <span>
                                    <?php echo number_format($report['discrepancies_found']); ?>
                                </span>
                                <?php if ($report['discrepancies_found'] > 0): ?>
                                    <br><small>
                                        <?php
                                        $parts = [];
                                        if ($report['missing_products'] > 0) {
                                            $parts[] = number_format_i18n($report['missing_products']) . ' ' . __('missing', 'multi-store-sync-for-woocommerce');
                                        }
                                        $rd = maybe_unserialize($report['report_data']);
                                        if (isset($rd['orphan_products']) && $rd['orphan_products'] > 0) {
                                            $parts[] = number_format_i18n($rd['orphan_products']) . ' ' . __('orphan', 'multi-store-sync-for-woocommerce');
                                        }
                                        if ($report['stock_mismatches'] > 0) {
                                            $parts[] = number_format_i18n($report['stock_mismatches']) . ' ' . __('stock', 'multi-store-sync-for-woocommerce');
                                        }
                                        if ($report['price_mismatches'] > 0) {
                                            $parts[] = number_format_i18n($report['price_mismatches']) . ' ' . __('price', 'multi-store-sync-for-woocommerce');
                                        }
                                        echo wp_kses_post('(' . implode(', ', $parts) . ')');
                                        ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($report['duration_seconds'], 1); ?>s</td>
                            <td>
                                <?php
                                $status_labels = [
                                    'completed' => __('Completed', 'multi-store-sync-for-woocommerce'),
                                    'running' => __('Running', 'multi-store-sync-for-woocommerce'),
                                    'failed' => __('Failed', 'multi-store-sync-for-woocommerce'),
                                ];
                                echo esc_html(isset($status_labels[$report['status']]) ? $status_labels[$report['status']] : $report['status']);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

        </div><!-- /.wc-mss-main-content -->

        <!-- Right sidebar: System Health -->
        <div class="wc-mss-sidebar">
            <div class="card wc-mss-card">
                <h3><?php esc_html_e('System Health', 'multi-store-sync-for-woocommerce'); ?></h3>

                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e('Store', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Categories', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Tags', 'multi-store-sync-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Status', 'multi-store-sync-for-woocommerce'); ?></th>
                    </tr></thead>
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Local Store', 'multi-store-sync-for-woocommerce'); ?></th>
                            <td><?php echo esc_html($local_cat_count); ?></td>
                            <td><?php echo esc_html($local_tag_count); ?></td>
                            <td>&mdash;</td>
                        </tr>
                        <?php foreach ($store_health as $health):
                            $cat_missing = count($health['categories']['missing']);
                            $tag_missing = count($health['tags']['missing']);
                            $no_cache = !$health['categories']['cached'] && !$health['tags']['cached'];
                        ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($health['name']); ?></th>
                            <td title="<?php echo esc_attr(implode(', ', array_slice($health['categories']['missing'], 0, 10))); ?>"><?php echo $health['categories']['cached'] ? esc_html(($local_cat_count - $cat_missing) . '/' . $local_cat_count) : '&mdash;'; ?></td>
                            <td title="<?php echo esc_attr(implode(', ', array_slice($health['tags']['missing'], 0, 10))); ?>"><?php echo $health['tags']['cached'] ? esc_html(($local_tag_count - $tag_missing) . '/' . $local_tag_count) : '&mdash;'; ?></td>
                            <td><?php
                                if ($no_cache) {
                                    esc_html_e('No cache yet', 'multi-store-sync-for-woocommerce');
                                } elseif ($cat_missing || $tag_missing || !$health['categories']['cached'] || !$health['tags']['cached']) {
                                    esc_html_e('Needs attention', 'multi-store-sync-for-woocommerce');
                                } else {
                                    esc_html_e('In sync', 'multi-store-sync-for-woocommerce');
                                }
                            ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (empty($store_health)): ?>
                    <p><?php esc_html_e('No active stores', 'multi-store-sync-for-woocommerce'); ?></p>
                <?php endif; ?>
                <p class="description">
                    <?php /* translators: %s: product matching method. */ echo esc_html(sprintf(esc_html__('Match: %s', 'multi-store-sync-for-woocommerce'), $match_by)); ?><br>
                    <?php esc_html_e('Cache: 24h', 'multi-store-sync-for-woocommerce'); ?>
                </p>
            </div>
        </div><!-- /.wc-mss-sidebar -->

    </div><!-- /.wc-mss-two-column-layout -->
</div>
