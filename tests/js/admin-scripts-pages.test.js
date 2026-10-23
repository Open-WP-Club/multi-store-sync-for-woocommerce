const test = require('node:test');
const assert = require('node:assert/strict');
const { createPage } = require('./helpers/fake-dom.js');

const XSS = '<img src=x onerror=alert(1)><script>alert(2)</script>';
const noMarkup = root => root.descendants().filter(n => ['IMG', 'SCRIPT'].includes(n.tagName)).length === 0;

/* ===================================================== Sync profiles page */

const PROFILES_HTML = `
<div class="wc-mss-sync-profiles">
  <input id="wc-mss-profile-name" value="  Night sync  ">
  <input id="wc-mss-profile-description" value="Runs at 2am">
  <button id="wc-mss-save-profile-btn">Save</button>
  <button class="wc-mss-apply-preset" id="preset" data-preset="minimal" data-name="Minimal">Apply</button>
  <button class="wc-mss-apply-profile" id="apply" data-id="5" data-name="Night">Apply</button>
  <button class="wc-mss-export-profile" id="export" data-id="a b&c">Export</button>
  <button class="wc-mss-delete-profile" id="delete" data-id="5" data-name="Night">Delete</button>
  <table><tr id="wc-mss-profile-row-5"><td>Night</td></tr></table>
  <div id="wc-mss-profiles-notices"></div>
</div>`;
const PROFILE_I18N = {
    enter_name: 'Enter a name', saving: 'Saving...', save_profile: 'Save profile', error_saving: 'Error saving',
    confirm_apply_preset: 'Apply preset %s?', error_applying_preset: 'Preset failed',
    confirm_apply_profile: 'Apply profile %s?', error_applying_profile: 'Profile failed',
    confirm_delete_profile: 'Delete profile %s?', error_deleting_profile: 'Delete failed'
};
const profilePage = () => createPage({ html: PROFILES_HTML, globals: { wcMssSyncProfilesData: { i18n: PROFILE_I18N } } });
const profileNotice = page => page.byId('wc-mss-profiles-notices').querySelector('.wc-mss-notice-message');

test('profile save requires a non-blank name', () => {
    const page = profilePage();
    page.byId('wc-mss-profile-name').value = '   ';
    page.byId('wc-mss-save-profile-btn').dispatch('click');
    assert.equal(page.requests.length, 0);
    assert.equal(profileNotice(page).textContent, 'Enter a name');
    assert.equal(page.lastNotice().level, 'error');
});

test('profile save posts the trimmed name and description, then reloads', async () => {
    const page = profilePage();
    const button = page.byId('wc-mss-save-profile-btn');

    button.dispatch('click');
    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Saving...');
    assert.equal(page.requests[0].action, 'wc_mss_profile_save');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', name: 'Night sync', description: 'Runs at 2am' });

    await page.ok(page.requests[0], { message: 'Profile saved' });
    assert.equal(profileNotice(page).textContent, 'Profile saved');
    assert.equal(page.lastNotice().level, 'success');
    assert.equal(page.timers[0].ms, 1000);
    page.runTimers();
    assert.equal(page.location.reloads, 1);
});

test('profile save failure restores the button and shows a safe error', async () => {
    const page = profilePage();
    const button = page.byId('wc-mss-save-profile-btn');
    button.dispatch('click');
    await page.fail(page.requests[0], XSS);
    assert.equal(profileNotice(page).textContent, XSS);
    assert.ok(noMarkup(page.byId('wc-mss-profiles-notices')));
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Save profile');
    assert.equal(page.timers.length, 0);
});

test('apply preset confirms with the preset name, sends preset_key and re-enables the button', async () => {
    const page = profilePage();
    page.confirmAnswer = false;
    page.byId('preset').dispatch('click');
    assert.deepEqual(page.confirms, ['Apply preset Minimal?']);
    assert.equal(page.requests.length, 0);
    assert.equal(page.byId('preset').disabled, false);

    page.confirmAnswer = true;
    page.byId('preset').dispatch('click');
    assert.equal(page.byId('preset').disabled, true);
    assert.equal(page.requests[0].action, 'wc_mss_profile_apply');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', preset_key: 'minimal' });

    await page.ok(page.requests[0], { message: 'Applied' });
    assert.equal(profileNotice(page).textContent, 'Applied');
    assert.equal(page.byId('preset').disabled, false);
});

test('apply preset failure shows the server message (or fallback) as an error', async () => {
    const page = profilePage();
    page.byId('preset').dispatch('click');
    await page.fail(page.requests[0], 'Not allowed');
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(profileNotice(page).textContent, 'Not allowed');
    assert.equal(page.byId('preset').disabled, false);
});

test('apply saved profile sends profile_id and handles failure', async () => {
    const page = profilePage();
    page.byId('apply').dispatch('click');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', profile_id: '5' });
    assert.deepEqual(page.confirms, ['Apply profile Night?']);

    await page.fail(page.requests[0], XSS);
    assert.equal(profileNotice(page).textContent, XSS);
    assert.ok(noMarkup(page.byId('wc-mss-profiles-notices')));
    assert.equal(page.byId('apply').disabled, false);
});

