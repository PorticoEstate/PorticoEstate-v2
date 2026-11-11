/**
 * Hermetic check of the hours formatter of the Next client (task 42446, GO 42467 on declaration 42462):
 * formatHours(hours, lang, t) in utils/format-hours.ts, which prints the «2.5 t» of Min side's «Reserverte tider»
 * and «Forespurte tidsrom» (the plain list and the recurring series, which sit under the same heading) in the
 * UI language's own decimal separator and unit.
 *
 *   node tests/format-hours/run.mts
 *
 * It loads a TEMP COPY of utils/format-hours.ts and utils/hospitality-deadline.ts (the one language -> locale
 * mapping, localeFor), as tests/format-quantity/run.mts does. Two optional variables point it at alternative
 * copies: CLIENT_SRC (the client's src/ tree) and LANG_DIR (bookingfrontend/setup/, where the unit key is read
 * from the real .lang files); tests/format-hours/mutate.sh uses them to prove that a deliberately broken helper,
 * call site or .lang row turns this harness red.
 *
 * What it holds the helper to:
 *   1. the hours -> text table below, in THREE UI languages (no, nn, en), every expected string written by hand.
 *      The unit comes from the real phpgw_{no,nn,en}.lang (t, t, h), the way the client's t() resolves it;
 *   2. the LOCALE TAG the helper hands to Intl.NumberFormat, per language: no nb-NO, nn 'no', en en-GB. This is a
 *      separate row because Node cannot see the nn defect by OUTPUT: Node has Nynorsk data, so 'nn' and 'nn-NO'
 *      both print «2,5» here, while Chrome 157 resolves them to en-US and prints «2.5» (ledger #42455). Only the
 *      tag shows it. Said plainly: the mutant that sends nn to 'nn-NO' is killed by this row and by nothing else;
 *   3. the whole table is run in two child processes whose DEFAULT Intl locale is nb and en (LC_ALL), so a helper
 *      that forgets the locale and falls back to the default cannot pass on the machine's own luck. A control
 *      proves the two runs really did start under different defaults;
 *   4. the call sites (source patterns; the TSX is not executed here): the three Min side lists print through the
 *      helper with the language of useClientTranslation, no raw «{hours} t» is left anywhere, and the client
 *      holds exactly ONE definition of formatHours.
 *
 * Out of scope, by the GO: formatQuantity, formatCurrency, durationHours (it rounds to one decimal first) and
 * manage-modal.tsx, which localises its own «(2 h)» note.
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
const langDir = process.env.LANG_DIR ?? join(repo, 'src/modules/bookingfrontend/setup');

const NBSP = ' ';
const LANGUAGES = ['no', 'nn', 'en'] as const;
type Lang = typeof LANGUAGES[number];

/** [the LC_ALL a child starts under, the default Intl locale tag that must result]. */
const DEFAULTS = [
    ['nb_NO.UTF-8', 'nb'],
    ['en_US.UTF-8', 'en'],
] as const;

// ---- the expectations: written by hand, nothing derived from the code under test ----------------------------
const UNIT: Record<Lang, string> = {no: 't', nn: 't', en: 'h'};
const LOCALE_TAG: Record<Lang, string> = {no: 'nb-NO', nn: 'no', en: 'en-GB'};

/** [hours, the number in no and nn, the number in en] */
const rows: Array<[number, string, string]> = [
    [0.5, '0,5', '0.5'],
    [1, '1', '1'],            // whole hours carry no decimals
    [2.5, '2,5', '2.5'],      // the defect: «2.5 t» with a dot in every language
    [3, '3', '3'],
    [1.25, '1,25', '1.25'],   // two decimals kept
    [2.504, '2,5', '2.5'],    // at most two
    [1234.5, '1234,5', '1234.5'], // never grouped (nb-NO would otherwise print «1 234,5» with a no-break space)
];
const expected = (lang: Lang, row: [number, string, string]): string =>
    `${lang === 'en' ? row[2] : row[1]}${NBSP}${UNIT[lang]}`;

// ---- the unit as the real .lang files carry it (what the client's t() serves after a lang install) ----------
const langUnit = (lang: string): string | null => {
    for (const line of readFileSync(join(langDir, `phpgw_${lang}.lang`), 'utf8').split('\n')) {
        const [key, module, code, ...text] = line.replace(/\r$/, '').split('\t');
        if (key === 'hours_short' && module === 'bookingfrontend' && code === lang) return text.join('\t');
    }
    return null;
};
// the client's own miss text (app/i18n/settings.ts parseMissingKeyHandler)
const translator = (lang: string) => (key: string): string =>
    key === 'bookingfrontend.hours_short' ? (langUnit(lang) ?? `TRANSLATION MISSING FOR "${key}"`) : `TRANSLATION MISSING FOR "${key}"`;

