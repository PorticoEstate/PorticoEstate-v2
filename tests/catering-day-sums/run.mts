/**
 * Hermetic check of the per-day catering sums in the checkout (GH #1478, unit C2).
 *
 *   node tests/catering-day-sums/run.mts
 *
 * Loads TEMP COPIES of catering-day-sum.ts and what it and checkout-total.ts import, with their import lines pointed at
 * the copies (no luxon, no path alias: tests/checkout-total/run.mts does the same). CDS_TS, CTOTAL_TS and SECTION_TSX
 * (optional) point at alternative copies of catering-day-sum.ts, checkout-total.ts and the component's source: mutate.py
 * uses them to prove that a mutant turns this red.
 *
 * The oracle is exact BigInt arithmetic, with none of the helpers under test:
 *   - a catering line is net = quantity × unit price in øre, and its VAT = net × ITS OWN percent / 100 rounded half up
 *     ONCE per line (the billing job's rule, as tests/checkout-total/run.mts states it);
 *   - a day's sum = Σ its orders' lines, the cancelled orders left out;
 *   - which day an order belongs to is written into the fixture (expectedDay), so the grouping is checked, not assumed.
 *
 * Checks: the days; each day's sum against the oracle and against figures worked out by hand; the day sums foot to the
 * catering total to the øre; an unknown VAT % makes its day, and the total, unknown (never zero); the day label; the
 * offers line; the component's wiring and the lang keys (text only: the component is not rendered here, the live run
 * shows the page).
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

const dir = mkdtempSync(join(tmpdir(), 'catering-day-sums-'));
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
    + dropImports(read(process.env.CTOTAL_TS ?? join(utils, 'checkout-total.ts')))
);
writeFileSync(
    join(dir, 'catering-day-sum.mts'),
    `import {sumOreOrNull} from './article-pricing.mts';\n`
    + `import {localeFor} from './hospitality-deadline.mts';\n`
    + dropImports(read(process.env.CDS_TS ?? join(utils, 'catering-day-sum.ts')))
);
const C: any = await import(pathToFileURL(join(dir, 'checkout-total.mts')).href);
const D: any = await import(pathToFileURL(join(dir, 'catering-day-sum.mts')).href);

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
const oracleLine = (quantity: string, unitPrice: string, percent: number): bigint => {
    const net = BigInt(quantity) * ore(unitPrice);
    const vat = (net * BigInt(percent) + 50n) / 100n; // half up; every amount here is >= 0
    return net + vat;
};
const kr = (o: bigint): string => `${o / 100n}.${String(o % 100n).padStart(2, '0')}`;

// ---- fixtures --------------------------------------------------------------------------------------------------------

interface Spec {qty: string; unit: string; percent: number | null; name: string}
let lineId = 1;
/**
 * One catering order as GET hospitality-orders sends it. `expectedDay` is the venue day the fixture says it belongs to
 * (null: no serving time); `oracleOre` is its amount incl. VAT by the oracle (0 when cancelled, null when a VAT % is unknown).
 */
const order = (id: number, servingIso: string | null, expectedDay: string | null, status: string, specs: Spec[]) => ({
    id,
    expectedDay,
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
    oracleOre: status === 'cancelled' ? 0n
        : specs.some(spec => spec.percent === null) ? null
        : specs.reduce((s, spec) => s + oracleLine(spec.qty, spec.unit, spec.percent as number), 0n),
});

