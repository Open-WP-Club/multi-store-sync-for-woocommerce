const test = require('node:test');
const assert = require('node:assert/strict');
const createUI = require('../../admin/js/admin-ui.js');

function setup() {
    const calls = [];
    const root = node('main');
    function node(tag) {
        return {
            tag, children: [], attributes: {}, listeners: {},
            appendChild(child) { this.children.push(child); },
            replaceChildren(...children) { this.children = children; },
            prepend(child) { this.children.unshift(child); },
            setAttribute(key, value) { this.attributes[key] = value; },
            addEventListener(key, fn) { this.listeners[key] = fn; },
            remove() { this.removed = true; },
            set innerHTML(_) { throw new Error('Notice must not interpolate HTML'); }
        };
    }
    const document = {
        createElement: node, body: root,
        getElementById: () => null,
        querySelector: () => root
    };
    const ui = createUI({ajax: {send(action, options) { calls.push({action, options}); }}}, document, () => ({
        ajax_url: '/wp-admin/admin-ajax.php', nonce: 'admin-nonce',
        i18n: {request_failed: 'Request failed', dismiss_notice: 'Dismiss'}
    }));
    return {ui, calls, root};
}

test('uses wp.ajax with default nonce and retains special-action nonces', async () => {
    const {ui, calls} = setup();
    const first = ui.request('load_records', {store_url: 'https://store.test'});
    assert.equal(calls[0].action, 'load_records');
    assert.equal(calls[0].options.url, '/wp-admin/admin-ajax.php');
    assert.equal(calls[0].options.data.nonce, 'admin-nonce');
    calls[0].options.success({count: 2});
    assert.deepEqual(await first, {count: 2});
    const second = ui.request('stop_sync', {nonce: 'stop-nonce'});
    assert.equal(calls[1].options.data.nonce, 'stop-nonce');
    calls[1].options.success(true);
    assert.equal(await second, true);
});

test('legacy adapter preserves repeated SKU fields and encoded config data', async () => {
    const {ui, calls} = setup();
    const data = new URLSearchParams({action: 'sync_skus', config: '{"name":"A&B=1"}'});
    data.append('skus[]', 'A&B');
    data.append('skus[]', 'C D');
    const pending = ui.response(data);
    assert.deepEqual(calls[0].options.data.skus, ['A&B', 'C D']);
    assert.equal(calls[0].options.data.config, '{"name":"A&B=1"}');
    assert.equal(calls[0].options.data.action, undefined);
    calls[0].options.success({queued: 2});
    assert.deepEqual(await pending, {success: true, data: {queued: 2}});
});

test('request errors reject for controllers while legacy handlers receive a failure envelope', async () => {
    const {ui, calls} = setup();
    const request = ui.request('save');
    calls[0].options.error([{message: 'Permission denied'}]);
    await assert.rejects(request, {message: 'Permission denied'});
    const legacy = ui.response({action: 'save'});
    calls[1].options.error({status: 503});
    assert.deepEqual(await legacy, {success: false, data: {message: 'Request failed'}});
    const expired = ui.request('save');
    calls[2].options.error('-1');
    await assert.rejects(expired, {message: 'Request failed'});
});

test('notice renders untrusted text safely with accessible error and dismissal', () => {
    const {ui, root} = setup();
    const notice = ui.notice(root, '<img src=x onerror=alert(1)>', 'error');
    assert.equal(root.children.length, 1);
    assert.equal(notice.className, 'notice notice-error inline is-dismissible');
    assert.equal(notice.attributes.role, 'alert');
    assert.equal(notice.children[0].textContent, '<img src=x onerror=alert(1)>');
    notice.children[1].listeners.click();
    assert.equal(notice.removed, true);
});
