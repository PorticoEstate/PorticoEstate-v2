/**
 * Hermetic check of the checkout's total with the catering in it (GH #1478, operator D1; task 50144).
 *
 *   node tests/checkout-total/run.mts
 *
 * Loads TEMP COPIES of the Next client's utils (checkout-total.ts and what it imports: article-pricing.ts, cost-utils.ts,
 * catering-status.ts, catering-venue-time.ts, hospitality-deadline.ts) with their import lines pointed at the copies, so
 * luxon and the path alias are not needed (Node >= 22.18 strips the types itself). CT_TS (optional) points at an
 * alternative copy of checkout-total.ts and CART_TSX one of cart-section.tsx: mutate.py uses them to prove that a
 * deliberately broken total, or a cart that stops listing the recurring applications' catering, turns this red.
 *
 * The oracle is exact BigInt arithmetic, no floats and none of the helpers under test:
 *   - a stored application line (room rental, equipment / services) is amount + tax in øre, as the server sums
 *     order.sum (ApplicationRepository::fetchOrders: sum += amount + tax);
 *   - a catering line is net = quantity × unit price in øre, VAT = net × ITS OWN percent / 100 rounded half up ONCE
 *     per line (the billing job, create_purchase_orders_from_hospitality; HospitalityOrderAmount);
 *   - the total = Σ stored × recurring instances + Σ catering lines of the orders that are not cancelled.
 *
 * Cases (the brief's): room only · room + extras · room + catering on two days at VAT 0 / 15 / 25 with an overridden
 * article · a cancelled order left out; and, beyond the brief: a recurring application (stored lines × instances,
 * catering once), scope (a section adds only ITS applications' catering), an unknown VAT % (total unknown, never a
 * guess), and the invariant «the total is the sum of the lines the checkout lists».
 *
 * What it can NOT do is render the components: cart-section.tsx and hospitality-section.tsx are only checked as
 * source text (section 9), and the live run is what shows the page.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const client = join(root, 'src/modules/bookingfrontend/client/src');
const utils = join(client, 'utils');

// ---- loader --------------------------------------------------------------------------------------------------------

const dir = mkdtempSync(join(tmpdir(), 'checkout-total-'));
const dropImports = (text: string) => text.split('\n').filter(line => !line.startsWith('import ')).join('\n');
const read = (path: string) => readFileSync(path, 'utf8');
writeFileSync(join(dir, 'cost-utils.mts'), dropImports(read(join(utils, 'cost-utils.ts'))));
writeFileSync(join(dir, 'catering-status.mts'), dropImports(read(join(utils, 'catering-status.ts'))));
writeFileSync(
    join(dir, 'article-pricing.mts'),
    `const DateTime: any = {};\n`
    + `import {formatAmount, formatCurrency} from './cost-utils.mts';\n`
    + `import {cateringBillingNotRuledOut, showsPriceSummary} from './catering-status.mts';\n`
    + dropImports(read(join(utils, 'article-pricing.ts')))
);
writeFileSync(join(dir, 'hospitality-deadline.mts'), read(join(utils, 'hospitality-deadline.ts')));
writeFileSync(
    join(dir, 'catering-venue-time.mts'),
    `import {localeFor, VENUE_TIMEZONE} from './hospitality-deadline.mts';\n` + dropImports(read(join(utils, 'catering-venue-time.ts')))
);
writeFileSync(
    join(dir, 'checkout-total.mts'),
    `import {hospitalityLineTotalOre, hospitalityUnitPriceIncl, sumOreOrNull} from './article-pricing.mts';\n`
    + `import {groupByServingDay} from './catering-venue-time.mts';\n`
    + dropImports(read(process.env.CT_TS ?? join(utils, 'checkout-total.ts')))
);
const C: any = await import(pathToFileURL(join(dir, 'checkout-total.mts')).href);

// ---- harness -------------------------------------------------------------------------------------------------------

let fail = 0;
let n = 0;
const check = (name: string, ok: boolean, detail: unknown = '') => {
    n++;
    if (!ok) {
        fail++;
        console.log(`FAIL ${name} ${typeof detail === 'string' ? detail : JSON.stringify(detail)}`);
    }
};
const eq = (name: string, got: unknown, want: unknown) =>
    check(name, JSON.stringify(got) === JSON.stringify(want), {got, want});

// ---- oracle (BigInt, no helper under test) --------------------------------------------------------------------------

/** '136.99' → 13699n, exactly, from the decimal text. */
const ore = (kroner: string): bigint => {
    const [whole, frac = ''] = kroner.split('.');
    return BigInt(whole) * 100n + BigInt((frac + '00').slice(0, 2));
};
const oracleCateringLine = (quantity: string, unitPrice: string, percent: number): bigint => {
    const net = BigInt(quantity) * ore(unitPrice);
    const vat = (net * BigInt(percent) + 50n) / 100n; // half up; every amount here is >= 0
    return net + vat;
};
const kr = (o: bigint): string => `${o / 100n}.${String(o % 100n).padStart(2, '0')}`;

