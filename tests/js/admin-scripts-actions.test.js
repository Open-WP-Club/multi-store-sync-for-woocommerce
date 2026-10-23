const test = require('node:test');
const assert = require('node:assert/strict');
const { createPage } = require('./helpers/fake-dom.js');

const XSS = '<img src=x onerror=alert(1)><script>alert(2)</script>';

/** A notice must carry remote text as plain text: no innerHTML writes, no injected elements. */
function assertSafeNotice(notice, expectedText) {
    assert.ok(notice, 'a notice was expected');
    assert.equal(notice.message.textContent, expectedText);
    const all = [notice.node, ...notice.node.descendants()];
    assert.ok(all.every(n => n.innerHTMLWrites.length === 0), 'notice must not use innerHTML');
    assert.ok(!all.some(n => ['IMG', 'SCRIPT'].includes(n.tagName)), 'no markup may be created from remote text');
}

/* ------------------------------------------------------------ Test connection */

const CONNECTION_HTML = `
<div class="wrap">
  <button id="wc-mss-test-connection">Test Connection</button>
  <input id="store_url" value="https://shop.test">
  <input id="consumer_key" value="ck_123">
  <input id="consumer_secret" value="cs_456">
</div>`;

test('test connection refuses to send when any credential is empty', () => {
    for (const missing of ['store_url', 'consumer_key', 'consumer_secret']) {
        const page = createPage({ html: CONNECTION_HTML });
        page.byId(missing).value = '';
        const button = page.byId('wc-mss-test-connection');

        button.click();

        assert.equal(page.requests.length, 0, missing);
        assert.equal(page.lastNotice().level, 'error');
        assert.equal(page.lastNotice().message.textContent, 'Please fill in all fields before testing the connection.');
        assert.equal(button.disabled, false);
    }
});

test('test connection tolerates credential inputs missing from the DOM', () => {
    const page = createPage({ html: '<button id="wc-mss-test-connection">Test</button>' });
    assert.doesNotThrow(() => page.byId('wc-mss-test-connection').click());
    assert.equal(page.requests.length, 0);
    assert.equal(page.lastNotice().level, 'error');
});

test('test connection posts credentials, disables the button while pending and restores it', async () => {
    const page = createPage({ html: CONNECTION_HTML });
    const button = page.byId('wc-mss-test-connection');

    const event = button.dispatch('click');
    assert.ok(event.defaultPrevented);
    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Testing...');
    assert.equal(page.requests.length, 1);
    const [req] = page.requests;
    assert.equal(req.action, 'wc_mss_test_connection');
    assert.equal(req.url, '/wp-admin/admin-ajax.php');
    assert.deepEqual(req.data, {
        nonce: 'nonce-main', store_url: 'https://shop.test', consumer_key: 'ck_123', consumer_secret: 'cs_456'
    });

    await page.ok(req, { message: 'Connection successful' });

    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Test Connection');
    assert.equal(page.lastNotice().level, 'success');
    assert.equal(page.lastNotice().message.textContent, 'Connection successful');
});

test('test connection round-trips special characters in credentials unchanged', async () => {
    const page = createPage({ html: CONNECTION_HTML });
    page.byId('consumer_secret').value = 'cs&=+ %20/é?';
    page.byId('wc-mss-test-connection').click();
    assert.equal(page.requests[0].data.consumer_secret, 'cs&=+ %20/é?');
});

test('test connection failure shows the server message as an error and restores the button', async () => {
    const page = createPage({ html: CONNECTION_HTML });
    const button = page.byId('wc-mss-test-connection');
    button.click();

    await page.fail(page.requests[0], 'Invalid consumer key');

    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().message.textContent, 'Invalid consumer key');
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Test Connection');
});

test('test connection renders remote messages as text, never as HTML', async () => {
    const page = createPage({ html: CONNECTION_HTML });
    page.byId('wc-mss-test-connection').click();
    await page.fail(page.requests[0], XSS);
    assertSafeNotice(page.lastNotice(), XSS);

    page.byId('wc-mss-test-connection').click();
    await page.ok(page.requests[1], { message: XSS });
    assertSafeNotice(page.lastNotice(), XSS);
});

