const test = require('node:test');
const assert = require('node:assert/strict');
const { FakeDocument, makeJQuery, parseSelector } = require('./helpers/fake-dom.js');

// Guards the shared fake DOM itself so a helper regression cannot silently weaken the other suites.

test('parser builds elements, decodes entities and keeps hostile attribute values literal', () => {
    const doc = new FakeDocument();
    doc.mount('<div id="a" class="x y" data-local-slug="a&quot;b"><p>1 &lt; 2 &amp; 3</p><input value="v" disabled><br></div>');
    const div = doc.getElementById('a');
    assert.equal(div.dataset.localSlug, 'a"b');
    assert.ok(div.classList.contains('y'));
    assert.equal(div.querySelector('p').textContent, '1 < 2 & 3');
    assert.equal(div.querySelector('input').value, 'v');
    assert.equal(div.querySelector('input').disabled, true);
});

test('innerHTML getter escapes like a browser: & < > but not quotes', () => {
    const doc = new FakeDocument();
    const el = doc.createElement('div');
    el.textContent = '"<b>&';
    assert.equal(el.innerHTML, '"&lt;b&gt;&amp;');
});

test('selectors support descendants, attributes, lists and :checked', () => {
    const doc = new FakeDocument();
    doc.mount('<table id="t"><tbody><tr><td><input type="checkbox" checked name="n"></td></tr></tbody></table><span data-for="x"></span>');
    assert.equal(doc.querySelectorAll('#t tbody tr td').length, 1);
    assert.equal(doc.querySelectorAll('input[type="checkbox"]:checked').length, 1);
    assert.equal(doc.querySelectorAll('span[data-for="x"], td').length, 2);
    assert.equal(doc.querySelectorAll('input[name="n"]').length, 1);
    assert.throws(() => parseSelector('a > b'), /unsupported/);
});

test('events bubble from target to document and respect disabled on click()', () => {
    const doc = new FakeDocument();
    doc.mount('<div id="o"><button id="b">x</button></div>');
    const seen = [];
    doc.addEventListener('click', e => seen.push('doc:' + e.target.id));
    doc.getElementById('o').addEventListener('click', () => seen.push('outer'));
    doc.getElementById('b').click();
    doc.getElementById('b').disabled = true;
    doc.getElementById('b').click();
    assert.deepEqual(seen, ['outer', 'doc:b']);
});

test('select values follow the selected option unless assigned', () => {
    const doc = new FakeDocument();
    doc.mount('<select id="s"><option value="a">A</option><option value="b" selected>B</option></select>');
    const select = doc.getElementById('s');
    assert.equal(select.value, 'b');
    select.value = 'a';
    assert.equal(select.value, 'a');
});

test('jQuery subset coerces data() like jQuery, delegates events and chains', () => {
    const doc = new FakeDocument();
    doc.mount('<ul id="l"><li class="i" data-id="5" data-flag="true" data-name="007">a</li></ul>');
    const $ = makeJQuery(doc);
    const li = $('.i');
    assert.strictEqual(li.data('id'), 5);
    assert.strictEqual(li.data('flag'), true);
    assert.strictEqual(li.data('name'), '007');
    assert.strictEqual($('.none').val(), undefined);

    let clicked = null;
    $(doc).on('click', '.i', function () { clicked = this; });
    doc.querySelector('.i').click();
    assert.equal(clicked, doc.querySelector('.i'));

    $('.i').prop('disabled', true).text('b');
    assert.equal(doc.querySelector('.i').disabled, true);
    assert.equal(doc.querySelector('.i').textContent, 'b');
});
