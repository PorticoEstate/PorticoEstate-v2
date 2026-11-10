/**
 * Hermetic check of the PRICE SUMMARY in the Next client (task 39791, GH #1423): the sum ex VAT, the VAT per
 * rate and the total incl. VAT on Min side.
 *
 *   node tests/incl-vat/run_summary.mts
 *
 * It loads a TEMP COPY of utils/article-pricing.ts exactly as tests/incl-vat/run.mts does. PRICING_TS
 * (optional) points at an alternative copy: tests/incl-vat/mutate_summary.sh uses it to prove that a
 * deliberately broken summary turns this harness red.
 *
 * What it holds the summary to:
 *   1. ex + VAT = incl to the øre, on every case, against an oracle in exact integer arithmetic that never
 *      uses the summary's own helpers (tax = round-half-up of amount x % / 100, per LINE, as the billing job
 *      writes it);
 *   2. the incl total is the SAME number the page already shows: Σ orderLineTotalOre over the stored lines
 *      (== Σ order.sum, which the server builds as amount + tax) and Σ hospitalityOrderTotalOre over the
 *      catering orders;
 *   3. the VAT is the VAT of the lines, never the VAT of the sum (the control case differs by an øre);
 *   4. the rate rows add up to the VAT row, highest rate first, and a free line makes no row;
 *   5. an UNKNOWN VAT stays unknown (null → «-»): never 0 %, and no rate rows that would not add up;
 *   6. the rate label of a stored application line is its own `tax_percent` (fm_ecomva, sent read-only by the
 *      server) and only where it AGREES with the stored tax: 25 and 0 fit, NULL (or an absent field) and a rate
 *      the stored tax contradicts (stored lines 18352 and 3169) give NO percent and the line's stored VAT is still
 *      summed under the plain «Mva» row, never as 0 %; nothing is inferred from the ratio tax / amount.
 *
 *   7. the page's own figures are unchanged (orderLineTotal is orderLineTotalOre / 100);
 *   8. the category rows of the application summary leave out a 0 kr row («Romleie 0 kr» on an application with no
 *      stored lines), keep an unknown one (null, «-») and a non-zero one, and NEVER touch the VAT rows or the
 *      total: they are not in that list, and the component filters nothing else (a source pattern).
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const utils = join(resolve(here, '../..'), 'src/modules/bookingfrontend/client/src/utils');

const dir = mkdtempSync(join(tmpdir(), 'price-summary-'));
const dropImports = (text: string) => text.split('\n').filter(line => !line.startsWith('import ')).join('\n');
writeFileSync(join(dir, 'cost-utils.mts'), dropImports(readFileSync(join(utils, 'cost-utils.ts'), 'utf8')));
writeFileSync(
    join(dir, 'article-pricing.mts'),
    `const DateTime: any = {};\nimport {formatAmount, formatCurrency} from './cost-utils.mts';\n`
    + dropImports(readFileSync(process.env.PRICING_TS ?? join(utils, 'article-pricing.ts'), 'utf8'))
);
const P: any = await import(pathToFileURL(join(dir, 'article-pricing.mts')).href);

let fail = 0;
let n = 0;
const check = (name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) {
        fail++;
        console.log(`FAIL ${name} ${detail}`);
    }
};
const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

// ---- the oracle: exact integers, nothing from the code under test ----------------------------------------
const taxOre = (exOre: number, pct: number): number => Number((BigInt(exOre) * BigInt(pct) + 50n) / 100n);
const kr = (ore: number): string => (ore / 100).toFixed(2);

// ---- fixtures --------------------------------------------------------------------------------------------
let nextId = 1;
/** A stored catering line: ex amount in øre and the percent as the API sends it (null = unknown). */
const cateringLine = (exOre: number, percent: number | null) => ({id: nextId++, amount: kr(exOre), tax_percent: percent});
/** A stored application order line, written the way the server writes it (tax = amount x % to the øre). */
const storedLine = (exOre: number, pct: number, cat: 1 | 2, taxCode: number) => ({
    article_cat_id: cat,
    tax_code: taxCode,
    amount: exOre / 100,
    tax: taxOre(exOre, pct) / 100,
    tax_percent: pct,
});

