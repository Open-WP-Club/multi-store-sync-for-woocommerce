<?php
/**
 * Logs View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-mss-logs">
    <h1><?php _e('Sync Logs', 'wc-multi-store-sync'); ?></h1>

    <div class="wc-mss-card">
        <h2><?php _e('Recent Activity', 'wc-multi-store-sync'); ?></h2>

        <div class="wc-mss-log-toolbar">
            <label>
                <input type="checkbox" id="wc-mss-reverse-logs">
                <?php _e('Newest first', 'wc-multi-store-sync'); ?>
            </label>
            <label>
                <input type="checkbox" id="wc-mss-auto-scroll" checked>
                <?php _e('Auto-scroll to latest', 'wc-multi-store-sync'); ?>
            </label>
            <div class="wc-mss-log-search">
                <input type="text" id="wc-mss-log-filter" placeholder="<?php esc_attr_e('Filter logs...', 'wc-multi-store-sync'); ?>">
            </div>
        </div>

        <div class="wc-mss-log-viewer" id="wc-mss-log-viewer">
            <?php if (!empty($logs)): ?>
                <pre class="wc-mss-log-content" id="wc-mss-log-content"><?php echo esc_html($logs); ?></pre>
            <?php else: ?>
                <p style="padding: 15px; color: #888;"><?php _e('No log entries found.', 'wc-multi-store-sync'); ?></p>
            <?php endif; ?>
        </div>

        <div class="wc-mss-log-actions" style="margin-top: 15px;">
            <button type="button" class="button" onclick="location.reload();">
                <?php _e('Refresh', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" class="button" id="wc-mss-scroll-bottom">
                <?php _e('Scroll to Bottom', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" class="button" id="wc-mss-scroll-top">
                <?php _e('Scroll to Top', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" class="button button-link-delete" id="wc-mss-clear-log" style="margin-left: 10px;">
                <?php _e('Clear Logs', 'wc-multi-store-sync'); ?>
            </button>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#wc-mss-clear-log').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to clear all sync logs? This cannot be undone.', 'wc-multi-store-sync')); ?>')) {
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(wcMssAdmin.ajax_url, {
                    action: 'wc_mss_clear_sync_log',
                    nonce:  wcMssAdmin.nonce
                }, function(response) {
                    if (response.success) {
                        $('#wc-mss-log-content').text('');
                        $('#wc-mss-log-viewer').html('<p style="padding: 15px; color: #888;"><?php echo esc_js(__('No log entries found.', 'wc-multi-store-sync')); ?></p>');
                    } else {
                        alert(response.data?.message || '<?php echo esc_js(__('Failed to clear logs', 'wc-multi-store-sync')); ?>');
                    }
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
    </div>

    <div class="wc-mss-card" id="wc-mss-issues-card">
        <h2><?php _e('Warnings &amp; Errors', 'wc-multi-store-sync'); ?> <span id="wc-mss-issues-count" style="font-size:14px;font-weight:normal;color:#888;"></span></h2>
        <p class="description" style="margin-bottom:10px;"><?php _e('Only [WARNING] and [ERROR] entries from the current log. This is the first place to look when something seems off.', 'wc-multi-store-sync'); ?></p>

        <div id="wc-mss-issues-viewer" style="background:#1d2327;border-radius:4px;padding:12px 16px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.6;">
            <p style="color:#888;padding:0;margin:0;" id="wc-mss-issues-empty"><?php _e('No warnings or errors found — everything looks good!', 'wc-multi-store-sync'); ?></p>
        </div>

        <div style="margin-top:12px;">
            <button type="button" class="button button-link-delete" id="wc-mss-clear-warnings-errors">
                <?php _e('Clear Warnings &amp; Errors', 'wc-multi-store-sync'); ?>
            </button>
            <span id="wc-mss-clear-warnings-result" style="margin-left:10px;font-size:13px;"></span>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var raw = $('#wc-mss-log-content').text();
        if (!raw) return;

        // Matches WooCommerce's own log line format: "{ISO8601} {LEVEL} {message} ..."
        var issueLinePattern = /^\S+\s+(WARNING|ERROR)\b/;
        var lines = raw.split('\n');
        var issues = lines.filter(function(l) {
            return issueLinePattern.test(l);
        });

        var $viewer = $('#wc-mss-issues-viewer');
        var $empty  = $('#wc-mss-issues-empty');
        var $count  = $('#wc-mss-issues-count');

        if (issues.length === 0) {
            $count.text('(0)');
            return;
        }

        $empty.remove();
        $count.text('(' + issues.length + ')');

        issues.forEach(function(line) {
            var color = line.indexOf('[ERROR]') !== -1 ? '#f87171' : '#fbbf24';
            var $div = $('<div>').css({color: color, 'word-break': 'break-all', 'padding': '1px 0'}).text(line);
            $viewer.append($div);
        });

        // Scroll to bottom by default
        $viewer.scrollTop($viewer[0].scrollHeight);

        $('#wc-mss-clear-warnings-errors').on('click', function() {
            if (!confirm('<?php echo esc_js(__('Remove all [WARNING] and [ERROR] entries from the log? INFO entries will be kept.', 'wc-multi-store-sync')); ?>')) {
                return;
            }

            var $btn    = $(this);
            var $result = $('#wc-mss-clear-warnings-result');
            $btn.prop('disabled', true);
            $result.text('');

            $.post(wcMssAdmin.ajax_url, {
                action: 'wc_mss_clear_warnings_errors',
                nonce:  wcMssAdmin.nonce
            }, function(response) {
                if (response.success) {
                    $viewer.html('<p style="color:#888;padding:0;margin:0;"><?php echo esc_js(__('No warnings or errors found — everything looks good!', 'wc-multi-store-sync')); ?></p>');
                    $count.text('(0)');
                    $result.css('color', '#2e7d32').text(response.data.message);
                } else {
                    $result.css('color', '#c62828').text(response.data?.message || '<?php echo esc_js(__('Failed to clear', 'wc-multi-store-sync')); ?>');
                }
            }).fail(function() {
                $result.css('color', '#c62828').text('<?php echo esc_js(__('Request failed', 'wc-multi-store-sync')); ?>');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    });
    </script>

    <div class="wc-mss-card">
        <h2><?php _e('Force Full Sync by SKU', 'wc-multi-store-sync'); ?></h2>
        <p class="description"><?php _e('Enter one or more product SKUs (comma-separated) to immediately queue a full sync (with images, categories, attributes, etc.) to all active stores.', 'wc-multi-store-sync'); ?></p>

        <div style="display: flex; gap: 10px; align-items: flex-start; margin-top: 15px; flex-wrap: wrap;">
            <input type="text" id="wc-mss-test-sku"
                   placeholder="<?php esc_attr_e('SKU1, SKU2, SKU3...', 'wc-multi-store-sync'); ?>"
                   style="width: 400px;"
                   class="regular-text">
            <button type="button" class="button button-primary" id="wc-mss-force-sync-btn">
                <?php _e('Force Full Sync', 'wc-multi-store-sync'); ?>
            </button>
        </div>

        <div id="wc-mss-force-sync-result" style="margin-top: 12px; display: none;"></div>

        <script>
        jQuery(document).ready(function($) {
            $('#wc-mss-force-sync-btn').on('click', function() {
                var raw = $('#wc-mss-test-sku').val().trim();
                if (!raw) {
                    $('#wc-mss-force-sync-result')
                        .show()
                        .html('<div class="notice notice-warning inline" style="margin:0;"><p><?php echo esc_js(__('Please enter at least one SKU', 'wc-multi-store-sync')); ?></p></div>');
                    return;
                }

                var skus = raw.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });

                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Queuing...', 'wc-multi-store-sync')); ?>');
                $('#wc-mss-force-sync-result').hide();

                $.post(wcMssAdmin.ajax_url, {
                    action: 'wc_mss_force_sync_by_sku',
                    skus:   skus,
                    nonce:  wcMssAdmin.nonce
                }, function(response) {
                    var html;
                    if (response.success) {
                        var lines = response.data.results.map(function(r) {
                            if (r.success) {
                                return '<li style="color:#2e7d32;">&#10003; ' + r.message + '</li>';
                            } else {
                                return '<li style="color:#c62828;">&#10007; ' + r.message + '</li>';
                            }
                        }).join('');
                        html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + response.data.message + '</strong></p>'
                             + '<ul style="margin:6px 0 4px 16px;">' + lines + '</ul>'
                             + '<p style="margin:4px 0 0;color:#666;font-size:12px;"><?php echo esc_js(__('Refresh the page in a moment to see the sync log entries appear below.', 'wc-multi-store-sync')); ?></p></div>';
                    } else {
                        html = '<div class="notice notice-error inline" style="margin:0;"><p>' + (response.data?.message || '<?php echo esc_js(__('An error occurred', 'wc-multi-store-sync')); ?>') + '</p></div>';
                    }
                    $('#wc-mss-force-sync-result').html(html).show();
                }).fail(function() {
                    $('#wc-mss-force-sync-result')
                        .html('<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js(__('Request failed', 'wc-multi-store-sync')); ?></p></div>')
                        .show();
                }).always(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Force Full Sync', 'wc-multi-store-sync')); ?>');
                });
            });

            $('#wc-mss-test-sku').on('keypress', function(e) {
                if (e.which === 13) {
                    $('#wc-mss-force-sync-btn').trigger('click');
                }
            });
        });
        </script>
    </div>

    <div class="wc-mss-card">
        <h2><?php _e('Force Full Sync by Category', 'wc-multi-store-sync'); ?></h2>
        <p class="description"><?php _e('Select a product category to immediately queue a full sync (with images, categories, attributes, etc.) for all published products in it to all active stores.', 'wc-multi-store-sync'); ?></p>

        <div style="display: flex; gap: 10px; align-items: flex-start; margin-top: 15px; flex-wrap: wrap;">
            <select id="wc-mss-category-select" style="min-width: 260px;">
                <option value=""><?php _e('— Select a category —', 'wc-multi-store-sync'); ?></option>
                <?php
                $product_cats = get_terms([
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => true,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ]);
                if (!is_wp_error($product_cats)) {
                    foreach ($product_cats as $cat) {
                        printf(
                            '<option value="%d">%s (%d)</option>',
                            esc_attr($cat->term_id),
                            esc_html($cat->name),
                            (int) $cat->count
                        );
                    }
                }
                ?>
            </select>
            <button type="button" class="button button-primary" id="wc-mss-force-sync-category-btn">
                <?php _e('Force Full Sync', 'wc-multi-store-sync'); ?>
            </button>
        </div>

        <div id="wc-mss-force-sync-category-result" style="margin-top: 12px; display: none;"></div>

        <script>
        jQuery(document).ready(function($) {
            $('#wc-mss-force-sync-category-btn').on('click', function() {
                var categoryId = $('#wc-mss-category-select').val();
                if (!categoryId) {
                    $('#wc-mss-force-sync-category-result')
                        .show()
                        .html('<div class="notice notice-warning inline" style="margin:0;"><p><?php echo esc_js(__('Please select a category', 'wc-multi-store-sync')); ?></p></div>');
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Queuing...', 'wc-multi-store-sync')); ?>');
                $('#wc-mss-force-sync-category-result').hide();

                $.post(wcMssAdmin.ajax_url, {
                    action:      'wc_mss_force_sync_by_category',
                    category_id: categoryId,
                    nonce:       wcMssAdmin.nonce
                }, function(response) {
                    var html;
                    if (response.success) {
                        html = '<div class="notice notice-success inline" style="margin:0;">'
                             + '<p>' + response.data.message + '</p>'
                             + '<p style="margin:4px 0 0;color:#666;font-size:12px;"><?php echo esc_js(__('Refresh the page in a moment to see the sync log entries appear below.', 'wc-multi-store-sync')); ?></p>'
                             + '</div>';
                    } else {
                        html = '<div class="notice notice-error inline" style="margin:0;"><p>' + (response.data?.message || '<?php echo esc_js(__('An error occurred', 'wc-multi-store-sync')); ?>') + '</p></div>';
                    }
                    $('#wc-mss-force-sync-category-result').html(html).show();
                }).fail(function() {
                    $('#wc-mss-force-sync-category-result')
                        .html('<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js(__('Request failed', 'wc-multi-store-sync')); ?></p></div>')
                        .show();
                }).always(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Force Full Sync', 'wc-multi-store-sync')); ?>');
                });
            });
        });
        </script>
    </div>

    <div class="wc-mss-card">
        <h3><?php _e('About Logs', 'wc-multi-store-sync'); ?></h3>
        <p><?php _e('This page shows the most recent 500 log entries, read from the WooCommerce log for this plugin. Retention is controlled by the "Log retention period" setting under WooCommerce → Status → Logs.', 'wc-multi-store-sync'); ?></p>
        <p><?php _e('Log entries include:', 'wc-multi-store-sync'); ?></p>
        <ul>
            <li><?php _e('Product sync operations (create, update, delete)', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('Connection attempts and errors', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('API communication issues', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('System warnings and errors', 'wc-multi-store-sync'); ?></li>
        </ul>
    </div>
</div>
