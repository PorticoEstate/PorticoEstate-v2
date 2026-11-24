#!/usr/bin/env node
/**
 * LIVE arms for the admin order page's Save (task 49777, §49718, pe-backlog/13923): the served order_show.js, driven in
 * Chromium through playwright-core, on the four fixture orders 204 (A) 205 (B) 206 (C) 207 (S) that 49763 created in the
 * test DB. Every arm resets its fixture first, logs in as the admin, records every /booking/ request with its body and
 * every toast the page shows, and reads the order's serving time (UTC, as stored) before and after.
 *
 *   PWCORE=<dir of playwright-core> LIVE_PASSWD=<admin password, AUTH-DETAILS.md> node tests/hospitality-order-show/live.cjs
 *     [--orders 204,205,206,207] [--widths 1280,375] [--arms nochange,line,...] [--patch <order_show.js>] [--out <file.json>]
 *
 * Arms (the row each is the live test of; «N/A» = the shape offers no way to do it, printed, never skipped silently):
 *   load, edit       L1   the page load and the edit toggle write nothing
 *   nochange         L2 (204 205 206) / L8 (207)   Lagre with nothing touched: no request, «no changes»
 *   line             L3 / L8   a quantity changed: only the line request
 *   comment          L4 / L8   the comment changed: one order PUT with comment + changelog_comment and nothing else
 *   change           L5   a different slot chosen: the slot and its application are sent, the DB follows
 *   clear            L6   the time placeholder chosen (after a slot, where the list starts on the placeholder): null is sent
 *   timethendate     L7   (205) date, a time, then ANOTHER date: nothing sent, the warning
 *   datenotime       D2   (205) a date with no preselected slot, no time: the warning, nothing sent
 *   datepreselect    D2p  (205) the stored date re-picked, the stored slot preselected and not chosen: the warning, nothing sent
 *
 * Before any arm the driver reads the SERVED page and prints what order_show.js it inlines («served_page_inlines»); a run
 * without --patch refuses to start (exit 2) unless that is this checkout's file — i.e. unless pe-ceo has served the commit.
 * --expect base is the one exception: the NEGATIVE CONTROL, run while the served file is still the base one, where the
 * arms must FAIL (49763's defect, reproduced) — the proof that the driver can fail.
 *
 * --patch <file>: PRE-FLIGHT without serving. The served page's inlined base order_show.js (git show 7891c67b6:…) is
 * replaced in the response by <file> (route interception); nothing is written to the served tree. A run with no --patch
 * tests what the server serves: that is the dev window. The output says which one it was.
 *
 * Exit 0 when every arm met its expectation, 1 otherwise. The language is read from the repo's .lang rows (no): a user
 * whose UI is another language fails the toast text on purpose (it names the expected text).
 *
 * Data written: the test DB's four fixture orders (reset below) and the changelog rows a save creates. The revert of the
 * fixtures is in forum/pe-general/49763: DELETE the changelog, line and order rows of ids 204-207.
 */
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const { chromium } = require(process.env.PWCORE);
const REPO = path.resolve(__dirname, '..', '..');
const argv = process.argv.slice(2);
const opt = (name, dflt) => { const i = argv.indexOf('--' + name); return i >= 0 ? argv[i + 1] : dflt; };
const ORDERS = opt('orders', '204,205,206,207').split(',').map(Number);
const WIDTHS = opt('widths', '1280,375').split(',').map(Number);
const PATCH = opt('patch', null);
const OUT = opt('out', null);
const EXPECT = opt('expect', 'fix');   // what the SERVED page must inline when there is no --patch: 'fix' (this checkout's file, the window) or 'base' (the negative control)
const BASE_SHA = process.env.HM_BASE_SHA || '7891c67b6';
const JS_REL = 'src/modules/booking/html/base/hospitality/order_show/order_show.js';
const EXE = process.env.CHROMIUM_EXE || process.env.HOME + '/Library/Caches/ms-playwright/chromium-1248/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';

