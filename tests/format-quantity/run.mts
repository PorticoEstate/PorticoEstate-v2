/**
 * Hermetic check of the ONE shared quantity formatter in the Next client (task 41367, GO 41355 on declaration
 * 41350): formatQuantity in utils/article-pricing.ts, which replaced three local copies and now also serves the
 * checkout article lines and the article table.
 *
 *   node tests/format-quantity/run.mts
 *
 * It loads a TEMP COPY of utils/article-pricing.ts (and cost-utils.ts) exactly as tests/incl-vat/run_summary.mts
 * does. CLIENT_SRC (optional) points at an alternative copy of the client's src/ tree: tests/format-quantity/
 * mutate.sh uses it to prove that a deliberately broken helper, or a call site that goes back to its own copy,
 * turns this harness red.
 *
 * What it holds the helper to:
 *   1. the input -> output table below, in THREE UI languages. The helper takes no language: nb-NO is hard-wired
 *      like formatCurrency, so «2,5» sits beside «81,25 kr» even on the English page. The table is therefore run
 *      three times, in child processes whose DEFAULT Intl locale is nb / nn / en (LC_ALL), and every column must
 *      carry the same text. A control proves the three runs really did start under different default locales, or
 *      the columns would be one measurement printed three times;
 *   2. the decimal separator of a quantity is the one formatCurrency prints (the cross-check against the real
 *      formatCurrency, not a second copy of «,»);
 *   3. the call sites (source patterns; the TSX is not executed here): the three components that carried their own
 *      copy import the shared one and define none, the checkout article line and the article table print through
 *      it, the type admits the string the API sends, and the client holds exactly ONE definition.
 *
 * Out of scope, by the GO: the dead sites (article-table.tsx `{item.quantity}`, hospitality-order-card.tsx) and
 * every lane/server change.
 *
 * Exit code: 0 all pass, 1 any failure, 2 the harness itself crashed (a crash is never a pass NOR a kill).
 */
import {spawnSync} from 'node:child_process';
import {mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const clientSrc = process.env.CLIENT_SRC ?? join(repo, 'src/modules/bookingfrontend/client/src');

/** [UI language, the LC_ALL its child process starts under, the default Intl locale tag that must result]. */
const LANGUAGES = [
    ['no', 'nb_NO.UTF-8', 'nb'],
    ['nn', 'nn_NO.UTF-8', 'nn'],
    ['en', 'en_US.UTF-8', 'en'],
] as const;

// ---- the table: written by hand, nothing derived from the code under test ---------------------------------
const rows: Array<[unknown, string]> = [
    ['3.00', '3'],           // the API's own shape: numeric(10,2) as a string
    [3, '3'],
    ['2.50', '2,5'],         // a stored fraction: comma, no trailing zero, NEVER rounded to «3»
    [2.5, '2,5'],
    ['2.25', '2,25'],        // two decimals kept
    ['2.504', '2,5'],        // at most two
    ['0.50', '0,5'],
    ['0.00', '0'],
    ['1800.00', '1800'],     // never grouped (nb-NO would otherwise print «1 800» with a no-break space)
    [12345.5, '12345,5'],
    [null, ''],              // nothing to show is nothing, never a made-up «0»
    [undefined, ''],
    ['', ''],
    ['   ', ''],
    ['abc', ''],
    [NaN, ''],
    [Infinity, ''],
];
const label = (value: unknown): string => (typeof value === 'string' ? JSON.stringify(value) : String(value));

// ---- CHILD: one UI language; prints one JSON line with what the helper returned --------------------------
const langArg = process.argv.find(arg => arg.startsWith('--lang='));
if (langArg) {
    const utils = join(clientSrc, 'utils');
    const dir = mkdtempSync(join(tmpdir(), 'format-quantity-'));
    try {
        const dropImports = (text: string) => text.split('\n').filter(line => !line.startsWith('import ')).join('\n');
        writeFileSync(join(dir, 'cost-utils.mts'), dropImports(readFileSync(join(utils, 'cost-utils.ts'), 'utf8')));
        writeFileSync(
            join(dir, 'article-pricing.mts'),
            `const DateTime: any = {};\nimport {formatAmount, formatCurrency} from './cost-utils.mts';\n`
            + dropImports(readFileSync(join(utils, 'article-pricing.ts'), 'utf8'))
        );
        const P: any = await import(pathToFileURL(join(dir, 'article-pricing.mts')).href);
        const C: any = await import(pathToFileURL(join(dir, 'cost-utils.mts')).href);
        const amount: string = C.formatCurrency(81.25);
        const separator = /\d([^\d])\d/.exec(amount)?.[1] ?? null;
        console.log(JSON.stringify({
            lang: langArg.slice('--lang='.length),
            defaultLocale: new Intl.NumberFormat().resolvedOptions().locale,
            got: rows.map(([value]) => P.formatQuantity(value)),
            amount,
            quantityBesideIt: P.formatQuantity('2.50'),
            separator,
        }));
    } catch (error) {
        console.error(`CRASH in the ${langArg} child: ${(error as Error).message}`);
        process.exitCode = 2;
    } finally {
        rmSync(dir, {recursive: true, force: true});
    }
    process.exit();
}

// ---- ORCHESTRATOR ----------------------------------------------------------------------------------------
let fail = 0;
let crashed = false;
const FAILED = (line: string) => {
    fail++;
    console.log(`FAIL ${line}`);
};

interface ChildReport {lang: string; defaultLocale: string; got: string[]; amount: string; quantityBesideIt: string; separator: string | null}
const reports: Record<string, ChildReport> = {};
for (const [code, locale, tag] of LANGUAGES) {
    const run = spawnSync(process.execPath, [fileURLToPath(import.meta.url), `--lang=${code}`], {
        encoding: 'utf8',
        env: {...process.env, LC_ALL: locale, LANG: locale, LANGUAGE: code, CLIENT_SRC: clientSrc},
    });
    if (run.status !== 0) {
        console.log(`CRASH ${code}: ${(run.stderr || `exit ${run.status}`).trim()}`);
        crashed = true;
        continue;
    }
    reports[code] = JSON.parse(run.stdout.trim().split('\n').pop() as string);
    // the control: the language columns are only independent if the three children started under different defaults
    if (!reports[code].defaultLocale.toLowerCase().startsWith(tag)) {
        FAILED(`control [${code}]: LC_ALL=${locale} gave the default Intl locale «${reports[code].defaultLocale}», not «${tag}…»; the language columns would be one run printed three times`);
    }
}
if (crashed) process.exit(2);

console.log(`${'input'.padEnd(12)} ${'expected'.padEnd(10)} ${LANGUAGES.map(([code]) => code.padEnd(10)).join(' ')}`);
rows.forEach(([value, expected], index) => {
    const cells: string[] = [];
    for (const [code] of LANGUAGES) {
        const got = reports[code].got[index];
        cells.push(`«${got}»`.padEnd(10));
        if (got !== expected) FAILED(`row ${label(value)} [${code}] got «${got}» want «${expected}»`);
    }
    console.log(`${label(value).padEnd(12)} ${`«${expected}»`.padEnd(10)} ${cells.join(' ')}`);
});

for (const [code] of LANGUAGES) {
    const r = reports[code];
    const shared = r.separator !== null && r.quantityBesideIt.includes(r.separator);
    console.log(`separator [${code}] formatCurrency(81.25) = «${r.amount}» (separator «${r.separator}»), formatQuantity('2.50') = «${r.quantityBesideIt}»`);
    if (!shared) FAILED(`separator [${code}] the quantity «${r.quantityBesideIt}» does not use formatCurrency's decimal separator «${r.separator}» («${r.amount}»)`);
}

// ---- the call sites (source patterns) --------------------------------------------------------------------
const read = (relative: string): string => readFileSync(join(clientSrc, relative), 'utf8');
const check = (name: string, ok: boolean) => {
    if (ok) console.log(`ok   ${name}`);
    else FAILED(name);
};
const DEFINITION = /\b(?:const|let|var|function)\s+formatQuantity\b/;
const IMPORT = /import\s*\{[^}]*\bformatQuantity\b[^}]*\}\s*from\s*'@\/utils\/article-pricing'/;