/* -------------------------------------------------------------- Sync product */

const SYNC_HTML = `
<button class="wc-mss-sync-product" id="a" data-product-id="42" data-sync-type="stock">Sync stock</button>
<button class="wc-mss-sync-product" id="b" data-product-id="7">Sync</button>
<button class="wc-mss-sync-product" id="c">Orphan</button>`;

test('sync product sends id and explicit sync type, defaulting to full_product', async () => {
    const page = createPage({ html: SYNC_HTML });

    page.byId('a').click();
    page.byId('b').click();

    assert.deepEqual(page.requests.map(r => [r.action, r.data]), [
        ['wc_mss_sync_product', { nonce: 'nonce-main', product_id: '42', sync_type: 'stock' }],
        ['wc_mss_sync_product', { nonce: 'nonce-main', product_id: '7', sync_type: 'full_product' }]
    ]);
});

test('sync product without an id shows an error and sends nothing', () => {
    const page = createPage({ html: SYNC_HTML });
    page.byId('c').click();
    assert.equal(page.requests.length, 0);
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().message.textContent, 'Product ID not found.');
    assert.equal(page.byId('c').disabled, false);
});

test('sync product disables only the clicked button and restores its label on success', async () => {
    const page = createPage({ html: SYNC_HTML });
    const a = page.byId('a'), b = page.byId('b');

    a.click();
    assert.equal(a.disabled, true);
    assert.equal(a.textContent, 'Syncing...');
    assert.equal(b.disabled, false);

    await page.ok(page.requests[0], {});

    assert.equal(a.disabled, false);
    assert.equal(a.textContent, 'Sync stock');
    assert.equal(page.lastNotice().level, 'success');
    assert.equal(page.lastNotice().message.textContent, 'Product synced successfully!');
});

test('sync product ignores a second click while the first request is in flight', () => {
    const page = createPage({ html: SYNC_HTML });
    page.byId('a').click();
    page.byId('a').click();
    assert.equal(page.requests.length, 1);
});

test('sync product failure reports the server message safely and re-enables the button', async () => {
    const page = createPage({ html: SYNC_HTML });
    page.byId('a').click();

    await page.fail(page.requests[0], XSS);

    assert.equal(page.lastNotice().level, 'error');
    assertSafeNotice(page.lastNotice(), 'Sync failed: ' + XSS);
    assert.equal(page.byId('a').disabled, false);
    assert.equal(page.byId('a').textContent, 'Sync stock');
});

test('sync product script loads with no sync buttons present', () => {
    assert.doesNotThrow(() => createPage({ html: '' }));
});

/* ------------------------------------------------------------- Force sync all */

test('force sync all asks for confirmation, then posts the dedicated nonce', async () => {
    const page = createPage({
        html: '<button id="wc-mss-force-sync-all">Force</button><div id="wc-mss-force-sync-result"></div>',
        admin: { force_sync_nonce: 'force-nonce' }
    });
    const button = page.byId('wc-mss-force-sync-all');

    page.confirmAnswer = false;
    button.click();
    assert.equal(page.requests.length, 0);
    assert.deepEqual(page.confirms, ['Sync everything?']);

    page.confirmAnswer = true;
    button.click();
    assert.equal(page.requests[0].action, 'wc_mss_force_sync_all');
    assert.equal(page.requests[0].nonce, 'force-nonce');
    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Syncing...');

    await page.ok(page.requests[0], { message: 'Queued 5 products' });

    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Force');
    const result = page.byId('wc-mss-force-sync-result');
    assert.equal(result.querySelector('.wc-mss-notice-message').textContent, 'Queued 5 products');
});

test('force sync all failure is shown inside the result container', async () => {
    const page = createPage({
        html: '<button id="wc-mss-force-sync-all">Force</button><div id="wc-mss-force-sync-result"></div>'
    });
    page.byId('wc-mss-force-sync-all').click();
    await page.fail(page.requests[0], 'Queue unavailable');
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().container, undefined);
    assert.equal(page.byId('wc-mss-force-sync-result').querySelector('.wc-mss-notice-message').textContent, 'Queue unavailable');
});

/* --------------------------------------------------------------- Health check */

