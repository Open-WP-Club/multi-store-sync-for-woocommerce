const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(page = false, actions = false) {
    const elements = new Map();
    function element(id) {
        if (!elements.has(id)) elements.set(id, {
            style: {}, hidden: false, disabled: false, textContent: id, innerHTML: id,
            listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; },
            removeAttribute(name) { delete this[name]; }
        });
        return elements.get(id);
    }
    const suffix = page ? '-page' : '';
    const ids = ['start', 'cancel'].map(name => 'wc-mss-' + name + '-verification' + suffix)
        .concat(['progress', 'status', 'percent', 'bar', 'details', 'result'].map(name => 'wc-mss-verification-' + name + suffix));
    ids.forEach(element);
    if (actions) ['stop-weekly-sync', 'stop-all-sync', 'sync-stop-status', 'verification_enabled',
        'wc-mss-queue-orphans', 'wc-mss-orphan-status'].forEach(element);
    const events = new Map();
    const requests = [], notices = [], timers = new Map();
    let ready, timerId = 0, reloads = 0;
    const window = {
        wcMssRequest(action, data) {
            return new Promise((resolve, reject) => requests.push({action, data, resolve, reject}));
        },
        wcMssNotice(target, message, type) { notices.push({target, message, type}); },
        confirm() { return true; }, addEventListener(name, callback) { events.set(name, callback); }, location: {reload() { reloads++; }}
    };
    const document = {
        addEventListener(name, callback) { ready = callback; },
        getElementById(id) { return elements.get(id) || null; }, querySelectorAll() { return []; }
    };
    vm.runInNewContext(fs.readFileSync('admin/js/verification.js', 'utf8'), {
        window, document, wcMssAdmin: {stop_sync_nonce: 'stop-nonce', verification_i18n: new Proxy({}, {get: (_, key) => key})},
        setTimeout(callback) { const id = ++timerId; timers.set(id, callback); return id; },
        clearTimeout(id) { timers.delete(id); }
    });
    ready();
    return {
        requests, notices, element, timers,
        event(name) { events.get(name)(); },
        clickId(id) { element(id).listeners.click(); },
        get reloads() { return reloads; },
        click(name) { element('wc-mss-' + name + '-verification' + suffix).listeners.click(); },
        runTimer() { const [id, callback] = timers.entries().next().value; timers.delete(id); callback(); }
    };
}
const flush = () => new Promise(resolve => setImmediate(resolve));

test('resumes a pending verification and cancels it without leaving disabled controls', async () => {
    const ui = setup(true);
    ui.requests[0].resolve({status: 'pending'});
    await flush();
    assert.equal(ui.element('wc-mss-start-verification-page').style.display, 'none');
    assert.equal(ui.timers.size, 1);
    ui.click('cancel');
    assert.equal(ui.timers.size, 0);
    assert.equal(ui.requests[1].action, 'wc_mss_cancel_verification');
    ui.requests[1].resolve({message: 'Cancelled'});
    await flush();
    assert.equal(ui.element('wc-mss-verification-progress-page').hidden, true);
    assert.equal(ui.element('wc-mss-start-verification-page').disabled, false);
    assert.equal(ui.notices.at(-1).message, 'Cancelled');
});

test('polling stops on completion and weekly report reloads once', async () => {
    const ui = setup(true);
    ui.requests[0].resolve({status: 'running', percent: 20, processed: 2, total: 10});
    await flush();
    assert.equal(ui.element('wc-mss-verification-bar-page').value, 20);
    ui.runTimer();
    ui.requests[1].resolve({status: 'completed', processed: 10, discrepancies: 1});
    await flush();
    assert.equal(ui.element('wc-mss-verification-bar-page').value, 100);
    assert.equal(ui.element('wc-mss-start-verification-page').disabled, false);
    ui.runTimer();
    assert.equal(ui.reloads, 1);
    assert.equal(ui.timers.size, 0);
});

