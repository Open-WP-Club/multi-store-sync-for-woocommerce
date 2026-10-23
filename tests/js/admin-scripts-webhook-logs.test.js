const test = require('node:test');
const assert = require('node:assert/strict');
const { createPage } = require('./helpers/fake-dom.js');

const XSS = '<img src=x onerror=alert(1)><script>alert(2)</script>';
const OPEN_HTML = `
<button id="view-webhook-logs">View</button>
<button id="export-webhook-logs">Export</button>`;

const tableHtml = '<table><tbody>'
    + '<tr><td>1</td><td><button class="view-webhook-detail" data-id="9">Details</button></td></tr>'
    + '<tr><td>2</td><td><button class="view-webhook-detail" data-id="10">Details</button></td></tr>'
    + '</tbody></table>';

function openViewer(options = {}) {
    const page = createPage({ html: OPEN_HTML, ...options });
    page.byId('view-webhook-logs').click();
    return page;
}

/** Answer the pending list request (and optionally the stats request that follows). */
async function loadLogs(page, data, stats) {
    const req = page.reqs('wc_mss_get_webhook_logs').pop();
    await page.ok(req, data);
    if (stats) await page.ok(page.reqs('wc_mss_get_webhook_stats').pop(), stats);
    return req;
}

test('opening the viewer builds the modal once, opens a dialog and loads page 1 with empty filters', () => {
    const page = openViewer();

    const modal = page.byId('wc-mss-webhook-logs-modal');
    assert.ok(modal && modal.parentNode === page.dom.body);
    assert.equal(page.dom.dialogCalls.length, 2);
    assert.equal(page.dom.dialogCalls[0][0].autoOpen, false);
    assert.equal(page.dom.dialogCalls[0][0].width, 1200);
    assert.equal(page.dom.dialogCalls[0][0].title, 'Webhook Logs');
    assert.deepEqual(page.dom.dialogCalls[1], ['open']);
    assert.equal(page.byId('webhook-logs-container').textContent, 'Loading...');

    const [req] = page.requests;
    assert.equal(req.action, 'wc_mss_get_webhook_logs');
    assert.deepEqual(req.data, {
        nonce: 'nonce-main', page: '1', per_page: '50', log_type: '', status: '', product_sku: '', date_from: '', date_to: ''
    });

    page.byId('view-webhook-logs').click();
    assert.equal(page.qa('#wc-mss-webhook-logs-modal').length, 1, 'modal is reused');
    assert.equal(page.reqs('wc_mss_get_webhook_logs').length, 2);
    assert.deepEqual(page.dom.dialogCalls.at(-1), ['open']);
});

test('modal width never exceeds the viewport', () => {
    const page = createPage({ html: OPEN_HTML });
    page.window.innerWidth = 500;
    page.byId('view-webhook-logs').click();
    assert.equal(page.dom.dialogCalls[0][0].width, 460);
});

test('filters are read from the form and sent when Filter is clicked', () => {
    const page = openViewer();
    page.byId('webhook-log-type-filter').value = 'stock_synced';
    page.byId('webhook-log-status-filter').value = 'failed';
    page.byId('webhook-log-sku-filter').value = 'SKU-1&x=2';
    page.byId('webhook-log-date-from').value = '2025-01-01';
    page.byId('webhook-log-date-to').value = '2025-01-31';

    page.byId('apply-webhook-filters').click();

    assert.deepEqual(page.requests.at(-1).data, {
        nonce: 'nonce-main', page: '1', per_page: '50', log_type: 'stock_synced', status: 'failed',
        product_sku: 'SKU-1&x=2', date_from: '2025-01-01', date_to: '2025-01-31'
    });
});

test('the type select offers every webhook log type used by the server', () => {
    const page = openViewer();
    const values = page.byId('webhook-log-type-filter').querySelectorAll('option').map(o => o.value);
    assert.deepEqual(values, ['', 'order_received', 'stock_deducted', 'stock_synced', 'auth_failed', 'validation_error', 'product_not_found', 'rate_limited']);
});

test('a successful list response renders the table, hides pagination for a single page and loads stats', async () => {
    const page = openViewer();
    await loadLogs(page, { table_html: tableHtml, page: 1, pages: 1, total: 2 });

    const container = page.byId('webhook-logs-container');
    assert.equal(container.innerHTMLWrites.at(-1), tableHtml);
    assert.equal(container.querySelectorAll('tr').length, 2);
    assert.equal(page.byId('webhook-logs-pagination').innerHTML, '');
    const stats = page.reqs('wc_mss_get_webhook_stats')[0];
    assert.deepEqual(stats.data, { nonce: 'nonce-main', days: '30' });

    await page.ok(stats, {
        type_counts: { order_received: 3, stock_deducted: 2 },
        status_counts: { failed: 1 },
        stock_stats: { total_quantity_changed: 7 }
    });
    assert.equal(page.byId('webhook-logs-stats').textContent,
        'Last 30 days: 5 events | Stock changes: 2 | Units changed: 7 | Errors: 1');
    assert.equal(page.byId('webhook-logs-stats').innerHTMLWrites.length, 0);
});

