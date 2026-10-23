const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('admin/js/admin-scripts.js', 'utf8');

function classList(initial = []) {
    const set = new Set(initial);
    return { add: c => set.add(c), remove: c => set.delete(c), contains: c => set.has(c), set };
}

function checkbox(value, checked = false) {
    const item = { classList: classList() };
    return { type: 'checkbox', value, checked, closest: sel => (sel === '.wc-mss-checkbox-item' ? item : null), item };
}

function container(boxes) {
    return {
        querySelectorAll(selector) {
            return selector.includes(':checked') ? boxes.filter(b => b.checked) : boxes;
        }
    };
}

/** Load the script with a tiny DOM; returns the window plus hooks to inspect it. */
function load({ elements = {}, selectedCounts = {}, storeData, saveButton = null } = {}) {
    const listeners = {};
    const documentListeners = {};
    const window = {
        location: {},
        onbeforeunload: () => 'leave?',
        addEventListener(name, fn, capture) { (listeners[name] ||= []).push({ fn, capture }); }
    };
    const notices = [];
    const document = {
        addEventListener(name, fn) { (documentListeners[name] ||= []).push(fn); },
        getElementById: id => elements[id] || null,
        querySelector(selector) {
            if (selector.includes('button-primary')) return saveButton;
            const match = /data-target="([^"]+)"/.exec(selector);
            return match ? selectedCounts[match[1]] || null : null;
        },
        querySelectorAll: () => []
    };
    const sandbox = {
        window, document,
        wp: { i18n: { __: s => s, sprintf: (fmt, ...args) => {
            let i = 0;
            return fmt.replace(/%(\d\$)?d/g, () => String(args[i++]));
        } } },
        wcMssAdmin: { i18n: {} }, wcMssAdminUI: {}, wcMssNotice: (...args) => notices.push(args)
    };
    if (storeData) sandbox.wcMssStoreData = storeData;
    vm.runInNewContext(source, sandbox);
    return { window, notices, listeners, documentListeners };
}

test('wcMssSelectAll checks every box, marks items and updates the count', () => {
    const boxes = [checkbox('1'), checkbox('2'), checkbox('3')];
    const counter = { textContent: '' };
    const { window } = load({
        elements: { grid: container(boxes) },
        selectedCounts: { grid: counter }
    });

    window.wcMssSelectAll('grid');

    assert.ok(boxes.every(b => b.checked));
    assert.ok(boxes.every(b => b.item.classList.contains('selected')));
    assert.equal(counter.textContent, '3 selected');
});

test('wcMssDeselectAll clears boxes, selection class and count', () => {
    const boxes = [checkbox('1', true), checkbox('2', true)];
    boxes.forEach(b => b.item.classList.add('selected'));
    const counter = { textContent: '2 selected' };
    const { window } = load({ elements: { grid: container(boxes) }, selectedCounts: { grid: counter } });

    window.wcMssDeselectAll('grid');

    assert.ok(boxes.every(b => !b.checked));
    assert.ok(boxes.every(b => !b.item.classList.contains('selected')));
    assert.equal(counter.textContent, '0 selected');
});

test('select/deselect all ignore unknown containers', () => {
    const { window } = load();
    assert.doesNotThrow(() => window.wcMssSelectAll('missing'));
    assert.doesNotThrow(() => window.wcMssDeselectAll('missing'));
});

test('select all tolerates checkboxes outside a .wc-mss-checkbox-item', () => {
    const orphan = { type: 'checkbox', value: '1', checked: false, closest: () => null };
    const { window } = load({ elements: { grid: container([orphan]) } });

    window.wcMssSelectAll('grid');

    assert.equal(orphan.checked, true);
});

test('sync preview shows the total when nothing is excluded', () => {
    const preview = { textContent: '' };
    const cats = container([checkbox('7', false)]);
    const { window } = load({
        elements: { add_exclude_categories: cats, add_exclude_tags: container([]), add_sync_preview: preview },
        storeData: { totalProducts: 100, categoryProducts: { 7: 30 }, tagProducts: {} }
    });

    window.wcMssDeselectAll('add_exclude_categories');

    assert.equal(preview.textContent, '100 products will be synced');
});

