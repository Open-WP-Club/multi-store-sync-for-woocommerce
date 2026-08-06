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
    <h1><?php _e('Weekly Sync Verification', 'wc-multi-store-sync'); ?></h1>

    <?php settings_errors('wc_mss_weekly_verification'); ?>

    <p class="description">
        <?php _e('Automatically verify that all products are correctly synchronized across all stores on a weekly basis.', 'wc-multi-store-sync'); ?>
    </p>

    <!-- Two-column layout -->
    <div class="wc-mss-two-column-layout">
        <!-- Left column: Main content -->
        <div class="wc-mss-main-content">

    <div class="wc-mss-card">
        <h2><?php _e('Verification Settings', 'wc-multi-store-sync'); ?></h2>

        <form method="post" action="">
            <?php wp_nonce_field('wc_mss_save_weekly_verification'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="verification_enabled"><?php _e('Enable Weekly Verification', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="verification_enabled" id="verification_enabled" value="1"
                                   <?php checked($settings['enabled'], true); ?>>
                            <?php _e('Enable automatic weekly sync verification', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, the system will automatically verify product sync across all stores on a weekly schedule.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="verification_day"><?php _e('Verification Day', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="verification_day" id="verification_day">
                            <option value="0" <?php selected($settings['day_of_week'], 0); ?>><?php _e('Sunday', 'wc-multi-store-sync'); ?></option>
                            <option value="1" <?php selected($settings['day_of_week'], 1); ?>><?php _e('Monday', 'wc-multi-store-sync'); ?></option>
                            <option value="2" <?php selected($settings['day_of_week'], 2); ?>><?php _e('Tuesday', 'wc-multi-store-sync'); ?></option>
                            <option value="3" <?php selected($settings['day_of_week'], 3); ?>><?php _e('Wednesday', 'wc-multi-store-sync'); ?></option>
                            <option value="4" <?php selected($settings['day_of_week'], 4); ?>><?php _e('Thursday', 'wc-multi-store-sync'); ?></option>
                            <option value="5" <?php selected($settings['day_of_week'], 5); ?>><?php _e('Friday', 'wc-multi-store-sync'); ?></option>
                            <option value="6" <?php selected($settings['day_of_week'], 6); ?>><?php _e('Saturday', 'wc-multi-store-sync'); ?></option>
                        </select>
                        <p class="description"><?php _e('Which day of the week to run verification', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="verification_time"><?php _e('Verification Time', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="time" name="verification_time" id="verification_time" value="<?php echo esc_attr($settings['time_of_day']); ?>">
                        <p class="description"><?php _e('What time to run verification (server time). Recommended: during low traffic hours.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('What to Check', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="check_stock" value="1" <?php checked($settings['check_stock'], true); ?>>
                            <?php _e('Stock Quantities', 'wc-multi-store-sync'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="check_prices" value="1" <?php checked($settings['check_prices'], true); ?>>
                            <?php _e('Prices (Regular & Sale)', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Select what fields to verify during the check', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sample_mode"><?php _e('Products to Check', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="sample_mode" id="sample_mode">
                            <option value="all" <?php selected($settings['sample_mode'], 'all'); ?>><?php _e('All Products', 'wc-multi-store-sync'); ?></option>
                            <option value="recent" <?php selected($settings['sample_mode'], 'recent'); ?>><?php _e('Most Recent Products', 'wc-multi-store-sync'); ?></option>
                            <option value="modified" <?php selected($settings['sample_mode'], 'modified'); ?>><?php _e('Recently Modified Products', 'wc-multi-store-sync'); ?></option>
                            <option value="random" <?php selected($settings['sample_mode'], 'random'); ?>><?php _e('Random Sample', 'wc-multi-store-sync'); ?></option>
                        </select>
                        <p class="description"><?php _e('Which products to include in verification', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="product_limit"><?php _e('Product Limit', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="product_limit" id="product_limit" value="<?php echo esc_attr($settings['product_limit']); ?>" min="0" step="1" class="small-text">
                        <p class="description"><?php _e('Maximum number of products to check (0 = unlimited). Recommended: 100-500 for large catalogs to avoid timeouts.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="batch_size"><?php _e('Batch Size', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="batch_size" id="batch_size" value="<?php echo esc_attr(isset($settings['batch_size']) ? $settings['batch_size'] : 20); ?>" min="1" max="100" step="1" class="small-text">
                        <span><?php _e('products per batch', 'wc-multi-store-sync'); ?></span>
                        <p class="description"><?php _e('Number of products to verify per batch. Higher values = faster verification but more server load. Recommended: 10-30.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_correct"><?php _e('Auto-Correct Discrepancies', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_correct" id="auto_correct" value="1"
                                   <?php checked($settings['auto_correct'], true); ?>>
                            <?php _e('Automatically queue products for re-sync when discrepancies are found', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, products with discrepancies will be automatically added to the sync queue for correction.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr id="auto_correct_limit_row" style="<?php echo !$settings['auto_correct'] ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="auto_correct_limit"><?php _e('Auto-Correct Limit', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <?php $auto_correct_limit = isset($settings['auto_correct_limit']) ? (int) $settings['auto_correct_limit'] : 500; ?>
                        <input type="number" name="auto_correct_limit" id="auto_correct_limit"
                               value="<?php echo esc_attr($auto_correct_limit); ?>"
                               min="0" max="10000" step="50" class="small-text">
                        <span><?php _e('products max', 'wc-multi-store-sync'); ?></span>
                        <p class="description">
                            <?php _e('Maximum number of products to auto-correct per verification run. Set to 0 for unlimited (not recommended). Default: 500.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <?php
                // Auto-correct sync settings
                $weekly_sync_type = isset($settings['weekly_sync_type']) ? $settings['weekly_sync_type'] : 'use_default';
                $weekly_stock_sync = isset($settings['weekly_stock_sync']) ? $settings['weekly_stock_sync'] : 'use_default';
                $weekly_category_auto_create = isset($settings['weekly_category_auto_create']) ? $settings['weekly_category_auto_create'] : 'use_default';
                ?>

                <tr id="auto_correct_sync_type_row" class="auto-correct-option" style="<?php echo !$settings['auto_correct'] ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="weekly_sync_type"><?php _e('Auto-Correct Sync Type', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="weekly_sync_type" id="weekly_sync_type">
                            <?php
                            WC_Multi_Store_Sync_Type_Options::render([
                                'use_default' => __('Use General Settings', 'wc-multi-store-sync'),
                                'full_product' => __('Full Product (all data)', 'wc-multi-store-sync'),
                                'price_quantity_categories' => __('Price, Quantity & Categories', 'wc-multi-store-sync'),
                                'price_quantity' => __('Price & Quantity', 'wc-multi-store-sync'),
                                'quantity' => __('Quantity Only (fastest)', 'wc-multi-store-sync'),
                            ], $weekly_sync_type);
                            ?>
                        </select>
                        <p class="description">
                            <?php _e('What type of data to sync when auto-correcting discrepancies.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr id="auto_correct_stock_sync_row" class="auto-correct-option" style="<?php echo !$settings['auto_correct'] ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="weekly_stock_sync"><?php _e('Stock Synchronization', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="weekly_stock_sync" id="weekly_stock_sync">
                            <option value="use_default" <?php selected($weekly_stock_sync, 'use_default'); ?>>
                                <?php _e('Use General Settings', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="enabled" <?php selected($weekly_stock_sync, 'enabled'); ?>>
                                <?php _e('Enabled', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="disabled" <?php selected($weekly_stock_sync, 'disabled'); ?>>
                                <?php _e('Disabled', 'wc-multi-store-sync'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e('Whether to sync stock quantities when auto-correcting.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr id="auto_correct_category_row" class="auto-correct-option" style="<?php echo !$settings['auto_correct'] ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="weekly_category_auto_create"><?php _e('Auto-create Categories', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="weekly_category_auto_create" id="weekly_category_auto_create">
                            <option value="use_default" <?php selected($weekly_category_auto_create, 'use_default'); ?>>
                                <?php _e('Use General Settings', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="enabled" <?php selected($weekly_category_auto_create, 'enabled'); ?>>
                                <?php _e('Enabled', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="disabled" <?php selected($weekly_category_auto_create, 'disabled'); ?>>
                                <?php _e('Disabled', 'wc-multi-store-sync'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e('Whether to auto-create missing categories on remote stores when auto-correcting.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <script>
                jQuery(document).ready(function($) {
                    $('#auto_correct').on('change', function() {
                        if ($(this).is(':checked')) {
                            $('#auto_correct_limit_row').show();
                            $('.auto-correct-option').show();
                        } else {
                            $('#auto_correct_limit_row').hide();
                            $('.auto-correct-option').hide();
                        }
                    });
                });
                </script>

                <tr>
                    <th scope="row">
                        <label for="email_enabled"><?php _e('Email Notifications', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label style="display: block; margin-bottom: 10px;">
                            <input type="checkbox" name="email_enabled" id="email_enabled" value="1"
                                   <?php checked($settings['email_enabled'], true); ?>>
                            <?php _e('Send email when discrepancies are found', 'wc-multi-store-sync'); ?>
                        </label>

                        <label for="email_recipients"><?php _e('Email Recipients:', 'wc-multi-store-sync'); ?></label><br>
                        <input type="text" name="email_recipients" id="email_recipients" value="<?php echo esc_attr($settings['email_recipients']); ?>" class="regular-text">
                        <p class="description"><?php _e('Comma-separated list of email addresses to notify when discrepancies are found.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="wc_mss_save_weekly_verification" class="button button-primary">
                    <?php _e('Save Settings', 'wc-multi-store-sync'); ?>
                </button>
            </p>
        </form>
    </div>

    <div class="wc-mss-card">
        <h2><?php _e('Manual Verification & Sync Control', 'wc-multi-store-sync'); ?></h2>

        <?php if ($next_run): ?>
            <p>
                <strong><?php _e('Next Scheduled Run:', 'wc-multi-store-sync'); ?></strong>
                <?php echo date('l, F j, Y \a\t g:i A', $next_run); ?>
                (<?php echo human_time_diff($next_run, current_time('timestamp')); ?>)
            </p>
        <?php else: ?>
            <p><em><?php _e('No verification scheduled. Enable weekly verification above to schedule.', 'wc-multi-store-sync'); ?></em></p>
        <?php endif; ?>

        <div style="margin: 15px 0;">
            <button type="button" id="wc-mss-start-verification-page" class="button button-secondary">
                <?php _e('Run Verification Now', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" id="wc-mss-cancel-verification-page" class="button" style="display: none; margin-left: 10px;">
                <?php _e('Cancel', 'wc-multi-store-sync'); ?>
            </button>
            <span class="description" style="margin-left: 10px;"><?php _e('Runs verification in the background.', 'wc-multi-store-sync'); ?></span>
        </div>

        <h3><?php _e('Stop Synchronization', 'wc-multi-store-sync'); ?></h3>
        <p class="description"><?php _e('Use these buttons to stop sync operations. Products queued by auto-correct will be removed.', 'wc-multi-store-sync'); ?></p>
        <div style="margin: 15px 0;">
            <button type="button" id="stop-weekly-sync" class="button button-secondary" style="color: #d63638; border-color: #d63638;">
                <span class="dashicons dashicons-controls-pause" style="margin-top: 4px;"></span>
                <?php _e('Stop Weekly Verification', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" id="stop-all-sync" class="button button-secondary" style="color: #fff; background-color: #d63638; border-color: #d63638; margin-left: 10px;">
                <span class="dashicons dashicons-no" style="margin-top: 4px;"></span>
                <?php _e('Stop All Sync & Clear Queue', 'wc-multi-store-sync'); ?>
            </button>
        </div>
        <div id="sync-stop-status" style="margin-top: 10px; display: none;"></div>
        <div id="wc-mss-verification-progress-page" style="display: none; margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                <span id="wc-mss-verification-status-page"><?php _e('Starting...', 'wc-multi-store-sync'); ?></span>
                <span id="wc-mss-verification-percent-page">0%</span>
            </div>
            <div class="wc-mss-progress-container">
                <div class="wc-mss-progress-bar" id="wc-mss-verification-bar-page" style="width: 0%;"></div>
            </div>
            <div style="margin-top: 10px; font-size: 12px; color: #646970;">
                <span id="wc-mss-verification-details-page"></span>
            </div>
        </div>
        <div id="wc-mss-verification-result-page" style="margin-top: 10px;"></div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var startBtn = document.getElementById('wc-mss-start-verification-page');
            var cancelBtn = document.getElementById('wc-mss-cancel-verification-page');
            var progressDiv = document.getElementById('wc-mss-verification-progress-page');
            var resultDiv = document.getElementById('wc-mss-verification-result-page');
            var statusSpan = document.getElementById('wc-mss-verification-status-page');
            var percentSpan = document.getElementById('wc-mss-verification-percent-page');
            var progressBar = document.getElementById('wc-mss-verification-bar-page');
            var detailsSpan = document.getElementById('wc-mss-verification-details-page');
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var nonce = '<?php echo esc_js(wp_create_nonce('wc_mss_admin')); ?>';
            var pollInterval = null;
            var verificationJustStarted = false;
            var idleCount = 0; // Count consecutive idle responses before resetting

            function showResult(message, isError) {
                resultDiv.textContent = '';
                var notice = document.createElement('div');
                notice.className = 'notice inline ' + (isError ? 'notice-error' : 'notice-success');
                var p = document.createElement('p');
                p.textContent = message;
                notice.appendChild(p);
                resultDiv.appendChild(notice);
            }

            function startVerification() {
                startBtn.disabled = true;
                startBtn.textContent = '<?php echo esc_js(__('Starting...', 'wc-multi-store-sync')); ?>';
                resultDiv.textContent = '';
                verificationJustStarted = true;
                idleCount = 0;

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=wc_mss_start_verification&nonce=' + nonce
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        progressDiv.style.display = 'block';
                        cancelBtn.style.display = 'inline-block';
                        startBtn.style.display = 'none';
                        statusSpan.textContent = data.data.message;
                        // Wait a bit before first poll to allow transient to be set
                        setTimeout(function() {
                            pollProgress();
                            pollInterval = setInterval(pollProgress, 3000);
                        }, 1000);
                    } else {
                        verificationJustStarted = false;
                        startBtn.disabled = false;
                        startBtn.textContent = '<?php echo esc_js(__('Run Verification Now', 'wc-multi-store-sync')); ?>';
                        showResult(data.data.message || 'Error', true);
                    }
                });
            }

            function pollProgress() {
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=wc_mss_get_verification_progress&nonce=' + nonce
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        var d = data.data;
                        if (d.status === 'running') {
                            idleCount = 0; // Reset idle counter
                            verificationJustStarted = false;
                            percentSpan.textContent = d.percent + '%';
                            progressBar.style.width = d.percent + '%';
                            statusSpan.textContent = '<?php echo esc_js(__('Verifying...', 'wc-multi-store-sync')); ?>';
                            detailsSpan.textContent = d.processed + '/' + d.total + ' | <?php echo esc_js(__('Batch', 'wc-multi-store-sync')); ?> ' + d.current_batch + '/' + d.total_batches;
                        } else if (d.status === 'completed') {
                            clearInterval(pollInterval);
                            verificationJustStarted = false;
                            percentSpan.textContent = '100%';
                            progressBar.style.width = '100%';
                            statusSpan.textContent = '<?php echo esc_js(__('Completed!', 'wc-multi-store-sync')); ?>';
                            detailsSpan.textContent = d.discrepancies + ' <?php echo esc_js(__('discrepancies found', 'wc-multi-store-sync')); ?>';
                            showResult('<?php echo esc_js(__('Verification completed. Refresh page to see results.', 'wc-multi-store-sync')); ?>', false);
                            setTimeout(function() { window.location.reload(); }, 3000);
                        } else if (d.status === 'cancelled') {
                            clearInterval(pollInterval);
                            verificationJustStarted = false;
                            resetUI();
                        } else if (d.status === 'idle') {
                            // Only reset after many consecutive idle responses
                            // Action Scheduler may take up to 1-2 minutes to start processing
                            idleCount++;
                            if (verificationJustStarted && idleCount < 40) {
                                // Still waiting for Action Scheduler to pick up the task (up to 2 min)
                                var waitTime = idleCount * 3;
                                statusSpan.textContent = '<?php echo esc_js(__('Waiting for Action Scheduler...', 'wc-multi-store-sync')); ?> (' + waitTime + 's)';
                                detailsSpan.textContent = '<?php echo esc_js(__('Action Scheduler processes tasks in the background. This may take up to 1-2 minutes.', 'wc-multi-store-sync')); ?>';
                            } else if (idleCount >= 40) {
                                // After 40 consecutive idle responses (~2 minutes), stop polling but don't reset
                                clearInterval(pollInterval);
                                verificationJustStarted = false;
                                statusSpan.textContent = '<?php echo esc_js(__('Verification scheduled', 'wc-multi-store-sync')); ?>';
                                detailsSpan.textContent = '<?php echo esc_js(__('The verification is running in the background. Refresh this page to check progress.', 'wc-multi-store-sync')); ?>';
                                progressBar.style.width = '5%';
                                progressBar.style.background = '#ffb900';
                                cancelBtn.style.display = 'none';
                                // Don't reset UI - keep progress div visible
                            }
                        } else if (d.status === 'pending') {
                            // Action is scheduled but not yet running
                            idleCount = 0;
                            statusSpan.textContent = '<?php echo esc_js(__('Scheduled, waiting to start...', 'wc-multi-store-sync')); ?>';
                            detailsSpan.textContent = '<?php echo esc_js(__('Action Scheduler will process this soon.', 'wc-multi-store-sync')); ?>';
                            progressBar.style.width = '2%';
                        }
                    }
                });
            }

            function cancelVerification() {
                cancelBtn.disabled = true;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=wc_mss_cancel_verification&nonce=' + nonce
                }).then(function() {
                    clearInterval(pollInterval);
                    statusSpan.textContent = '<?php echo esc_js(__('Cancelling...', 'wc-multi-store-sync')); ?>';
                });
            }

            function resetUI() {
                progressDiv.style.display = 'none';
                cancelBtn.style.display = 'none';
                cancelBtn.disabled = false;
                startBtn.style.display = 'inline-block';
                startBtn.disabled = false;
                startBtn.textContent = '<?php echo esc_js(__('Run Verification Now', 'wc-multi-store-sync')); ?>';
            }

            startBtn.addEventListener('click', startVerification);
            cancelBtn.addEventListener('click', cancelVerification);

            // Check if already running
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=wc_mss_get_verification_progress&nonce=' + nonce
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.data && data.data.status === 'running') {
                    progressDiv.style.display = 'block';
                    cancelBtn.style.display = 'inline-block';
                    startBtn.style.display = 'none';
                    pollProgress();
                    pollInterval = setInterval(pollProgress, 3000);
                }
            });
        });
        </script>
    </div>

    <?php if ($latest_report): ?>
        <div class="wc-mss-card">
            <h2><?php _e('Latest Verification Report', 'wc-multi-store-sync'); ?></h2>

            <div class="wc-mss-report-summary">
                <p>
                    <strong><?php _e('Run Date:', 'wc-multi-store-sync'); ?></strong>
                    <?php echo date('F j, Y \a\t g:i A', strtotime($latest_report['started_at'])); ?>
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
                    <div style="background: #f0f0f1; padding: 15px; border-radius: 4px;">
                        <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo number_format($latest_report['products_checked']); ?></div>
                        <div style="color: #646970;"><?php _e('Products Checked', 'wc-multi-store-sync'); ?></div>
                    </div>
                    <div style="background: #f0f0f1; padding: 15px; border-radius: 4px;">
                        <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo number_format($latest_report['stores_checked']); ?></div>
                        <div style="color: #646970;"><?php _e('Stores Checked', 'wc-multi-store-sync'); ?></div>
                    </div>
                    <div style="background: <?php echo $latest_report['discrepancies_found'] > 0 ? '#fff3cd' : '#d1f0d1'; ?>; padding: 15px; border-radius: 4px;">
                        <div style="font-size: 24px; font-weight: bold; color: <?php echo $latest_report['discrepancies_found'] > 0 ? '#856404' : '#0f5c0f'; ?>;">
                            <?php echo number_format($latest_report['discrepancies_found']); ?>
                        </div>
                        <div style="color: #646970;"><?php _e('Discrepancies Found', 'wc-multi-store-sync'); ?></div>
                    </div>
                    <div style="background: #f0f0f1; padding: 15px; border-radius: 4px;">
                        <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo number_format($latest_report['duration_seconds'], 1); ?>s</div>
                        <div style="color: #646970;"><?php _e('Duration', 'wc-multi-store-sync'); ?></div>
                    </div>
                </div>

                <?php if ($latest_report['discrepancies_found'] > 0): ?>
                    <div style="margin-top: 15px;">
                        <h3><?php _e('Breakdown', 'wc-multi-store-sync'); ?></h3>
                        <ul>
                            <li><strong><?php _e('Missing Products:', 'wc-multi-store-sync'); ?></strong> <?php echo number_format($latest_report['missing_products']); ?></li>
                            <?php
                            $report_data_temp = maybe_unserialize($latest_report['report_data']);
                            $orphan_count = isset($report_data_temp['orphan_products']) ? $report_data_temp['orphan_products'] : 0;
                            if ($orphan_count > 0):
                            ?>
                            <li style="color: #d63638;">
                                <strong><?php _e('Orphan Products:', 'wc-multi-store-sync'); ?></strong> <?php echo number_format($orphan_count); ?>
                                <em>(<?php _e('exist on remote but should be excluded', 'wc-multi-store-sync'); ?>)</em>
                                <button type="button" id="wc-mss-queue-orphans" class="button button-small" style="margin-left: 10px; background: #d63638; border-color: #d63638; color: #fff;">
                                    <?php _e('Queue for Deletion', 'wc-multi-store-sync'); ?>
                                </button>
                                <span id="wc-mss-orphan-status" style="margin-left: 10px;"></span>
                            </li>
                            <?php endif; ?>
                            <li><strong><?php _e('Stock Mismatches:', 'wc-multi-store-sync'); ?></strong> <?php echo number_format($latest_report['stock_mismatches']); ?></li>
                            <li><strong><?php _e('Price Mismatches:', 'wc-multi-store-sync'); ?></strong> <?php echo number_format($latest_report['price_mismatches']); ?></li>
                        </ul>

                        <?php
                        $report_data = maybe_unserialize($latest_report['report_data']);
                        if (!empty($report_data['details'])):
                            $shown = 0;
                        ?>
                            <h3><?php _e('Sample Discrepancies (First 5)', 'wc-multi-store-sync'); ?></h3>
                            <?php foreach ($report_data['details'] as $product_report):
                                if ($shown >= 5) break;
                                if (!empty($product_report['discrepancies'])):
                                    $shown++;
                            ?>
                                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 10px 0;">
                                    <strong><?php echo esc_html($product_report['name']); ?></strong>
                                    (SKU: <?php echo esc_html($product_report['sku']); ?>, ID: <?php echo $product_report['product_id']; ?>)
                                    <ul style="margin: 5px 0 0 20px;">
                                        <?php foreach ($product_report['discrepancies'] as $disc): ?>
                                            <li>
                                                <strong><?php echo esc_html($disc['store_name']); ?>:</strong>
                                                <?php echo WC_Multi_Store_Weekly_Sync_Verifier::format_discrepancy_message($disc); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="background: #d1f0d1; border-left: 4px solid #46b450; padding: 15px; margin: 15px 0;">
                        <strong><?php _e('All products are in sync!', 'wc-multi-store-sync'); ?></strong>
                        <p><?php _e('No discrepancies were found during this verification.', 'wc-multi-store-sync'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($reports)): ?>
        <div class="wc-mss-card">
            <h2><?php _e('Recent Verification Reports', 'wc-multi-store-sync'); ?></h2>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Date', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Products Checked', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Stores', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Discrepancies', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Duration', 'wc-multi-store-sync'); ?></th>
                        <th><?php _e('Status', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?php echo date('M j, Y g:i A', strtotime($report['started_at'])); ?></td>
                            <td><?php echo number_format($report['products_checked']); ?></td>
                            <td><?php echo number_format($report['stores_checked']); ?></td>
                            <td>
                                <span style="<?php echo $report['discrepancies_found'] > 0 ? 'color: #856404; font-weight: bold;' : 'color: #0f5c0f;'; ?>">
                                    <?php echo number_format($report['discrepancies_found']); ?>
                                </span>
                                <?php if ($report['discrepancies_found'] > 0): ?>
                                    <br><small>
                                        <?php
                                        $parts = [];
                                        if ($report['missing_products'] > 0) {
                                            $parts[] = number_format($report['missing_products']) . ' missing';
                                        }
                                        $rd = maybe_unserialize($report['report_data']);
                                        if (isset($rd['orphan_products']) && $rd['orphan_products'] > 0) {
                                            $parts[] = '<span style="color:#d63638;">' . number_format($rd['orphan_products']) . ' orphan</span>';
                                        }
                                        if ($report['stock_mismatches'] > 0) {
                                            $parts[] = number_format($report['stock_mismatches']) . ' stock';
                                        }
                                        if ($report['price_mismatches'] > 0) {
                                            $parts[] = number_format($report['price_mismatches']) . ' price';
                                        }
                                        echo '(' . implode(', ', $parts) . ')';
                                        ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($report['duration_seconds'], 1); ?>s</td>
                            <td>
                                <?php
                                $status_labels = [
                                    'completed' => __('Completed', 'wc-multi-store-sync'),
                                    'running' => __('Running', 'wc-multi-store-sync'),
                                    'failed' => __('Failed', 'wc-multi-store-sync'),
                                ];
                                echo isset($status_labels[$report['status']]) ? $status_labels[$report['status']] : $report['status'];
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
            <div class="wc-mss-sidebar-card">
                <h3><?php _e('System Health', 'wc-multi-store-sync'); ?></h3>

                <div class="wc-mss-health-local">
                    <span class="dashicons dashicons-store"></span>
                    <strong><?php _e('Local Store', 'wc-multi-store-sync'); ?></strong>
                    <div class="wc-mss-health-stats">
                        <span class="stat"><?php echo $local_cat_count; ?> <?php _e('Cat', 'wc-multi-store-sync'); ?></span>
                        <span class="stat"><?php echo $local_tag_count; ?> <?php _e('Tags', 'wc-multi-store-sync'); ?></span>
                    </div>
                </div>

                <?php if (empty($store_health)): ?>
                    <p class="wc-mss-no-stores">
                        <span class="dashicons dashicons-warning"></span>
                        <?php _e('No active stores', 'wc-multi-store-sync'); ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($store_health as $health):
                        $cat_missing = count($health['categories']['missing']);
                        $tag_missing = count($health['tags']['missing']);
                        $cat_synced = $local_cat_count - $cat_missing;
                        $tag_synced = $local_tag_count - $tag_missing;
                        $no_cache = !$health['categories']['cached'] && !$health['tags']['cached'];
                        $all_good = $cat_missing === 0 && $tag_missing === 0 && !$no_cache;

                        if ($all_good) {
                            $status_class = 'status-ok';
                            $status_icon = 'yes-alt';
                        } elseif ($no_cache) {
                            $status_class = 'status-pending';
                            $status_icon = 'clock';
                        } else {
                            $status_class = 'status-warning';
                            $status_icon = 'warning';
                        }
                    ?>
                    <div class="wc-mss-health-store <?php echo $status_class; ?>">
                        <div class="store-header">
                            <span class="dashicons dashicons-<?php echo $status_icon; ?>"></span>
                            <strong><?php echo esc_html($health['name']); ?></strong>
                        </div>
                        <?php if ($no_cache): ?>
                            <small class="store-status"><?php _e('No cache yet', 'wc-multi-store-sync'); ?></small>
                        <?php else: ?>
                            <div class="store-counts">
                                <div class="sync-row" title="<?php echo $cat_missing > 0 ? esc_attr(__('Missing: ', 'wc-multi-store-sync') . implode(', ', array_slice($health['categories']['missing'], 0, 10))) : ''; ?>">
                                    <span class="sync-label"><?php _e('Categories:', 'wc-multi-store-sync'); ?></span>
                                    <span class="sync-status <?php echo $cat_missing === 0 ? 'synced' : 'partial'; ?>">
                                        <?php echo $cat_synced; ?>/<?php echo $local_cat_count; ?>
                                        <?php if ($cat_missing === 0): ?>
                                            <span class="dashicons dashicons-yes"></span>
                                        <?php else: ?>
                                            <em class="missing">(<?php echo $cat_missing; ?> <?php _e('missing', 'wc-multi-store-sync'); ?>)</em>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="sync-row">
                                    <span class="sync-label"><?php _e('Tags:', 'wc-multi-store-sync'); ?></span>
                                    <span class="sync-status <?php echo $tag_missing === 0 ? 'synced' : 'partial'; ?>">
                                        <?php echo $tag_synced; ?>/<?php echo $local_tag_count; ?>
                                        <?php if ($tag_missing === 0): ?>
                                            <span class="dashicons dashicons-yes"></span>
                                        <?php else: ?>
                                            <em class="missing">(<?php echo $tag_missing; ?> <?php _e('missing', 'wc-multi-store-sync'); ?>)</em>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <p class="wc-mss-health-note">
                        <small>
                            <?php printf(__('Match: %s', 'wc-multi-store-sync'), $match_by); ?><br>
                            <?php _e('Cache: 24h', 'wc-multi-store-sync'); ?>
                        </small>
                    </p>
                <?php endif; ?>
            </div>
        </div><!-- /.wc-mss-sidebar -->

    </div><!-- /.wc-mss-two-column-layout -->
</div>

<style>
/* Two-column layout */
.wc-mss-two-column-layout {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}

.wc-mss-main-content {
    flex: 1;
    min-width: 0;
}

.wc-mss-sidebar {
    width: 280px;
    flex-shrink: 0;
    position: sticky;
    top: 32px;
}

@media (max-width: 1200px) {
    .wc-mss-two-column-layout {
        flex-direction: column;
    }
    .wc-mss-sidebar {
        width: 100%;
        position: static;
    }
}

/* Sidebar card */
.wc-mss-sidebar-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 15px;
    border-radius: 4px;
}

.wc-mss-sidebar-card h3 {
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #dcdcde;
    font-size: 14px;
}

/* Health local */
.wc-mss-health-local {
    background: #f0f6fc;
    border-left: 3px solid #0073aa;
    padding: 10px;
    margin-bottom: 15px;
    border-radius: 0 4px 4px 0;
}

.wc-mss-health-local .dashicons {
    color: #0073aa;
    margin-right: 5px;
}

.wc-mss-health-stats {
    margin-top: 5px;
    font-size: 13px;
}

.wc-mss-health-stats .stat {
    display: inline-block;
    background: #fff;
    padding: 2px 8px;
    border-radius: 3px;
    margin-right: 5px;
}

/* Health stores */
.wc-mss-health-store {
    padding: 8px 10px;
    margin-bottom: 8px;
    border-radius: 4px;
    font-size: 12px;
}

.wc-mss-health-store.status-ok {
    background: #edfaef;
    border-left: 3px solid #00a32a;
}

.wc-mss-health-store.status-warning {
    background: #fcf9e8;
    border-left: 3px solid #dba617;
}

.wc-mss-health-store.status-pending {
    background: #f6f7f7;
    border-left: 3px solid #999;
}

.wc-mss-health-store .store-header {
    display: flex;
    align-items: center;
    gap: 5px;
}

.wc-mss-health-store .store-header .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.status-ok .dashicons { color: #00a32a; }
.status-warning .dashicons { color: #dba617; }
.status-pending .dashicons { color: #999; }

.wc-mss-health-store .store-counts {
    margin-top: 6px;
    padding-left: 21px;
    color: #666;
}

.wc-mss-health-store .sync-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 3px;
    font-size: 11px;
}

.wc-mss-health-store .sync-row:last-child {
    margin-bottom: 0;
}

.wc-mss-health-store .sync-label {
    color: #666;
}

.wc-mss-health-store .sync-status {
    display: flex;
    align-items: center;
    gap: 3px;
}

.wc-mss-health-store .sync-status.synced {
    color: #00a32a;
    font-weight: 500;
}

.wc-mss-health-store .sync-status.synced .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}

.wc-mss-health-store .sync-status.partial {
    color: #996800;
}

.wc-mss-health-store .missing {
    color: #d63638;
    font-style: normal;
    font-size: 10px;
}

.wc-mss-health-store .store-status {
    color: #666;
    display: block;
    padding-left: 21px;
}

.wc-mss-health-note {
    margin: 10px 0 0 0;
    padding-top: 10px;
    border-top: 1px solid #dcdcde;
    color: #666;
}

.wc-mss-no-stores {
    color: #d63638;
    margin: 0;
}

/* Main cards */
.wc-mss-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin: 20px 0;
    padding: 20px;
}

.wc-mss-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #dcdcde;
}

.wc-mss-report-summary {
    margin-top: 15px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var button = document.getElementById('wc-mss-queue-orphans');
    var status = document.getElementById('wc-mss-orphan-status');

    if (!button) return;

    function setStatus(message, isSuccess) {
        status.textContent = message;
        status.style.color = isSuccess ? '#46b450' : '#dc3232';
    }

    button.addEventListener('click', function() {
        if (!confirm('<?php echo esc_js(__('This will queue all orphan products for deletion from their respective remote stores. Are you sure?', 'wc-multi-store-sync')); ?>')) {
            return;
        }

        button.disabled = true;
        button.textContent = '<?php echo esc_js(__('Queuing...', 'wc-multi-store-sync')); ?>';
        status.textContent = '';

        var formData = new FormData();
        formData.append('action', 'wc_mss_queue_orphans_for_deletion');
        formData.append('nonce', '<?php echo wp_create_nonce('wc_mss_admin'); ?>');

        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(response) {
            if (response.success) {
                setStatus(response.data.message, true);
                // Hide button after successful queuing
                setTimeout(function() {
                    button.style.display = 'none';
                }, 1500);
                button.textContent = '<?php echo esc_js(__('Queued!', 'wc-multi-store-sync')); ?>';
                button.style.background = '#46b450';
                button.style.borderColor = '#46b450';
            } else {
                setStatus(response.data.message, false);
                button.disabled = false;
                button.textContent = '<?php echo esc_js(__('Queue for Deletion', 'wc-multi-store-sync')); ?>';
            }
        })
        .catch(function() {
            setStatus('<?php echo esc_js(__('Request failed. Please try again.', 'wc-multi-store-sync')); ?>', false);
            button.disabled = false;
            button.textContent = '<?php echo esc_js(__('Queue for Deletion', 'wc-multi-store-sync')); ?>';
        });
    });
});
</script>

<script>
jQuery(document).ready(function($) {
    var $stopWeeklyBtn = $('#stop-weekly-sync');
    var $stopAllBtn = $('#stop-all-sync');
    var $stopStatus = $('#sync-stop-status');

    // Stop Weekly Verification
    $stopWeeklyBtn.on('click', function() {
        if (!confirm('<?php echo esc_js(__('Are you sure you want to disable weekly verification? You can re-enable it in the settings above.', 'wc-multi-store-sync')); ?>')) {
            return;
        }

        $stopWeeklyBtn.prop('disabled', true).text('<?php echo esc_js(__('Stopping...', 'wc-multi-store-sync')); ?>');
        $stopStatus.show().html('<span style="color: #666;"><?php echo esc_js(__('Disabling weekly verification...', 'wc-multi-store-sync')); ?></span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_mss_stop_weekly_verification',
                nonce: '<?php echo wp_create_nonce('wc_mss_stop_sync'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $stopStatus.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                    $('#verification_enabled').prop('checked', false);
                } else {
                    $stopStatus.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $stopStatus.html('<span style="color: #d63638;">✗ <?php echo esc_js(__('Request failed. Please try again.', 'wc-multi-store-sync')); ?></span>');
            },
            complete: function() {
                $stopWeeklyBtn.prop('disabled', false).html('<span class="dashicons dashicons-controls-pause" style="margin-top: 4px;"></span> <?php echo esc_js(__('Stop Weekly Verification', 'wc-multi-store-sync')); ?>');
            }
        });
    });

    // Stop All Sync & Clear Queue
    $stopAllBtn.on('click', function() {
        if (!confirm('<?php echo esc_js(__('WARNING: This will clear the entire queue and cancel pending sync jobs. The plugin will remain active. Products currently waiting to sync will be removed. Are you sure?', 'wc-multi-store-sync')); ?>')) {
            return;
        }

        $stopAllBtn.prop('disabled', true).text('<?php echo esc_js(__('Stopping...', 'wc-multi-store-sync')); ?>');
        $stopStatus.show().html('<span style="color: #666;"><?php echo esc_js(__('Stopping all sync operations...', 'wc-multi-store-sync')); ?></span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_mss_stop_all_sync',
                nonce: '<?php echo wp_create_nonce('wc_mss_stop_sync'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $stopStatus.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                } else {
                    $stopStatus.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $stopStatus.html('<span style="color: #d63638;">✗ <?php echo esc_js(__('Request failed. Please try again.', 'wc-multi-store-sync')); ?></span>');
            },
            complete: function() {
                $stopAllBtn.prop('disabled', false).html('<span class="dashicons dashicons-no" style="margin-top: 4px;"></span> <?php echo esc_js(__('Stop All Sync & Clear Queue', 'wc-multi-store-sync')); ?>');
            }
        });
    });
});
</script>