const HEALTH_HTML = '<button id="wc-mss-run-health-check">Run</button><div id="wc-mss-health-check-result"></div>';

test('health check uses its dedicated nonce, falling back to the main one', () => {
    const dedicated = createPage({ html: HEALTH_HTML, admin: { health_check_nonce: 'hc-nonce' } });
    dedicated.byId('wc-mss-run-health-check').click();
    assert.equal(dedicated.requests[0].action, 'wc_mss_run_health_check');
    assert.equal(dedicated.requests[0].nonce, 'hc-nonce');

    const fallback = createPage({ html: HEALTH_HTML });
    fallback.byId('wc-mss-run-health-check').click();
    assert.equal(fallback.requests[0].nonce, 'nonce-main');
});

test('health check shows progress, then a success notice and schedules a reload when all stores are healthy', async () => {
    const page = createPage({ html: HEALTH_HTML });
    const button = page.byId('wc-mss-run-health-check');
    const result = page.byId('wc-mss-health-check-result');

    button.click();
    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Running health check...');
    assert.equal(result.textContent, 'Checking all stores...');

    await page.ok(page.requests[0], { message: 'All 3 healthy', healthy_count: 3, total_count: 3 });

    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Run');
    assert.equal(page.lastNotice().level, 'success');
    assert.equal(result.querySelector('.wc-mss-notice-message').textContent, 'All 3 healthy');
    assert.equal(page.location.reloads, 0);
    assert.equal(page.timers[0].ms, 2000);
    page.runTimers();
    assert.equal(page.location.reloads, 1);
});

test('health check warns when some stores are unhealthy', async () => {
    const page = createPage({ html: HEALTH_HTML });
    page.byId('wc-mss-run-health-check').click();
    await page.ok(page.requests[0], { message: '1 of 3 healthy', healthy_count: 1, total_count: 3 });
    assert.equal(page.lastNotice().level, 'warning');
});

test('health check failure surfaces the server message as an error and restores the button', async () => {
    const page = createPage({ html: HEALTH_HTML });
    page.byId('wc-mss-run-health-check').click();

    await page.fail(page.requests[0], XSS);

    assertSafeNotice(page.lastNotice(), XSS);
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.byId('wc-mss-run-health-check').disabled, false);
    assert.equal(page.timers.length, 0, 'no reload after a failed check');
});

test('health check without a result container does not throw or notify', async () => {
    const page = createPage({ html: '<button id="wc-mss-run-health-check">Run</button>' });
    page.byId('wc-mss-run-health-check').click();
    await page.ok(page.requests[0], { message: 'ok', healthy_count: 1, total_count: 1 });
    assert.equal(page.notices.length, 0);
    assert.equal(page.byId('wc-mss-run-health-check').disabled, false);
});

test('per-store check updates the health cell with text only and restores the button', async () => {
    const page = createPage({
        html: `<table><tr><td>Store</td><td>x</td><td id="cell">old</td>
               <td><button class="wc-mss-check-store-btn" data-store-url="https://s.test">Check</button></td></tr></table>`,
        admin: { single_store_nonce: 'single-nonce' }
    });
    const button = page.q('.wc-mss-check-store-btn');

    button.click();
    assert.equal(button.textContent, '...');
    assert.equal(page.requests[0].action, 'wc_mss_check_single_store');
    assert.deepEqual(page.requests[0].data, { nonce: 'single-nonce', store_url: 'https://s.test' });

    await page.ok(page.requests[0], { result: { message: XSS, checked_at: '10:00' } });

    const cell = page.byId('cell');
    assert.equal(cell.textContent, XSS + '\nLast checked: 10:00');
    assert.equal(cell.innerHTMLWrites.length, 0);
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Check');
});

test('per-store check failure shows an error notice', async () => {
    const page = createPage({
        html: `<table><tr><td></td><td></td><td id="cell">old</td>
               <td><button class="wc-mss-check-store-btn" data-store-url="https://s.test">Check</button></td></tr></table>`
    });
    page.q('.wc-mss-check-store-btn').click();
    await page.fail(page.requests[0], 'Store unreachable');
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.byId('cell').textContent, 'old');
});

/* -------------------------------------------------------- Queue filter/refresh */