test('sync preview subtracts excluded categories and tags and never goes negative', () => {
    const preview = { textContent: '' };
    const cats = container([checkbox('7'), checkbox('8')]);
    const tags = container([checkbox('3')]);
    const elements = { edit_exclude_categories: cats, edit_exclude_tags: tags, edit_sync_preview: preview };
    const { window } = load({
        elements,
        storeData: { totalProducts: 100, categoryProducts: { 7: 30, 8: 20 }, tagProducts: { 3: 10 } }
    });

    window.wcMssSelectAll('edit_exclude_categories');
    assert.equal(preview.textContent, 'Approximately 50 of 100 products will be synced');

    window.wcMssSelectAll('edit_exclude_tags');
    assert.equal(preview.textContent, 'Approximately 40 of 100 products will be synced');

    // Overlapping categories can over-count; the estimate is clamped at zero.
    const overshoot = load({
        elements: { add_exclude_categories: container([checkbox('1')]), add_exclude_tags: container([]), add_sync_preview: preview },
        storeData: { totalProducts: 10, categoryProducts: { 1: 50 }, tagProducts: {} }
    });
    overshoot.window.wcMssSelectAll('add_exclude_categories');
    assert.equal(preview.textContent, 'Approximately 0 of 10 products will be synced');
});

test('sync preview picks the edit_ or add_ form from the container id', () => {
    const addPreview = { textContent: 'unchanged' };
    const editPreview = { textContent: 'unchanged' };
    const { window } = load({
        elements: {
            add_exclude_categories: container([checkbox('1')]), add_exclude_tags: container([]), add_sync_preview: addPreview,
            edit_exclude_categories: container([checkbox('1')]), edit_exclude_tags: container([]), edit_sync_preview: editPreview
        },
        storeData: { totalProducts: 5, categoryProducts: { 1: 1 }, tagProducts: {} }
    });

    window.wcMssSelectAll('edit_exclude_categories');

    assert.equal(addPreview.textContent, 'unchanged');
    assert.match(editPreview.textContent, /^Approximately 4 of 5/);
});

test('missing wcMssStoreData skips the preview without errors', () => {
    const preview = { textContent: 'unchanged' };
    const { window } = load({ elements: { add_exclude_categories: container([checkbox('1')]), add_sync_preview: preview } });

    window.wcMssSelectAll('add_exclude_categories');

    assert.equal(preview.textContent, 'unchanged');
});

test('wcMssSafeNavigate drops the unload prompt, blocks later beforeunload handlers and navigates', () => {
    const { window, listeners } = load();

    window.wcMssSafeNavigate('https://example.test/next');

    assert.equal(window.onbeforeunload, null);
    assert.equal(window.location.href, 'https://example.test/next');
    const guard = listeners.beforeunload[0];
    assert.equal(guard.capture, true, 'must run in the capture phase to beat other handlers');
    let stopped = false;
    guard.fn({ stopImmediatePropagation() { stopped = true; } });
    assert.ok(stopped);
});

test('Ctrl/Cmd+S clicks the save button and shows an info notice; other keys do nothing', () => {
    const save = { clicked: 0, click() { this.clicked++; } };
    const { documentListeners, notices } = load({ saveButton: save });

    // Boot the page: the script registers everything inside its DOMContentLoaded callback.
    documentListeners.DOMContentLoaded[0]();
    const keydown = documentListeners.keydown[0];

    let prevented = 0;
    const press = (key, mods) => keydown({ key, ...mods, preventDefault() { prevented++; } });

    press('s', { ctrlKey: true });
    press('s', { metaKey: true });
    press('s', {});
    press('a', { ctrlKey: true });

    assert.equal(save.clicked, 2);
    assert.equal(prevented, 2, 'Only the save shortcut may suppress the browser default');
    assert.deepEqual(notices.map(n => n[2]), ['info', 'info']);
});

test('Ctrl+S without a save button still prevents the browser save dialog but does not throw', () => {
    const { documentListeners, notices } = load();
    documentListeners.DOMContentLoaded[0]();
    let prevented = false;

    assert.doesNotThrow(() => documentListeners.keydown[0]({ key: 's', ctrlKey: true, preventDefault() { prevented = true; } }));

    assert.ok(prevented);
    assert.equal(notices.length, 0);
});
