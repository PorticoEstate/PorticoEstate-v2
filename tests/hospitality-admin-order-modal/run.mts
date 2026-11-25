/**
 * Hermetic check of the admin «Opprett bestilling» dialog (src/modules/booking/html/base/components/hospitality_order_modal/
 * hospitality_order_modal.js, task 49612): the REAL modal script and the REAL search_select.js run on a stub DOM, against
 * fixtures shaped like the live responses of application 85106 / hospitality 5 and 6 (Fana kulturhus).
 *
 *   node tests/hospitality-admin-order-modal/run.mts
 *   HM_ROOT=<a tree that has the files listed in FILES> node tests/hospitality-admin-order-modal/run.mts
 *   LUXON_DIR=<path to luxon> ...            (L7, see below)
 *
 * HM_ROOT is how the RED arms are run: mutate.py points it at a copy carrying one deliberate defect, and at the tree as
 * it was on base (7891c67b6).
 *
 * What it holds the dialog to (each row names the rule it is the test of):
 *   L1  a remote room the application booked is offered                                       (control: unchanged on base)
 *   L2  the kitchen's own room («main») is offered although the application did not book it   (FIX row)
 *   L3  a remote room the application did NOT book is not offered                             (control: unchanged on base)
 *   L4  nothing offered → the NEW «no delivery locations» text; the hospitality and application pickers, which are
 *       not part of this change, keep «Ingen bestillinger»                                    (FIX row)
 *   L5  main chosen → the times are the application's own times INSIDE the kitchen's opening hours, with an edge row at
 *       open and at close; the hours are fetched on the first need, from the citizen's two endpoints; where they cannot
 *       be judged or fetched there is NO bound (the application's own times, the failure shown once): the bound has no
 *       server rule behind it and must never stop a case officer from ordering (pe-ceo 49668)  (FIX row)
 *   L6  a remote room chosen, or no room chosen yet → the times are the application's own range, as on base
 *   L7  PARITY: over every quarter hour of a week, the dialog's on-site list equals what the citizen client's own
 *       isWithinBusinessHours (business-hours.ts, run through luxon) says; the dialog repeats that rule in plain JS
 *   L8  Lagre posts the main room with the chosen time
 *   L9  a change of hospitality drops the previous one's Lokale list (Opus final Q2, forum/pe-general/49764): the new one's
 *       own list is fetched and offered, whether the two kitchens differ or share one main room with on-site 0 → 1 (h4 → h5
 *       on test); an answer still on its way for the previous one when the officer switches is not kept as the new one's
 *       (FIX row; the row where the first list was never opened is the control: green on 775c8d0eb)
 *   L10 an hours answer the rule cannot read (boundaries that are not a list; a null resource, season or boundary) is a failed
 *       fetch: the application's own times, ONE translated toast, the cause logged, NO unhandled rejection (Opus final Q3b)
 *       (FIX row). The row that proves the harness SEES an unhandled rejection is its control
 *   S*  static anchors: the twig attributes and lang rows that carry the new text, the orders list's own «Ingen
 *       bestillinger», the host pages passing the hours' sources, the citizen's rule and data sources this copies
 *
 * L1–L3 run in BOTH host contexts (application page, hospitality page) and in BOTH arrival orders (the application's
 * dates before the locations, and after them), because the location filter sits at two sites and each order reaches a
 * different one.
 *
 * EVERY CHECK CAN FAIL: mutate.py applies one defect at a time and expects red. A row that passes on base is named as
 * a control there, not counted as a fix.
 *
 * Exit code: 0 all pass, 1 any failure, 2 the harness itself crashed.
 */
process.env.TZ = 'Europe/Oslo';

import {execFileSync} from 'node:child_process';
import {existsSync, mkdirSync, mkdtempSync, readFileSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const root = resolve(process.env.HM_ROOT ?? repo);

const BOOKING = 'src/modules/booking';
const FILES = {
    modal: `${BOOKING}/html/base/components/hospitality_order_modal/hospitality_order_modal.js`,
    select: `${BOOKING}/html/base/components/search_select/search_select.js`,
    appTwig: `${BOOKING}/html/base/_shared/application_show_body.twig`,
    hospTwig: `${BOOKING}/html/base/hospitality/show/hospitality_show.twig`,
    appJs: `${BOOKING}/html/base/application/show/application_show.js`,
    hospJs: `${BOOKING}/html/base/hospitality/show/hospitality_show.js`,
    repo: `${BOOKING}/repositories/HospitalityRepository.php`,
    langNo: `${BOOKING}/setup/phpgw_no.lang`,
    langNn: `${BOOKING}/setup/phpgw_nn.lang`,
    langEn: `${BOOKING}/setup/phpgw_en.lang`,
    citizenApi: 'src/modules/bookingfrontend/client/src/service/api/api-utils.ts',
    citizenHours: 'src/modules/bookingfrontend/client/src/service/utils/business-hours.ts',
    citizenModal: 'src/modules/bookingfrontend/client/src/components/checkout/hospitality/hospitality-order-modal.tsx',
};

let n = 0;
let failed = 0;
let skipped = 0;
const check = (id: string, name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) { failed++; console.log(`FAIL ${id} ${name}${detail ? ' — ' + detail : ''}`); }
};
const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);
/**
 * A scenario that cannot reach its own end (the dialog offers no kitchen room to click, say) is a FAILED ROW named by `id`,
 * not a crash of the harness: a crash would hide every row after it.
 */
const attempt = async (id: string, scenario: () => Promise<void>) => {
    try { await scenario(); } catch (error) { check(id, 'the scenario ran to its end', false, (error as Error).message); }
};
const show = (v: unknown) => JSON.stringify(v);

const logError = console.error;
/** What the dialog itself logs (its own catch of an hours answer it cannot read): kept, for the L10 rows to read. */
const modalLogs: string[] = [];
console.error = (...args: unknown[]) => {
    const head = String(args[0]);
    if (head.startsWith('SearchSelect:')) return;   // the picker logs a failed request; the row reads the outcome
    if (head.startsWith('HospitalityOrderModal:')) { modalLogs.push(args.map(String).join(' ')); return; }
    logError(...args);
};
/**
 * Node ends the process on an unhandled rejection, which would hide every row after it. Counted instead, so a row can say
 * «none»; the L10 control proves this listener fires, and the last row proves no row of the harness left one behind.
 */
const unhandled: string[] = [];
process.on('unhandledRejection', reason => { unhandled.push(String((reason as Error)?.message ?? reason)); });

// A missing file is a failed check, never a crash: a harness that dies has not looked at anything.
const read = (rel: string): string => {
    try {
        return readFileSync(join(root, rel), 'utf8');
    } catch (error) {
        check('S0', `can read ${rel}`, false, String(error));
        return '';
    }
};

// ---- a stub DOM, as small as the dialog and search_select.js need ------------------------------------------------
const VOID = new Set(['input', 'br', 'img', 'hr']);
type Listener = (e: any) => void;

class El {
    tag: string;
    attrs: Record<string, string> = {};
    children: El[] = [];
    parent: El | null = null;
    listeners: Record<string, Listener[]> = {};
    style: Record<string, string> = {};
    disabled = false;
    textOwn = '';
    private cls = new Set<string>();
    private val: string | null = null;
    private sel = 0;
    classList = {
        add: (c: string) => { this.cls.add(c); },
        remove: (c: string) => { this.cls.delete(c); },
        toggle: (c: string, on?: boolean) => { (on ?? !this.cls.has(c)) ? this.cls.add(c) : this.cls.delete(c); },
        contains: (c: string) => this.cls.has(c),
    };

    constructor(tag: string) { this.tag = tag.toLowerCase(); }

    get className() { return [...this.cls].join(' '); }
    set className(v: string) { this.cls = new Set(v.split(/\s+/).filter(Boolean)); }
    get id() { return this.attrs.id ?? ''; }
    get options() { return this.children.filter(c => c.tag === 'option'); }