test('export profile navigates to the AJAX URL with an encoded profile id and the nonce', () => {
    const page = profilePage();
    page.byId('export').dispatch('click');
    assert.equal(page.location.href,
        '/wp-admin/admin-ajax.php?action=wc_mss_export_config&nonce=nonce-main&profile_id=a%20b%26c');
});

test('delete profile confirms, removes the row on success and keeps it on failure', async () => {
    const page = profilePage();
    page.confirmAnswer = false;
    page.byId('delete').dispatch('click');
    assert.equal(page.requests.length, 0);

    page.confirmAnswer = true;
    page.byId('delete').dispatch('click');
    assert.equal(page.byId('delete').disabled, true);
    assert.equal(page.requests[0].action, 'wc_mss_profile_delete');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', profile_id: '5' });
    await page.fail(page.requests[0], 'Locked');
    assert.equal(page.byId('wc-mss-profile-row-5').removed, undefined);
    assert.equal(page.byId('delete').disabled, false);
    assert.equal(page.lastNotice().level, 'error');

    page.byId('delete').dispatch('click');
    await page.ok(page.requests[1], { message: 'Deleted' });
    assert.equal(page.byId('wc-mss-profile-row-5'), null, 'row removed from the DOM');
    assert.equal(profileNotice(page).textContent, 'Deleted');
});

test('profiles page does nothing without its data object or jQuery', () => {
    const noData = createPage({ html: PROFILES_HTML });
    noData.byId('wc-mss-save-profile-btn').dispatch('click');
    assert.equal(noData.requests.length + noData.notices.length, 0);
    assert.doesNotThrow(() => createPage({ html: PROFILES_HTML, jquery: false, globals: { wcMssSyncProfilesData: { i18n: {} } } }));
});

/* ========================================================= Category scan */

const SCAN_HTML = `
<button id="wc-mss-scan-categories-btn">Scan</button>
<div id="wc-mss-cat-progress" style="display:none"></div>
<progress id="wc-mss-cat-bar"></progress>
<span id="wc-mss-cat-progress-label"></span>
<select id="wc-mss-cat-store-select">
  <option value="">All stores</option>
  <option value="https://a.test"> Store A </option>
  <option value="https://b.test">Store &lt;B&gt;</option>
</select>
<div id="wc-mss-cat-results">old</div>`;
const SCAN_I18N = {
    no_active_stores: 'No stores', starting_scan: 'Starting', scanning: 'Scanning', scan_complete: 'Done',
    unknown_error: 'Unknown', request_failed: 'Request failed', all_match: 'All match',
    mismatch_count_label: 'mismatches', local: 'local', remote: 'remote'
};
const scanPage = (html = SCAN_HTML) => createPage({
    html, admin: { scan_categories_nonce: 'scan-nonce' }, globals: { wcMssDiscrepanciesData: { i18n: SCAN_I18N } }
});