test('stats default missing counters to zero', async () => {
    const page = openViewer();
    await loadLogs(page, { table_html: '', page: 1, pages: 1, total: 0 }, { type_counts: {}, status_counts: {} });
    assert.equal(page.byId('webhook-logs-stats').textContent,
        'Last 30 days: 0 events | Stock changes: 0 | Units changed: 0 | Errors: 0');
});

test('a failed list request shows a generic error inside the container and does not request stats', async () => {
    const page = openViewer();
    await page.fail(page.requests[0], XSS);
    const message = page.byId('webhook-logs-container').querySelector('.wc-mss-notice-message');
    assert.equal(message.textContent, 'Request failed');
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.reqs('wc_mss_get_webhook_stats').length, 0);
});

test('pagination shows page info, both buttons in the middle, and navigates keeping the filters', async () => {
    const page = openViewer();
    page.byId('webhook-log-status-filter').value = 'failed';
    page.byId('apply-webhook-filters').click();
    await loadLogs(page, { table_html: tableHtml, page: 1, pages: 3, total: 120 });
    page.byId('webhook-logs-pagination').querySelector('button[data-page="2"]').click();
    await loadLogs(page, { table_html: tableHtml, page: 2, pages: 3, total: 120 });

    const pagination = page.byId('webhook-logs-pagination');
    assert.equal(pagination.querySelector('span').textContent, 'Page 2 of 3 (120 records)');
    assert.deepEqual(pagination.querySelectorAll('button').map(b => [b.textContent, b.dataset.page]),
        [['Previous', '1'], ['Next', '3']]);

    pagination.querySelector('button[data-page="3"]').click();
    const next = page.requests.at(-1);
    assert.equal(next.action, 'wc_mss_get_webhook_logs');
    assert.equal(next.data.page, '3');
    assert.equal(next.data.status, 'failed');
});

test('pagination hides Previous on the first page and Next on the last', async () => {
    const page = openViewer();
    await loadLogs(page, { table_html: '', page: 1, pages: 2, total: 51 });
    assert.deepEqual(page.byId('webhook-logs-pagination').querySelectorAll('button').map(b => b.textContent), ['Next']);

    page.byId('view-webhook-logs').click();
    const second = page.reqs('wc_mss_get_webhook_logs').pop();
    await page.ok(second, { table_html: '', page: 1, pages: 2, total: 51 });
    page.byId('webhook-logs-pagination').querySelector('button').click();
    await loadLogs(page, { table_html: '', page: 2, pages: 2, total: 51 });
    assert.deepEqual(page.byId('webhook-logs-pagination').querySelectorAll('button').map(b => b.textContent), ['Previous']);
});

test('pagination text is escaped and built only from numbers and labels', async () => {
    const page = openViewer();
    await loadLogs(page, { table_html: '', page: 1, pages: 2, total: '9<script>' });
    const html = page.byId('webhook-logs-pagination').innerHTMLWrites.at(-1);
    assert.ok(!html.includes('<script>'));
    assert.equal(page.byId('webhook-logs-pagination').querySelectorAll('script').length, 0);
});

/* ---------------------------------------------------------------- detail */

async function openDetail(logData) {
    const page = openViewer();
    await loadLogs(page, { table_html: tableHtml, page: 1, pages: 1, total: 2 });
    page.qa('.view-webhook-detail')[0].click();
    const req = page.reqs('wc_mss_get_webhook_log_detail')[0];
    if (logData) await page.ok(req, logData);
    return { page, req };
}

test('clicking a row detail button requests that log id', async () => {
    const { page, req } = await openDetail();
    assert.deepEqual(req.data, { nonce: 'nonce-main', log_id: '9' });
    page.qa('.view-webhook-detail')[1].click();
    assert.equal(page.reqs('wc_mss_get_webhook_log_detail')[1].data.log_id, '10');
});

