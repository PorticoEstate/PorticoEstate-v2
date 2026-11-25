/**
 * TypeScript side of the catering keep-price harness (operator ruling D5 = a, forum/pe-general/50372): the price each ordered
 * article is shown at in the catering dialog, and the dialog's TOTAL against the stored total after a save.
 *
 *   node tests/catering-keep-price/run.mts
 *
 * The client modules are NOT copied by hand. The harness transpiles the ones it needs with the client's own TypeScript, into a
 * mirror: «@/» imports are pointed at the mirror, relative imports stay relative, and packages (luxon) resolve through a symlink
 * to the client's node_modules. Types are erased by the transpiler, so the mirror needs no type stubs.
 *
 * Environment (all optional):
 *   CLIENT_DIR     the client directory to test (default: this repo's)
 *   CLIENT_MODULES its node_modules (default: CLIENT_DIR/node_modules)
 *   PRICING_TS     an alternative hospitality-order-pricing.ts, used in place of the client's (mutants)
 *
 * On a tree WITHOUT the pricing module every row that uses it fails: that is this side's red on base.
 * Exit code: 0 all pass, 1 any failure, 2 the mirror cannot be built.
 */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {existsSync, mkdirSync, mkdtempSync, readFileSync, statSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, relative, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const clientDir = process.env.CLIENT_DIR ?? join(root, 'src/modules/bookingfrontend/client');
const modulesDir = process.env.CLIENT_MODULES ?? join(clientDir, 'node_modules');
const srcRoot = join(clientDir, 'src');
const pricingSource = join(srcRoot, 'utils/hospitality-order-pricing.ts');

// The client's own TypeScript, resolved from its node_modules.
const ts: any = createRequire(join(modulesDir, '..', 'package.json'))('typescript');

// --- the mirror ----------------------------------------------------------------------------------------
const dest = mkdtempSync(join(tmpdir(), 'kp-ts-'));
symlinkSync(modulesDir, join(dest, 'node_modules'), 'dir');
const mirrorOf = (src: string): string => join(dest, relative(srcRoot, src).replace(/\.tsx?$/, '.mjs'));
const resolveLocal = (spec: string, from: string): string | null => {
    const base = spec.startsWith('@/') ? join(srcRoot, spec.slice(2)) : resolve(dirname(from), spec);
    for (const candidate of [base, `${base}.ts`, `${base}.tsx`, join(base, 'index.ts'), join(base, 'index.tsx')]) {
        if (existsSync(candidate) && statSync(candidate).isFile()) return candidate;
    }
    return null;
};
const built = new Set<string>();
const build = (src: string): void => {
    if (built.has(src)) return;
    built.add(src);
    const text = src === pricingSource && process.env.PRICING_TS
        ? readFileSync(process.env.PRICING_TS, 'utf8')
        : readFileSync(src, 'utf8');
    const js: string = ts.transpileModule(text, {
        fileName: src,
        compilerOptions: {module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2020, jsx: ts.JsxEmit.ReactJSX},
    }).outputText;
    const rewritten = js.replace(/(\bfrom\s*|\bimport\s*)(['"])([^'"]+)\2/g, (whole: string, lead: string, quote: string, spec: string) => {
        if (!spec.startsWith('@/') && !spec.startsWith('.')) return whole;
        const target = resolveLocal(spec, src);
        if (target === null) throw new Error(`${src}: cannot resolve «${spec}»`);
        build(target);
        let rel = relative(dirname(mirrorOf(src)), mirrorOf(target));
        if (!rel.startsWith('.')) rel = `./${rel}`;
        return `${lead}${quote}${rel}${quote}`;
    });
    mkdirSync(dirname(mirrorOf(src)), {recursive: true});
    writeFileSync(mirrorOf(src), rewritten);
};

let pricing: any = null;
let summary: any = null;
let articlePricing: any = null;
try {
    build(join(srcRoot, 'utils/article-pricing.ts'));
    build(join(srcRoot, 'utils/hospitality-price-summary.ts'));
    summary = await import(pathToFileURL(mirrorOf(join(srcRoot, 'utils/hospitality-price-summary.ts'))).href);
    articlePricing = await import(pathToFileURL(mirrorOf(join(srcRoot, 'utils/article-pricing.ts'))).href);
} catch (error) {
    console.log(`mirror: the summary and article pricing modules do not build: ${(error as Error).message}`);
    process.exit(2);
}
try {
    build(pricingSource);
    pricing = await import(pathToFileURL(mirrorOf(pricingSource)).href);
} catch (error) {
    console.log(`FAIL the pricing module does not load from ${pricingSource}: ${(error as Error).message.split('\n')[0]}`);
}

// --- assertions ----------------------------------------------------------------------------------------
const verbose = Boolean(process.env.KP_VERBOSE);
const failures: string[] = [];
let passed = 0;
const show = (value: unknown): string => JSON.stringify(value, (_k, v) => (typeof v === 'number' && !Number.isFinite(v) ? String(v) : v))?.slice(0, 400) ?? 'undefined';
/** `want` may be a thunk: an expectation that reads the module is then evaluated inside the row, so a missing module fails the row. */
const same = (name: string, got: () => unknown, want: unknown): void => {
    let value: unknown;
    let expected: unknown;
    try {
        value = got();
        expected = typeof want === 'function' ? (want as () => unknown)() : want;
        assert.deepStrictEqual(value, expected);
        passed++;
        if (verbose) console.log(`ROW PASS ${name}`);
    } catch (error) {
        const detail = value === undefined
            ? `${name} — threw: ${(error as Error).message.split('\n')[0]}`
            : `${name} — got ${show(value)} want ${show(expected)}`;
        failures.push(detail);
        if (verbose) console.log(`ROW FAIL ${detail}`);
    }
};

// --- fixtures ------------------------------------------------------------------------------------------
/** A menu article as the dialog holds it (EX VAT price as a decimal string, VAT % or null). */
const menu = (id: number, price: string, tax: number | null): any => ({
    id, hospitality_id: 3, article_group_id: null, article_mapping_id: id, description: null, sort_order: 0, active: 1,
    article_name: `A${id}`, article_code: `${id}`, service_name_json: null, service_description_json: null, unit: 'stk',
    base_price: price, base_tax_code: 1, effective_price: price, effective_tax_code: 1, effective_tax_percent: tax,
});
const codeOf = (percent: number): number => ({0: 0, 25: 1, 15: 2} as Record<number, number>)[percent];
/** A stored order line as GET returns it: unit_price EX VAT, tax_percent the VAT % of its tax code, amount EX VAT. */
const stored = (articleId: number, quantity: string, unit: string, percent: number, amount: string): any => ({
    id: 400 + articleId, order_id: 7, hospitality_article_id: articleId, quantity, unit_price: unit,
    tax_code: codeOf(percent), tax_percent: percent, amount, comment: null, article_name: `A${articleId}`, unit: 'stk',
});

/** The menu after the officer's changes: Kaffe 45 → 60, Kake 100 → 130, Vann 0 % → 25 %, the Te override removed.
 *  Ølfri (deactivated) and Nopris (price row gone) are no longer on the menu. */
const menuMoved = [menu(11, '60.00', 25), menu(12, '130.00', 25), menu(13, '20.00', 25), menu(14, '60.00', 15), menu(15, '80.00', 25)];

/** The order as it was placed, after the menu moved: the lines a save leaves untouched. */
const stateA = [
    stored(11, '3.00', '45.00', 25, '135.00'),
    stored(12, '2.00', '100.00', 25, '200.00'),
    stored(13, '1.00', '20.00', 0, '20.00'),
    stored(15, '1.00', '77.70', 15, '77.70'),
    stored(16, '2.00', '30.00', 25, '60.00'),
    stored(17, '1.00', '50.00', 25, '50.00'),
];
/** After the applicant saves Kake 2 → 3. */
const stateB = stateA.map((l: any) => (l.hospitality_article_id === 12 ? stored(12, '3.00', '100.00', 25, '300.00') : l));
/** After the applicant saves a new Brød line, 2 at today's 60,00 and 15 %. */
const stateC = [...stateB, stored(14, '2.00', '60.00', 15, '120.00')];

/** The quantities the dialog opens with: one per stored article, as the modal's preload reads them. */
const quantitiesOf = (lines: any[]): Record<number, number> =>
    Object.fromEntries(lines.map((l: any) => [l.hospitality_article_id, parseFloat(l.quantity)]));

/** The stored total in øre: each line's amount plus its VAT, the VAT rounded once to the øre (the billing rule). */
const storedTotalOre = (lines: any[]): number =>
    lines.reduce((sum: number, l: any) => {
        const amountOre = Math.round(Number(l.amount) * 100);
        return sum + amountOre + Math.round((amountOre * l.tax_percent) / 100);
    }, 0);

/** The dialog's total incl. VAT in øre, as the modal computes it: quantities × the price each article is held at. */
const dialogTotalOre = (menuArticles: any[], lines: any[], quantities: Record<number, number>): number | null => {
    const s = summary.summarizeHospitalityVat(
        pricing.orderedDialogRows(quantities, menuArticles, lines)
            .map((row: any) => summary.hospitalityMenuLineForSummary(row.price, row.quantity))
    );
    return s.known ? s.inclOre : null;
};

// 1–10. The price each ordered article is shown and summed at.
same('1. Kaffe ordered at 45.00 keeps 45.00 when the menu says 60.00',
    () => pricing.orderedPriceOf(11, [menu(11, '60.00', 25)], [stored(11, '3.00', '45.00', 25, '135.00')]),
    {effective_price: '45.00', effective_tax_percent: 25});
same('2. Vann ordered at 0 % keeps 0 % when the menu says 25 %',
    () => pricing.orderedPriceOf(13, [menu(13, '20.00', 25)], [stored(13, '1.00', '20.00', 0, '20.00')]),
    {effective_price: '20.00', effective_tax_percent: 0});
same('3. Te ordered at 77.70 / 15 % keeps both when the menu says 80.00 / 25 %',
    () => pricing.orderedPriceOf(15, [menu(15, '80.00', 25)], [stored(15, '1.00', '77.70', 15, '77.70')]),
    {effective_price: '77.70', effective_tax_percent: 15});
same('4. a new Brød line takes the menu price 60.00 at 15 %',
    () => pricing.orderedPriceOf(14, [menu(14, '60.00', 15)], [stored(11, '3.00', '45.00', 25, '135.00')]),
    {effective_price: '60.00', effective_tax_percent: 15});
same('5. an ordered Ølfri no longer on the menu is priced at its stored 30.00 / 25 %',
    () => pricing.orderedPriceOf(16, [], [stored(16, '2.00', '30.00', 25, '60.00')]),
    {effective_price: '30.00', effective_tax_percent: 25});
same('6. a stored line whose VAT code has no row stays null, not 0 %',
    () => pricing.orderedPriceOf(17, [], [stored(17, '1.00', '50.00', null as any, '50.00')]),
    {effective_price: '50.00', effective_tax_percent: null});
same('7. an article the order does not hold and the menu does not have: null',
    () => pricing.orderedPriceOf(99, [menu(11, '45.00', 25)], []),
    null);
same('8. Kaffe 3 (stored 45.00) and Brød 2 (new, 60.00 / 15 %); Kake 0 is not summed',
    () => pricing.orderedPriceLines(
        {11: 3, 12: 0, 14: 2},
        [menu(11, '60.00', 25), menu(12, '100.00', 25), menu(14, '60.00', 15)],
        [stored(11, '3.00', '45.00', 25, '135.00')]
    ),
    [
        {price: {effective_price: '45.00', effective_tax_percent: 25}, quantity: 3},
        {price: {effective_price: '60.00', effective_tax_percent: 15}, quantity: 2},
    ]);
same('9. an ordered Ølfri 2 no longer on the menu is still summed at its stored price',
    () => pricing.orderedPriceLines({16: 2}, [], [stored(16, '2.00', '30.00', 25, '60.00')]),
    [{price: {effective_price: '30.00', effective_tax_percent: 25}, quantity: 2}]);
same('10. an article with a quantity but neither stored nor on the menu is not summed',
    () => pricing.orderedPriceLines({99: 1}, [], []),
    []);

// 11–16. The dialog's TOTAL equals the stored total: at each state the order is saved in, and while the applicant edits it.
// The hand-written figures are øre, worked line by line from the amounts above: 66 561, 79 061, 92 861.
same('11. the stored total of state A (menu moved, every line kept) is 66 561 øre (665,61 kr)',
    () => storedTotalOre(stateA), 66561);
same('11. the dialog total when opened on state A, with the menu moved, equals the stored total',
    () => dialogTotalOre(menuMoved, stateA, quantitiesOf(stateA)), storedTotalOre(stateA));
same('11b. Kaffe in that dialog: 3 × 56,25 kr (ordered at 45,00 + 25 %), 16 875 øre, not the menu\'s 75,00',
    () => [articlePricing.hospitalityUnitPriceIncl(pricing.orderedPriceOf(11, menuMoved, stateA)),
        articlePricing.hospitalityMenuLineTotalOre(pricing.orderedPriceOf(11, menuMoved, stateA), 3)],
    [56.25, 16875]);
same('12. the stored total of state B (Kake 2 → 3 saved) is 79 061 øre',
    () => storedTotalOre(stateB), 79061);
same('12. editing Kake to 3 before saving: the dialog total is the stored total of state B',
    () => dialogTotalOre(menuMoved, stateA, {...quantitiesOf(stateA), 12: 3}), storedTotalOre(stateB));
same('13. after saving state B, the dialog total equals the stored total',
    () => dialogTotalOre(menuMoved, stateB, quantitiesOf(stateB)), storedTotalOre(stateB));
same('14. the stored total of state C (plus Brød 2 at 60,00 / 15 %) is 92 861 øre',
    () => storedTotalOre(stateC), 92861);
same('14. editing in a new Brød 2 before saving: the dialog total is the stored total of state C',
    () => dialogTotalOre(menuMoved, stateB, {...quantitiesOf(stateB), 14: 2}), storedTotalOre(stateC));
same('15. after saving state C, the dialog total equals the stored total',
    () => dialogTotalOre(menuMoved, stateC, quantitiesOf(stateC)), storedTotalOre(stateC));

// 16–18. An ordered line whose article has left the menu is LISTED read-only, and the dialog's total is the sum of the listed rows.
// Ølfri (16) is ordered 2 × 30,00 at 25 %: 7 500 øre incl. VAT. Menu rows are listed with onMenu true.
const rowsA = (): any[] => pricing.orderedDialogRows(quantitiesOf(stateA), menuMoved, stateA);
// Off the menu in state A: Ølfri (16, deactivated) and Nopris (17, price row gone).
same('16. the dialog lists the ordered Ølfri and Nopris, off the menu, read-only: name, quantity and stored price',
    () => rowsA().filter((row: any) => !row.onMenu),
    [
        {articleId: 16, name: 'A16', quantity: 2, onMenu: false, price: {effective_price: '30.00', effective_tax_percent: 25}},
        {articleId: 17, name: 'A17', quantity: 1, onMenu: false, price: {effective_price: '50.00', effective_tax_percent: 25}},
    ]);
same('16. the on-menu rows are the ordered articles still on the menu',
    () => rowsA().filter((row: any) => row.onMenu).map((row: any) => row.articleId),
    [11, 12, 13, 15]);
same('17. the listed rows add up to the dialog total, and that is the stored total (66 561 øre)',
    () => rowsA().reduce((sum: number, row: any) => sum + articlePricing.hospitalityMenuLineTotalOre(row.price, row.quantity), 0),
    storedTotalOre(stateA));
same('17. the dialog total on state A equals the sum of the listed rows',
    () => dialogTotalOre(menuMoved, stateA, quantitiesOf(stateA)),
    () => rowsA().reduce((sum: number, row: any) => sum + articlePricing.hospitalityMenuLineTotalOre(row.price, row.quantity), 0));
same('18. taking Ølfri to 0 removes its row and its charge: the total is 66 561 − 7 500 = 59 061 øre',
    () => [pricing.orderedDialogRows({...quantitiesOf(stateA), 16: 0}, menuMoved, stateA).some((row: any) => row.articleId === 16),
        dialogTotalOre(menuMoved, stateA, {...quantitiesOf(stateA), 16: 0})],
    [false, 59061]);

// Information, not a row: what the dialog as it stands on base would total for state A (it sums menu prices only).
if (summary && articlePricing) {
    const q = quantitiesOf(stateA);
    const menuOnly = summary.summarizeHospitalityVat(
        menuMoved.filter((a: any) => (q[a.id] || 0) > 0).map((a: any) => summary.hospitalityMenuLineForSummary(a, q[a.id]))
    );
    console.log(`OBSERVED menu-only total for state A (what the base dialog sums): ${menuOnly.known ? menuOnly.inclOre : '-'} øre, stored ${storedTotalOre(stateA)} øre`);
}

if (failures.length > 0) {
    for (const f of failures) {
        console.log(`FAIL ${f}`);
    }
    console.log(`TS catering keep-price harness: FAIL ${passed} of ${passed + failures.length} checks passed`);
    process.exit(1);
}
console.log(`TS catering keep-price harness: PASS ${passed} checks`);