test('category scan warns when there are no stores to scan', () => {
    const page = scanPage(SCAN_HTML.replace(/<option value="https[^]*?<\/option>/g, ''));
    page.byId('wc-mss-scan-categories-btn').dispatch('click');
    assert.equal(page.requests.length, 0);
    assert.equal(page.lastNotice().level, 'warning');
    assert.equal(page.lastNotice().message.textContent, 'No stores');
});

test('category scan walks the stores sequentially with the scan nonce and updates progress', async () => {
    const page = scanPage();
    const button = page.byId('wc-mss-scan-categories-btn');
    button.dispatch('click');

    assert.equal(button.disabled, true);
    assert.equal(page.byId('wc-mss-cat-progress').style.display, '');
    assert.equal(page.byId('wc-mss-cat-results').innerHTMLWrites.at(-1), '');
    assert.equal(page.requests.length, 1, 'one store at a time');
    assert.equal(page.requests[0].action, 'wc_mss_scan_categories');
    assert.deepEqual(page.requests[0].data, { nonce: 'scan-nonce', store_url: 'https://a.test' });
    assert.equal(page.byId('wc-mss-cat-progress-label').textContent, 'Scanning Store A (1/2)');
    assert.equal(Number(page.byId('wc-mss-cat-bar').value), 0);

    await page.ok(page.requests[0], { count: 0 });
    assert.equal(page.requests.length, 2);
    assert.deepEqual(page.requests[1].data, { nonce: 'scan-nonce', store_url: 'https://b.test' });
    assert.equal(page.byId('wc-mss-cat-progress-label').textContent, 'Scanning Store <B> (2/2)');
    assert.equal(Number(page.byId('wc-mss-cat-bar').value), 50);

    await page.ok(page.requests[1], { count: 0 });
    assert.equal(button.disabled, false);
    assert.equal(Number(page.byId('wc-mss-cat-bar').value), 100);
    assert.equal(page.byId('wc-mss-cat-progress-label').textContent, 'Done');
    assert.equal(page.lastNotice().message.textContent, 'All match');
    assert.equal(page.lastNotice().level, 'success');
});

test('category scan limits itself to the selected store', async () => {
    const page = scanPage();
    page.byId('wc-mss-cat-store-select').value = 'https://b.test';
    page.byId('wc-mss-scan-categories-btn').dispatch('click');
    await page.ok(page.requests[0], { count: 0 });
    assert.deepEqual(page.requests.map(r => r.data.store_url), ['https://b.test']);
});

test('category scan renders mismatches, server errors and failures with escaped names', async () => {
    const page = scanPage();
    page.byId('wc-mss-scan-categories-btn').dispatch('click');

    await page.ok(page.requests[0], {
        count: 2, store_name: XSS, store_url: 'https://a.test', total_local: 10, total_remote: 8,
        table_html: '<table class="mm"><tr><td>Shoes</td></tr></table>'
    });
    await page.fail(page.requests[1], XSS);

    const results = page.byId('wc-mss-cat-results');
    assert.ok(noMarkup(results), 'store names and errors must be escaped');
    assert.equal(results.querySelector('h3').textContent, XSS + ' — 2 mismatches (10 local / 8 remote)');
    assert.equal(results.querySelectorAll('table.mm').length, 1);
    const errorNotice = results.querySelector('.notice-error');
    assert.equal(errorNotice.textContent, 'Store <B>: ' + XSS);
    assert.equal(page.byId('wc-mss-scan-categories-btn').disabled, false);
});

test('category scan uses the unknown-error label when the server omits a message', async () => {
    const page = scanPage();
    page.byId('wc-mss-cat-store-select').value = 'https://a.test';
    page.byId('wc-mss-scan-categories-btn').dispatch('click');
    await page.fail(page.requests[0], '');
    const text = page.byId('wc-mss-cat-results').querySelector('.notice-error').textContent;
    assert.match(text, /^Store A: /);
});

test('category scan block is skipped without its data object', () => {
    const page = createPage({ html: SCAN_HTML });
    page.byId('wc-mss-scan-categories-btn').dispatch('click');
    assert.equal(page.requests.length, 0);
});

/* ============================================ Category / tag mapping tables */

const CAT_MAP_HTML = `
<select id="wc-mss-map-store-select"><option value="">-</option><option value="https://a.test">A</option><option value="https://b.test">B</option></select>
<div id="wc-mss-map-loading" style="display:none"></div>
<div id="wc-mss-map-tables" style="display:none">
  <table id="wc-mss-map-category-table"><tbody><tr><td>stale</td></tr></tbody></table>
  <table id="wc-mss-map-tag-table"><tbody></tbody></table>
  <button id="wc-mss-map-save-categories">Save cats</button><span class="wc-mss-map-save-status" data-for="categories"></span>
  <button id="wc-mss-map-save-tags">Save tags</button><span class="wc-mss-map-save-status" data-for="tags"></span>
</div>
<div id="wc-mss-map-empty" style="display:none"></div>`;
const MAP_I18N = { no_mapping: '-- none --', skip: '-- skip --', saving: 'Saving...', saved: 'Saved', save_failed: 'Save failed', load_failed: 'Load failed' };
const catMapPage = () => createPage({ html: CAT_MAP_HTML, globals: { wcMssCategoryMappingData: { i18n: MAP_I18N } } });
const shown = el => el.style.display !== 'none';

async function chooseStore(page, url, { mappings, cats, tags } = {}) {
    page.byId('wc-mss-map-store-select').value = url;
    page.byId('wc-mss-map-store-select').dispatch('change');
    const reqs = page.requests.slice(-3);
    await page.ok(reqs[0], mappings);
    await page.ok(reqs[1], cats || { terms: [] });
    await page.ok(reqs[2], tags || { terms: [] });
    return reqs;
}

const baseMappings = () => ({
    local_categories: [{ name: 'Shoes', slug: 'shoes' }, { name: 'Hats', slug: 'hats' }],
    local_tags: [{ name: 'Sale', slug: 'sale' }],
    category_mappings: { shoes: 'footwear', hats: '__skip__' },
    tag_mappings: {}
});

test('choosing no store clears and hides the tables without requests', () => {
    const page = catMapPage();
    page.byId('wc-mss-map-store-select').dispatch('change');
    assert.equal(page.requests.length, 0);
    assert.equal(page.byId('wc-mss-map-category-table').querySelector('tbody').children.length, 0);
});

test('choosing a store requests mappings plus remote categories and tags with the main nonce', async () => {
    const page = catMapPage();
    page.byId('wc-mss-map-store-select').value = 'https://a.test';
    page.byId('wc-mss-map-store-select').dispatch('change');

    assert.equal(shown(page.byId('wc-mss-map-loading')), true);
    assert.deepEqual(page.requests.map(r => [r.action, r.data]), [
        ['wc_mss_get_category_mappings', { nonce: 'nonce-main', store_url: 'https://a.test' }],
        ['wc_mss_get_remote_terms', { nonce: 'nonce-main', store_url: 'https://a.test', taxonomy: 'category' }],
        ['wc_mss_get_remote_terms', { nonce: 'nonce-main', store_url: 'https://a.test', taxonomy: 'tag' }]
    ]);
});

test('mapping rows preselect the saved remote term, skip, or none', async () => {
    const page = catMapPage();
    await chooseStore(page, 'https://a.test', {
        mappings: baseMappings(),
        cats: { terms: [{ slug: 'footwear', name: 'Footwear' }, { slug: 'caps', name: 'Caps' }] },
        tags: { terms: [{ slug: 'promo', name: 'Promo' }] }
    });

    assert.equal(shown(page.byId('wc-mss-map-loading')), false);
    assert.equal(shown(page.byId('wc-mss-map-tables')), true);
    const rows = page.qa('#wc-mss-map-category-table tbody tr');
    assert.deepEqual(rows.map(r => r.cells[0].textContent), ['Shoes', 'Hats']);
    assert.deepEqual(rows.map(r => r.querySelector('select').value), ['footwear', '__skip__']);
    assert.deepEqual(rows[0].querySelectorAll('option').map(o => o.textContent), ['-- none --', '-- skip --', 'Footwear', 'Caps']);
    const tagSelect = page.qa('#wc-mss-map-tag-table tbody select')[0];
    assert.equal(tagSelect.value, '', 'unmapped tags default to none');
    assert.equal(tagSelect.dataset.localSlug, 'sale');
});

test('mapping tables still render when a remote terms request fails', async () => {
    const page = catMapPage();
    page.byId('wc-mss-map-store-select').value = 'https://a.test';
    page.byId('wc-mss-map-store-select').dispatch('change');
    await page.ok(page.requests[0], baseMappings());
    await page.fail(page.requests[1], 'remote down');
    await page.fail(page.requests[2], 'remote down');
    const options = page.qa('#wc-mss-map-category-table tbody tr')[0].querySelectorAll('option');
    assert.equal(options.length, 2);
});

test('mapping load failure shows the server message; empty stores show the empty state', async () => {
    const page = catMapPage();
    page.byId('wc-mss-map-store-select').value = 'https://a.test';
    page.byId('wc-mss-map-store-select').dispatch('change');
    await page.fail(page.requests[0], 'Store offline');
    await page.ok(page.requests[1], { terms: [] });
    await page.ok(page.requests[2], { terms: [] });
    assert.equal(page.byId('wc-mss-map-empty').textContent, 'Store offline');
    assert.equal(shown(page.byId('wc-mss-map-empty')), true);
    assert.equal(shown(page.byId('wc-mss-map-tables')), false);

    const empty = catMapPage();
    await chooseStore(empty, 'https://a.test', { mappings: { local_categories: [], local_tags: [], category_mappings: {}, tag_mappings: {} } });
    assert.equal(shown(empty.byId('wc-mss-map-empty')), true);
    assert.equal(shown(empty.byId('wc-mss-map-tables')), false);
});

test('mapping tables insert term names as escaped text', async () => {
    const page = catMapPage();
    await chooseStore(page, 'https://a.test', {
        mappings: { ...baseMappings(), local_categories: [{ name: XSS, slug: 'x' }], category_mappings: {} },
        cats: { terms: [{ slug: 'r', name: XSS }] }
    });
    const tbody = page.byId('wc-mss-map-category-table').querySelector('tbody');
    assert.ok(noMarkup(tbody));
    assert.equal(tbody.querySelector('td').textContent, XSS);
    assert.equal(tbody.querySelectorAll('option')[2].textContent, XSS);
});

test('saving categories posts only mapped rows and reports success on its own status element', async () => {
    const page = catMapPage();
    await chooseStore(page, 'https://a.test', {
        mappings: baseMappings(), cats: { terms: [{ slug: 'footwear', name: 'Footwear' }] }
    });
    const selects = page.qa('#wc-mss-map-category-table tbody select');
    selects[0].value = '';
    selects[1].value = 'footwear';
    const before = page.requests.length;

    const button = page.byId('wc-mss-map-save-categories');
    button.dispatch('click');

    assert.equal(button.disabled, true);
    assert.equal(page.requests.length, before + 1);
    const req = page.requests.at(-1);
    assert.equal(req.action, 'wc_mss_save_category_mappings');
    assert.deepEqual(req.data, {
        nonce: 'nonce-main', store_url: 'https://a.test', mapping_type: 'category', 'mappings[hats]': 'footwear'
    });

    await page.ok(req, {});
    const status = page.q('.wc-mss-map-save-status[data-for="categories"]');
    assert.equal(status.querySelector('.wc-mss-notice-message').textContent, 'Saved');
    assert.equal(page.q('.wc-mss-map-save-status[data-for="tags"]').children.length, 0);
    assert.equal(button.disabled, false);
});

test('saving tags uses the tag table, mapping_type tag and the server error message', async () => {
    const page = catMapPage();
    await chooseStore(page, 'https://a.test', { mappings: baseMappings(), tags: { terms: [{ slug: 'promo', name: 'Promo' }] } });
    page.qa('#wc-mss-map-tag-table tbody select')[0].value = 'promo';

    page.byId('wc-mss-map-save-tags').dispatch('click');
    const req = page.requests.at(-1);
    assert.deepEqual(req.data, { nonce: 'nonce-main', store_url: 'https://a.test', mapping_type: 'tag', 'mappings[sale]': 'promo' });

    await page.fail(req, XSS);
    const status = page.q('.wc-mss-map-save-status[data-for="tags"]');
    assert.equal(status.querySelector('.wc-mss-notice-message').textContent, XSS);
    assert.ok(noMarkup(status));
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.byId('wc-mss-map-save-tags').disabled, false);
});

// BUG (admin-scripts.js ~L628-L631, used at L1103-L1119 and L1171-L1213): escapeHtml() is
// div.textContent -> div.innerHTML, which does NOT escape double quotes. Slugs/values inserted into
// data-*="..." and value="..." attributes by the mapping builders can therefore break out of the
// attribute (remote store term slugs and saved mappings are not trusted input).
test('mapping rows keep hostile slugs inside their attribute', async () => {
    const page = catMapPage();
    await chooseStore(page, 'https://a.test', {
        mappings: { ...baseMappings(), local_categories: [{ name: 'Evil', slug: 'a" data-pwn="1' }], category_mappings: {} }
    });
    const select = page.qa('#wc-mss-map-category-table tbody select')[0];
    assert.equal(select.getAttribute('data-pwn'), null);
    assert.equal(select.dataset.localSlug, 'a" data-pwn="1');
});

// BUG (admin-scripts.js ~L1139-L1165): the change handler never checks that the store is still the
// selected one, so a slow response for store A overwrites the tables after the user chose store B.
test('mapping tables ignore a stale response from a previously selected store', async () => {
    const page = catMapPage();
    const sel = page.byId('wc-mss-map-store-select');
    sel.value = 'https://a.test'; sel.dispatch('change');
    const a = page.requests.slice(-3);
    sel.value = 'https://b.test'; sel.dispatch('change');
    const b = page.requests.slice(-3);
    const mappingsFor = name => ({ local_categories: [{ name, slug: name }], local_tags: [], category_mappings: {}, tag_mappings: {} });

    for (const r of b) await page.ok(r, r.action === 'wc_mss_get_category_mappings' ? mappingsFor('FromB') : { terms: [] });
    for (const r of a) await page.ok(r, r.action === 'wc_mss_get_category_mappings' ? mappingsFor('FromA') : { terms: [] });

    assert.equal(page.byId('wc-mss-map-category-table').querySelector('td').textContent, 'FromB');
});

/* ====================================================== Attribute mappings */

const ATTR_HTML = `
<select id="wc-mss-attr-map-store-select"><option value="">-</option><option value="https://a.test">A</option></select>
<div id="wc-mss-attr-map-loading" style="display:none"></div>
<div id="wc-mss-attr-map-tables" style="display:none">
  <table id="wc-mss-attr-map-name-table"><tbody></tbody></table>
  <div id="wc-mss-attr-map-value-groups"></div>
  <button id="wc-mss-attr-map-save-names">Save names</button><span class="wc-mss-attr-map-save-status" data-for="names"></span>
  <button id="wc-mss-attr-map-save-values">Save values</button><span class="wc-mss-attr-map-save-status" data-for="values"></span>
</div>
<div id="wc-mss-attr-map-empty" style="display:none"></div>`;
const ATTR_I18N = { ...MAP_I18N, no_values: 'No values' };
const attrPage = () => createPage({ html: ATTR_HTML, globals: { wcMssAttributeMappingData: { i18n: ATTR_I18N } } });

const attrData = () => ({
    local_attributes: [
        { name: 'Color', values: ['Red', 'Blue'] },
        { name: 'Size', values: ['2024'] },
        { name: 'Empty', values: [] }
    ],
    name_mappings: { Color: 'Colour' },
    value_mappings: { Color: { Red: 'Rouge' } }
});

async function chooseAttrStore(page, mappings = attrData(), remote = { terms: [{ name: 'Colour' }, { name: 'Dim' }] }) {
    page.byId('wc-mss-attr-map-store-select').value = 'https://a.test';
    page.byId('wc-mss-attr-map-store-select').dispatch('change');
    const reqs = page.requests.slice(-2);
    await page.ok(reqs[0], mappings);
    await page.ok(reqs[1], remote);
}

test('attribute store change requests mappings and remote attributes', () => {
    const page = attrPage();
    page.byId('wc-mss-attr-map-store-select').value = 'https://a.test';
    page.byId('wc-mss-attr-map-store-select').dispatch('change');
    assert.deepEqual(page.requests.map(r => [r.action, r.data]), [
        ['wc_mss_get_attribute_mappings', { nonce: 'nonce-main', store_url: 'https://a.test' }],
        ['wc_mss_get_remote_terms', { nonce: 'nonce-main', store_url: 'https://a.test', taxonomy: 'attribute' }]
    ]);
});

test('attribute name rows and value groups render with saved mappings', async () => {
    const page = attrPage();
    await chooseAttrStore(page);

    const rows = page.qa('#wc-mss-attr-map-name-table tbody tr');
    assert.deepEqual(rows.map(r => r.cells[0].textContent), ['Color', 'Size', 'Empty']);
    assert.equal(rows[0].querySelector('select').value, 'Colour');
    assert.equal(rows[1].querySelector('select').value, '');
    const groups = page.qa('details.wc-mss-attr-map-value-group');
    assert.deepEqual(groups.map(g => g.dataset.attributeName), ['Color', 'Size'], 'attributes without values get no group');
    assert.deepEqual(groups[0].querySelectorAll('input').map(i => i.value), ['Rouge', '']);
    assert.equal(page.byId('wc-mss-attr-map-tables').style.display, '');
});

test('attribute page shows a placeholder when no attribute has values', async () => {
    const page = attrPage();
    await chooseAttrStore(page, { local_attributes: [{ name: 'Weight', values: [] }], name_mappings: {}, value_mappings: {} });
    assert.equal(page.byId('wc-mss-attr-map-value-groups').querySelector('p.description').textContent, 'No values');
});

test('attribute page load failure and empty state', async () => {
    const page = attrPage();
    page.byId('wc-mss-attr-map-store-select').value = 'https://a.test';
    page.byId('wc-mss-attr-map-store-select').dispatch('change');
    await page.fail(page.requests[0], 'Nope');
    await page.ok(page.requests[1], { terms: [] });
    assert.equal(page.byId('wc-mss-attr-map-empty').textContent, 'Nope');

    const empty = attrPage();
    await chooseAttrStore(empty, { local_attributes: [], name_mappings: {}, value_mappings: {} });
    assert.equal(empty.byId('wc-mss-attr-map-empty').style.display, '');
    assert.equal(empty.byId('wc-mss-attr-map-tables').style.display, 'none');
});

test('attribute rows insert names and values as escaped text', async () => {
    const page = attrPage();
    await chooseAttrStore(page, {
        local_attributes: [{ name: XSS, values: [XSS] }], name_mappings: {}, value_mappings: {}
    }, { terms: [{ name: XSS }] });
    assert.ok(noMarkup(page.byId('wc-mss-attr-map-tables')));
    assert.equal(page.qa('#wc-mss-attr-map-name-table tbody td')[0].textContent, XSS);
    assert.equal(page.q('details summary').textContent, XSS);
});

test('saving attribute names posts only non-empty selections', async () => {
    const page = attrPage();
    await chooseAttrStore(page);
    page.qa('#wc-mss-attr-map-name-table tbody select')[1].value = 'Dim';

    const button = page.byId('wc-mss-attr-map-save-names');
    button.dispatch('click');
    const req = page.requests.at(-1);

    assert.equal(button.disabled, true);
    assert.equal(req.action, 'wc_mss_save_attribute_mappings');
    assert.deepEqual(req.data, {
        nonce: 'nonce-main', store_url: 'https://a.test', mapping_type: 'names',
        'name_mappings[Color]': 'Colour', 'name_mappings[Size]': 'Dim'
    });
    await page.ok(req, {});
    assert.equal(page.q('.wc-mss-attr-map-save-status[data-for="names"] .wc-mss-notice-message').textContent, 'Saved');
    assert.equal(button.disabled, false);
});

test('saving attribute values sends one request per attribute group and combines the outcome', async () => {
    const page = attrPage();
    await chooseAttrStore(page);
    page.qa('input.wc-mss-attr-map-value-input')[2].value = 'Large';
    const before = page.requests.length;

    const button = page.byId('wc-mss-attr-map-save-values');
    button.dispatch('click');
    const saves = page.requests.slice(before);

    assert.equal(button.disabled, true);
    assert.deepEqual(saves.map(r => r.data), [
        { nonce: 'nonce-main', store_url: 'https://a.test', mapping_type: 'values', attribute_name: 'Color', 'value_mappings[Red]': 'Rouge' },
        { nonce: 'nonce-main', store_url: 'https://a.test', mapping_type: 'values', attribute_name: 'Size', 'value_mappings[2024]': 'Large' }
    ]);

    await page.ok(saves[0], {});
    await page.fail(saves[1], 'bad');
    const status = page.q('.wc-mss-attr-map-save-status[data-for="values"]');
    assert.equal(status.querySelector('.wc-mss-notice-message').textContent, 'Save failed');
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(button.disabled, false);

    button.dispatch('click');
    const again = page.requests.slice(-2);
    await page.ok(again[0], {});
    await page.ok(again[1], {});
    assert.equal(page.lastNotice().message.textContent, 'Saved');
    assert.equal(page.lastNotice().level, 'success');
});

test('attribute mapping inputs keep hostile saved values inside their attribute', async () => {
    const page = attrPage();
    await chooseAttrStore(page, {
        local_attributes: [{ name: 'Color', values: ['Red'] }], name_mappings: {},
        value_mappings: { Color: { Red: 'x" data-pwn="1' } }
    });
    const input = page.q('input.wc-mss-attr-map-value-input');
    assert.equal(input.getAttribute('data-pwn'), null);
    assert.equal(input.value, 'x" data-pwn="1');
});

/* =============================================================== Logs page */

const LOGS_HTML = `
<button id="wc-mss-clear-log">Clear</button>
<pre id="wc-mss-log-content">2025-01-01T00:00:00Z INFO started
2025-01-01T00:00:01Z WARNING low stock
2025-01-01T00:00:02Z ERROR boom &lt;img src=x onerror=alert(1)&gt;
2025-01-01T00:00:03Z NOTICE fine</pre>
<div id="wc-mss-log-viewer">viewer</div>
<span id="wc-mss-issues-count"></span>
<div id="wc-mss-issues-viewer"><p id="wc-mss-issues-empty">none</p></div>
<button id="wc-mss-clear-warnings-errors">Clear issues</button><span id="wc-mss-clear-warnings-result"></span>
<input id="wc-mss-test-sku" value=" A1, ,B2 ,">
<button id="wc-mss-force-sync-btn">Force</button><div id="wc-mss-force-sync-result" style="display:none"></div>
<select id="wc-mss-category-select"><option value="">-</option><option value="3">Shoes</option></select>
<button id="wc-mss-force-sync-category-btn">Force cat</button><div id="wc-mss-force-sync-category-result" style="display:none"></div>`;
const LOGS_I18N = {
    confirm_clear_log: 'Clear log?', no_log_entries: 'No entries', failed_to_clear_logs: 'Could not clear',
    confirm_clear_warnings_errors: 'Clear issues?', no_warnings_errors: 'No issues', failed_to_clear: 'Failed to clear',
    please_enter_sku: 'Enter SKU', queuing: 'Queuing...', force_full_sync: 'Force full sync',
    refresh_to_see_logs: 'Refresh to see logs', please_select_category: 'Pick category'
};
const logsPage = (html = LOGS_HTML) => createPage({ html, globals: { wcMssLogsData: { i18n: LOGS_I18N } } });

test('clear log asks first, then clears the viewer on success and re-enables the button', async () => {
    const page = logsPage();
    page.confirmAnswer = false;
    page.byId('wc-mss-clear-log').dispatch('click');
    assert.equal(page.requests.length, 0);
    assert.deepEqual(page.confirms, ['Clear log?']);

    page.confirmAnswer = true;
    page.byId('wc-mss-clear-log').dispatch('click');
    assert.equal(page.byId('wc-mss-clear-log').disabled, true);
    assert.equal(page.requests[0].action, 'wc_mss_clear_sync_log');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main' });

    await page.ok(page.requests[0], {});
    assert.equal(page.byId('wc-mss-log-content').textContent, '');
    assert.equal(page.byId('wc-mss-log-viewer').innerHTML, '<p>No entries</p>');
    assert.equal(page.byId('wc-mss-clear-log').disabled, false);
});

test('clear log failure keeps the log, shows the safe error and re-enables the button', async () => {
    const page = logsPage();
    page.byId('wc-mss-clear-log').dispatch('click');
    await page.fail(page.requests[0], XSS);
    assert.ok(page.byId('wc-mss-log-content').textContent.includes('started'));
    assert.equal(page.lastNotice().message.textContent, XSS);
    assert.ok(noMarkup(page.lastNotice().node));
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.byId('wc-mss-clear-log').disabled, false);
});

