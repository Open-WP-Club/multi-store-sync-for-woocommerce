/**
 * Pure helpers for rendering the Conflicts admin table.
 * No jQuery/DOM dependency so they can run under Node's test runner.
 */
(function (root, factory) {
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = factory();
    } else {
        root.wcMssConflictUtils = factory();
    }
})(typeof window !== 'undefined' ? window : this, function () {

    function escapeHtml(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildConflictsTable(conflicts, i18n) {
        if (!conflicts.length) {
            return '<div class="notice notice-info inline"><p>' + escapeHtml(i18n.no_conflicts) + '</p></div>';
        }

        var html = '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            + '<th>' + escapeHtml(i18n.th_product) + '</th>'
            + '<th>' + escapeHtml(i18n.th_store) + '</th>'
            + '<th>' + escapeHtml(i18n.th_changed_fields) + '</th>'
            + '<th>' + escapeHtml(i18n.th_detected) + '</th>'
            + '<th>' + escapeHtml(i18n.th_status) + '</th>'
            + '<th>' + escapeHtml(i18n.th_actions) + '</th>'
            + '</tr></thead><tbody>';

        conflicts.forEach(function (c) {
            var productCell = c.edit_url
                ? '<a href="' + escapeHtml(c.edit_url) + '" target="_blank"><strong>' + escapeHtml(c.product_name) + '</strong></a>'
                : '<strong>' + escapeHtml(c.product_name) + '</strong>';
            if (c.product_sku) {
                productCell += '<br><code>' + escapeHtml(c.product_sku) + '</code>';
            }

            var fieldsHtml = (c.changed_fields || []).map(function (f) {
                return '<span class="wc-mss-changed-field-tag">' + escapeHtml(f) + '</span>';
            }).join(' ');

            var statusHtml = c.resolved
                ? '<span class="wc-mss-status-badge wc-mss-status-resolved">' + escapeHtml(i18n.resolved) + (c.resolution ? ' (' + escapeHtml(c.resolution) + ')' : '') + '</span>'
                : '<span class="wc-mss-status-badge wc-mss-status-unresolved">' + escapeHtml(i18n.unresolved) + '</span>';

            var safeId = escapeHtml(c.id);
            var actionsHtml = '';
            if (!c.resolved) {
                actionsHtml = '<button type="button" class="button button-primary button-small wc-mss-resolve-conflict-btn" data-id="' + safeId + '" data-resolution="overwrite">' + escapeHtml(i18n.overwrite) + '</button> '
                    + '<button type="button" class="button button-small wc-mss-resolve-conflict-btn" data-id="' + safeId + '" data-resolution="keep_remote">' + escapeHtml(i18n.keep_remote) + '</button> '
                    + '<button type="button" class="button button-small wc-mss-resolve-conflict-btn" data-id="' + safeId + '" data-resolution="merge">' + escapeHtml(i18n.merge) + '</button>';
            }

            html += '<tr id="wc-mss-conflict-row-' + safeId + '">'
                + '<td>' + productCell + '</td>'
                + '<td>' + escapeHtml(c.store_url) + '</td>'
                + '<td>' + fieldsHtml + '</td>'
                + '<td>' + escapeHtml(c.detected_at) + '</td>'
                + '<td>' + statusHtml + '</td>'
                + '<td>' + actionsHtml + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    return { escapeHtml: escapeHtml, buildConflictsTable: buildConflictsTable };
});
