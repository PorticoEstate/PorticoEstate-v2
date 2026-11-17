/**
 * Hermetic check of the catering price summary (task 39792): sum ex VAT, VAT per rate, total incl. VAT.
 *
 *   node tests/incl-vat/run_hospitality_summary.mts
 *
 * Imports a TEMP COPY of utils/hospitality-price-summary.ts and utils/article-pricing.ts (only their import
 * lines are replaced, so luxon and the path alias are not needed; Node >= 22.18 strips the types itself).
 * SUMMARY_TS (optional) points at an alternative copy of hospitality-price-summary.ts: the mutation driver
 * (mutate_hospitality_summary.py) uses it to prove that a deliberately broken helper turns this harness red.
 *
 * The oracle is exact BigInt arithmetic, no floats: amount = ex øre x quantity, VAT = round-half-up of
 * amount x percent / 100 PER LINE (what the billing job writes: create_purchase_orders_from_hospitality),
 * incl = amount + VAT. The summary is compared with it figure by figure, and against the item-row rule
 * (hospitalityMenuLineTotalOre) that the dialog's rows already go through.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const utils = join(resolve(here, '../..'), 'src/modules/bookingfrontend/client/src/utils');

const dir = mkdtempSync(join(tmpdir(), 'hosp-summary-'));
const dropImports = (text: string) => text.split('\n').filter(line => !line.startsWith('import ')).join('\n');
writeFileSync(join(dir, 'cost-utils.mts'), dropImports(readFileSync(join(utils, 'cost-utils.ts'), 'utf8')));
writeFileSync(
    join(dir, 'article-pricing.mts'),
    `const DateTime: any = {};\nimport {formatAmount, formatCurrency} from './cost-utils.mts';\n`
    + dropImports(readFileSync(join(utils, 'article-pricing.ts'), 'utf8'))
);
writeFileSync(
    join(dir, 'hospitality-price-summary.mts'),
    `import {inclOre} from './article-pricing.mts';\n`
    + dropImports(readFileSync(process.env.SUMMARY_TS ?? join(utils, 'hospitality-price-summary.ts'), 'utf8'))
);
const P: any = await import(pathToFileURL(join(dir, 'article-pricing.mts')).href);
const S: any = await import(pathToFileURL(join(dir, 'hospitality-price-summary.mts')).href);

let fail = 0;
let n = 0;
const check = (name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) {
        fail++;
        console.log(`FAIL ${name} ${detail}`);
    }
};

const kr = (ore: number) => (ore / 100).toFixed(2);
const vatOracle = (exOre: bigint, pct: number): bigint => (exOre * BigInt(pct) + 50n) / 100n;

interface Ordered {
    priceOre: number;
    qty: number;
    pct: number | null;
}

const asLines = (ordered: Ordered[]) => ordered.map(o => S.hospitalityMenuLineForSummary(
    {effective_price: kr(o.priceOre), effective_tax_percent: o.pct},
    o.qty
));

/** What the billing job would write for these lines, per rate and in total. */
const expected = (ordered: Ordered[]) => {
    const rates = new Map<number, {ex: bigint; vat: bigint}>();
    let ex = 0n;
    let vat = 0n;
    for (const o of ordered) {
        const lineEx = BigInt(o.priceOre) * BigInt(o.qty);
        const lineVat = vatOracle(lineEx, o.pct as number);
        const r = rates.get(o.pct as number) ?? {ex: 0n, vat: 0n};
        r.ex += lineEx;
        r.vat += lineVat;
        rates.set(o.pct as number, r);
        ex += lineEx;
        vat += lineVat;
    }
    return {ex, vat, rates: [...rates.entries()].sort((a, b) => b[0] - a[0])};
};

const matches = (ordered: Ordered[]): string => {
    const got = S.summarizeHospitalityVat(asLines(ordered));
    const want = expected(ordered);
    if (!got.known) return 'summary not known';
    if (BigInt(got.exOre) !== want.ex) return `ex ${got.exOre} != ${want.ex}`;
    if (BigInt(got.vatOre) !== want.vat) return `vat ${got.vatOre} != ${want.vat}`;
    if (BigInt(got.inclOre) !== want.ex + want.vat) return `incl ${got.inclOre} != ${want.ex + want.vat}`;
    if (got.rates.length !== want.rates.length) return `rates ${got.rates.length} != ${want.rates.length}`;
    for (let i = 0; i < want.rates.length; i++) {
        const [pct, r] = want.rates[i];
        const g = got.rates[i];
        if (g.percent !== pct || BigInt(g.exOre) !== r.ex || BigInt(g.vatOre) !== r.vat) {
            return `rate row ${i}: ${JSON.stringify(g)} != ${pct}/${r.ex}/${r.vat}`;
        }
    }
    return '';
};

// 1. The worked example of artboard D: Drikke 3 x 180 + 1 x 110 + 30 x 35 and Bakverk 30 x 35, all at 25 %
{
    const ordered: Ordered[] = [
        {priceOre: 18000, qty: 3, pct: 25},
        {priceOre: 11000, qty: 1, pct: 25},
        {priceOre: 3500, qty: 30, pct: 25},
        {priceOre: 3500, qty: 30, pct: 25},
    ];
    const got = S.summarizeHospitalityVat(asLines(ordered));
    // The artboard's «2 750 kr» is labelled «eks. mva.»: it is the ex-VAT sum (1 700 + 1 050). Incl. VAT it is 3 437,50.
    check('design example: ex 2 750 kr, VAT 687,50 kr, incl 3 437,50 kr',
        got.known && got.exOre === 275000 && got.vatOre === 68750 && got.inclOre === 343750, JSON.stringify(got));
    check('design example matches the oracle', matches(ordered) === '', matches(ordered));
}

