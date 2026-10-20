/** Shared admin feedback and requests, backed by WordPress' wp.ajax API. */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory;
    } else {
        root.wcMssAdminUI = factory(root.wp, root.document, function () { return root.wcMssAdmin || {}; });
        root.wcMssRequest = root.wcMssAdminUI.request;
        root.wcMssNotice = root.wcMssAdminUI.notice;
    }
})(typeof window === 'undefined' ? this : window, function (wp, document, config) {
    'use strict';

    function message(error) {
        var fallback = (config().i18n || {}).request_failed || 'Request failed';
        if (typeof error === 'string' && error.trim() && error !== '-1' && error !== '0') return error;
        if (Array.isArray(error)) return error.map(message).join('\n');
        return error && (error.message || error.error) || fallback;
    }

    function request(action, data) {
        var settings = config();
        return new Promise(function (resolve, reject) {
            wp.ajax.send(action, {
                url: settings.ajax_url,
                data: Object.assign({ nonce: settings.nonce }, data || {}),
                success: resolve,
                error: function (result) {
                    var error = new Error(message(result));
                    error.data = result;
                    error.applicationError = !(result && typeof result.status === 'number');
                    reject(error);
                }
            });
        });
    }

    // Keep existing handlers' WP response envelope while sharing one transport.
    function response(data) {
        var params = {};
        if (typeof data === 'string' || data instanceof URLSearchParams) {
            new URLSearchParams(data).forEach(function (value, key) {
                if (key.endsWith('[]')) {
                    key = key.slice(0, -2);
                    if (!params[key]) params[key] = [];
                    params[key].push(value);
                } else {
                    params[key] = value;
                }
            });
        } else {
            params = Object.assign({}, data);
        }
        var action = params.action;
        delete params.action;
        return request(action, params).then(function (result) {
            return { success: true, data: result };
        }, function (error) {
            return { success: false, data: { message: error.message } };
        });
    }

    function notice(container, text, type) {
        if (typeof container === 'string') container = document.querySelector(container);
        if (container && container.jquery) container = container[0];
        if (!container) {
            container = document.getElementById('wc-mss-notices');
            if (!container) {
                container = document.createElement('div');
                container.id = 'wc-mss-notices';
                (document.querySelector('.wrap') || document.body).prepend(container);
            }
        }
        var level = ['success', 'error', 'warning', 'info'].indexOf(type) === -1 ? 'success' : type;
        var node = document.createElement('div');
        node.className = 'notice notice-' + level + ' inline is-dismissible';
        node.setAttribute('role', level === 'error' ? 'alert' : 'status');
        var paragraph = document.createElement('p');
        paragraph.className = 'wc-mss-notice-message';
        paragraph.textContent = String(text == null ? '' : text);
        node.appendChild(paragraph);
        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'notice-dismiss';
        var label = document.createElement('span');
        label.className = 'screen-reader-text';
        label.textContent = (config().i18n || {}).dismiss_notice || 'Dismiss this notice.';
        dismiss.appendChild(label);
        dismiss.addEventListener('click', function () { node.remove(); });
        node.appendChild(dismiss);
        container.replaceChildren(node);
        return node;
    }

    return { request: request, response: response, notice: notice, message: message };
});
