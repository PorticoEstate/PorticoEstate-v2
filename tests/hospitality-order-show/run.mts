/**
 * Hermetic check of the admin order page's Save (src/modules/booking/html/base/hospitality/order_show/order_show.js,
 * task 49777, §49718, pe-backlog/13923): the REAL page script runs on a stub DOM, against the REAL twig's data-lang-*
 * attributes and the REAL .lang rows, with fixtures shaped like the live orders 204 (A), 205 (B), 206 (C) and 207 (S) that
 * 49763 measured on 7891c67b6.
 *
 *   node tests/hospitality-order-show/run.mts
 *   HM_ROOT=<a tree that has the files listed in FILES> node tests/hospitality-order-show/run.mts
 *
 * HM_ROOT is how the RED arms are run: mutate.py points it at a copy carrying one deliberate defect, and at the tree as it
 * was on base (7891c67b6).
 *
 * The defect (49763): Save sent `serving_time_iso: null` whenever the time select could not hold the stored serving time,
 * whatever the officer had touched; the server wrote it. The select cannot hold it in three independent shapes:
 *   A  the order's location is not the hospitality's main room and no date lists it    → the date select offers no date
 *   B  the application (or its group) has several dates                                  → the date select is empty
 *   C  one date, but the stored time is off the date's window / quarter-hour grid        → the time select is empty
 *   S  one date and the stored time is a slot of it                                       → preselected: the SAFE control
 *
 * The rule the page now keeps (49766): serving_time_iso (and application_id) are sent only when the officer CHOSE a time;
 * a date change clears that; a date picked with no time chosen is refused with the existing «select time» text (D2).
 * Task 49934 (the follow-up, facts 49917/49918): the time PLACEHOLDER counts as a choice (a deliberate clear) only when the
 * time list opened on the STORED slot (S, or B on its stored date), i.e. only where the officer could see the time they
 * clear. Anywhere else choosing it puts the list back to how it opened: nothing chosen, so «no changes», or D2 if a date
 * is still picked. On C (the stored time is in no list) that leaves «set a time» and no clear: accepted by pe-ceo, 49937.
 *
 * What it holds the page to (each row names the rule it is the test of):
 *   P1  the fixtures reach the declared edit states (date/time select value, enabled, option count) — the premise of every row
 *   C1  the recorder sees every request the page makes, and every one is answered by a route (a zero below is a real zero)
 *   L1  load and the edit toggle write nothing                                                (control: green on base)
 *   L2  a save with nothing touched sends nothing and says «no changes»                       (FIX row: A, B, C)
 *   L3  a line-only save sends the line and nothing for the serving time                      (FIX row: A, B, C)
 *   L4  a comment-only save sends the comment, with no serving_time_iso and no application_id (FIX row: A, B, C)
 *   L5  an intentional time change still saves, with its application_id                       (control: B, C, S)
 *   L6  a deliberate clear (the time placeholder chosen) still saves null, where the list    (control: B on its stored
 *       opened on the stored slot, also after another slot was chosen                         date, S)
 *   L6c the placeholder where the stored time is in NO list is not a clear (49918)            (FIX row: C)
 *   L6d the placeholder after a time on a date that does NOT hold the stored time: refused    (FIX row: B; the 49917 sequence)
 *       like D2, nothing sent, the order stays on its application, the page is not wedged
 *   L6e the list re-derives «opened on the stored slot» at every rebuild: stored date, other   (control: B)
 *       date, stored date again, a time, the placeholder: a clear
 *   E1  the clear map: of every realizable pick sequence, the ones that save serving_time_iso  (FIX row: B, C)
 *       null exist exactly on B's stored date and on S
 *   L7  a time chosen, then the DATE changed: nothing is sent                                 (FIX row: B)
 *   L7b a time chosen, then the date put back to the placeholder: nothing sent, no warning    (FIX row: B)
 *   L8  the safe shape S: a save of something else sends no write for the serving time        (FIX row: S)
 *   D2  a date picked and no time chosen: refused with the «select time» text, nothing sent   (FIX row: B)
 *   D2b … and a pending line change is not saved either (the whole save is refused)           (FIX row: B)
 *   D2c … and choosing a time afterwards saves (the refusal does not wedge the page)          (FIX row: B)
 *   D2p the slot PRESELECTED for the stored time is not a choice: the date re-picked → refused (SPEC row, 49777)
 *   S1  the twig carries the «select time» attribute and the .lang rows exist in no / nn / en
 *   S3  the twig has the element ids the stub page is built from
 *
 * EVERY CHECK CAN FAIL: mutate.py applies one defect at a time and expects red, and runs the tree as it was on base to show
 * which rows are FIX rows (red there). A row that is green on base is named a control there, not counted as a fix.
 *
 * Not modelled (and so not tested here): MutationObserver (the «cancelled» path of the changelog dialog), timers (the toast
 * is never removed), layout. The live arms in a dev window (live.cjs) carry what a stub cannot.
 *
 * Exit code: 0 all pass, 1 any failure, 2 the harness itself crashed.
 */
process.env.TZ = 'Europe/Oslo';

import {readFileSync} from 'node:fs';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const root = resolve(process.env.HM_ROOT ?? repo);

const BOOKING = 'src/modules/booking';
const FILES = {
    js: `${BOOKING}/html/base/hospitality/order_show/order_show.js`,
    twig: `${BOOKING}/html/base/hospitality/order_show/order_show.twig`,
    langNo: `${BOOKING}/setup/phpgw_no.lang`,
    langNn: `${BOOKING}/setup/phpgw_nn.lang`,
    langEn: `${BOOKING}/setup/phpgw_en.lang`,
    commonNo: 'src/modules/phpgwapi/setup/phpgw_no.lang',   // read from the repo, never mutated: only the save button's text
};