test('refresh queue reloads the page', () => {
    const page = createPage({ html: '<button id="wc-mss-refresh-queue">Refresh</button>' });
    page.byId('wc-mss-refresh-queue').click();
    assert.equal(page.location.reloads, 1);
});

test('queue status filter navigates using the data-base-url, skipping the param for all/empty', () => {
    const page = createPage({
        html: `<div id="wc-mss-queue-filter" data-base-url="https://x.test/wp-admin/admin.php?page=queue"></div>
               <select id="queue_status"><option value="all">All</option><option value="failed">F</option></select>`
    });
    const select = page.byId('queue_status');
    const navigate = status => { select.value = status; select.dispatch('change'); return page.location.href; };

    assert.equal(navigate('failed'), 'https://x.test/wp-admin/admin.php?page=queue&queue_status=failed');
    assert.equal(navigate('all'), 'https://x.test/wp-admin/admin.php?page=queue');
    assert.equal(navigate(''), 'https://x.test/wp-admin/admin.php?page=queue');
    assert.equal(navigate('a&b=c'), 'https://x.test/wp-admin/admin.php?page=queue&queue_status=a%26b%3Dc');
    assert.equal(page.window.onbeforeunload, null, 'leave-site prompt must be bypassed');
});

test('queue status filter falls back to the current URL without a filter bar', () => {
    const page = createPage({ html: '<select id="queue_status"><option value="pending">p</option></select>' });
    const select = page.byId('queue_status');
    select.value = 'pending';
    select.dispatch('change');
    assert.equal(page.location.href, 'https://example.test/wp-admin/admin.php?page=wc-mss&queue_status=pending');
});

/* ------------------------------------------------------------ Dead letter queue */

const DLQ_HTML = `
<button id="wc-mss-dlq-retry-all" data-confirm="Retry every job?">Retry all</button>
<button id="wc-mss-dlq-clear-all" data-confirm="Delete every job?">Clear all</button>`;

for (const [id, action] of [['wc-mss-dlq-retry-all', 'wc_mss_dlq_retry_all'], ['wc-mss-dlq-clear-all', 'wc_mss_dlq_clear_all']]) {
    test(`${action}: declining the confirmation sends nothing`, () => {
        const page = createPage({ html: DLQ_HTML });
        page.confirmAnswer = false;
        page.byId(id).click();
        assert.equal(page.requests.length, 0);
        assert.equal(page.byId(id).disabled, false);
        assert.equal(page.confirms.length, 1);
    });

    test(`${action}: confirmed request carries only action and nonce, then notifies and reloads`, async () => {
        const page = createPage({ html: DLQ_HTML });
        page.byId(id).click();

        assert.equal(page.confirms[0], page.byId(id).dataset.confirm);
        assert.equal(page.byId(id).disabled, true);
        assert.equal(page.requests[0].action, action);
        assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main' });

        await page.ok(page.requests[0], { message: 'Done: 4 jobs' });

        assert.equal(page.lastNotice().level, 'success');
        assert.equal(page.lastNotice().message.textContent, 'Done: 4 jobs');
        assert.equal(page.location.reloads, 1);
    });

    test(`${action}: failure shows the safe error message and does not reload`, async () => {
        const page = createPage({ html: DLQ_HTML });
        page.byId(id).click();
        await page.fail(page.requests[0], XSS);
        assert.equal(page.lastNotice().level, 'error');
        assertSafeNotice(page.lastNotice(), XSS);
        assert.equal(page.location.reloads, 0);
    });

    // BUG (admin-scripts.js ~L712-727): the button is disabled before the request and never
    // re-enabled on failure, so a transient error forces a manual page reload to retry.
    test(`${action}: failure re-enables the button so the user can retry`, async () => {
        const page = createPage({ html: DLQ_HTML });
        page.byId(id).click();
        await page.fail(page.requests[0], 'Boom');
        assert.equal(page.byId(id).disabled, false);
    });
}

test('dead letter queue buttons are optional', () => {
    assert.doesNotThrow(() => createPage({ html: '' }));
});

/* ------------------------------------------------------------- Config export */