// 1+2. catering: ex + VAT = incl, equal to the page's own helper, per line and per order
for (const pct of [0, 15, 25]) {
    for (let exOre = 0; exOre <= 4000; exOre += 3) {
        const line = cateringLine(exOre, pct);
        const priced = P.pricedHospitalityLine(line);
        if (priced.exOre !== exOre || priced.vatOre !== taxOre(exOre, pct) || priced.exOre + priced.vatOre !== P.hospitalityLineTotalOre(line)) {
            check('pricedHospitalityLine', false, JSON.stringify({exOre, pct, priced}));
        }
    }
}
check('pricedHospitalityLine equals the oracle and hospitalityLineTotalOre on 4 011 cases', true);

// 3. VAT of the lines, not of the sum: 2 lines of 0.10 ex at 25 % read 0.26 incl (ef871f818), VAT 0.06
{
    const lines = [cateringLine(10, 25), cateringLine(10, 25)];
    const s = P.summarisePricedLines(lines.map(P.pricedHospitalityLine));
    check('2 x 0.10 ex @25: ex 20, VAT 6, incl 26', s.exOre === 20 && s.vatOre === 6 && s.inclOre === 26, JSON.stringify(s));
    check('control: the VAT of the SUM would be 5 (so this case can tell the two apart)', taxOre(20, 25) === 5 && s.vatOre !== 5);
}

// 1+2+4. mixed rates over many generated orders: the totals, the rows, the page's own totals
{
    let seed = 20261110;
    const rand = (max: number) => (seed = (seed * 1103515245 + 12345) % 2147483648) % max;
    const rates = [0, 12, 15, 25];
    let cases = 0;
    for (let round = 0; round < 400; round++) {
        const nOrders = 1 + rand(4);
        const cateringOrders = Array.from({length: nOrders}, () => ({
            lines: Array.from({length: rand(5)}, () => cateringLine(1 + rand(90000), rates[rand(4)])),
        }));
        const nStored = rand(3);
        const orders = Array.from({length: nStored}, () => ({
            order_id: nextId++,
            lines: Array.from({length: 1 + rand(4)}, (_, i) => storedLine(rand(80000), rates[rand(4)], i % 2 === 0 ? 1 : 2, 100 + rand(4))),
        }));
        const parts = P.applicationPriceSummary(orders, cateringOrders);
        const all = [...orders.flatMap(o => o.lines.map((l: any) => ({ex: Math.round(l.amount * 100), vat: Math.round(l.tax * 100)}))),
            ...cateringOrders.flatMap(o => o.lines.map((l: any) => {
                const ex = Math.round(Number(l.amount) * 100);
                return {ex, vat: taxOre(ex, l.tax_percent)};
            }))];
        const wantEx = all.reduce((s, l) => s + l.ex, 0);
        const wantVat = all.reduce((s, l) => s + l.vat, 0);
        const t = parts.total;
        cases++;
        if (t.exOre !== wantEx || t.vatOre !== wantVat || t.inclOre !== wantEx + wantVat || t.exOre + t.vatOre !== t.inclOre) {
            check('total = oracle', false, JSON.stringify({t, wantEx, wantVat}));
        }
        // the page's own numbers
        const storedIncl = orders.reduce((s, o) => s + o.lines.reduce((a: number, l: any) => a + P.orderLineTotalOre(l), 0), 0);
        const serverSum = orders.reduce((s, o) => s + Math.round(o.lines.reduce((a: number, l: any) => a + l.amount + l.tax, 0) * 100), 0);
        const cateringIncl = P.sumOreOrNull(cateringOrders.map((o: any) => P.hospitalityOrderTotalOre(o)));
        if (parts.rental.inclOre + parts.services.inclOre !== storedIncl || storedIncl !== serverSum) {
            check('rental + services = Σ stored lines = Σ order.sum', false, JSON.stringify({parts, storedIncl, serverSum}));
        }
        if (parts.catering.inclOre !== cateringIncl) check('catering incl = Σ hospitalityOrderTotalOre', false, JSON.stringify({c: parts.catering, cateringIncl}));
        if (t.inclOre !== parts.rental.inclOre + parts.services.inclOre + parts.catering.inclOre) check('total = rental + services + catering', false);
        // the rate rows
        const rowVat = t.rates.reduce((s: number, r: any) => s + r.vatOre, 0);
        const rowEx = t.rates.reduce((s: number, r: any) => s + r.exOre, 0);
        // highest first, strictly (one row per rate), a row without a percent only ever last
        const known = t.rates.filter((r: any) => r.percent !== null).map((r: any) => r.percent);
        const noPercentAt = t.rates.findIndex((r: any) => r.percent === null);
        const sorted = known.every((p: number, i: number) => i === 0 || known[i - 1] > p)
            && (noPercentAt === -1 || noPercentAt === t.rates.length - 1);
        if (rowVat !== t.vatOre || rowEx !== t.exOre || !sorted) check('rate rows add up and are sorted', false, JSON.stringify(t));
        if (!t.rates.every((r: any) => r.percent === null || rates.includes(r.percent))) check('every printed rate is one a line carried', false, JSON.stringify(t.rates));
    }
    check(`${cases} generated applications: totals = oracle, = the page's own sums, rate rows add up`, true);
}