for (const file of [
    'components/user/application/application-price-lines.tsx',
    'components/user/application/catering-overview.tsx',
    'components/checkout/hospitality/hospitality-order-modal.tsx',
]) {
    const text = read(file);
    check(`site ${file}: no local formatQuantity`, !DEFINITION.test(text));
    check(`site ${file}: imports the shared formatQuantity`, IMPORT.test(text));
    check(`site ${file}: uses it`, /\bformatQuantity\(/.test(text));
}
const articlesSection = read('components/checkout/articles/articles-section.tsx');
check('site articles-section: imports the shared formatQuantity', IMPORT.test(articlesSection));
check('site articles-section: the line quantity prints through it', /formatMinutes\(line\.quantity, t\) : formatQuantity\(line\.quantity\)/.test(articlesSection));
const articleTable = read('components/article-table/article-table.tsx');
check('site article-table: imports the shared formatQuantity', IMPORT.test(articleTable));
check('site article-table: the selected quantity prints through it', /formatMinutes\(quantity, t\) : formatQuantity\(quantity\)/.test(articleTable));
const orderLine = /export interface IOrderLine \{[\s\S]*?\n\}/.exec(read('service/types/api/application.types.ts'))?.[0] ?? '';
check('type IOrderLine.quantity admits the string the API sends (number | string)', /^\s*quantity:\s*number \| string;$/m.test(orderLine));

const definitions: string[] = [];
const walk = (directory: string) => {
    for (const entry of readdirSync(directory, {withFileTypes: true})) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) walk(path);
        else if (/\.tsx?$/.test(entry.name) && DEFINITION.test(readFileSync(path, 'utf8'))) definitions.push(path.slice(clientSrc.length + 1));
    }
};
walk(clientSrc);
check(`exactly one definition of formatQuantity under src/ (found: ${definitions.join(', ') || 'none'})`, definitions.length === 1 && definitions[0] === 'utils/article-pricing.ts');

console.log(fail === 0 ? 'ALL PASS' : `${fail} FAILED`);
process.exit(fail === 0 ? 0 : 1);
