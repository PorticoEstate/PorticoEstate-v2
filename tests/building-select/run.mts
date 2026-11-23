/**
 * Hermetic check of the admin building search (src/modules/booking/js/base/building-select.js, task 47995 item 4,
 * plus the «no results» text of item 2): a stub DOM and a fetch the test releases by hand, so the window between
 * «the user types» and «the list has arrived» is exact instead of a timing guess.
 *
 *   node tests/building-select/run.mts
 *   BS_FILE=<path to another copy of building-select.js> node tests/building-select/run.mts
 *
 * BS_FILE is how the RED arm is run: point it at the file as it was on base (`git show 6ca0ca454:<path>`).
 *
 * What it holds the component to:
 *   1. THE RACE: focus (the request starts), type, and only then release the list: no unhandled rejection
 *      (base: `Cannot read properties of null (reading 'filter')`, one per typed character), and once the list
 *      arrives the dropdown shows exactly what the typed text matches, the text is not lost, the list is open;
 *   2. CONTROL, same typing with the list already loaded: no error. A failing arm that does not also fail the
 *      control would only be measuring the stub;
 *   3. ONE REQUEST: focus + input + focus while it is in flight is one fetch, not three;
 *   4. A FAILED request leaves an empty list the user can still type into (no throw, «no results» shown);
 *   5. THE EMPTY TEXT is the container's data-lang-no-results, never a literal: two different values are read back.
 *
 * Exit code: 0 all pass, 1 any failure, 2 the harness itself crashed.
 */
import {readFileSync} from 'node:fs';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const file = process.env.BS_FILE ?? join(repo, 'src/modules/booking/js/base/building-select.js');
const source = readFileSync(file, 'utf8');

// ---- a stub DOM, as small as the component needs ------------------------------------------------------------
class ClassList {
    names = new Set<string>();
    add(n: string) { this.names.add(n); }
    remove(n: string) { this.names.delete(n); }
    toggle(n: string, on?: boolean) { (on ?? !this.names.has(n)) ? this.names.add(n) : this.names.delete(n); }
    contains(n: string) { return this.names.has(n); }
}
class El {
    tag: string;
    children: El[] = [];
    dataset: Record<string, string> = {};
    classList = new ClassList();
    attrs: Record<string, string> = {};
    className = '';
    value = '';
    textContent = '';
    /** As in a real DOM, assigning '' removes the children (the component re-renders the list that way). */
    set innerHTML(html: string) { if (html === '') this.children = []; }
    get innerHTML() { return ''; }
    constructor(tag = 'div') { this.tag = tag; }
    setAttribute(k: string, v: string) { this.attrs[k] = v; }
    addEventListener() {}
    removeEventListener() {}
    appendChild(c: El) { this.children.push(c); return c; }
    contains() { return false; }
    scrollIntoView() {}
    querySelectorAll() { return this.children; }
}

const unhandled: string[] = [];
process.on('unhandledRejection', reason => unhandled.push(String((reason as Error)?.message ?? reason)));
(globalThis as any).document = {createElement: (tag: string) => new El(tag), addEventListener() {}, removeEventListener() {}};
const consoleError = console.error;
console.error = () => {};   // the component logs a failed request; the test reads the outcome, not the log

const BuildingSelect: any = new Function(`${source}\n;return BuildingSelect;`)();

const BUILDINGS = [{id: 1, name: 'Eidsvåg skole'}, {id: 2, name: 'Fana kulturhus'}, {id: 3, name: 'Eikeland'}];
const tick = () => new Promise<void>(res => setImmediate(res));