// 4. a free line makes no row and shifts no sum
{
    const s = P.summarisePricedLines([{exOre: 0, vatOre: 0, percent: 25}, {exOre: 1000, vatOre: 250, percent: 25}]);
    check('free line: one row @25 only', same(s.rates, [{percent: 25, exOre: 1000, vatOre: 250}]), JSON.stringify(s.rates));
    const freeWithout = P.summarisePricedLines([{exOre: 1000, vatOre: 250, percent: 25}, {exOre: 0, vatOre: 0, percent: null}]);
    check('a free line WITHOUT a percent makes no row either (only the 25 % row)', same(freeWithout.rates.map((r: any) => r.percent), [25]), JSON.stringify(freeWithout.rates));
    check('...and a summary of free lines alone has no rows and 0 VAT', same(P.summarisePricedLines([{exOre: 0, vatOre: 0, percent: null}]).rates, []));
    const none = P.summarisePricedLines([]);
    check('no lines: 0 / 0 / 0 and no rows', none.exOre === 0 && none.vatOre === 0 && none.inclOre === 0 && none.rates.length === 0);
    const mixed = P.summarisePricedLines([{exOre: 1000, vatOre: 250, percent: 25}, {exOre: 800, vatOre: 120, percent: 15}, {exOre: 500, vatOre: 0, percent: 0}]);
    check('rows are highest rate first, a 0 % row with an amount is kept',
        same(mixed.rates.map((r: any) => r.percent), [25, 15, 0]) && mixed.vatOre === 370 && mixed.inclOre === 2670, JSON.stringify(mixed));
}

// 5. an unknown VAT stays unknown
{
    const s = P.summarisePricedLines([P.pricedHospitalityLine(cateringLine(1000, 25)), P.pricedHospitalityLine(cateringLine(500, null))]);
    check('unknown catering percent: ex known, VAT null, incl null, no rows', s.exOre === 1500 && s.vatOre === null && s.inclOre === null && s.rates.length === 0, JSON.stringify(s));
    check('...and the line itself reports null, not 0', P.pricedHospitalityLine(cateringLine(500, null)).vatOre === null);
    const parts = P.applicationPriceSummary([], [{lines: [cateringLine(500, null)]}]);
    check('an application with one unknown-VAT catering line has no incl total (null → «-»)', parts.total.inclOre === null && parts.catering.inclOre === null);
}

