/**
 * The Min side rule for a REJECTED or CANCELLED application: its catering orders stay on view,
 * READ-ONLY, and nothing can be added, changed or cancelled (GH #1423, operator: "read-only"; this
 * replaces the earlier "hidden" rule, task 35894).
 *
 *   node tests/hospitality-deadline/run_closed.mts
 *
 * tsc and ESLint cannot see what matters here, because IApplication.status is a plain string (a
 * misspelt 'REJECTD' compiles and lints). So this checks:
 *   1. the predicate isCateringClosed, over every status the system knows and a few it must not trip on;
 *   2. showsCateringSection, the one pure function that decides «section or no section»
 *      (status × orders × offers);
 *   3. that catering-overview.tsx really is built on those two, as source patterns: the orders are
 *      fetched on a closed application, the offer list is not, every action / hint / locked-Alert and
 *      the order dialog stay behind the status, the note and the kitchen contact are shown there, and
 *      a disabled offer query cannot hold the section back;
 *   4. that the note's lang key exists in no, en and nn.
 *
 * What it can NOT do is render the component: 1 and 2 are behaviour, 3 is text. Every pattern in 3
 * has a control that must NOT match the shape it exists to forbid.
 *
 * The client code is plain TypeScript with type annotations only, so Node strips the types itself
 * (Node >= 22.18). HD_ROOT (optional) = a tree holding src/; mutate_citizen.py points it at a copy
 * that carries one deliberate defect and expects this harness to go red.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {copyFileSync, mkdtempSync, readFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.HD_ROOT ?? resolve(here, '../..');
const client = join(root, 'src/modules/bookingfrontend/client/src');

const failures: string[] = [];
let checks = 0;
const check = (name: string, ok: boolean, detail = ''): void => {
    checks++;
    if (!ok) failures.push(detail ? `${name} — ${detail}` : name);
};

// A missing file is a failed check, never a crash: a harness that dies has not looked at anything.
const read = (path: string): string => {
    try {
        return readFileSync(path, 'utf8');
    } catch (error) {
        check(`can read ${path}`, false, String(error));
        return '';
    }
};

// Import through a temp copy named .mts so the module format is unambiguous whatever the nearest
// package.json says (the same trick run.mts uses).
const dir = mkdtempSync(join(tmpdir(), 'hd-closed-'));
const copy = join(dir, 'catering-status.mts');
copyFileSync(join(client, 'utils/catering-status.ts'), copy);
const mod = await import(pathToFileURL(copy).href);

check('the module exports isCateringClosed', typeof mod.isCateringClosed === 'function');
check('the module exports the list', Array.isArray(mod.CATERING_CLOSED_APPLICATION_STATUSES));
check('the module exports showsCateringSection', typeof mod.showsCateringSection === 'function');

// 1. The predicate. The first three are the statuses that keep catering; the next two are read-only.
const table: Array<[string | null | undefined, boolean, string]> = [
    ['NEW', false, 'a new application keeps catering'],
    ['PENDING', false, 'a pending application keeps catering'],
    ['ACCEPTED', false, 'an accepted application keeps catering'],
    ['REJECTED', true, 'a rejected application is read-only'],
    ['CANCELLED', true, 'a cancelled application is read-only'],
    // Anything else keeps today's behaviour: the section shows and the server decides.
    ['', false, 'an empty status'],
    ['NEWPARTIAL1', false, 'a draft status'],
    ['rejected', false, 'lower case is not the stored value'],
    ['Cancelled', false, 'mixed case is not the stored value'],
    [' REJECTED', false, 'a padded value is not the stored value'],
    ['DENIED', false, 'a status that does not exist'],
    [null, false, 'a missing status'],
    [undefined, false, 'an absent status'],
];
for (const [status, want, why] of table) {
    check(`isCateringClosed(${JSON.stringify(status)}): ${why}`, mod.isCateringClosed?.(status) === want, `got ${mod.isCateringClosed?.(status)} want ${want}`);
}
check('the list is exactly REJECTED and CANCELLED (no other status changes)',
    JSON.stringify([...(mod.CATERING_CLOSED_APPLICATION_STATUSES ?? [])].sort()) === JSON.stringify(['CANCELLED', 'REJECTED']),
    JSON.stringify(mod.CATERING_CLOSED_APPLICATION_STATUSES));

// 2. Section or no section. «orders» are the non-cancelled orders the component counts, «offers» the
//    hospitalities offered for the booked resources (what "Legg til" works from).
const sectionTable: Array<[string | null | undefined, number, number, boolean, string]> = [
    // A closed application: the orders already placed stay on view; nothing else brings the section back.
    ['REJECTED', 2, 0, true, 'orders are shown'],
    ['REJECTED', 2, 3, true, 'orders are shown (offers are irrelevant)'],
    ['REJECTED', 0, 0, false, 'no orders, no section'],
    ['REJECTED', 0, 3, false, 'offers alone do not bring the section back: nothing can be added'],
    ['CANCELLED', 2, 0, true, 'orders are shown'],
    ['CANCELLED', 2, 3, true, 'orders are shown (offers are irrelevant)'],
    ['CANCELLED', 0, 0, false, 'no orders, no section'],
    ['CANCELLED', 0, 3, false, 'offers alone do not bring the section back: nothing can be added'],
    // The open statuses: today's rule, an order or something to add.
    ['NEW', 0, 0, false, 'nothing ordered, nothing to add'],
    ['NEW', 0, 3, true, 'something to add'],
    ['NEW', 2, 0, true, 'orders'],
    ['NEW', 2, 3, true, 'orders and something to add'],
    ['PENDING', 0, 0, false, 'nothing ordered, nothing to add'],
    ['PENDING', 0, 3, true, 'something to add'],
    ['PENDING', 2, 0, true, 'orders'],
    ['PENDING', 2, 3, true, 'orders and something to add'],
    ['ACCEPTED', 0, 0, false, 'nothing ordered, nothing to add'],
    ['ACCEPTED', 0, 3, true, 'something to add'],
    ['ACCEPTED', 2, 0, true, 'orders'],
    ['ACCEPTED', 2, 3, true, 'orders and something to add'],
];
// Statuses that are not on the deny-list behave exactly like an open one.
for (const status of ['NEWPARTIAL1', '', 'rejected', 'Cancelled', ' REJECTED', 'DENIED', null, undefined]) {
    sectionTable.push([status, 0, 0, false, 'nothing ordered, nothing to add']);
    sectionTable.push([status, 0, 3, true, 'something to add (today\'s behaviour)']);
    sectionTable.push([status, 2, 0, true, 'orders']);
}
for (const [status, orders, offers, want, why] of sectionTable) {
    const got = mod.showsCateringSection?.(status, orders, offers);
    check(`showsCateringSection(${JSON.stringify(status)}, orders ${orders}, offers ${offers}): ${why}`, got === want, `got ${got} want ${want}`);
}

// 3. The component is built on them. Source patterns, each with a control.
const source = read(join(client, 'components/user/application/catering-overview.tsx'));
const count = (re: RegExp): number => (source.match(re) ?? []).length;

//    (a) the predicate and the function are imported.
const importsFromStatus = (name: string): RegExp => new RegExp(`import \\{[^}]*\\b${name}\\b[^}]*\\} from '@/utils/catering-status';`);
check('control: the import pattern does NOT match a file that imports neither',
    !importsFromStatus('isCateringClosed').test("import {formatCurrency} from '@/utils/cost-utils';"));
check('catering-overview.tsx imports isCateringClosed from @/utils/catering-status', importsFromStatus('isCateringClosed').test(source));
check('catering-overview.tsx imports showsCateringSection from @/utils/catering-status', importsFromStatus('showsCateringSection').test(source));
check('control: the `readOnly` pattern does NOT match a readOnly derived from something else',
    !/const readOnly = isCateringClosed\(application\.status\);/.test('const readOnly = !!application.locked;'));
check('`readOnly` is isCateringClosed(application.status)', /const readOnly = isCateringClosed\(application\.status\);/.test(source));

//    (b) the orders are fetched on a closed application: `enabled` is the setting alone.
const enabledLine = /const enabled = ([^;]*);/.exec(source)?.[1] ?? '';
const mentionsStatus = /isCateringClosed|readOnly|application\.status/;
check('control: the old gate (setting && !isCateringClosed) is recognised as a status term in `enabled`',
    mentionsStatus.test('!!serverSettings?.booking_config?.enable_hospitality && !isCateringClosed(application.status)'));
check('control: `enabled` was found at all', enabledLine.includes('enable_hospitality'), JSON.stringify(enabledLine));
check('`enabled` is the setting alone: a closed application still fetches its orders', !mentionsStatus.test(enabledLine), enabledLine);
check('the orders query is keyed on `enabled` only', count(/useHospitalityOrders\(enabled \? application\.id : undefined\)/g) === 1,
    `found ${count(/useHospitalityOrders\(enabled \? application\.id : undefined\)/g)}`);

//    (c) the offer list is NOT fetched on a closed application, and being disabled cannot blank the section.
check('the offer query is keyed on `enabled && !readOnly`: a closed application does not fetch it',
    count(/useAvailableHospitalities\(enabled && !readOnly \? application\.id : undefined\)/g) === 1,
    `found ${count(/useAvailableHospitalities\(enabled && !readOnly \? application\.id : undefined\)/g)}`);
check('control: the offer-query pattern finds the query at all', count(/useAvailableHospitalities\(/g) === 1, `found ${count(/useAvailableHospitalities\(/g)}`);
// react-query v5 reports isLoading=false for a disabled query, v4 reports true. Whatever the version,
// the loading guard must not wait for a query that is switched off on a closed application.
check('the offer query\'s isLoading is read once, and only while it is enabled (a react-query downgrade cannot blank a closed application)',
    count(/hospitalitiesQuery\.isLoading/g) === 1 && /const hospitalitiesLoading = !readOnly && hospitalitiesQuery\.isLoading;/.test(source),
    `isLoading read ${count(/hospitalitiesQuery\.isLoading/g)} time(s)`);
check('the loading guard uses that value, not the raw query state',
    /if \(!enabled \|\| ordersQuery\.isLoading \|\| hospitalitiesLoading \|\| ordersQuery\.isError\) \{/.test(source));

//    (d) section or no section is decided by the tested function; the old "no orders and no offers" test is gone.
check('control: the old inline rule is recognised', /orders\.length === 0 && hospitalities\.length === 0/.test('if (orders.length === 0 && hospitalities.length === 0) {'));
check('the component asks showsCateringSection(status, orders, offers) and returns null when it says no',
    /if \(!showsCateringSection\(application\.status, orders\.length, hospitalities\.length\)\) \{\s*return null;\s*\}/.test(source));
check('...and no longer decides it with its own inline rule', !/orders\.length === 0 && hospitalities\.length === 0/.test(source));

//    (e) every action, hint and locked-Alert hangs off `handleable`, which a closed application never is.
check('control: `handleable` without the status guard is NOT recognised as the guarded shape',
    !/const handleable = !readOnly && /.test('const handleable = HANDLEABLE_STATUSES.includes(application.status);'));
check('`handleable` is false for a read-only application, whatever HANDLEABLE_STATUSES holds',
    /const handleable = !readOnly && HANDLEABLE_STATUSES\.includes\(application\.status\);/.test(source));
check('"Legg til" needs `handleable`', /const canAdd = handleable\s*&&/.test(source));
check('"Rediger" needs `handleable`', /const changeOpen = handleable && /.test(source));
check('"Avbestill" needs `handleable`', /const cancelOpen = handleable && /.test(source));
check('the locked reasons (and so the locked-Alert) need `handleable`: the server\'s `application_closed` is never turned into text',
    /if \(handleable && actions\) \{/.test(source));
// GH #1423 design (task 39791): a deadline is no longer printed per order. The rule is stated ONCE by an Alert,
// built from the offers' configuration and only for a handleable application; `actions` still decide the buttons.
check('control: a per-order deadline hint is recognised', /actions\?\.(change|cancel)\.until/.test('{cancelOpen && actions?.cancel.until && ('));
check('there is no per-order deadline hint any more (no `actions?.change.until` / `actions?.cancel.until`)', !/actions\?\.(change|cancel)\.until/.test(source));
check('control: the rule pattern does NOT match a rule that is built for a read-only application',
    !/const rules = handleable \? ruleGroups\(orders, hospitalities\) : \[\];/.test('const rules = ruleGroups(orders, hospitalities);'));
check('the change / cancel rule is derived only for a `handleable` application (a closed one gets no rule)',
    /const rules = handleable \? ruleGroups\(orders, hospitalities\) : \[\];/.test(source));
check('the Alert is mounted from those rule groups, and only when there is one',
    /\{rules\.length > 0 && \(?\s*(<div[^>]*>\s*)?<CateringDeadlineAlert groups=\{rules\}\/>/.test(source) && count(/<CateringDeadlineAlert/g) === 1);
check('the buttons still follow the server: `changeOpen` / `cancelOpen` read `actions`, never the rule',
    /const changeOpen = handleable && !!actions\?\.change\.allowed && !!hospitality;/.test(source)
    && /const cancelOpen = handleable && !!actions\?\.cancel\.allowed;/.test(source) && !/rules\b[^;]*(changeOpen|cancelOpen)|(changeOpen|cancelOpen)[^;]*\brules\b/.test(source));
check('cancelling asks a Digdir Dialog, not window.confirm', !/window\.confirm/.test(source) && count(/<CateringCancelDialog/g) === 1);
check('...and HANDLEABLE_STATUSES is untouched (no other status changes)', /const HANDLEABLE_STATUSES = \['NEW', 'PENDING', 'ACCEPTED'\];/.test(source));
const handleable = [...(/const HANDLEABLE_STATUSES = \[([^\]]*)\];/.exec(source)?.[1] ?? '').matchAll(/'([^']+)'/g)].map(m => m[1]);
check('control: the HANDLEABLE_STATUSES list was parsed', handleable.length === 3, JSON.stringify(handleable));
check('...and none of REJECTED / CANCELLED is in it',
    handleable.length > 0 && !handleable.some(s => (mod.CATERING_CLOSED_APPLICATION_STATUSES ?? []).includes(s)), JSON.stringify(handleable));

//    (f) the read-only view: the note and the kitchen contact are shown, the order dialog is not mounted.
check('control: the note pattern does NOT match a note shown on an open application',
    !/\{readOnly && \(\s*<Paragraph[^>]*data-testid="catering-read-only"/.test('{!readOnly && (<Paragraph data-testid="catering-read-only">'));
check('the read-only note is shown for a read-only application, with the existing lang key',
    /\{readOnly && \(\s*<Paragraph[^>]*data-testid="catering-read-only"[^>]*>\s*\{t\('bookingfrontend\.catering_application_closed'\)\}\s*<\/Paragraph>\s*\)\}/.test(source));
// Task 40576 (design A): the contact is said once per kitchen at the top of the section, from every serving of a read-only
// application (and every locked one), instead of under each card.
check('the kitchen contact stays visible on a read-only application (information, not an action), once per kitchen',
    /const contacts = uniqueKitchenContacts\(rows\.filter\(row => readOnly \|\| servingState\(row\.order\)\.locked\)\.map\(row => row\.order\.contact\)\);/.test(source)
    && /\{contacts\.map\(\(contact, index\) => <KitchenContact key=\{index\} contact=\{contact\}\/>\)\}/.test(source));
check('the order dialog is not mounted for a read-only application',
    /\{!readOnly && \(\s*<HospitalityOrderModal/.test(source) && count(/<HospitalityOrderModal/g) === 1,
    `${count(/<HospitalityOrderModal/g)} mount(s)`);

// 3b. The price summary of the whole application is shown only where a billed catering order cannot be in it
// twice (task 39791, D1). A billed order is also a stored order line, and the server sends no `billed` flag,
// only `actions`: isProvablyNotBilled says «proven» from an allowed action or a refusal decided AFTER the
// guard's billed check, and «not proven» from billed itself, from the two refusals that come BEFORE it and
// hide it (order_status = delivered, application_closed), and from no actions at all.
const act = (allowed: boolean, reason: string | null) => ({allowed, reason});
const both = (change: [boolean, string | null], cancel: [boolean, string | null]) => ({change: act(...change), cancel: act(...cancel)});
const provenTable: Array<[string, any, boolean]> = [
    ['both actions open', both([true, null], [true, null]), true],
    ['change locked by the cutoff, cancel open', both([false, 'order_cutoff_passed'], [true, null]), true],
    ['both locked by the cancellation deadline', both([false, 'cancellation_deadline_passed'], [false, 'cancellation_deadline_passed']), true],
    ['both locked by the serving time', both([false, 'serving_time_passed'], [false, 'serving_time_passed']), true],
    ['paid at checkout (checked after billed)', both([false, 'order_paid_at_checkout'], [false, 'order_paid_at_checkout']), true],
    ['no deadline basis (checked after billed)', both([false, 'no_deadline_basis'], [false, 'no_deadline_basis']), true],
    ['BILLED', both([false, 'order_billed'], [false, 'order_billed']), false],
    ['delivered (order_status comes BEFORE billed, so billed is hidden)', both([false, 'order_status'], [false, 'order_status']), false],
    ['application closed (comes before everything)', both([false, 'application_closed'], [false, 'application_closed']), false],
    ['no actions sent', undefined, false],
    ['actions null', null, false],
    ['the two actions disagree, one only proven (fail closed)', both([true, null], [false, 'order_billed']), false],
    ['a refusal without a reason is not proof', both([false, null], [false, null]), false],
];
check('control: isProvablyNotBilled exists on the module', typeof mod.isProvablyNotBilled === 'function');
for (const [name, actions, want] of provenTable) {
    const got = mod.isProvablyNotBilled?.(actions);
    check(`isProvablyNotBilled: ${name} → ${want}`, got === want, `got ${got}`);
}
// Since 326199b38 (task 40439, F1) the rule lives in ONE place, showsPriceSummary (utils/catering-status.ts), whose
// `every` / `some` shape is checked by tests/application-view/run_application_view.mts (F1a and its mutants); the old
// inline expression this row looked for is gone, so the row was red from 326199b38 on (found by task 40576).
check('the application price summary needs EVERY shown order proven not billed (through showsPriceSummary)',
    /const showApplicationSummary = showsPriceSummary\(orders, prices\.total\.inclOre\);/.test(source) && count(/\{showApplicationSummary && \(/g) === 1
    && !/isProvablyNotBilled|cateringBillingNotRuledOut/.test(source));
check('control: `some` instead of `every` is not the recognised shape', !/orders\.every\(order => isProvablyNotBilled/.test('orders.some(order => isProvablyNotBilled(order.actions))'));

// 4. The note's lang key exists where the client reads it from.
const langRow = (lang: string, key: string): RegExp => new RegExp(`^${key}\\tbookingfrontend\\t${lang}\\t\\S.*$`, 'm');
check('control: the lang pattern does NOT match a key that is not there',
    !langRow('no', 'catering_application_closed').test('catering_application_closed_x\tbookingfrontend\tno\tText'));
for (const lang of ['no', 'en', 'nn']) {
    const lines = read(join(root, `src/modules/bookingfrontend/setup/phpgw_${lang}.lang`));
    check(`phpgw_${lang}.lang has bookingfrontend.catering_application_closed`, langRow(lang, 'catering_application_closed').test(lines));
}

if (failures.length) {
    for (const line of failures) console.log(`FAIL ${line}`);
    console.log(`Closed harness: FAIL ${failures.length} of ${checks} checks`);
    process.exit(1);
}
console.log(`Closed harness: PASS ${checks} checks on node ${process.version}`);
