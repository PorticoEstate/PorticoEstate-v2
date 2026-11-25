/**
 * Hermetic check of the date texts of the checkout's catering section in the UI language (task 47995, item 6a):
 * formatDayMonth, formatDayMonthTime and formatDeadlineDateTime in utils/hospitality-deadline.ts, next to localeFor,
 * the client's one language -> locale mapping. On /en the section used to print «24. nov.», «01. des. 2026, 12:00»
 * and, in the order dialog, «Ons. 23. desember», because every site hard-wired nb-NO.
 *
 *   node tests/checkout-dates/run.mts
 *   CD_ROOT=<a tree holding src/…> node tests/checkout-dates/run.mts      (the mutation arm, and the RED arm on base)
 *
 * What it holds the code to:
 *   1. the date -> text table below, in THREE UI languages, every expected string written by hand (no, nn, en);
 *   2. NORWEGIAN IS UNCHANGED: for 2 × 365 days at 3 times of day, `no` and `nn` print exactly what the old
 *      `toLocaleDateString('nb-NO', …)` expressions printed (the ruling: no stays byte-identical);
 *   3. the table is run in two child processes whose DEFAULT Intl locale is nb and en (LC_ALL), so a helper that
 *      forgets its locale cannot pass on the machine's own luck; a control proves the two really differ;
 *   4. the language -> locale tag: no nb-NO, nn 'no', en en-GB, anything else nb-NO;
 *   5. the call sites (source patterns; the TSX is not executed here): the section and the order dialog hold no
 *      'nb-NO' literal and call the helpers with the UI language; the client holds ONE definition of each helper.
 *
 * The zone is pinned to the venue's: the helpers print the browser-local clock, as every caller always did.
 * Exit code: 0 all pass, 1 any failure, 2 the harness itself crashed.
 */
process.env.TZ = 'Europe/Oslo';