const FIXTURE = {
  204: { shape: 'A', app: 85167, time: '2026-12-10 09:30:00', tag: 'T49718-A-loc-not-main-0-dates' },
  205: { shape: 'B', app: 83734, time: '2026-06-06 17:00:00', tag: 'T49718-B-main-3-dates-group' },
  206: { shape: 'C', app: 84278, time: '2026-11-03 11:00:00', tag: 'T49718-C-1-date-stored-outside' },
  207: { shape: 'S', app: 85166, time: '2026-12-11 11:30:00', tag: 'T49718-S-1-date-slot-matches' },
};
const ALL_ARMS = ['load', 'edit', 'nochange', 'line', 'comment', 'change', 'clear', 'timethendate', 'datenotime', 'datepreselect'];
const ARMS = opt('arms', ALL_ARMS.join(',')).split(',');
const APPLIES = { // which arms a shape offers; the others print N/A with the reason
  A: ['load', 'edit', 'nochange', 'line', 'comment'],
  B: ALL_ARMS,
  C: ['load', 'edit', 'nochange', 'line', 'comment', 'change', 'clear'],
  S: ['load', 'edit', 'nochange', 'line', 'comment', 'change', 'clear'],
};
const NA_WHY = { A: 'the date select offers no date: the page cannot pick or change a serving time on this shape (D4, recorded)', C: 'one date, disabled: the date cannot be changed', S: 'one date, disabled: the date cannot be changed' };

function langRow(file, key) {
  const m = fs.readFileSync(path.join(REPO, file), 'utf8').match(new RegExp('^' + key + '\\tbooking\\tno\\t(.*)$', 'm'));
  return m ? m[1].trim() : null;
}
const NO_CHANGES = langRow('src/modules/booking/setup/phpgw_no.lang', 'no_changes');
const SELECT_TIME = langRow('src/modules/booking/setup/phpgw_no.lang', 'select_time');
const SAVED = langRow('src/modules/booking/setup/phpgw_no.lang', 'saved');

function psql(sql) {
  return execFileSync('docker', ['exec', '-i', 'portico_postgres', 'psql', '-U', 'postgres', '-d', 'portico_ebe', '-At', '-F', '|', '-c', sql]).toString().trim();
}
const dbRow = (id) => {
  const [serving, modified, app, comment] = psql(`SELECT coalesce(serving_time_iso::text,'NULL'), modified::text, coalesce(application_id::text,''), coalesce(comment,'') FROM bb_hospitality_order WHERE id=${id}`).split('|');
  return { serving, modified, app: +app, comment };
};
const dbLog = (id) => +psql(`SELECT count(*) FROM bb_hospitality_order_changelog WHERE order_id=${id}`);
const dbLines = (id) => psql(`SELECT coalesce(string_agg(id||':'||quantity::int, ','),'') FROM bb_hospitality_order_line WHERE order_id=${id}`);
const resetFixture = (id) => {
  const f = FIXTURE[id];
  psql(`UPDATE bb_hospitality_order SET serving_time_iso='${f.time}', comment='${f.tag}', application_id=${f.app} WHERE id=${id}`);
  psql(`UPDATE bb_hospitality_order_line SET quantity=1, amount=unit_price WHERE order_id=${id}`);
};
const utc = (iso) => new Date(iso).toISOString().slice(0, 19).replace('T', ' ');