    get value(): string {
        if (this.tag === 'select') return this.options[this.sel]?.value ?? '';
        if (this.tag === 'option') return this.val ?? this.attrs.value ?? this.textContent;
        return this.val ?? this.attrs.value ?? '';
    }
    set value(v: string) {
        if (this.tag === 'select') this.sel = this.options.findIndex(o => o.value === String(v));
        else this.val = String(v);
    }
    get textContent(): string { return this.children.length ? this.children.map(c => c.textContent).join('') : this.textOwn; }
    set textContent(t: string) { this.children = []; this.textOwn = String(t); }
    set innerHTML(html: string) {
        this.children = []; this.textOwn = ''; this.sel = 0;
        if (html !== '') parseInto(this, html);
    }
    get innerHTML() { return ''; }

    setAttribute(k: string, v: string) { this.attrs[k] = String(v); }
    addEventListener(type: string, fn: Listener) { (this.listeners[type] ??= []).push(fn); }
    removeEventListener(type: string, fn: Listener) { this.listeners[type] = (this.listeners[type] ?? []).filter(f => f !== fn); }
    appendChild(c: El) { c.parent = this; this.children.push(c); return c; }
    contains(node: El | null) { for (let x = node; x; x = x.parent) if (x === this) return true; return false; }
    select() {}
    focus() {}
    scrollIntoView() {}
    dispatch(type: string, extra: Record<string, unknown> = {}) {
        const ev = {type, target: this, key: undefined, preventDefault() {}, stopPropagation() {}, ...extra};
        for (const fn of [...(this.listeners[type] ?? [])]) fn.call(this, ev);
    }