// 6. the rate label of a stored application line: its own `tax_percent` (fm_ecomva.percent_, sent read-only by
// the server), NEVER inferred from tax / amount, NULL is not 0 %, and it is printed only where it AGREES with the
// stored tax (fm_ecomva holds today's rate, the tax was stored when the line was billed)
{
    const priced = (taxPercent: number | null | undefined, amount: number, tax: number) =>
        P.pricedOrderLine({amount, tax, tax_percent: taxPercent});
    check('25 fits: tax_percent 25 on 100 kr with 25 kr tax → labelled 25 %', priced(25, 100, 25).percent === 25);
    check('0 fits: tax_percent 0 on 50 kr with no tax → labelled 0 % (a real 0 %, kept apart from unknown)', priced(0, 50, 0).percent === 0);
    check('tax_percent NULL → no percent', priced(null, 100, 25).percent === null);
    check('a payload WITHOUT the field → no percent', priced(undefined, 100, 25).percent === null);
    check('NULL is not read as 0 %: the same line with 0 and with NULL differ', priced(0, 100, 0).percent === 0 && priced(null, 100, 0).percent === null);
    // nothing is inferred: 25 kr on 100 kr WOULD read 25 % from the ratio, and 7 kr on 100 kr 7 %
    check('control: the raw ratio of 25 on 100 reads 25 % and of 7 on 100 reads 7 %',
        P.taxPercentOfOrderLine({amount: 100, tax: 25}) === 25 && P.taxPercentOfOrderLine({amount: 100, tax: 7}) === 7);
    check('...yet with tax_percent NULL neither line has a percent (no inference)', priced(null, 100, 25).percent === null && priced(null, 100, 7).percent === null);

    // the stored tax contradicts the code's rate: NO label, the VAT as stored. Stored line 18352 (app 84024): 100.00 ex,
    // VAT 0.00 under a 25 % code; stored line 3169: 200.00 ex, VAT 2.00 under a 0 % code.
    const l18352 = priced(25, 100, 0);
    check('18352\'s shape (25 % code, VAT 0.00 on 100.00): NO percent, the VAT stays the stored 0', l18352.percent === null && l18352.vatOre === 0 && l18352.exOre === 10000);
    const l3169 = priced(0, 200, 2);
    check('3169\'s shape (0 % code, VAT 2.00 on 200.00): NO percent, the VAT stays the stored 2.00', l3169.percent === null && l3169.vatOre === 200);
    // generated: a label is printed only if that rate reproduces the stored tax, and always when the line was billed at it
    let printed = 0;
    let withheld = 0;
    for (let ex = 0; ex <= 2000; ex++) {
        for (const billedAt of [0, 12, 15, 25]) {
            for (const field of [0, 12, 15, 25]) {
                const got = priced(field, ex / 100, taxOre(ex, billedAt) / 100).percent;
                if (got !== null) {
                    printed++;
                    if (got !== field || taxOre(ex, field) !== taxOre(ex, billedAt)) check('a printed label reproduces the stored tax', false, JSON.stringify({ex, billedAt, field, got}));
                } else {
                    withheld++;
                    if (taxOre(ex, field) === taxOre(ex, billedAt)) check('a rate that reproduces the stored tax is not withheld', false, JSON.stringify({ex, billedAt, field}));
                }
                if (field === billedAt && got !== field) check('the rate a line was billed at is always printed', false, JSON.stringify({ex, billedAt, got}));
            }
        }
    }
    check(`${printed} labels printed and ${withheld} withheld over 32 016 generated (line, field) pairs: every label reproduces the stored tax, every withheld one does not`, printed > 0 && withheld > 0);

    // in a summary: 25 %, 0 %, a code with no rate (NULL), and the 18352 shape, which joins the plain row with its stored 0
    const lines = [
        {article_cat_id: 2, tax_code: 9, amount: 100, tax: 25, tax_percent: 25},
        {article_cat_id: 1, tax_code: 0, amount: 50, tax: 0, tax_percent: 0},
        {article_cat_id: 2, tax_code: 77, amount: 40, tax: 8, tax_percent: null},
        {article_cat_id: 2, tax_code: 9, amount: 100, tax: 0, tax_percent: 25},
        {article_cat_id: 1, tax_code: 9, amount: 0, tax: 0, tax_percent: 25},
    ];
    const s = P.applicationPriceSummary([{lines}], []).total;
    check('rows: 25 % (25 kr), 0 % (0 kr), then ONE plain row (8 kr + the 18352-shaped 0 kr); never «25 %: 0 kr»',
        same(s.rates.map((r: any) => [r.percent, r.vatOre]), [[25, 2500], [0, 0], [null, 800]]), JSON.stringify(s.rates));
    check('...ex 290 kr, VAT 33 kr, incl 323 kr = ex + VAT', s.exOre === 29000 && s.vatOre === 3300 && s.inclOre === 32300, JSON.stringify(s));
}