const EXPORT_HTML = `
<button id="wc-mss-export-btn" data-label-loading="Exporting..." data-success-message="Config exported">Export</button>
<input type="checkbox" id="wc-mss-export-include-keys">
<div id="wc-mss-export-result"></div>`;

test('config export sends include_keys 0/1 with the nonce and shows a loading label', async () => {
    const page = createPage({ html: EXPORT_HTML });
    const button = page.byId('wc-mss-export-btn');

    button.click();
    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Exporting...');
    assert.equal(page.requests[0].action, 'wc_mss_export_config');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', include_keys: '0' });
    await page.ok(page.requests[0], { config: {}, filename: 'x.json' });

    page.byId('wc-mss-export-include-keys').checked = true;
    button.click();
    assert.equal(page.requests[1].data.include_keys, '1');
});

test('config export downloads pretty-printed JSON, cleans up and reports success', async () => {
    const page = createPage({ html: EXPORT_HTML });
    const button = page.byId('wc-mss-export-btn');
    button.click();

    const config = { stores: [{ url: 'https://a.test', note: XSS }], version: 2 };
    await page.ok(page.requests[0], { config, filename: 'mss-config.json' });

    assert.equal(page.downloads.length, 1);
    const [download] = page.downloads;
    assert.equal(download.filename, 'mss-config.json');
    assert.equal(download.attached, true, 'anchor must be in the document when clicked (Firefox)');
    assert.equal(download.blob.type, 'application/json');
    assert.equal(download.blob.parts[0], JSON.stringify(config, null, 2));
    assert.deepEqual(page.revoked, [download.href]);
    assert.equal(page.qa('a').length, 0, 'temporary anchor is removed');
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Export');
    assert.equal(page.lastNotice().level, 'success');
    assert.equal(page.lastNotice().message.textContent, 'Config exported');
});

test('config export failure shows the server error and does not download', async () => {
    const page = createPage({ html: EXPORT_HTML });
    page.byId('wc-mss-export-btn').click();
    await page.fail(page.requests[0], XSS);
    assert.equal(page.downloads.length, 0);
    assertSafeNotice(page.lastNotice(), XSS);
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.byId('wc-mss-export-btn').disabled, false);
    assert.equal(page.byId('wc-mss-export-btn').textContent, 'Export');
});

// Robustness: the click handler dereferences the include-keys checkbox without a null check, so a
// template without it throws after the button was already disabled (L760-763).
test('config export survives a missing include-keys checkbox', async () => {
    const page = createPage({ html: '<button id="wc-mss-export-btn" data-label-loading="..." data-success-message="done">Export</button><div id="wc-mss-export-result"></div>' });
    assert.doesNotThrow(() => page.byId('wc-mss-export-btn').click());
    assert.equal(page.requests[0].data.include_keys, '0', 'keys are never included when the option is absent');
    await page.ok(page.requests[0], { config: {}, filename: 'x.json' });
    assert.equal(page.byId('wc-mss-export-btn').disabled, false);
});

/* ------------------------------------------------------------- Config import */

const IMPORT_HTML = `
<input type="file" id="wc-mss-import-file">
<button id="wc-mss-import-btn" data-confirm="Overwrite settings?" data-label-loading="Importing..." disabled>Import</button>
<div id="wc-mss-import-result"></div>`;

test('config import button follows the file selection', () => {
    const page = createPage({ html: IMPORT_HTML });
    const file = page.byId('wc-mss-import-file'), button = page.byId('wc-mss-import-btn');

    file.files = [{ text: '{}' }];
    file.dispatch('change');
    assert.equal(button.disabled, false);

    file.files = [];
    file.dispatch('change');
    assert.equal(button.disabled, true);
});

test('config import requires confirmation and a selected file', () => {
    const page = createPage({ html: IMPORT_HTML });
    const file = page.byId('wc-mss-import-file'), button = page.byId('wc-mss-import-btn');
    file.files = [{ text: '{}' }];

    page.confirmAnswer = false;
    button.disabled = false;
    button.click();
    assert.equal(page.confirms[0], 'Overwrite settings?');
    assert.equal(button.textContent, 'Import');

    page.confirmAnswer = true;
    file.files = [];
    button.click();
    assert.equal(button.disabled, false, 'no file: nothing starts');
    assert.equal(page.requests.length, 0);
});