const NOW = Date.parse('2026-11-25T10:00:00+01:00');
// Day 3 Dec: a 15 % line; two orders at 15 % and 25 %, the second at 12:00 so it is listed first.
const A1 = order(41, '2026-12-03T14:30:00+01:00', '2026-12-03', 'pending', [{qty: '12', unit: '23.20', percent: 15, name: 'Kaffe'}]);
const A2 = order(42, '2026-12-03T12:00:00+01:00', '2026-12-03', 'confirmed', [
    {qty: '7', unit: '64.50', percent: 15, name: 'Lunsj (overstyrt)'},
    {qty: '7', unit: '19.99', percent: 25, name: 'Mineralvann'},
]);
// Day 4 Dec: 00:30 Oslo is 23:30 UTC on 3 Dec, so a UTC split would put it on the day before.
const B0 = order(43, '2026-12-04T00:30:00+01:00', '2026-12-04', 'pending', [{qty: '1', unit: '10.00', percent: 25, name: 'Natteserveringen'}]);
const B1 = order(44, '2026-12-04T18:00:00+01:00', '2026-12-04', 'pending', [
    {qty: '3', unit: '136.99', percent: 25, name: 'Vin'},
    {qty: '1', unit: '50.00', percent: 0, name: 'Dekketøy'},
]);
// No serving time: listed last, in a group of its own.
const U = order(45, null, null, 'pending', [{qty: '2', unit: '45.00', percent: 25, name: 'Kake'}]);
// Cancelled: neither listed nor counted.
const CANCELLED = order(46, '2026-12-03T09:00:00+01:00', '2026-12-03', 'cancelled', [
    {qty: '20', unit: '99.00', percent: 15, name: 'Frokost (avbestilt)'},
]);
const scenario = [A1, A2, B0, B1, U, CANCELLED];
// A line whose VAT % is unknown on its own day.
const UNKNOWN = order(47, '2026-12-05T10:00:00+01:00', '2026-12-05', 'pending', [{qty: '1', unit: '100.00', percent: null, name: 'Ukjent mva'}]);

const sortedIds = (ids: number[]) => [...ids].sort((a, b) => a - b);
const oracleDay = (fixture: any[], expectedDay: string | null): bigint =>
    fixture.filter(o => o.expectedDay === expectedDay && o.status !== 'cancelled')
        .reduce((s, o) => s + (o.oracleOre as bigint), 0n);

// ---- 1. the days: the venue days, earliest first, the undated last, each with its own orders -------------------------

const days = C.checkoutCateringDays(scenario, 'no', NOW);
eq('days: the venue days, earliest first, the undated last', days.map((day: any) => day.dayKey), ['2026-12-03', '2026-12-04', null]);
for (const day of days) {
    const idsOf = sortedIds(day.orders.map((item: any) => item.order.id));
    const wanted = sortedIds(scenario.filter(o => o.expectedDay === day.dayKey && o.status !== 'cancelled').map(o => o.id));
    eq(`day ${day.dayKey}: its orders, the cancelled one left out`, idsOf, wanted);
}

// ---- 2. each day's sum: against the oracle, and against figures worked out by hand (øre, incl. VAT) -------------------

for (const day of days) {
    eq(`day ${day.dayKey}: sum = oracle`, D.cateringDaySumOre(day), Number(oracleDay(scenario, day.dayKey)));
}
// By hand, rule: net × own % / 100, half up, once per line. 3 Dec: 32 016 + (51 923 + 17 491). 4 Dec: 1 250 + (51 371 + 5 000).
eq('3 Dec = 101 430 øre, by hand', D.cateringDaySumOre(days[0]), 101430);
eq('4 Dec = 57 621 øre, by hand', D.cateringDaySumOre(days[1]), 57621);
eq('no serving time = 11 250 øre, by hand', D.cateringDaySumOre(days[2]), 11250);

// ---- 3. the day sums foot to the catering total, to the øre ------------------------------------------------------------

const daySums: Array<number | null> = days.map((day: any) => D.cateringDaySumOre(day));
check('no day sum is unknown in the scenario', daySums.every(sum => sum !== null), daySums);
const footed = daySums.reduce<number>((s, sum) => s + (sum ?? 0), 0);
eq('the day sums foot to the catering total (øre)', footed, C.cateringTotalOre(scenario));
const oracleTotal = scenario.filter(o => o.status !== 'cancelled').reduce((s, o) => s + (o.oracleOre as bigint), 0n);
eq('the catering total = the oracle, 170 301 øre by hand', C.cateringTotalOre(scenario), Number(oracleTotal));
eq('the oracle total is the hand figure', Number(oracleTotal), 170301);

// ---- 4. an unknown VAT % makes its day, and the total, unknown: never a zero or a guess -------------------------------

const withUnknown = [...scenario, UNKNOWN];
const daysUnknown = C.checkoutCateringDays(withUnknown, 'no', NOW);
const unknownDay = daysUnknown.find((day: any) => day.dayKey === '2026-12-05');
check('unknown VAT: its day exists', unknownDay !== undefined);
eq('unknown VAT: its day has no sum (null, shown «-»)', D.cateringDaySumOre(unknownDay), null);
eq('unknown VAT: the other days keep their sums',
    daysUnknown.filter((day: any) => day.dayKey !== '2026-12-05').map((day: any) => D.cateringDaySumOre(day)), [101430, 57621, 11250]);
