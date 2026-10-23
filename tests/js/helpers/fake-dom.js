'use strict';
/**
 * Reusable fake browser for admin/js/admin-scripts.js tests.
 *
 * Not a test file (it lives in a helpers/ folder and does not match node's
 * test-file patterns). It provides:
 *  - a small element tree with an HTML parser for innerHTML/mount(), a CSS
 *    selector subset (tag, #id, .class, [attr], [attr=val], :checked, descendant
 *    and comma lists), event bubbling and form-control behaviour;
 *  - a jQuery subset sufficient for the script (delegation, data() coercion,
 *    prop/val/text/html/show/hide, find/each, $.each, ready callbacks);
 *  - the REAL admin-ui.js (notices + response envelope) on top of a fake
 *    wp.ajax.send, so requests are captured exactly as WordPress would see them;
 *  - Blob / URL / FileReader / clipboard / timers / confirm fakes.
 *
 * Entities such as innerHTML getters deliberately mimic browsers: escaping only
 * & < > (not quotes), so attribute-injection bugs stay observable.
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const createUI = require('../../../admin/js/admin-ui.js');

const SCRIPT_PATH = path.join(__dirname, '..', '..', '..', 'admin', 'js', 'admin-scripts.js');
const VOID = new Set(['input', 'br', 'img', 'hr', 'meta', 'link']);

const decode = s => s.replace(/&(#x?[0-9a-f]+|amp|lt|gt|quot|apos|nbsp);/gi, (m, e) => {
    const l = e.toLowerCase();
    if (l === 'amp') return '&';
    if (l === 'lt') return '<';
    if (l === 'gt') return '>';
    if (l === 'quot') return '"';
    if (l === 'apos') return "'";
    if (l === 'nbsp') return ' ';
    return String.fromCodePoint(l[1] === 'x' ? parseInt(l.slice(2), 16) : parseInt(l.slice(1), 10));
});
const escText = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const escAttr = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;');

/* ---------------------------------------------------------------- selectors */

function splitTop(str, sep) {
    const out = []; let depth = 0, quote = null, cur = '';
    for (const ch of str) {
        if (quote) { if (ch === quote) quote = null; cur += ch; continue; }
        if (ch === '"' || ch === "'") { quote = ch; cur += ch; continue; }
        if (ch === '[') depth++;
        if (ch === ']') depth--;
        if (depth === 0 && (sep === ' ' ? /\s/.test(ch) : ch === sep)) { if (cur) out.push(cur); cur = ''; continue; }
        cur += ch;
    }
    if (cur) out.push(cur);
    return out.map(s => s.trim()).filter(Boolean);
}