test('config import posts the file contents verbatim, then reloads after success', async () => {
    const page = createPage({ html: IMPORT_HTML });
    const file = page.byId('wc-mss-import-file'), button = page.byId('wc-mss-import-btn');
    const text = JSON.stringify({ name: 'a&b=c+d %41', note: XSS, uni: 'é中' });
    file.files = [{ text }];
    button.disabled = false;

    button.click();
    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Importing...');
    await page.flush();

    assert.equal(page.requests[0].action, 'wc_mss_import_config');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', config: text });

    await page.ok(page.requests[0], { message: 'Imported 3 stores' });

    assert.equal(page.lastNotice().level, 'success');
    assert.equal(page.lastNotice().message.textContent, 'Imported 3 stores');
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Import');
    assert.equal(page.timers[0].ms, 1500);
    page.runTimers();
    assert.equal(page.location.reloads, 1);
});

test('config import failure shows a safe error and does not reload', async () => {
    const page = createPage({ html: IMPORT_HTML });
    page.byId('wc-mss-import-file').files = [{ text: '{bad' }];
    page.byId('wc-mss-import-btn').disabled = false;
    page.byId('wc-mss-import-btn').click();
    await page.flush();

    await page.fail(page.requests[0], XSS);

    assertSafeNotice(page.lastNotice(), XSS);
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.byId('wc-mss-import-btn').disabled, false);
    assert.equal(page.timers.length, 0);
});

test('config import reports unreadable files without sending a request', async () => {
    const page = createPage({ html: IMPORT_HTML });
    page.byId('wc-mss-import-file').files = [{ error: true }];
    page.byId('wc-mss-import-btn').disabled = false;
    page.byId('wc-mss-import-btn').click();
    await page.flush();

    assert.equal(page.requests.length, 0);
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().message.textContent, 'Request failed');
    assert.equal(page.byId('wc-mss-import-btn').disabled, false);
    assert.equal(page.byId('wc-mss-import-btn').textContent, 'Import');
});

test('config import/export are skipped when only half of the controls exist', () => {
    assert.doesNotThrow(() => createPage({ html: '<button id="wc-mss-import-btn">Import</button>' }));
});

/* ---------------------------------------------------------- Settings toggles */

const TOGGLE_HTML = `
<input type="checkbox" class="wc-mss-feature-toggle" id="cat" data-action="wc_mss_toggle_category_mapper">
<input type="checkbox" class="wc-mss-feature-toggle" id="dbg" data-action="wc_mss_toggle_debug" checked>`;

test('feature toggle posts enabled=1/0 for its own action and disables itself while pending', async () => {
    const page = createPage({ html: TOGGLE_HTML });
    const cat = page.byId('cat'), dbg = page.byId('dbg');

    cat.checked = true;
    cat.dispatch('change');
    assert.equal(cat.disabled, true);
    assert.equal(dbg.disabled, false);
    assert.equal(page.requests[0].action, 'wc_mss_toggle_category_mapper');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', enabled: '1' });

    dbg.checked = false;
    dbg.dispatch('change');
    assert.equal(page.requests[1].action, 'wc_mss_toggle_debug');
    assert.equal(page.requests[1].data.enabled, '0');
});

test('feature toggle success notifies; only tab-toggling features reload the page', async () => {
    const page = createPage({ html: TOGGLE_HTML });
    page.byId('dbg').checked = false;
    page.byId('dbg').dispatch('change');
    await page.ok(page.requests[0], { message: 'Debug off' });
    assert.equal(page.lastNotice().level, 'success');
    assert.equal(page.lastNotice().message.textContent, 'Debug off');
    assert.equal(page.byId('dbg').disabled, false);
    assert.equal(page.timers.length, 0);

    page.byId('cat').checked = true;
    page.byId('cat').dispatch('change');
    await page.ok(page.requests[1], { message: 'Mapper on' });
    assert.equal(page.timers.length, 1);
    assert.equal(page.timers[0].ms, 600);
    page.runTimers();
    assert.equal(page.location.reloads, 1);
});