// 7. the page's own figures, pinned: orderLineTotal is still orderLineTotalOre / 100
check('orderLineTotal = orderLineTotalOre / 100', P.orderLineTotal({amount: 400, tax: 100}) === 500 && P.orderLineTotalOre({amount: 400, tax: 100}) === 50000);

// 8. the category rows of the application summary: a 0 kr row is left out, the VAT rows and the total never are
{
    const part = (key: string, ore: number | null) => ({key, label: key, ore});
    const kept = (parts: any[]): string[] => P.withoutZeroParts(parts).map((p: any) => p.key);
    check('no stored lines: «Romleie 0 kr» and «Utstyr og tjenester 0 kr» are left out, the catering row stays',
        same(kept([part('rental', 0), part('services', 0), part('catering', 225250)]), ['catering']));
    check('a non-zero row stays, in order', same(kept([part('rental', 650000), part('services', 0), part('catering', 895000)]), ['rental', 'catering']));
    check('an UNKNOWN amount (null, shown «-») is not 0 and stays', same(kept([part('rental', null), part('services', 0)]), ['rental']));
    check('control: a row that is 0 is the ONLY kind that goes (a 1 øre row stays)', same(kept([part('a', 1), part('b', 0), part('c', -1)]), ['a', 'c']));
    check('all category rows 0 → none left (the ex row, the VAT rows and the total are not in this list)', kept([part('a', 0), part('b', 0)]).length === 0);
    const s = P.summarisePricedLines([{exOre: 1000, vatOre: 250, percent: 25}, {exOre: 500, vatOre: 0, percent: 0}]);
    check('a non-zero total keeps ALL its VAT rows, a real «Mva 0 %: 0 kr» included, and its total',
        same(s.rates.map((r: any) => [r.percent, r.vatOre]), [[25, 250], [0, 0]]) && s.inclOre === 1750, JSON.stringify(s));
    const component = readFileSync(join(utils, '../components/user/application/catering-price-summary.tsx'), 'utf8');
    const filtersRows = /(rates|vatRows|summary)\.filter\(/;
    check('control: the pattern WOULD see a VAT rate row being filtered', filtersRows.test('summary.rates.filter(rate => rate.vatOre !== 0).map(rate => x)'));
    check('the component filters the category rows through withoutZeroParts and nothing else',
        /const shownParts = withoutZeroParts\(parts \?\? \[\]\);/.test(component) && /\{shownParts\.map\(part => \(/.test(component) && !filtersRows.test(component));
}

if (fail) {
    console.log(`Price-summary harness: FAIL ${fail} of ${n} checks`);
    process.exit(1);
}
console.log(`Price-summary harness: PASS ${n} checks on node ${process.version}`);