// ---- CHILD: one default Intl locale; prints one JSON line with what the helper returned ---------------------
if (process.argv.includes('--child')) {
    const dir = mkdtempSync(join(tmpdir(), 'format-hours-'));
    try {
        const source = readFileSync(join(clientSrc, 'utils/format-hours.ts'), 'utf8');
        const IMPORT = "from './hospitality-deadline';";
        if (source.split(IMPORT).length !== 2) throw new Error(`utils/format-hours.ts must contain ${IMPORT} exactly once`);
        writeFileSync(join(dir, 'format-hours.mts'), source.replace(IMPORT, "from './hospitality-deadline.mts';"));
        writeFileSync(join(dir, 'hospitality-deadline.mts'), readFileSync(join(clientSrc, 'utils/hospitality-deadline.ts'), 'utf8'));
        const moduleUrl = pathToFileURL(join(dir, 'format-hours.mts')).href;

        const defaultLocale = new Intl.NumberFormat().resolvedOptions().locale;
        // Record the locale argument of every NumberFormat the helper builds: the only way to see 'nn-NO' from Node.
        let seen: unknown[] = [];
        (Intl as any).NumberFormat = new Proxy(Intl.NumberFormat, {
            construct(target, args, newTarget) {
                seen.push(args[0]);
                return Reflect.construct(target, args, newTarget);
            },
        });
        // a fresh module instance per run: the helper keeps one formatter per locale
        const run = async (tag: string, lang: unknown, t: (key: string) => string, hours: number[]) => {
            seen = [];
            const helper: any = await import(`${moduleUrl}?run=${tag}`);
            return {got: hours.map(h => helper.formatHours(h, lang, t)), locales: [...new Set(seen.map(String))]};
        };
        const languages: Record<string, unknown> = {};
        for (const lang of LANGUAGES) languages[lang] = await run(lang, lang, translator(lang), rows.map(([hours]) => hours));
        const noLanguage = await run('no-language', undefined, translator('no'), [2.5]);
        console.log(JSON.stringify({defaultLocale, languages, noLanguage}));
    } catch (error) {
        console.error(`CRASH in the child: ${(error as Error).message}`);
        process.exitCode = 2;
    } finally {
        rmSync(dir, {recursive: true, force: true});
    }
    process.exit();
}

// ---- ORCHESTRATOR ------------------------------------------------------------------------------------------
let fail = 0;
let crashed = false;
const FAILED = (line: string) => {
    fail++;
    console.log(`FAIL ${line}`);
};

interface Run {got: string[]; locales: string[]}
interface ChildReport {defaultLocale: string; languages: Record<Lang, Run>; noLanguage: Run}
const reports: Array<{defaultTag: string; report: ChildReport}> = [];
for (const [locale, tag] of DEFAULTS) {
    const child = spawnSync(process.execPath, [fileURLToPath(import.meta.url), '--child'], {
        encoding: 'utf8',
        env: {...process.env, LC_ALL: locale, LANG: locale, LANGUAGE: tag, CLIENT_SRC: clientSrc, LANG_DIR: langDir},
    });
    if (child.status !== 0) {
        console.log(`CRASH (default ${tag}): ${(child.stderr || `exit ${child.status}`).trim()}`);
        crashed = true;
        continue;
    }
    const report: ChildReport = JSON.parse(child.stdout.trim().split('\n').pop() as string);
    reports.push({defaultTag: tag, report});
    // the control: the two runs are only independent if they started under different defaults
    if (!report.defaultLocale.toLowerCase().startsWith(tag)) {
        FAILED(`control: LC_ALL=${locale} gave the default Intl locale «${report.defaultLocale}», not «${tag}…»; the two default-locale runs would be one run printed twice`);
    }
}
if (crashed) process.exit(2);

// 0. the unit keys, from the real .lang files
for (const lang of LANGUAGES) {
    const unit = langUnit(lang);
    console.log(`lang key [${lang}] bookingfrontend.hours_short = «${unit}»`);
    if (unit !== UNIT[lang]) FAILED(`lang key [${lang}] hours_short is «${unit}», want «${UNIT[lang]}»`);
}