// ---- fixtures --------------------------------------------------------------------------------------------------------

/** A stored application line, as GET partials sends it: amount and tax are numbers (the server's floats). */
interface StoredLine {amount: string; tax: string}
const application = (id: number, lines: StoredLine[]) => {
    const sum = lines.reduce((s, line) => s + ore(line.amount) + ore(line.tax), 0n);
    return {
        id,
        // The server adds amount + tax as PHP floats and sends the float; reproduce that, do not hand it the exact value.
        orders: [{order_id: 900 + id, sum: lines.reduce((s, line) => s + Number(line.amount) + Number(line.tax), 0), lines: []}],
        oracleOre: sum,
    };
};

let lineId = 1;
interface CateringLineSpec {qty: string; unit: string; percent: number | null; name: string}
const cateringOrder = (id: number, applicationId: number, servingIso: string | null, status: string, specs: CateringLineSpec[]) => ({
    id,
    application_id: applicationId,
    status,
    serving_time_iso: servingIso,
    hospitality_name: 'Kantina',
    location_name: 'Sal 1',
    lines: specs.map(spec => ({
        id: lineId++,
        order_id: id,
        hospitality_article_id: 1,
        quantity: `${spec.qty}.00`,
        unit_price: spec.unit,
        tax_code: spec.percent === null ? 99 : spec.percent,
        tax_percent: spec.percent,
        // What the line stores: quantity × unit price, net, numeric(10,2)
        amount: kr(BigInt(spec.qty) * ore(spec.unit)),
        comment: null,
        article_name: spec.name,
        unit: 'each',
    })),
    oracleOre: status === 'cancelled' || specs.some(spec => spec.percent === null)
        ? 0n
        : specs.reduce((s, spec) => s + oracleCateringLine(spec.qty, spec.unit, spec.percent as number), 0n),
});

