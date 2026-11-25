#!/usr/bin/env node
/**
 * LIVE check of the admin «Opprett bestilling» dialog on the served stack (task 49612). Run it only against a tree that serves
 * the commit under test; it browses the admin pages as the test login and, WITH --save, creates ONE order (row LV4).
 *
 *   PLAYWRIGHT_CORE=<dir of playwright-core> node tests/hospitality-admin-order-modal/live.cjs [--save] [--out=<dir>]
 *
 *   PLAYWRIGHT_CORE  the playwright-core package directory (required)
 *   CHROME           a Chromium / Chrome for Testing executable (default: the newest in ~/Library/Caches/ms-playwright)
 *   BASE_URL         default https://pe-api.test
 *   AUTH_FILE        default <repo>/AUTH-DETAILS.md (the test login is read from it, never embedded here)
 *   --out=<dir>      screenshots (1280 and 375 wide); default <tmp>/hospitality-admin-order-modal-live
 *
 * The browser and its version are printed first: a UI result is only a result for the browser that produced it.
 *
 * What it measures, on the local test database (hospitality 6 = on-site only, 5 = on-site + remote, 10 = neither; applications
 * 85106 = Wed 2026-12-02 20:00-21:00 booking resource 781, 85095 = Wed 2026-11-25 13:00-14:00 booking 781; the kitchen room 482 is
 * open Wednesday 15:00-23:45 in season 1024 of building 10; ids and hours are the fixtures, re-measure if the data has moved):
 *   LV1  the tester's case: 85106 / h6 offers the kitchen room «Anretning (main)» and nothing else; its times are the
 *        application's own 20:00-21:00 (all inside the hours, so nothing is cut)
 *   LV2  the meeting-room case, unchanged: 85106 / h5 offers «Musikkrom 3 (remote)» and «Anretning (main)», not the room the
 *        application did not book; the remote room's times are 20:00-21:00 and NO opening-hours request is made for it
 *   LV3  the bound at work: 85095 / h5: the kitchen room → no time (13:00-14:00 is before it opens), the remote room → 13:00-14:00
 *   LV4  (--save) 85106 / h6, kitchen room, 20:00 → Lagre → 201, and the order is on the application's order tab
 *   LV5  the empty text: hospitality 10's page, Lokale → the new text; the orders list's own empty text is not it
 *   LV6  the failure arm (pe-ceo 49668: the bound must never stop a case officer from ordering): the seasons request is ABORTED
 *        in the browser (LV6) or answered 200 with something that is not a list (LV6/malformed); 85095 / h5, kitchen room →
 *        the application's own 13:00-14:00 (LV3 shows 0 when the hours arrive) and a danger toast, once, in the page's own
 *        translated words (its data-lang-opening-hours-unavailable, not the browser's or the server's message). Writes nothing.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const repo = path.resolve(__dirname, '../..');
const args = process.argv.slice(2);
const SAVE = args.includes('--save');
const OUT = (args.find(a => a.startsWith('--out=')) || '').slice(6) || path.join(os.tmpdir(), 'hospitality-admin-order-modal-live');
const BASE = process.env.BASE_URL || 'https://pe-api.test';

if (!process.env.PLAYWRIGHT_CORE) { console.error('set PLAYWRIGHT_CORE=<dir of playwright-core>'); process.exit(2); }
const { chromium } = require(process.env.PLAYWRIGHT_CORE);

function chromePath() {
    if (process.env.CHROME) return process.env.CHROME;
    const cache = path.join(os.homedir(), 'Library/Caches/ms-playwright');
    const dirs = fs.existsSync(cache) ? fs.readdirSync(cache).filter(d => /^chromium-\d+$/.test(d)).sort((a, b) => Number(b.slice(9)) - Number(a.slice(9))) : [];
    for (const d of dirs) {
        const p = path.join(cache, d, 'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing');
        if (fs.existsSync(p)) return p;
    }
    throw new Error('no Chromium found: set CHROME=<executable>');
}

function login() {
    const text = fs.readFileSync(process.env.AUTH_FILE || path.join(repo, 'AUTH-DETAILS.md'), 'utf8');
    const user = (text.match(/\*\*Username:\*\*\s*(\S+)/) || [])[1];
    const pass = (text.match(/\*\*Password:\*\*\s*(\S+)/) || [])[1];
    if (!user || !pass) throw new Error('AUTH-DETAILS.md has no Username/Password lines');
    return { user, pass };
}

let n = 0;
let failed = 0;
const check = (id, name, ok, detail = '') => {
    n++;
    if (!ok) failed++;
    console.log(`${ok ? 'PASS' : 'FAIL'} ${id} ${name}${detail ? ' — ' + detail : ''}`);
};
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const quarters = (from, to) => {
    const t = s => Number(s.slice(0, 2)) * 60 + Number(s.slice(3, 5));
    const out = [];
    for (let m = t(from); m <= t(to); m += 15) out.push(String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'));
    return out;
};

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: chromePath(), headless: true });
    console.log('BROWSER Chromium', browser.version(), '(headless, playwright-core)');
    const { user, pass } = login();

    async function newPage(width, height) {
        const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width, height }, locale: 'nb-NO' });
        const page = await ctx.newPage();
        page.setDefaultTimeout(8000);   // a control that is not there is a FAILED ROW (see `row`), not a 30 s wait
        const requests = [];
        page.on('request', r => requests.push(r.method() + ' ' + r.url().replace(BASE, '')));
        const res = await ctx.request.post(BASE + '/login', { form: { login: user, passwd: pass, logindomain: 'default', section: '' } });
        if (res.status() !== 200) throw new Error('login ' + res.status());
        return { ctx, page, requests };
    }
    /** A scenario that cannot reach its own end (nothing to click: the dialog does not offer it) is a failed row, not a crash. */
    const row = async (id, scenario) => {
        try { await scenario(); } catch (e) { check(id, 'the scenario ran to its end', false, String(e.message).split('\n')[0]); }
    };
    const texts = (page, sel) => page.$$eval(sel, els => els.map(e => e.textContent.trim()));
    const dropdown = (page, box) => texts(page, `#${box} .search-select__dropdown li`);
    const options = (page, role) => texts(page, `[data-booking-role="${role}"] option`).then(o => o.slice(1));

    /** Application page → Bestillinger → Opprett bestilling → the chosen Bevertning. */
    async function openApplicationDialog(page, app, hospName) {
        await page.goto(`${BASE}/booking/view/applications/${app}`, { waitUntil: 'domcontentloaded' });   // the first request after login is swallowed
        await page.goto(`${BASE}/booking/view/applications/${app}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#tab-btn-hospitality-orders:not([hidden])', { timeout: 20000 });
        await page.click('#tab-btn-hospitality-orders');
        await page.click('[data-booking-action="create-hospitality-order"]');
        await page.waitForSelector('[data-booking-role="hospitality-input"]');
        await page.click('[data-booking-role="hospitality-input"]');
        await page.locator('#modal-order-hosp-container .search-select__dropdown li', { hasText: hospName }).first().click();
        await page.waitForTimeout(1200);
    }
    async function listLocations(page) {
        await page.fill('[data-booking-role="location-input"]', '');
        await page.click('[data-booking-role="location-input"]');
        await page.waitForTimeout(500);
        return dropdown(page, 'modal-order-loc-container');
    }
    async function chooseLocation(page, text) {
        await listLocations(page);
        await page.locator('#modal-order-loc-container .search-select__dropdown li', { hasText: text }).first().click();
        await page.waitForTimeout(1500);   // the dates, and, for the kitchen room, the hours
    }
    const shots = async (page, name) => {
        for (const [w, h] of [[1280, 900], [375, 812]]) {
            await page.setViewportSize({ width: w, height: h });
            await page.waitForTimeout(250);
            await page.screenshot({ path: path.join(OUT, `${name}-${w}.png`) });
        }
        await page.setViewportSize({ width: 1280, height: 900 });
    };

    // ---- LV1 the tester's case ---------------------------------------------------------------------------------------
    await row('LV1', async () => {
        const { ctx, page, requests } = await newPage(1280, 900);
        await openApplicationDialog(page, 85106, 'Fana kulturhus mat og drikke');
        const offered = await listLocations(page);
        check('LV1', '85106 / h6: Lokale offers the kitchen room and nothing else', same(offered, ['Anretning (main)']), JSON.stringify(offered));
        await chooseLocation(page, 'Anretning (main)');
        const times = await options(page, 'serving-time');
        check('LV1/times', 'its times are the application\'s own 20:00-21:00 (inside Wed 15:00-23:45)', same(times, quarters('20:00', '21:00')), JSON.stringify(times));
        check('LV1/source', 'the hours came from the citizen endpoints, once each',
            requests.filter(r => /\/bookingfrontend\/buildings\/10\/seasons/.test(r)).length === 1 && requests.filter(r => /\/api\/server-settings/.test(r)).length === 1,
            JSON.stringify(requests.filter(r => /seasons|server-settings/.test(r))));
        await shots(page, 'lv1-app85106-h6-main');
        await ctx.close();
    });

    // ---- LV2 the meeting-room case ------------------------------------------------------------------------------------
    await row('LV2', async () => {
        const { ctx, page, requests } = await newPage(1280, 900);
        await openApplicationDialog(page, 85106, 'Fana kulturhus servering');
        const offered = await listLocations(page);
        check('LV2', '85106 / h5: the kitchen room and the booked meeting room, not the one the application did not book',
            same(offered, ['Anretning (main)', 'Musikkrom 3 (remote)']), JSON.stringify(offered));
        await chooseLocation(page, 'Musikkrom 3 (remote)');
        const times = await options(page, 'serving-time');
        check('LV2/times', 'the meeting room\'s times are 20:00-21:00', same(times, quarters('20:00', '21:00')), JSON.stringify(times));
        check('LV2/no-hours-request', 'and no opening-hours request was made for it', requests.filter(r => /seasons|server-settings/.test(r)).length === 0, JSON.stringify(requests.filter(r => /seasons|server-settings/.test(r))));
        await shots(page, 'lv2-app85106-h5-remote');
        await ctx.close();
    });

    // ---- LV3 the bound at work ----------------------------------------------------------------------------------------
    await row('LV3', async () => {
        const { ctx, page } = await newPage(1280, 900);
        await openApplicationDialog(page, 85095, 'Fana kulturhus servering');
        await chooseLocation(page, 'Anretning (main)');
        const main = await options(page, 'serving-time');
        check('LV3/main', '85095 / h5, the kitchen room: Wed 13:00-14:00 is before it opens (15:00) → no time', main.length === 0, JSON.stringify(main));
        await shots(page, 'lv3-app85095-h5-main');
        await chooseLocation(page, 'Musikkrom 3 (remote)');
        const remote = await options(page, 'serving-time');
        check('LV3/remote', 'the same application, the booked meeting room: 13:00-14:00 (the control: the list is not empty for a reason of its own)', same(remote, quarters('13:00', '14:00')), JSON.stringify(remote));
        await ctx.close();
    });

    // ---- LV4 Lagre -----------------------------------------------------------------------------------------------------
    if (SAVE) await row('LV4', async () => {
        const { ctx, page } = await newPage(1280, 900);
        await openApplicationDialog(page, 85106, 'Fana kulturhus mat og drikke');
        await chooseLocation(page, 'Anretning (main)');
        await page.selectOption('[data-booking-role="serving-time"]', { label: '20:00' });
        const [response] = await Promise.all([
            page.waitForResponse(r => r.request().method() === 'POST' && /\/booking\/hospitality-orders/.test(r.url()), { timeout: 20000 }),
            page.click('#modal-order-submit'),
        ]);
        let body = {};
        try { body = await response.json(); } catch (e) { /* not JSON */ }
        check('LV4', 'Lagre → 201', response.status() === 201, `HTTP ${response.status()} ${JSON.stringify(body).slice(0, 200)}`);
        await page.waitForTimeout(1500);
        const tab = (await page.textContent('#application-hospitality-orders')) || '';
        check('LV4/shown', 'the order is on the application\'s Bestillinger tab', /Fana kulturhus mat og drikke/.test(tab) && /Anretning/.test(tab), tab.replace(/\s+/g, ' ').slice(0, 160));
        await shots(page, 'lv4-app85106-order-saved');
        const id = body.id ?? (body.order && body.order.id);
        check('LV4/id', 'the 201 body carries the new order\'s id', Number.isInteger(Number(id)) && Number(id) > 0, JSON.stringify(body).slice(0, 160));
        if (Number(id) > 0) {
            console.log(`ORDER ${id} created on application 85106. Revert it (psql via docker exec), CHILD ROWS FIRST: three tables hold foreign keys to the order.`);
            console.log(`REVERT:    DELETE FROM bb_hospitality_order_changelog WHERE order_id=${id}; DELETE FROM bb_hospitality_order_document WHERE owner_id=${id}; DELETE FROM bb_hospitality_order_line WHERE order_id=${id}; DELETE FROM bb_hospitality_order WHERE id=${id} AND application_id=85106;`);
            console.log(`READ-BACK: SELECT (SELECT count(*) FROM bb_hospitality_order WHERE id=${id}) AS orders, (SELECT count(*) FROM bb_hospitality_order_changelog WHERE order_id=${id}) AS changelog, (SELECT count(*) FROM bb_hospitality_order_document WHERE owner_id=${id}) AS documents, (SELECT count(*) FROM bb_hospitality_order_line WHERE order_id=${id}) AS lines;   -- expect 0 0 0 0`);
        } else {
            console.log('ORDER id unknown. Find it: SELECT id, created FROM bb_hospitality_order WHERE application_id=85106 AND hospitality_id=6 ORDER BY id DESC LIMIT 3;  then revert as above.');
        }
        await ctx.close();
    });
    else console.log('SKIP LV4 (no --save: nothing is written)');

    // ---- LV5 the empty text --------------------------------------------------------------------------------------------
    await row('LV5', async () => {
        const { ctx, page } = await newPage(1280, 900);
        await page.goto(`${BASE}/booking/view/hospitality/10`, { waitUntil: 'domcontentloaded' });
        await page.goto(`${BASE}/booking/view/hospitality/10`, { waitUntil: 'networkidle' });
        await page.waitForSelector('[data-tab="orders"]', { timeout: 20000 });
        await page.click('[data-tab="orders"]');   // the create button is on the orders tab
        await page.waitForSelector('[data-action="create-order"]');
        await page.click('[data-action="create-order"]');
        await page.waitForSelector('[data-booking-role="location-input"]');
        await page.click('[data-booking-role="location-input"]');
        await page.waitForTimeout(600);
        const rows = await dropdown(page, 'modal-order-loc-container');
        const root = await page.$eval('#hospitality-show', e => ({ delivery: e.dataset.langNoDeliveryLocations, orders: e.dataset.langNoOrders }));
        check('LV5', 'hospitality 10 (no delivery location): Lokale shows the new text, not «Ingen bestillinger»',
            rows.length === 1 && !!root.delivery && rows[0] === root.delivery && rows[0] !== root.orders, JSON.stringify({ rows, root }));
        await shots(page, 'lv5-hospitality10-empty');
        await ctx.close();
    });

    // ---- LV6 the hours cannot be fetched ---------------------------------------------------------------------------------
    for (const [id, how, intercept] of [
        ['LV6', 'the seasons request fails (aborted in the browser)', route => route.abort()],
        ['LV6/malformed', 'the seasons request answers 200 with something that is not a list', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"error":"x"}' })],
    ]) await row(id, async () => {
        const { ctx, page, requests } = await newPage(1280, 900);
        await page.route('**/bookingfrontend/buildings/*/seasons*', intercept);   // before the page loads: nothing reaches the server
        await openApplicationDialog(page, 85095, 'Fana kulturhus servering');
        await chooseLocation(page, 'Anretning (main)');
        const toasts = await texts(page, '.app-show__toast[data-color="danger"]');   // the toast lives 3 s on this page; chooseLocation waited 1.5 s
        const times = await options(page, 'serving-time');
        check(id, how + ' → the kitchen room offers the application\'s own 13:00-14:00 (LV3: none when the hours arrive)', same(times, quarters('13:00', '14:00')), JSON.stringify(times));
        const expected = await page.$eval('#application-show', e => e.dataset.langOpeningHoursUnavailable || '');
        check(id + '/toast', 'and the failure is said once, in the page\'s own translated words', toasts.length === 1 && !!expected && toasts[0] === expected, JSON.stringify({ toasts, expected }));
        check(id + '/asked-once', 'and the seasons were asked for once', requests.filter(r => /\/bookingfrontend\/buildings\/10\/seasons/.test(r)).length === 1, JSON.stringify(requests.filter(r => /seasons/.test(r))));
        await shots(page, id.toLowerCase().replace('/', '-') + '-app85095-h5-main');
        await ctx.close();
    });

    await browser.close();
    console.log(`${n - failed}/${n} live checks pass`);
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error('live script crashed:', e); process.exit(2); });