test('feature toggle failure reverts the checkbox in both directions and shows a safe error', async () => {
    const page = createPage({ html: TOGGLE_HTML });
    const cat = page.byId('cat'), dbg = page.byId('dbg');

    cat.checked = true;
    cat.dispatch('change');
    await page.fail(page.requests[0], XSS);
    assert.equal(cat.checked, false);
    assert.equal(cat.disabled, false);
    assertSafeNotice(page.lastNotice(), XSS);
    assert.equal(page.lastNotice().level, 'error');

    dbg.checked = false;
    dbg.dispatch('change');
    await page.fail(page.requests[1], 'Nope');
    assert.equal(dbg.checked, true);
    assert.equal(page.timers.length, 0, 'no reload after a failed tab toggle');
});

test('feature toggle without jQuery or without toggles registers nothing', () => {
    const page = createPage({ html: TOGGLE_HTML, jquery: false });
    page.byId('cat').dispatch('change');
    assert.equal(page.requests.length, 0);
    assert.doesNotThrow(() => createPage({ html: '' }));
});

/* ------------------------------------------------- Scheduled sync visibility */

const SCHEDULE_HTML = `
<input type="checkbox" id="scheduled_sync_enabled">
<input type="radio" name="sync_all_products" id="all" value="1" checked>
<input type="radio" name="sync_all_products" id="mod" value="0">
<div class="scheduled-sync-option" id="o1"></div>
<div class="scheduled-sync-option" id="o2"></div>
<div id="sync_modified_hours_row"></div>`;

const hidden = el => el.style.display === 'none';

test('scheduled sync options show only while scheduling is enabled', () => {
    const page = createPage({ html: SCHEDULE_HTML });
    const enable = page.byId('scheduled_sync_enabled');

    enable.checked = true;
    enable.dispatch('change');
    assert.equal(hidden(page.byId('o1')) || hidden(page.byId('o2')), false);

    enable.checked = false;
    enable.dispatch('change');
    assert.ok(hidden(page.byId('o1')) && hidden(page.byId('o2')));
});

test('modified-hours row is hidden when syncing all products and shown otherwise', () => {
    const page = createPage({ html: SCHEDULE_HTML });
    const enable = page.byId('scheduled_sync_enabled'), all = page.byId('all'), mod = page.byId('mod');
    enable.checked = true;

    enable.dispatch('change');
    assert.equal(hidden(page.byId('sync_modified_hours_row')), true, 'all products selected');

    all.checked = false;
    mod.checked = true;
    mod.dispatch('change');
    assert.equal(hidden(page.byId('sync_modified_hours_row')), false);

    all.checked = true;
    mod.checked = false;
    all.dispatch('change');
    assert.equal(hidden(page.byId('sync_modified_hours_row')), true);
});

test('modified-hours row is left alone while scheduling is disabled', () => {
    const page = createPage({ html: SCHEDULE_HTML });
    page.byId('mod').checked = true;
    page.byId('all').checked = false;
    page.byId('mod').dispatch('change');
    assert.equal(page.byId('sync_modified_hours_row').style.display, undefined);
    assert.ok(hidden(page.byId('o1')));
});

test('scheduled sync toggle tolerates missing option rows and missing jQuery', () => {
    const page = createPage({ html: '<input type="checkbox" id="scheduled_sync_enabled">' });
    assert.doesNotThrow(() => page.byId('scheduled_sync_enabled').dispatch('change'));
    assert.doesNotThrow(() => createPage({ html: SCHEDULE_HTML, jquery: false }));
});

/* -------------------------------------------------------- Sync by category */

const CAT_SYNC_HTML = `
<select id="wc-mss-cat-sync-category"><option value="">-</option><option value="12">Shoes</option></select>
<select id="wc-mss-cat-sync-type"><option value="full_product">Full</option><option value="stock">Stock</option></select>
<input type="checkbox" id="wc-mss-cat-sync-children">
<button id="wc-mss-cat-sync-btn" data-msg-select-category="Pick a category" data-msg-queuing="Queuing..." data-msg-error="Could not queue">Go</button>
<div id="wc-mss-cat-sync-result"></div>`;