async function runArm(browser, ctx, width, id, arm) {
  const shape = FIXTURE[id].shape;
  const out = { width, order: id, shape, arm, row: rowOf(id, arm) };
  if (!APPLIES[shape].includes(arm)) { out.n_a = NA_WHY[shape] || 'not offered by this shape'; return out; }
  resetFixture(id);
  out.db_before = dbRow(id); out.log_before = dbLog(id); out.lines_before = dbLines(id);
  const page = await ctx.newPage();
  await page.addInitScript(() => {
    window.__toasts = [];
    new MutationObserver((ms) => ms.forEach((m) => m.addedNodes.forEach((n) => {
      if (n.classList && n.classList.contains('app-show__toast')) window.__toasts.push({ text: n.textContent, color: n.dataset.color });
    }))).observe(document, { childList: true, subtree: true });
  });
  if (PATCH) {
    const base = execFileSync('git', ['-C', REPO, 'show', `${BASE_SHA}:${JS_REL}`]).toString();
    const patched = fs.readFileSync(PATCH, 'utf8');
    await page.route(/\/booking\/view\/hospitality-orders\/\d+$/, async (route) => {
      const r = await route.fetch(); const body = await r.text();
      if (!body.includes(base)) { out.patch_error = 'the base js is not inline in the served page'; return route.fulfill({ response: r, body }); }
      await route.fulfill({ response: r, body: body.split(base).join(patched) });
    });
  }
  const reqs = [];
  page.on('request', (r) => { const u = new URL(r.url()); if (/^\/booking\//.test(u.pathname)) reqs.push({ method: r.method(), path: u.pathname + u.search, body: r.postData() || undefined }); });
  await page.goto('https://pe-api.test/booking/view/hospitality-orders/' + id, { waitUntil: 'networkidle' });
  await page.waitForSelector('#toggle-edit-mode, #order-error:not([hidden])', { timeout: 15000 });
  await page.waitForTimeout(600);
  out.after_load_non_get = reqs.filter((r) => r.method !== 'GET').length;
  if (arm !== 'load') {
    await page.click('#toggle-edit-mode');
    await page.waitForSelector('#save-all-changes');
    out.edit_state = await page.evaluate(() => {
      const d = document.querySelector('[data-edit-date]'); const t = document.querySelector('[data-edit-time]');
      return { date: { value: d.value, disabled: d.disabled, options: d.options.length }, time: { value: t.value, disabled: t.disabled, options: t.options.length, selected: t.options[t.selectedIndex] && t.options[t.selectedIndex].textContent } };
    });
    out.after_edit_non_get = reqs.filter((r) => r.method !== 'GET').length;
  }
  const save = arm !== 'load' && arm !== 'edit';
  if (save) {
    const d = page.locator('[data-edit-date]'); const t = page.locator('[data-edit-time]');
    const firstSlotNot = (cur) => t.evaluate((s, cur) => Array.from(s.options).findIndex((o) => o.value !== '' && o.value !== cur), cur);
    if (arm === 'change' || arm === 'clear') {
      if (await d.isEnabled()) await d.selectOption({ index: 1 });                       // B: its first date
      if (arm === 'change') {
        const idx = await firstSlotNot(await t.inputValue());
        await t.selectOption({ index: idx });
        out.chosen_time = await t.inputValue();
      } else {
        if ((await t.inputValue()) === '') await t.selectOption({ index: await firstSlotNot('') });   // an officer cannot choose the placeholder it is already on
        await t.selectOption('');
      }
    } else if (arm === 'timethendate') {
      await d.selectOption({ index: 1 }); await t.selectOption({ index: 2 }); await d.selectOption({ index: 2 });
      out.time_after_date_change = await t.inputValue();
    } else if (arm === 'datenotime') {
      await d.selectOption({ index: 2 }); out.time_after_date = await t.inputValue();
    } else if (arm === 'datepreselect') {
      await d.selectOption({ index: 1 }); out.time_after_date = await t.inputValue();
    } else if (arm === 'line') {
      await page.fill('[data-qty-article]', '2');
    } else if (arm === 'comment') {
      await page.fill('textarea[data-edit-field="comment"]', 'T49777 comment edited ' + Date.now());
    }
    const before = reqs.length;
    await page.click('#save-all-changes');
    const dlg = await page.waitForSelector('#changelog-comment-input', { timeout: 1500 }).catch(() => null);
    out.comment_dialog_shown = !!dlg;
    if (dlg) { await page.fill('#changelog-comment-input', `T49777 ${arm} ${width}`); await page.click('#changelog-comment-submit'); }
    await page.waitForTimeout(2200);
    out.save_requests = reqs.slice(before).filter((r) => r.method !== 'GET').map((r) => `${r.method} ${r.path} ${r.body || ''}`.trim());
    out.toasts = await page.evaluate(() => window.__toasts);
  }
  out.db_after = dbRow(id); out.log_after = dbLog(id); out.lines_after = dbLines(id);
  await page.close();
  out.problems = expectations(out);
  out.verdict = out.problems.length ? 'FAIL' : 'PASS';
  return out;
}

function rowOf(id, arm) {
  if (arm === 'load' || arm === 'edit') return 'L1';
  if (id === 207 && ['nochange', 'line', 'comment'].includes(arm)) return 'L8';
  return { nochange: 'L2', line: 'L3', comment: 'L4', change: 'L5', clear: 'L6', timethendate: 'L7', datenotime: 'D2', datepreselect: 'D2p' }[arm];
}

function expectations(o) {
  const p = [];
  const same = o.db_after.serving === o.db_before.serving && o.db_after.app === o.db_before.app;
  const none = (o.save_requests || []).length === 0;
  const warn = (o.toasts || []).length === 1 && o.toasts[0].text === SELECT_TIME && o.toasts[0].color === 'warning';
  const body = (s) => JSON.parse(s.replace(/^PUT \S+ /, ''));
  if (o.after_load_non_get !== 0) p.push(`the page load wrote ${o.after_load_non_get} request(s)`);
  if (o.arm === 'load') { if (!same) p.push('the DB changed on a load'); return p; }
  if (o.after_edit_non_get !== 0) p.push(`the edit toggle wrote ${o.after_edit_non_get} request(s)`);
  if (o.arm === 'edit') { if (!same) p.push('the DB changed on the edit toggle'); return p; }
  if (['nochange', 'timethendate', 'datenotime', 'datepreselect'].includes(o.arm)) {
    if (!none) p.push(`requests were sent: ${JSON.stringify(o.save_requests)}`);
    if (!same) p.push(`serving time/application changed in the DB: ${o.db_before.serving}/${o.db_before.app} → ${o.db_after.serving}/${o.db_after.app}`);
    if (o.db_after.modified !== o.db_before.modified) p.push('`modified` was bumped');
    if (o.comment_dialog_shown) p.push('the changelog-comment dialog was asked for');
    if (o.arm === 'nochange') { if (!((o.toasts || []).length === 1 && o.toasts[0].text === NO_CHANGES)) p.push(`expected the «${NO_CHANGES}» toast, got ${JSON.stringify(o.toasts)}`); }
    else if (!warn) p.push(`expected exactly the «${SELECT_TIME}» warning toast, got ${JSON.stringify(o.toasts)}`);
    if (o.arm === 'datepreselect' && !(o.time_after_date && o.time_after_date !== '')) p.push('premise: the stored slot was not preselected after the date was re-picked');
    if (o.arm === 'datenotime' && o.time_after_date !== '') p.push('premise: a slot was preselected, so this is not the no-slot arm');
    if (o.arm === 'timethendate' && o.time_after_date_change !== '') p.push('premise: the time list was not rebuilt on the placeholder by the date change');
    return p;
  }
  if (o.arm === 'line') {
    if (!(o.save_requests.length === 1 && /^PUT \/booking\/hospitality-orders\/\d+\/lines\/\d+ /.test(o.save_requests[0]))) p.push(`expected exactly the one line PUT, got ${JSON.stringify(o.save_requests)}`);
    if (!same) p.push('serving time/application changed in the DB');
    if (!/:2(,|$)/.test(o.lines_after)) p.push(`the line was not saved: ${o.lines_after}`);
    return p;
  }
  if (o.arm === 'comment') {
    const keys = o.save_requests.length === 1 ? Object.keys(body(o.save_requests[0])).sort().join(',') : null;
    if (keys !== 'changelog_comment,comment') p.push(`expected one order PUT with exactly comment + changelog_comment, got ${JSON.stringify(o.save_requests)}`);
    if (!same) p.push('serving time/application changed in the DB');
    if (!/^T49777 comment edited/.test(o.db_after.comment)) p.push(`the comment was not saved: ${o.db_after.comment}`);
    return p;
  }
  if (o.arm === 'change') {
    const b = o.save_requests.length === 1 ? body(o.save_requests[0]) : null;
    if (!b || b.serving_time_iso !== o.chosen_time || !b.application_id) p.push(`expected one order PUT with the chosen slot ${o.chosen_time} and an application_id, got ${JSON.stringify(o.save_requests)}`);
    if (o.db_after.serving !== utc(o.chosen_time)) p.push(`the DB serving time is ${o.db_after.serving}, expected ${utc(o.chosen_time)} (UTC)`);
    if (o.log_after !== o.log_before + 1) p.push(`expected one changelog row, got ${o.log_after - o.log_before}`);
    return p;
  }
  if (o.arm === 'clear') {
    const b = o.save_requests.length === 1 ? body(o.save_requests[0]) : null;
    if (!b || b.serving_time_iso !== null || !('serving_time_iso' in b)) p.push(`expected one order PUT with serving_time_iso null, got ${JSON.stringify(o.save_requests)}`);
    if (o.db_after.serving !== 'NULL') p.push(`the DB serving time is ${o.db_after.serving}, expected NULL`);
    return p;
  }
  return p;
}

(async () => {
  if (!process.env.LIVE_PASSWD) { console.error('set LIVE_PASSWD (AUTH-DETAILS.md)'); process.exit(2); }
  const browser = await chromium.launch({ executablePath: EXE, headless: true });
  const header = { browser: 'Chromium ' + browser.version(), playwright_core: require(path.join(path.dirname(require.resolve(process.env.PWCORE)), 'package.json')).version, client: PATCH ? 'PATCHED via route interception (PRE-FLIGHT, not served): ' + PATCH : 'served order_show.js (the dev window)', driver_checkout_head: execFileSync('git', ['-C', REPO, 'rev-parse', '--short', 'HEAD']).toString().trim(), tz: 'Europe/Oslo' };
  console.log(JSON.stringify({ header }));
  const results = [];
  for (const width of WIDTHS) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width, height: 900 }, timezoneId: 'Europe/Oslo', locale: 'nb-NO' });
    const login = await ctx.request.post('https://pe-api.test/login', { form: { login: 'henning', passwd: process.env.LIVE_PASSWD, logindomain: 'default' } });
    if (login.status() !== 200) { console.error('login ' + login.status()); process.exit(2); }
    if (!header.served_page_inlines) {
      // What the SERVER hands out, not what this checkout holds: the page inlines order_show.js, so look for it there.
      const html = await (await ctx.request.get('https://pe-api.test/booking/view/hospitality-orders/' + ORDERS[0])).text();
      const mine = fs.readFileSync(path.join(REPO, JS_REL), 'utf8');
      const base = execFileSync('git', ['-C', REPO, 'show', `${BASE_SHA}:${JS_REL}`]).toString();
      header.served_page_inlines = html.includes(mine) ? "this checkout's order_show.js (the fix)" : html.includes(base) ? `the BASE order_show.js (${BASE_SHA})` : "NEITHER this checkout's file NOR the base file";
      console.log(JSON.stringify({ served_page_inlines: header.served_page_inlines }));
      const want = PATCH || EXPECT === 'base' ? 'the BASE' : "this checkout's";   // with --patch the served file is the base one and the route swaps it
      if (!header.served_page_inlines.startsWith(want)) {
        console.error(`NOT RUNNING: the served page inlines ${header.served_page_inlines}, this run (${PATCH ? '--patch' : '--expect ' + EXPECT}) needs ${want} file. ${PATCH || EXPECT === 'base' ? '' : 'Ask pe-ceo to serve the commit first.'}`);
        process.exit(2);
      }
    }
    for (const id of ORDERS) for (const arm of ARMS) {
      let r;
      try { r = await runArm(browser, ctx, width, id, arm); } catch (e) { r = { width, order: id, arm, row: rowOf(id, arm), verdict: 'FAIL', problems: ['driver error: ' + e.message] }; }
      results.push(r);
      console.log(JSON.stringify(r));
    }
    await ctx.close();
  }
  await browser.close();
  console.log('\n' + JSON.stringify(header));
  console.log('width order shape arm            row   verdict  serving (DB, UTC) before → after');
  for (const r of results) {
    const db = r.db_before ? `${r.db_before.serving} → ${r.db_after ? r.db_after.serving : '?'}` : '';
    console.log(`${String(r.width).padEnd(5)} ${r.order}   ${(r.shape || '?').padEnd(5)} ${r.arm.padEnd(15)} ${String(r.row).padEnd(5)} ${(r.n_a ? 'N/A' : r.verdict).padEnd(8)} ${db}${r.problems && r.problems.length ? '   ✗ ' + r.problems.join(' | ') : ''}`);
  }
  const bad = results.filter((r) => r.verdict === 'FAIL').length;
  console.log(`\n${results.filter((r) => r.verdict === 'PASS').length} PASS, ${bad} FAIL, ${results.filter((r) => r.n_a).length} N/A`);
  if (OUT) fs.writeFileSync(OUT, JSON.stringify({ header, results }, null, 1));
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.error('DRIVER ERROR', e); process.exit(2); });
