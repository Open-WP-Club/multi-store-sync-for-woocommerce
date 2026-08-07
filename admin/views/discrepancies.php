<?php
/**
 * Stock Discrepancies Admin Page
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle actions
if (isset($_POST['action']) && isset($_POST['discrepancy_id'])) {
    check_admin_referer('wc_mss_discrepancy_action');

    $discrepancy_id = absint($_POST['discrepancy_id']);
    $action = sanitize_text_field($_POST['action']);

    switch ($action) {
        case 'mark_resolved':
            if (WC_Multi_Store_Stock_Verifier::mark_resolved($discrepancy_id)) {
                echo '<div class="notice notice-success"><p>' . __('Discrepancy marked as resolved.', 'wc-multi-store-sync') . '</p></div>';
            }
            break;

        case 'mark_ignored':
            if (WC_Multi_Store_Stock_Verifier::mark_ignored($discrepancy_id)) {
                echo '<div class="notice notice-success"><p>' . __('Discrepancy marked as ignored.', 'wc-multi-store-sync') . '</p></div>';
            }
            break;

        case 'auto_correct':
            $result = WC_Multi_Store_Stock_Verifier::auto_correct($discrepancy_id);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . __('Auto-correction queued. Stock will be synced shortly.', 'wc-multi-store-sync') . '</p></div>';
            }
            break;
    }
}

// Handle bulk cleanup
if (isset($_POST['cleanup_old']) && check_admin_referer('wc_mss_cleanup_discrepancies')) {
    $days = isset($_POST['cleanup_days']) ? absint($_POST['cleanup_days']) : 30;
    $deleted = WC_Multi_Store_Stock_Verifier::cleanup_old_discrepancies($days);
    echo '<div class="notice notice-success"><p>' . sprintf(__('Cleaned up %d old discrepancies.', 'wc-multi-store-sync'), $deleted) . '</p></div>';
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
$store_filter = isset($_GET['store']) ? sanitize_text_field($_GET['store']) : '';

// Get discrepancies
$args = [
    'status' => $status_filter,
    'limit' => 100,
    'orderby' => 'detected_at',
    'order' => 'DESC',
];

if (!empty($store_filter)) {
    $args['store_url'] = $store_filter;
}

$discrepancies = WC_Multi_Store_Stock_Verifier::get_discrepancies($args);
$pending_count = WC_Multi_Store_Stock_Verifier::get_discrepancy_count('pending');
$total_count = WC_Multi_Store_Stock_Verifier::get_discrepancy_count('all');

// Get all stores for filter
$stores = get_option('wc_multi_store_sync_stores', []);
?>

<div class="wrap">
    <h1>
        <?php _e('Stock Discrepancies', 'wc-multi-store-sync'); ?>
        <?php if ($pending_count > 0): ?>
            <span class="wc-mss-badge wc-mss-badge-error"><?php echo esc_html($pending_count); ?></span>
        <?php endif; ?>
    </h1>

    <p class="description">
        <?php _e('This page shows stock level discrepancies detected between your main store and remote stores. Discrepancies occur when the actual stock on a remote store does not match the expected stock after synchronization.', 'wc-multi-store-sync'); ?>
    </p>

    <div class="wc-mss-discrepancies-filters" style="margin: 20px 0;">
        <form method="get">
            <input type="hidden" name="page" value="wc-settings" />
            <input type="hidden" name="tab" value="multi_store_sync" />
            <input type="hidden" name="section" value="discrepancies" />

            <select name="status" id="status-filter">
                <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'wc-multi-store-sync'); ?></option>
                <option value="resolving" <?php selected($status_filter, 'resolving'); ?>><?php _e('Resolving', 'wc-multi-store-sync'); ?></option>
                <option value="resolved" <?php selected($status_filter, 'resolved'); ?>><?php _e('Resolved', 'wc-multi-store-sync'); ?></option>
                <option value="ignored" <?php selected($status_filter, 'ignored'); ?>><?php _e('Ignored', 'wc-multi-store-sync'); ?></option>
                <option value="all" <?php selected($status_filter, 'all'); ?>><?php _e('All', 'wc-multi-store-sync'); ?></option>
            </select>

            <select name="store" id="store-filter">
                <option value=""><?php _e('All Stores', 'wc-multi-store-sync'); ?></option>
                <?php foreach ($stores as $store_url => $store_config): ?>
                    <?php if (($store_config['status'] ?? '') === 'active'): ?>
                        <option value="<?php echo esc_attr($store_url); ?>" <?php selected($store_filter, $store_url); ?>>
                            <?php echo esc_html($store_config['name'] ?? $store_url); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="button"><?php _e('Filter', 'wc-multi-store-sync'); ?></button>
        </form>

        <form method="post" style="display: inline-block; margin-left: 20px;">
            <?php wp_nonce_field('wc_mss_cleanup_discrepancies'); ?>
            <input type="number" name="cleanup_days" value="30" min="1" max="365" style="width: 60px;" />
            <button type="submit" name="cleanup_old" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete old resolved/ignored discrepancies?', 'wc-multi-store-sync'); ?>');">
                <?php _e('Cleanup Old Records', 'wc-multi-store-sync'); ?>
            </button>
        </form>
    </div>

    <?php if (empty($discrepancies)): ?>
        <div class="notice notice-info">
            <p>
                <?php if ($status_filter === 'pending'): ?>
                    <strong><?php _e('No pending discrepancies found.', 'wc-multi-store-sync'); ?></strong><br>
                    <?php _e('All stock levels are synchronized correctly!', 'wc-multi-store-sync'); ?>
                <?php else: ?>
                    <?php _e('No discrepancies found matching your filters.', 'wc-multi-store-sync'); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Product', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('SKU', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Store', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Expected Stock', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Actual Stock', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Difference', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Detected', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Status', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Actions', 'wc-multi-store-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($discrepancies as $discrepancy): ?>
                    <?php
                    $product = wc_get_product($discrepancy['product_id']);
                    $product_name = $product ? $product->get_name() : __('(Product not found)', 'wc-multi-store-sync');
                    $edit_link = $product ? get_edit_post_link($discrepancy['product_id']) : '';

                    // Find store name — stores are keyed by URL
                    $store_name = parse_url($discrepancy['store_url'], PHP_URL_HOST);
                    $norm_disc_url = untrailingslashit($discrepancy['store_url']);
                    foreach ($stores as $s_url => $s_config) {
                        if (untrailingslashit($s_url) === $norm_disc_url) {
                            $store_name = $s_config['name'] ?? $s_url;
                            break;
                        }
                    }

                    $difference = $discrepancy['difference'];
                    $diff_class = $difference > 0 ? 'wc-mss-positive' : 'wc-mss-negative';
                    $diff_text = sprintf('%+d', $difference);

                    $status_class = 'wc-mss-status-' . $discrepancy['status'];
                    ?>
                    <tr>
                        <td>
                            <?php if ($edit_link): ?>
                                <a href="<?php echo esc_url($edit_link); ?>" target="_blank">
                                    <strong><?php echo esc_html($product_name); ?></strong>
                                </a>
                            <?php else: ?>
                                <strong><?php echo esc_html($product_name); ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($discrepancy['sku']); ?></code></td>
                        <td><?php echo esc_html($store_name); ?></td>
                        <td><?php echo esc_html($discrepancy['expected_stock']); ?></td>
                        <td><?php echo esc_html($discrepancy['actual_stock']); ?></td>
                        <td class="<?php echo esc_attr($diff_class); ?>">
                            <strong><?php echo esc_html($diff_text); ?></strong>
                        </td>
                        <td>
                            <?php echo esc_html(human_time_diff(strtotime($discrepancy['detected_at']), current_time('timestamp'))); ?> <?php _e('ago', 'wc-multi-store-sync'); ?>
                        </td>
                        <td>
                            <span class="wc-mss-status-badge <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html(ucfirst($discrepancy['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($discrepancy['status'] === 'pending'): ?>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('wc_mss_discrepancy_action'); ?>
                                    <input type="hidden" name="discrepancy_id" value="<?php echo esc_attr($discrepancy['id']); ?>" />
                                    <button type="submit" name="action" value="auto_correct" class="button button-primary button-small">
                                        <?php _e('Auto-Correct', 'wc-multi-store-sync'); ?>
                                    </button>
                                    <button type="submit" name="action" value="mark_resolved" class="button button-small">
                                        <?php _e('Mark Resolved', 'wc-multi-store-sync'); ?>
                                    </button>
                                    <button type="submit" name="action" value="mark_ignored" class="button button-small">
                                        <?php _e('Ignore', 'wc-multi-store-sync'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <hr style="margin: 2rem 0;" />

    <h2><?php _e('Category Scan', 'wc-multi-store-sync'); ?></h2>
    <p class="description">
        <?php _e('Compares product categories between your main store and a remote store. Shows products where categories are missing or differ.', 'wc-multi-store-sync'); ?>
    </p>

    <div style="margin: 1rem 0; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <select id="wc-mss-cat-store-select">
            <option value=""><?php _e('All Stores', 'wc-multi-store-sync'); ?></option>
            <?php foreach ($stores as $url => $config): ?>
                <option value="<?php echo esc_attr($url); ?>">
                    <?php echo esc_html($config['name'] ?? $url); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-primary" id="wc-mss-scan-categories-btn">
            <?php _e('Scan Categories', 'wc-multi-store-sync'); ?>
        </button>
    </div>

    <div id="wc-mss-cat-progress" style="display:none; margin-bottom: 1rem;">
        <div class="wc-mss-progress-bar-wrap">
            <div class="wc-mss-progress-bar" id="wc-mss-cat-bar"></div>
        </div>
        <p id="wc-mss-cat-progress-label" style="margin: 0.25rem 0 0;"></p>
    </div>

    <div id="wc-mss-cat-results"></div>
</div>

<?php
// Build store list for JS — only the stores we actually scanned
$cat_scan_stores = [];
foreach ($stores as $url => $cfg) {
    $cat_scan_stores[] = ['url' => $url, 'name' => $cfg['name'] ?? $url];
}
?>

<script>
jQuery(function ($) {
    var btn      = $('#wc-mss-scan-categories-btn');
    var progress = $('#wc-mss-cat-progress');
    var bar      = $('#wc-mss-cat-bar');
    var label    = $('#wc-mss-cat-progress-label');
    var results  = $('#wc-mss-cat-results');
    var nonce    = '<?php echo wp_create_nonce('wc_mss_scan_categories'); ?>';
    var resyncNonce = '<?php echo wp_create_nonce('wc_mss_admin'); ?>';

    var allStores = <?php echo wp_json_encode($cat_scan_stores); ?>;
    var selectedUrl = '';

    btn.on('click', function () {
        selectedUrl = $('#wc-mss-cat-store-select').val();

        // Determine which stores to process
        var storesToScan = selectedUrl
            ? allStores.filter(function (s) { return s.url === selectedUrl; })
            : allStores;

        if (!storesToScan.length) {
            results.html('<div class="notice notice-warning inline"><p><?php echo esc_js(__('No active stores found.', 'wc-multi-store-sync')); ?></p></div>');
            return;
        }

        btn.prop('disabled', true);
        results.html('');
        progress.show();
        bar.css('width', '0%');
        label.text('<?php echo esc_js(__('Starting scan…', 'wc-multi-store-sync')); ?>');

        var allMismatches = [];
        var index = 0;

        function scanNext() {
            if (index >= storesToScan.length) {
                // Done
                btn.prop('disabled', false);
                bar.css('width', '100%');
                label.text('<?php echo esc_js(__('Scan complete.', 'wc-multi-store-sync')); ?>');
                renderResults(allMismatches);
                return;
            }

            var store = storesToScan[index];
            var pct   = Math.round((index / storesToScan.length) * 100);
            bar.css('width', pct + '%');
            label.text('<?php echo esc_js(__('Scanning:', 'wc-multi-store-sync')); ?> ' + store.name + ' (' + (index + 1) + '/' + storesToScan.length + ')');

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:    'wc_mss_scan_categories',
                    nonce:     nonce,
                    store_url: store.url,
                }),
            })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (resp.success && resp.data.count > 0) {
                        allMismatches.push(resp.data);
                    }
                    if (!resp.success) {
                        allMismatches.push({
                            store_name: store.name,
                            store_url:  store.url,
                            error:      resp.data.message || '<?php echo esc_js(__('Unknown error', 'wc-multi-store-sync')); ?>',
                            items:      [],
                            count:      0,
                        });
                    }
                    index++;
                    scanNext();
                })
                .catch(function () {
                    allMismatches.push({
                        store_name: store.name,
                        store_url:  store.url,
                        error:      '<?php echo esc_js(__('Request failed.', 'wc-multi-store-sync')); ?>',
                        items:      [],
                        count:      0,
                    });
                    index++;
                    scanNext();
                });
        }

        scanNext();
    });

    function renderResults(list) {
        var hasAny = list.some(function (s) { return s.count > 0 || s.error; });

        if (!hasAny) {
            results.html('<div class="notice notice-success inline"><p><?php echo esc_js(__('All categories match across all stores!', 'wc-multi-store-sync')); ?></p></div>');
            return;
        }

        var html = '';
        $.each(list, function (i, store) {
            if (store.error) {
                html += '<div class="notice notice-error inline"><p><strong>' + store.store_name + ':</strong> ' + store.error + '</p></div>';
                return;
            }
            if (!store.count) {
                return;
            }

            html += '<h3>' + store.store_name + ' — ' + store.count + ' <?php echo esc_js(__('product(s) with category mismatch', 'wc-multi-store-sync')); ?>';
            html += ' <small style="font-weight:normal;color:#666;">(' + store.total_local + ' <?php echo esc_js(__('local', 'wc-multi-store-sync')); ?> / ' + store.total_remote + ' <?php echo esc_js(__('remote', 'wc-multi-store-sync')); ?>)</small></h3>';
            html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:1.5rem;">';
            html += '<thead><tr>';
            html += '<th style="width:30%"><?php echo esc_js(__('Product', 'wc-multi-store-sync')); ?></th>';
            html += '<th style="width:12%"><?php echo esc_js(__('SKU', 'wc-multi-store-sync')); ?></th>';
            html += '<th><?php echo esc_js(__('Missing on remote', 'wc-multi-store-sync')); ?></th>';
            html += '<th><?php echo esc_js(__('Extra on remote', 'wc-multi-store-sync')); ?></th>';
            html += '<th style="width:90px"><?php echo esc_js(__('Action', 'wc-multi-store-sync')); ?></th>';
            html += '</tr></thead><tbody>';

            $.each(store.items, function (j, item) {
                html += '<tr>';
                html += '<td><a href="' + item.edit_link + '" target="_blank"><strong>' + item.product_name + '</strong></a></td>';
                html += '<td><code>' + item.sku + '</code></td>';
                html += '<td>' + (item.missing.length ? '<span class="wc-mss-negative">' + item.missing.join(', ') + '</span>' : '—') + '</td>';
                html += '<td>' + (item.extra.length   ? '<span class="wc-mss-positive">' + item.extra.join(', ')   + '</span>' : '—') + '</td>';
                html += '<td><button class="button button-small wc-mss-resync-btn" data-product-id="' + item.product_id + '"><?php echo esc_js(__('Re-sync', 'wc-multi-store-sync')); ?></button></td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
        });

        results.html(html);

        results.off('click', '.wc-mss-resync-btn').on('click', '.wc-mss-resync-btn', function () {
            var b   = $(this);
            var pid = b.data('product-id');
            b.prop('disabled', true).text('<?php echo esc_js(__('Queuing…', 'wc-multi-store-sync')); ?>');
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:     'wc_mss_force_sync_product',
                    nonce:      resyncNonce,
                    product_id: pid,
                }),
            })
                .then(function (res) { return res.json(); })
                .then(function (r) {
                    b.text(r.success ? '<?php echo esc_js(__('Queued ✓', 'wc-multi-store-sync')); ?>' : '<?php echo esc_js(__('Failed', 'wc-multi-store-sync')); ?>');
                });
        });
    }
});
</script>

<style>
/* All measurements in rems (1rem = 16px) */
.wc-mss-badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    border-radius: 0.625rem;
    font-size: 0.75rem;
    font-weight: bold;
    margin-left: 0.625rem;
}