test('sync by category warns when no category is chosen', () => {
    const page = createPage({ html: CAT_SYNC_HTML });
    page.byId('wc-mss-cat-sync-btn').dispatch('click');
    assert.equal(page.requests.length, 0);
    assert.equal(page.lastNotice().level, 'warning');
    assert.equal(page.lastNotice().message.textContent, 'Pick a category');
});

test('sync by category posts category, type and include-children flag', async () => {
    const page = createPage({ html: CAT_SYNC_HTML });
    page.byId('wc-mss-cat-sync-category').value = '12';
    page.byId('wc-mss-cat-sync-type').value = 'stock';
    page.byId('wc-mss-cat-sync-children').checked = true;

    page.byId('wc-mss-cat-sync-btn').dispatch('click');

    assert.equal(page.byId('wc-mss-cat-sync-btn').disabled, true);
    assert.equal(page.lastNotice().message.textContent, 'Queuing...');
    assert.equal(page.requests[0].action, 'wc_mss_sync_by_category');
    assert.deepEqual(page.requests[0].data, {
        nonce: 'nonce-main', category_id: '12', sync_type: 'stock', include_children: '1'
    });

    await page.ok(page.requests[0], { message: 'Queued 9' });
    assert.equal(page.byId('wc-mss-cat-sync-btn').disabled, false);
    assert.equal(page.lastNotice().level, 'success');
});

test('sync by category failure is reported and re-enables the button', async () => {
    const page = createPage({ html: CAT_SYNC_HTML });
    page.byId('wc-mss-cat-sync-category').value = '12';
    page.byId('wc-mss-cat-sync-btn').dispatch('click');
    assert.equal(page.requests[0].data.include_children, '0');
    await page.fail(page.requests[0], XSS);
    assertSafeNotice(page.lastNotice(), XSS);
    assert.equal(page.byId('wc-mss-cat-sync-btn').disabled, false);
});

/* --------------------------------------------------------- Password toggle */

test('password toggle flips the preceding field between password and text', () => {
    const page = createPage({
        html: '<input type="password" id="pw"><button class="wc-mss-toggle-password"><span id="icon">eye</span></button><p id="other">x</p>'
    });
    const field = page.byId('pw');

    page.byId('icon').click();
    assert.equal(field.type, 'text');
    page.byId('icon').click();
    assert.equal(field.type, 'password');

    page.byId('other').click();
    assert.equal(field.type, 'password', 'unrelated clicks are ignored');
});

test('password toggle without a previous sibling does not throw', () => {
    const page = createPage({ html: '<button class="wc-mss-toggle-password" id="lonely">eye</button>' });
    assert.doesNotThrow(() => page.byId('lonely').click());
});

test('password toggles added after load still work (delegated listener)', () => {
    const page = createPage({ html: '' });
    page.dom.mount('<input type="password" id="late"><button class="wc-mss-toggle-password" id="lateBtn">e</button>');
    page.byId('lateBtn').click();
    assert.equal(page.byId('late').type, 'text');
});

/* ----------------------------------------------------- Copy / generate secret */

test('copy webhook URL buttons show the copied label then restore, and report clipboard failure', async () => {
    const page = createPage({ html: '<button class="copy-webhook-url" data-url="https://s.test/hook">Copy</button>' });
    const button = page.q('.copy-webhook-url');

    button.click();
    await page.flush();
    assert.deepEqual(page.clipboard.written, ['https://s.test/hook']);
    assert.equal(button.textContent, 'Copied!');
    page.runTimers();
    assert.equal(button.textContent, 'Copy');

    page.clipboard.fail = true;
    button.click();
    await page.flush();
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().message.textContent, 'Copy failed');
});

test('generate secret fills a 32 character value and is optional', () => {
    const page = createPage({ html: '<button id="generate-secret">Gen</button><input id="webhook_secret">' });
    page.byId('generate-secret').click();
    assert.equal(page.byId('webhook_secret').value.length, 32);
    assert.match(page.byId('webhook_secret').value, /^[A-Za-z0-9!@#$%^&*]{32}$/);

    const bare = createPage({ html: '<button id="generate-secret">Gen</button>' });
    assert.doesNotThrow(() => bare.byId('generate-secret').click());
});