// 1. the table
console.log(`${'lang'.padEnd(5)} ${'hours'.padEnd(8)} ${'expected'.padEnd(12)} ${reports.map(r => `default ${r.defaultTag}`.padEnd(14)).join(' ')}`);
for (const lang of LANGUAGES) {
    rows.forEach((row, index) => {
        const cells: string[] = [];
        for (const {defaultTag, report} of reports) {
            const got = report.languages[lang].got[index];
            cells.push(`«${got}»`.padEnd(14));
            if (got !== expected(lang, row)) FAILED(`row ${row[0]} [${lang}] (default ${defaultTag}) got «${got}» want «${expected(lang, row)}»`);
        }
        console.log(`${lang.padEnd(5)} ${String(row[0]).padEnd(8)} ${`«${expected(lang, row)}»`.padEnd(12)} ${cells.join(' ')}`);
    });
}
for (const {defaultTag, report} of reports) {
    const got = report.noLanguage.got[0];
    console.log(`no language given (undefined): «${got}» (default ${defaultTag}), locale ${report.noLanguage.locales.join(',')}`);
    if (got !== expected('no', rows[2])) FAILED(`row 2.5 [lang undefined] (default ${defaultTag}) got «${got}» want «${expected('no', rows[2])}»`);
}

// 2. the locale tag the helper hands to Intl: the one row that sees nn -> 'nn-NO' from Node
for (const lang of LANGUAGES) {
    for (const {defaultTag, report} of reports) {
        const locales = report.languages[lang].locales;
        const ok = locales.length === 1 && locales[0] === LOCALE_TAG[lang];
        console.log(`locale [${lang}] (default ${defaultTag}) Intl.NumberFormat built with «${locales.join(', ')}», want «${LOCALE_TAG[lang]}»`);
        if (!ok) FAILED(`locale [${lang}] (default ${defaultTag}) Intl.NumberFormat was built with «${locales.join(', ')}», want only «${LOCALE_TAG[lang]}»`);
    }
}
// the control for 2: the three languages must reach THREE tags that differ, or the row would pin nothing
const tags = new Set(LANGUAGES.map(lang => reports[0].report.languages[lang].locales.join(',')));
if (tags.size !== LANGUAGES.length) FAILED(`control: the three languages reach ${tags.size} locale tags (${[...tags].join(', ')}), not three`);

// 3. the call sites (source patterns)
const read = (relative: string): string => readFileSync(join(clientSrc, relative), 'utf8');
const check = (name: string, ok: boolean) => {
    if (ok) console.log(`ok   ${name}`);
    else FAILED(name);
};
const SITE = 'components/user/application/application-details.tsx';
const details = read(SITE);
check(`site ${SITE}: imports formatHours from '@/utils/format-hours'`, /import\s*\{[^}]*\bformatHours\b[^}]*\}\s*from\s*["']@\/utils\/format-hours["']/.test(details));
check(`site ${SITE}: exactly three renders print through formatHours(hours, i18n.language, t)`, (details.match(/\{formatHours\(hours, i18n\.language, t\)\}/g) ?? []).length === 3);

const RAW = /\{hours\} t\b/;
const definitions: string[] = [];
const raw: string[] = [];
const walk = (directory: string) => {
    for (const entry of readdirSync(directory, {withFileTypes: true})) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) walk(path);
        else if (/\.tsx?$/.test(entry.name)) {
            const text = readFileSync(path, 'utf8');
            if (/\b(?:const|let|var|function)\s+formatHours\b/.test(text)) definitions.push(path.slice(clientSrc.length + 1));
            if (RAW.test(text)) raw.push(path.slice(clientSrc.length + 1));
        }
    }
};
walk(clientSrc);
check(`no raw «{hours} t» render left under src/ (found: ${raw.join(', ') || 'none'})`, raw.length === 0);
check(`exactly one definition of formatHours under src/ (found: ${definitions.join(', ') || 'none'})`, definitions.length === 1 && definitions[0] === 'utils/format-hours.ts');

// each of the three lists must READ the language: the component's own text carries the hook and the render
const componentText = (name: string): string => {
    const start = details.search(new RegExp(`^const ${name}: FC`, 'm'));
    if (start < 0) return '';
    const next = details.slice(start + 1).search(/\n(?:const|function|export|interface|type) /);
    return next < 0 ? details.slice(start) : details.slice(start, start + 1 + next);
};
for (const name of ['DatesList', 'RecurringSeriesList', 'ReservedTimesList']) {
    const text = componentText(name);
    check(`site ${name}: reads the UI language (useClientTranslation) and prints through formatHours`,
        /const \{[^}]*\bi18n\b[^}]*\} = useClientTranslation\(\)/.test(text) && /\{formatHours\(hours, i18n\.language, t\)\}/.test(text));
}

console.log(fail === 0 ? 'ALL PASS' : `${fail} FAILED`);
process.exit(fail === 0 ? 0 : 1);