// 2. Mixed rates: one row per rate, highest first, a 0 % rate is a row with VAT 0
{
    const ordered: Ordered[] = [
        {priceOre: 3500, qty: 2, pct: 15},
        {priceOre: 18000, qty: 1, pct: 25},
        {priceOre: 1000, qty: 3, pct: 0},
        {priceOre: 2500, qty: 4, pct: 25},
    ];
    const got = S.summarizeHospitalityVat(asLines(ordered));
    check('mixed rates: rows 25, 15, 0 in that order',
        got.known && got.rates.map((r: any) => r.percent).join(',') === '25,15,0', JSON.stringify(got.rates));
    check('0 % row exists with VAT 0', got.known && got.rates[2].vatOre === 0 && got.rates[2].exOre === 3000);
    check('mixed rates match the oracle', matches(ordered) === '', matches(ordered));
}

// 3. VAT is rounded PER LINE, not once on the sum: 3 lines of 2 øre at 25 % = 3 x round(0.5) = 3, not round(1.5) = 2
{
    const ordered: Ordered[] = [1, 2, 3].map(() => ({priceOre: 2, qty: 1, pct: 25}));
    const got = S.summarizeHospitalityVat(asLines(ordered));
    check('VAT is the sum of per-line roundings (3), not a rounding of the sum (2)', got.known && got.vatOre === 3, JSON.stringify(got));
}

// 4. ex + VAT = incl to the øre, and incl equals the item-row rule, on many random orders
let seed = 20261110;
const rnd = (max: number) => {
    seed = (seed * 1103515245 + 12345) & 0x7fffffff;
    return seed % max;
};
const RATES = [0, 12, 15, 25];
for (let i = 0; i < 3000; i++) {
    const ordered: Ordered[] = [];
    const count = 1 + rnd(7);
    for (let k = 0; k < count; k++) {
        ordered.push({priceOre: 1 + rnd(60000), qty: 1 + rnd(40), pct: RATES[rnd(RATES.length)]});
    }
    const m = matches(ordered);
    if (m !== '') check('random order matches the oracle', false, `${m} ${JSON.stringify(ordered)}`);
    const got = S.summarizeHospitalityVat(asLines(ordered));
    if (!got.known || got.exOre + got.vatOre !== got.inclOre) {
        check('ex + VAT = incl', false, JSON.stringify({ordered, got}));
    }
    const rowSum = ordered.reduce((sum: number | null, o) => {
        const line = P.hospitalityMenuLineTotalOre({effective_price: kr(o.priceOre), effective_tax_percent: o.pct}, o.qty);
        return sum === null || line === null ? null : sum + line;
    }, 0);
    if (!got.known || got.inclOre !== rowSum) check('incl equals the sum of the item rows', false, JSON.stringify({ordered, got, rowSum}));
    if (got.known && got.rates.reduce((s: number, r: any) => s + r.vatOre, 0) !== got.vatOre) {
        check('the rate rows add up to the total VAT', false, JSON.stringify({ordered, got}));
    }
}
check('3000 random orders: oracle, ex + VAT = incl, item rows, rate rows', true);

// 5. Unknown rate or price: not known, never a figure that hides the gap; the ex sum stays when every price is known
{
    const lines = asLines([
        {priceOre: 3500, qty: 2, pct: 25},
        {priceOre: 1000, qty: 1, pct: null},
    ]);
    const got = S.summarizeHospitalityVat(lines);
    check('an unknown VAT % makes the summary not known', got.known === false);
    check('the ex sum stays exact (80 kr)', got.exOre === 8000, JSON.stringify(got));
    check('an unknown rate is NOT read as 0 %', !('vatOre' in got) && !('inclOre' in got), JSON.stringify(got));

    const noPrice = S.summarizeHospitalityVat([
        S.hospitalityMenuLineForSummary({effective_price: null, effective_tax_percent: 25}, 1),
        {exOre: 500, percent: 25},
    ]);
    check('an unknown price: not known and no ex sum', noPrice.known === false && noPrice.exOre === null, JSON.stringify(noPrice));
}

// 6. Nothing ordered: known, all zero, no rate rows
{
    const got = S.summarizeHospitalityVat([]);
    check('empty order: known, zero, no rates', got.known === true && got.exOre === 0 && got.vatOre === 0 && got.inclOre === 0 && got.rates.length === 0, JSON.stringify(got));
}

// 7. A percent that arrives as a string, and the empty string, as the item-row rule treats them
{
    const a = S.hospitalityMenuLineForSummary({effective_price: '35.00', effective_tax_percent: '25' as any}, 2);
    check('a string percent is read as a number', a.percent === 25 && a.exOre === 7000, JSON.stringify(a));
    const b = S.hospitalityMenuLineForSummary({effective_price: '35.00', effective_tax_percent: '' as any}, 2);
    check('an empty percent is unknown, not 0', b.percent === null, JSON.stringify(b));
}

console.log(fail === 0 ? `catering price summary: PASS ${n} checks` : `catering price summary: FAIL ${fail} of ${n}`);
process.exit(fail === 0 ? 0 : 1);
