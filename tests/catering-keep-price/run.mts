/**
 * TypeScript side of the catering keep-price harness (operator ruling D5 = a, forum/pe-general/50372): the price each ordered
 * article is shown and summed at in the catering dialog (src/utils/hospitality-order-pricing.ts).
 *
 *   node tests/catering-keep-price/run.mts
 *
 * The module is loaded through a temp copy named .mts, as tests/hospitality-deadline/run.mts does, so the module format is
 * unambiguous whatever the nearest package.json says. Its imports are types only, so nothing else needs resolving.
 *
 * Scope: which price each ordered article is shown and summed at. The dialog's TOTAL equal to the stored total after a save
 * is the live check, not this file. PRICING_TS (optional) points at another copy: on a tree without the module every row
 * fails, which is this side's red on base.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import assert from 'node:assert/strict';
import {copyFileSync, mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const source = process.env.PRICING_TS
    ?? join(root, 'src/modules/bookingfrontend/client/src/utils/hospitality-order-pricing.ts');

let pricing: any = null;
try {
    const dir = mkdtempSync(join(tmpdir(), 'kp-ts-'));
    const copy = join(dir, 'hospitality-order-pricing.mts');
    copyFileSync(source, copy);
    pricing = await import(pathToFileURL(copy).href);
} catch (error) {
    console.log(`FAIL the pricing module does not load from ${source}: ${(error as Error).message}`);
}

const failures: string[] = [];
let passed = 0;
const same = (name: string, got: () => unknown, want: unknown): void => {
    try {
        assert.deepStrictEqual(got(), want);
        passed++;
    } catch (error) {
        failures.push(`${name} — ${(error as Error).message.split('\n').slice(0, 4).join(' | ')}`);
    }
};

/** A menu article as the dialog holds it (EX VAT price as a decimal string, VAT % or null). */
const menu = (id: number, price: string, tax: number | null) => ({
    id, hospitality_id: 3, article_group_id: null, article_mapping_id: id, description: null, sort_order: 0, active: 1,
    article_name: `A${id}`, article_code: `${id}`, service_name_json: null, service_description_json: null, unit: 'stk',
    base_price: price, base_tax_code: 1, effective_price: price, effective_tax_code: 1, effective_tax_percent: tax,
});
/** A stored order line as GET returns it (unit_price EX VAT, tax_percent the VAT % of its tax code). */
const stored = (articleId: number, unit: string, tax: number | null, quantity = '1.00') => ({
    id: 400 + articleId, order_id: 7, hospitality_article_id: articleId, quantity, unit_price: unit,
    tax_code: 1, tax_percent: tax, amount: '0.00', comment: null, article_name: `A${articleId}`, unit: 'stk',
});

// 1. A line the order holds is priced at what it was ordered at, not at today's menu price.
same('1. Kaffe ordered at 45.00 keeps 45.00 when the menu says 60.00',
    () => pricing.orderedPriceOf(11, [menu(11, '60.00', 25)], [stored(11, '45.00', 25)]),
    {effective_price: '45.00', effective_tax_percent: 25});

// 2. ... and its VAT: ordered at 0 %, the menu says 25 %: 0 % stays.
same('2. Vann ordered at 0 % keeps 0 % when the menu says 25 %',
    () => pricing.orderedPriceOf(13, [menu(13, '20.00', 25)], [stored(13, '20.00', 0)]),
    {effective_price: '20.00', effective_tax_percent: 0});

// 3. Mixed VAT with an overridden article: the 77.70 at 15 % it was ordered at, not the menu's base 80.00 at 25 %.
same('3. Te ordered at 77.70 / 15 % keeps both when the menu says 80.00 / 25 %',
    () => pricing.orderedPriceOf(15, [menu(15, '80.00', 25)], [stored(15, '77.70', 15)]),
    {effective_price: '77.70', effective_tax_percent: 15});

// 4. A NEW article (no stored line) takes today's menu price and VAT.
same('4. a new Brød line takes the menu price 60.00 at 15 %',
    () => pricing.orderedPriceOf(14, [menu(14, '60.00', 15)], [stored(11, '45.00', 25)]),
    {effective_price: '60.00', effective_tax_percent: 15});

// 5. A stored line whose article has left the menu is still priced at its stored price (the server holds it).
same('5. an ordered Ølfri no longer on the menu is priced at its stored 30.00 / 25 %',
    () => pricing.orderedPriceOf(16, [], [stored(16, '30.00', 25)]),
    {effective_price: '30.00', effective_tax_percent: 25});

// 6. An unknown VAT % stays unknown (null), never 0 %.
same('6. a stored line whose VAT code has no row stays null, not 0 %',
    () => pricing.orderedPriceOf(17, [], [stored(17, '50.00', null)]),
    {effective_price: '50.00', effective_tax_percent: null});

// 7. An article neither ordered nor on the menu has no price.
same('7. an article the order does not hold and the menu does not have: null',
    () => pricing.orderedPriceOf(99, [menu(11, '45.00', 25)], []),
    null);

// 8. Lines to sum: ordered articles only (quantity above 0), each at the price it is held at.
same('8. Kaffe 3 (stored 45.00) and Brød 2 (new, 60.00 / 15 %); Kake 0 is not summed',
    () => pricing.orderedPriceLines(
        {11: 3, 12: 0, 14: 2},
        [menu(11, '60.00', 25), menu(12, '100.00', 25), menu(14, '60.00', 15)],
        [stored(11, '45.00', 25)]
    ),
    [
        {price: {effective_price: '45.00', effective_tax_percent: 25}, quantity: 3},
        {price: {effective_price: '60.00', effective_tax_percent: 15}, quantity: 2},
    ]);

// 9. An ordered line whose article has left the menu is still summed, so the dialog's total is the stored total.
same('9. an ordered Ølfri 2 no longer on the menu is still summed at its stored price',
    () => pricing.orderedPriceLines({16: 2}, [], [stored(16, '30.00', 25)]),
    [{price: {effective_price: '30.00', effective_tax_percent: 25}, quantity: 2}]);

// 10. An article with a quantity the order does not hold and the menu lacks is not summed.
same('10. an article with a quantity but neither stored nor on the menu is not summed',
    () => pricing.orderedPriceLines({99: 1}, [], []),
    []);

if (failures.length > 0) {
    for (const f of failures) {
        console.log(`FAIL ${f}`);
    }
    console.log(`TS catering keep-price harness: FAIL ${passed} of ${passed + failures.length} checks passed`);
    process.exit(1);
}
console.log(`TS catering keep-price harness: PASS ${passed} checks`);
