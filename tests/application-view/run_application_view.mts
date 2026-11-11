/**
 * Hermetic check of the pure parts of the Min side application view (GH #1423, task 40289):
 *   A. applicationWhen (utils/application-when.ts): the header's «Onsdag 23. desember 2026, 08:00–16:00» and the
 *      number of further dates, from the wire strings the API sends (measured on 84278 / 84285 / 84272), in no / nn /
 *      en; the EARLIEST date whatever the order of the input; a date that spans midnight; midnight itself («00:00»,
 *      never «24:00»); an unreadable date skipped, never thrown; and ZONE INDEPENDENCE: the same text when this process
 *      runs in another zone (child runs, whose control is the legacy browser-zone formatting, which DOES change).
 *   B. the stored order lines (utils/article-pricing.ts): splitStoredLines puts category 1 under the room rental and
 *      everything else (a line WITHOUT a category included, as every line was on the websocket lane before this
 *      task) under equipment and services; storedLinesAreFree / storedLinesInclOre price a list per line from the
 *      line's OWN stored tax, so 0 % and 25 % lines and a line whose stored tax contradicts its code add up to the øre.
 *      The oracle is exact BigInt arithmetic: tax = round-half-up(ex x percent / 100).
 *   C. the totals of the price cards (tasks 40439 and its Opus FIX 40491, F1 of GH #1423): when billing cannot be ruled
 *      out, or the catering cannot be read, the price summary (Prissammendrag) is not drawn and the application page
 *      closes on ONE row, the registered total: the stored lines added up incl. VAT (what the server's order.sum adds
 *      up). showsPriceSummary is the one statement of «the summary is drawn» (catering-overview draws it, and
 *      applicationTotals gives the row exactly when it is not); the old inline expressions of both components are run
 *      against the new pure functions over every guard reason, an absent `actions`, every pair of orders, every state
 *      of the catering read and several stored lines. The three components are read as text to prove they use them
 *      (the mutants edit those copies too).
 *   D. design A (task 40576): the VAT is broken down ONCE on the page. cateringEnding decides how the catering section
 *      ends (nothing / one «Bevertning total» row / its own sum ex VAT + VAT + total) from whether the price summary
 *      is drawn; over every stored set x catering state x catering list (billed, unbilled, absent actions, 0 kr, an
 *      unknown VAT, mixed rates) the page shows at most ONE breakdown, exactly one when catering is priced or the
 *      summary is drawn, and the old page's count wherever it was not two. uniqueKitchenContacts says the kitchen's
 *      contact once per kitchen. application-details.tsx is read as text for the order of the price surface.
 *
 *   node tests/application-view/run_application_view.mts
 *
 * The client code is plain TypeScript with type annotations only, so Node strips the types itself (Node >= 22.18).
 * AV_ROOT (optional) = a tree holding src/; mutate_application_view.sh points it at a copy that carries one deliberate
 * defect and expects this harness to go red.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {spawnSync} from 'node:child_process';
import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.AV_ROOT ?? resolve(here, '../..');
const utils = join(root, 'src/modules/bookingfrontend/client/src/utils');

const dir = mkdtempSync(join(tmpdir(), 'app-view-'));
const dropImports = (text: string) => text.split('\n').filter(line => !line.startsWith('import ')).join('\n');
const copy = (name: string, text: string) => writeFileSync(join(dir, name), text);
const rewrite = (file: string, from: string, to: string): string => {
    const text = readFileSync(join(utils, file), 'utf8');
    const out = text.replace(from, to);
    if (out === text) throw new Error(`${file} no longer contains ${from}`);
    return out;
};
copy('hospitality-deadline.mts', readFileSync(join(utils, 'hospitality-deadline.ts'), 'utf8'));
copy('catering-venue-time.mts', rewrite('catering-venue-time.ts', "from './hospitality-deadline'", "from './hospitality-deadline.mts'"));
copy('application-when.mts', rewrite('application-when.ts', "from './hospitality-deadline'", "from './hospitality-deadline.mts'")
    .replace("from './catering-venue-time'", "from './catering-venue-time.mts'"));
copy('cost-utils.mts', dropImports(readFileSync(join(utils, 'cost-utils.ts'), 'utf8')));
copy('catering-status.mts', readFileSync(join(utils, 'catering-status.ts'), 'utf8'));
copy('article-pricing.mts', `const DateTime: any = {};\nimport {formatAmount, formatCurrency} from './cost-utils.mts';\nimport {cateringBillingNotRuledOut, showsPriceSummary} from './catering-status.mts';\n`
    + dropImports(readFileSync(join(utils, 'article-pricing.ts'), 'utf8')));

const W: any = await import(pathToFileURL(join(dir, 'application-when.mts')).href);
const P: any = await import(pathToFileURL(join(dir, 'article-pricing.mts')).href);
const S: any = await import(pathToFileURL(join(dir, 'catering-status.mts')).href);

let failures = 0;
let checks = 0;
const eq = (name: string, got: unknown, expected: unknown) => {
    checks++;
    const show = (value: unknown) => JSON.stringify(value, (_key, v) => (typeof v === 'bigint' ? `${v}n` : v));
    if (show(got) !== show(expected)) {
        failures++;
        console.log(`FAIL ${name}\n   expected: ${show(expected)}\n   got:      ${show(got)}`);
    }
};

// ---- A. applicationWhen
const d = (from_: string, to_: string) => ({from_, to_});
const one = [d('2026-12-23T08:00:00+01:00', '2026-12-23T16:00:00+01:00')];
eq('one date, no', W.applicationWhen(one, 'no'), {text: 'Onsdag 23. desember 2026, 08:00–16:00', moreDates: 0});
eq('one date, nn', W.applicationWhen(one, 'nn'), {text: 'Onsdag 23. desember 2026, 08:00–16:00', moreDates: 0});
eq('one date, en', W.applicationWhen(one, 'en'), {text: 'Wednesday, 23 December 2026, 08:00–16:00', moreDates: 0});
// 84278's own wire strings (summer time, +02:00)
eq('84278', W.applicationWhen([d('2029-06-04T12:30:00+02:00', '2029-06-04T15:00:00+02:00')], 'no'),
    {text: 'Mandag 4. juni 2029, 12:30–15:00', moreDates: 0});
// several dates: the earliest, whatever the order, and the count of the others
const several = [
    d('2029-06-11T12:30:00+02:00', '2029-06-11T15:00:00+02:00'),
    d('2029-06-04T12:30:00+02:00', '2029-06-04T15:00:00+02:00'),
    d('2029-06-18T12:30:00+02:00', '2029-06-18T15:00:00+02:00'),
];
eq('several dates: earliest + 2 more', W.applicationWhen(several, 'no'), {text: 'Mandag 4. juni 2029, 12:30–15:00', moreDates: 2});
eq('several dates: input order irrelevant', W.applicationWhen([...several].reverse(), 'no'), W.applicationWhen(several, 'no'));
// the earliest is by INSTANT, not by the written date: 01:00+02:00 on the 5th is 23:00Z on the 4th, which is BEFORE
// 23:30Z on the 4th (01:30 Oslo on the 5th), although the second one's text starts with the earlier date
eq('earliest by instant across offsets',
    W.applicationWhen([d('2029-06-04T23:30:00+00:00', '2029-06-05T00:30:00+00:00'), d('2029-06-05T01:00:00+02:00', '2029-06-05T02:00:00+02:00')], 'no'),
    {text: 'Tirsdag 5. juni 2029, 01:00–02:00', moreDates: 1});
// the hour that repeats when summer time ends (Oslo, 25 Oct 2026: 02:30 happens at 00:30Z and again at 01:30Z): both read
// «02:30», the EARLIER one is the one at +02:00, so its end shows, whichever came first in the input
const repeated = [d('2026-10-25T02:30:00+01:00', '2026-10-25T03:30:00+01:00'), d('2026-10-25T02:30:00+02:00', '2026-10-25T02:45:00+02:00')];
eq('the repeated hour: the first 02:30 wins', W.applicationWhen(repeated, 'no'), {text: 'Søndag 25. oktober 2026, 02:30–02:45', moreDates: 1});
eq('the repeated hour: input order irrelevant', W.applicationWhen([...repeated].reverse(), 'no'), W.applicationWhen(repeated, 'no'));
// spans midnight
eq('spans midnight', W.applicationWhen([d('2026-12-23T22:00:00+01:00', '2026-12-24T02:00:00+01:00')], 'no').text,
    'Onsdag 23. desember 2026 22:00 → Torsdag 24. desember 2026 02:00');
// midnight itself
eq('midnight is 00:00, never 24:00', W.applicationWhen([d('2026-12-23T00:00:00+01:00', '2026-12-23T01:00:00+01:00')], 'no').text,
    'Onsdag 23. desember 2026, 00:00–01:00');
// the venue's day, from a UTC instant
eq('UTC instant read at the venue', W.applicationWhen([d('2026-12-22T23:30:00Z', '2026-12-23T01:00:00Z')], 'no').text,
    'Onsdag 23. desember 2026, 00:30–02:00');
// unreadable: skipped, not thrown; none readable = null
eq('an unreadable date is skipped', W.applicationWhen([d('nonsense', 'x'), ...one], 'no'), {text: 'Onsdag 23. desember 2026, 08:00–16:00', moreDates: 1});
eq('no readable date = null', W.applicationWhen([d('nonsense', 'x')], 'no'), null);
eq('no dates = null', W.applicationWhen([], 'no'), null);
eq('an unreadable end keeps the start', W.applicationWhen([d('2026-12-23T08:00:00+01:00', 'x')], 'no').text, 'Onsdag 23. desember 2026, 08:00');

// zone independence: the same text under other process zones (child runs)
const probe = `
import {pathToFileURL} from 'node:url';
const W = await import(pathToFileURL(${JSON.stringify(join(dir, 'application-when.mts'))}).href);
const legacy = new Date('2029-06-04T12:30:00+02:00').toLocaleTimeString('nb-NO', {hour: '2-digit', minute: '2-digit'});
console.log(JSON.stringify({text: W.applicationWhen([{from_: '2029-06-04T12:30:00+02:00', to_: '2029-06-04T15:00:00+02:00'}, {from_: '2026-12-22T23:30:00Z', to_: '2026-12-23T01:00:00Z'}], 'no'), legacy}));
`;
const probeFile = join(dir, 'probe.mts');
writeFileSync(probeFile, probe);
const byZone = new Map<string, {text: unknown; legacy: string}>();
for (const tz of ['Europe/Oslo', 'UTC', 'America/Los_Angeles', 'Pacific/Auckland']) {
    const run = spawnSync(process.execPath, [probeFile], {env: {...process.env, TZ: tz}, encoding: 'utf8'});
    if (run.status !== 0) { failures++; checks++; console.log(`FAIL zone ${tz}: ${run.stderr}`); continue; }
    byZone.set(tz, JSON.parse(run.stdout.trim()));
}
const texts = [...byZone.values()].map(v => JSON.stringify(v.text));
eq('same text in every zone', new Set(texts).size, 1);
eq('control: the browser-zone formatting differs between zones', new Set([...byZone.values()].map(v => v.legacy)).size > 1, true);

// ---- B. stored lines
const line = (amount: string, tax: string, extra: Record<string, unknown> = {}) => ({amount, tax, ...extra});
const lines = [
    {order_id: 1, article_cat_id: 1, name: 'Rom', amount: '0.00', tax: '0.00'},
    {order_id: 2, article_cat_id: 2, name: 'Utvask', amount: '250.00', tax: '62.50'},
    {order_id: 3, article_cat_id: 2, name: 'Prosjektor', amount: '100.00', tax: '0.00'},
    {order_id: 4, name: 'No category', amount: '10.00', tax: '2.50'},
];
const split = P.splitStoredLines([{lines: [lines[0], lines[1]]}, {lines: [lines[2], lines[3]]}]);
eq('rental = category 1', split.rental.map((l: any) => l.name), ['Rom']);
eq('services = every other line, a line without a category included', split.services.map((l: any) => l.name), ['Utvask', 'Prosjektor', 'No category']);
eq('free: a 0 kr list', P.storedLinesAreFree([line('0.00', '0.00'), line('0', '0')]), true);
eq('not free: any amount', P.storedLinesAreFree([line('0.00', '0.00'), line('0.01', '0.00')]), false);
eq('not free: VAT only', P.storedLinesAreFree([line('0.00', '0.01')]), false);
eq('an empty list is not «free»', P.storedLinesAreFree([]), false);

// incl. VAT = ex + the line's OWN stored tax; mixed rates + a line whose stored tax contradicts its code (tax_percent 25, tax 0.00)
const mixed = [line('250.00', '62.50', {tax_percent: 25}), line('100.00', '0.00', {tax_percent: 0}), line('100.00', '0.00', {tax_percent: 25}), line('136.99', '34.25', {tax_percent: 25})];
const oracle = (ex: string, tax: string): bigint => BigInt(Math.round(Number(ex) * 100)) + BigInt(Math.round(Number(tax) * 100));
const expected = mixed.reduce((sum, l) => sum + oracle(l.amount, l.tax), 0n);
eq('mixed 0 % / 25 % / contradicting line: the sum of the stored amounts + stored taxes', P.storedLinesInclOre(mixed), Number(expected));
eq('... = 250 + 62,50 + 100 + 100 + 136,99 + 34,25 kr in øre', Number(expected), 68374);
// never a constant rate: the same ex amounts with different stored taxes give different totals
eq('the VAT comes from the line, not a rate', P.storedLinesInclOre([line('100.00', '15.00')]), 11500);
eq('0 % line', P.storedLinesInclOre([line('100.00', '0.00')]), 10000);

// ---- C. the totals of the price cards, and the rule that decides when the price summary is drawn
// Every shape the server's `actions` can take: absent, null, and each pair of (change, cancel) over an allowed action,
// a refusal with no reason and a refusal for each guard reason (HospitalityOrderGuard::REASON_*).
const GUARD_REASONS = ['order_cutoff_passed', 'cancellation_deadline_passed', 'serving_time_passed', 'order_billed',
    'order_paid_at_checkout', 'application_closed', 'order_status', 'no_deadline_basis'];
const act = (allowed: boolean, reason: string | null) => ({allowed, reason, until: null});
const oneAction = [act(true, null), act(false, null), ...GUARD_REASONS.map(reason => act(false, reason))];
const shapes: any[] = [undefined, null];
for (const change of oneAction) for (const cancel of oneAction) shapes.push({change, cancel});
const orderOf = (actions: any) => ({actions, lines: []});
const lists: any[][] = [[]];
for (const a of shapes) {
    lists.push([orderOf(a)]);
    for (const b of shapes) lists.push([orderOf(a), orderOf(b)]);
}
const nonEmptyLists = lists.filter(list => list.length > 0);

// F1a: the OLD inline expression of catering-overview.tsx («the summary is drawn for orders that are all proven not
// billed, whose total is not 0 kr») against showsPriceSummary, over EVERY list and total.
const TOTALS = [null, 0, 1, 5000];
const oldSummaryShown = (orders: any[], total: number | null): boolean =>
    orders.length > 0 && orders.every(order => S.isProvablyNotBilled(order.actions)) && (total === null || total > 0);
const summaryCases = lists.flatMap(list => TOTALS.map(total => ({list, total})));
eq(`F1a: the old inline expression == showsPriceSummary over ${lists.length} order lists x ${TOTALS.length} totals (${shapes.length} action shapes, pairs, empty)`,
    summaryCases.filter(({list, total}) => oldSummaryShown(list, total) !== S.showsPriceSummary(list, total)).length, 0);
eq('F1a control: both outcomes occur (the comparison could have disagreed)',
    [new Set(summaryCases.map(({list, total}) => oldSummaryShown(list, total))).size, new Set(summaryCases.map(({list, total}) => S.showsPriceSummary(list, total))).size], [2, 2]);
eq('summary: no orders = no summary', S.showsPriceSummary([], 5000), false);
eq('summary: a total of 0 kr = no summary', S.showsPriceSummary([orderOf({change: act(true, null), cancel: act(true, null)})], 0), false);
eq('summary: an UNKNOWN total is not 0 kr = the summary is drawn', S.showsPriceSummary([orderOf({change: act(true, null), cancel: act(true, null)})], null), true);
eq('summary: a billed order = no summary', S.showsPriceSummary([orderOf({change: act(false, 'order_billed'), cancel: act(false, 'order_billed')})], 5000), false);

eq('predicate: no orders = billing cannot be at issue', S.cateringBillingNotRuledOut([]), false);
eq('predicate: an absent actions cannot rule billing out', S.cateringBillingNotRuledOut([orderOf(undefined)]), true);
eq('predicate: a billed order cannot be ruled out', S.cateringBillingNotRuledOut([orderOf({change: act(false, 'order_billed'), cancel: act(false, 'order_billed')})]), true);
eq('predicate: one order that is not proven makes the list «not ruled out»',
    S.cateringBillingNotRuledOut([orderOf({change: act(true, null), cancel: act(true, null)}), orderOf(undefined)]), true);
eq('predicate: allowed actions prove it', S.cateringBillingNotRuledOut([orderOf({change: act(true, null), cancel: act(true, null)})]), false);

// The stored lines as the API sends them; order.sum is the server's own float sum of amount + tax per line.
const stored = (orderId: number, lines: any[]) => ({order_id: orderId, sum: lines.reduce((total, l) => total + Number(l.amount) + Number(l.tax ?? 0), 0), lines});
const ROOM = 1;
const ADDON = 2;
const billed = orderOf({change: act(false, 'order_billed'), cancel: act(false, 'order_billed')});
const unbilled = orderOf({change: act(true, null), cancel: act(true, null)});
const sigma = (orders: any[]): number => Math.round(orders.reduce((total, order) => total + order.sum, 0) * 100);

// application 83833: a 0 kr room line and a 25 kr add-on + 6,25 kr VAT on two stored orders, one billed catering order
const app83833 = [stored(23111, [{article_cat_id: ROOM, amount: '0.00', tax: '0.00', tax_percent: 0}]),
    stored(23122, [{article_cat_id: ADDON, amount: '25.00', tax: '6.25', tax_percent: 25}])];
// mixed rates (0 / 15 / 25 %, a line whose stored tax contradicts its code, odd øre), over several stored orders: a room and add-ons
const mixedOrders = [
    stored(1, [{article_cat_id: ROOM, amount: '250.00', tax: '62.50', tax_percent: 25}, {article_cat_id: ROOM, amount: '100.00', tax: '0.00', tax_percent: 0}]),
    stored(2, [{article_cat_id: ADDON, amount: '100.00', tax: '0.00', tax_percent: 25}, {article_cat_id: ADDON, amount: '136.99', tax: '34.25', tax_percent: 25}]),
    stored(3, [{article_cat_id: ADDON, amount: 200, tax: 30, tax_percent: 15}]),
];
const rentalOnly = [app83833[0], stored(5, [{article_cat_id: ROOM, amount: '90.00', tax: '22.50'}])];
const servicesOnly = [stored(6, [{article_cat_id: ADDON, amount: '100.00', tax: '25.00'}, {article_cat_id: ADDON, amount: '200.00', tax: '0.00'}])];
// a line with no tax is a line, not a gap: 0 / absent / '' all count their amount
const untaxed = [stored(7, [{article_cat_id: ADDON, amount: '100.00', tax: 0}, {article_cat_id: ADDON, amount: '50.00'}, {article_cat_id: ROOM, amount: '25.00', tax: ''}])];
const T = (stored_: any[], cateringOrders: any[], state: string) => P.applicationTotals(stored_, cateringOrders, state);

// F1b: billed catering, the catering read: the row is the registered total = Σ order.sum, per line, no rate assumed
eq('83833 billed: the row is «registered», the sum of order.sum = 31,25 kr', T(app83833, [billed], 'read').closing, {kind: 'registered', ore: 3125});
eq('83833 billed: ... = Σ order.sum', T(app83833, [billed], 'read').closing?.ore, sigma(app83833));
eq('83833 billed: the price summary is not drawn', S.showsPriceSummary([billed], P.applicationPriceSummary(app83833, [billed]).total.inclOre), false);
eq('83833 billed: the services total is withheld', T(app83833, [billed], 'read').showServicesTotal, false);
eq('mixed 0 / 15 / 25 % + contradicting line, billed: the stored amounts + stored taxes', T(mixedOrders, [billed], 'read').closing, {kind: 'registered', ore: 91374});
eq('mixed: = Σ order.sum', T(mixedOrders, [billed], 'read').closing?.ore, sigma(mixedOrders));
eq('mixed: one billed among unbilled orders still shows the row', T(mixedOrders, [unbilled, billed], 'read').closing, {kind: 'registered', ore: 91374});
eq('mixed: no rate is assumed (the VAT comes from each line)', T([stored(9, [{article_cat_id: ADDON, amount: '100.00', tax: '15.00', tax_percent: 15}])], [billed], 'read').closing?.ore, 11500);
eq('lines without a tax are not dropped from the sum', T(untaxed, [billed], 'read').closing?.ore, 17500);
eq('services only, billed: the row (the services total is withheld)', T(servicesOnly, [billed], 'read').closing, {kind: 'registered', ore: 32500});

// when there is NO row, catering read
eq('proven not billed: no row (the price summary is drawn)', T(mixedOrders, [unbilled], 'read').closing, null);
eq('no catering: the combined row «Total inkl. mva» over the two cards', T(mixedOrders, [], 'read').closing, {kind: 'combined', ore: 91374});
eq('no catering: = the Romleie total + the add-ons total', T(mixedOrders, [], 'read').closing?.ore, P.storedLinesInclOre(P.splitStoredLines(mixedOrders).rental) + P.storedLinesInclOre(P.splitStoredLines(mixedOrders).services));
eq('no catering, add-ons only (Romleie free): no row, the add-ons total is the sum', [T(app83833, [], 'read').closing, T(app83833, [], 'read').showServicesTotal], [null, true]);
eq('rental only, billed: no row (the Romleie total is that sum)', T(rentalOnly, [billed], 'read').closing, null);
eq('no stored lines: no row', T([], [billed], 'read').closing, null);

// F1+ (Opus FIX 40491): the catering could not be read
eq('catering unreadable, a room and add-ons: the registered row, and the add-ons total as it always was', T(mixedOrders, [], 'unreadable'), {showServicesTotal: true, closing: {kind: 'registered', ore: 91374}});
eq('catering unreadable, add-ons only: no row (the add-ons total is the sum)', T(servicesOnly, [], 'unreadable'), {showServicesTotal: true, closing: null});
eq('catering unreadable, 83833 (Romleie free): no row', T(app83833, [], 'unreadable').closing, null);
eq('catering unreadable, rental only: no row', T(rentalOnly, [], 'unreadable').closing, null);
eq('catering unreadable with an EARLIER read still in the query: the summary is not drawn, so the row is there',
    T(mixedOrders, [unbilled], 'unreadable').closing, {kind: 'registered', ore: 91374});
eq('catering unreadable with an earlier read of a billed order: row, services total withheld', T(mixedOrders, [billed], 'unreadable'), {showServicesTotal: false, closing: {kind: 'registered', ore: 91374}});
// while the orders are being read nothing is decided
eq('catering pending: no total of either kind', [mixedOrders, app83833, servicesOnly, rentalOnly].map(stored_ => T(stored_, [], 'pending')),
    [0, 1, 2, 3].map(() => ({showServicesTotal: false, closing: null})));

// «the old paths are unchanged»: the services total always, and the closing row everywhere except the two F1 paths
// (catering read with billing not ruled out, catering unreadable), against the logic of 76ae7a09f
const oldTotals = (storedOrders: any[], orders: any[], state: string) => {
    const {rental, services} = P.splitStoredLines(storedOrders);
    const showRental = rental.length > 0 && !P.storedLinesAreFree(rental);
    const showServices = services.length > 0;
    const cateringKnown = state === 'read';
    const billingSettled = cateringKnown || state === 'unreadable';
    const billedPossible = orders.some((order: any) => !S.isProvablyNotBilled(order.actions));
    const rentalOre = P.storedLinesInclOre(rental);
    const servicesOre = P.storedLinesInclOre(services);
    return {
        showServicesTotal: billingSettled && !billedPossible,
        combined: showRental && showServices && cateringKnown && orders.length === 0
            ? (rentalOre === null || servicesOre === null ? null : rentalOre + servicesOre)
            : undefined,
    };
};
const storedSets: Record<string, any[]> = {app83833, mixedOrders, rentalOnly, servicesOnly, untaxed, none: []};
let compared = 0;
let servicesTotalDiffers = 0;
let unchangedDiffers = 0;
let oldCombinedSeen = 0;
let newRows = 0;
for (const storedOrders of Object.values(storedSets)) {
    for (const state of ['pending', 'read', 'unreadable']) {
        for (const list of lists) {
            compared++;
            const now = T(storedOrders, list, state);
            const old = oldTotals(storedOrders, list, state);
            if (now.showServicesTotal !== old.showServicesTotal) servicesTotalDiffers++;
            const f1Path = state === 'unreadable' || (state === 'read' && S.cateringBillingNotRuledOut(list));
            if (!f1Path) {
                const want = old.combined === undefined ? null : {kind: 'combined', ore: old.combined};
                if (JSON.stringify(now.closing) !== JSON.stringify(want)) unchangedDiffers++;
                if (want) oldCombinedSeen++;
            } else if (now.closing !== null) {
                newRows++;
            }
        }
    }
}
eq(`the add-ons total is as in 76ae7a09f in all ${compared} cases (stored lines x catering states x order lists)`, servicesTotalDiffers, 0);
eq('the closing row is as in 76ae7a09f everywhere except the two F1 paths (combined, or none)', unchangedDiffers, 0);
eq('control: the old logic did show a combined row in some of those cases', oldCombinedSeen > 0, true);
eq('control: the F1 paths do show a row where 76ae7a09f showed none', newRows > 0, true);

// the price summary and the row are exact opposites when the catering was read and the application has add-ons: never both,
// never neither; and with the catering unreadable (nothing drawn) the row is there whenever it has a room and add-ons
const opposed = nonEmptyLists.flatMap(list => [app83833, mixedOrders, servicesOnly].map(stored_ => ({list, stored_})));
eq(`read: the price summary and the row are never both there or both missing, over ${opposed.length} cases`,
    opposed.filter(({list, stored_}) => S.showsPriceSummary(list, P.applicationPriceSummary(stored_, list).total.inclOre) === (T(stored_, list, 'read').closing !== null)).length, 0);
eq(`unreadable: a room and add-ons always have the row, over ${nonEmptyLists.length + 1} order lists`,
    [[], ...nonEmptyLists].filter(list => T(mixedOrders, list, 'unreadable').closing === null).length, 0);

// ---- D. design A (task 40576): one VAT breakdown on the page; the kitchen's contact once per kitchen
copy('kitchen-contacts.mts', readFileSync(join(utils, 'kitchen-contacts.ts'), 'utf8'));
const K: any = await import(pathToFileURL(join(dir, 'kitchen-contacts.mts')).href);

eq('ending: no order = nothing', [S.cateringEnding(0, 5000, false), S.cateringEnding(0, null, true)], ['none', 'none']);
eq('ending: a catering of 0 kr = nothing, whether or not the summary is drawn', [S.cateringEnding(1, 0, true), S.cateringEnding(2, 0, false)], ['none', 'none']);
eq('ending: the summary drawn = ONE row', [S.cateringEnding(1, 5000, true), S.cateringEnding(3, null, true)], ['total', 'total']);
eq('ending: the summary not drawn = the catering\'s own breakdown', [S.cateringEnding(1, 5000, false), S.cateringEnding(2, null, false)], ['breakdown', 'breakdown']);
eq('ending: an UNKNOWN catering total (null, «-») is not 0 kr', S.cateringEnding(1, null, false), 'breakdown');

// catering orders WITH lines (stored ex VAT + the line's VAT %, as the API sends them)
const pricedOrder = (actions: any, lines: Array<[string, number | null]>) => ({actions, lines: lines.map(([amount, tax_percent]) => ({amount, tax_percent}))});
const UNBILLED = unbilled.actions;
const BILLED = billed.actions;
const cateringLists: Record<string, any[]> = {
    none: [],
    unbilled: [pricedOrder(UNBILLED, [['50.00', 25]])],
    billed: [pricedOrder(BILLED, [['25.00', 25]])],
    absentActions: [pricedOrder(undefined, [['25.00', 25]])],
    emptyOrder: [pricedOrder(UNBILLED, [])],
    zeroLine: [pricedOrder(UNBILLED, [['0.00', 25]])],
    unknownVat: [pricedOrder(UNBILLED, [['40.00', null]])],
    unbilledAndBilled: [pricedOrder(UNBILLED, [['50.00', 25]]), pricedOrder(BILLED, [['25.00', 25]])],
    mixedRates: [pricedOrder(UNBILLED, [['50.00', 25], ['20.00', 0]]), pricedOrder(UNBILLED, [['10.00', 15]])],
};
// What the page breaks the VAT down in: the price summary (drawn by catering-overview when showsPriceSummary) and the
// catering section's own ending. Both exist only when the catering was READ (catering-overview renders nothing while
// its orders are pending or when they cannot be read).
const breakdowns = (storedOrders: any[], orders: any[], state: string, ending: (n: number, ore: number | null, drawn: boolean) => string) => {
    if (state !== 'read' || orders.length === 0) return 0;
    const prices = P.applicationPriceSummary(storedOrders, orders);
    const drawn = S.showsPriceSummary(orders, prices.total.inclOre);
    return (drawn ? 1 : 0) + (ending(orders.length, prices.catering.inclOre, drawn) === 'breakdown' ? 1 : 0);
};
// the page before task 40576: the catering's breakdown whenever it was priced, AND the summary after it
const oldEnding = (n: number, ore: number | null) => (n > 0 && (ore === null || ore > 0) ? 'breakdown' : 'none');
let vatCases = 0, moreThanOne = 0, wrongCount = 0, notOldOrOne = 0, oldTwo = 0, oneSeen = 0, zeroSeen = 0;
for (const [storedName, storedOrders] of Object.entries(storedSets)) {
    for (const state of ['pending', 'read', 'unreadable']) {
        for (const [listName, orders] of Object.entries(cateringLists)) {
            vatCases++;
            const now = breakdowns(storedOrders, orders, state, S.cateringEnding);
            const old = breakdowns(storedOrders, orders, state, oldEnding);
            const prices = P.applicationPriceSummary(storedOrders, orders);
            const drawn = S.showsPriceSummary(orders, prices.total.inclOre);
            const expected = state === 'read' && orders.length > 0 && (drawn || prices.catering.inclOre !== 0) ? 1 : 0;
            if (now > 1) { moreThanOne++; console.log(`   two breakdowns: ${storedName} / ${state} / ${listName}`); }
            if (now !== expected) { wrongCount++; console.log(`   ${now} breakdown(s), expected ${expected}: ${storedName} / ${state} / ${listName}`); }
            if (now !== Math.min(old, 1)) notOldOrOne++;
            if (old === 2) oldTwo++;
            if (now === 1) oneSeen++; else zeroSeen++;
        }
    }
}
eq(`VAT: never two breakdowns on the page, over ${vatCases} cases (stored sets x catering states x catering lists)`, moreThanOne, 0);
eq('VAT: exactly one when the catering was read and is priced (or the summary is drawn), none otherwise', wrongCount, 0);
eq('VAT: the old page\'s count wherever it was not two, one where it was two', notOldOrOne, 0);
eq('control: the old page DID show two breakdowns in some cases, and both counts occur now', [oldTwo > 0, oneSeen > 0, zeroSeen > 0], [true, true, true]);
// the named states of the GO (40632)
const named = (storedOrders: any[], list: string, state: string) => breakdowns(storedOrders, cateringLists[list], state, S.cateringEnding);
eq('VAT, named: the summary drawn (84024-like) / billed F1 (83833) / query error / no catering / rental only, unbilled catering',
    [named(servicesOnly, 'unbilled', 'read'), named(app83833, 'billed', 'read'), named(mixedOrders, 'unbilled', 'unreadable'), named(mixedOrders, 'none', 'read'), named(rentalOnly, 'unbilled', 'read')],
    [1, 1, 0, 0, 1]);
eq('VAT, named: where it is — the summary (no section breakdown) when drawn, the section when billed',
    [S.cateringEnding(1, P.applicationPriceSummary(servicesOnly, cateringLists.unbilled).catering.inclOre, true), S.cateringEnding(1, P.applicationPriceSummary(app83833, cateringLists.billed).catering.inclOre, false)],
    ['total', 'breakdown']);

const contact = (info: string | null, building_email: string | null = 'kjokken@example.invalid', building_name: string | null = 'Fana kulturhus', building_phone: string | null = '55 00 00 00') =>
    ({info, building_name, building_email, building_phone});
eq('contacts: three servings of one kitchen = ONE contact', K.uniqueKitchenContacts([contact('<p>Ring oss</p>'), contact('<p>Ring oss</p>'), contact('<p>Ring oss</p>')]).length, 1);
eq('contacts: the same text but another email = two kitchens', K.uniqueKitchenContacts([contact('<p>Ring oss</p>'), contact('<p>Ring oss</p>', 'annet@example.invalid')]).length, 2);
eq('contacts: no text, another building = two', K.uniqueKitchenContacts([contact(null), contact(null, 'kjokken@example.invalid', 'Kulturhuset')]).length, 2);
eq('contacts: another phone = two', K.uniqueKitchenContacts([contact(null), contact(null, 'kjokken@example.invalid', 'Fana kulturhus', '55 11 11 11')]).length, 2);
eq('contacts: whitespace at the ends is the same contact', K.uniqueKitchenContacts([contact('<p>Ring oss</p>'), contact('  <p>Ring oss</p>\n')]).length, 1);
eq('contacts: a missing contact is skipped', K.uniqueKitchenContacts([undefined, null, contact('x'), undefined]).map((c: any) => (c ? c.info : 'MISSING')), ['x']);
eq('contacts: in the order they first appear', K.uniqueKitchenContacts([contact('b'), contact('a'), contact('b')]).map((c: any) => c.info), ['b', 'a']);
eq('contacts: none = none', K.uniqueKitchenContacts([]), []);

// the components use the shared functions and draw what they return (read as text: the mutants edit these copies)
const componentDir = join(root, 'src/modules/bookingfrontend/client/src/components/user/application');
const overview = readFileSync(join(componentDir, 'catering-overview.tsx'), 'utf8');
const priceLines = readFileSync(join(componentDir, 'application-price-lines.tsx'), 'utf8');
const header = readFileSync(join(componentDir, 'application-header.tsx'), 'utf8');
eq('catering-overview: the summary is drawn by showsPriceSummary', overview.includes('const showApplicationSummary = showsPriceSummary(orders, prices.total.inclOre);'), true);
eq('catering-overview: no copy of the billing rule left', overview.includes('isProvablyNotBilled') || overview.includes('cateringBillingNotRuledOut'), false);
eq('price lines: every decision comes from applicationTotals',
    priceLines.includes('applicationTotals(application.orders ?? [], cateringOrders, catering)')
    && !priceLines.includes('isProvablyNotBilled') && !priceLines.includes('cateringBillingNotRuledOut') && !priceLines.includes('showsPriceSummary'), true);
eq('price lines: the catering state is read / unreadable (the query failed) / pending',
    priceLines.includes("hospitalityOn === false || ordersQuery.isSuccess ? 'read' : ordersQuery.isError ? 'unreadable' : 'pending'"), true);
eq('price lines: the row is drawn under the label of its kind, with the services total from applicationTotals',
    priceLines.includes('{closing && (')
    && priceLines.includes("closing.kind === 'combined' ? 'bookingfrontend.total_incl_vat' : 'bookingfrontend.application_registered_total'")
    && priceLines.includes('application-price-lines-${closing.kind}')
    && priceLines.includes('totalOre={showServicesTotal ? servicesOre : undefined}'), true);
const details = readFileSync(join(componentDir, 'application-details.tsx'), 'utf8');
eq('catering-overview: the section ends as cateringEnding says, from the summary\'s own rule',
    overview.includes('const ending = cateringEnding(orders.length, prices.catering.inclOre, showApplicationSummary);')
    && overview.includes("{ending === 'total' && (") && overview.includes("{ending === 'breakdown' && (")
    && (overview.match(/summary=\{prices\.catering\}/g) ?? []).length === 1 && !overview.includes('cateringPaid'), true);
eq('catering-overview: the kitchen\'s contact is drawn in ONE place, from uniqueKitchenContacts over the locked or closed servings',
    overview.includes('const contacts = uniqueKitchenContacts(rows.filter(row => readOnly || servingState(row.order).locked).map(row => row.order.contact));')
    && (overview.match(/<KitchenContact /g) ?? []).length === 1 && overview.includes('{contacts.map((contact, index) => <KitchenContact key={index} contact={contact}/>)}'), true);
eq('catering-overview: an order without lines has no «Ordretotal»', overview.includes('{order.lines.length > 0 && (\n                                    <Paragraph data-size="sm" className={cateringStyles.orderTotal}>'), true);
const surfaceAt = (text: string) => details.indexOf(text);
eq('application-details: ONE price surface, in the order Romleie/Utstyr → Bevertning → the total, before «Tider og lokale»',
    surfaceAt('<div className={`${styles.card} ${styles.priceSurface}`} data-testid="application-prices">') > 0
    && surfaceAt('<div className={`${styles.card} ${styles.priceSurface}`} data-testid="application-prices">') < surfaceAt('<ApplicationPriceLines application={application}/>')
    && surfaceAt('<ApplicationPriceLines application={application}/>') < surfaceAt('<CateringOverview application={application}/>')
    && surfaceAt('<CateringOverview application={application}/>') < surfaceAt('<ApplicationPriceLinesTotal application={application}/>')
    && surfaceAt('<ApplicationPriceLinesTotal application={application}/>') < surfaceAt('{/* Tider og lokale */}'), true);
eq('header: «+N datoer» renders applicationWhen().moreDates, not a count of its own',
    header.includes('{when && when.moreDates > 0 &&') && header.includes('+{when.moreDates} {dateWord(when.moreDates)}') && !header.includes('dates.length - 1'), true);

console.log(`${checks - failures}/${checks} pass`);
process.exit(failures === 0 ? 0 : 1);