let n = 0;
let failed = 0;
const check = (id: string, name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) { failed++; console.log(`FAIL ${id} ${name}${detail ? ' — ' + detail : ''}`); }
};
const show = (v: unknown) => JSON.stringify(v);
const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);
/** A scenario that cannot reach its own end is a FAILED ROW named by `id`, not a crash: a crash would hide every row after it. */
const attempt = async (id: string, scenario: () => Promise<void>) => {
    try { await scenario(); } catch (error) { check(id, 'the scenario ran to its end', false, (error as Error).message); }
};

// A missing file is a failed check, never a crash: a harness that dies has not looked at anything.
const read = (rel: string, fromRepo = false): string => {
    try {
        return readFileSync(join(fromRepo ? repo : root, rel), 'utf8');
    } catch (error) {
        check('S0', `can read ${rel}`, false, String(error));
        return '';
    }
};

// ---- a stub DOM, as small as order_show.js needs ------------------------------------------------------------------
const VOID = new Set(['input', 'br', 'img', 'hr']);
type Listener = (e: any) => void;
const decode = (s: string) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
    .replace(/&mdash;/g, '—').replace(/&times;/g, '×').replace(/&amp;/g, '&');
const encodeText = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** One compound selector: tag, #id, .class, [attr], [attr="value"]. */
function matchesCompound(el: El, sel: string): boolean {
    const m = sel.match(/^([a-zA-Z0-9]*)((?:[.#][\w-]+|\[[\w-]+(?:="[^"]*")?\])*)$/);
    if (!m) throw new Error(`stub DOM: unsupported selector ${sel}`);
    if (m[1] && m[1].toLowerCase() !== el.tag) return false;
    for (const part of m[2].match(/[.#][\w-]+|\[[^\]]+\]/g) ?? []) {
        if (part[0] === '.') { if (!el.classList.contains(part.slice(1))) return false; }
        else if (part[0] === '#') { if (el.id !== part.slice(1)) return false; }
        else {
            const a = part.slice(1, -1).match(/^([\w-]+)(?:="([^"]*)")?$/)!;
            if (!(a[1] in el.attrs) || (a[2] !== undefined && el.attrs[a[1]] !== a[2])) return false;
        }
    }
    return true;
}
/** «a, b» lists and «a b» descendant chains (the only combinators the page uses). */
function matchesSelector(el: El, selector: string): boolean {
    return selector.split(',').some(alt => {
        const chain = alt.trim().split(/\s+/);
        if (!matchesCompound(el, chain[chain.length - 1])) return false;
        let at: El | null = el.parent;
        for (let i = chain.length - 2; i >= 0; i--) {
            while (at && !matchesCompound(at, chain[i])) at = at.parent;
            if (!at) return false;
            at = at.parent;
        }
        return true;
    });
}

class El {
    tag: string;
    attrs: Record<string, string> = {};
    children: El[] = [];
    parent: El | null = null;
    listeners: Record<string, Listener[]> = {};
    disabled = false;
    hidden = false;
    textOwn = '';
    selFlag = false;
    private cls = new Set<string>();
    private val: string | null = null;
    sel = 0;
    classList = {
        add: (c: string) => { this.cls.add(c); },
        remove: (c: string) => { this.cls.delete(c); },
        toggle: (c: string, on?: boolean) => { (on ?? !this.cls.has(c)) ? this.cls.add(c) : this.cls.delete(c); },
        contains: (c: string) => this.cls.has(c),
    };

    constructor(tag: string) { this.tag = tag.toLowerCase(); }

    get tagName() { return this.tag.toUpperCase(); }
    get className() { return [...this.cls].join(' '); }
    set className(v: string) { this.cls = new Set(v.split(/\s+/).filter(Boolean)); }
    get id() { return this.attrs.id ?? ''; }
    set id(v: string) { this.attrs.id = v; }
    get options() { return this.children.filter(c => c.tag === 'option'); }

    /** data-foo-bar ⇄ dataset.fooBar, live on the attributes, as in a browser (assignment sets, `delete` removes). */
    get dataset(): Record<string, string | undefined> {
        const attrs = this.attrs;
        const key = (p: string) => 'data-' + p.replace(/[A-Z]/g, c => '-' + c.toLowerCase());
        return new Proxy({}, {
            get: (_t, p) => typeof p === 'string' ? attrs[key(p)] : undefined,
            set: (_t, p, v) => { attrs[key(String(p))] = String(v); return true; },
            deleteProperty: (_t, p) => { delete attrs[key(String(p))]; return true; },
            has: (_t, p) => key(String(p)) in attrs,
        });
    }

    get value(): string {
        if (this.tag === 'select') return this.options[this.sel]?.value ?? '';
        if (this.tag === 'option') return this.val ?? this.attrs.value ?? this.textContent;
        if (this.tag === 'textarea') return this.val ?? this.textContent;
        return this.val ?? this.attrs.value ?? '';
    }
    set value(v: string) {
        if (this.tag === 'select') this.sel = this.options.findIndex(o => o.value === String(v));
        else this.val = String(v);
    }
    set selected(on: boolean) {
        this.selFlag = on;
        if (on && this.parent?.tag === 'select') this.parent.sel = this.parent.options.indexOf(this);
    }
    get selected() { return this.parent?.tag === 'select' ? this.parent.options[this.parent.sel] === this : this.selFlag; }

    get textContent(): string { return this.children.length ? this.children.map(c => c.textContent).join('') : this.textOwn; }
    set textContent(t: string) { this.children = []; this.textOwn = String(t); }
    set innerHTML(html: string) {
        this.children = []; this.textOwn = ''; this.sel = 0;
        if (html !== '') parseInto(this, html);
    }
    get innerHTML(): string {
        return this.children.length ? this.children.map(c => c.outerHTML).join('') : encodeText(this.textOwn);
    }
    get outerHTML(): string {
        const a = Object.entries(this.attrs).map(([k, v]) => ` ${k}="${v}"`).join('');
        return VOID.has(this.tag) ? `<${this.tag}${a}>` : `<${this.tag}${a}>${this.innerHTML}</${this.tag}>`;
    }

    addEventListener(type: string, fn: Listener) { (this.listeners[type] ??= []).push(fn); }
    removeEventListener(type: string, fn: Listener) { this.listeners[type] = (this.listeners[type] ?? []).filter(f => f !== fn); }
    appendChild(c: El) {
        c.parent = this; this.children.push(c);
        if (this.tag === 'select' && c.tag === 'option' && c.selFlag) this.sel = this.options.length - 1;
        return c;
    }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(c => c !== this); this.parent = null; }
    focus() {}
    showModal() {}
    close() {}
    /** Bubbles to the ancestors, as click / input / change do; a listener runs with `this` = the element it is on. */
    dispatch(type: string, extra: Record<string, unknown> = {}) {
        let stopped = false;
        const ev = {type, target: this, key: undefined, preventDefault() {}, stopPropagation() { stopped = true; }, ...extra};
        for (let x: El | null = this; x && !stopped; x = x.parent) for (const fn of [...(x.listeners[type] ?? [])]) fn.call(x, ev);
    }
    click() { if (!this.disabled) this.dispatch('click'); }
    closest(sel: string): El | null { for (let x: El | null = this; x; x = x.parent) if (matchesSelector(x, sel)) return x; return null; }
    querySelectorAll(sel: string): El[] {
        const out: El[] = [];
        const walk = (e: El) => { for (const c of e.children) { if (matchesSelector(c, sel)) out.push(c); walk(c); } };
        walk(this);
        return out;
    }
    querySelector(sel: string): El | null { return this.querySelectorAll(sel)[0] ?? null; }
}

function parseInto(parent: El, html: string) {
    const stack: El[] = [parent];
    const re = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s[^>]*?)?)\s*(\/?)>|([^<]+)/g;
    for (const m of html.matchAll(re)) {
        const top = stack[stack.length - 1];
        if (m[5] !== undefined) { top.textOwn += decode(m[5]); continue; }
        const tag = m[2].toLowerCase();
        if (m[1] === '/') {
            const at = stack.map(s => s.tag).lastIndexOf(tag);
            if (at > 0) stack.length = at;
            continue;
        }
        const el = new El(tag);
        for (const a of m[3].matchAll(/([a-zA-Z_:][-\w:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'))?/g)) el.attrs[a[1]] = decode(a[2] ?? a[3] ?? '');
        if (el.attrs.class) el.className = el.attrs.class;
        if ('disabled' in el.attrs) el.disabled = true;
        if ('hidden' in el.attrs) el.hidden = true;
        if ('selected' in el.attrs) el.selFlag = true;
        top.appendChild(el);
        if (!VOID.has(tag) && m[4] !== '/') stack.push(el);
    }
}

class FakeDocument {
    body = new El('body');
    createElement(tag: string) { return new El(tag); }
    getElementById(id: string): El | null { return this.body.querySelector(`#${id}`); }
}
class FakeObserver { observe() {} disconnect() {} }

const settle = async () => { for (let i = 0; i < 12; i++) await new Promise<void>(r => setImmediate(r)); };

// ---- lang(): resolved the way the host does, through the twig's data-lang-* attributes and the .lang files --------------
const camel = (kebab: string) => kebab.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase());
function langRows(file: string): Record<string, string> {
    const rows: Record<string, string> = {};
    for (const line of file.split('\n')) {
        const [key, module, , text] = line.split('\t');
        if (key && module && text !== undefined) rows[`${module}.${key}`] = text.trim();
    }
    return rows;
}
const twigSrc = read(FILES.twig);
const langNo = {...langRows(read(FILES.commonNo, true)), ...langRows(read(FILES.langNo))};
/** The root's data-lang-* attributes, as the twig writes them: `data-lang-select-time="{{ lang('booking.select_time') }}"`. */
const LANG_ATTRS: Record<string, string> = {};
for (const m of twigSrc.matchAll(/data-(lang-[a-z0-9-]+)="\{\{\s*lang\('([a-zA-Z]+)\.([A-Za-z0-9_]+)'\)\s*\}\}"/g)) {
    LANG_ATTRS[`data-${m[1]}`] = langNo[`${m[2]}.${m[3]}`] ?? `«${m[2]}.${m[3]}»`;
}
const SELECT_TIME = langNo['booking.select_time'];     // «Velg tid»
const NO_CHANGES = langNo['booking.no_changes'];       // «Ingen endringer registrert»
const SAVED = langNo['booking.saved'];                 // «Lagret»

// ---- fixtures, shaped like the live responses of orders 204-207 (49763) ------------------------------------------------
type ShapeKey = 'A' | 'B' | 'C' | 'S';
type Shape = {key: ShapeKey; order: any; hospitality: any; groups: any[]; articles: any[]; dates: any[]; lineArticle: number; lineId: number};

const date = (from: string, to: string, resources: number[], application_id: number) => ({from_: from, to_: to, resources, application_id});
const art = (id: number, hospitality_id: number, group: number | null, name: string, price: string) =>
    ({id, hospitality_id, article_group_id: group, active: 1, article_name: name, unit: 'each', base_price: price, effective_price: price});
const line = (id: number, order_id: number, article: number, name: string, price: string) =>
    ({id, order_id, hospitality_article_id: article, quantity: '1.00', unit_price: price, tax_code: 12, tax_percent: 25, amount: price, article_name: name, unit: 'each'});
const orderOf = (id: number, application_id: number, hospitality_id: number, location: number, serving: string, comment: string, lines: any[]) => ({
    id, application_id, hospitality_id, location_resource_id: location, status: 'pending', comment, special_requirements: null,
    serving_time_iso: serving, created: '2026-11-24T23:56:48+01:00', modified: '2026-11-25T00:16:40+01:00',
    hospitality_name: 'x', location_name: 'y', lines, changelog: [],
});

const H4 = {id: 4, resource_id: 1, name: 'Kantine Kulturhuset'};
const H6 = {id: 6, resource_id: 482, name: 'Fana kulturhus mat og drikke'};
const SOUP = art(15, 6, 16, 'Kyllingsuppe porsjon', '65.00');
const GROUPS_6 = [{id: 16, hospitality_id: 6, name: 'Forrett', sort_order: 0, active: 1, articles: [SOUP]}];

const SHAPES: Record<ShapeKey, Shape> = {
    // 204: location 7 is not the main room (1) and no date lists it → the date select offers nothing
    A: {key: 'A', hospitality: H4, groups: [], lineArticle: 6, lineId: 320,
        articles: [art(6, 4, null, 'Red Bull 33cl', '25.00'), art(11, 4, null, 'Kyllingsuppe porsjon', '77.00')],
        dates: [date('2026-12-10T10:00:00+01:00', '2026-12-10T12:00:00+01:00', [1], 85167)],
        order: orderOf(204, 85167, 4, 7, '2026-12-10T10:30:00+01:00', 'T49718-A-loc-not-main-0-dates',
            [line(320, 204, 6, 'Red Bull 33cl', '25.00'), line(324, 204, 11, 'Kyllingsuppe porsjon', '77.00')])},
    // 205: the main room, a group of three dates → the date select is empty; the stored time is 19:00 on the first date
    B: {key: 'B', hospitality: H6, groups: GROUPS_6, articles: [SOUP], lineArticle: 15, lineId: 321,
        dates: [date('2026-06-06T16:30:00+02:00', '2026-06-06T19:30:00+02:00', [482], 83734),
                date('2026-06-07T16:00:00+02:00', '2026-06-07T20:00:00+02:00', [125, 482], 83735),
                date('2026-06-08T16:00:00+02:00', '2026-06-08T18:30:00+02:00', [482], 83736)],
        order: orderOf(205, 83734, 6, 482, '2026-06-06T19:00:00+02:00', 'T49718-B-main-3-dates-group', [line(321, 205, 15, 'Kyllingsuppe porsjon', '65.00')])},
    // 206: one date, the stored time is outside it → the time select is empty
    C: {key: 'C', hospitality: H6, groups: GROUPS_6, articles: [SOUP], lineArticle: 15, lineId: 322,
        dates: [date('2029-06-04T12:30:00+02:00', '2029-06-04T15:00:00+02:00', [125], 84278)],
        order: orderOf(206, 84278, 6, 482, '2026-11-03T12:00:00+01:00', 'T49718-C-1-date-stored-outside', [line(322, 206, 15, 'Kyllingsuppe porsjon', '65.00')])},
    // 207: one date, the stored 12:30 is a slot of it → preselected (the SAFE control)
    S: {key: 'S', hospitality: H6, groups: GROUPS_6, articles: [SOUP], lineArticle: 15, lineId: 323,
        dates: [date('2026-12-11T12:00:00+01:00', '2026-12-11T13:30:00+01:00', [781], 85166)],
        order: orderOf(207, 85166, 6, 482, '2026-12-11T12:30:00+01:00', 'T49718-S-1-date-slot-matches', [line(323, 207, 15, 'Kyllingsuppe porsjon', '65.00')])},
};

// ---- a backend that answers the page's requests and records every one ------------------------------------------------
type Req = {method: string; path: string; body?: any};
class Backend {
    shape: Shape;
    order: any;
    requests: Req[] = [];
    unrouted: string[] = [];
    constructor(shape: Shape) { this.shape = shape; this.order = structuredClone(shape.order); }

    private answer(method: string, path: string, body: any): unknown | undefined {
        const s = this.shape, o = this.order;
        if (method === 'GET') {
            if (path === `/booking/hospitality-orders/${o.id}`) return o;
            if (path === `/booking/hospitality/${s.hospitality.id}/article-groups`) return s.groups;
            if (path === `/booking/hospitality/${s.hospitality.id}/articles`) return s.articles;
            if (path === `/booking/hospitality/${s.hospitality.id}`) return s.hospitality;
            if (path === `/booking/applications/${o.application_id}/dates`) return s.dates;
            if (path === `/booking/applications/${o.application_id}/related`) return [];
            return undefined;
        }
        if (method === 'PUT' && path === `/booking/hospitality-orders/${o.id}`) {
            const {changelog_comment, ...fields} = body ?? {};
            Object.assign(o, fields);                          // an explicit null is written, as the server does
            return o;
        }
        const lines = path.match(new RegExp(`^/booking/hospitality-orders/${o.id}/lines(?:/(\\d+))?$`));
        if (lines && method === 'PUT') { const l = o.lines.find((x: any) => x.id === +lines[1]); if (l && body?.quantity != null) l.quantity = body.quantity; return o; }
        if (lines && method === 'POST') { o.lines.push({id: 999, ...body}); return o; }
        if (lines && method === 'DELETE') { o.lines = o.lines.filter((x: any) => x.id !== +lines[1]); return o; }
        return undefined;
    }

    fetch = (url: string, init?: {method?: string; body?: string}) => {
        const method = init?.method ?? 'GET';
        const path = url.split('?')[0];
        const body = init?.body ? JSON.parse(init.body) : undefined;
        this.requests.push({method, path, ...(body !== undefined ? {body} : {})});
        const data = this.answer(method, path, body);
        if (data === undefined) this.unrouted.push(`${method} ${path}`);
        return Promise.resolve({
            ok: data !== undefined, status: data !== undefined ? 200 : 404,
            json: async () => structuredClone(data ?? {error: 'no route in the harness'}),
        });
    };
    get serving(): string | null { return this.order.serving_time_iso; }
}

// ---- one page on stub elements, driven the way an officer drives it ---------------------------------------------------
const SRC = read(FILES.js);
const run = (doc: FakeDocument, backend: Backend) =>
    new Function('document', 'fetch', 'MutationObserver', 'setTimeout', SRC)(doc, backend.fetch, FakeObserver, () => 0);

async function open(key: ShapeKey) {
    const shape = SHAPES[key];
    const doc = new FakeDocument();
    const backend = new Backend(shape);
    const rootEl = new El('div');
    Object.assign(rootEl.attrs, {
        id: 'order-show', 'data-order-id': String(shape.order.id), 'data-order-url': `/booking/hospitality-orders/${shape.order.id}`,
        'data-orders-base-url': '/booking/hospitality-orders', 'data-hospitality-base-url': '/booking/hospitality',
        'data-applications-base-url': '/booking/applications', 'data-can-write': '1', 'data-back-application-id': '0', ...LANG_ATTRS,
    });
    rootEl.innerHTML = '<div id="order-loading"></div><div id="order-error" hidden><p id="order-error-message"></p></div>' +
        '<div id="order-content" hidden><div id="order-header"></div><div id="order-details"></div><div id="order-lines"></div><div id="order-changelog"></div></div>';
    doc.body.appendChild(rootEl);
    run(doc, backend);
    await settle();

    let dialogs = 0;
    const page = {
        key, backend, doc, rootEl,
        get dialogsShown() { return dialogs; },
        writes: () => backend.requests.filter(r => r.method !== 'GET'),
        toasts: () => doc.body.querySelectorAll('.app-show__toast').map(t => ({text: t.textContent, color: t.attrs['data-color']})),
        date: () => rootEl.querySelector('[data-edit-date]')!,
        time: () => rootEl.querySelector('[data-edit-time]')!,
        async edit() { rootEl.querySelector('#toggle-edit-mode')!.click(); await settle(); },
        pickDate(i: number | '') { const d = page.date(); d.value = String(i); d.dispatch('change'); },
        /** by the text of the slot, «18:00»: what an officer reads; '' is the placeholder. */
        pickTime(label: string) {
            const t = page.time();
            const opt = label === '' ? t.options[0] : t.options.find(o => o.textContent === label);
            if (!opt) throw new Error(`no time «${label}» in the list: ${show(t.options.map(o => o.textContent))}`);
            t.value = opt.value; t.dispatch('change');
        },
        typeComment(text: string) { const c = rootEl.querySelector('textarea[data-edit-field="comment"]')!; c.value = text; c.dispatch('input'); },
        setQuantity(article: number, qty: number) { const q = rootEl.querySelector(`[data-qty-article="${article}"]`)!; q.value = String(qty); q.dispatch('input'); },
        /** Lagre; where the page asks for the changelog comment, answer it, as the officer must. */
        async save() {
            rootEl.querySelector('#save-all-changes')!.click();
            await settle();
            if (doc.getElementById('changelog-comment-dialog')) {
                dialogs++;
                doc.getElementById('changelog-comment-input')!.value = 'T49777 harness';
                doc.getElementById('changelog-comment-submit')!.click();
                await settle();
            }
        },
    };
    return page;
}
type Page = Awaited<ReturnType<typeof open>>;

const ORIGINAL = (k: ShapeKey) => SHAPES[k].order.serving_time_iso as string;
const NO_WRITES = (p: Page, id: string, label: string) => {
    check(id, `${label}: no write request`, p.writes().length === 0, show(p.writes()));
    check(id, `${label}: the stored serving time is unchanged`, p.backend.serving === ORIGINAL(p.key), `now ${show(p.backend.serving)}`);
};
const orderPuts = (p: Page) => p.writes().filter(r => r.method === 'PUT' && r.path === `/booking/hospitality-orders/${p.backend.order.id}`);
const linePuts = (p: Page) => p.writes().filter(r => r.method === 'PUT' && r.path.includes('/lines/'));
const KEYS = (r?: Req) => Object.keys(r?.body ?? {}).sort();
const SHAPE_KEYS: ShapeKey[] = ['A', 'B', 'C', 'S'];

// ======================================================================================================================
// the rows
// ======================================================================================================================
async function main() {
    // ---- S1, S3: the twig and the .lang rows the page's own text comes from ----------------------------------------------
    check('S1', 'the twig wires data-lang-select-time to booking.select_time', /data-lang-select-time="\{\{\s*lang\('booking\.select_time'\)\s*\}\}"/.test(twigSrc));
    for (const [name, file] of [['no', FILES.langNo], ['nn', FILES.langNn], ['en', FILES.langEn]] as const) {
        check('S1', `the ${name} .lang file has booking.select_time`, new RegExp(`^select_time\\tbooking\\t${name}\\t\\S`, 'm').test(read(file)));
    }
    check('S1', 'the warning text resolves to a real translation, not the key', !!SELECT_TIME && SELECT_TIME !== 'selectTime', show(SELECT_TIME));
    for (const id of ['order-show', 'order-loading', 'order-error', 'order-error-message', 'order-content', 'order-header', 'order-details', 'order-lines', 'order-changelog']) {
        check('S3', `the twig has #${id}, which the stub page is built from`, twigSrc.includes(`id="${id}"`));
    }

    // ---- P1, C1, L1: the premise, the instrument, the control ---------------------------------------------------------
    const EDIT_STATE: Record<ShapeKey, {date: string; dateDisabled: boolean; dateOptions: number; time: string; timeDisabled: boolean; timeOptions: number}> = {
        A: {date: '', dateDisabled: false, dateOptions: 1, time: '', timeDisabled: true, timeOptions: 1},
        B: {date: '', dateDisabled: false, dateOptions: 4, time: '', timeDisabled: true, timeOptions: 1},
        C: {date: '0', dateDisabled: true, dateOptions: 2, time: '', timeDisabled: false, timeOptions: 12},   // 12:30..15:00 = 11 slots + placeholder
        S: {date: '0', dateDisabled: true, dateOptions: 2, time: '2026-12-11T12:30:00+01:00', timeDisabled: false, timeOptions: 8},   // 12:00..13:30 = 7 + placeholder
    };
    for (const k of SHAPE_KEYS) {
        await attempt('P1', async () => {
            const p = await open(k);
            check('L1', `${k}: loading the page writes nothing`, p.writes().length === 0, show(p.writes()));
            check('C1', `${k}: the recorder saw the page's own loads`, p.backend.requests.filter(r => r.method === 'GET').length >= 5, show(p.backend.requests.map(r => r.path)));
            await p.edit();
            check('L1', `${k}: the edit toggle writes nothing`, p.writes().length === 0, show(p.writes()));
            const got = {date: p.date().value, dateDisabled: p.date().disabled, dateOptions: p.date().options.length,
                time: p.time().value, timeDisabled: p.time().disabled, timeOptions: p.time().options.length};
            check('P1', `${k}: the edit state is the declared one`, same(got, EDIT_STATE[k]), `${show(got)} ≠ ${show(EDIT_STATE[k])}`);
            check('C1', `${k}: every request was answered by a route`, p.backend.unrouted.length === 0, show(p.backend.unrouted));
        });
    }

    // ---- L2: nothing touched ⇒ nothing sent, «no changes» -------------------------------------------------------------
    for (const k of SHAPE_KEYS) {
        await attempt('L2', async () => {
            const p = await open(k);
            await p.edit();
            await p.save();
            NO_WRITES(p, 'L2', `${k}: a save with nothing touched`);
            check('L2', `${k}: it answers «no changes», as the toast`, same(p.toasts(), [{text: NO_CHANGES, color: 'info'}]), show(p.toasts()));
            check('L2', `${k}: no changelog-comment dialog was asked for`, p.dialogsShown === 0);
        });
    }

    // ---- L3: line only; L4: comment only ------------------------------------------------------------------------------
    for (const k of SHAPE_KEYS) {
        await attempt('L3', async () => {
            const p = await open(k);
            await p.edit();
            p.setQuantity(SHAPES[k].lineArticle, 2);
            await p.save();
            check('L3', `${k}: a line-only save sends the line`, linePuts(p).length === 1 && same(KEYS(linePuts(p)[0]), ['changelog_comment', 'quantity']) && linePuts(p)[0].body.quantity === 2, show(p.writes()));
            check('L3', `${k}: …and nothing for the order itself`, orderPuts(p).length === 0, show(orderPuts(p)));
            check('L3', `${k}: …and the stored serving time is unchanged`, p.backend.serving === ORIGINAL(k), show(p.backend.serving));
            check('L3', `${k}: …and it is reported as saved`, p.toasts().some(t => t.text === SAVED), show(p.toasts()));
        });
        await attempt('L4', async () => {
            const p = await open(k);
            await p.edit();
            p.typeComment('T49777 comment edited');
            await p.save();
            check('L4', `${k}: a comment-only save is one order PUT`, orderPuts(p).length === 1 && p.writes().length === 1, show(p.writes()));
            check('L4', `${k}: …carrying the comment and the changelog comment and NOTHING else (no serving_time_iso, no application_id)`,
                same(KEYS(orderPuts(p)[0]), ['changelog_comment', 'comment']) && orderPuts(p)[0].body.comment === 'T49777 comment edited', show(orderPuts(p)[0]?.body));
            check('L4', `${k}: …the comment is stored and the serving time is unchanged`, p.backend.order.comment === 'T49777 comment edited' && p.backend.serving === ORIGINAL(k), show(p.backend.serving));
        });
    }

    // ---- L5: an intentional change still saves (control) ---------------------------------------------------------------
    const CHANGE: Array<[ShapeKey, number | null, string, string, number]> = [
        // shape, date to pick (null: its only date is preselected), the slot, the iso that goes, the application the date belongs to
        ['B', 0, '18:00', '2026-06-06T18:00:00+02:00', 83734],
        ['C', null, '13:00', '2029-06-04T13:00:00+02:00', 84278],
        ['S', null, '13:00', '2026-12-11T13:00:00+01:00', 85166],
    ];
    for (const [k, dateIdx, slot, iso, app] of CHANGE) {
        await attempt('L5', async () => {
            const p = await open(k);
            await p.edit();
            if (dateIdx !== null) p.pickDate(dateIdx);
            p.pickTime(slot);
            await p.save();
            check('L5', `${k}: choosing ${slot} saves one order PUT with the slot and its application`,
                orderPuts(p).length === 1 && same(KEYS(orderPuts(p)[0]), ['application_id', 'changelog_comment', 'serving_time_iso'])
                && orderPuts(p)[0].body.serving_time_iso === iso && orderPuts(p)[0].body.application_id === app, show(orderPuts(p)[0]?.body));
            check('L5', `${k}: …and the stored serving time is now the slot`, p.backend.serving === iso, show(p.backend.serving));
        });
    }

    // ---- L6: a deliberate clear still saves (control) ------------------------------------------------------------------
    const CLEAR: Array<[ShapeKey, number | null, string | null, number]> = [
        ['B', 0, null, 83734],        // the stored date picked (the list opens on the stored 19:00), the time placeholder chosen
        ['B', 0, '18:00', 83734],     // …or another slot first, then the placeholder: the list opened on the stored slot
        ['S', null, null, 85166],     // the preselected slot replaced by the placeholder
        ['S', null, '13:00', 85166],  // …or another slot first, then the placeholder
    ];
    for (const [k, dateIdx, firstPick, app] of CLEAR) {
        await attempt('L6', async () => {
            const p = await open(k);
            await p.edit();
            if (dateIdx !== null) p.pickDate(dateIdx);
            if (firstPick) p.pickTime(firstPick);
            p.pickTime('');
            await p.save();
            const how = firstPick ? ` after ${firstPick}` : '';
            check('L6', `${k}: choosing the time placeholder${how} deliberately saves serving_time_iso null with its application`,
                orderPuts(p).length === 1 && orderPuts(p)[0].body.serving_time_iso === null && 'serving_time_iso' in orderPuts(p)[0].body && orderPuts(p)[0].body.application_id === app, show(orderPuts(p)[0]?.body));
            check('L6', `${k}: …and the stored serving time is cleared${how}`, p.backend.serving === null, show(p.backend.serving));
        });
    }

    // ---- L7: a time chosen, then the DATE changed ---------------------------------------------------------------------------
    await attempt('L7', async () => {
        const p = await open('B');
        await p.edit();
        p.pickDate(0); p.pickTime('18:00');
        p.pickDate(1);                                   // the 7th: the list is rebuilt, nothing chosen from it
        check('L7', 'B: the time list was rebuilt on the placeholder by the date change', p.time().value === '', show(p.time().value));
        await p.save();
        NO_WRITES(p, 'L7', 'B: a time chosen and then the date changed');
    });
    await attempt('L7b', async () => {
        const p = await open('B');
        await p.edit();
        p.pickDate(0); p.pickTime('18:00');
        p.pickDate('');                                  // the date put back to the placeholder: no date is picked
        await p.save();
        NO_WRITES(p, 'L7b', 'B: a time chosen and then the date put back to the placeholder');
        check('L7b', 'B: …no date is picked, so there is no warning; the page says «no changes»', same(p.toasts(), [{text: NO_CHANGES, color: 'info'}]), show(p.toasts()));
        // and a pending line is still saved: the page is not wedged by having been touched
        p.setQuantity(SHAPES.B.lineArticle, 2);
        await p.save();
        check('L7b', 'B: …a line change made afterwards saves, with no order PUT', linePuts(p).length === 1 && orderPuts(p).length === 0, show(p.writes()));
    });

    // ---- L8: the SAFE shape — a save of something else sends no write for the serving time --------------------------------
    await attempt('L8', async () => {
        const p = await open('S');
        await p.edit();
        await p.save();
        NO_WRITES(p, 'L8', 'S: a nothing-touched save (base sent {application_id}, a no-op write that bumps `modified`)');
    });
    await attempt('L8', async () => {
        const p = await open('S');
        await p.edit();
        p.setQuantity(SHAPES.S.lineArticle, 2);
        await p.save();
        check('L8', 'S: a line-only save is the line request and no order PUT', linePuts(p).length === 1 && p.writes().length === 1 && orderPuts(p).length === 0, show(p.writes()));
    });
    await attempt('L8', async () => {
        const p = await open('S');
        await p.edit();
        p.typeComment('T49777 comment edited');
        await p.save();
        check('L8', 'S: a comment-only save carries the comment and no application_id', orderPuts(p).length === 1 && same(KEYS(orderPuts(p)[0]), ['changelog_comment', 'comment']), show(orderPuts(p)[0]?.body));
    });

    // ---- D2: a date picked and no time chosen is refused with the warning ------------------------------------------------------
    const WARNING = [{text: SELECT_TIME, color: 'warning'}];
    await attempt('D2', async () => {
        const p = await open('B');
        await p.edit();
        p.pickDate(1);
        check('D2', 'B: picking the 7th leaves the time list on its placeholder (no slot is the stored time)', p.time().value === '' && !p.time().disabled, show(p.time().value));
        await p.save();
        check('D2', 'B: Lagre answers with the «select time» text, as a warning', same(p.toasts(), WARNING), show(p.toasts()));
        NO_WRITES(p, 'D2', 'B: a date picked and no time chosen');
        check('D2', 'B: …and no changelog-comment dialog was asked for', p.dialogsShown === 0);
    });
    await attempt('D2b', async () => {
        const p = await open('B');
        await p.edit();
        p.pickDate(1);
        p.setQuantity(SHAPES.B.lineArticle, 2);
        await p.save();
        check('D2b', 'B: with a line change pending, the save is refused with the warning', same(p.toasts(), WARNING), show(p.toasts()));
        NO_WRITES(p, 'D2b', 'B: a date picked, no time chosen and a line change pending (the whole save is refused)');
    });
    await attempt('D2c', async () => {
        const p = await open('B');
        await p.edit();
        p.pickDate(1);
        await p.save();
        check('D2c', 'B: the first save is refused', p.writes().length === 0 && same(p.toasts(), WARNING), show(p.toasts()));
        p.pickTime('17:00');
        await p.save();
        check('D2c', 'B: …choosing a time afterwards saves it, with the date\'s application',
            orderPuts(p).length === 1 && orderPuts(p)[0].body.serving_time_iso === '2026-06-07T17:00:00+02:00' && orderPuts(p)[0].body.application_id === 83735, show(orderPuts(p)[0]?.body));
    });
    await attempt('D2p', async () => {
        const p = await open('B');
        await p.edit();
        p.pickDate(0);                                   // the 6th holds the stored 19:00: the list preselects it
        check('D2p', 'B: re-picking the stored date preselects the stored slot', p.time().value === ORIGINAL('B'), show(p.time().value));
        await p.save();
        check('D2p', 'B: a preselected slot is not a choice: the save is refused with the warning', same(p.toasts(), WARNING), show(p.toasts()));
        NO_WRITES(p, 'D2p', 'B: the stored date re-picked, the preselected slot not chosen');
    });

    // ---- L6c: the placeholder where the stored time is in NO list is not a clear (49918) ------------------------------------
    await attempt('L6c', async () => {
        const p = await open('C');
        await p.edit();
        check('L6c', 'C: the list opens on the placeholder (the stored time is in no list)', p.time().value === '' && !p.time().disabled, show(p.time().value));
        p.pickTime('13:00'); p.pickTime('');
        await p.save();
        NO_WRITES(p, 'L6c', 'C: a slot chosen and then the placeholder (the list is back to how it opened)');
        check('L6c', 'C: …no date is picked (its only date is preselected), so no warning: the page says «no changes»', same(p.toasts(), [{text: NO_CHANGES, color: 'info'}]), show(p.toasts()));
        p.pickTime('14:00');
        await p.save();
        check('L6c', 'C: …and choosing a slot afterwards saves it with its application (the page is not wedged)',
            orderPuts(p).length === 1 && orderPuts(p)[0].body.serving_time_iso === '2029-06-04T14:00:00+02:00' && orderPuts(p)[0].body.application_id === 84278, show(orderPuts(p)[0]?.body));
    });
    await attempt('L6c', async () => {
        const p = await open('C');
        await p.edit();
        p.pickTime('13:00'); p.pickTime('');
        p.setQuantity(SHAPES.C.lineArticle, 2);
        await p.save();
        check('L6c', 'C: a slot chosen, then the placeholder, then a line change: the save is the line and no order PUT',
            linePuts(p).length === 1 && orderPuts(p).length === 0 && p.backend.serving === ORIGINAL('C'), show(p.writes()));
    });

    // ---- L6d: the placeholder after a time on a date that does not hold the stored time (the 49917 sequence) ------------------
    const stays = (p: Page) => p.backend.order.application_id === SHAPES.B.order.application_id;
    for (const [label, lead] of [['the 7th, a time, the placeholder', [1]], ['the stored date, then the 7th, a time, the placeholder', [0, 1]]] as Array<[string, number[]]>) {
        await attempt('L6d', async () => {
            const p = await open('B');
            await p.edit();
            for (const d of lead) p.pickDate(d);
            p.pickTime('17:00'); p.pickTime('');
            check('L6d', `B (${label}): the list is back on the placeholder`, p.time().value === '', show(p.time().value));
            await p.save();
            check('L6d', `B (${label}): Lagre answers the «select time» text, as for the date alone (D2)`, same(p.toasts(), WARNING), show(p.toasts()));
            NO_WRITES(p, 'L6d', `B (${label})`);
            check('L6d', `B (${label}): …the order stays on its own application`, stays(p), show(p.backend.order.application_id));
            check('L6d', `B (${label}): …and no changelog-comment dialog was asked for`, p.dialogsShown === 0);
            p.pickTime('18:00');
            await p.save();
            check('L6d', `B (${label}): …choosing a time afterwards saves it, with the date's application (the page is not wedged)`,
                orderPuts(p).length === 1 && orderPuts(p)[0].body.serving_time_iso === '2026-06-07T18:00:00+02:00' && orderPuts(p)[0].body.application_id === 83735, show(orderPuts(p)[0]?.body));
        });
    }

    // ---- L6e: «opened on the stored slot» is re-derived at every rebuild of the list ------------------------------------------
    await attempt('L6e', async () => {
        const p = await open('B');
        await p.edit();
        p.pickDate(1); p.pickDate(0);                    // the 7th, then back to the stored date: the list opens on the stored 19:00 again
        check('L6e', 'B: the stored date again preselects the stored slot', p.time().value === ORIGINAL('B'), show(p.time().value));
        p.pickTime('17:00'); p.pickTime('');
        await p.save();
        check('L6e', 'B: the placeholder is a clear again: saves serving_time_iso null with the stored date\'s application',
            orderPuts(p).length === 1 && orderPuts(p)[0].body.serving_time_iso === null && orderPuts(p)[0].body.application_id === 83734, show(orderPuts(p)[0]?.body));
        check('L6e', 'B: …and the stored serving time is cleared', p.backend.serving === null, show(p.backend.serving));
    });

    // ---- E1: the clear map ---------------------------------------------------------------------------------------------------
    // Every realizable sequence of up to three picks in the time list (a pick of what the list already shows fires no change
    // in a browser, and a disabled select cannot be picked), after no date or after each date the shape offers; Lagre. The
    // places where one of them saves serving_time_iso null must be exactly where the list opens on the stored slot.
    await attempt('E1', async () => {
        const labels = ['', '12:30', '13:00', '17:00', '18:00', '19:00'];
        const seqs: string[][] = [[]];
        for (const a of labels) { seqs.push([a]); for (const b of labels) { seqs.push([a, b]); for (const c of labels) seqs.push([a, b, c]); } }
        const clearable: string[] = [];
        let realized = 0;
        for (const k of SHAPE_KEYS) {
            const probe = await open(k); await probe.edit();
            const dates: Array<number | null> = probe.date().disabled ? [null] : [null, ...Array.from({length: probe.date().options.length - 1}, (_, i) => i)];
            for (const d of dates) {
                let any = false;
                for (const seq of seqs) {
                    const p = await open(k); await p.edit();
                    try {
                        if (d !== null) p.pickDate(d);
                        for (const t of seq) {
                            const tt = p.time();
                            const want = t === '' ? tt.options[0] : tt.options.find(o => o.textContent === t);
                            if (tt.disabled || !want || want.value === tt.value) throw new Error('not realizable');
                            p.pickTime(t);
                        }
                    } catch { continue; }
                    realized++;
                    await p.save();
                    if (p.writes().some(r => r.body && 'serving_time_iso' in r.body && r.body.serving_time_iso === null)) any = true;
                }
                if (any) clearable.push(`${k}/${d === null ? 'its date' : 'date ' + d}`);
            }
        }
        check('E1', `the instrument ran ${realized} realizable sequences (a map of zeros would be a broken instrument)`, realized > 100, `${realized}`);
        check('E1', 'a null serving time can be saved exactly on B\'s stored date (date 0) and on S, and nowhere else (not A, not C, not B\'s other dates)',
            same(clearable, ['B/date 0', 'S/its date']), show(clearable));
    });

    // ---- INFO (not a row): what is NOT changed, recorded so the report can cite a command ---------------------------------
    await attempt('INFO', async () => {
        const p = await open('S');
        await p.edit();
        p.pickTime('13:00'); p.pickTime('12:30');         // chosen, then chosen back to what is stored
        await p.save();
        console.log(`INFO S: a slot chosen and then chosen back to the stored one sends ${show(p.writes().map(r => `${r.method} ${r.path} ${show(r.body)}`))}`);
    });

    console.log(failed === 0 ? `ok — ${n} checks` : `${failed} of ${n} checks failed`);
    process.exit(failed === 0 ? 0 : 1);
}

main().catch(error => { console.error('HARNESS CRASHED:', error); process.exit(2); });