function parseCompound(src) {
    const c = { tag: null, id: null, classes: [], attrs: [], checked: false };
    let s = src, m;
    if ((m = /^(\*|[a-zA-Z][\w-]*)/.exec(s))) { c.tag = m[1] === '*' ? null : m[1].toLowerCase(); s = s.slice(m[0].length); }
    while (s) {
        if ((m = /^#([\w-]+)/.exec(s))) c.id = m[1];
        else if ((m = /^\.([\w-]+)/.exec(s))) c.classes.push(m[1]);
        else if ((m = /^\[([\w-]+)(?:=(?:"([^"]*)"|'([^']*)'|([^\]]*)))?\]/.exec(s))) {
            c.attrs.push({ name: m[1], value: m[2] ?? m[3] ?? m[4] ?? null });
        } else if ((m = /^:checked/.exec(s))) c.checked = true;
        else throw new Error('fake-dom: unsupported selector "' + src + '"');
        s = s.slice(m[0].length);
    }
    return c;
}

const selectorCache = new Map();
function parseSelector(sel) {
    if (!selectorCache.has(sel)) {
        selectorCache.set(sel, splitTop(sel, ',').map(part => splitTop(part, ' ').map(parseCompound)));
    }
    return selectorCache.get(sel);
}

function matchCompound(el, c) {
    if (el.nodeType !== 1) return false;
    if (c.tag && el.tagName.toLowerCase() !== c.tag) return false;
    if (c.id && el.getAttribute('id') !== c.id) return false;
    if (c.classes.some(cl => !el.classList.contains(cl))) return false;
    for (const a of c.attrs) {
        const v = el.getAttribute(a.name);
        if (v === null || (a.value !== null && v !== a.value)) return false;
    }
    if (c.checked && !el.checked) return false;
    return true;
}

function matchesChain(el, chain) {
    if (!matchCompound(el, chain[chain.length - 1])) return false;
    let node = el.parentNode, i = chain.length - 2;
    while (i >= 0 && node) {
        if (matchCompound(node, chain[i])) i--;
        node = node.parentNode;
    }
    return i < 0;
}

/* -------------------------------------------------------------------- nodes */

class FakeText {
    constructor(data) { this.nodeType = 3; this.data = data; this.parentNode = null; }
    get textContent() { return this.data; }
    set textContent(v) { this.data = String(v); }
}

class FakeElement {
    constructor(tagName, ownerDocument) {
        this.nodeType = 1;
        this.tagName = String(tagName).toUpperCase();
        this.ownerDocument = ownerDocument;
        this.parentNode = null;
        this.childNodes = [];
        this.attributes = {};
        this.listeners = {};
        this.style = {};
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.selected = false;
        this.files = [];
        this.scrollTop = 0;
        this.scrollHeight = 0;
        this.innerHTMLWrites = [];
        this._value = undefined;
        const el = this;
        this.dataset = new Proxy({}, {
            get: (_, k) => { const v = el.getAttribute('data-' + kebab(String(k))); return v === null ? undefined : v; },
            set: (_, k, v) => { el.setAttribute('data-' + kebab(String(k)), v); return true; },
            has: (_, k) => el.getAttribute('data-' + kebab(String(k))) !== null
        });
        this.classList = {
            _list: () => (el.attributes.class || '').split(/\s+/).filter(Boolean),
            add: (...c) => el.setAttribute('class', [...new Set([...el.classList._list(), ...c])].join(' ')),
            remove: (...c) => el.setAttribute('class', el.classList._list().filter(x => !c.includes(x)).join(' ')),
            contains: c => el.classList._list().includes(c),
            toggle: c => (el.classList.contains(c) ? el.classList.remove(c) : el.classList.add(c))
        };
    }

    get id() { return this.attributes.id || ''; }
    set id(v) { this.attributes.id = String(v); }
    get className() { return this.attributes.class || ''; }
    set className(v) { this.attributes.class = String(v); }
    get type() { return this.attributes.type || (this.tagName === 'INPUT' ? 'text' : ''); }
    set type(v) { this.attributes.type = String(v); }

    get value() {
        if (this.tagName === 'SELECT') {
            if (this._value !== undefined) return this._value;
            const options = this.querySelectorAll('option');
            const chosen = options.find(o => o.selected) || options[0];
            return chosen ? chosen.value : '';
        }
        if (this.tagName === 'OPTION') return this.attributes.value !== undefined ? this.attributes.value : this.textContent;
        if (this.tagName === 'TEXTAREA') return this._value !== undefined ? this._value : this.textContent;
        return this._value !== undefined ? this._value : (this.attributes.value ?? '');
    }
    set value(v) { this._value = String(v); }

    get children() { return this.childNodes.filter(n => n.nodeType === 1); }
    get firstChild() { return this.childNodes[0] || null; }
    get cells() { return this.children.filter(c => c.tagName === 'TD' || c.tagName === 'TH'); }
    get previousElementSibling() {
        if (!this.parentNode) return null;
        const sibs = this.parentNode.children;
        return sibs[sibs.indexOf(this) - 1] || null;
    }

    get textContent() { return this.childNodes.map(n => n.textContent).join(''); }
    set textContent(v) {
        this.childNodes.forEach(n => { n.parentNode = null; });
        this.childNodes = [];
        if (v !== '' && v != null) this.appendChild(new FakeText(String(v)));
    }

    get innerHTML() { return this.childNodes.map(n => (n.nodeType === 3 ? escText(n.data) : serialize(n))).join(''); }
    set innerHTML(html) {
        this.innerHTMLWrites.push(String(html));
        this.childNodes.forEach(n => { n.parentNode = null; });
        this.childNodes = [];
        parseInto(this, String(html));
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
        if (name === 'selected') this.selected = true;
        if (name === 'checked') this.checked = true;
        if (name === 'disabled') this.disabled = true;
    }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
    removeAttribute(name) { delete this.attributes[name]; }
    hasAttribute(name) { return this.getAttribute(name) !== null; }

    appendChild(child) {
        if (child.parentNode) child.parentNode.removeChild(child);
        child.parentNode = this;
        this.childNodes.push(child);
        return child;
    }
    prepend(child) {
        if (child.parentNode) child.parentNode.removeChild(child);
        child.parentNode = this;
        this.childNodes.unshift(child);
    }
    replaceChildren(...nodes) {
        this.childNodes.forEach(n => { n.parentNode = null; });
        this.childNodes = [];
        nodes.forEach(n => this.appendChild(n));
    }
    removeChild(child) {
        const i = this.childNodes.indexOf(child);
        if (i !== -1) { this.childNodes.splice(i, 1); child.parentNode = null; }
        return child;
    }
    remove() { if (this.parentNode) this.parentNode.removeChild(this); this.removed = true; }

    descendants() {
        const out = [];
        const walk = n => n.childNodes.forEach(c => { if (c.nodeType === 1) { out.push(c); walk(c); } });
        walk(this);
        return out;
    }
    matches(sel) { return parseSelector(sel).some(chain => matchesChain(this, chain)); }
    closest(sel) {
        for (let n = this; n && n.nodeType === 1; n = n.parentNode) if (n.matches(sel)) return n;
        return null;
    }
    querySelectorAll(sel) { return this.descendants().filter(e => e.matches(sel)); }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }

    addEventListener(type, fn, capture) { (this.listeners[type] ||= []).push({ fn, capture }); }
    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter(l => l.fn !== fn); }

    /** Fire an event with bubbling: target -> ancestors -> document. */
    dispatch(type, init = {}) {
        let stopped = false;
        const event = Object.assign({
            type, target: this, currentTarget: null, defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
            stopPropagation() { stopped = true; },
            stopImmediatePropagation() { stopped = true; }
        }, init);
        for (let node = this; node && !stopped; node = node.parentNode) {
            event.currentTarget = node;
            for (const l of [...(node.listeners[type] || [])]) {
                l.fn.call(node, event);
                if (stopped) break;
            }
        }
        return event;
    }
    dispatchEvent(event) { return this.dispatch(event.type, event); }

    click() {
        if (this.disabled) return;
        const event = this.dispatch('click');
        if (this.ownerDocument && this.ownerDocument.onDefaultClick && !event.defaultPrevented) {
            this.ownerDocument.onDefaultClick(this);
        }
    }
}

function kebab(s) { return s.replace(/[A-Z]/g, m => '-' + m.toLowerCase()); }

function serialize(el) {
    const attrs = Object.entries(el.attributes).map(([k, v]) => ' ' + k + '="' + escAttr(v) + '"').join('');
    const tag = el.tagName.toLowerCase();
    if (VOID.has(tag)) return '<' + tag + attrs + '>';
    return '<' + tag + attrs + '>' + el.innerHTML + '</' + tag + '>';
}

/* ------------------------------------------------------------------- parser */

function parseInto(parent, html) {
    const doc = parent.ownerDocument;
    const token = /<!--[\s\S]*?-->|<\/([a-zA-Z][\w-]*)\s*>|<([a-zA-Z][\w-]*)((?:\s+[^\s=>\/]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*\/?>|([^<]+|<)/g;
    const attrRe = /([^\s=>\/]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g;
    const stack = [parent];
    let m;
    while ((m = token.exec(html))) {
        const top = stack[stack.length - 1];
        if (m[0].startsWith('<!--')) continue;
        if (m[1]) {
            const name = m[1].toLowerCase();
            for (let i = stack.length - 1; i > 0; i--) {
                if (stack[i].tagName.toLowerCase() === name) { stack.length = i; break; }
            }
        } else if (m[2]) {
            const el = new FakeElement(m[2], doc);
            let a;
            attrRe.lastIndex = 0;
            const attrSrc = m[3] || '';
            while ((a = attrRe.exec(attrSrc))) {
                const raw = a[2] ?? a[3] ?? a[4];
                el.setAttribute(a[1], raw === undefined ? '' : decode(raw));
            }
            top.appendChild(el);
            if (!VOID.has(m[2].toLowerCase()) && !/\/>$/.test(m[0])) stack.push(el);
        } else if (m[4] !== undefined) {
            top.appendChild(new FakeText(decode(m[4])));
        }
    }
}

/* ----------------------------------------------------------------- document */

class FakeDocument extends FakeElement {
    constructor() {
        super('#document', null);
        this.nodeType = 9;
        this.ownerDocument = this;
        this.body = new FakeElement('body', this);
        this.appendChild(this.body);
        this.onDefaultClick = null;
    }
    createElement(tag) { return new FakeElement(tag, this); }
    getElementById(id) { return this.querySelector('#' + id); }
    /** Parse markup and append it to `parent` (default body); returns the first new element. */
    mount(html, parent = this.body) {
        const holder = new FakeElement('div', this);
        parseInto(holder, html);
        const nodes = [...holder.childNodes];
        nodes.forEach(n => parent.appendChild(n));
        return nodes.find(n => n.nodeType === 1) || null;
    }
}

/* ------------------------------------------------------------------- jQuery */

function coerceData(v) {
    if (v === null || v === undefined) return undefined;
    if (v === 'true') return true;
    if (v === 'false') return false;
    if (v === 'null') return null;
    if (v === String(+v) && v !== '') return +v;
    if (/^(?:\{[\s\S]*\}|\[[\s\S]*\])$/.test(v)) { try { return JSON.parse(v); } catch (e) { return v; } }
    return v;
}

function makeJQuery(dom) {
    class JQ {
        constructor(els) { this.els = els; this.length = els.length; this.jquery = '3.7.1'; els.forEach((e, i) => { this[i] = e; }); }
        on(type, selOrFn, fn) {
            this.els.forEach(el => {
                if (typeof selOrFn === 'function') {
                    el.addEventListener(type, function (e) { return selOrFn.call(this, e); });
                } else {
                    el.addEventListener(type, function (e) {
                        for (let t = e.target; t && t !== this.parentNode; t = t.parentNode) {
                            if (t.nodeType === 1 && t.matches && t.matches(selOrFn)) { fn.call(t, e); break; }
                            if (t === this) break;
                        }
                    });
                }
            });
            return this;
        }
        val(v) {
            if (v === undefined) return this.els.length ? this.els[0].value : undefined;
            this.els.forEach(e => { e.value = v; });
            return this;
        }
        data(k) {
            if (!this.els.length) return undefined;
            return coerceData(this.els[0].getAttribute('data-' + kebab(k)));
        }
        is(sel) { return this.els.some(e => (sel === ':checked' ? !!e.checked : e.matches(sel))); }
        prop(name, v) {
            if (v === undefined) return this.els.length ? this.els[0][name] : undefined;
            this.els.forEach(e => { e[name] = v; });
            return this;
        }
        text(v) {
            if (v === undefined) return this.els.map(e => e.textContent).join('');
            this.els.forEach(e => { e.textContent = v; });
            return this;
        }
        html(v) {
            if (v === undefined) return this.els.length ? this.els[0].innerHTML : undefined;
            this.els.forEach(e => { e.innerHTML = v; });
            return this;
        }
        show() { this.els.forEach(e => { e.style.display = ''; }); return this; }
        hide() { this.els.forEach(e => { e.style.display = 'none'; }); return this; }
        addClass(c) { this.els.forEach(e => e.classList.add(...c.split(/\s+/).filter(Boolean))); return this; }
        removeClass(c) { this.els.forEach(e => e.classList.remove(...c.split(/\s+/).filter(Boolean))); return this; }
        find(sel) { return new JQ([].concat(...this.els.map(e => e.querySelectorAll(sel)))); }
        each(fn) { this.els.forEach((e, i) => fn.call(e, i, e)); return this; }
        empty() { this.els.forEach(e => e.replaceChildren()); return this; }
        remove() { this.els.forEach(e => e.remove()); return this; }
        toArray() { return [...this.els]; }
        scrollTop(v) { if (v !== undefined) this.els.forEach(e => { e.scrollTop = v; }); return this; }
        append(c) { this.els.forEach(e => (c instanceof JQ ? c.els : [c]).forEach(n => e.appendChild(n))); return this; }
        fadeOut(ms, cb) { this.els.forEach(e => { e.style.display = 'none'; if (cb) cb.call(e); }); return this; }
        trigger(type) { this.els.forEach(e => e.dispatch(type)); return this; }
        dialog(...args) { dom.dialogCalls.push(args); return this; }
    }
    function $(arg) {
        if (typeof arg === 'function') { arg($); return undefined; }
        if (arg instanceof JQ) return arg;
        if (typeof arg === 'string') {
            const create = /^<([a-z][\w-]*)\s*\/?>$/i.exec(arg);
            if (create) return new JQ([dom.createElement(create[1])]);
            return new JQ(dom.querySelectorAll(arg));
        }
        if (Array.isArray(arg)) return new JQ(arg);
        return new JQ(arg ? [arg] : []);
    }
    $.each = (list, fn) => { Array.from(list).forEach((item, i) => fn.call(item, i, item)); };
    $.fn = JQ.prototype;
    return $;
}

/* --------------------------------------------------------------------- i18n */

function sprintf(fmt, ...args) {
    let next = 0;
    return String(fmt).replace(/%(?:(\d+)\$)?([sd%])/g, (m, pos, type) => {
        if (type === '%') return '%';
        const v = args[pos ? Number(pos) - 1 : next++];
        return type === 'd' ? String(parseInt(v, 10)) : String(v);
    });
}

/* --------------------------------------------------------------------- page */

const flush = () => new Promise(resolve => setImmediate(resolve));

/**
 * Build a page, load admin-scripts.js into it and fire DOMContentLoaded.
 *
 * options: { html, admin (wcMssAdmin overrides), globals, jquery:false, boot:false }
 */
function createPage(options = {}) {
    const dom = new FakeDocument();
    dom.dialogCalls = [];
    if (options.html) dom.mount(options.html);

    const requests = [], notices = [], timers = [], downloads = [], blobs = new Map(), confirms = [];
    const revoked = [], windowListeners = {}, clipboard = { written: [], fail: false };
    let objectUrls = 0;

    const sandbox = {};
    sandbox.window = sandbox;
    sandbox.document = dom;
    sandbox.location = { href: 'https://example.test/wp-admin/admin.php?page=wc-mss', reloads: 0, reload() { this.reloads++; } };
    sandbox.innerWidth = 1400;
    sandbox.innerHeight = 900;
    sandbox.addEventListener = (name, fn, capture) => { (windowListeners[name] ||= []).push({ fn, capture }); };
    sandbox.onbeforeunload = () => 'leave?';
    sandbox.wcMssAdmin = Object.assign({
        ajax_url: '/wp-admin/admin-ajax.php',
        nonce: 'nonce-main',
        i18n: {
            request_failed: 'Request failed', failed_to_update: 'Failed to update', syncing: 'Syncing...',
            confirm_sync_all: 'Sync everything?', copied: 'Copied!', copy_failed: 'Copy failed'
        }
    }, options.admin || {});
    sandbox.wp = {
        i18n: { __: s => s, sprintf },
        ajax: {
            send(action, opts) {
                const req = {
                    action, url: opts.url, data: opts.data, nonce: opts.data.nonce,
                    ok(result) { opts.success(result); },
                    fail(result) { opts.error(result); }
                };
                requests.push(req);
            }
        }
    };
    const ui = createUI(sandbox.wp, dom, () => sandbox.wcMssAdmin);
    sandbox.wcMssAdminUI = ui;
    sandbox.wcMssRequest = ui.request;
    sandbox.wcMssNotice = (target, text, type) => {
        const node = ui.notice(target, text, type);
        notices.push({
            target, text, type, node,
            level: /notice-(\w+)/.exec(node.className)[1],
            message: node.querySelector('.wc-mss-notice-message')
        });
        return node;
    };

    const page = {
        dom, sandbox, requests, notices, timers, downloads, blobs, confirms, revoked, clipboard, windowListeners,
        confirmAnswer: true,
        window: sandbox,
        location: sandbox.location,
        byId: id => dom.getElementById(id),
        q: sel => dom.querySelector(sel),
        qa: sel => dom.querySelectorAll(sel),
        flush,
        /** Run and clear every pending setTimeout callback. */
        runTimers() { timers.splice(0).forEach(t => t.fn()); },
        lastNotice: () => notices[notices.length - 1],
        /** Requests (not yet answered) for a given action. */
        reqs: action => requests.filter(r => r.action === action),
        /** Answer a request like the server's wp_send_json_success()/error(). */
        ok(req, data) { req.ok(data); return flush(); },
        fail(req, message) { req.fail(message); return flush(); }
    };

    sandbox.confirm = message => { confirms.push(message); return page.confirmAnswer; };
    sandbox.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
    sandbox.navigator = { clipboard: { writeText(text) { clipboard.written.push(text); return clipboard.fail ? Promise.reject(new Error('denied')) : Promise.resolve(); } } };
    sandbox.URLSearchParams = URLSearchParams;
    sandbox.atob = atob;
    sandbox.ajaxurl = '/wp-admin/admin-ajax.php';
    sandbox.Blob = class Blob { constructor(parts, opts) { this.parts = parts; this.type = opts && opts.type; } };
    sandbox.URL = {
        createObjectURL(blob) { const u = 'blob:fake/' + (++objectUrls); blobs.set(u, blob); return u; },
        revokeObjectURL(u) { revoked.push(u); }
    };
    sandbox.FileReader = class FileReader {
        readAsText(file) {
            Promise.resolve().then(() => {
                if (file.error) { if (this.onerror) this.onerror({}); } else { this.onload({ target: { result: file.text } }); }
            });
        }
    };
    dom.onDefaultClick = el => {
        if (el.tagName === 'A' && el.download) {
            downloads.push({ href: el.href, filename: el.download, blob: blobs.get(el.href), attached: !!el.parentNode });
        }
    };
    if (options.jquery !== false) sandbox.jQuery = makeJQuery(dom);
    Object.assign(sandbox, options.globals || {});

    vm.runInNewContext(fs.readFileSync(SCRIPT_PATH, 'utf8'), sandbox);
    if (options.boot !== false) dom.dispatch('DOMContentLoaded');
    return page;
}

module.exports = { createPage, FakeDocument, FakeElement, makeJQuery, parseSelector, sprintf, flush };