    private matches(sel: string): boolean {
        const m = sel.match(/^([a-zA-Z0-9]*)((?:[.#][\w-]+|\[[\w-]+(?:="[^"]*")?\])*)$/);
        if (!m) throw new Error(`stub DOM: unsupported selector ${sel}`);
        if (m[1] && m[1].toLowerCase() !== this.tag) return false;
        for (const part of m[2].match(/[.#][\w-]+|\[[^\]]+\]/g) ?? []) {
            if (part[0] === '.') { if (!this.cls.has(part.slice(1))) return false; }
            else if (part[0] === '#') { if (this.id !== part.slice(1)) return false; }
            else {
                const a = part.slice(1, -1).match(/^([\w-]+)(?:="([^"]*)")?$/)!;
                if (!(a[1] in this.attrs) || (a[2] !== undefined && this.attrs[a[1]] !== a[2])) return false;
            }
        }
        return true;
    }
    querySelectorAll(sel: string): El[] {
        const out: El[] = [];
        const walk = (e: El) => { for (const c of e.children) { if (c.matches(sel)) out.push(c); walk(c); } };
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
        if (m[5] !== undefined) { top.textOwn += m[5]; continue; }
        const tag = m[2].toLowerCase();
        if (m[1] === '/') {
            const at = stack.map(s => s.tag).lastIndexOf(tag);
            if (at > 0) stack.length = at;
            continue;
        }
        const el = new El(tag);
        for (const a of m[3].matchAll(/([a-zA-Z_:][-\w:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'))?/g)) el.attrs[a[1]] = a[2] ?? a[3] ?? '';
        if (el.attrs.class) el.className = el.attrs.class;
        if ('disabled' in el.attrs) el.disabled = true;
        top.appendChild(el);
        if (!VOID.has(tag) && m[4] !== '/') stack.push(el);
    }
}

class FakeDocument {
    roots: El[] = [];
    listeners: Record<string, Listener[]> = {};
    createElement(tag: string) { return new El(tag); }
    addEventListener(type: string, fn: Listener) { (this.listeners[type] ??= []).push(fn); }
    removeEventListener() {}
    getElementById(id: string): El | null {
        for (const r of this.roots) { if (r.id === id) return r; const f = r.querySelector(`#${id}`); if (f) return f; }
        return null;
    }
    querySelector(sel: string): El | null {
        for (const r of this.roots) { const f = r.querySelector(sel); if (f) return f; }
        return null;
    }
}

// ---- load the two scripts as the page does: plain scripts sharing one scope --------------------------------------
const selectSrc = read(FILES.select);
const modalSrc = read(FILES.modal);
const loadModal = (src: string): any => new Function(`${selectSrc}\n${src}\n;return HospitalityOrderModal;`)();
const HospitalityOrderModal = loadModal(modalSrc);

// ---- lang(): resolved the way the hosts do, through the twig's data-lang-* attributes and the .lang files ---------
const camel = (kebab: string) => kebab.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase());
function langRows(file: string): Record<string, string> {
    const rows: Record<string, string> = {};
    for (const line of file.split('\n')) {
        const [key, module, , text] = line.split('\t');
        if (key && module && text !== undefined) rows[`${module}.${key}`] = text.trim();
    }
    return rows;
}
const langNo = langRows(read(FILES.langNo));
/** What the host's lang('noDeliveryLocations') returns: the twig attribute `data-lang-no-delivery-locations`, then its .lang row. */
function hostLang(twig: string): (key: string) => string {
    const byAttr: Record<string, string> = {};
    for (const m of twig.matchAll(/data-lang-([a-z0-9-]+)="\{\{\s*lang\('([a-zA-Z]+)\.([A-Za-z0-9_]+)'\)\s*\}\}"/g)) {
        byAttr[camel(m[1])] = langNo[`${m[2]}.${m[3]}`] ?? `«${m[2]}.${m[3]}»`;
    }
    return key => byAttr[key] ?? key;   // an unwired key renders as itself, as in the hosts
}
const appLang = hostLang(read(FILES.appTwig));
const hospLang = hostLang(read(FILES.hospTwig));
const NO_ORDERS = langNo['booking.no_orders'];
const NO_DELIVERY = langNo['booking.no_delivery_locations'];
/** What the failure toast must say: the twig attribute's .lang row, never the browser's or the server's message. */
const NO_HOURS = langNo['booking.opening_hours_unavailable'];

// ---- fixtures, shaped like the live responses ---------------------------------------------------------------------
type Loc = {id: number; name: string; location_type: 'main' | 'remote'};
const MAIN: Loc = {id: 482, name: 'Anretning', location_type: 'main'};
const ARTIST: Loc = {id: 576, name: 'Artistgarderobe', location_type: 'remote'};
const MUSIC3: Loc = {id: 781, name: 'Musikkrom 3', location_type: 'remote'};
const HALL: Loc = {id: 125, name: 'Kultursalen', location_type: 'remote'};
const label = (l: Loc) => `${l.name} (${l.location_type})`;

const date = (day: string, from: string, to: string, i = 1) => ({
    id: 630000 + i, from_: `${day}T${from}:00+01:00`, to_: `${day}T${to}:00+01:00`, resources: [781], application_id: 85106,
});
/** Wednesday 2 Dec 2026, 08:00-20:00: wider than the kitchen's Wednesday hours, so the bound is visible. */
const WED = [date('2026-12-02', '08:00', '20:00')];
const hm = (h: number, m: number) => `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
/** Quarter hours from..to, both ends included (the dialog's own grid), computed here and not by the dialog. */
function quarters(from: string, to: string): string[] {
    const t = (s: string) => Number(s.slice(0, 2)) * 60 + Number(s.slice(3, 5));
    const out: string[] = [];
    for (let m = Math.ceil(t(from) / 15) * 15; m <= t(to); m += 15) out.push(hm(Math.floor(m / 60), m % 60));
    return out;
}
const WED_ALL = quarters('08:00', '20:00');       // the application's own range
const WED_KITCHEN = quarters('10:00', '16:00');   // ...inside the kitchen's Wednesday 10:00-16:00

const FAR = {from_: '2026-01-01T00:00:00+01:00', to_: '2029-09-26T23:59:59+02:00'};
const bound = (wday: number, from_: string, to_: string) => ({wday, from_, to_});
/**
 * The building's seasons. Weekdays are ISO (1 = Monday ... 7 = Sunday). S1 is the kitchen's: Wednesday 10:00-16:00, Thursday
 * in two parts, no Saturday. The rest must NOT open anything for the kitchen: an inactive season, another resource's season,
 * a season with no `resources` key at all (live data has them), and one that ended before the week.
 */
const SEASONS = [
    {id: 1, ...FAR, active: 1, resources: [{id: 482}, {id: 125}], boundaries: [
        bound(1, '08:00:00', '22:00:00'), bound(2, '15:00:00', '23:45:00'), bound(3, '10:00:00', '16:00:00'),
        bound(4, '08:00:00', '12:00:00'), bound(4, '14:00:00', '18:00:00'), bound(5, '00:00:00', '23:45:00'),
        bound(7, '12:00:00', '23:59:00')]},
    {id: 2, ...FAR, active: 0, resources: [{id: 482}], boundaries: [bound(3, '00:00:00', '23:00:00')]},
    {id: 3, ...FAR, active: 1, resources: [{id: 999}], boundaries: [bound(6, '08:00:00', '20:00:00')]},
    {id: 4, ...FAR, active: 1, boundaries: [bound(6, '08:00:00', '20:00:00')]},
    {id: 5, from_: '2026-01-01T00:00:00+01:00', to_: '2026-06-30T23:59:59+02:00', active: 1, resources: [{id: 482}], boundaries: [bound(3, '00:00:00', '24:00:00')]},
    {id: 6, from_: '2026-12-03T12:00:00+01:00', to_: '2026-12-05T12:00:00+01:00', active: 1, resources: [{id: 482}], boundaries: [
        bound(4, '00:00:00', '23:45:00'), bound(5, '00:00:00', '23:45:00'), bound(6, '00:00:00', '23:45:00')]},
];

type Hosp = {id: number; name: string; resource_id: number; building_id: number | null};
const H6: Hosp = {id: 6, name: 'Fana kulturhus mat og drikke', resource_id: 482, building_id: 10};
const H5: Hosp = {id: 5, name: 'Fana kulturhus servering', resource_id: 482, building_id: 10};
/**
 * The test server's pair (pe-ceo, forum/pe-tasks-sonnet/49771): «Bevertning til konferanserommet» (h4) is remote-only and
 * «Lunsj i Spiseriet» (h5) is on-site-only, and both are served from the same main room 941. h4's delivery locations hold no
 * main room at all; h5's hold nothing else.
 */
const SPISERI: Loc = {id: 941, name: 'Spiseriet', location_type: 'main'};
const H4: Hosp = {id: 4, name: 'Bevertning til konferanserommet', resource_id: 941, building_id: 10};
const H5_SPISERIET: Hosp = {id: 5, name: 'Lunsj i Spiseriet', resource_id: 941, building_id: 10};
/** The hours of room 941 (building 10): Wednesday 10:00-16:00, as S1 gives room 482. */
const SEASONS_941 = [{id: 7, ...FAR, active: 1, resources: [{id: 941}], boundaries: [bound(3, '10:00:00', '16:00:00')]}];

// ---- one dialog on stub elements ------------------------------------------------------------------------------------
type Cfg = {
    ctx: 'app' | 'hosp';
    hosp?: Hosp;
    hospitalities?: Hosp[];
    locations?: Loc[] | Record<number, Loc[]>;
    dates?: any[];
    seasons?: unknown;                 // the body of /seasons: an array, or anything else for a malformed answer
    seasonsStatus?: number;
    settingsStatus?: number;
    closeWhenNoSeasons?: boolean;
    hospBuildingId?: number | null;    // hospitality page: what resource_building_id says
    holdDates?: boolean;
    holdHours?: boolean;
    holdLocations?: number[];          // hospitality ids whose delivery-locations request stays on its way until released
};

const SEASONS_URL = '/bookingfrontend/buildings';
const SETTINGS_URL = '/api/server-settings';
const settle = async () => { for (let i = 0; i < 8; i++) await new Promise<void>(r => setImmediate(r)); };

async function boot(cfg: Cfg) {
    const doc = new FakeDocument();
    (globalThis as any).document = doc;
    const hosp = cfg.hosp ?? H6;
    const locations = (id: number): Loc[] => Array.isArray(cfg.locations) ? cfg.locations : (cfg.locations?.[id] ?? []);
    const routes: Record<string, () => unknown> = {
        '/booking/applications/85106/dates': () => cfg.dates ?? WED,
        '/booking/hospitality/6/delivery-locations': () => locations(6),
        '/booking/hospitality/5/delivery-locations': () => locations(5),
        '/booking/hospitality/4/delivery-locations': () => locations(4),
        '/booking/hospitality/6/relevant-applications': () => [{id: 85106, status: 'NEW', contact_name: 'T46184'}],
        '/booking/hospitality/5/relevant-applications': () => [{id: 85106, status: 'NEW', contact_name: 'T46184'}],
        [`${SEASONS_URL}/10/seasons`]: () => cfg.seasons ?? SEASONS,
        [SETTINGS_URL]: () => ({bookingfrontend_config: {close_calendar_without_season: !!cfg.closeWhenNoSeasons}}),
    };
    const calls: string[] = [];
    const held: Record<string, () => void> = {};
    const holds = new Set<string>();
    if (cfg.holdDates) holds.add('/booking/applications/85106/dates');
    if (cfg.holdHours) { holds.add(`${SEASONS_URL}/10/seasons`); holds.add(SETTINGS_URL); }
    for (const id of cfg.holdLocations ?? []) holds.add(`/booking/hospitality/${id}/delivery-locations`);

    (globalThis as any).fetch = (url: string) => {
        calls.push(url);
        const path = url.split('?')[0];
        const make = () => {
            const known = path in routes;
            const status = path === `${SEASONS_URL}/10/seasons` && cfg.seasonsStatus ? cfg.seasonsStatus
                : path === SETTINGS_URL && cfg.settingsStatus ? cfg.settingsStatus : known ? 200 : 404;
            return {ok: status === 200, status, json: async () => structuredClone(known ? routes[path]() : {})};
        };
        if (holds.has(path)) return new Promise(res => { held[path] = () => res(make()); });
        return Promise.resolve(make());
    };
    const fetchJson = (url: string) => (globalThis as any).fetch(url).then((r: any) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });

    const toasts: Array<[string, string | undefined]> = [];
    const posted: Array<{url: string; data: any}> = [];
    const lang = cfg.ctx === 'app' ? appLang : hospLang;
    const common = {
        lang,
        esc: (s: unknown) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
        fetchJson,
        postJson: (url: string, data: any) => { posted.push({url, data}); return Promise.resolve({}); },
        showModal: (id: string, title: string, body: string, footer: string) => {
            const dialog = new El('dialog');
            dialog.attrs.id = id;
            parseInto(dialog, body + footer);
            doc.roots = doc.roots.filter(r => r.id !== id);
            doc.roots.push(dialog);
        },
        closeModal: () => {},
        showToast: (msg: string, type?: string) => { toasts.push([msg, type]); },
        onSuccess: () => {},
        ordersStoreUrl: '/booking/hospitality-orders',
        applicationsBaseUrl: '/booking/applications',
        seasonsBaseUrl: SEASONS_URL,
        serverSettingsUrl: SETTINGS_URL,
    };
    const opts = cfg.ctx === 'app'
        ? {...common, applicationId: 85106, hospitalities: cfg.hospitalities ?? [hosp], deliveryLocationsBaseUrl: '/booking/hospitality'}
        : {
            ...common, hospitalityId: hosp.id, hospMainResourceId: hosp.resource_id,
            hospBuildingId: cfg.hospBuildingId === undefined ? hosp.building_id : cfg.hospBuildingId,
            relevantAppsUrl: `/booking/hospitality/${hosp.id}/relevant-applications`,
            deliveryLocationsUrl: `/booking/hospitality/${hosp.id}/delivery-locations`,
        };
    HospitalityOrderModal.open(opts);

    const q = (sel: string) => {
        const e = doc.querySelector(sel);
        if (!e) throw new Error(`the dialog has no ${sel}`);
        return e;
    };
    const inBox = (box: string, sel: string) => {
        const e = q(`#${box}`).querySelector(sel);
        if (!e) throw new Error(`#${box} has no ${sel}`);
        return e;
    };
    const items = (box: string) => inBox(box, '.search-select__dropdown').children;
    const run = {
        calls, toasts, posted,
        seasonsCalls: () => calls.filter(u => u.includes('/seasons')),
        settingsCalls: () => calls.filter(u => u.includes('server-settings')),
        locationCalls: (hospitalityId: number) => calls.filter(u => u === `/booking/hospitality/${hospitalityId}/delivery-locations`),
        /** The id of the room chosen in Lokale, as the hidden input Lagre reads it through; '' when none is chosen. */
        locationValue: () => inBox('modal-order-loc-container', 'input[type="hidden"]').value,
        release: async (path: string) => { held[path]?.(); delete held[path]; holds.delete(path); await settle(); },
        releaseDates: () => run.release('/booking/applications/85106/dates'),
        releaseLocations: (hospitalityId: number) => run.release(`/booking/hospitality/${hospitalityId}/delivery-locations`),
        releaseHours: async () => { await run.release(`${SEASONS_URL}/10/seasons`); await run.release(SETTINGS_URL); },
        /** Open the picker (focus) and read what it shows: the offered labels, or the empty text. */
        pick: async (box: string) => {
            const input = inBox(box, '.search-select__input');
            input.value = '';   // search_select.js filters by the text in the field: a field still holding the last choice lists only that
            input.dispatch('focus');
            await settle();
            const rows = items(box);
            return {
                offered: rows.filter(r => r.className.includes('search-select__item')).map(r => r.textContent),
                empty: rows.find(r => r.className.includes('search-select__empty'))?.textContent ?? null,
            };
        },
        click: async (box: string, text: string) => {
            const r = items(box).find(x => x.textContent === text);
            if (!r) throw new Error(`${box} offers no «${text}»: ${show(items(box).map(x => x.textContent))}`);
            r.dispatch('click');
            await settle();
        },
        locations: () => run.pick('modal-order-loc-container'),
        chooseLocation: async (loc: Loc) => { await run.locations(); await run.click('modal-order-loc-container', label(loc)); },
        chooseHospitality: async (h: Hosp) => { await run.pick('modal-order-hosp-container'); await run.click('modal-order-hosp-container', h.name); await settle(); },
        chooseApplication: async () => { const o = await run.pick('modal-order-app-container'); await run.click('modal-order-app-container', o.offered[0]); await settle(); },
        /** Type into a picker whose items are all known and read its empty text. */
        typeNothingMatching: async (box: string) => {
            const input = inBox(box, '.search-select__input');
            input.value = 'zzzz';
            input.dispatch('input');
            await settle();
            return items(box).find(r => r.className.includes('search-select__empty'))?.textContent ?? null;
        },
        dateSel: () => q('[data-booking-role="serving-date"]'),
        timeSel: () => q('[data-booking-role="serving-time"]'),
        times: () => run.timeSel().options.slice(1).map(o => o.textContent),
        dates: () => run.dateSel().options.slice(1).map(o => o.textContent),
        pickDate: async (i: number) => { const d = run.dateSel(); d.value = String(i); d.dispatch('change'); await settle(); },
        pickTime: (text: string) => { const t = run.timeSel(); t.value = t.options.find(o => o.textContent === text)!.value; },
        save: async () => { q('#modal-order-submit').dispatch('click'); await settle(); },
        /** What the user sees once the application is known: app page = on open, hospitality page = after choosing one. */
        start: async (order: 'dates-first' | 'locations-first') => {
            if (cfg.ctx === 'app') await run.chooseHospitality(hosp);
            if (order === 'dates-first') {
                if (cfg.ctx === 'hosp') await run.chooseApplication();
                return;
            }
            // locations-first: the picker is opened before the application's dates have arrived
            await run.locations();
            if (cfg.ctx === 'hosp') await run.chooseApplication();
            else await run.releaseDates();
        },
    };
    return run;
}

// =====================================================================================================================
// L1-L3: what the Lokale picker offers
// =====================================================================================================================
const CONTEXTS = ['app', 'hosp'] as const;
const ORDERS = ['dates-first', 'locations-first'] as const;
try {
    check('L0', 'the stub DOM keeps local time in Europe/Oslo (a +01:00 instant reads as 12:00)', new Date('2026-12-02T12:00:00+01:00').getHours() === 12);

    for (const ctx of CONTEXTS) for (const order of ORDERS) await attempt(`L2[${ctx}/${order}]`, async () => {
        const at = `[${ctx}/${order}]`;
        // hospitality 5 (remote serving on): the application booked 781 only
        const mixed = [MAIN, ARTIST, HALL, MUSIC3];
        const t = await boot({ctx, hosp: H5, locations: mixed, dates: WED, holdDates: order === 'locations-first' && ctx === 'app'});
        await t.start(order);
        const list = await t.locations();
        check(`L1${at}`, 'a booked remote room is offered', list.offered.includes(label(MUSIC3)), show(list.offered));
        check(`L2${at}`, 'the kitchen room is offered although the application did not book it', list.offered.includes(label(MAIN)), show(list.offered));
        check(`L3${at}`, 'an unbooked remote room is not offered', !list.offered.includes(label(ARTIST)) && !list.offered.includes(label(HALL)), show(list.offered));
        check(`L3${at}/exact`, 'the list is exactly the kitchen room and the booked room (the control for the two rows above)',
            same(list.offered, [label(MAIN), label(MUSIC3)]), show(list.offered));

        // hospitality 6 (on-site only): the case from the tester's screenshot
        const on = await boot({ctx, hosp: H6, locations: [MAIN], dates: WED, holdDates: order === 'locations-first' && ctx === 'app'});
        await on.start(order);
        const only = await on.locations();
        check(`L2${at}/h6`, 'on-site-only hospitality: the kitchen room is the one choice', same(only.offered, [label(MAIN)]) && only.empty === null, show(only));
    });

    // =================================================================================================================
    // L4: the empty text
    // =================================================================================================================
    for (const ctx of CONTEXTS) await attempt(`L4[${ctx}]`, async () => {
        const at = `[${ctx}]`;
        const none = await boot({ctx, locations: []});
        await none.start('dates-first');
        const a = await none.locations();
        check(`L4${at}/empty-api`, 'no delivery locations at all → the new text', a.empty === NO_DELIVERY && a.offered.length === 0, show(a));

        const filtered = await boot({ctx, locations: [ARTIST]});
        await filtered.start('dates-first');
        const b = await filtered.locations();
        check(`L4${at}/all-filtered`, 'every location filtered out (only an unbooked remote) → the new text', b.empty === NO_DELIVERY && b.offered.length === 0, show(b));
    });
    check('L4/text', 'the new text is the .lang row, in a lang row of its own, and is not the orders list text',
        !!NO_DELIVERY && NO_DELIVERY !== NO_ORDERS && !!NO_ORDERS, show({NO_DELIVERY, NO_ORDERS}));
    await attempt('L4/pickers', async () => {
        // the pickers this change does not touch keep «Ingen bestillinger»
        const t = await boot({ctx: 'app', hospitalities: [H6, H5], locations: [MAIN]});
        check('L4/hospitality-picker', 'the hospitality picker keeps «Ingen bestillinger»', (await t.typeNothingMatching('modal-order-hosp-container')) === NO_ORDERS);
        const h = await boot({ctx: 'hosp', locations: [MAIN]});
        check('L4/application-picker', 'the application picker keeps «Ingen bestillinger»', (await h.pick('modal-order-app-container'), await h.typeNothingMatching('modal-order-app-container')) === NO_ORDERS);
    });

    // =================================================================================================================
    // L5: the main room is bounded by the kitchen's opening hours
    // =================================================================================================================
    for (const ctx of CONTEXTS) await attempt(`L5[${ctx}]`, async () => {
        const at = `[${ctx}]`;
        const t = await boot({ctx, locations: [MAIN, MUSIC3], dates: WED});
        await t.start('dates-first');
        await t.locations();
        check(`L5${at}/lazy`, 'no opening-hours request before the kitchen room is chosen', t.seasonsCalls().length === 0 && t.settingsCalls().length === 0, show(t.calls));
        await t.click('modal-order-loc-container', label(MAIN));
        check(`L5${at}/times`, 'main chosen → exactly the quarter hours inside the kitchen\'s Wednesday 10:00-16:00', same(t.times(), WED_KITCHEN), show(t.times()));
        const times = t.times();
        check(`L5${at}/edge-open`, 'edge: the opening time itself is offered, the quarter before is not', times.includes('10:00') && !times.includes('09:45'), show(times.slice(0, 3)));
        check(`L5${at}/edge-close`, 'edge: the closing time itself is offered, the quarter after is not', times.includes('16:00') && !times.includes('16:15'), show(times.slice(-3)));
        check(`L5${at}/source`, 'the hours come from the citizen\'s two endpoints, once each',
            same(t.seasonsCalls(), [`${SEASONS_URL}/10/seasons`]) && same(t.settingsCalls(), [`${SETTINGS_URL}?include_configs=true`]), show(t.calls));
        check(`L5${at}/not-wider`, 'the bound only narrows: no time outside the application\'s own range', times.every(x => WED_ALL.includes(x)));
    });
    await attempt('L5/late', async () => {
        // the hours arrive late: nothing is offered until they do, and then the list fills in by itself
        const t = await boot({ctx: 'app', locations: [MAIN, MUSIC3], dates: WED, holdHours: true});
        await t.start('dates-first');
        await t.chooseLocation(MAIN);
        check('L5/late-empty', 'while the hours are on their way the time list is empty and disabled', t.times().length === 0 && t.timeSel().disabled === true, show({times: t.times(), disabled: t.timeSel().disabled}));
        await t.releaseHours();
        check('L5/late-filled', 'once they arrive the list fills in, bounded', same(t.times(), WED_KITCHEN) && t.timeSel().disabled === false, show(t.times()));
    });
    await attempt('L5/race', async () => {
        // the user moves on to a remote room before the hours arrive: their choice is not overwritten
        const t = await boot({ctx: 'app', locations: [MAIN, MUSIC3], dates: WED, holdHours: true});
        await t.start('dates-first');
        await t.chooseLocation(MAIN);
        await t.chooseLocation(MUSIC3);
        t.pickTime('12:00');
        const chosen = t.timeSel().value;
        await t.releaseHours();
        check('L5/race', 'a late answer does not clobber the times (or the chosen time) of the remote room chosen meanwhile',
            same(t.times(), WED_ALL) && t.timeSel().value === chosen && chosen !== '', show({times: t.times().length, value: t.timeSel().value, chosen}));
    });
    await attempt('L5/no-seasons', async () => {
        // no seasons at all: open or closed by the admin setting, as the citizen dialog reads it
        const open = await boot({ctx: 'app', locations: [MAIN], dates: WED, seasons: [], closeWhenNoSeasons: false});
        await open.start('dates-first'); await open.chooseLocation(MAIN);
        check('L5/no-seasons-open', 'no seasons and close_calendar_without_season off → the application\'s own range', same(open.times(), WED_ALL), show(open.times().length));
        const closed = await boot({ctx: 'app', locations: [MAIN], dates: WED, seasons: [], closeWhenNoSeasons: true});
        await closed.start('dates-first'); await closed.chooseLocation(MAIN);
        check('L5/no-seasons-closed', 'no seasons and close_calendar_without_season on → no time', closed.times().length === 0, show(closed.times().length));
    });
    await attempt('L5/unjudgeable', async () => {
        // The hours cannot be judged: NO BOUND, the application's own times, as without it (pe-ceo 49668: the bound is a
        // client-side convenience with no server rule, and must never stop a case officer from ordering).
        const noBuilding = await boot({ctx: 'app', hospitalities: [{...H6, building_id: null}], locations: [MAIN], dates: WED});
        await noBuilding.start('dates-first'); await noBuilding.chooseLocation(MAIN);
        check('L5[app]/no-building', 'the kitchen resource is in no building → the application\'s own times, and no request', same(noBuilding.times(), WED_ALL) && noBuilding.seasonsCalls().length === 0, show({times: noBuilding.times().length, calls: noBuilding.calls}));
        const hospNoBuilding = await boot({ctx: 'hosp', hospBuildingId: null, locations: [MAIN], dates: WED});
        await hospNoBuilding.start('dates-first'); await hospNoBuilding.chooseLocation(MAIN);
        check('L5[hosp]/no-building', 'hospitality page without a building → the application\'s own times, and no request', same(hospNoBuilding.times(), WED_ALL) && hospNoBuilding.seasonsCalls().length === 0, show({times: hospNoBuilding.times().length, calls: hospNoBuilding.calls}));

        // the seasons request fails: the application's own times, the failure said once, the answer kept
        const broken = await boot({ctx: 'app', locations: [MAIN, MUSIC3], dates: WED, seasonsStatus: 500});
        await broken.start('dates-first'); await broken.chooseLocation(MAIN);
        check('L5/error-open', 'the seasons request fails → the application\'s own times, and the failure is shown in the translated words', same(broken.times(), WED_ALL) && broken.toasts.length === 1 && broken.toasts[0][1] === 'danger' && broken.toasts[0][0] === NO_HOURS, show({times: broken.times().length, toasts: broken.toasts, expected: NO_HOURS}));
        await broken.chooseLocation(MUSIC3);
        await broken.chooseLocation(MAIN);
        check('L5/error-once', 'choosing the kitchen room again neither asks again nor says it again, and the times are still the application\'s own',
            broken.seasonsCalls().length === 1 && broken.toasts.length === 1 && same(broken.times(), WED_ALL), show({calls: broken.seasonsCalls(), toasts: broken.toasts.length, times: broken.times().length}));

        // an answer that is not a list is a failed fetch, not «no seasons»: the setting that closes a building without seasons must not close it
        const malformed = await boot({ctx: 'app', locations: [MAIN], dates: WED, seasons: {error: 'x'}, closeWhenNoSeasons: true});
        await malformed.start('dates-first'); await malformed.chooseLocation(MAIN);
        check('L5/malformed-open', 'an answer that is not a list of seasons → the application\'s own times and the failure shown, even where close_calendar_without_season is on', same(malformed.times(), WED_ALL) && malformed.toasts.length === 1 && malformed.toasts[0][1] === 'danger' && malformed.toasts[0][0] === NO_HOURS, show({times: malformed.times().length, toasts: malformed.toasts}));

        // the settings request fails and the seasons arrive: the seasons still bound the times, the setting reads «false» (the citizen's `?? false`)
        const noSettings = await boot({ctx: 'app', locations: [MAIN], dates: WED, settingsStatus: 500});
        await noSettings.start('dates-first'); await noSettings.chooseLocation(MAIN);
        check('L5/settings-failed', 'the settings request fails, the seasons arrive → still bounded by the seasons, and no failure shown (the citizen shows none)', same(noSettings.times(), WED_KITCHEN) && noSettings.toasts.length === 0, show({times: noSettings.times().length, toasts: noSettings.toasts.length}));

        // the same toast on the hospitality page, through ITS twig (the attribute is wired per page)
        const hospBroken = await boot({ctx: 'hosp', locations: [MAIN], dates: WED, seasonsStatus: 500});
        await hospBroken.start('dates-first'); await hospBroken.chooseLocation(MAIN);
        check('L5[hosp]/error-text', 'hospitality page, the seasons request fails → the application\'s own times and the translated failure text', same(hospBroken.times(), WED_ALL) && hospBroken.toasts.length === 1 && hospBroken.toasts[0][0] === NO_HOURS, show({times: hospBroken.times().length, toasts: hospBroken.toasts, expected: NO_HOURS}));
    });
    check('L5/toast-text', 'the failure text is a real sentence in the lang row: not the raw key, and nothing of a browser or server message in it',
        !!NO_HOURS && NO_HOURS !== 'openingHoursUnavailable' && !/Failed|fetch|HTTP|seasons|Error|Feil:/i.test(NO_HOURS), show(NO_HOURS));

    // =================================================================================================================
    // L6: a remote room, or no room, keeps the application's own times
    // =================================================================================================================
    for (const ctx of CONTEXTS) await attempt(`L6[${ctx}]`, async () => {
        const t = await boot({ctx, hosp: H5, locations: [MAIN, MUSIC3], dates: WED});
        await t.start('dates-first');
        await t.chooseLocation(MUSIC3);
        check(`L6[${ctx}]/remote`, 'a remote room chosen → every quarter hour of the application\'s range, as on base', same(t.times(), WED_ALL), show(t.times().length));
        check(`L6[${ctx}]/remote-no-request`, 'and no opening-hours request is made for it', t.seasonsCalls().length === 0 && t.settingsCalls().length === 0, show(t.calls));
    });
    await attempt('L6/no-room-after-switch', async () => {
        // main chosen, then ANOTHER hospitality: with no room chosen the range is the application's own again
        const two = [H6, {...H5, building_id: 10}];
        const t = await boot({ctx: 'app', hospitalities: two, locations: {6: [MAIN], 5: [MAIN, MUSIC3]}, dates: [date('2026-12-02', '08:00', '20:00', 1), date('2026-12-03', '08:00', '20:00', 2)]});
        await t.chooseHospitality(H6);
        await t.chooseLocation(MAIN);
        await t.pickDate(0);
        const bounded = t.times();
        await t.chooseHospitality(H5);
        await t.pickDate(0);
        check('L6/no-room-after-switch', 'after switching hospitality, with no room chosen yet, the date offers the application\'s own range',
            bounded.length < WED_ALL.length && same(t.times(), WED_ALL), show({before: bounded.length, after: t.times().length}));
    });

    // =================================================================================================================
    // L7: parity with the citizen client's rule, over every quarter hour of a week
    // =================================================================================================================
    const luxonDir = [
        process.env.LUXON_DIR,
        join(root, 'src/modules/bookingfrontend/client/node_modules/luxon'),
        join(repo, 'src/modules/bookingfrontend/client/node_modules/luxon'),
        (() => { try { return join(dirname(execFileSync('git', ['-C', repo, 'rev-parse', '--path-format=absolute', '--git-common-dir'], {encoding: 'utf8'}).trim()), 'src/modules/bookingfrontend/client/node_modules/luxon'); } catch { return undefined; } })(),
    ].find(p => p && existsSync(join(p, 'package.json')));
    const citizenSrc = read(FILES.citizenHours);
    const SEASON_IMPORT = "import {Season} from '@/service/types/Building';";
    check('L7/source', 'business-hours.ts still has the one type import the harness replaces to load it', citizenSrc.includes(SEASON_IMPORT));
    if (!luxonDir) {
        if (process.env.HM_SKIP_PARITY === '1') { skipped++; console.log('SKIP L7 (luxon not found, HM_SKIP_PARITY=1)'); }
        else check('L7', 'luxon is available to run the citizen rule', false, 'set LUXON_DIR=<path to luxon>, or HM_SKIP_PARITY=1 to skip this row knowingly');
    } else if (citizenSrc.includes(SEASON_IMPORT)) await attempt('L7', async () => {
        const dir = mkdtempSync(join(tmpdir(), 'hm-citizen-'));
        mkdirSync(join(dir, 'node_modules'));
        symlinkSync(luxonDir, join(dir, 'node_modules', 'luxon'), 'dir');
        writeFileSync(join(dir, 'business-hours.mts'), citizenSrc.replace(SEASON_IMPORT, 'type Season = any;'));
        const citizen = (await import(pathToFileURL(join(dir, 'business-hours.mts')).href)).isWithinBusinessHours as (d: Date, r: string[], s: any[], c: boolean) => boolean;

        // The citizen client never calls the rule with the raw response: useBuildingSeasons first gives a season with no
        // `resources` key an empty list (withNormalisedResources), and the rule throws on one that has none.
        const normalised = SEASONS.map(s => Array.isArray((s as any).resources) ? s : {...s, resources: []});
        const week = ['02', '03', '04', '05', '06', '07', '08'].map((d, i) => date(`2026-12-${d}`, '00:00', '23:45', i + 1));
        const t = await boot({ctx: 'app', locations: [MAIN], dates: week});
        await t.start('dates-first');
        await t.chooseLocation(MAIN);
        check('L7/dates', 'main chosen → every day of the week is listed', t.dates().length === 7, show(t.dates()));
        let opened = 0;
        let closedSlots = 0;
        for (let i = 0; i < 7; i++) {
            await t.pickDate(i);
            const day = new Date(week[i].from_);
            const expected: string[] = [];
            for (let q = 0; q < 96; q++) {
                const at = new Date(day.getFullYear(), day.getMonth(), day.getDate(), Math.floor(q / 4), (q % 4) * 15);
                if (citizen(at, ['482'], normalised, false)) expected.push(hm(at.getHours(), at.getMinutes()));
            }
            opened += expected.length;
            closedSlots += 96 - expected.length;
            check(`L7/${week[i].from_.slice(0, 10)}`, 'the dialog\'s on-site list equals the citizen rule\'s, quarter by quarter', same(t.times(), expected), show({dialog: t.times().length, citizen: expected.length}));
        }
        // the instrument can fail: the week holds both open and closed quarters, and the oracle really is the one that says «closed» on Saturday
        check('L7/control', 'the week has both open and closed quarters, so an always-open or always-closed list cannot pass', opened > 100 && closedSlots > 100, show({opened, closedSlots}));
        check('L7/oracle', 'the citizen rule says Saturday 2026-12-05 11:00 is open (season 6) and 13:00 is closed',
            citizen(new Date(2026, 11, 5, 11, 0), ['482'], normalised, false) === true && citizen(new Date(2026, 11, 5, 13, 0), ['482'], normalised, false) === false);
    });

    // =================================================================================================================
    // L8: Lagre posts the main room and the chosen time
    // =================================================================================================================
    await attempt('L8', async () => {
        const t = await boot({ctx: 'app', locations: [MAIN], dates: WED});
        await t.start('dates-first');
        await t.chooseLocation(MAIN);
        t.pickTime('12:00');
        await t.save();
        const p = t.posted[0]?.data;
        check('L8', 'Lagre posts the main room, the hospitality, the application and the chosen time',
            t.posted.length === 1 && p?.location_resource_id === 482 && p?.hospitality_id === 6 && p?.application_id === 85106 && p?.serving_time_iso === '2026-12-02T12:00:00+01:00', show(t.posted));
    });

    // =================================================================================================================
    // L9: a change of hospitality drops the previous one's Lokale list (Opus final Q2)
    //
    // The list is fetched on the first focus of the picker and cached for the application's dates, which push it into the
    // picker of the hospitality chosen NOW. Kept across a switch, the new picker is handed the old one's list and never asks
    // for its own. Only the application page has a hospitality picker, so these run in that context.
    // =================================================================================================================
    await attempt('L9/h4-h5', async () => {
        // the tester's flow: the meeting room's hospitality first, then the Spiseriet's, in the same dialog
        const t = await boot({ctx: 'app', hospitalities: [H4, H5_SPISERIET], locations: {4: [MUSIC3], 5: [SPISERI]}, dates: WED, seasons: SEASONS_941});
        await t.chooseHospitality(H4);
        const first = await t.locations();
        check('L9/h4-h5/first', 'h4 (remote only) offers the booked meeting room, as it did on base', same(first.offered, [label(MUSIC3)]), show(first));
        await t.click('modal-order-loc-container', label(MUSIC3));
        check('L9/h4-h5/chosen', 'control: the meeting room is chosen under h4 (the hidden value Lagre reads is its id)', t.locationValue() === String(MUSIC3.id), show(t.locationValue()));

        await t.chooseHospitality(H5_SPISERIET);
        check('L9/h4-h5/cleared', 'the room chosen under h4 is gone after the switch', t.locationValue() === '', show(t.locationValue()));
        const second = await t.locations();
        check('L9/h4-h5/fetched', 'h5 fetches ITS OWN delivery locations (one call)', t.locationCalls(5).length === 1, show(t.locationCalls(5)));
        check('L9/h4-h5/offered', 'h5 offers the Spiseriet (main 941, on-site under h5) and not h4\'s meeting room',
            same(second.offered, [label(SPISERI)]) && second.empty === null, show(second));
        await t.click('modal-order-loc-container', label(SPISERI));
        check('L9/h4-h5/times', 'the Spiseriet chosen → the times are bounded by the kitchen\'s hours, as for any on-site order', same(t.times(), WED_KITCHEN), show(t.times()));

        await t.chooseHospitality(H4);
        const back = await t.locations();
        check('L9/h4-h5/back', 'and back to h4: h4\'s own list again, not h5\'s', same(back.offered, [label(MUSIC3)]), show(back));
    });
    await attempt('L9/kitchens', async () => {
        // two hospitalities with DIFFERENT kitchens: the first one's main room must not be offered under the second
        const t = await boot({ctx: 'app', hospitalities: [H6, H5_SPISERIET], locations: {6: [MAIN], 5: [SPISERI, ARTIST, MUSIC3]}, dates: WED, seasons: SEASONS_941});
        await t.chooseHospitality(H6);
        const first = await t.locations();
        check('L9/kitchens/first', 'the first hospitality offers its kitchen room (482)', same(first.offered, [label(MAIN)]), show(first));
        await t.chooseHospitality(H5_SPISERIET);
        const second = await t.locations();
        check('L9/kitchens/fetched', 'the second hospitality fetches its own delivery locations (one call)', t.locationCalls(5).length === 1, show(t.locationCalls(5)));
        check('L9/kitchens/offered', 'the second offers ITS kitchen room (941) and the room the application booked, and not the first one\'s kitchen',
            same(second.offered, [label(SPISERI), label(MUSIC3)]), show(second));
    });
    await attempt('L9/not-opened', async () => {
        // CONTROL (green on 775c8d0eb too, as Opus measured): nothing was cached because the first list was never opened
        const t = await boot({ctx: 'app', hospitalities: [H4, H5_SPISERIET], locations: {4: [MUSIC3], 5: [SPISERI]}, dates: WED, seasons: SEASONS_941});
        await t.chooseHospitality(H4);
        await t.chooseHospitality(H5_SPISERIET);
        const second = await t.locations();
        check('L9/not-opened', 'control: the first list never opened → the second hospitality offers its own', same(second.offered, [label(SPISERI)]) && t.locationCalls(5).length === 1, show({second, calls: t.locationCalls(5)}));
    });
    await attempt('L9/in-flight', async () => {
        // The first list is still on its way when the officer switches, and the application's dates for the second hospitality
        // arrive after it has landed: the late answer must not become the second one's cache.
        const t = await boot({ctx: 'app', hospitalities: [H4, H5_SPISERIET], locations: {4: [MUSIC3], 5: [SPISERI]}, dates: WED, seasons: SEASONS_941, holdLocations: [4], holdDates: true});
        await t.chooseHospitality(H4);
        await t.locations();                       // h4's list is requested on this focus, and held
        await t.chooseHospitality(H5_SPISERIET);   // the officer moves on; h5's dates are requested, and held
        await t.releaseLocations(4);               // h4's answer lands now
        await t.releaseDates();                    // h5's dates arrive after it: the application's dates reach the picker only now
        const second = await t.locations();
        check('L9/in-flight/offered', 'h4\'s late answer is not h5\'s list: h5 offers the Spiseriet', same(second.offered, [label(SPISERI)]), show(second));
        check('L9/in-flight/fetched', 'h5 fetched its own delivery locations (one call)', t.locationCalls(5).length === 1, show(t.locationCalls(5)));
    });

    // =================================================================================================================
    // L10: an hours answer the rule cannot read is a failed fetch (Opus final Q3b)
    //
    // `getSeasons` cannot emit these shapes (asserted by Opus), so the rows build them on purpose: the dialog's promise is
    // «fail open on ANY failure», and a throw inside the synchronous filter was the one failure that escaped it (0 times, no
    // toast, an unhandled rejection from the promise chain that fills the list).
    // =================================================================================================================
    await attempt('L10/instrument', async () => {
        const mark = unhandled.length;
        Promise.reject(new Error('L10-control'));
        await settle();
        const seen = unhandled.slice(mark);
        unhandled.splice(mark);   // the control's own rejection is not a leak
        check('L10/instrument', 'control: the harness sees an unhandled rejection when one happens (so «none» in the rows below means none)', same(seen, ['L10-control']), show(seen));
    });
    const S1 = SEASONS[0] as any;
    const MALFORMED: Record<string, unknown[]> = {
        'boundaries-object': [{...S1, boundaries: {wday: 3, from_: '10:00:00', to_: '16:00:00'}}],
        'resource-null': [{...S1, resources: [null]}],
        'season-null': [S1, null],
        'boundary-null': [{...S1, boundaries: [null]}],
    };
    for (const ctx of CONTEXTS) for (const [shape, seasons] of Object.entries(MALFORMED)) await attempt(`L10[${ctx}/${shape}]`, async () => {
        const at = `[${ctx}/${shape}]`;
        const mark = unhandled.length;
        const logMark = modalLogs.length;
        const t = await boot({ctx, locations: [MAIN, MUSIC3], dates: WED, seasons});
        await t.start('dates-first');
        await t.chooseLocation(MAIN);
        await settle();
        check(`L10${at}/times`, 'the application\'s own times, in a list that can be chosen from (fail open)', same(t.times(), WED_ALL) && t.timeSel().disabled === false, show({times: t.times().length, disabled: t.timeSel().disabled}));
        check(`L10${at}/toast`, 'ONE danger toast, in the translated words',
            t.toasts.length === 1 && t.toasts[0][1] === 'danger' && t.toasts[0][0] === NO_HOURS, show({toasts: t.toasts, expected: NO_HOURS}));
        check(`L10${at}/unhandled`, 'no unhandled rejection', unhandled.length === mark, show(unhandled.slice(mark)));
        const logs = modalLogs.slice(logMark);
        check(`L10${at}/logged`, 'the cause is logged once, for the developer, with the error named', logs.length === 1 && /TypeError/.test(logs[0]), show(logs));
        await t.chooseLocation(MUSIC3);
        await t.chooseLocation(MAIN);
        check(`L10${at}/kept`, 'choosing the kitchen room again neither asks nor says it again, and the times are still the application\'s own',
            t.seasonsCalls().length === 1 && t.toasts.length === 1 && same(t.times(), WED_ALL) && unhandled.length === mark && modalLogs.length === logMark + 1,
            show({calls: t.seasonsCalls().length, toasts: t.toasts.length, times: t.times().length, unhandled: unhandled.slice(mark), logs: modalLogs.length - logMark}));
    });
    await attempt('L10/later-date', async () => {
        // the malformed element lies in a season that only covers THURSDAY: Wednesday is bounded as usual, and the throw happens later,
        // inside the date picker's change handler (not in the promise chain that filled the first list)
        const mark = unhandled.length;
        const thursday = {id: 9, from_: '2026-12-03T00:00:00+01:00', to_: '2026-12-03T23:59:59+01:00', active: 1, resources: [{id: 482}], boundaries: {wday: 4}};
        const t = await boot({ctx: 'app', locations: [MAIN], dates: [date('2026-12-02', '08:00', '20:00', 1), date('2026-12-03', '08:00', '20:00', 2)], seasons: [S1, thursday]});
        await t.start('dates-first');
        await t.chooseLocation(MAIN);
        await t.pickDate(0);
        check('L10/later-date/first', 'Wednesday, before the malformed season applies: bounded by the kitchen\'s hours, and nothing said', same(t.times(), WED_KITCHEN) && t.toasts.length === 0, show({times: t.times().length, toasts: t.toasts.length}));
        await t.pickDate(1);
        check('L10/later-date/second', 'Thursday, where it applies: the application\'s own times and ONE translated toast, nothing thrown out of the date picker',
            same(t.times(), WED_ALL) && t.toasts.length === 1 && t.toasts[0][0] === NO_HOURS && t.toasts[0][1] === 'danger', show({times: t.times().length, toasts: t.toasts}));
        await t.pickDate(0);
        check('L10/later-date/kept', 'back to Wednesday: the answer is kept (the application\'s own times, no second toast)', same(t.times(), WED_ALL) && t.toasts.length === 1, show({times: t.times().length, toasts: t.toasts.length}));
        check('L10/later-date/unhandled', 'no unhandled rejection', unhandled.length === mark, show(unhandled.slice(mark)));
    });

    // =================================================================================================================
    // S: static anchors
    // =================================================================================================================
    const appTwig = read(FILES.appTwig);
    const hospTwig = read(FILES.hospTwig);
    const ATTR = `data-lang-no-delivery-locations="{{ lang('booking.no_delivery_locations') }}"`;
    {
        const at = appTwig.indexOf(ATTR);
        const guard = appTwig.lastIndexOf('{% if enable_hospitality %}', at);
        const closed = appTwig.indexOf('{% endif %}', guard);
        check('S1', 'application page: the attribute is there, inside the enable_hospitality guard that holds data-lang-no-orders', at > 0 && guard > 0 && closed > at && appTwig.indexOf('data-lang-no-orders', guard) > 0 && appTwig.indexOf('data-lang-no-orders', guard) < closed);
        check('S2', 'hospitality page: the attribute is there', hospTwig.includes(ATTR));
        const HOURS_ATTR = `data-lang-opening-hours-unavailable="{{ lang('booking.opening_hours_unavailable') }}"`;
        const hat = appTwig.indexOf(HOURS_ATTR);
        const hguard = appTwig.lastIndexOf('{% if enable_hospitality %}', hat);
        const hclosed = appTwig.indexOf('{% endif %}', hguard);
        check('S1/hours', 'application page: the failure text\'s attribute is there, inside the enable_hospitality guard', hat > 0 && hguard > 0 && hclosed > hat);
        check('S2/hours', 'hospitality page: the failure text\'s attribute is there', hospTwig.includes(HOURS_ATTR));
        check('S2/orders', 'both pages still carry the orders list\'s data-lang-no-orders', appTwig.includes(`data-lang-no-orders="{{ lang('booking.no_orders') }}"`) && hospTwig.includes(`data-lang-no-orders="{{ lang('booking.no_orders') }}"`));
    }
    {
        for (const key of ['no_delivery_locations', 'opening_hours_unavailable']) {
            const rows = [FILES.langNo, FILES.langNn, FILES.langEn].map(f => langRows(read(f))[`booking.${key}`]);
            check(`S3/${key}`, 'the key has a row in no, nn and en', rows.every(r => !!r && r.length > 3), show(rows));
            check(`S3/${key}/distinct`, 'and the three are three different texts', new Set(rows).size === 3, show(rows));
        }
    }
    {
        const appJs = read(FILES.appJs);
        const hospJs = read(FILES.hospJs);
        const orders = (s: string) => (s.match(/new HospitalityOrderList\([\s\S]{0,400}?emptyText: lang\('noOrders'\)/g) ?? []).length;
        check('S4', 'the orders list keeps lang(\'noOrders\') as its own empty text on both pages', orders(appJs) >= 1 && orders(hospJs) >= 1, show({app: orders(appJs), hosp: orders(hospJs)}));
        check('S5', 'the application page passes the hours\' two URLs to the dialog, read from the twig attributes',
            /seasonsBaseUrl:\s*seasonsBaseUrl/.test(appJs) && /serverSettingsUrl:\s*serverSettingsUrl/.test(appJs)
            && appTwig.includes(`data-seasons-base-url="{{ phpgw_link('/bookingfrontend/buildings') }}"`) && appTwig.includes(`data-server-settings-url="{{ phpgw_link('/api/server-settings') }}"`));
        check('S5/hosp', 'the hospitality page passes both URLs and the kitchen\'s building (resource_building_id) to the dialog',
            /seasonsBaseUrl:\s*seasonsBaseUrl/.test(hospJs) && /serverSettingsUrl:\s*serverSettingsUrl/.test(hospJs)
            && /hospBuildingId:\s*hospitalityData \? hospitalityData\.resource_building_id : null/.test(hospJs)
            && hospTwig.includes(`data-seasons-base-url="{{ phpgw_link('/bookingfrontend/buildings') }}"`) && hospTwig.includes(`data-server-settings-url="{{ phpgw_link('/api/server-settings') }}"`));
        const php = read(FILES.repo);
        check('S6', 'the data the dialog reads from the server is still sent: the kitchen\'s building_id per hospitality, resource_building_id on the hospitality page',
            /\) AS building_id/.test(php) && /rb\.id AS resource_building_id/.test(php));
    }
    {
        const api = read(FILES.citizenApi);
        const cm = read(FILES.citizenModal);
        check('S7', 'the citizen client still reads the seasons from /bookingfrontend/buildings/{id}/seasons and the settings from /api/server-settings?include_configs',
            /\['bookingfrontend',\s*'buildings',\s*building_id,\s*'seasons'\]/.test(api) && /\['api',\s*'server-settings'\],\s*\{include_configs:\s*true\}/.test(api));
        check('S8', 'the citizen dialog still offers main always and a remote room only when booked (the rule the Lokale filter copies)',
            /loc\.location_type === 'main' \|\| appResourceIds\.has\(loc\.id\)/.test(cm));
        check('S9', 'the citizen dialog still bounds an on-site order with isWithinBusinessHours over the kitchen\'s seasons and close_calendar_without_season',
            /isWithinBusinessHours\(/.test(cm) && /close_calendar_without_season/.test(cm) && /String\(hospitality\.resource_id\)/.test(cm));
    }
    // Every row above ran with the unhandled-rejection counter on: none may have left one behind (the L10 control proves it can fire).
    check('L10/no-leak', 'no row of this harness left an unhandled rejection behind', unhandled.length === 0, show(unhandled));
} catch (error) {
    console.error('the harness itself crashed:', error);
    process.exit(2);
}

console.log(`${n - failed}/${n} checks pass${skipped ? `, ${skipped} skipped on request` : ''}`);
process.exit(failed ? 1 : 0);