// Room only: 1 000 kr + 25 %.
const roomOnly = application(1, [{amount: '1000.00', tax: '250.00'}]);
// Room + extras: the room, a projector at 25 %, a cleaning fee at 0 %, a ticket service at 15 % (amounts as stored).
const roomExtras = application(2, [
    {amount: '850.00', tax: '212.50'},
    {amount: '136.99', tax: '34.25'},
    {amount: '300.00', tax: '0.00'},
    {amount: '45.10', tax: '6.77'},
]);
// Room + catering on two days. Inputs in reverse order on purpose: the list must still be earliest first.
const roomCatering = application(3, [{amount: '1200.00', tax: '300.00'}]);
const DAY2_EVENING = '2026-12-04T18:00:00+01:00';
const DAY1_LATE = '2026-12-03T14:30:00+01:00';
const DAY1_NOON = '2026-12-03T12:00:00+01:00';
const orderDay2 = cateringOrder(31, 3, DAY2_EVENING, 'pending', [
    // 3 × 136.99 at 25 %: net 410.97, VAT 102.7425 → 102.74, incl 513.71 (and 3 × the rounded 171.24 would be 513.72)
    {qty: '3', unit: '136.99', percent: 25, name: 'Vin'},
    // 0 %: nothing added
    {qty: '1', unit: '50.00', percent: 0, name: 'Dekketøy'},
]);
const orderDay1Late = cateringOrder(32, 3, DAY1_LATE, 'pending', [
    // 15 %: 12 × 23.20 = 278.40, VAT 41.76
    {qty: '12', unit: '23.20', percent: 15, name: 'Kaffe'},
]);
const orderDay1Noon = cateringOrder(33, 3, DAY1_NOON, 'confirmed', [
    // An OVERRIDDEN article: the menu's own price is 80.00 at 25 %, the offer overrides it to 64.50 at 15 %. The line
    // stores what was ordered (64.50, the 15 % code), and that is what is counted: 7 × 64.50 = 451.50, VAT 67.725 → 67.73
    {qty: '7', unit: '64.50', percent: 15, name: 'Lunsj (overstyrt)'},
    {qty: '7', unit: '19.99', percent: 25, name: 'Mineralvann'},
]);
// A cancelled order on day 1: neither counted nor listed.
const orderCancelled = cateringOrder(34, 3, '2026-12-03T09:00:00+01:00', 'cancelled', [
    {qty: '20', unit: '99.00', percent: 15, name: 'Frokost (avbestilt)'},
]);
const cateringApp3 = [orderDay2, orderDay1Late, orderCancelled, orderDay1Noon];

const one = () => 1;

// ---- 1. room only ------------------------------------------------------------------------------------------------------
eq('room only', C.cartTotalOre([roomOnly], [], one), Number(roomOnly.oracleOre));
eq('room only: stored ore', C.applicationStoredOre(roomOnly), 125000);

// ---- 2. room + extras --------------------------------------------------------------------------------------------------
eq('room + extras', C.cartTotalOre([roomExtras], [], one), Number(roomExtras.oracleOre));

// ---- 3. room + catering on two days, VAT 0 / 15 / 25, an overridden article ------------------------------------------------
const cateringOracle = cateringApp3.reduce((s, order) => s + order.oracleOre, 0n);
const totalApp3 = C.cartTotalOre([roomCatering], cateringApp3, one);
eq('room + catering: total', totalApp3, Number(roomCatering.oracleOre + cateringOracle));
eq('room + catering: catering alone', C.cateringTotalOre(cateringApp3), Number(cateringOracle));
// Each line at its own rate, against the oracle
for (const order of cateringApp3.filter(o => o.status !== 'cancelled')) {
    for (const line of order.lines) {
        const qty = line.quantity.split('.')[0];
        eq(`line ${line.article_name} at ${line.tax_percent} %`, C.cateringLineOre(line), Number(oracleCateringLine(qty, line.unit_price, line.tax_percent as number)));
    }
}
// Gross, not net: the catering is more than its net, by exactly the VAT of the 15 % and 25 % lines
const netOre = cateringApp3.filter(o => o.status !== 'cancelled')
    .flatMap(o => o.lines).reduce((s, line) => s + ore(line.amount), 0n);
check('catering is gross, not net', C.cateringTotalOre(cateringApp3) > Number(netOre), {gross: C.cateringTotalOre(cateringApp3), net: Number(netOre)});
// Not one fixed rate: the same lines all at 25 % would be a different number
const all25 = cateringApp3.filter(o => o.status !== 'cancelled').flatMap(o => o.lines)
    .reduce((s, line) => s + oracleCateringLine(line.quantity.split('.')[0], line.unit_price, 25), 0n);
check('catering is not a constant 25 %', C.cateringTotalOre(cateringApp3) !== Number(all25), {got: C.cateringTotalOre(cateringApp3), at25: Number(all25)});
// The overridden article is counted at the price and rate the line stores, not the menu's 80.00 at 25 %
eq('overridden article counted as stored', C.cateringLineOre(orderDay1Noon.lines[0]), 51923);

