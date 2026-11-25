/**
 * Static guard for the SearchSelect empty-state text (task 49960).
 *
 * search_select.js shows its «no matches» line from options.emptyText. A caller that passes none gets the English literal
 * 'No matches' from the constructor's fallback, so the hospitality page's group list read English in every language.
 * The rule this holds the tree to: every construction passes an emptyText, and that emptyText is a value (a lang() call),
 * not a string literal.
 *
 *   node tests/search-select-empty-text/run_static.mts
 *   SSE_ROOT=<a tree that has src/> node tests/search-select-empty-text/run_static.mts
 *
 * SSE_ROOT is how the RED arms are run: point it at the tree as it was on base (the files named in WALK_FILES and
 * HOSPITALITY_TWIG, extracted with `git show origin/henning-dev:<path>`), and at a copy with the groupOpts emptyText line
 * removed (the mutant).
 *
 * What it holds the tree to:
 *   1. EVERY `new SearchSelect(` (or `new <ns>.SearchSelect(`) under src/ (.js and .twig) passes an emptyText that is not a
 *      string literal. Options written inline are read there; options held in a variable are read at the variable's
 *      declaration before the call (the last `name = {`), or at a `name.emptyText =` before the call. Only a key at the
 *      top level of that object counts: `lang: { emptyText: … }` does not. Options that cannot be read are a FAILURE;
 *   2. NO ALIAS: every bare `SearchSelect` in code is a `new` or the class declaration, so `var S = SearchSelect` cannot
 *      hide a construction from item 1;
 *   3. THE INSTRUMENT CAN FAIL: planted sources go through the same functions the scan uses. A missing, a literal, a
 *      variable-held missing, a nested, an unreadable and an alias must be reported; a call inside a comment or a string
 *      must not be counted; a wired call, one after an apostrophe in a comment and one with `//` inside a string pass;
 *   4. THE WALK REACHED SOMETHING: the three booking files that build a SearchSelect are found as constructions, so a
 *      scan that saw no files cannot pass by finding nothing;
 *   5. THE HOSPITALITY PAGE DELIVERS THE TEXT: its lang root carries data-lang-no-results = lang('booking.no_results').
 *      lang() in hospitality_show.js falls back to the RAW key when the attribute is missing, so an emptyText that is
 *      wired to nothing would print «noResults» on the page.
 *
 * Limits: a regex literal that holds a quote or `//` is not parsed as a regex (item 4 is the guard against a scan that
 * went blind); options built by a call (`new SearchSelect(el, makeOpts())`) and spread objects are reported unreadable,
 * not checked; the interior of a template literal's `${…}` is not scanned.
 *
 * Exit code: 0 all pass, 1 any failure, 2 the harness itself crashed.
 */
