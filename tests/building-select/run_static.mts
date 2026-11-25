/**
 * Static guard for the building search's «no results» text (tasks 47995 item 2, 48593).
 *
 * building-select.js renders its empty-state text from the container's data-lang-no-results and, on purpose, has NO
 * literal fallback (a fallback text would hide the next unwired container instead of failing here). So every
 * container has to carry the attribute, and a container that forgets it renders a BLANK row: that is what happened
 * to the two JS-built containers on the hospitality show page (hospitality_show.js, the Detaljer resource picker and
 * the inline building search), invisible to the component harness (run.mts) because that one builds its container
 * by hand.
 *
 *   node tests/building-select/run_static.mts
 *   BS_ROOT=<a tree that has src/> node tests/building-select/run_static.mts
 *
 * BS_ROOT is how the RED arm is run: point it at the tree as it was on base (extract `git archive d8389d588 src/...`).
 *
 * What it holds the tree to:
 *   1. EVERY element whose class list contains the token `building-select` (twig markup AND the JS string builders
 *      that write HTML) carries data-lang-no-results ON THAT ELEMENT's own tag (not on a child, not elsewhere in the
 *      file, not under another spelling). One check per container found, named by file:line;
 *   2. THE INSTRUMENT CAN FAIL: seven planted strings go through the same function the scan uses: an unwired JS
 *      builder, an unwired multi-line twig tag, a misspelt attribute and an attribute on a child element must be
 *      reported MISSING; a wired JS builder and a wired twig tag (attribute before the class) must be WIRED; the
 *      element's own parts (`building-select__input`, an id, a script src) must not count as containers;
 *   3. THE WALK REACHED SOMETHING: the shared component (building_select.twig) is itself found as a container, so a
 *      scan that saw no files cannot pass by finding nothing.
 *
 * Limits (what it cannot see): a container built with DOM calls (`createElement` + `className`) rather than HTML text,
 * and a Twig tag with a `>` inside an expression before its attribute (that one fails, never passes: the window is cut
 * short, not extended).
 *
 * Exit code: 0 all pass, 1 any failure, 2 the harness itself crashed.
 */
import {readdirSync, readFileSync} from 'node:fs';
import {dirname, extname, join, relative, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(process.env.BS_ROOT ?? join(here, '../..'));

const SKIP_DIRS = new Set(['node_modules', 'vendor', '.next', '.git', 'dist']);
const EXTENSIONS = new Set(['.twig', '.js', '.php', '.xsl', '.html']);
const COMPONENT = 'src/modules/booking/html/base/components/building_select/building_select.twig';

// class="…", class='…' and the escaped class=\"…\" of a string-built tag
const CLASS_ATTR = /class=\\?(["'])([^"'\\]*)\\?\1/g;
const WIRED = /(?<![\w-])data-lang-no-results\s*=/;

type Site = {file: string; line: number; wired: boolean};

/** Every container in `text`: its tag runs from the `<` before its class attribute to the first `>` after it. */
function sitesIn(text: string, file: string): Site[] {
    const sites: Site[] = [];
    for (const m of text.matchAll(CLASS_ATTR)) {
        const at = m.index ?? 0;
        if (!m[2].split(/\s+/).includes('building-select')) continue;
        const start = text.lastIndexOf('<', at);
        const end = text.indexOf('>', at + m[0].length);
        const tag = start >= 0 && end >= 0 ? text.slice(start, end + 1) : '';
        sites.push({file, line: text.slice(0, at).split('\n').length, wired: WIRED.test(tag)});
    }
    return sites;
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
    // 2. THE INSTRUMENT CAN FAIL (same function as the scan)
    const planted: [string, string, number, boolean | null][] = [
        ['a JS builder without the attribute', `html += '<div id="x" class="building-select" style="flex:1">' +\n'<input class="building-select__input">';`, 1, false],
        ['a JS builder with the attribute', `html += '<div id="x" class="building-select" data-lang-no-results="' + esc(lang('k')) + '">' +`, 1, true],
        ['a multi-line twig tag without the attribute', `<div class="building-select"\n     id="a"\n     data-value="1">\n  <input data-x="y">`, 1, false],
        ['a twig tag with the attribute BEFORE the class', `<div data-lang-no-results="{{ lang('k') }}"\n     id="a" class="building-select">`, 1, true],
        ['a misspelt attribute', `<div class="building-select" data-lang-no-result="x">`, 1, false],
        ['the attribute on a CHILD element, not the container', `<div class="building-select"><input data-lang-no-results="x"></div>`, 1, false],
        ['the container\'s own parts (input class, id, script src)', `<input class="building-select__input"> <div id="building-select"> <script src="/js/building-select.js"></script>`, 0, null],
    ];
    for (const [what, text, count, wired] of planted) {
        const found = sitesIn(text, 'planted');
        check(`instrument: ${what} is ${count === 0 ? 'not a container' : wired ? 'reported WIRED' : 'reported MISSING'}`,
            found.length === count && (wired === null || found[0]?.wired === wired), JSON.stringify(found));
    }

    // 1. THE TREE
    const src = join(root, 'src');
    const sites: Site[] = [];
    let files = 0;
    for (const path of walk(src)) {
        files++;
        const text = readFileSync(path, 'utf8');
        if (!text.includes('building-select')) continue;
        sites.push(...sitesIn(text, relative(root, path)));
    }

    // 3. THE WALK REACHED SOMETHING
    check(`walk: the shared component (${COMPONENT}) is found as a container`,
        sites.some(s => s.file === COMPONENT), `scanned ${files} files under ${src}`);

    for (const s of sites) {
        console.log(`${s.wired ? 'ok  ' : 'MISS'} ${s.file}:${s.line}`);
        check(`${s.file}:${s.line} class="building-select" carries data-lang-no-results`, s.wired);
    }

    console.log(`${sites.length} container(s) in ${files} scanned files; ${sites.filter(s => !s.wired).length} without data-lang-no-results`);
} catch (error) {
    console.log(`Building-select static guard CRASHED: ${(error as Error).stack}`);
    process.exit(2);
}

if (fail) {
    console.log(`Building-select static guard: FAIL ${fail} of ${n} checks (root: ${root})`);
    process.exit(1);
}
console.log(`Building-select static guard: PASS ${n} checks on node ${process.version} (root: ${root})`);