test('warnings and errors are extracted as text lines with a count', () => {
    const page = logsPage();
    assert.equal(page.byId('wc-mss-issues-count').textContent, '(2)');
    assert.equal(page.byId('wc-mss-issues-empty'), null);
    const lines = page.byId('wc-mss-issues-viewer').children.map(d => d.textContent);
    assert.deepEqual(lines, [
        '2025-01-01T00:00:01Z WARNING low stock',
        '2025-01-01T00:00:02Z ERROR boom <img src=x onerror=alert(1)>'
    ]);
    assert.ok(noMarkup(page.byId('wc-mss-issues-viewer')), 'log lines are inserted with text()');
});

test('a log without warnings or errors reports zero and keeps the empty marker', () => {
    const page = logsPage(LOGS_HTML.replace(/2025-01-01T00:00:0[123]Z (WARNING|ERROR|NOTICE)[^\n]*\n?/g, ''));
    assert.equal(page.byId('wc-mss-issues-count').textContent, '(0)');
    assert.ok(page.byId('wc-mss-issues-empty'));
    page.byId('wc-mss-clear-warnings-errors').dispatch('click');
    assert.equal(page.requests.length, 0, 'the clear handler is only bound when issues exist');
});

test('clear warnings and errors posts the request and resets the viewer', async () => {
    const page = logsPage();
    page.byId('wc-mss-clear-warnings-errors').dispatch('click');
    assert.equal(page.byId('wc-mss-clear-warnings-errors').disabled, true);
    assert.equal(page.requests[0].action, 'wc_mss_clear_warnings_errors');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main' });

    await page.ok(page.requests[0], { message: 'Cleared 2' });
    assert.equal(page.byId('wc-mss-issues-count').textContent, '(0)');
    assert.equal(page.byId('wc-mss-issues-viewer').innerHTML, '<p>No issues</p>');
    assert.equal(page.byId('wc-mss-clear-warnings-result').querySelector('.wc-mss-notice-message').textContent, 'Cleared 2');
    assert.equal(page.byId('wc-mss-clear-warnings-errors').disabled, false);
});