.wc-mss-badge-error {
    background: #dc3232;
    color: #fff;
}

.wc-mss-positive {
    color: #46b450;
}

.wc-mss-negative {
    color: #dc3232;
}

.wc-mss-status-badge {
    display: inline-block;
    padding: 0.1875rem 0.625rem;
    border-radius: 0.1875rem;
    font-size: 0.6875rem;
    font-weight: bold;
    text-transform: uppercase;
}

.wc-mss-status-pending {
    background: #ffb900;
    color: #000;
}

.wc-mss-status-resolving {
    background: #00a0d2;
    color: #fff;
}

.wc-mss-status-resolved {
    background: #46b450;
    color: #fff;
}

.wc-mss-status-ignored {
    background: #999;
    color: #fff;
}

.button-small {
    height: 1.625rem;
    line-height: 1.5rem;
    padding: 0 0.5rem;
    font-size: 0.6875rem;
}

.wc-mss-discrepancies-filters {
    background: #fff;
    padding: 0.9375rem;
    border: 0.0625rem solid #ccd0d4;
    border-radius: 0.25rem;
}

.wc-mss-progress-bar-wrap {
    background: #e0e0e0;
    border-radius: 0.25rem;
    height: 1.125rem;
    overflow: hidden;
}

.wc-mss-progress-bar {
    background: #2271b1;
    height: 100%;
    width: 0%;
    transition: width 0.3s ease;
    border-radius: 0.25rem;
}
</style>
