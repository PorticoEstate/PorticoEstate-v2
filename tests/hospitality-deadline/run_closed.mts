/**
 * The Min side rule «no catering on a REJECTED or CANCELLED application» (GH #1423 follow-up, task 34384).
 *
 *   node tests/hospitality-deadline/run_closed.mts
 *
 * tsc and ESLint cannot see the two things that matter here, because IApplication.status is a plain
 * string (a misspelt 'REJECTD' compiles and lints):
 *   1. the predicate, over every status the system knows and a few it must not trip on;
 *   2. that catering-overview.tsx really gates on it BEFORE any request is made.
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

// Import through a temp copy named .mts so the module format is unambiguous whatever the nearest
// package.json says (the same trick run.mts uses).
const dir = mkdtempSync(join(tmpdir(), 'hd-closed-'));
const copy = join(dir, 'catering-status.mts');
copyFileSync(join(client, 'utils/catering-status.ts'), copy);
const mod = await import(pathToFileURL(copy).href);

check('the module exports isCateringClosed', typeof mod.isCateringClosed === 'function');
check('the module exports the list', Array.isArray(mod.CATERING_CLOSED_APPLICATION_STATUSES));

// 1. The predicate. The first three are the statuses that keep catering; the next two lose it.
const table: Array<[string | null | undefined, boolean, string]> = [
    ['NEW', false, 'a new application keeps catering'],
    ['PENDING', false, 'a pending application keeps catering'],
    ['ACCEPTED', false, 'an accepted application keeps catering'],
    ['REJECTED', true, 'a rejected application loses it'],
    ['CANCELLED', true, 'a cancelled application loses it'],
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

// 2. The component gates on it before the queries, so a closed application makes no request at all.
const source = readFileSync(join(client, 'components/user/application/catering-overview.tsx'), 'utf8');
const gate = /const enabled = [^;\n]*!isCateringClosed\(application\.status\)[^;\n]*;/;
const queries = /use(?:HospitalityOrders|AvailableHospitalities)\(enabled \? application\.id : undefined\)/g;
check('control: the gate pattern does NOT match a component without the check',
    !gate.test("const enabled = !!serverSettings?.booking_config?.enable_hospitality;"));
check('catering-overview.tsx imports isCateringClosed from @/utils/catering-status',
    /import \{isCateringClosed\} from '@\/utils\/catering-status';/.test(source));
check('catering-overview.tsx derives `enabled` from the setting AND !isCateringClosed(application.status)', gate.test(source));
check('...and both queries are keyed on `enabled` (so a closed application fetches nothing)', (source.match(queries) ?? []).length === 2,
    `found ${(source.match(queries) ?? []).length}`);
check('control: the queries pattern finds them in the file at all', (source.match(queries) ?? []).length > 0);
check('...and the component returns null when not enabled', /if \(!enabled \|\| /.test(source));
check('...and HANDLEABLE_STATUSES is untouched (no other status changes)', /const HANDLEABLE_STATUSES = \['NEW', 'PENDING', 'ACCEPTED'\];/.test(source));

if (failures.length) {
    for (const line of failures) console.log(`FAIL ${line}`);
    console.log(`Closed harness: FAIL ${failures.length} of ${checks} checks`);
    process.exit(1);
}
console.log(`Closed harness: PASS ${checks} checks on node ${process.version}`);