test('clear warnings and errors failure keeps the lines', async () => {
    const page = logsPage();
    page.byId('wc-mss-clear-warnings-errors').dispatch('click');
    await page.fail(page.requests[0], 'Denied');
    assert.equal(page.byId('wc-mss-issues-count').textContent, '(2)');
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.byId('wc-mss-clear-warnings-errors').disabled, false);
});

test('force sync by SKU requires a SKU', () => {
    const page = logsPage();
    page.byId('wc-mss-test-sku').value = '  ';
    page.byId('wc-mss-force-sync-btn').dispatch('click');
    assert.equal(page.requests.length, 0);
    assert.equal(page.lastNotice().level, 'warning');
    assert.equal(page.lastNotice().message.textContent, 'Enter SKU');
    assert.equal(page.byId('wc-mss-force-sync-result').style.display, '');
});

test('force sync by SKU sends trimmed, non-empty SKUs as an array and shows per-SKU results', async () => {
    const page = logsPage();
    const button = page.byId('wc-mss-force-sync-btn');
    button.dispatch('click');

    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Queuing...');
    assert.equal(page.byId('wc-mss-force-sync-result').style.display, 'none');
    assert.equal(page.requests[0].action, 'wc_mss_force_sync_by_sku');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', skus: ['A1', 'B2'] });

    await page.ok(page.requests[0], {
        message: 'Queued', results: [{ success: true, message: 'A1 queued' }, { success: false, message: 'B2 <b>missing</b>' }]
    });

    assert.equal(page.lastNotice().level, 'warning');
    assert.equal(page.lastNotice().message.textContent, 'Queued\n✓ A1 queued\n✗ B2 <b>missing</b>\nRefresh to see logs');
    assert.ok(noMarkup(page.lastNotice().node));
    assert.equal(page.byId('wc-mss-force-sync-result').style.display, '');
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Force full sync');
});

