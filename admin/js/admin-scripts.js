/**
 * WooCommerce Multi-Store Sync - Admin Scripts
 * Uses WordPress admin APIs for requests and feedback.
 */

(function() {
    'use strict';

    var __ = wp.i18n.__;
    var sprintf = wp.i18n.sprintf;

    /**
     * Utility: AJAX request helper
     */
    function ajax(options) {
        return wcMssAdminUI.response(options.data).then(function (response) {
            if (options.success) options.success(response);
        }).catch(function (error) {
            if (options.error) options.error(error.message);
        }).finally(function () {
            if (options.complete) options.complete();
        });
    }

    /**
     * Utility: Serialize object to URL params
     */
    function serialize(obj) {
        var parts = [];
        for (var key in obj) {
            if (obj.hasOwnProperty(key)) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(obj[key]));
            }
        }
        return parts.join('&');
    }

    /**
     * Initialize on DOM ready
     */
    document.addEventListener('DOMContentLoaded', function() {

        /**
         * Keyboard Shortcuts (Ctrl+S to save)
         */
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();

                var submitButton = document.querySelector('.wc-mss-card form .button-primary, form.woocommerce-save-button .button-primary');

                if (submitButton) {
                    submitButton.click();

                    wcMssNotice(null, __('Saving...', 'multi-store-sync-for-woocommerce'), 'info');
                }
            }


        });

        /** Queue a full sync, with the same request and notice handling as other actions. */
        var forceSyncBtn = document.getElementById('wc-mss-force-sync-all');
        if (forceSyncBtn) {
            forceSyncBtn.addEventListener('click', function () {
                if (!confirm(wcMssAdmin.i18n.confirm_sync_all)) return;
                var originalText = forceSyncBtn.textContent;
                forceSyncBtn.disabled = true;
                forceSyncBtn.textContent = wcMssAdmin.i18n.syncing;
                wcMssRequest('wc_mss_force_sync_all', { nonce: wcMssAdmin.force_sync_nonce })
                    .then(function (data) { wcMssNotice('#wc-mss-force-sync-result', data.message, 'success'); })
                    .catch(function (error) { wcMssNotice('#wc-mss-force-sync-result', error.message, 'error'); })
                    .finally(function () {
                        forceSyncBtn.disabled = false;
                        forceSyncBtn.textContent = originalText;
                    });
            });
        }

        /**
         * Test Connection Button
         */
        var testConnectionBtn = document.getElementById('wc-mss-test-connection');
        if (testConnectionBtn) {
            testConnectionBtn.addEventListener('click', function(e) {
                e.preventDefault();

                var storeUrl = document.getElementById('store_url');
                var consumerKey = document.getElementById('consumer_key');
                var consumerSecret = document.getElementById('consumer_secret');

                if (!storeUrl || !consumerKey || !consumerSecret ||
                    !storeUrl.value || !consumerKey.value || !consumerSecret.value) {
                    wcMssNotice(null, __('Please fill in all fields before testing the connection.', 'multi-store-sync-for-woocommerce'), 'error');
                    return;
                }

                testConnectionBtn.disabled = true;
                var originalText = testConnectionBtn.textContent;
                testConnectionBtn.textContent = __('Testing...', 'multi-store-sync-for-woocommerce');

                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize({
                        action: 'wc_mss_test_connection',
                        nonce: wcMssAdmin.nonce,
                        store_url: storeUrl.value,
                        consumer_key: consumerKey.value,
                        consumer_secret: consumerSecret.value
                    }),
                    success: function(response) {
                        if (response.success) {
                            wcMssNotice(null, wcMssAdminUI.message(response.data), 'success');
                        } else {
                            wcMssNotice(null, wcMssAdminUI.message(response.data), 'error');
                        }
                    },
                    error: function(error) {
                        wcMssNotice(null, sprintf(__('Connection test failed: %s', 'multi-store-sync-for-woocommerce'), wcMssAdminUI.message(error)), 'error');
                    },
                    complete: function() {
                        testConnectionBtn.disabled = false;
                        testConnectionBtn.textContent = originalText;
                    }
                });
            });
        }

        /**
         * Sync Product Buttons
         */
        document.querySelectorAll('.wc-mss-sync-product').forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();

                var productId = this.dataset.productId;
                var syncType = this.dataset.syncType || 'full_product';

                if (!productId) {
                    wcMssNotice(null, __('Product ID not found.', 'multi-store-sync-for-woocommerce'), 'error');
                    return;
                }

                this.disabled = true;
                var originalText = this.textContent;
                this.textContent = __('Syncing...', 'multi-store-sync-for-woocommerce');
                var btn = this;

                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize({
                        action: 'wc_mss_sync_product',
                        nonce: wcMssAdmin.nonce,
                        product_id: productId,
                        sync_type: syncType
                    }),
                    success: function(response) {
                        if (response.success) {
                            wcMssNotice(null, __('Product synced successfully!', 'multi-store-sync-for-woocommerce'));
                        } else {
                            wcMssNotice(null, sprintf(__('Sync failed: %s', 'multi-store-sync-for-woocommerce'), wcMssAdminUI.message(response.data)), 'error');
                        }
                    },
                    error: function(error) {
                        wcMssNotice(null, sprintf(__('Sync failed: %s', 'multi-store-sync-for-woocommerce'), wcMssAdminUI.message(error)), 'error');
                    },
                    complete: function() {
                        btn.disabled = false;
                        btn.textContent = originalText;
                    }
                });
            });
        });

        /**
         * Health Check Button
         */
        var healthCheckBtn = document.getElementById('wc-mss-run-health-check');
        if (healthCheckBtn) {
            var healthResult = document.getElementById('wc-mss-health-check-result');

            healthCheckBtn.addEventListener('click', function(e) {
                e.preventDefault();

                var originalText = healthCheckBtn.textContent;
                healthCheckBtn.disabled = true;
                healthCheckBtn.textContent = __("Running health check...", 'multi-store-sync-for-woocommerce');
                if (healthResult) healthResult.textContent = __("Checking all stores...", 'multi-store-sync-for-woocommerce');

                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize({
                        action: 'wc_mss_run_health_check',
                        nonce: wcMssAdmin.health_check_nonce || wcMssAdmin.nonce
                    }),
                    success: function(response) {
                        if (response.success && healthResult) {
                            wcMssNotice(healthResult, response.data.message, response.data.healthy_count === response.data.total_count ? 'success' : 'warning');
                            setTimeout(function() { location.reload(); }, 2000);
                        } else if (healthResult) {
                            wcMssNotice(healthResult, wcMssAdminUI.message(response.data), 'error');
                        }
                    },
                    error: function() {
                        if (healthResult) {
                            wcMssNotice(healthResult, wcMssAdmin.i18n.request_failed, 'error');
                        }
                    },
                    complete: function() {
                        healthCheckBtn.disabled = false;
                        healthCheckBtn.textContent = originalText;
                    }
                });
            });
        }

        /**
         * Per-store "Check" buttons — run health check for a single store and
         * update the Health cell in the row without a full page reload.
         */
        document.querySelectorAll('.wc-mss-check-store-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var storeUrl = this.dataset.storeUrl;
                var originalText = this.textContent;
                this.disabled = true;
                this.textContent = '...';

                var row = this.closest('tr');
                var healthCell = row ? row.cells[2] : null;

                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize({
                        action: 'wc_mss_check_single_store',
                        nonce: wcMssAdmin.single_store_nonce || wcMssAdmin.nonce,
                        store_url: storeUrl
                    }),
                    success: function(response) {
                        if (response.success && healthCell) {
                            var r = response.data.result;
                            healthCell.textContent = r.message + '\n' + sprintf(__('Last checked: %s', 'multi-store-sync-for-woocommerce'), r.checked_at);
                        } else {
                            wcMssNotice(null, wcMssAdminUI.message(response.data), 'error');
                        }
                    },
                    complete: function() {
                        btn.disabled = false;
                        btn.textContent = originalText;
                    }
                });
            });
        });

        /**
         * "Test App Password" button — add form (fields resolved from DOM)
         */
        var testAppPwBtn = document.getElementById('wc-mss-test-app-password');
        if (testAppPwBtn) {
            testAppPwBtn.addEventListener('click', function() {
                var storeUrl  = (document.getElementById(this.dataset.storeUrlField) || {}).value || '';
                var username  = (document.getElementById(this.dataset.usernameField) || {}).value || '';
                var password  = (document.getElementById(this.dataset.passwordField) || {}).value || '';
                var resultEl  = document.getElementById('wc-mss-test-app-password-result');

                if (!storeUrl || !username || !password) {
                    if (resultEl) { wcMssNotice(resultEl, __("Fill in Store URL, username and password first.", 'multi-store-sync-for-woocommerce'), 'error'); }
                    return;
                }

                this.disabled = true;
                if (resultEl) { wcMssNotice(resultEl, __('Testing...', 'multi-store-sync-for-woocommerce'), 'info'); }

                var btn = this;
                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize({
                        action: 'wc_mss_test_app_password',
                        nonce: wcMssAdmin.test_app_password_nonce || wcMssAdmin.nonce,
                        store_url: storeUrl,
                        wp_username: username,
                        wp_app_password: password
                    }),
                    success: function(response) {
                        if (resultEl) {
                            wcMssNotice(resultEl, wcMssAdminUI.message(response.data), response.success ? 'success' : 'error');
                        }
                    },
                    error: function() {
                        if (resultEl) { wcMssNotice(resultEl, __("Request failed", 'multi-store-sync-for-woocommerce'), 'error'); }
                    },
                    complete: function() { btn.disabled = false; }
                });
            });
        }

        /**
         * "Test App Password" button — edit form (store URL comes from data attribute)
         */
        var testAppPwEditBtn = document.getElementById('wc-mss-test-app-password-edit');
        if (testAppPwEditBtn) {
            testAppPwEditBtn.addEventListener('click', function() {
                var storeUrl     = this.dataset.storeUrl;
                var username     = (document.getElementById(this.dataset.usernameField) || {}).value || this.dataset.savedUsername || '';
                var passwordEl   = document.getElementById(this.dataset.passwordField);
                var password     = passwordEl ? passwordEl.value : '';
                var hasSaved     = this.dataset.hasSavedPassword === '1';
                var resultEl     = document.getElementById('wc-mss-test-app-password-edit-result');

                if (!password && !hasSaved) {
                    if (resultEl) { wcMssNotice(resultEl, __("Enter an application password first.", 'multi-store-sync-for-woocommerce'), 'error'); }
                    return;
                }
                if (!username) {
                    if (resultEl) { wcMssNotice(resultEl, __("Enter a username first.", 'multi-store-sync-for-woocommerce'), 'error'); }
                    return;
                }

                this.disabled = true;
                if (resultEl) { wcMssNotice(resultEl, __('Testing...', 'multi-store-sync-for-woocommerce'), 'info'); }

                var btn = this;
                var postData = {
                    action: 'wc_mss_test_app_password',
                    nonce: wcMssAdmin.test_app_password_nonce || wcMssAdmin.nonce,
                    store_url: storeUrl,
                    wp_username: username,
                    wp_app_password: password
                };
                // If no new password entered, tell the server to use the saved one
                if (!password && hasSaved) {
                    postData.use_saved_password = '1';
                }

                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize(postData),
                    success: function(response) {
                        if (resultEl) {
                            wcMssNotice(resultEl, wcMssAdminUI.message(response.data), response.success ? 'success' : 'error');
                        }
                    },
                    error: function() {
                        if (resultEl) { wcMssNotice(resultEl, __("Request failed", 'multi-store-sync-for-woocommerce'), 'error'); }
                    },
                    complete: function() { btn.disabled = false; }
                });
            });
        }

        /**
         * Generate Webhook Secret
         */
        var generateSecretBtn = document.getElementById('generate-secret');
        if (generateSecretBtn) {
            generateSecretBtn.addEventListener('click', function(e) {
                e.preventDefault();

                var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                var secret = '';
                for (var i = 0; i < 32; i++) {
                    secret += chars.charAt(Math.floor(Math.random() * chars.length));
                }

                var secretInput = document.getElementById('webhook_secret');
                if (secretInput) secretInput.value = secret;
            });
        }

        /**
         * Copy Webhook URL Buttons
         */
        document.querySelectorAll('.copy-webhook-url').forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();

                var url = this.dataset.url;
                var btn = this;

                navigator.clipboard.writeText(url).then(function() {
                    var originalText = btn.textContent;
                    btn.textContent = wcMssAdmin.i18n.copied || 'Copied!';
                    setTimeout(function() {
                        btn.textContent = originalText;
                    }, 2000);
                }).catch(function() {
                    wcMssNotice(null, wcMssAdmin.i18n.copy_failed || __("Failed to copy. Please copy manually.", 'multi-store-sync-for-woocommerce'), 'error');
                });
            });
        });

        /**
         * Webhook Logs Viewer
         */
        var viewWebhookLogsBtn = document.getElementById('view-webhook-logs');
        var exportWebhookLogsBtn = document.getElementById('export-webhook-logs');

        if (viewWebhookLogsBtn) {
            viewWebhookLogsBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openWebhookLogsModal();
            });
        }

        if (exportWebhookLogsBtn) {
            exportWebhookLogsBtn.addEventListener('click', function(e) {
                e.preventDefault();
                exportWebhookLogs();
            });
        }

        function openWebhookLogsModal() {
            // Create modal if it doesn't exist
            var modal = document.getElementById('wc-mss-webhook-logs-modal');
            if (!modal) {
                modal = createWebhookLogsModal();
                document.body.appendChild(modal);
                jQuery(modal).dialog({
                    autoOpen: false, modal: true, dialogClass: 'wp-dialog',
                    title: __('Webhook Logs', 'multi-store-sync-for-woocommerce'),
                    width: Math.min(window.innerWidth - 40, 1200),
                    maxHeight: window.innerHeight - 60
                });
            }
            jQuery(modal).dialog('open');
            loadWebhookLogs(1);
        }

        function createWebhookLogsModal() {
            var modal = document.createElement('div');
            modal.id = 'wc-mss-webhook-logs-modal';
            modal.innerHTML = '<div id="wc-mss-webhook-notices"></div>' +
                    '<div class="wc-mss-webhook-filters">' +
                        '<select id="webhook-log-type-filter" class="regular-text">' +
                            "<option value=\"\">" + escapeHtml(__("All types", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"order_received\">" + escapeHtml(__("Order received", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"stock_deducted\">" + escapeHtml(__("Stock deducted", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"stock_synced\">" + escapeHtml(__("Stock synced", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"auth_failed\">" + escapeHtml(__("Authentication failed", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"validation_error\">" + escapeHtml(__("Validation error", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"product_not_found\">" + escapeHtml(__("Product not found", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"rate_limited\">" + escapeHtml(__("Rate limited", 'multi-store-sync-for-woocommerce')) + "</option>" +
                        '</select>' +
                        '<select id="webhook-log-status-filter" class="regular-text">' +
                            "<option value=\"\">" + escapeHtml(__("All statuses", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"success\">" + escapeHtml(__("Success", 'multi-store-sync-for-woocommerce')) + "</option>" +
                            "<option value=\"failed\">" + escapeHtml(__("Error", 'multi-store-sync-for-woocommerce')) + "</option>" +
                        '</select>' +
                        '<input type="text" id="webhook-log-sku-filter" placeholder="SKU..." class="regular-text" />' +
                        '<input type="date" id="webhook-log-date-from" class="regular-text" />' +
                        '<input type="date" id="webhook-log-date-to" class="regular-text" />' +
                        "<button type=\"button\" id=\"apply-webhook-filters\" class=\"button\">" + escapeHtml(__("Filter", 'multi-store-sync-for-woocommerce')) + "</button>" +
                    '</div>' +
                    '<div id="webhook-logs-stats"></div>' +
                    '<div class="wc-mss-delete-actions">' +
                        "<strong>" + escapeHtml(__("Delete:", 'multi-store-sync-for-woocommerce')) + "</strong> " +
                        "<button type=\"button\" class=\"button delete-webhook-logs\" data-type=\"errors\">" + escapeHtml(__("Errors only", 'multi-store-sync-for-woocommerce')) + "</button> " +
                        "<button type=\"button\" class=\"button delete-webhook-logs\" data-type=\"success\">" + escapeHtml(__("Successful only", 'multi-store-sync-for-woocommerce')) + "</button> " +
                        "<button type=\"button\" class=\"button delete-webhook-logs\" data-type=\"older_than\" data-days=\"30\">" + escapeHtml(__("Older than 30 days", 'multi-store-sync-for-woocommerce')) + "</button> " +
                        "<button type=\"button\" class=\"button delete-webhook-logs\" data-type=\"older_than\" data-days=\"7\">" + escapeHtml(__("Older than 7 days", 'multi-store-sync-for-woocommerce')) + "</button> " +
                        "<button type=\"button\" class=\"button button-link-delete delete-webhook-logs\" data-type=\"all\">" + escapeHtml(__("Delete all", 'multi-store-sync-for-woocommerce')) + "</button>" +
                    '</div>' +
                    '<div id="webhook-logs-container"></div>' +
                    '<div id="webhook-logs-pagination" class="tablenav"></div>';

            // Filter button handler
            modal.querySelector('#apply-webhook-filters').addEventListener('click', function() {
                loadWebhookLogs(1);
            });

            // Delete buttons handlers
            modal.querySelectorAll('.delete-webhook-logs').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var deleteType = this.dataset.type;
                    var days = this.dataset.days;

                    var confirmations = {
                        all: __('Delete all webhook logs?', 'multi-store-sync-for-woocommerce'),
                        errors: __('Delete all failed webhook logs?', 'multi-store-sync-for-woocommerce'),
                        success: __('Delete all successful webhook logs?', 'multi-store-sync-for-woocommerce'),
                        older_than: sprintf(__('Delete webhook logs older than %d days?', 'multi-store-sync-for-woocommerce'), days)
                    };
                    var confirmMsg = confirmations[deleteType];

                    if (!confirm(confirmMsg)) {
                        return;
                    }

                    deleteWebhookLogs(deleteType, days);
                });
            });

            return modal;
        }

        function loadWebhookLogs(page) {
            var body = document.getElementById('webhook-logs-container');
            var pagination = document.getElementById('webhook-logs-pagination');
            var stats = document.getElementById('webhook-logs-stats');

            if (!body) return;

            body.textContent = __('Loading...', 'multi-store-sync-for-woocommerce');

            var filters = {
                action: 'wc_mss_get_webhook_logs',
                nonce: wcMssAdmin.nonce,
                page: page,
                per_page: 50,
                log_type: document.getElementById('webhook-log-type-filter')?.value || '',
                status: document.getElementById('webhook-log-status-filter')?.value || '',
                product_sku: document.getElementById('webhook-log-sku-filter')?.value || '',
                date_from: document.getElementById('webhook-log-date-from')?.value || '',
                date_to: document.getElementById('webhook-log-date-to')?.value || ''
            };

            ajax({
                url: wcMssAdmin.ajax_url,
                data: serialize(filters),
                success: function(response) {
                    if (response.success) {
                        body.innerHTML = response.data.table_html;
                        body.querySelectorAll('.view-webhook-detail').forEach(function (button) {
                            button.addEventListener('click', function () { showWebhookLogDetail(this.dataset.id); });
                        });
                        renderWebhookPagination(response.data, pagination, page);
                        loadWebhookStats(stats);
                    } else {
                        wcMssNotice(body, wcMssAdmin.i18n.request_failed, 'error');
                    }
                },
                error: function() {
                    wcMssNotice(body, wcMssAdmin.i18n.request_failed, 'error');
                }
            });
        }

        function renderWebhookPagination(data, container, currentPage) {
            if (data.pages <= 1) {
                container.innerHTML = '';
                return;
            }

            var html = '<span>' + escapeHtml(sprintf(__('Page %1$d of %2$d (%3$d records)', 'multi-store-sync-for-woocommerce'), data.page, data.pages, data.total)) + '</span> ';

            if (currentPage > 1) {
                html += '<button type="button" class="button" data-page="' + (currentPage - 1) + "\">" + escapeHtml(__("Previous", 'multi-store-sync-for-woocommerce')) + "</button> ";
            }
            if (currentPage < data.pages) {
                html += '<button type="button" class="button" data-page="' + (currentPage + 1) + "\">" + escapeHtml(__("Next", 'multi-store-sync-for-woocommerce')) + "</button>";
            }

            container.innerHTML = html;

            container.querySelectorAll('button[data-page]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    loadWebhookLogs(parseInt(this.dataset.page));
                });
            });
        }

        function loadWebhookStats(container) {
            ajax({
                url: wcMssAdmin.ajax_url,
                data: serialize({
                    action: 'wc_mss_get_webhook_stats',
                    nonce: wcMssAdmin.nonce,
                    days: 30
                }),
                success: function(response) {
                    if (response.success) {
                        var stats = response.data;
                        var total = (stats.type_counts.order_received || 0) + (stats.type_counts.stock_deducted || 0);
                        var failed = stats.status_counts.failed || 0;
                        var stockChanges = stats.stock_stats?.total_quantity_changed || 0;

                        container.textContent = sprintf(
                            __('Last 30 days: %1$d events | Stock changes: %2$d | Units changed: %3$d | Errors: %4$d', 'multi-store-sync-for-woocommerce'),
                            total, stats.type_counts.stock_deducted || 0, stockChanges, failed
                        );
                    }
                }
            });
        }

        function showWebhookLogDetail(logId) {
            ajax({
                url: wcMssAdmin.ajax_url,
                data: serialize({
                    action: 'wc_mss_get_webhook_log_detail',
                    nonce: wcMssAdmin.nonce,
                    log_id: logId
                }),
                success: function(response) {
                    if (response.success) {
                        var log = response.data;
                        var details = [
                            ['ID', log.id],
                            [__('Date', 'multi-store-sync-for-woocommerce'), log.created_at],
                            [__('Type', 'multi-store-sync-for-woocommerce'), log.type_label],
                            [__('Status', 'multi-store-sync-for-woocommerce'), log.status],
                            [__('Store', 'multi-store-sync-for-woocommerce'), log.store_url],
                            [__('Order', 'multi-store-sync-for-woocommerce'), log.remote_order_id],
                            [__('Product ID', 'multi-store-sync-for-woocommerce'), log.product_id],
                            ['SKU', log.product_sku],
                            [__('Previous stock', 'multi-store-sync-for-woocommerce'), log.old_stock],
                            [__('Current stock', 'multi-store-sync-for-woocommerce'), log.new_stock],
                            [__('Change', 'multi-store-sync-for-woocommerce'), log.quantity_changed],
                            ['IP', log.request_ip],
                            [__('Reason', 'multi-store-sync-for-woocommerce'), log.change_reason]
                        ].map(function (entry) { return entry[0] + ': ' + (entry[1] == null ? '—' : entry[1]); }).join('\n');
                        if (log.error_message) details += '\n' + log.error_message;
                        if (log.request_data) details += '\n' + JSON.stringify(log.request_data, null, 2);
                        wcMssNotice('#wc-mss-webhook-notices', details, 'info');
                    }
                }
            });
        }

        function exportWebhookLogs() {
            var filters = {
                action: 'wc_mss_export_webhook_logs',
                nonce: wcMssAdmin.nonce,
                log_type: document.getElementById('webhook-log-type-filter')?.value || '',
                date_from: document.getElementById('webhook-log-date-from')?.value || '',
                date_to: document.getElementById('webhook-log-date-to')?.value || ''
            };

            ajax({
                url: wcMssAdmin.ajax_url,
                data: serialize(filters),
                success: function(response) {
                    if (response.success && response.data.csv_content) {
                        var csvData = atob(response.data.csv_content);
                        var blob = new Blob([csvData], { type: 'text/csv;charset=utf-8;' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename || 'webhook-logs.csv';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    } else {
                        wcMssNotice('#wc-mss-webhook-notices', __('Export failed', 'multi-store-sync-for-woocommerce'), 'error');
                    }
                },
                error: function() {
                    wcMssNotice('#wc-mss-webhook-notices', wcMssAdmin.i18n.request_failed, 'error');
                }
            });
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function deleteWebhookLogs(deleteType, days) {
            var data = {
                action: 'wc_mss_delete_webhook_logs',
                nonce: wcMssAdmin.nonce,
                delete_type: deleteType
            };

            if (days) {
                data.days = days;
            }

            ajax({
                url: wcMssAdmin.ajax_url,
                data: serialize(data),
                success: function(response) {
                    if (response.success) {
                        wcMssNotice(null, response.data.message + "\n" + __("Remaining records: ", 'multi-store-sync-for-woocommerce') + response.data.remaining);
                        loadWebhookLogs(1);
                    } else {
                        wcMssNotice(null, __("Error", 'multi-store-sync-for-woocommerce') + ": " + (response.data.message || __("Unknown error", 'multi-store-sync-for-woocommerce')));
                    }
                },
                error: function() {
                    wcMssNotice('#wc-mss-webhook-notices', wcMssAdmin.i18n.request_failed, 'error');
                }
            });
        }

        /**
         * Refresh Queue Button
         */
        var refreshQueueBtn = document.getElementById('wc-mss-refresh-queue');
        if (refreshQueueBtn) {
            refreshQueueBtn.addEventListener('click', function() {
                location.reload();
            });
        }

        /**
         * Queue Status Filter
         */
        var queueStatusFilter = document.getElementById('queue_status');
        if (queueStatusFilter) {
            var queueFilterBar = document.getElementById('wc-mss-queue-filter');
            var queueFilterBaseUrl = queueFilterBar ? queueFilterBar.dataset.baseUrl : window.location.href;

            queueStatusFilter.addEventListener('change', function() {
                var status = queueStatusFilter.value;
                var url = queueFilterBaseUrl;
                if (status && status !== 'all') {
                    url += '&queue_status=' + encodeURIComponent(status);
                }
                wcMssSafeNavigate(url);
            });
        }

        /**
         * Dead Letter Queue Actions
         */
        function wcMssDlqAction(action, data, callback) {
            data.action = action;
            data.nonce = wcMssAdmin.nonce;
            wcMssAdminUI.response(new URLSearchParams(data).toString())
            .then(function(result) {
                if (result.success) {
                    callback(true, result.data.message);
                } else {
                    callback(false, result.data.message || 'Error');
                }
            })
            .catch(function(err) {
                callback(false, err.message);
            });
        }

        var dlqRetryAllBtn = document.getElementById('wc-mss-dlq-retry-all');
        if (dlqRetryAllBtn) {
            dlqRetryAllBtn.addEventListener('click', function() {
                if (!confirm(dlqRetryAllBtn.dataset.confirm)) return;
                this.disabled = true;
                wcMssDlqAction('wc_mss_dlq_retry_all', {}, function(ok, msg) {
                    wcMssNotice(null, msg, ok ? 'success' : 'error');
                    if (ok) location.reload();
                });
            });
        }

        var dlqClearAllBtn = document.getElementById('wc-mss-dlq-clear-all');
        if (dlqClearAllBtn) {
            dlqClearAllBtn.addEventListener('click', function() {
                if (!confirm(dlqClearAllBtn.dataset.confirm)) return;
                this.disabled = true;
                wcMssDlqAction('wc_mss_dlq_clear_all', {}, function(ok, msg) {
                    wcMssNotice(null, msg, ok ? 'success' : 'error');
                    if (ok) location.reload();
                });
            });
        }

        /**
         * Config Export / Import
         */
        var configExportBtn = document.getElementById('wc-mss-export-btn');
        if (configExportBtn) {
            var configExportLabel = configExportBtn.textContent;

            configExportBtn.addEventListener('click', function() {
                var btn = this;
                btn.disabled = true;
                btn.textContent = btn.dataset.labelLoading;

                var includeKeys = document.getElementById('wc-mss-export-include-keys').checked;

                wcMssAdminUI.response('action=wc_mss_export_config&nonce=' + wcMssAdmin.nonce + '&include_keys=' + (includeKeys ? '1' : '0'))
                .then(function(data) {
                    if (data.success) {
                        // Download as file
                        var blob = new Blob([JSON.stringify(data.data.config, null, 2)], {type: 'application/json'});
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = data.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        wcMssNotice('#wc-mss-export-result', btn.dataset.successMessage, 'success');
                    } else {
                        wcMssNotice('#wc-mss-export-result', (data.data.message || 'Error'), 'error');
                    }
                    btn.disabled = false;
                    btn.textContent = configExportLabel;
                });
            });
        }

        var configImportFile = document.getElementById('wc-mss-import-file');
        var configImportBtn = document.getElementById('wc-mss-import-btn');
        if (configImportFile && configImportBtn) {
            var configImportLabel = configImportBtn.textContent;

            configImportFile.addEventListener('change', function() {
                configImportBtn.disabled = !this.files.length;
            });

            configImportBtn.addEventListener('click', function() {
                if (!confirm(configImportBtn.dataset.confirm)) return;

                var btn = this;
                var file = configImportFile.files[0];
                if (!file) return;

                btn.disabled = true;
                btn.textContent = btn.dataset.labelLoading;

                var reader = new FileReader();
                reader.onload = function(e) {
                    wcMssAdminUI.response('action=wc_mss_import_config&nonce=' + wcMssAdmin.nonce + '&config=' + encodeURIComponent(e.target.result))
                    .then(function(data) {
                        if (data.success) {
                            wcMssNotice('#wc-mss-import-result', data.data.message, 'success');
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            wcMssNotice('#wc-mss-import-result', (data.data.message || 'Error'), 'error');
                        }
                        btn.disabled = false;
                        btn.textContent = configImportLabel;
                    });
                };
                reader.onerror = function () {
                    wcMssNotice('#wc-mss-import-result', wcMssAdmin.i18n.request_failed, 'error');
                    btn.disabled = false;
                    btn.textContent = configImportLabel;
                };
                reader.readAsText(file);
            });
        }

        /**
         * Settings - Scheduled Sync Visibility Toggle
         */
        if (document.getElementById('scheduled_sync_enabled') && typeof jQuery !== 'undefined') {
            jQuery(function ($) {
                function updateScheduledSyncVisibility() {
                    var enabled = $('#scheduled_sync_enabled').is(':checked');
                    var allProducts = $('input[name="sync_all_products"]:checked').val() === '1';

                    if (enabled) {
                        $('.scheduled-sync-option').show();
                        if (allProducts) {
                            $('#sync_modified_hours_row').hide();
                        } else {
                            $('#sync_modified_hours_row').show();
                        }
                    } else {
                        $('.scheduled-sync-option').hide();
                    }
                }

                $('#scheduled_sync_enabled').on('change', updateScheduledSyncVisibility);
                $('input[name="sync_all_products"]').on('change', updateScheduledSyncVisibility);
            });
        }

        /**
         * Settings - Sync by Category
         */
        if (document.getElementById('wc-mss-cat-sync-btn') && typeof jQuery !== 'undefined') {
            jQuery(function ($) {
                $('#wc-mss-cat-sync-btn').on('click', function () {
                    var $btn      = $(this);
                    var catId     = $('#wc-mss-cat-sync-category').val();
                    var syncType  = $('#wc-mss-cat-sync-type').val();
                    var children  = $('#wc-mss-cat-sync-children').is(':checked') ? 1 : 0;
                    var $result   = $('#wc-mss-cat-sync-result');

                    if (!catId) {
                        wcMssNotice($result, $btn.data('msg-select-category'), 'warning');
                        return;
                    }

                    $btn.prop('disabled', true);
                    wcMssNotice($result, $btn.data('msg-queuing'), 'info');

                    wcMssAdminUI.response(new URLSearchParams({
                            action:           'wc_mss_sync_by_category',
                            nonce:            wcMssAdmin.nonce,
                            category_id:      catId,
                            sync_type:        syncType,
                            include_children: children,
                        }))
                        .then(function (resp) {
                            $btn.prop('disabled', false);
                            if (resp.success) {
                                wcMssNotice($result, resp.data.message, 'success');
                            } else {
                                wcMssNotice($result, resp.data.message || $btn.data('msg-error'), 'error');
                            }
                        })
                        .catch(function () {
                            $btn.prop('disabled', false);
                            wcMssNotice($result, wcMssAdmin.i18n.request_failed, 'error');
                        });
                });
            });
        }

        /**
         * Settings - Feature Toggle Checkboxes
         */
        if (document.querySelector('.wc-mss-feature-toggle') && typeof jQuery !== 'undefined') {
            jQuery(function ($) {
                $('.wc-mss-feature-toggle').on('change', function() {
                    var $checkbox = $(this);
                    var action = $checkbox.data('action');
                    var enabled = $checkbox.is(':checked') ? 1 : 0;

                    $checkbox.prop('disabled', true);

                    wcMssAdminUI.response(new URLSearchParams({
                            action: action,
                            enabled: enabled,
                            nonce: wcMssAdmin.nonce
                        }))
                        .then(function (response) {
                            $checkbox.prop('disabled', false);
                            if (response.success) {
                                wcMssNotice(null, wcMssAdminUI.message(response.data), 'success');

                                // These specific toggles show/hide their own settings tab
                                // (see get_sections() in wc-settings-integration.php) —
                                // reload so the tab list matches the new state right away
                                // instead of only after a manual refresh.
                                var tabTogglingActions = [
                                    'wc_mss_toggle_category_mapper',
                                    'wc_mss_toggle_attribute_remapping',
                                    'wc_mss_toggle_conflict_detection'
                                ];
                                if (tabTogglingActions.indexOf(action) !== -1) {
                                    setTimeout(function() { location.reload(); }, 600);
                                }
                            } else {
                                // Revert on failure
                                $checkbox.prop('checked', !enabled);
                                wcMssNotice(null, (response.data && response.data.message) || wcMssAdmin.i18n.failed_to_update, 'error');
                            }
                        })
                        .catch(function () {
                            $checkbox.prop('disabled', false);
                            $checkbox.prop('checked', !enabled);
                            wcMssNotice(null, wcMssAdmin.i18n.request_failed, 'error');
                        });
                });
            });
        }

        /**
         * Sync Profiles Page
         */
        if (document.querySelector('.wc-mss-sync-profiles') && typeof jQuery !== 'undefined' && typeof wcMssSyncProfilesData !== 'undefined') {
            (function($) {
                var i18n = wcMssSyncProfilesData.i18n;

                function showNotice(message, type) {
                    wcMssNotice('#wc-mss-profiles-notices', message, type);
                }

                // Save current settings as profile
                $('#wc-mss-save-profile-btn').on('click', function() {
                    var name = $('#wc-mss-profile-name').val().trim();
                    if (!name) {
                        showNotice(i18n.enter_name, 'error');
                        return;
                    }

                    var $btn = $(this).prop('disabled', true).text(i18n.saving);

                    wcMssAdminUI.response(new URLSearchParams({
                            action: 'wc_mss_profile_save',
                            nonce: wcMssAdmin.nonce,
                            name: name,
                            description: $('#wc-mss-profile-description').val()
                        }))
                        .then(function (response) {
                            if (response.success) {
                                showNotice(response.data.message);
                                setTimeout(function() { location.reload(); }, 1000);
                            } else {
                                showNotice(response.data.message || i18n.error_saving, 'error');
                                $btn.prop('disabled', false).text(i18n.save_profile);
                            }
                        });
                });

                // Apply preset
                $(document).on('click', '.wc-mss-apply-preset', function() {
                    var presetKey = $(this).data('preset');
                    var presetName = $(this).data('name');

                    if (!confirm(i18n.confirm_apply_preset.replace('%s', presetName))) {
                        return;
                    }

                    var $btn = $(this).prop('disabled', true);

                    wcMssAdminUI.response(new URLSearchParams({
                            action: 'wc_mss_profile_apply',
                            nonce: wcMssAdmin.nonce,
                            preset_key: presetKey
                        }))
                        .then(function (response) {
                            if (response.success) {
                                showNotice(response.data.message);
                            } else {
                                showNotice(response.data.message || i18n.error_applying_preset, 'error');
                            }
                            $btn.prop('disabled', false);
                        });
                });

                // Apply saved profile
                $(document).on('click', '.wc-mss-apply-profile', function() {
                    var profileId = $(this).data('id');
                    var profileName = $(this).data('name');

                    if (!confirm(i18n.confirm_apply_profile.replace('%s', profileName))) {
                        return;
                    }

                    var $btn = $(this).prop('disabled', true);

                    wcMssAdminUI.response(new URLSearchParams({
                            action: 'wc_mss_profile_apply',
                            nonce: wcMssAdmin.nonce,
                            profile_id: profileId
                        }))
                        .then(function (response) {
                            if (response.success) {
                                showNotice(response.data.message);
                            } else {
                                showNotice(response.data.message || i18n.error_applying_profile, 'error');
                            }
                            $btn.prop('disabled', false);
                        });
                });

                // Export profile
                $(document).on('click', '.wc-mss-export-profile', function() {
                    var profileId = $(this).data('id');
                    // Trigger a download via a hidden form (avoids pop-up blockers)
                    var url = ajaxurl + '?action=wc_mss_export_config&nonce=' + wcMssAdmin.nonce + '&profile_id=' + encodeURIComponent(profileId);
                    window.location.href = url;
                });

                // Delete profile
                $(document).on('click', '.wc-mss-delete-profile', function() {
                    var profileId = $(this).data('id');
                    var profileName = $(this).data('name');

                    if (!confirm(i18n.confirm_delete_profile.replace('%s', profileName))) {
                        return;
                    }

                    var $row = $('#wc-mss-profile-row-' + profileId);
                    var $btn = $(this).prop('disabled', true);

                    wcMssAdminUI.response(new URLSearchParams({
                            action: 'wc_mss_profile_delete',
                            nonce: wcMssAdmin.nonce,
                            profile_id: profileId
                        }))
                        .then(function (response) {
                            if (response.success) {
                                $row.fadeOut(300, function() { $(this).remove(); });
                                showNotice(response.data.message);
                            } else {
                                showNotice(response.data.message || i18n.error_deleting_profile, 'error');
                                $btn.prop('disabled', false);
                            }
                        });
                });
            })(jQuery);
        }

        /**
         * Discrepancies Page - Category Scan
         */
        if (document.getElementById('wc-mss-scan-categories-btn') && typeof jQuery !== 'undefined' && typeof wcMssDiscrepanciesData !== 'undefined') {
            jQuery(function ($) {
                var i18n     = wcMssDiscrepanciesData.i18n;
                var btn      = $('#wc-mss-scan-categories-btn');
                var progress = $('#wc-mss-cat-progress');
                var bar      = $('#wc-mss-cat-bar');
                var label    = $('#wc-mss-cat-progress-label');
                var results  = $('#wc-mss-cat-results');

                // Same store list already rendered into the filter <select> —
                // reading it here avoids needing a separate PHP->JS data payload.
                var allStores = Array.prototype.slice.call(document.querySelectorAll('#wc-mss-cat-store-select option'))
                    .filter(function (opt) { return opt.value !== ''; })
                    .map(function (opt) { return { url: opt.value, name: opt.textContent.trim() }; });

                btn.on('click', function () {
                    var selectedUrl = $('#wc-mss-cat-store-select').val();

                    // Determine which stores to process
                    var storesToScan = selectedUrl
                        ? allStores.filter(function (s) { return s.url === selectedUrl; })
                        : allStores;

                    if (!storesToScan.length) {
                        wcMssNotice(results, i18n.no_active_stores, 'warning');
                        return;
                    }

                    btn.prop('disabled', true);
                    results.html('');
                    progress.show();
                    bar.val(0);
                    label.text(i18n.starting_scan);

                    var allMismatches = [];
                    var index = 0;

                    function scanNext() {
                        if (index >= storesToScan.length) {
                            // Done
                            btn.prop('disabled', false);
                            bar.val(100);
                            label.text(i18n.scan_complete);
                            renderResults(allMismatches);
                            return;
                        }

                        var store = storesToScan[index];
                        var pct   = Math.round((index / storesToScan.length) * 100);
                        bar.val(pct);
                        label.text(i18n.scanning + ' ' + store.name + ' (' + (index + 1) + '/' + storesToScan.length + ')');

                        wcMssAdminUI.response(new URLSearchParams({
                                action:    'wc_mss_scan_categories',
                                nonce:     wcMssAdmin.scan_categories_nonce,
                                store_url: store.url,
                            }))
                            .then(function (resp) {
                                if (resp.success && resp.data.count > 0) {
                                    allMismatches.push(resp.data);
                                }
                                if (!resp.success) {
                                    allMismatches.push({
                                        store_name: store.name,
                                        store_url:  store.url,
                                        error:      resp.data.message || i18n.unknown_error,
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
                                    error:      i18n.request_failed,
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
                        wcMssNotice(results, i18n.all_match, 'success');
                        return;
                    }

                    var html = '';
                    $.each(list, function (i, store) {
                        if (store.error) {
                            html += '<div class="notice notice-error inline"><p><strong>' + escapeHtml(store.store_name) + ':</strong> ' + escapeHtml(store.error) + '</p></div>';
                            return;
                        }
                        if (!store.count) {
                            return;
                        }

                        html += '<h3>' + escapeHtml(store.store_name) + ' — ' + store.count + ' ' + i18n.mismatch_count_label;
                        html += ' <small>(' + store.total_local + ' ' + i18n.local + ' / ' + store.total_remote + ' ' + i18n.remote + ')</small></h3>';
                        html += store.table_html;
                    });

                    results.html(html);


                }
            });
        }

        /**
         * Category Mapping Page - per-store category/tag mapping tables
         */
        if (document.getElementById('wc-mss-map-store-select') && typeof jQuery !== 'undefined' && typeof wcMssCategoryMappingData !== 'undefined') {
            jQuery(function ($) {
                var i18n = wcMssCategoryMappingData.i18n;

                var storeSelect = $('#wc-mss-map-store-select');
                var loading     = $('#wc-mss-map-loading');
                var tablesWrap  = $('#wc-mss-map-tables');
                var emptyWrap   = $('#wc-mss-map-empty');
                var catBody     = $('#wc-mss-map-category-table tbody');
                var tagBody     = $('#wc-mss-map-tag-table tbody');

                function ajaxPost(action, extraParams) {
                    return wcMssAdminUI.response(Object.assign({ action: action }, extraParams));
                }

                function buildRemoteOptions(remoteTerms, currentValue) {
                    var html = '<option value=""' + (currentValue === undefined || currentValue === '' ? ' selected' : '') + '>' + i18n.no_mapping + '</option>';
                    html += '<option value="__skip__"' + (currentValue === '__skip__' ? ' selected' : '') + '>' + i18n.skip + '</option>';
                    remoteTerms.forEach(function (term) {
                        var sel = currentValue === term.slug ? ' selected' : '';
                        html += '<option value="' + escapeHtml(term.slug) + '"' + sel + '>' + escapeHtml(term.name) + '</option>';
                    });
                    return html;
                }

                function buildRows(localTerms, mappings, remoteTerms) {
                    var html = '';
                    localTerms.forEach(function (term) {
                        var current = mappings[term.slug];
                        html += '<tr>'
                            + '<td>' + escapeHtml(term.name) + '</td>'
                            + '<td><select class="wc-mss-map-remote-select" data-local-slug="' + escapeHtml(term.slug) + '">'
                            + buildRemoteOptions(remoteTerms, current)
                            + '</select></td>'
                            + '</tr>';
                    });
                    return html;
                }

                function saveMappings(button, mappingType, tbody) {
                    var storeUrl = storeSelect.val();
                    var statusEl = $('.wc-mss-map-save-status[data-for="' + (mappingType === 'tag' ? 'tags' : 'categories') + '"]');
                    var params = { store_url: storeUrl, mapping_type: mappingType };

                    tbody.find('select.wc-mss-map-remote-select').each(function () {
                        var slug = $(this).data('local-slug');
                        var val  = $(this).val();
                        if (val !== '') {
                            params['mappings[' + slug + ']'] = val;
                        }
                    });

                    button.prop('disabled', true);
                    statusEl.removeClass('success error').text(i18n.saving);

                    ajaxPost('wc_mss_save_category_mappings', params)
                        .then(function (resp) {
                            button.prop('disabled', false);
                            if (resp.success) {
                                wcMssNotice(statusEl, i18n.saved, 'success');
                            } else {
                                wcMssNotice(statusEl, (resp.data && resp.data.message) || i18n.save_failed, 'error');
                            }
                        })
                        .catch(function () {
                            button.prop('disabled', false);
                            wcMssNotice(statusEl, i18n.save_failed, 'error');
                        });
                }

                storeSelect.on('change', function () {
                    var storeUrl = $(this).val();

                    tablesWrap.hide();
                    emptyWrap.hide();
                    catBody.empty();
                    tagBody.empty();

                    if (!storeUrl) {
                        return;
                    }

                    loading.show();

                    Promise.all([
                        ajaxPost('wc_mss_get_category_mappings', { store_url: storeUrl }),
                        ajaxPost('wc_mss_get_remote_terms', { store_url: storeUrl, taxonomy: 'category' }),
                        ajaxPost('wc_mss_get_remote_terms', { store_url: storeUrl, taxonomy: 'tag' }),
                    ]).then(function (results) {
                        loading.hide();

                        var mappingsResp = results[0];
                        if (!mappingsResp.success) {
                            emptyWrap.text((mappingsResp.data && mappingsResp.data.message) || i18n.load_failed).show();
                            return;
                        }

                        var data = mappingsResp.data;
                        var remoteCategories = results[1].success ? results[1].data.terms : [];
                        var remoteTags = results[2].success ? results[2].data.terms : [];

                        if (!data.local_categories.length && !data.local_tags.length) {
                            emptyWrap.show();
                            return;
                        }

                        catBody.html(buildRows(data.local_categories, data.category_mappings, remoteCategories));
                        tagBody.html(buildRows(data.local_tags, data.tag_mappings, remoteTags));
                        tablesWrap.show();
                    }).catch(function () {
                        loading.hide();
                        emptyWrap.text(i18n.load_failed).show();
                    });
                });

                $('#wc-mss-map-save-categories').on('click', function () {
                    saveMappings($(this), 'category', catBody);
                });
                $('#wc-mss-map-save-tags').on('click', function () {
                    saveMappings($(this), 'tag', tagBody);
                });
            });
        }

        /**
         * Attribute Mapping Page - per-store attribute name/value mapping
         */
        if (document.getElementById('wc-mss-attr-map-store-select') && typeof jQuery !== 'undefined' && typeof wcMssAttributeMappingData !== 'undefined') {
            jQuery(function ($) {
                var i18n = wcMssAttributeMappingData.i18n;

                var storeSelect   = $('#wc-mss-attr-map-store-select');
                var loading       = $('#wc-mss-attr-map-loading');
                var tablesWrap    = $('#wc-mss-attr-map-tables');
                var emptyWrap     = $('#wc-mss-attr-map-empty');
                var nameBody      = $('#wc-mss-attr-map-name-table tbody');
                var valueGroups   = $('#wc-mss-attr-map-value-groups');

                function ajaxPost(action, extraParams) {
                    return wcMssAdminUI.response(Object.assign({ action: action }, extraParams));
                }

                function buildRemoteNameOptions(remoteAttributes, currentValue) {
                    var html = '<option value=""' + (currentValue === undefined || currentValue === '' ? ' selected' : '') + '>' + i18n.no_mapping + '</option>';
                    html += '<option value="__skip__"' + (currentValue === '__skip__' ? ' selected' : '') + '>' + i18n.skip + '</option>';
                    remoteAttributes.forEach(function (attr) {
                        var sel = currentValue === attr.name ? ' selected' : '';
                        html += '<option value="' + escapeHtml(attr.name) + '"' + sel + '>' + escapeHtml(attr.name) + '</option>';
                    });
                    return html;
                }

                function buildNameRows(localAttributes, nameMappings, remoteAttributes) {
                    var html = '';
                    localAttributes.forEach(function (attr) {
                        var current = nameMappings[attr.name];
                        html += '<tr>'
                            + '<td>' + escapeHtml(attr.name) + '</td>'
                            + '<td><select class="wc-mss-attr-map-remote-select" data-local-name="' + escapeHtml(attr.name) + '">'
                            + buildRemoteNameOptions(remoteAttributes, current)
                            + '</select></td>'
                            + '</tr>';
                    });
                    return html;
                }

                function buildValueGroups(localAttributes, valueMappingsByAttr) {
                    var html = '';
                    localAttributes.forEach(function (attr) {
                        if (!attr.values || !attr.values.length) {
                            return;
                        }
                        var mappings = valueMappingsByAttr[attr.name] || {};
                        var rows = attr.values.map(function (value) {
                            var current = mappings[value] || '';
                            return '<tr>'
                                + '<td>' + escapeHtml(value) + '</td>'
                                + '<td><input type="text" class="wc-mss-attr-map-value-input" data-local-value="' + escapeHtml(value) + '" value="' + escapeHtml(current) + '"></td>'
                                + '</tr>';
                        }).join('');

                        html += '<details class="wc-mss-attr-map-value-group" data-attribute-name="' + escapeHtml(attr.name) + '">'
                            + '<summary>' + escapeHtml(attr.name) + '</summary>'
                            + '<table class="wp-list-table widefat fixed striped wc-mss-attr-map-value-table">'
                            + '<thead><tr><th>' + escapeHtml(attr.name) + '</th><th></th></tr></thead>'
                            + '<tbody>' + rows + '</tbody>'
                            + '</table>'
                            + '</details>';
                    });
                    return html || '<p class="description">' + i18n.no_values + '</p>';
                }

                function saveNameMappings(button) {
                    var statusEl = $('.wc-mss-attr-map-save-status[data-for="names"]');
                    var params = { store_url: storeSelect.val(), mapping_type: 'names' };

                    nameBody.find('select.wc-mss-attr-map-remote-select').each(function () {
                        var name = $(this).data('local-name');
                        var val  = $(this).val();
                        if (val !== '') {
                            params['name_mappings[' + name + ']'] = val;
                        }
                    });

                    button.prop('disabled', true);
                    statusEl.removeClass('success error').text(i18n.saving);

                    ajaxPost('wc_mss_save_attribute_mappings', params)
                        .then(function (resp) {
                            button.prop('disabled', false);
                            if (resp.success) {
                                wcMssNotice(statusEl, i18n.saved, 'success');
                            } else {
                                wcMssNotice(statusEl, (resp.data && resp.data.message) || i18n.save_failed, 'error');
                            }
                        })
                        .catch(function () {
                            button.prop('disabled', false);
                            wcMssNotice(statusEl, i18n.save_failed, 'error');
                        });
                }

                function saveValueMappings(button) {
                    var statusEl = $('.wc-mss-attr-map-save-status[data-for="values"]');
                    var storeUrl = storeSelect.val();
                    var groups = valueGroups.find('details.wc-mss-attr-map-value-group').toArray();

                    button.prop('disabled', true);
                    statusEl.removeClass('success error').text(i18n.saving);

                    var saves = groups.map(function (group) {
                        var $group = $(group);
                        var attributeName = $group.data('attribute-name');
                        var params = {
                            store_url: storeUrl,
                            mapping_type: 'values',
                            attribute_name: attributeName,
                        };

                        $group.find('input.wc-mss-attr-map-value-input').each(function () {
                            var value = $(this).data('local-value');
                            var mapped = $(this).val();
                            if (mapped !== '') {
                                params['value_mappings[' + value + ']'] = mapped;
                            }
                        });

                        return ajaxPost('wc_mss_save_attribute_mappings', params);
                    });

                    Promise.all(saves)
                        .then(function (results) {
                            button.prop('disabled', false);
                            var failed = results.some(function (r) { return !r.success; });
                            wcMssNotice(statusEl, failed ? i18n.save_failed : i18n.saved, failed ? 'error' : 'success');
                        })
                        .catch(function () {
                            button.prop('disabled', false);
                            wcMssNotice(statusEl, i18n.save_failed, 'error');
                        });
                }

                storeSelect.on('change', function () {
                    var storeUrl = $(this).val();

                    tablesWrap.hide();
                    emptyWrap.hide();
                    nameBody.empty();
                    valueGroups.empty();

                    if (!storeUrl) {
                        return;
                    }

                    loading.show();

                    Promise.all([
                        ajaxPost('wc_mss_get_attribute_mappings', { store_url: storeUrl }),
                        ajaxPost('wc_mss_get_remote_terms', { store_url: storeUrl, taxonomy: 'attribute' }),
                    ]).then(function (results) {
                        loading.hide();

                        var mappingsResp = results[0];
                        if (!mappingsResp.success) {
                            emptyWrap.text((mappingsResp.data && mappingsResp.data.message) || i18n.load_failed).show();
                            return;
                        }

                        var data = mappingsResp.data;
                        var remoteAttributes = results[1].success ? results[1].data.terms : [];

                        if (!data.local_attributes.length) {
                            emptyWrap.show();
                            return;
                        }

                        nameBody.html(buildNameRows(data.local_attributes, data.name_mappings, remoteAttributes));
                        valueGroups.html(buildValueGroups(data.local_attributes, data.value_mappings));
                        tablesWrap.show();
                    }).catch(function () {
                        loading.hide();
                        emptyWrap.text(i18n.load_failed).show();
                    });
                });

                $('#wc-mss-attr-map-save-names').on('click', function () {
                    saveNameMappings($(this));
                });
                $('#wc-mss-attr-map-save-values').on('click', function () {
                    saveValueMappings($(this));
                });
            });
        }

        /**
         * Logs Page - Clear Log / Warnings & Errors / Force Sync by SKU / Category
         */
        if (document.getElementById('wc-mss-clear-log') && typeof jQuery !== 'undefined' && typeof wcMssLogsData !== 'undefined') {
            jQuery(function ($) {
                var i18n = wcMssLogsData.i18n;

                // Clear Log
                $('#wc-mss-clear-log').on('click', function() {
                    if (!confirm(i18n.confirm_clear_log)) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true);

                    wcMssAdminUI.response(new URLSearchParams({
                            action: 'wc_mss_clear_sync_log',
                            nonce:  wcMssAdmin.nonce
                        }))
                        .then(function (response) {
                            if (response.success) {
                                $('#wc-mss-log-content').text('');
                                $('#wc-mss-log-viewer').html('<p>' + i18n.no_log_entries + '</p>');
                            } else {
                                wcMssNotice(null, (response.data && response.data.message) || i18n.failed_to_clear_logs, 'error');
                            }
                        })
                        .finally(function() {
                            $btn.prop('disabled', false);
                        });
                });

                // Warnings & Errors extraction + clear
                var raw = $('#wc-mss-log-content').text();
                if (raw) {
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
                    } else {
                        $empty.remove();
                        $count.text('(' + issues.length + ')');

                        issues.forEach(function(line) {
                            var $div = $('<div>').text(line);
                            $viewer.append($div);
                        });

                        // Scroll to bottom by default
                        $viewer.scrollTop($viewer[0].scrollHeight);

                        $('#wc-mss-clear-warnings-errors').on('click', function() {
                            if (!confirm(i18n.confirm_clear_warnings_errors)) {
                                return;
                            }

                            var $btn    = $(this);
                            var $result = $('#wc-mss-clear-warnings-result');
                            $btn.prop('disabled', true);
                            $result.text('');

                            wcMssAdminUI.response(new URLSearchParams({
                                    action: 'wc_mss_clear_warnings_errors',
                                    nonce:  wcMssAdmin.nonce
                                }))
                                .then(function (response) {
                                    if (response.success) {
                                        $viewer.html('<p>' + i18n.no_warnings_errors + '</p>');
                                        $count.text('(0)');
                                        wcMssNotice($result, response.data.message, 'success');
                                    } else {
                                        wcMssNotice($result, (response.data && response.data.message) || i18n.failed_to_clear, 'error');
                                    }
                                })
                                .catch(function() {
                                    wcMssNotice($result, wcMssAdmin.i18n.request_failed, 'error');
                                })
                                .finally(function() {
                                    $btn.prop('disabled', false);
                                });
                        });
                    }
                }

                // Force Full Sync by SKU
                $('#wc-mss-force-sync-btn').on('click', function() {
                    var raw = $('#wc-mss-test-sku').val().trim();
                    if (!raw) {
                        wcMssNotice('#wc-mss-force-sync-result', i18n.please_enter_sku, 'warning');
                        $('#wc-mss-force-sync-result').show();
                        return;
                    }

                    var skus = raw.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });

                    var $btn = $(this);
                    $btn.prop('disabled', true).text(i18n.queuing);
                    $('#wc-mss-force-sync-result').hide();

                    var params = new URLSearchParams();
                    params.append('action', 'wc_mss_force_sync_by_sku');
                    skus.forEach(function (sku) { params.append('skus[]', sku); });
                    params.append('nonce', wcMssAdmin.nonce);

                    wcMssAdminUI.response(params)
                        .then(function(response) {
                            var message = wcMssAdminUI.message(response.data);
                            var type = response.success ? 'success' : 'error';
                            if (response.success) {
                                var results = response.data.results || [];
                                if (results.some(function (item) { return !item.success; })) type = 'warning';
                                message += '\n' + results.map(function (item) {
                                    return (item.success ? '✓ ' : '✗ ') + item.message;
                                }).join('\n') + '\n' + i18n.refresh_to_see_logs;
                            }
                            wcMssNotice('#wc-mss-force-sync-result', message, type);
                            $('#wc-mss-force-sync-result').show();
                        })
                        .catch(function (error) {
                            wcMssNotice('#wc-mss-force-sync-result', error.message, 'error');
                            $('#wc-mss-force-sync-result').show();
                        })
                        .finally(function() {
                            $btn.prop('disabled', false).text(i18n.force_full_sync);
                        });
                });

                $('#wc-mss-test-sku').on('keypress', function(e) {
                    if (e.which === 13) {
                        $('#wc-mss-force-sync-btn').trigger('click');
                    }
                });

                // Force Full Sync by Category
                $('#wc-mss-force-sync-category-btn').on('click', function() {
                    var categoryId = $('#wc-mss-category-select').val();
                    if (!categoryId) {
                        wcMssNotice('#wc-mss-force-sync-category-result', i18n.please_select_category, 'warning');
                        $('#wc-mss-force-sync-category-result').show();
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text(i18n.queuing);
                    $('#wc-mss-force-sync-category-result').hide();

                    wcMssAdminUI.response(new URLSearchParams({
                            action:      'wc_mss_force_sync_by_category',
                            category_id: categoryId,
                            nonce:       wcMssAdmin.nonce
                        }))
                        .then(function(response) {
                            var message = wcMssAdminUI.message(response.data);
                            if (response.success) message += '\n' + i18n.refresh_to_see_logs;
                            wcMssNotice('#wc-mss-force-sync-category-result', message, response.success ? 'success' : 'error');
                            $('#wc-mss-force-sync-category-result').show();
                        })
                        .catch(function (error) {
                            wcMssNotice('#wc-mss-force-sync-category-result', error.message, 'error');
                            $('#wc-mss-force-sync-category-result').show();
                        })
                        .finally(function() {
                            $btn.prop('disabled', false).text(i18n.force_full_sync);
                        });
                });
            });
        }

        /**
         * Log Viewer
         */
        var logViewer = document.getElementById('wc-mss-log-viewer');
        var logContent = document.getElementById('wc-mss-log-content');

        if (logViewer && logContent) {
            var autoScrollCheckbox = document.getElementById('wc-mss-auto-scroll');
            var reverseCheckbox = document.getElementById('wc-mss-reverse-logs');
            var filterInput = document.getElementById('wc-mss-log-filter');
            var scrollBottomBtn = document.getElementById('wc-mss-scroll-bottom');
            var scrollTopBtn = document.getElementById('wc-mss-scroll-top');

            var originalLogs = logContent.textContent;
            var originalLines = originalLogs.split('\n').filter(function(line) {
                return line.trim() !== '';
            });

            function scrollToBottom() {
                logViewer.scrollTop = logViewer.scrollHeight;
            }

            function scrollToTop() {
                logViewer.scrollTop = 0;
            }

            function applyFilter() {
                var filter = filterInput ? filterInput.value.toLowerCase() : '';
                var isReversed = reverseCheckbox ? reverseCheckbox.checked : false;
                var lines = originalLines.slice();

                if (filter) {
                    lines = lines.filter(function(line) {
                        return line.toLowerCase().indexOf(filter) !== -1;
                    });
                }

                if (isReversed) {
                    lines.reverse();
                }

                logContent.textContent = lines.join('\n');

                if (!isReversed && autoScrollCheckbox && autoScrollCheckbox.checked) {
                    scrollToBottom();
                }
            }

            if (autoScrollCheckbox && autoScrollCheckbox.checked) {
                scrollToBottom();
            }

            if (reverseCheckbox) {
                reverseCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        var lines = logContent.textContent.split('\n').filter(function(line) {
                            return line.trim() !== '';
                        });
                        logContent.textContent = lines.reverse().join('\n');
                        scrollToTop();
                    } else {
                        applyFilter();
                        if (autoScrollCheckbox && autoScrollCheckbox.checked) {
                            scrollToBottom();
                        }
                    }
                });
            }

            if (filterInput) {
                filterInput.addEventListener('input', applyFilter);
            }

            if (scrollBottomBtn) scrollBottomBtn.addEventListener('click', scrollToBottom);
            if (scrollTopBtn) scrollTopBtn.addEventListener('click', scrollToTop);
        }

        /**
         * API Usage Chart (if Chart.js is loaded)
         */
        var chartEl = document.getElementById('wc-mss-trend-chart');
        if (chartEl && typeof Chart !== 'undefined' && typeof wcMssChartData !== 'undefined') {
            var ctx = chartEl.getContext('2d');
            var trendData = wcMssChartData;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendData.map(function(item) { return item.date; }),
                    datasets: [
                        {
                            label: wcMssAdmin.i18n.total_requests || __("Total Requests", 'multi-store-sync-for-woocommerce'),
                            data: trendData.map(function(item) { return item.total_requests; }),
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34, 113, 177, 0.1)',
                            fill: true
                        },
                        {
                            label: wcMssAdmin.i18n.successful || 'Successful',
                            data: trendData.map(function(item) { return item.successful_requests; }),
                            borderColor: '#46b450',
                            backgroundColor: 'rgba(70, 180, 80, 0.1)',
                            fill: false
                        },
                        {
                            label: wcMssAdmin.i18n.failed || 'Failed',
                            data: trendData.map(function(item) { return item.failed_requests; }),
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        /**
         * Password field show/hide toggle buttons
         */
        document.addEventListener('click', function(e) {
            var toggle = e.target.closest('.wc-mss-toggle-password');
            if (!toggle) return;
            var field = toggle.previousElementSibling;
            if (field) field.type = field.type === 'password' ? 'text' : 'password';
        });

        /**
         * Stores Page - Checkbox Grid Interactions
         */
        document.querySelectorAll('.wc-mss-checkbox-grid').forEach(function(grid) {
            grid.addEventListener('change', function(e) {
                if (e.target.type === 'checkbox') {
                    var item = e.target.closest('.wc-mss-checkbox-item');
                    if (e.target.checked) {
                        item.classList.add('selected');
                    } else {
                        item.classList.remove('selected');
                    }
                    wcMssUpdateCount(grid.id);
                    wcMssUpdateSyncPreview(grid.id);
                }
            });
        });


    });

    /**
     * Navigate while bypassing WooCommerce's "Leave site?" warning
     */
    window.wcMssSafeNavigate = function(url) {
        window.onbeforeunload = null;
        window.addEventListener('beforeunload', function(e) {
            e.stopImmediatePropagation();
        }, true);
        window.location.href = url;
    };

    /**
     * Stores Page - Select All Checkboxes
     */
    window.wcMssSelectAll = function(containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;

        container.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
            cb.checked = true;
            var item = cb.closest('.wc-mss-checkbox-item');
            if (item) item.classList.add('selected');
        });
        wcMssUpdateCount(containerId);
        wcMssUpdateSyncPreview(containerId);
    };

    /**
     * Stores Page - Deselect All Checkboxes
     */
    window.wcMssDeselectAll = function(containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;

        container.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
            cb.checked = false;
            var item = cb.closest('.wc-mss-checkbox-item');
            if (item) item.classList.remove('selected');
        });
        wcMssUpdateCount(containerId);
        wcMssUpdateSyncPreview(containerId);
    };

    /**
     * Stores Page - Update Selected Count
     */
    function wcMssUpdateCount(containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;

        var count = container.querySelectorAll('input[type="checkbox"]:checked').length;
        var countSpan = document.querySelector('.wc-mss-selected-count[data-target="' + containerId + '"]');
        if (countSpan) {
            countSpan.textContent = count + ' selected';
        }
    }

    /**
     * Stores Page - Update Sync Preview
     */
    function wcMssUpdateSyncPreview(containerId) {
        // Check if store data is available
        if (typeof wcMssStoreData === 'undefined') return;

        // Determine which form we're in (edit or add)
        var prefix = containerId.indexOf('edit_') === 0 ? 'edit_' : 'add_';
        var previewId = prefix + 'sync_preview';
        var catContainerId = prefix + 'exclude_categories';
        var tagContainerId = prefix + 'exclude_tags';

        var catContainer = document.getElementById(catContainerId);
        var tagContainer = document.getElementById(tagContainerId);

        // Calculate approximate excluded products
        var excludedProducts = 0;

        if (catContainer) {
            catContainer.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
                var catId = cb.value;
                var count = wcMssStoreData.categoryProducts[catId] || 0;
                excludedProducts += count;
            });
        }

        if (tagContainer) {
            tagContainer.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
                var tagId = cb.value;
                var count = wcMssStoreData.tagProducts[tagId] || 0;
                excludedProducts += count;
            });
        }

        // Estimate sync count (approximate due to products in multiple categories)
        var syncCount = Math.max(0, wcMssStoreData.totalProducts - excludedProducts);

        // Update preview using safe DOM methods
        var previewSpan = document.getElementById(previewId);
        if (previewSpan) {
            previewSpan.textContent = excludedProducts > 0
                ? sprintf(__('Approximately %1$d of %2$d products will be synced', 'multi-store-sync-for-woocommerce'), syncCount, wcMssStoreData.totalProducts)
                : sprintf(__('%d products will be synced', 'multi-store-sync-for-woocommerce'), wcMssStoreData.totalProducts);
        }
    }

})();
