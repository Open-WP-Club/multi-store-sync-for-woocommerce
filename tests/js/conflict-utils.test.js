'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { escapeHtml, buildConflictsTable } = require('../../admin/js/conflict-utils.js');

const i18n = {
    no_conflicts: 'No conflicts found.',
    th_product: 'Product',
    th_store: 'Store',
    th_changed_fields: 'Changed Fields',
    th_detected: 'Detected',
    th_status: 'Status',
    th_actions: 'Actions',
    resolved: 'Resolved',
    unresolved: 'Unresolved',
    overwrite: 'Overwrite',
    keep_remote: 'Keep Remote',
    merge: 'Merge',
};

test('escapeHtml escapes all HTML-significant characters', () => {
    assert.equal(
        escapeHtml(`<script>alert("x")</script> & 'quote'`),
        '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;quote&#039;'
    );
});

test('escapeHtml treats null/undefined as empty string', () => {
    assert.equal(escapeHtml(null), '');
    assert.equal(escapeHtml(undefined), '');
});

test('escapeHtml coerces non-string values', () => {
    assert.equal(escapeHtml(42), '42');
});

test('buildConflictsTable renders the empty state when there are no conflicts', () => {
    const html = buildConflictsTable([], i18n);
    assert.match(html, /No conflicts found\./);
    assert.doesNotMatch(html, /<table/);
});

test('buildConflictsTable renders a row per conflict with escaped values', () => {
    const html = buildConflictsTable([
        {
            id: 7,
            product_name: '<b>Evil</b> Widget',
            product_sku: 'SKU-1',
            edit_url: 'https://example.com/edit/7',
            store_url: 'https://store-b.example.com',
            changed_fields: ['price', 'stock'],
            detected_at: '2026-08-12 10:00',
            resolved: false,
        },
    ], i18n);

    assert.match(html, /<table/);
    assert.match(html, /id="wc-mss-conflict-row-7"/);
    assert.match(html, /&lt;b&gt;Evil&lt;\/b&gt; Widget/);
    assert.doesNotMatch(html, /<b>Evil<\/b>/);
    assert.match(html, /data-resolution="overwrite"/);
    assert.match(html, /wc-mss-changed-field-tag">price</);
});

test('buildConflictsTable hides resolve actions for already-resolved conflicts', () => {
    const html = buildConflictsTable([
        {
            id: 3,
            product_name: 'Widget',
            store_url: 'https://store-b.example.com',
            changed_fields: [],
            detected_at: '2026-08-12 10:00',
            resolved: true,
            resolution: 'overwrite',
        },
    ], i18n);

    assert.doesNotMatch(html, /wc-mss-resolve-conflict-btn/);
    assert.match(html, /wc-mss-status-resolved/);
    assert.match(html, /Resolved \(overwrite\)/);
});