import {existsSync, readdirSync, readFileSync} from 'node:fs';
import {dirname, extname, join, relative, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(process.env.SSE_ROOT ?? join(here, '../..'));

const SKIP_DIRS = new Set(['node_modules', 'vendor', '.next', '.git', 'dist']);
const EXTENSIONS = new Set(['.js', '.twig']);
const WALK_FILES = [
    'src/modules/booking/html/base/hospitality/show/hospitality_show.js',
    'src/modules/booking/html/base/components/hospitality_order_modal/hospitality_order_modal.js',
    'src/modules/booking/html/base/components/article_select/article_select.js',
];
const HOSPITALITY_TWIG = 'src/modules/booking/html/base/hospitality/show/hospitality_show.twig';
const ROOT_ATTR = /data-lang-no-results="\{\{\s*lang\('booking\.no_results'\)\s*\}\}"/;

type Verdict = {ok: boolean; how: string};
type Site = {line: number; verdict: Verdict};

/** The source with comments, and the text inside quotes, blanked to spaces. Length and line breaks are kept, so a
 *  position or a line number found here is the same one in the file. */
function codeOnly(src: string): string {
    const out = src.split('');
    const blank = (from: number, to: number) => {
        for (let k = from; k < to; k++) if (out[k] !== '\n') out[k] = ' ';
    };
    let i = 0;
    while (i < src.length) {
        const c = src[i];
        if (c === '/' && src[i + 1] === '/') {
            const nl = src.indexOf('\n', i);
            const end = nl < 0 ? src.length : nl;
            blank(i, end);
            i = end;
        } else if (c === '/' && src[i + 1] === '*') {
            const close = src.indexOf('*/', i + 2);
            const end = close < 0 ? src.length : close + 2;
            blank(i, end);
            i = end;
        } else if (c === '"' || c === "'" || c === '`') {
            let k = i + 1;
            while (k < src.length && src[k] !== c) {
                if (src[k] === '\\') k++;
                else if (c !== '`' && src[k] === '\n') break;
                k++;
            }
            blank(i + 1, Math.min(k, src.length));
            i = k + 1;
        } else {
            i++;
        }
    }
    return out.join('');
}

/** Index of the bracket that closes the one at `open`, or -1. */
function closeOf(code: string, open: number): number {
    let depth = 0;
    for (let k = open; k < code.length; k++) {
        const ch = code[k];
        if (ch === '(' || ch === '[' || ch === '{') depth++;
        else if (ch === ')' || ch === ']' || ch === '}') {
            depth--;
            if (depth === 0) return k;
        }
    }
    return -1;
}

/** The comma-separated parts of `text` at its own bracket depth, trimmed, empty parts dropped. */
function splitTop(text: string): string[] {
    const parts: string[] = [];
    let depth = 0;
    let last = 0;
    for (let k = 0; k < text.length; k++) {
        const ch = text[k];
        if (ch === '(' || ch === '[' || ch === '{') depth++;
        else if (ch === ')' || ch === ']' || ch === '}') depth--;
        else if (ch === ',' && depth === 0) {
            parts.push(text.slice(last, k));
            last = k + 1;
        }
    }
    parts.push(text.slice(last));
    return parts.map(p => p.trim()).filter(p => p.length > 0);
}

/** The properties of an object literal at its top level only: key → value text. */
function topProps(obj: string): Map<string, string> {
    const props = new Map<string, string>();
    for (const part of splitTop(obj.slice(1, -1))) {
        const m = /^([A-Za-z_$][\w$]*)\s*(?::([\s\S]*))?$/.exec(part);
        if (m) props.set(m[1], m[2] === undefined ? m[1] : m[2].trim());
    }
    return props;
}

const lineOf = (text: string, index: number): number => text.slice(0, index).split('\n').length;

function lastBefore(code: string, re: RegExp, before: number): RegExpMatchArray | null {
    let found: RegExpMatchArray | null = null;
    for (const m of code.matchAll(re)) {
        if ((m.index ?? 0) < before) found = m;
    }
    return found;
}

function verdictOf(props: Map<string, string>, where: string): Verdict {
    const value = props.get('emptyText');
    if (value === undefined) return {ok: false, how: `${where}: no emptyText`};
    if (/^['"`]/.test(value)) return {ok: false, how: `${where}: emptyText is a string literal`};
    return {ok: true, how: `${where}: emptyText is not a literal`};
}

/** The verdict for the options argument of the constructor call at index `ctor` in `code`. */
function judge(code: string, ctor: number, arg: string): Verdict {
    if (arg.startsWith('{')) return verdictOf(topProps(arg), 'inline options');
    if (!/^[A-Za-z_$][\w$]*$/.test(arg)) return {ok: false, how: `options argument "${arg.slice(0, 60)}" cannot be read`};
    const id = arg.replace(/\$/g, '\\$');
    const decl = lastBefore(code, new RegExp(`(?:\\b(?:var|let|const)\\s+|(?<![\\w$.]))${id}\\s*=\\s*\\{`, 'g'), ctor);
    if (decl) {
        const open = (decl.index ?? 0) + decl[0].length - 1;
        const obj = code.slice(open, closeOf(code, open) + 1);
        return verdictOf(topProps(obj), `options "${arg}" (declared at line ${lineOf(code, decl.index ?? 0)})`);
    }
    const assigned = lastBefore(code, new RegExp(`(?<![\\w$.])${id}\\.emptyText\\s*=([^;\\n]*)`, 'g'), ctor);
    if (assigned) {
        return /^['"`]/.test(assigned[1].trim())
            ? {ok: false, how: `"${arg}".emptyText is a string literal`}
            : {ok: true, how: `"${arg}".emptyText assigned before the call`};
    }
    return {ok: false, how: `options "${arg}" has no declaration before the call`};
}

function sitesIn(src: string): Site[] {
    const code = codeOnly(src);
    const sites: Site[] = [];
    for (const m of code.matchAll(/\bnew\s+(?:[\w$]+\.)*SearchSelect\s*\(/g)) {
        const at = m.index ?? 0;
        const open = at + m[0].length - 1;
        const close = closeOf(code, open);
        const args = close < 0 ? [] : splitTop(code.slice(open + 1, close));
        const verdict: Verdict = close < 0
            ? {ok: false, how: 'unclosed constructor call'}
            : args.length < 2
                ? {ok: false, how: 'no options argument'}
                : judge(code, at, args[1]);
        sites.push({line: lineOf(code, at), verdict});
    }
    return sites;
}

/** Lines where `SearchSelect` appears in code other than as `new SearchSelect` or the class declaration. */
function untrackedLines(src: string): number[] {
    const code = codeOnly(src);
    const lines: number[] = [];
    for (const m of code.matchAll(/\bSearchSelect\b/g)) {
        const at = m.index ?? 0;
        const before = code.slice(Math.max(0, at - 40), at);
        if (/\bnew\s+(?:[\w$]+\.)*$/.test(before) || /\bclass\s+$/.test(before)) continue;
        lines.push(lineOf(code, at));
    }
    return lines;
}

function* walk(dir: string): Generator<string> {
    for (const entry of readdirSync(dir, {withFileTypes: true})) {
        if (entry.isDirectory()) {
            if (!SKIP_DIRS.has(entry.name)) yield* walk(join(dir, entry.name));
        } else if (entry.isFile() && EXTENSIONS.has(extname(entry.name))) {
            yield join(dir, entry.name);
        }
    }
}

let n = 0;
let fail = 0;
const check = (name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) { fail++; console.log(`FAIL ${name} ${detail}`); }
};

try {
    // 3. THE INSTRUMENT CAN FAIL (same functions as the scan)
    const planted: {what: string; src: string; sites: number; ok: boolean | null; untracked: number}[] = [
        {what: 'a call with no emptyText', src: `new SearchSelect(el, {items: x});`, sites: 1, ok: false, untracked: 0},
        {what: 'a call with an emptyText from lang()', src: `new SearchSelect(el, {items: x, emptyText: lang('noResults')});`, sites: 1, ok: true, untracked: 0},
        {what: 'a call with an English literal emptyText', src: `new SearchSelect(el, {items: x, emptyText: 'No matches'});`, sites: 1, ok: false, untracked: 0},
        {what: 'options in a variable with no emptyText', src: `var o = {items: x};\nnew SearchSelect(el, o);`, sites: 1, ok: false, untracked: 0},
        {what: 'options in a variable with an emptyText from lang()', src: `var o = {emptyText: lang('noResults')};\nnew SearchSelect(el, o);`, sites: 1, ok: true, untracked: 0},
        {what: 'emptyText only nested inside another option', src: `var o = {lang: {emptyText: lang('x')}};\nnew SearchSelect(el, o);`, sites: 1, ok: false, untracked: 0},
        {what: 'options built by a call (unreadable)', src: `new SearchSelect(el, makeOpts());`, sites: 1, ok: false, untracked: 0},
        {what: 'a constructor reached through an alias', src: `var S = SearchSelect;\nnew S(el, {items: x, emptyText: lang('x')});`, sites: 0, ok: null, untracked: 1},
        {what: 'a call and a literal only inside comments and a string', src: `// new SearchSelect(el, {items: x});\n/* new SearchSelect(el, {}) */\nvar msg = 'new SearchSelect(el, {})';`, sites: 0, ok: null, untracked: 0},
        {what: 'a wired call after an apostrophe in a comment', src: `// the previous hospitality's list\nnew SearchSelect(el, {items: x, emptyText: lang('noResults')});`, sites: 1, ok: true, untracked: 0},
        {what: 'a // inside a string argument does not end the call', src: `new SearchSelect(el, {items: x, emptyText: lang('http://a')});`, sites: 1, ok: true, untracked: 0},
        {what: 'a namespaced constructor with a literal emptyText', src: `new window.SearchSelect(el, {emptyText: "No matches"});`, sites: 1, ok: false, untracked: 0},
    ];
    for (const p of planted) {
        const found = sitesIn(p.src);
        const firstOk = found[0]?.verdict.ok ?? null;
        const untracked = untrackedLines(p.src).length;
        check(`instrument: ${p.what}`,
            found.length === p.sites && (p.ok === null || firstOk === p.ok) && untracked === p.untracked,
            JSON.stringify({sites: found.length, ok: firstOk, untracked}));
    }
    const twigPlanted: [string, string, boolean][] = [
        ['the root attribute wired from booking.no_results', `<div id="hospitality-show"\n\t data-lang-no-results="{{ lang('booking.no_results') }}"`, true],
        ['a misspelt attribute', `<div id="hospitality-show" data-lang-no-result="{{ lang('booking.no_results') }}">`, false],
        ['the attribute with an English literal', `<div id="hospitality-show" data-lang-no-results="No results">`, false],
        ['the attribute wired from another key', `<div id="hospitality-show" data-lang-no-results="{{ lang('booking.no_orders') }}">`, false],
    ];
    for (const [what, text, want] of twigPlanted) {
        check(`instrument: twig ${what}`, ROOT_ATTR.test(text) === want);
    }

    // 1 and 4. THE TREE
    const src = join(root, 'src');
    const sites: {file: string; site: Site}[] = [];
    const aliases: string[] = [];
    let files = 0;
    for (const path of walk(src)) {
        files++;
        const text = readFileSync(path, 'utf8');
        if (!text.includes('SearchSelect')) continue;
        const file = relative(root, path);
        for (const site of sitesIn(text)) sites.push({file, site});
        for (const line of untrackedLines(text)) aliases.push(`${file}:${line}`);
    }

    for (const f of WALK_FILES) {
        const hits = sites.filter(s => s.file === f).length;
        check(`walk: ${f} is found as a construction`, hits > 0, `(${hits} found in ${files} scanned files under ${src})`);
    }
    for (const {file, site} of sites) {
        console.log(`${site.verdict.ok ? 'ok  ' : 'MISS'} ${file}:${site.line} (${site.verdict.how})`);
        check(`${file}:${site.line} new SearchSelect( passes a non-literal emptyText`, site.verdict.ok, site.verdict.how);
    }
    for (const at of aliases) {
        console.log(`ALIAS ${at}`);
        check(`${at} bare SearchSelect is not a construction`, false);
    }

    // 5. THE HOSPITALITY PAGE DELIVERS THE TEXT
    const twigPath = join(root, HOSPITALITY_TWIG);
    check(`${HOSPITALITY_TWIG} root carries data-lang-no-results = lang('booking.no_results')`,
        existsSync(twigPath) && ROOT_ATTR.test(readFileSync(twigPath, 'utf8')));

    console.log(`${sites.length} construction(s) in ${files} scanned files; ${sites.filter(s => !s.site.verdict.ok).length} without a usable emptyText`);
} catch (error) {
    console.log(`SearchSelect empty-text static guard CRASHED: ${(error as Error).stack}`);
    process.exit(2);
}

if (fail) {
    console.log(`SearchSelect empty-text static guard: FAIL ${fail} of ${n} checks (root: ${root})`);
    process.exit(1);
}
console.log(`SearchSelect empty-text static guard: PASS ${n} checks on node ${process.version} (root: ${root})`);