test('force sync by SKU is a success when every SKU queued, and an error on failure', async () => {
    const page = logsPage();
    page.byId('wc-mss-force-sync-btn').dispatch('click');
    await page.ok(page.requests[0], { message: 'Queued', results: [{ success: true, message: 'ok' }] });
    assert.equal(page.lastNotice().level, 'success');

    page.byId('wc-mss-force-sync-btn').dispatch('click');
    await page.fail(page.requests[1], XSS);
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().message.textContent, XSS);
    assert.equal(page.byId('wc-mss-force-sync-btn').textContent, 'Force full sync');
});

test('pressing Enter in the SKU field triggers the force sync button only for Enter', () => {
    const page = logsPage();
    page.byId('wc-mss-test-sku').dispatch('keypress', { which: 65 });
    assert.equal(page.requests.length, 0);
    page.byId('wc-mss-test-sku').dispatch('keypress', { which: 13 });
    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0].action, 'wc_mss_force_sync_by_sku');
});

test('force sync by category needs a category, then posts it', async () => {
    const page = logsPage();
    page.byId('wc-mss-force-sync-category-btn').dispatch('click');
    assert.equal(page.requests.length, 0);
    assert.equal(page.lastNotice().message.textContent, 'Pick category');
    assert.equal(page.lastNotice().level, 'warning');

    page.byId('wc-mss-category-select').value = '3';
    page.byId('wc-mss-force-sync-category-btn').dispatch('click');
    assert.equal(page.requests[0].action, 'wc_mss_force_sync_by_category');
    assert.deepEqual(page.requests[0].data, { nonce: 'nonce-main', category_id: '3' });
    assert.equal(page.byId('wc-mss-force-sync-category-btn').textContent, 'Queuing...');

    await page.ok(page.requests[0], { message: 'Queued 12' });
    assert.equal(page.lastNotice().message.textContent, 'Queued 12\nRefresh to see logs');
    assert.equal(page.lastNotice().level, 'success');
    assert.equal(page.byId('wc-mss-force-sync-category-btn').disabled, false);
    assert.equal(page.byId('wc-mss-force-sync-category-btn').textContent, 'Force full sync');
});

test('force sync by category failure is an error without the refresh hint', async () => {
    const page = logsPage();
    page.byId('wc-mss-category-select').value = '3';
    page.byId('wc-mss-force-sync-category-btn').dispatch('click');
    await page.fail(page.requests[0], XSS);
    assert.equal(page.lastNotice().level, 'error');
    assert.equal(page.lastNotice().message.textContent, XSS);
    assert.ok(noMarkup(page.lastNotice().node));
});

test('logs page block is skipped without its data object', () => {
    const page = createPage({ html: LOGS_HTML });
    page.byId('wc-mss-clear-log').dispatch('click');
    assert.equal(page.requests.length, 0);
});
