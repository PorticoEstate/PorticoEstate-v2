/**
 * Hermetic check of the prices-incl-VAT helpers in the Next client (task 36900).
 *
 *   node tests/incl-vat/run.mts
 *
 * Imports a TEMP COPY of utils/article-pricing.ts and utils/cost-utils.ts (only their import lines are
 * replaced, so the type-only imports and luxon are not needed; Node >= 22.18 strips the types itself).
 * PRICING_TS (optional) points at an alternative copy of article-pricing.ts: a mutation driver uses it to
 * prove that a deliberately broken helper turns this harness red.
 *
 * The oracle is exact integer arithmetic, no floats: amount = ex øre x quantity, tax = round-half-up of
 * amount x percent / 100. That is what the billing job writes (async_task_update_reservation_state,
 * create_purchase_orders_from_hospitality: amount = quantity x unit_price, tax = amount x percent / 100,
 * stored in numeric(10,2)), and what ArticleRepository::savePurchaseOrderLine writes for articles.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const utils = join(resolve(here, '../..'), 'src/modules/bookingfrontend/client/src/utils');

const dir = mkdtempSync(join(tmpdir(), 'incl-vat-'));
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

const oracle = (exOre: number, qty: number, pct: number): number => {
    const amount = BigInt(exOre) * BigInt(qty);
    const tax = (amount * BigInt(pct) + 50n) / 100n;
    return Number(amount + tax);
};
const kr = (ore: number) => (ore / 100).toFixed(2);

// 1. An article line (lineTotalOre) equals the oracle on every øre price up to 60 kr, 3 rates, 4 quantities
for (const pct of [0, 15, 25]) {
    for (let exOre = 0; exOre <= 6000; exOre++) {
        for (const qty of [1, 2, 3, 7]) {
            const got = P.lineTotalOre({ex_tax_price: kr(exOre), tax_percent: pct}, qty);
            if (got !== oracle(exOre, qty, pct)) check('lineTotalOre', false, JSON.stringify({exOre, qty, pct, got}));
        }
    }
}
check('lineTotalOre equals the oracle on 72 012 cases', true);
check('136.99 ex x3 @25 = 513.71 (not 3 x 171.24)', P.lineTotalOre({ex_tax_price: '136.99', tax_percent: 25}, 3) === 51371);

// 2. A catering line: the stored line (amount) and the menu row (price x quantity) both equal the oracle
for (const pct of [0, 25]) {
    for (let exOre = 1; exOre <= 30000; exOre += 7) {
        for (const qty of [1, 2, 3, 12]) {
            const line = P.hospitalityLineTotalOre({amount: kr(exOre * qty), tax_percent: pct});
            if (line !== oracle(exOre, qty, pct)) check('hospitalityLineTotalOre', false, JSON.stringify({exOre, qty, pct, line}));
            const menu = P.hospitalityMenuLineTotalOre({effective_price: kr(exOre), effective_tax_percent: pct}, qty);
            if (menu !== line) check('menu line equals stored line', false, JSON.stringify({exOre, qty, pct, menu, line}));
        }
    }
}
check('catering lines equal the oracle, stored line == menu line (34 296 cases)', true);

// 3. Rows measured on the laptop DB
check('billed hospitality order 56: 25.00 ex @25 -> 31.25 (PO line 17905: 25.00 + 6.25)', P.hospitalityLineTotalOre({amount: '25.00', tax_percent: 25}) === 3125);
check('Kaffi 1l 266.00 @25 -> 332.50', P.hospitalityMenuLineTotalOre({effective_price: '266.00', effective_tax_percent: 25}, 1) === 33250);
check('0 % keeps the amount', P.hospitalityLineTotalOre({amount: '100.00', tax_percent: 0}) === 10000);

// 4. An unknown VAT % is NOT 0 %
for (const bad of [null, undefined, '']) {
    check(`line percent ${String(bad)} -> null`, P.hospitalityLineTotalOre({amount: '10.00', tax_percent: bad}) === null);
    check(`menu percent ${String(bad)} -> null`, P.hospitalityMenuLineTotalOre({effective_price: '10.00', effective_tax_percent: bad}, 2) === null);
    check(`unit price percent ${String(bad)} -> null`, P.hospitalityUnitPriceIncl({effective_price: '10.00', effective_tax_percent: bad}) === null);
}
check('a menu article with no price -> null, not NaN', P.hospitalityMenuLineTotalOre({effective_price: null, effective_tax_percent: 25}, 2) === null);
check('0 is a known percent', P.hospitalityLineTotalOre({amount: '10.00', tax_percent: 0}) === 1000);

// 5. Sums: each line rounded once, an unknown line makes the total unknown, nothing is shown as ex VAT
check('sum [] = 0', P.sumOreOrNull([]) === 0);
check('sum with a null = null', P.sumOreOrNull([1, null, 3]) === null);
check('2 x 0.10 ex @25: each line 0.125 -> 0.13, total 0.26', P.hospitalityOrderTotalOre({lines: [{amount: '0.10', tax_percent: 25}, {amount: '0.10', tax_percent: 25}]}) === 26);
check('an order with an unknown line has no total', P.hospitalityOrderTotalOre({lines: [{amount: '10.00', tax_percent: 25}, {amount: '10.00', tax_percent: null}]}) === null);
check('an order with no lines is 0', P.hospitalityOrderTotalOre({lines: []}) === 0);
check('formatOreOrDash(null) is a dash', P.formatOreOrDash(null) === '-');
check('formatOreOrDash(33250) reads 332,50', /332,50/.test(P.formatOreOrDash(33250)));
check('formatOreOrDash(0) is a real zero, not a dash', P.formatOreOrDash(0) !== '-');

// 6. Unit prices shown incl. VAT
check('266.00 @25 -> 332.5', P.hospitalityUnitPriceIncl({effective_price: '266.00', effective_tax_percent: 25}) === 332.5);
check('3.76 @25 -> 4.7', P.hospitalityUnitPriceIncl({effective_price: '3.76', effective_tax_percent: 25}) === 4.7);
check('stored order line 3.76 ex, tax 0.94 -> 4.7', P.unitPriceInclOfOrderLine({unit_price: '3.76', amount: '3.76', tax: '0.94'}) === 4.7);
check('stored order line 136.99 ex, tax 102.74 on 410.97 -> 171.24', P.unitPriceInclOfOrderLine({unit_price: '136.99', amount: '410.97', tax: '102.74'}) === 171.24);

// 7. The label stored with a chosen price (a bare amount is the option's EX-VAT price)
const base = {unit: 'hour', currency: 'NOK', unit_price: '3.76', amount: '3.76', tax: '0.94'};
const label = (patch: object) => P.priceLabelForDisplay({...base, ...patch});
check('bare amount equal to the unit price -> shown incl. (4,70)', /4,70/.test(String(label({price_label: '3.76'}))));
check('bare amount with a comma -> shown incl.', /4,70/.test(String(label({price_label: '3,76'}))));
check('a text remark is unchanged', label({price_label: 'Seniorpris'}) === 'Seniorpris');
check('no label stays no label', label({price_label: null}) === null && label({price_label: undefined}) === null);
check('minute line + bare amount -> not shown (the per-minute price)', label({unit: 'minute', price_label: '3.76'}) === null);
check('minute line + text remark is unchanged', label({unit: 'minute', price_label: 'Lag'}) === 'Lag');
check('a numeric label that is not the unit price stays as typed', label({price_label: '150'}) === '150');
check('no amount to read the VAT % from -> not shown, never the ex amount', label({price_label: '3.76', amount: '0.00', tax: '0.00'}) === null);

console.log(fail === 0 ? `incl-VAT helpers: PASS ${n} checks` : `incl-VAT helpers: FAIL ${fail} of ${n}`);
process.exit(fail === 0 ? 0 : 1);