eq('unknown VAT: the total is unknown too', C.cateringTotalOre(withUnknown), null);
eq('unknown VAT: a null day and a null total agree',
    daysUnknown.some((day: any) => D.cateringDaySumOre(day) === null), C.cateringTotalOre(withUnknown) === null);

// ---- 5. the label of a day: «3. desember», built from the calendar key with no zone shift ------------------------------

eq('label: 3 Dec in Norwegian', D.cateringDaySumDate('2026-12-03', 'no'), '3. desember');
eq('label: 4 Dec in Norwegian (the midnight order\'s day)', D.cateringDaySumDate('2026-12-04', 'no'), '4. desember');
eq('label: nn reads as nb', D.cateringDaySumDate('2026-12-04', 'nn'), '4. desember');
eq('label: English', D.cateringDaySumDate('2026-12-03', 'en'), '3 December');

// ---- 6. the offers line: the names of the building's offers, one line, Norwegian list ----------------------------------

eq('offers: one', D.cateringOffersText(['Kantina'], 'no'), 'Kantina');
eq('offers: two', D.cateringOffersText(['Kantina', 'Spiseriet'], 'no'), 'Kantina og Spiseriet');
eq('offers: three', D.cateringOffersText(['Kantina', 'Spiseriet', 'Kiosken'], 'no'), 'Kantina, Spiseriet og Kiosken');
eq('offers: a name twice is listed once', D.cateringOffersText(['Kantina', 'Kantina'], 'no'), 'Kantina');

// ---- 7. wiring and lang (text only: the component is not rendered here) -------------------------------------------------

const section = read(process.env.SECTION_TSX ?? join(client, 'components/checkout/hospitality/hospitality-section.tsx'));
check('section: the day sum is the helper\'s, once per day',
    section.split('cateringDaySumOre(day)').length - 1 === 1);
check('section: the day sum carries its test id', section.includes('data-testid="checkout-catering-day-sum"'));
check('section: the day label is the helper\'s', section.includes('daySumLabel(day.dayKey)') && section.includes('cateringDaySumDate(dayKey, lang)'));
check('section: the sums are keyed by their own lang keys',
    section.includes("'bookingfrontend.catering_day_sum'") && section.includes("'bookingfrontend.catering_day_sum_undated'"));
for (const lang of ['no', 'nn', 'en']) {
    const file = read(join(root, `src/modules/bookingfrontend/setup/phpgw_${lang}.lang`));
    check(`lang catering_day_sum (${lang}) has %1`, new RegExp(`^catering_day_sum\\tbookingfrontend\\t${lang}\\t.*%1`, 'm').test(file));
    check(`lang catering_day_sum_undated (${lang}) present`, new RegExp(`^catering_day_sum_undated\\tbookingfrontend\\t${lang}\\t.+$`, 'm').test(file));
    check(`lang catering_offers_in_building (${lang}) has %1`, new RegExp(`^catering_offers_in_building\\tbookingfrontend\\t${lang}\\t.*%1`, 'm').test(file));
}

// ---- 8. the offers line before the first order: two rows, on the component's source text (it is not rendered here) -----
// The line sits under canAdd, which needs an available hospitality (allowAdd and hospitalities.length > 0), and under
// "no order listed yet" (listedCount === 0). It names the fetched hospitalities. The live run shows the line itself.
const gateNeedsHospitality = (text: string): boolean => /const canAdd = allowAdd && hospitalities\.length > 0;/.test(text);
const shownBeforeFirstOrder = (text: string): boolean => /canAdd && listedCount === 0 &&[\s\S]{0,200}catering_offers_in_building/.test(text);
const namesFetchedOffers = (text: string): boolean => /cateringOffersText\(hospitalities\.map\(hospitality => hospitality\.name\), lang\)/.test(text);
// Row 1: no available hospitality → no info line
check('offers line: no available hospitality → no info line', gateNeedsHospitality(section) && shownBeforeFirstOrder(section));
// Row 2: zero orders + ≥1 available hospitality → the info line names them
check('offers line: zero orders + an available hospitality → the line names them', shownBeforeFirstOrder(section) && namesFetchedOffers(section));

console.log(`${fail === 0 ? 'PASS' : 'FAIL'} ${n - fail}/${n}`);
process.exit(fail === 0 ? 0 : 1);