test('detail shows every field, a dash for null values, and the JSON request data as plain text', async () => {
    const { page } = await openDetail({
        id: 9, created_at: '2025-01-02 10:00:00', type_label: 'Stock deducted', status: 'failed',
        store_url: 'https://s.test', remote_order_id: null, product_id: 5, product_sku: 'SKU-1',
        old_stock: 10, new_stock: 8, quantity_changed: -2, request_ip: '10.0.0.1', change_reason: 'order',
        error_message: 'Out of stock', request_data: { items: [1, 2] }
    });

    const notice = page.lastNotice();
    assert.equal(notice.level, 'info');
    const text = notice.message.textContent;
    assert.match(text, /^ID: 9\nDate: 2025-01-02 10:00:00\nType: Stock deducted\nStatus: failed\nStore: https:\/\/s\.test\nOrder: —\n/);
    assert.match(text, /SKU: SKU-1\nPrevious stock: 10\nCurrent stock: 8\nChange: -2\nIP: 10\.0\.0\.1\nReason: order\nOut of stock\n\{/);
    assert.ok(text.endsWith(JSON.stringify({ items: [1, 2] }, null, 2)));
    assert.equal(notice.node.parentNode, page.byId('wc-mss-webhook-notices'));
});

test('detail renders attacker-controlled log fields as text only', async () => {
    const { page } = await openDetail({
        id: 9, store_url: XSS, product_sku: XSS, request_ip: XSS, error_message: XSS, request_data: { evil: XSS }
    });
    const notice = page.lastNotice();
    const all = [notice.node, ...notice.node.descendants()];
    assert.ok(notice.message.textContent.includes(XSS));
    assert.ok(all.every(n => n.innerHTMLWrites.length === 0));
    assert.equal(all.filter(n => ['IMG', 'SCRIPT'].includes(n.tagName)).length, 0);
});

// BUG (admin-scripts.js ~L504-L535): showWebhookLogDetail has no else branch, so when the request
// fails (expired nonce, deleted log, network error) the user clicks "Details" and nothing happens.
test('detail shows an error when the log cannot be loaded', async () => {
    const { page, req } = await openDetail();
    await page.fail(req, 'Log not found');
    assert.equal(page.lastNotice().level, 'error');
});

/* ---------------------------------------------------------------- delete */

function deleteButton(page, type, days) {
    return page.qa('.delete-webhook-logs').find(b => b.dataset.type === type && (days === undefined || b.dataset.days === String(days)));
}

test('each delete button asks its own confirmation question', () => {
    const page = openViewer();
    page.confirmAnswer = false;
    const before = page.requests.length;

    [['all'], ['errors'], ['success'], ['older_than', 30], ['older_than', 7]].forEach(([type, days]) => deleteButton(page, type, days).click());

    assert.deepEqual(page.confirms, [
        'Delete all webhook logs?',
        'Delete all failed webhook logs?',
        'Delete all successful webhook logs?',
        'Delete webhook logs older than 30 days?',
        'Delete webhook logs older than 7 days?'
    ]);
    assert.equal(page.requests.length, before, 'declined confirmations send nothing');
});

test('confirmed delete sends delete_type and days only for the age-based buttons', () => {
    const page = openViewer();
    deleteButton(page, 'errors').click();
    assert.deepEqual(page.requests.at(-1).data, { nonce: 'nonce-main', delete_type: 'errors' });
    assert.equal(page.requests.at(-1).action, 'wc_mss_delete_webhook_logs');

    deleteButton(page, 'older_than', 30).click();
    assert.deepEqual(page.requests.at(-1).data, { nonce: 'nonce-main', delete_type: 'older_than', days: '30' });

    deleteButton(page, 'all').click();
    assert.equal(page.requests.at(-1).data.delete_type, 'all');
    assert.ok(!('days' in page.requests.at(-1).data));
});

test('successful delete reports the remaining records and reloads page 1', async () => {
    const page = openViewer();
    deleteButton(page, 'success').click();
    const before = page.reqs('wc_mss_get_webhook_logs').length;

    await page.ok(page.requests.at(-1), { message: 'Deleted 4', remaining: 11 });

    assert.equal(page.lastNotice().message.textContent, 'Deleted 4\nRemaining records: 11');
    assert.equal(page.reqs('wc_mss_get_webhook_logs').length, before + 1);
    assert.equal(page.reqs('wc_mss_get_webhook_logs').at(-1).data.page, '1');
});

test('failed delete reports the server message safely and does not reload the table', async () => {
    const page = openViewer();
    deleteButton(page, 'all').click();
    const before = page.reqs('wc_mss_get_webhook_logs').length;

    await page.fail(page.requests.at(-1), XSS);

    const notice = page.lastNotice();
    assert.equal(notice.message.textContent, 'Error: ' + XSS);
    assert.equal(notice.node.descendants().filter(n => n.tagName === 'IMG').length, 0);
    assert.equal(page.reqs('wc_mss_get_webhook_logs').length, before);
});

// BUG (admin-scripts.js ~L593): the failure branch calls wcMssNotice(null, "Error: ...") without
// a type, and wcMssNotice defaults to "success", so a failed delete is rendered as a green notice.
test('failed delete is displayed with the error style', async () => {
    const page = openViewer();
    deleteButton(page, 'all').click();
    await page.fail(page.requests.at(-1), 'Denied');
    assert.equal(page.lastNotice().level, 'error');
});

/* ---------------------------------------------------------------- export */

const base64 = text => Buffer.from(text, 'utf8').toString('base64');

test('export sends the same filters the list shows, even before the modal was opened', () => {
    const page = createPage({ html: OPEN_HTML });
    page.byId('export-webhook-logs').click();
    assert.equal(page.requests[0].action, 'wc_mss_export_webhook_logs');
    assert.deepEqual(page.requests[0].data, {
        nonce: 'nonce-main', log_type: '', status: '', product_sku: '', date_from: '', date_to: ''
    });

    const opened = openViewer();
    opened.byId('webhook-log-type-filter').value = 'auth_failed';
    opened.byId('webhook-log-status-filter').value = 'failed';
    opened.byId('webhook-log-sku-filter').value = 'ABC-1';
    opened.byId('webhook-log-date-from').value = '2025-02-01';
    opened.byId('webhook-log-date-to').value = '2025-02-28';
    opened.byId('export-webhook-logs').click();
    assert.deepEqual(opened.requests.at(-1).data, {
        nonce: 'nonce-main', log_type: 'auth_failed', status: 'failed', product_sku: 'ABC-1',
        date_from: '2025-02-01', date_to: '2025-02-28'
    });
});

test('export decodes the base64 CSV into a downloaded file and cleans up', async () => {
    const page = createPage({ html: OPEN_HTML });
    page.byId('export-webhook-logs').click();

    await page.ok(page.requests[0], { csv_content: base64('id,sku\n1,A1\n'), filename: 'logs-2025.csv' });

    assert.equal(page.downloads.length, 1);
    const [download] = page.downloads;
    assert.equal(download.filename, 'logs-2025.csv');
    assert.equal(download.blob.type, 'text/csv;charset=utf-8;');
    assert.equal(Buffer.from(download.blob.parts[0]).toString('utf8'), 'id,sku\n1,A1\n');
    assert.equal(download.attached, true);
    assert.equal(page.qa('a').length, 0);
    assert.deepEqual(page.revoked, [download.href]);
});

test('export falls back to a default filename', async () => {
    const page = createPage({ html: OPEN_HTML });
    page.byId('export-webhook-logs').click();
    await page.ok(page.requests[0], { csv_content: base64('a') });
    assert.equal(page.downloads[0].filename, 'webhook-logs.csv');
});

test('export without content or on server failure shows an error in the modal notice area', async () => {
    const page = openViewer();
    page.byId('export-webhook-logs').click();
    await page.ok(page.requests.at(-1), { csv_content: '' });
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().message.textContent, 'Export failed');
    assert.equal(page.lastNotice().node.parentNode, page.byId('wc-mss-webhook-notices'));
    assert.equal(page.downloads.length, 0);

    page.byId('export-webhook-logs').click();
    await page.fail(page.requests.at(-1), 'ignored');
    assert.equal(page.lastNotice().message.textContent, 'Export failed');
});

test('export without the modal still reports failures (default notice container)', async () => {
    const page = createPage({ html: OPEN_HTML });
    page.byId('export-webhook-logs').click();
    await page.ok(page.requests[0], {});
    assert.equal(page.lastNotice().level, 'error');
    assert.ok(page.byId('wc-mss-notices'));
});

// BUG (admin-scripts.js ~L560-L563): atob() yields a binary (Latin-1) string and new Blob([string])
// re-encodes it as UTF-8, so every non-ASCII byte (product names, SKUs, reasons) is double encoded.
test('export preserves UTF-8 bytes of the CSV', async () => {
    const page = createPage({ html: OPEN_HTML });
    page.byId('export-webhook-logs').click();
    const original = 'id,name\n1,Müller 中文\n';
    await page.ok(page.requests[0], { csv_content: base64(original) });
    const written = Buffer.from(page.downloads[0].blob.parts[0]).toString('utf8');
    assert.equal(written, original);
});

test('webhook log buttons are optional', () => {
    assert.doesNotThrow(() => createPage({ html: '' }));
    assert.doesNotThrow(() => createPage({ html: '<button id="export-webhook-logs">x</button>' }).byId('export-webhook-logs').click());
});