// ---- 4. a cancelled order is left out -------------------------------------------------------------------------------------
const withoutCancelled = cateringApp3.filter(o => o.status !== 'cancelled');
eq('cancelled order not counted', C.cartTotalOre([roomCatering], cateringApp3, one), C.cartTotalOre([roomCatering], withoutCancelled, one));
check('cancelled order is non-trivial (control)', orderCancelled.lines.length > 0 && Number(ore(orderCancelled.lines[0].amount)) > 0);
eq('cancelled only = 0 kr catering', C.cateringTotalOre([orderCancelled]), 0);

// ---- 5. listed per day, earliest first; the total is the sum of what is listed ----------------------------------------------
const NOW = Date.parse('2026-11-25T09:00:00+01:00');
const days = C.checkoutCateringDays(cateringApp3, 'no', NOW);
eq('days ascending', days.map((d: any) => d.dayKey), ['2026-12-03', '2026-12-04']);
eq('day headings', days.map((d: any) => d.heading), ['Torsdag 3. desember', 'Fredag 4. desember']);
eq('day 1 by serving time, cancelled not listed', days[0].orders.map((o: any) => [o.order.id, o.time]), [[33, '12:00'], [32, '14:30']]);
eq('day 2', days[1].orders.map((o: any) => [o.order.id, o.time]), [[31, '18:00']]);
const listedLineOre = days.flatMap((d: any) => d.orders.flatMap((o: any) => o.lines.map((l: any) => l.ore)));
const listedSum = listedLineOre.reduce((s: number, v: number) => s + v, 0);
eq('total = stored + Σ listed lines', totalApp3, C.applicationStoredOre(roomCatering) + listedSum);
eq('listed line count', listedLineOre.length, 5);
// Each order's own sum is the sum of its listed lines
for (const day of days) {
    for (const o of day.orders) {
        eq(`order ${o.order.id} sum = Σ its lines`, o.ore, o.lines.reduce((s: number, l: any) => s + l.ore, 0));
    }
}
// «N × unit price»: the unit price incl. VAT is rounded for showing, and the amount is NOT N × it
const wine = days[1].orders[0].lines[0];
eq('unit price incl. VAT shown (136.99 at 25 %)', wine.unitIncl, 171.24);
eq('line amount as billed, not 3 × 171.24', wine.ore, 51371);
// Orders without a serving time come last, under no day
const undated = cateringOrder(35, 3, null, 'pending', [{qty: '1', unit: '10.00', percent: 15, name: 'Kake'}]);
const daysWithUndated = C.checkoutCateringDays([undated, ...cateringApp3], 'no', NOW);
eq('undated last', daysWithUndated.map((d: any) => d.dayKey), ['2026-12-03', '2026-12-04', null]);
// Venue time: 23:30 UTC on the 3rd is 00:30 on the 4th in Oslo
const lateUtc = cateringOrder(36, 3, '2026-12-03T23:30:00+00:00', 'pending', [{qty: '1', unit: '10.00', percent: 15, name: 'Nattmat'}]);
eq('venue day, not UTC day', C.checkoutCateringDays([lateUtc], 'no', NOW).map((d: any) => [d.dayKey, d.orders[0].time]), [['2026-12-04', '00:30']]);

// ---- 6. scope: a section adds only ITS applications' catering ------------------------------------------------------------
eq('section of app 1 ignores app 3 catering', C.cartTotalOre([roomOnly], cateringApp3, one), Number(roomOnly.oracleOre));
eq('grand total of all three', C.cartTotalOre([roomOnly, roomExtras, roomCatering], cateringApp3, one),
    Number(roomOnly.oracleOre + roomExtras.oracleOre + roomCatering.oracleOre + cateringOracle));

// ---- 7. recurring (declaration ⑥, pe-pm 50167): a recurring application with catering orders → its catering is
//         LISTED (the recurring section) and counted ONCE; only its stored lines go × instances --------------------------
const recurring = application(4, [{amount: '400.00', tax: '100.00'}]);
const recurringOrder = cateringOrder(41, 4, '2026-12-10T12:00:00+01:00', 'pending', [{qty: '2', unit: '100.00', percent: 15, name: 'Lunsj'}]);
const recurringOrder2 = cateringOrder(42, 4, '2026-12-17T12:00:00+01:00', 'pending', [{qty: '1', unit: '40.59', percent: 0, name: 'Farris'}]);
const recurringCatering = [recurringOrder, recurringOrder2];
const recurringTotal = C.cartTotalOre([recurring], recurringCatering, () => 3);
eq('⑥ recurring: stored × 3 + catering once', recurringTotal,
    Number(recurring.oracleOre * 3n + recurringOrder.oracleOre + recurringOrder2.oracleOre));
