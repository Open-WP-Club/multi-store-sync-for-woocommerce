/** Shared verification controls for the dashboard and weekly settings page. */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var strings = wcMssAdmin.verification_i18n;
        var suffix = document.getElementById('wc-mss-start-verification-page') ? '-page' : '';
        var start = document.getElementById('wc-mss-start-verification' + suffix);
        if (!start) return;

        var cancel = document.getElementById('wc-mss-cancel-verification' + suffix);
        var progress = document.getElementById('wc-mss-verification-progress' + suffix);
        var status = document.getElementById('wc-mss-verification-status' + suffix);
        var percent = document.getElementById('wc-mss-verification-percent' + suffix);
        var bar = document.getElementById('wc-mss-verification-bar' + suffix);
        var details = document.getElementById('wc-mss-verification-details' + suffix);
        var result = document.getElementById('wc-mss-verification-result' + suffix);
        var startLabel = start.textContent;
        var timer;
        var revision = 0;
        var idleCount = 0;

        function stopPolling() {
            revision++;
            clearTimeout(timer);
        }

        function restoreControls() {
            start.style.display = '';
            start.disabled = false;
            start.textContent = startLabel;
            cancel.hidden = true;
            cancel.disabled = false;
        }

        function showProgress() {
            progress.hidden = false;
            start.style.display = 'none';
            cancel.hidden = false;
            cancel.disabled = false;
        }

        function setPercent(value) {
            value = Math.max(0, Math.min(100, Number(value) || 0));
            percent.textContent = value + '%';
            bar.value = value;
        }

        function update(data) {
            if (data.status === 'running') {
                idleCount = 0;
                showProgress();
                setPercent(data.percent);
                status.textContent = strings.verifying;
                details.textContent = data.processed + '/' + data.total + ' ' + strings.products +
                    ' | ' + strings.batch + ' ' + data.current_batch + '/' + data.total_batches +
                    ' | ' + strings.discrepancies + ': ' + data.discrepancies;
            } else if (data.status === 'completed' || data.status === 'cancelled') {
                stopPolling();
                restoreControls();
                if (data.status === 'completed') {
                    setPercent(100);
                    status.textContent = strings.completed;
                    details.textContent = data.processed + ' ' + strings.products + ' | ' +
                        strings.discrepancies + ': ' + data.discrepancies;
                    window.wcMssNotice(result, strings.completed_results, 'success');
                    if (suffix) timer = setTimeout(function() { window.location.reload(); }, 3000);
                } else {
                    progress.hidden = true;
                    window.wcMssNotice(result, strings.cancelled, 'success');
                }
                return false;
            } else {
                idleCount++;
                status.textContent = strings.waiting;
                details.textContent = strings.background;
                bar.removeAttribute('value');
                percent.textContent = '';
                if (data.status === 'idle' && idleCount >= 40) {
                    stopPolling();
                    restoreControls();
                    window.wcMssNotice(result, strings.background, 'info');
                    return false;
                }
            }
            return true;
        }

        function poll() {
            var current = revision;
            window.wcMssRequest('wc_mss_get_verification_progress').then(function(data) {
                if (current !== revision) return;
                if (update(data)) timer = setTimeout(poll, 3000);
            }).catch(function(error) {
                if (current !== revision) return;
                stopPolling();
                restoreControls();
                window.wcMssNotice(result, error.message, 'error');
            });
        }

        start.addEventListener('click', function() {
            stopPolling();
            var current = revision;
            start.disabled = true;
            start.textContent = strings.starting;
            result.textContent = '';
            idleCount = 0;
            setPercent(0);
            details.textContent = '';
            window.wcMssRequest('wc_mss_start_verification').then(function(data) {
                if (current !== revision) return;
                showProgress();
                status.textContent = data.message;
                timer = setTimeout(poll, 1000);
            }).catch(function(error) {
                if (current !== revision) return;
                restoreControls();
                window.wcMssNotice(result, error.message, 'error');
            });
        });

        cancel.addEventListener('click', function() {
            stopPolling();
            var current = revision;
            cancel.disabled = true;
            window.wcMssRequest('wc_mss_cancel_verification').then(function(data) {
                if (current !== revision) return;
                restoreControls();
                progress.hidden = true;
                window.wcMssNotice(result, data.message, 'success');
            }).catch(function(error) {
                if (current !== revision) return;
                cancel.disabled = false;
                window.wcMssNotice(result, error.message, 'error');
                timer = setTimeout(poll, 3000);
            });
        });

        // Resume both queued and running verification when returning to either page.
        var initialRevision = revision;
        window.wcMssRequest('wc_mss_get_verification_progress').then(function(data) {
            if (initialRevision !== revision) return;
            if (data.status === 'running' || data.status === 'pending') {
                showProgress();
                update(data);
                timer = setTimeout(poll, 3000);
            }
        }).catch(function(error) {
            if (initialRevision === revision) window.wcMssNotice(result, error.message, 'error');
        });

        function bindAction(id, action, target, confirmation, nonce, onSuccess) {
            var button = document.getElementById(id);
            if (!button) return;
            button.addEventListener('click', function() {
                if (!window.confirm(confirmation)) return;
                button.disabled = true;
                var original = button.innerHTML;
                button.textContent = strings.working;
                window.wcMssRequest(action, nonce ? {nonce: nonce} : {}).then(function(data) {
                    window.wcMssNotice(target, data.message, 'success');
                    if (onSuccess) onSuccess(button);
                }).catch(function(error) {
                    window.wcMssNotice(target, error.message, 'error');
                }).finally(function() {
                    button.disabled = false;
                    button.innerHTML = original;
                });
            });
        }

        var stopResult = document.getElementById('sync-stop-status');
        bindAction('stop-weekly-sync', 'wc_mss_stop_weekly_verification', stopResult,
            strings.confirm_stop_weekly, wcMssAdmin.stop_sync_nonce, function() {
                document.getElementById('verification_enabled').checked = false;
                stopPolling();
                restoreControls();
                progress.hidden = true;
            });
        bindAction('stop-all-sync', 'wc_mss_stop_all_sync', stopResult,
            strings.confirm_stop_all, wcMssAdmin.stop_sync_nonce);
        bindAction('wc-mss-queue-orphans', 'wc_mss_queue_orphans_for_deletion',
            document.getElementById('wc-mss-orphan-status'), strings.confirm_orphans, null,
            function(button) { button.hidden = true; });

        var autoCorrect = document.getElementById('auto_correct');
        if (autoCorrect) autoCorrect.addEventListener('change', function() {
            document.querySelectorAll('#auto_correct_limit_row, .auto-correct-option').forEach(function(row) {
                row.hidden = !autoCorrect.checked;
            });
        });
        window.addEventListener('pagehide', stopPolling);
    });
})();
