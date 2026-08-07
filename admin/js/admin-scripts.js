/**
 * WooCommerce Multi-Store Sync - Admin Scripts
 * Native JavaScript (no jQuery)
 */

(function() {
    'use strict';

    /**
     * Utility: AJAX request helper
     */
    function ajax(options) {
        var xhr = new XMLHttpRequest();
        xhr.open(options.method || 'POST', options.url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (options.success) options.success(response);
                } catch (e) {
                    if (options.error) options.error(e);
                }
            } else {
                if (options.error) options.error(xhr.statusText);
            }
            if (options.complete) options.complete();
        };

        xhr.onerror = function() {
            if (options.error) options.error('Network error');
            if (options.complete) options.complete();
        };

        xhr.send(options.data);
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

                    var feedback = document.createElement('div');
                    feedback.className = 'wc-mss-save-feedback';
                    feedback.textContent = 'Saving...';
                    document.body.appendChild(feedback);

                    setTimeout(function() {
                        feedback.classList.add('show');
                    }, 10);

                    setTimeout(function() {
                        feedback.classList.remove('show');
                        setTimeout(function() {
                            feedback.remove();
                        }, 300);
                    }, 1500);
                }
            }

            if (e.key === 'Escape') {
                var feedbacks = document.querySelectorAll('.wc-mss-save-feedback');
                feedbacks.forEach(function(f) { f.remove(); });
            }
        });

        /**
         * Force Sync All Products
         */
        var forceSyncBtn = document.getElementById('wc-mss-force-sync-all');
        if (forceSyncBtn) {
            var result = document.getElementById('wc-mss-force-sync-result');
            var progressContainer = document.getElementById('wc-mss-sync-progress-container');
            var progressBar = document.getElementById('wc-mss-sync-progress-bar');
            var progressText = document.getElementById('wc-mss-sync-progress-text');

            forceSyncBtn.addEventListener('click', function() {
                if (!confirm(wcMssAdmin.i18n.confirm_sync_all || 'Are you sure you want to force sync ALL products?')) {
                    return;
                }

                var originalText = forceSyncBtn.textContent;
                forceSyncBtn.disabled = true;
                forceSyncBtn.textContent = wcMssAdmin.i18n.syncing || 'Syncing...';
                if (result) result.textContent = '';
                if (progressContainer) progressContainer.style.display = 'block';

                var progress = 10;
                if (progressBar) progressBar.style.width = progress + '%';
                if (progressText) progressText.textContent = progress + '%';

                var progressInterval = setInterval(function() {
                    if (progress < 90) {
                        progress += Math.random() * 15;
                        if (progress > 90) progress = 90;
                        if (progressBar) progressBar.style.width = progress + '%';
                        if (progressText) progressText.textContent = Math.floor(progress) + '%';
                    }
                }, 500);

                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize({
                        action: 'wc_mss_force_sync_all',
                        nonce: wcMssAdmin.force_sync_nonce
                    }),
                    success: function(response) {
                        clearInterval(progressInterval);
                        if (progressBar) progressBar.style.width = '100%';
                        if (progressText) progressText.textContent = '100%';

                        setTimeout(function() {
                            if (result) {
                                while (result.firstChild) result.removeChild(result.firstChild);
                                var p = document.createElement('p');
                                if (response.success) {
                                    p.style.color = '#46b450';
                                    p.textContent = '\u2713 ' + response.data.message;
                                } else {
                                    p.style.color = '#dc3232';
                                    p.textContent = '\u2717 ' + (response.data ? response.data.message : 'Error');
                                }
                                result.appendChild(p);
                            }
                            forceSyncBtn.disabled = false;
                            forceSyncBtn.textContent = originalText;

                            setTimeout(function() {
                                if (progressContainer) {
                                    progressContainer.style.opacity = '0';
                                    setTimeout(function() {
                                        progressContainer.style.display = 'none';
                                        progressContainer.style.opacity = '1';
                                    }, 300);
                                }
                            }, 2000);
                        }, 500);
                    },
                    error: function() {
                        clearInterval(progressInterval);
                        if (result) {
                            while (result.firstChild) result.removeChild(result.firstChild);
                            var p = document.createElement('p');
                            p.style.color = '#dc3232';
                            p.textContent = '\u2717 ' + (wcMssAdmin.i18n.request_failed || 'Request failed');
                            result.appendChild(p);
                        }
                        forceSyncBtn.disabled = false;
                        forceSyncBtn.textContent = originalText;
                        if (progressContainer) progressContainer.style.display = 'none';
                    }
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
                    alert('Please fill in all fields before testing the connection.');
                    return;
                }

                testConnectionBtn.disabled = true;
                var originalText = testConnectionBtn.textContent;
                testConnectionBtn.textContent = 'Testing...';

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
                            alert('Success: ' + response.data);
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function(error) {
                        alert('Connection test failed: ' + error);
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
                    alert('Product ID not found.');
                    return;
                }

                this.disabled = true;
                var originalText = this.textContent;
                this.textContent = 'Syncing...';
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
                            alert('Product synced successfully!');
                        } else {
                            alert('Sync failed: ' + response.data);
                        }
                    },
                    error: function(error) {
                        alert('Sync failed: ' + error);
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
                healthCheckBtn.textContent = 'Running health check...';
                if (healthResult) healthResult.textContent = 'Checking all stores...';

                ajax({
                    url: wcMssAdmin.ajax_url,
                    data: serialize({
                        action: 'wc_mss_run_health_check',
                        nonce: wcMssAdmin.health_check_nonce || wcMssAdmin.nonce
                    }),
                    success: function(response) {
                        if (response.success && healthResult) {
                            var color = response.data.healthy_count === response.data.total_count ? '#46b450' : '#dc3232';
                            healthResult.style.color = color;
                            healthResult.textContent = '\u2713 ' + response.data.message;
                            setTimeout(function() { location.reload(); }, 2000);
                        } else if (healthResult) {
                            healthResult.style.color = '#dc3232';
                            healthResult.textContent = '\u2717 ' + response.data.message;
                        }
                    },
                    error: function() {
                        if (healthResult) {
                            healthResult.style.color = '#dc3232';
                            healthResult.textContent = '\u2717 Health check failed';
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
                            var color = r.healthy ? '#46b450' : '#dc3232';
                            healthCell.innerHTML =
                                '<span style="color:' + color + ';">' + r.message + '</span>' +
                                '<br><small style="color:#666;">Last: ' + r.checked_at + '</small>';
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
                    if (resultEl) { resultEl.style.color = '#dc3232'; resultEl.textContent = 'Fill in Store URL, username and password first.'; }
                    return;
                }

                this.disabled = true;
                if (resultEl) { resultEl.style.color = '#646970'; resultEl.textContent = 'Testing...'; }

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
                            resultEl.style.color = response.success ? '#46b450' : '#dc3232';
                            resultEl.textContent = (response.success ? '✓ ' : '✗ ') + response.data.message;
                        }
                    },
                    error: function() {
                        if (resultEl) { resultEl.style.color = '#dc3232'; resultEl.textContent = '✗ Request failed'; }
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
                    if (resultEl) { resultEl.style.color = '#dc3232'; resultEl.textContent = 'Enter an application password first.'; }
                    return;
                }
                if (!username) {
                    if (resultEl) { resultEl.style.color = '#dc3232'; resultEl.textContent = 'Enter a username first.'; }
                    return;
                }

                this.disabled = true;
                if (resultEl) { resultEl.style.color = '#646970'; resultEl.textContent = 'Testing...'; }

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
                            resultEl.style.color = response.success ? '#46b450' : '#dc3232';
                            resultEl.textContent = (response.success ? '✓ ' : '✗ ') + response.data.message;
                        }
                    },
                    error: function() {
                        if (resultEl) { resultEl.style.color = '#dc3232'; resultEl.textContent = '✗ Request failed'; }
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
                    alert(wcMssAdmin.i18n.copy_failed || 'Failed to copy. Please copy manually.');
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
            }
            modal.style.display = 'block';
            loadWebhookLogs(1);
        }

        function createWebhookLogsModal() {
            var modal = document.createElement('div');
            modal.id = 'wc-mss-webhook-logs-modal';
            modal.className = 'wc-mss-modal';
            modal.innerHTML = '<div class="wc-mss-modal-content wc-mss-modal-large">' +
                '<div class="wc-mss-modal-header">' +
                    '<h2>Webhook Logs</h2>' +
                    '<span class="wc-mss-modal-close">&times;</span>' +
                '</div>' +
                '<div class="wc-mss-modal-body">' +
                    '<div class="wc-mss-webhook-filters" style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;">' +
                        '<select id="webhook-log-type-filter" class="regular-text">' +
                            '<option value="">Всички типове</option>' +
                            '<option value="order_received">Получена поръчка</option>' +
                            '<option value="stock_deducted">Намалена наличност</option>' +
                            '<option value="stock_synced">Синхронизирана наличност</option>' +
                            '<option value="auth_failed">Неуспешна автентикация</option>' +
                            '<option value="validation_error">Грешка при валидация</option>' +
                            '<option value="product_not_found">Продукт не е намерен</option>' +
                            '<option value="rate_limited">Надвишен лимит</option>' +
                        '</select>' +
                        '<select id="webhook-log-status-filter" class="regular-text">' +
                            '<option value="">Всички статуси</option>' +
                            '<option value="success">Успех</option>' +
                            '<option value="failed">Грешка</option>' +
                        '</select>' +
                        '<input type="text" id="webhook-log-sku-filter" placeholder="SKU..." class="regular-text" style="width: 120px;" />' +
                        '<input type="date" id="webhook-log-date-from" class="regular-text" />' +
                        '<input type="date" id="webhook-log-date-to" class="regular-text" />' +
                        '<button type="button" id="apply-webhook-filters" class="button">Филтрирай</button>' +
                    '</div>' +
                    '<div id="webhook-logs-stats" style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;"></div>' +
                    '<div class="wc-mss-delete-actions" style="margin-bottom: 15px; padding: 10px; background: #fff8e5; border: 1px solid #ffb900; border-radius: 4px;">' +
                        '<strong>Изтриване:</strong> ' +
                        '<button type="button" class="button delete-webhook-logs" data-type="errors" style="margin-left: 10px;">Само грешки</button> ' +
                        '<button type="button" class="button delete-webhook-logs" data-type="success">Само успешни</button> ' +
                        '<button type="button" class="button delete-webhook-logs" data-type="older_than" data-days="30">По-стари от 30 дни</button> ' +
                        '<button type="button" class="button delete-webhook-logs" data-type="older_than" data-days="7">По-стари от 7 дни</button> ' +
                        '<button type="button" class="button button-link-delete delete-webhook-logs" data-type="all" style="color: #b32d2e;">Изтрий всички</button>' +
                    '</div>' +
                    '<div id="webhook-logs-container" style="max-height: 400px; overflow-y: auto;">' +
                        '<table class="wp-list-table widefat fixed striped">' +
                            '<thead><tr>' +
                                '<th style="width: 140px;">Дата/Час</th>' +
                                '<th style="width: 150px;">Тип</th>' +
                                '<th style="width: 100px;">SKU</th>' +
                                '<th style="width: 80px;">Наличност</th>' +
                                '<th>Причина</th>' +
                                '<th style="width: 80px;">Статус</th>' +
                                '<th style="width: 70px;">Детайли</th>' +
                            '</tr></thead>' +
                            '<tbody id="webhook-logs-body"></tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div id="webhook-logs-pagination" style="margin-top: 15px; text-align: center;"></div>' +
                '</div>' +
            '</div>';

            // Close button handler
            modal.querySelector('.wc-mss-modal-close').addEventListener('click', function() {
                modal.style.display = 'none';
            });

            // Click outside to close
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });

            // Filter button handler
            modal.querySelector('#apply-webhook-filters').addEventListener('click', function() {
                loadWebhookLogs(1);
            });

            // Delete buttons handlers
            modal.querySelectorAll('.delete-webhook-logs').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var deleteType = this.dataset.type;
                    var days = this.dataset.days;

                    var confirmMsg = 'Сигурни ли сте, че искате да изтриете ';
                    switch (deleteType) {
                        case 'all':
                            confirmMsg += 'ВСИЧКИ логове?';
                            break;
                        case 'errors':
                            confirmMsg += 'всички записи с грешки?';
                            break;
                        case 'success':
                            confirmMsg += 'всички успешни записи?';
                            break;
                        case 'older_than':
                            confirmMsg += 'всички записи по-стари от ' + days + ' дни?';
                            break;
                    }

                    if (!confirm(confirmMsg)) {
                        return;
                    }

                    deleteWebhookLogs(deleteType, days);
                });
            });

            return modal;
        }

        function loadWebhookLogs(page) {
            var body = document.getElementById('webhook-logs-body');
            var pagination = document.getElementById('webhook-logs-pagination');
            var stats = document.getElementById('webhook-logs-stats');

            if (!body) return;

            body.innerHTML = '<tr><td colspan="7" style="text-align: center;">Зареждане...</td></tr>';

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
                        renderWebhookLogs(response.data.logs, body);
                        renderWebhookPagination(response.data, pagination, page);
                        loadWebhookStats(stats);
                    } else {
                        body.innerHTML = '<tr><td colspan="7" style="text-align: center; color: red;">Грешка при зареждане</td></tr>';
                    }
                },
                error: function() {
                    body.innerHTML = '<tr><td colspan="7" style="text-align: center; color: red;">Грешка при връзката</td></tr>';
                }
            });
        }

        function renderWebhookLogs(logs, container) {
            if (!logs || logs.length === 0) {
                container.innerHTML = '<tr><td colspan="7" style="text-align: center;">Няма записи</td></tr>';
                return;
            }

            var html = '';
            logs.forEach(function(log) {
                var stockChange = '';
                if (log.old_stock !== null && log.new_stock !== null) {
                    stockChange = log.old_stock + ' → ' + log.new_stock;
                    if (log.quantity_changed) {
                        stockChange += ' (' + (log.quantity_changed > 0 ? '+' : '') + log.quantity_changed + ')';
                    }
                }

                html += '<tr>' +
                    '<td>' + (log.created_at || '') + '</td>' +
                    '<td>' + (log.type_label || log.log_type) + '</td>' +
                    '<td>' + (log.product_sku || '-') + '</td>' +
                    '<td>' + (stockChange || '-') + '</td>' +
                    '<td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="' + escapeHtml(log.change_reason || '') + '">' + (log.change_reason || '-') + '</td>' +
                    '<td>' + (log.status_badge || log.status) + '</td>' +
                    '<td><button type="button" class="button button-small view-webhook-detail" data-id="' + log.id + '">Виж</button></td>' +
                '</tr>';
            });
            container.innerHTML = html;

            // Add detail view handlers
            container.querySelectorAll('.view-webhook-detail').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    showWebhookLogDetail(this.dataset.id);
                });
            });
        }

        function renderWebhookPagination(data, container, currentPage) {
            if (data.pages <= 1) {
                container.innerHTML = '';
                return;
            }

            var html = '<span>Страница ' + data.page + ' от ' + data.pages + ' (общо ' + data.total + ' записа)</span> ';

            if (currentPage > 1) {
                html += '<button type="button" class="button" data-page="' + (currentPage - 1) + '">« Предишна</button> ';
            }
            if (currentPage < data.pages) {
                html += '<button type="button" class="button" data-page="' + (currentPage + 1) + '">Следваща »</button>';
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

                        container.innerHTML = '<strong>Последни 30 дни:</strong> ' +
                            'Общо: ' + total + ' събития | ' +
                            'Промени в наличност: ' + (stats.type_counts.stock_deducted || 0) + ' | ' +
                            'Общо променени бройки: ' + stockChanges + ' | ' +
                            'Грешки: ' + failed;
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
                        var details = 'ID: ' + log.id + '\n' +
                            'Дата: ' + log.created_at + '\n' +
                            'Тип: ' + log.type_label + '\n' +
                            'Статус: ' + log.status + '\n' +
                            'Магазин: ' + (log.store_url || '-') + '\n' +
                            'Поръчка #: ' + (log.remote_order_id || '-') + '\n' +
                            'Продукт ID: ' + (log.product_id || '-') + '\n' +
                            'SKU: ' + (log.product_sku || '-') + '\n' +
                            'Стара наличност: ' + (log.old_stock !== null ? log.old_stock : '-') + '\n' +
                            'Нова наличност: ' + (log.new_stock !== null ? log.new_stock : '-') + '\n' +
                            'Промяна: ' + (log.quantity_changed || '-') + '\n' +
                            'IP: ' + (log.request_ip || '-') + '\n' +
                            'Причина: ' + (log.change_reason || '-') + '\n' +
                            (log.error_message ? 'Грешка: ' + log.error_message + '\n' : '') +
                            (log.request_data ? '\nДанни от заявката:\n' + JSON.stringify(log.request_data, null, 2) : '');
                        alert(details);
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
                        alert('Грешка при експортиране');
                    }
                },
                error: function() {
                    alert('Грешка при връзката');
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
                        alert(response.data.message + '\nОставащи записи: ' + response.data.remaining);
                        loadWebhookLogs(1);
                    } else {
                        alert('Грешка: ' + (response.data.message || 'Неизвестна грешка'));
                    }
                },
                error: function() {
                    alert('Грешка при връзката със сървъра');
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
         * Queue Retry Button
         */
        document.querySelectorAll('.wc-mss-queue-retry').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                var row = document.getElementById('queue-row-' + id);
                var table = this.closest('table');
                var labelRetry = (table && table.dataset.labelRetry) || 'Retry';
                var labelRetrying = (table && table.dataset.labelRetrying) || 'Retrying…';
                var labelPending = (table && table.dataset.labelPending) || 'Pending';
                var labelError = (table && table.dataset.labelError) || 'Error';

                this.disabled = true;
                this.textContent = labelRetrying;

                fetch(wcMssAdmin.ajax_url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: 'wc_mss_queue_retry_item', nonce: wcMssAdmin.nonce, item_id: id}).toString()
                })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) {
                        if (row) {
                            var statusCell = row.querySelector('td:nth-child(6) span');
                            if (statusCell) {
                                statusCell.style.cssText = 'color: #2271b1;';
                                statusCell.textContent = labelPending;
                            }
                            var errorNote = row.querySelector('td:nth-child(6) small');
                            if (errorNote) errorNote.remove();
                            var actionCell = row.querySelector('td:last-child');
                            if (actionCell) actionCell.innerHTML = '';
                            var attemptsCell = row.querySelector('td:nth-child(8)');
                            if (attemptsCell) attemptsCell.textContent = '0/3';
                        }
                    } else {
                        alert((result.data && result.data.message) || labelError);
                        this.disabled = false;
                        this.textContent = labelRetry;
                    }
                }.bind(this))
                .catch(function() {
                    alert(wcMssAdmin.i18n.request_failed);
                    this.disabled = false;
                    this.textContent = labelRetry;
                }.bind(this));
            });
        });

        /**
         * Dead Letter Queue Actions
         */
        function wcMssDlqAction(action, data, callback) {
            data.action = action;
            data.nonce = wcMssAdmin.nonce;
            fetch(wcMssAdmin.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(data).toString()
            })
            .then(function(r) { return r.json(); })
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

        document.querySelectorAll('.wc-mss-dlq-retry').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                var row = document.getElementById('dlq-row-' + id);
                this.disabled = true;
                wcMssDlqAction('wc_mss_dlq_retry_item', {item_id: id}, function(ok, msg) {
                    if (ok && row) row.style.opacity = '0.3';
                    alert(msg);
                });
            });
        });

        document.querySelectorAll('.wc-mss-dlq-resolve').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                var row = document.getElementById('dlq-row-' + id);
                this.disabled = true;
                wcMssDlqAction('wc_mss_dlq_resolve_item', {item_id: id}, function(ok, msg) {
                    if (ok && row) row.style.opacity = '0.3';
                });
            });
        });

        var dlqRetryAllBtn = document.getElementById('wc-mss-dlq-retry-all');
        if (dlqRetryAllBtn) {
            dlqRetryAllBtn.addEventListener('click', function() {
                if (!confirm(dlqRetryAllBtn.dataset.confirm)) return;
                this.disabled = true;
                wcMssDlqAction('wc_mss_dlq_retry_all', {}, function(ok, msg) {
                    alert(msg);
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
                    alert(msg);
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

                fetch(wcMssAdmin.ajax_url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=wc_mss_export_config&nonce=' + wcMssAdmin.nonce + '&include_keys=' + (includeKeys ? '1' : '0')
                })
                .then(function(r) { return r.json(); })
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

                        document.getElementById('wc-mss-export-result').innerHTML = '<div class="notice inline notice-success"><p>' + btn.dataset.successMessage + '</p></div>';
                    } else {
                        document.getElementById('wc-mss-export-result').innerHTML = '<div class="notice inline notice-error"><p>' + (data.data.message || 'Error') + '</p></div>';
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
                    fetch(wcMssAdmin.ajax_url, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wc_mss_import_config&nonce=' + wcMssAdmin.nonce + '&config=' + encodeURIComponent(e.target.result)
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            document.getElementById('wc-mss-import-result').innerHTML = '<div class="notice inline notice-success"><p>' + data.data.message + '</p></div>';
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            document.getElementById('wc-mss-import-result').innerHTML = '<div class="notice inline notice-error"><p>' + (data.data.message || 'Error') + '</p></div>';
                        }
                        btn.disabled = false;
                        btn.textContent = configImportLabel;
                    });
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
                        $result.text($btn.data('msg-select-category')).css('color', '#d63638');
                        return;
                    }

                    $btn.prop('disabled', true);
                    $result.text($btn.data('msg-queuing')).css('color', '');

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:           'wc_mss_sync_by_category',
                            nonce:            wcMssAdmin.nonce,
                            category_id:      catId,
                            sync_type:        syncType,
                            include_children: children,
                        }),
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (resp) {
                            $btn.prop('disabled', false);
                            if (resp.success) {
                                $result.text(resp.data.message).css('color', '#00a32a');
                            } else {
                                $result.text(resp.data.message || $btn.data('msg-error')).css('color', '#d63638');
                            }
                        })
                        .catch(function () {
                            $btn.prop('disabled', false);
                            $result.text(wcMssAdmin.i18n.request_failed).css('color', '#d63638');
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

                    fetch(wcMssAdmin.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: action,
                            enabled: enabled,
                            nonce: wcMssAdmin.nonce
                        }),
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (response) {
                            $checkbox.prop('disabled', false);
                            if (response.success) {
                                // Flash green briefly
                                $checkbox.closest('td').css('background-color', '#d4edda').delay(800).queue(function(next) {
                                    $(this).css('background-color', '');
                                    next();
                                });
                            } else {
                                // Revert on failure
                                $checkbox.prop('checked', !enabled);
                                alert((response.data && response.data.message) || wcMssAdmin.i18n.failed_to_update);
                            }
                        })
                        .catch(function () {
                            $checkbox.prop('disabled', false);
                            $checkbox.prop('checked', !enabled);
                            alert(wcMssAdmin.i18n.request_failed);
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
         * Deletion Audit - View Details Toggle
         */
        document.querySelectorAll('.view-details').forEach(function(button) {
            button.addEventListener('click', function() {
                var logId = this.dataset.logId;
                var detailsRow = document.getElementById('details-' + logId);

                if (!detailsRow) return;

                var isVisible = detailsRow.style.display !== 'none';

                if (isVisible) {
                    detailsRow.style.display = 'none';
                    this.textContent = wcMssAdmin.i18n.view_details || 'View Details';
                } else {
                    document.querySelectorAll('.audit-details').forEach(function(row) {
                        row.style.display = 'none';
                    });
                    document.querySelectorAll('.view-details').forEach(function(btn) {
                        btn.textContent = wcMssAdmin.i18n.view_details || 'View Details';
                    });

                    detailsRow.style.display = 'table-row';
                    this.textContent = wcMssAdmin.i18n.hide_details || 'Hide Details';
                }
            });
        });

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
                            label: wcMssAdmin.i18n.total_requests || 'Total Requests',
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
            // Clear existing content
            previewSpan.textContent = '';

            // Create elements safely
            var strong = document.createElement('strong');
            strong.style.color = '#2271b1';
            strong.style.fontSize = '16px';

            if (excludedProducts > 0) {
                strong.textContent = '~' + syncCount;
                previewSpan.appendChild(strong);
                previewSpan.appendChild(document.createTextNode(' of ' + wcMssStoreData.totalProducts + ' products will be synced '));
                var small = document.createElement('small');
                small.style.color = '#666';
                small.textContent = '(approximate)';
                previewSpan.appendChild(small);
            } else {
                strong.textContent = wcMssStoreData.totalProducts;
                previewSpan.appendChild(strong);
                previewSpan.appendChild(document.createTextNode(' of ' + wcMssStoreData.totalProducts + ' products will be synced'));
            }
        }
    }

})();