const recurringDays = C.checkoutCateringDays(recurringCatering, 'no', NOW);
eq('⑥ recurring: its catering is listed, per day', recurringDays.map((d: any) => [d.dayKey, d.orders.map((o: any) => o.order.id)]),
    [['2026-12-10', [41]], ['2026-12-17', [42]]]);
const recurringListed = recurringDays.flatMap((d: any) => d.orders.flatMap((o: any) => o.lines.map((l: any) => l.ore)))
    .reduce((s: number, v: number) => s + v, 0);
eq('⑥ recurring: total = stored × instances + Σ listed catering lines', recurringTotal, C.applicationStoredOre(recurring) * 3 + recurringListed);
check('⑥ recurring: the catering is NOT multiplied (control: × 3 is a different number)',
    recurringTotal !== (C.applicationStoredOre(recurring) + recurringListed) * 3, {recurringTotal});
check('⑥ recurring: the cart renders the recurring section\'s catering without «Legg til»',
    /applicationIds=\{recurringApplications\.map\(a => a\.id\)\}[\s\S]{0,120}allowAdd=\{false\}/.test(read(process.env.CART_TSX ?? join(client, 'components/checkout/cart-section.tsx'))));

// ---- 8. an unknown VAT % makes the total unknown, never a guess -----------------------------------------------------------
const unknownVat = cateringOrder(51, 3, DAY1_NOON, 'pending', [{qty: '1', unit: '100.00', percent: null, name: 'Ukjent mva'}]);
eq('unknown VAT → total unknown', C.cartTotalOre([roomCatering], [...cateringApp3, unknownVat], one), null);
eq('unknown VAT on a CANCELLED order does not matter', C.cartTotalOre([roomCatering], [...cateringApp3, {...unknownVat, status: 'cancelled'}], one), totalApp3);
eq('no catering at all = stored', C.cartTotalOre([roomCatering], [], one), Number(roomCatering.oracleOre));

// ---- 9. source checks (text only: the components are not rendered here) ---------------------------------------------------
const cart = read(process.env.CART_TSX ?? join(client, 'components/checkout/cart-section.tsx'));
const section = read(join(client, 'components/checkout/hospitality/hospitality-section.tsx'));
check('cart-section totals go through cartTotalOre', /cartTotalOre\(apps, enableHospitality \? catering\.orders : \[\], instancesOf\)/.test(cart));
check('cart-section no longer sums order.sum by itself', !/calculateApplicationCost/.test(cart));
check('cart-section shows no total while the catering is pending', /!cateringPending &&/.test(cart));
check('the recurring section lists catering, without «Legg til»', /allowAdd=\{false\}/.test(cart));
check('hospitality-section lists checkoutCateringDays', /checkoutCateringDays\(orders, lang/.test(section) && /days\.map\(day =>/.test(section));
check('hospitality-section has no fixed VAT rate', !/\b25\b/.test(section) && !/\b25\b/.test(read(process.env.CT_TS ?? join(utils, 'checkout-total.ts'))));
for (const lang of ['no', 'nn', 'en']) {
    const file = read(join(root, `src/modules/bookingfrontend/setup/phpgw_${lang}.lang`));
    check(`lang key catering_checkout_serving_time (${lang}) has %1`, new RegExp(`^catering_checkout_serving_time\\tbookingfrontend\\t${lang}\\t.*%1`, 'm').test(file));
}

console.log(`${fail === 0 ? 'PASS' : 'FAIL'} ${n - fail}/${n}`);
process.exit(fail === 0 ? 0 : 1);