import {spawnSync} from 'node:child_process';
import {mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.CD_ROOT ?? resolve(here, '../..');
const client = join(root, 'src/modules/bookingfrontend/client/src');
const section = join(client, 'components/checkout/hospitality/hospitality-section.tsx');
const modal = join(client, 'components/checkout/hospitality/hospitality-order-modal.tsx');

const LANGUAGES = ['no', 'nn', 'en'] as const;
type Lang = typeof LANGUAGES[number];

/** [an instant (the venue clock), the day+month, day+month+time, the deadline text]: per language no, nn, en. */
const D1 = '2026-11-24T12:00:00+01:00';
const D2 = '2026-12-03T12:00:00+01:00';
const D3 = '2026-12-01T12:00:00+01:00';
const D4 = '2027-01-05T09:05:00+01:00';
const EXPECT: Record<string, Record<Lang, string>> = {
    [`dayMonth ${D1}`]: {no: '24. nov.', nn: '24. nov.', en: '24 Nov'},
    [`dayMonth ${D2}`]: {no: '03. des.', nn: '03. des.', en: '3 Dec'},          // en: no zero-padded day
    [`dayMonth ${D4}`]: {no: '05. jan.', nn: '05. jan.', en: '5 Jan'},
    [`dayMonthTime ${D2}`]: {no: '03. des. 12:00', nn: '03. des. 12:00', en: '3 Dec 12:00'},
    [`dayMonthTime ${D4}`]: {no: '05. jan. 09:05', nn: '05. jan. 09:05', en: '5 Jan 09:05'},   // the clock is 24 h, padded, everywhere
    [`deadline ${D3}`]: {no: '01. des. 2026, 12:00', nn: '01. des. 2026, 12:00', en: '1 Dec 2026, 12:00'},
    [`deadline ${D4}`]: {no: '05. jan. 2027, 09:05', nn: '05. jan. 2027, 09:05', en: '5 Jan 2027, 09:05'},
};
const TAGS: Array<[string, string]> = [['no', 'nb-NO'], ['nn', 'no'], ['en', 'en-GB'], ['xx', 'nb-NO']];

// ---- CHILD: one default Intl locale; prints one JSON line --------------------------------------------------
if (process.argv.includes('--child')) {
    const dir = mkdtempSync(join(tmpdir(), 'checkout-dates-'));
    try {
        writeFileSync(join(dir, 'hospitality-deadline.mts'), readFileSync(join(client, 'utils/hospitality-deadline.ts'), 'utf8'));
        const h: any = await import(pathToFileURL(join(dir, 'hospitality-deadline.mts')).href);
        const defaultLocale = new Intl.DateTimeFormat().resolvedOptions().locale;
        const missing = ['formatDayMonth', 'formatDayMonthTime', 'formatDeadlineDateTime', 'localeFor'].filter(name => typeof h[name] !== 'function');
        if (missing.length) {   // a tree without the helpers (base): a failed check in the parent, not a crash
            console.log(JSON.stringify({defaultLocale, missing}));
            process.exit(0);
        }
        const got: Record<string, Record<string, string>> = {};
        for (const key of Object.keys(EXPECT)) {
            const [kind, iso] = key.split(' ');
            const date = new Date(iso);
            got[key] = {};
            for (const lang of LANGUAGES) {
                got[key][lang] = kind === 'dayMonth' ? h.formatDayMonth(date, lang)
                    : kind === 'dayMonthTime' ? h.formatDayMonthTime(date, lang)
                    : h.formatDeadlineDateTime(date, lang);
            }
        }
        // NORWEGIAN UNCHANGED: the old expressions, byte for byte
        let compared = 0;
        const different: string[] = [];
        for (let day = 0; day < 730; day++) {
            for (const hour of [0, 9, 23]) {
                const date = new Date(Date.UTC(2026, 0, 1 + day, hour, 7));
                const oldDay = date.toLocaleDateString('nb-NO', {day: '2-digit', month: 'short'});
                const oldDeadline = date.toLocaleDateString('nb-NO', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'});
                for (const lang of ['no', 'nn']) {
                    compared++;
                    if (h.formatDayMonth(date, lang) !== oldDay) different.push(`dayMonth ${lang} ${date.toISOString()}`);
                    if (h.formatDeadlineDateTime(date, lang) !== oldDeadline) different.push(`deadline ${lang} ${date.toISOString()}`);
                }
            }
        }
        const tags = Object.fromEntries(TAGS.map(([lang]) => [lang, h.localeFor(lang)]));
        console.log(JSON.stringify({defaultLocale, got, compared, different: different.slice(0, 5), nDifferent: different.length, tags}));
    } finally {
        rmSync(dir, {recursive: true, force: true});
    }
    process.exit(0);
}

// ---- PARENT -------------------------------------------------------------------------------------------------
let n = 0;
let fail = 0;
const check = (name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) { fail++; console.log(`FAIL ${name} ${detail}`); }
};

try {
    const runs = [['nb_NO.UTF-8', 'nb'], ['en_US.UTF-8', 'en']].map(([lc, tag]) => {
        const p = spawnSync(process.execPath, [fileURLToPath(import.meta.url), '--child'], {
            env: {...process.env, LC_ALL: lc, LANG: lc, CD_ROOT: root}, encoding: 'utf8',
        });
        if (p.status !== 0) throw new Error(`child ${lc} failed: ${p.stderr || p.stdout}`);
        const out = JSON.parse(p.stdout.trim().split('\n').pop() as string);
        return {lc, tag, out};
    });

    check('control: the two child runs really started under DIFFERENT default locales',
        runs[0].out.defaultLocale !== runs[1].out.defaultLocale, `${runs[0].out.defaultLocale} vs ${runs[1].out.defaultLocale}`);
    const missing: string[] = runs[0].out.missing ?? [];
    check('the helpers exist in utils/hospitality-deadline.ts', missing.length === 0, `missing: ${missing.join(', ')}`);
    if (missing.length === 0) {
        check('the table reads the same under either default locale', JSON.stringify(runs[0].out.got) === JSON.stringify(runs[1].out.got));

        for (const [key, byLang] of Object.entries(EXPECT)) {
            for (const lang of LANGUAGES) {
                const gotText = runs[0].out.got[key][lang];
                check(`${key} in ${lang}`, gotText === byLang[lang], `got «${gotText}», want «${byLang[lang]}»`);
            }
        }
        check('Norwegian is byte-identical to the old nb-NO expressions (no, nn; 2 × 365 days × 3 times)',
            runs[0].out.nDifferent === 0 && runs[0].out.compared === 730 * 3 * 2, `${runs[0].out.nDifferent} differ of ${runs[0].out.compared}: ${JSON.stringify(runs[0].out.different)}`);
        for (const [lang, tag] of TAGS) {
            check(`locale tag: ${lang} → ${tag}`, runs[0].out.tags[lang] === tag, `got ${runs[0].out.tags[lang]}`);
        }
    }

    // ---- the call sites ----
    const sectionSrc = readFileSync(section, 'utf8');
    const modalSrc = readFileSync(modal, 'utf8');
    check('call sites: no hard-wired nb-NO in the section', !/nb-NO/.test(sectionSrc));
    check('call sites: no hard-wired nb-NO in the order dialog', !/nb-NO/.test(modalSrc));
    // Since task 50144 (GH #1478) the section lists its orders under day headings, built in venue time by
    // checkoutCateringDays → groupByServingDay (tests/hospitality-deadline/run_serving_days.mts holds the text); the
    // call site must hand it the UI language.
    check('call sites: the section\'s serving days take the UI language',
        /checkoutCateringDays\(orders, lang, /.test(sectionSrc) && /const lang = i18n\.language;/.test(sectionSrc));
    check('call sites: the section\'s notice dates take the UI language',
        /formatDeadlineDateTime\(cancelBy, lang\)/.test(sectionSrc) && /formatDayMonthTime\(anchor, lang\)/.test(sectionSrc));
    check('call sites: the dialog\'s deadline text takes the UI language', /formatDeadlineDateTime\(cancelBy, i18n\.language\)/.test(modalSrc));
    check('call sites: the dialog\'s day labels take the UI language',
        /formatDayLabel\(from, lang\)/.test(modalSrc) && /formatDayLabel\(stored, i18n\.language\)/.test(modalSrc)
        && /toLocaleDateString\(locale, \{weekday: 'short'/.test(modalSrc));

    // the client holds exactly ONE definition of each helper
    const definitions: Record<string, number> = {formatDayMonth: 0, formatDayMonthTime: 0, formatDeadlineDateTime: 0};
    const walk = (dirPath: string) => {
        for (const entry of readdirSync(dirPath, {withFileTypes: true})) {
            const full = join(dirPath, entry.name);
            if (entry.isDirectory()) { if (entry.name !== 'node_modules') walk(full); continue; }
            if (!/\.(ts|tsx)$/.test(entry.name)) continue;
            const text = readFileSync(full, 'utf8');
            for (const name of Object.keys(definitions)) {
                definitions[name] += (text.match(new RegExp(`export function ${name}\\(`, 'g')) ?? []).length;
            }
        }
    };
    walk(client);
    for (const [name, count] of Object.entries(definitions)) check(`the client holds exactly ONE definition of ${name}`, count === 1, `${count}`);
} catch (error) {
    console.log(`Checkout-dates harness CRASHED: ${(error as Error).stack}`);
    process.exit(2);
}

if (fail) {
    console.log(`Checkout-dates harness: FAIL ${fail} of ${n} checks (root: ${root})`);
    process.exit(1);
}
console.log(`Checkout-dates harness: PASS ${n} checks on node ${process.version}`);