test('failed cancellation keeps polling and enables cancellation retry', async () => {
    const ui = setup();
    ui.requests[0].resolve({status: 'running', percent: 10});
    await flush();
    ui.click('cancel');
    ui.requests[1].reject(new Error('Cannot cancel'));
    await flush();
    assert.equal(ui.notices.at(-1).type, 'error');
    assert.equal(ui.element('wc-mss-cancel-verification').disabled, false);
    assert.equal(ui.timers.size, 1);
    ui.runTimer();
    assert.equal(ui.requests[2].action, 'wc_mss_get_verification_progress');
});

test('stale initial progress response cannot override a user start', async () => {
    const ui = setup();
    ui.click('start');
    ui.requests[0].resolve({status: 'running', percent: 90});
    await flush();
    assert.equal(ui.timers.size, 0);
    ui.requests[1].resolve({message: 'Scheduled'});
    await flush();
    assert.equal(ui.timers.size, 1);
    assert.equal(ui.element('wc-mss-verification-status').textContent, 'Scheduled');
});

test('request failures restore start controls and show an error notice', async () => {
    const ui = setup();
    ui.requests[0].resolve({status: 'idle'});
    await flush();
    ui.click('start');
    ui.requests[1].reject(new Error('Request failed'));
    await flush();
    assert.equal(ui.element('wc-mss-start-verification').disabled, false);
    assert.equal(ui.notices.at(-1).message, 'Request failed');
    assert.equal(ui.timers.size, 0);
});

for (const outcome of ['resolve', 'reject']) {
    test(`late cancellation ${outcome} after leaving the page cannot resume polling or change notices`, async () => {
        const ui = setup();
        ui.requests[0].resolve({status: 'running', percent: 10});
        await flush();
        ui.click('cancel');
        ui.event('pagehide');
        ui.requests[1][outcome](outcome === 'resolve' ? {message: 'Cancelled'} : new Error('Cannot cancel'));
        await flush();
        assert.equal(ui.notices.length, 0);
        assert.equal(ui.timers.size, 0);
    });
}

test('stopping weekly verification uses its nonce and ignores an in-flight progress response', async () => {
    const ui = setup(true, true);
    ui.element('verification_enabled').checked = true;
    ui.requests[0].resolve({status: 'running', percent: 10});
    await flush();
    ui.runTimer();
    ui.clickId('stop-weekly-sync');
    assert.equal(ui.requests[2].action, 'wc_mss_stop_weekly_verification');
    assert.equal(ui.requests[2].data.nonce, 'stop-nonce');
    ui.requests[2].resolve({message: 'Stopped'});
    await flush();
    ui.requests[1].resolve({status: 'running', percent: 90});
    await flush();
    assert.equal(ui.element('verification_enabled').checked, false);
    assert.equal(ui.element('wc-mss-verification-progress-page').hidden, true);
    assert.equal(ui.element('stop-weekly-sync').disabled, false);
    assert.equal(ui.timers.size, 0);
});

test('failed orphan deletion can be retried and a successful queue hides the action', async () => {
    const ui = setup(false, true);
    ui.requests[0].resolve({status: 'idle'});
    ui.clickId('wc-mss-queue-orphans');
    assert.equal(ui.requests[1].action, 'wc_mss_queue_orphans_for_deletion');
    ui.requests[1].reject(new Error('Unavailable'));
    await flush();
    assert.equal(ui.element('wc-mss-queue-orphans').disabled, false);
    assert.equal(ui.element('wc-mss-queue-orphans').hidden, false);
    assert.equal(ui.notices.at(-1).type, 'error');
    ui.clickId('wc-mss-queue-orphans');
    ui.requests[2].resolve({message: 'Queued'});
    await flush();
    assert.equal(ui.element('wc-mss-queue-orphans').hidden, true);
    assert.equal(ui.notices.at(-1).message, 'Queued');
});

test('a failed progress poll stops scheduling and restores the start control', async () => {
    const ui = setup();
    ui.requests[0].resolve({status: 'pending'});
    await flush();
    ui.runTimer();
    ui.requests[1].reject(new Error('Connection lost'));
    await flush();
    assert.equal(ui.timers.size, 0);
    assert.equal(ui.element('wc-mss-start-verification').disabled, false);
    assert.equal(ui.element('wc-mss-start-verification').style.display, '');
    assert.equal(ui.notices.at(-1).message, 'Connection lost');
});