/** A component on stub elements, and a fetch that resolves/rejects only when the test says so. */
function make(noResults = 'Ingen treff') {
    const input = new El('input'), hidden = new El('input'), dropdown = new El('ul'), container = new El('div');
    container.dataset.apiUrl = '/booking/buildings';
    container.dataset.langNoResults = noResults;
    (container as any).querySelector = (sel: string) =>
        sel === '.building-select__input' ? input : sel === 'input[type="hidden"]' ? hidden : sel === '.building-select__dropdown' ? dropdown : null;
    (container as any).contains = () => false;

    let calls = 0;
    let settle: (mode: 'ok' | 'fail') => void = () => {};
    (globalThis as any).fetch = () => {
        calls++;
        return new Promise((resolveFetch, rejectFetch) => {
            settle = mode => mode === 'ok'
                ? resolveFetch({ok: true, json: async () => BUILDINGS})
                : rejectFetch(new Error('network down'));
        });
    };
    const select = new BuildingSelect(container, {apiUrl: '/booking/buildings'});
    return {select, input, dropdown, calls: () => calls, release: (mode: 'ok' | 'fail' = 'ok') => settle(mode)};
}
const shown = (dropdown: El) => dropdown.children.map(c => c.textContent);

let n = 0;
let fail = 0;
const check = (name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) { fail++; console.log(`FAIL ${name} ${detail}`); }
};

try {
    // 1. THE RACE
    {
        const before = unhandled.length;
        const t = make();
        t.select._onFocus();                       // focus: the request starts
        t.input.value = 'E'; t.select._onInput();  // typing while buildings === null
        t.input.value = 'Ei'; t.select._onInput();
        await tick();
        t.release('ok');
        await tick(); await tick();
        const errors = unhandled.slice(before);
        check('race: typing before the list has loaded throws nothing', errors.length === 0, JSON.stringify(errors));
        check('race: the dropdown shows what the typed text matches once the list arrives',
            JSON.stringify(shown(t.dropdown)) === JSON.stringify(['Eidsvåg skole', 'Eikeland']), JSON.stringify(shown(t.dropdown)));
        check('race: the typed text is still in the input', t.input.value === 'Ei');
        check('race: the dropdown is open', t.select.isOpen === true);
    }
    // 2. CONTROL: the same typing with the list loaded first
    {
        const before = unhandled.length;
        const t = make();
        t.select._onFocus();
        t.release('ok');
        await tick(); await tick();
        t.input.value = 'Ei'; t.select._onInput();
        await tick();
        check('control: typing after the list has loaded throws nothing', unhandled.length === before, JSON.stringify(unhandled.slice(before)));
        check('control: and filters', JSON.stringify(shown(t.dropdown)) === JSON.stringify(['Eidsvåg skole', 'Eikeland']), JSON.stringify(shown(t.dropdown)));
    }
    // 3. ONE REQUEST
    {
        const t = make();
        t.select._onFocus();
        t.input.value = 'F'; t.select._onInput();
        t.select._onFocus();
        t.release('ok');
        await tick(); await tick();
        check('one request: focus + input + focus while in flight is ONE fetch', t.calls() === 1, `fetch calls = ${t.calls()}`);
    }
    // 4. A FAILED request
    {
        const before = unhandled.length;
        const t = make('Ingen treff');
        t.select._onFocus();
        t.input.value = 'x'; t.select._onInput();
        t.release('fail');
        await tick(); await tick();
        check('failed request: nothing is thrown', unhandled.length === before, JSON.stringify(unhandled.slice(before)));
        check('failed request: the user sees the «no results» text', JSON.stringify(shown(t.dropdown)) === JSON.stringify(['Ingen treff']), JSON.stringify(shown(t.dropdown)));
    }
    // 5. THE EMPTY TEXT comes from the container's attribute
    for (const text of ['Ingen treff', 'No results']) {
        const t = make(text);
        t.select._onFocus();
        t.release('ok');
        await tick(); await tick();
        t.input.value = 'zzzz'; t.select._onInput();
        await tick();
        check(`empty text: «${text}» is read from data-lang-no-results`, JSON.stringify(shown(t.dropdown)) === JSON.stringify([text]), JSON.stringify(shown(t.dropdown)));
    }
} catch (error) {
    console.error = consoleError;
    console.log(`Building-select harness CRASHED: ${(error as Error).stack}`);
    process.exit(2);
}

console.error = consoleError;
if (fail) {
    console.log(`Building-select harness: FAIL ${fail} of ${n} checks (file: ${file})`);
    process.exit(1);
}
console.log(`Building-select harness: PASS ${n} checks on node ${process.version} (file: ${file})`);
