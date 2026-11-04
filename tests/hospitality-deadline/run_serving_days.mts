/**
 * Min side catering list: orders grouped per DAY, sorted by serving time, all in venue time
 * (Europe/Oslo). Task 36875.
 *
 *   node tests/hospitality-deadline/run_serving_days.mts
 *
 * It checks, against src/modules/bookingfrontend/client/src/utils/catering-venue-time.ts:
 *   1. the wire strings the API really sends ("2026-11-03T12:00:00+01:00", measured), the
 *      near-midnight pair, midnight itself ("00:00", never "24:00"), the two DST changes;
 *   2. the grouping: days ascending, a day by serving time then order id, the order of the INPUT
 *      irrelevant, the hour that repeats when summer time ends ordered by instant, the orders
 *      without a (readable) serving time last, nothing throwing on an unreadable one;
 *   3. the day heading in no / nn / en, and the year only when it is not the current year, both
 *      read at the venue (so also across New Year);
 *   4. ZONE INDEPENDENCE: the same outputs when this process runs in other zones (child runs).
 *      Its control: the legacy browser-zone formatting DOES change between those zones, so the
 *      children are able to see a regression;
 *   5. as source patterns: catering-overview.tsx formats no time itself, builds the groups from the
 *      non-cancelled orders, and keeps the heading outline h2 > h3 (day) > h4 (order); the module
 *      chooses its time zone in exactly one place; the new lang key exists in no, en and nn.
 *
 * What it can NOT do is render the component (the day heading and the order blocks are read as text).
 *
 * The client code is plain TypeScript with type annotations only, so Node strips the types itself
 * (Node >= 22.18). HD_ROOT (optional) = a tree holding src/; mutate_serving_days.py points it at a
 * copy that carries one deliberate defect and expects this harness to go red.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {spawnSync} from 'node:child_process';
import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.HD_ROOT ?? resolve(here, '../..');
const client = join(root, 'src/modules/bookingfrontend/client/src');

// Load the module through temp copies named .mts (unambiguous module format), with its one relative
// import pointed at the copy of hospitality-deadline.
async function loadModule(): Promise<any> {
    const dir = mkdtempSync(join(tmpdir(), 'hd-days-'));
    writeFileSync(join(dir, 'hospitality-deadline.mts'), readFileSync(join(client, 'utils/hospitality-deadline.ts'), 'utf8'));
    const venue = readFileSync(join(client, 'utils/catering-venue-time.ts'), 'utf8');
    const rewritten = venue.replace("from './hospitality-deadline'", "from './hospitality-deadline.mts'");
    if (rewritten === venue) throw new Error("the module no longer imports './hospitality-deadline' as expected");
    writeFileSync(join(dir, 'catering-venue-time.mts'), rewritten);
    return import(pathToFileURL(join(dir, 'catering-venue-time.mts')).href);
}

// What the API sends, measured on GET /bookingfrontend/applications/84278/hospitality-orders.
const WIRE = {
    o84: '2026-10-31T13:31:17+01:00',
    o86: '2026-11-03T12:00:00+01:00',
    o85: '2029-06-04T13:00:00+02:00',
};
// Instants near midnight Oslo on 12/13 Nov 2026 (CET, UTC+1), written with different offsets.
const BEFORE_MIDNIGHT = '2026-11-12T22:30:00+00:00'; // 23:30 Oslo on the 12th
const AFTER_MIDNIGHT = '2026-11-12T23:30:00Z'; // 00:30 Oslo on the 13th
const AFTER_MIDNIGHT_OSLO = '2026-11-13T00:30:00+01:00'; // the same instant, written in Oslo

const order = (id: number, iso: string | null) => ({id, serving_time_iso: iso});

// The fixture of task 36875, plus the orders application 84278 already had.
const FIXTURE = [
    order(84, WIRE.o84),
    order(85, WIRE.o85),
    order(86, WIRE.o86),
    order(87, WIRE.o86),
    order(89, '2026-11-12T23:30:00+01:00'), // B: 23:30 Oslo on the 12th
    order(90, AFTER_MIDNIGHT_OSLO), //          A: 00:30 Oslo on the 13th
    order(91, '2026-11-12T09:00:00+01:00'), //  C: earlier on the 12th, but a higher id than B
    order(92, null), //                         D: no serving time
];
const NOW = Date.parse('2026-11-04T09:00:00Z');

// Instants the browser-zone formatting (below) and the venue formatting are both asked about.
const PROBES = [WIRE.o86, AFTER_MIDNIGHT, '2026-11-12T22:30:00Z', '2026-07-01T10:15:00Z'];

// Everything the module answers that must not depend on the zone this process runs in.
function digest(mod: any): unknown {
    const isos = [
        ...Object.values(WIRE), BEFORE_MIDNIGHT, AFTER_MIDNIGHT, AFTER_MIDNIGHT_OSLO,
        '2026-11-12T23:00:00Z', '2026-11-12T22:59:00Z', '2026-11-12T22:00:00Z',
        '2026-03-29T00:59:00Z', '2026-03-29T01:00:00Z', '2026-10-25T00:30:00Z', '2026-10-25T01:30:00Z',
        '2026-12-31T22:59:00Z', '2026-12-31T23:00:00Z',
    ];
    return {
        moments: isos.map(iso => [iso, mod.servingMoment(iso)]),
        groups: mod.groupByServingDay([...FIXTURE, order(93, 'garbage')].reverse(), 'no', NOW),
        headings: isos.map(iso => ['no', 'nn', 'en'].map(lang => mod.formatServingDayHeading(Date.parse(iso), lang, NOW))),
        deadlines: PROBES.map(iso => mod.formatVenueDeadline(iso)),
    };
}

// The browser-zone formatting this replaces: the answer depends on the zone of the process.
function legacy(): {serving: string[]; deadline: string[]} {
    return {
        serving: PROBES.map(iso => new Date(iso).toLocaleString('nb-NO', {
            weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
        })),
        deadline: PROBES.map(iso => new Date(iso).toLocaleDateString('nb-NO', {
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
        })),
    };
}

// Child mode: print this process's digest, nothing else.
if (process.argv[2] === '--digest') {
    const mod = await loadModule();
    console.log(JSON.stringify({module: digest(mod), legacy: legacy()}));
    process.exit(0);
}

const failures: string[] = [];
let checks = 0;
const check = (name: string, ok: boolean, detail = ''): void => {
    checks++;
    if (!ok) failures.push(detail ? `${name} — ${detail}` : name);
};
const same = (a: unknown, b: unknown): boolean => JSON.stringify(a) === JSON.stringify(b);
// A missing file is a failed check, never a crash: a harness that dies has not looked at anything.
const read = (path: string): string => {
    try {
        return readFileSync(path, 'utf8');
    } catch (error) {
        check(`can read ${path}`, false, String(error));
        return '';
    }
};

const mod = await loadModule();

// 1. Moments.
const moment = (iso: string | null) => mod.servingMoment(iso);
const day = (iso: string) => moment(iso)?.dayKey;
const time = (iso: string) => moment(iso)?.time;
check('wire o86 → 2026-11-03 12:00', day(WIRE.o86) === '2026-11-03' && time(WIRE.o86) === '12:00', JSON.stringify(moment(WIRE.o86)));
check('wire o84 → 2026-10-31 13:31 (seconds dropped)', day(WIRE.o84) === '2026-10-31' && time(WIRE.o84) === '13:31', JSON.stringify(moment(WIRE.o84)));
check('wire o85 (summer time, +02:00) → 2029-06-04 13:00', day(WIRE.o85) === '2029-06-04' && time(WIRE.o85) === '13:00', JSON.stringify(moment(WIRE.o85)));
check('23:30 Oslo stays on the 12th', day(BEFORE_MIDNIGHT) === '2026-11-12' && time(BEFORE_MIDNIGHT) === '23:30', JSON.stringify(moment(BEFORE_MIDNIGHT)));
check('00:30 Oslo is on the 13th', day(AFTER_MIDNIGHT) === '2026-11-13' && time(AFTER_MIDNIGHT) === '00:30', JSON.stringify(moment(AFTER_MIDNIGHT)));
check('the same instant written with another offset gives the same moment', same(moment(AFTER_MIDNIGHT), moment(AFTER_MIDNIGHT_OSLO)));
check('control: a UTC date split WOULD put 23:30 and 00:30 Oslo on one day',
    new Date(BEFORE_MIDNIGHT).toISOString().slice(0, 10) === new Date(AFTER_MIDNIGHT).toISOString().slice(0, 10));
check('midnight is 00:00 on the new day, never 24:00 on the old', day('2026-11-12T23:00:00Z') === '2026-11-13' && time('2026-11-12T23:00:00Z') === '00:00',
    JSON.stringify(moment('2026-11-12T23:00:00Z')));
check('23:59 Oslo is still the 12th', day('2026-11-12T22:59:00Z') === '2026-11-12' && time('2026-11-12T22:59:00Z') === '23:59');
check('spring forward: 00:59Z is 01:59, 01:00Z is 03:00', time('2026-03-29T00:59:00Z') === '01:59' && time('2026-03-29T01:00:00Z') === '03:00');
check('summer time ends: 00:30Z and 01:30Z both read 02:30 on the same day',
    time('2026-10-25T00:30:00Z') === '02:30' && time('2026-10-25T01:30:00Z') === '02:30' && day('2026-10-25T00:30:00Z') === day('2026-10-25T01:30:00Z'));
check('New Year: 22:59Z on 31 Dec is the 31st, 23:00Z is 1 Jan', day('2026-12-31T22:59:00Z') === '2026-12-31' && day('2026-12-31T23:00:00Z') === '2027-01-01');
// Intl's format() throws a RangeError on an invalid date: every path must catch it first, and a
// throw is reported as a failed check instead of ending the run.
const attempt = <T,>(fn: () => T): T | 'threw' => {
    try {
        return fn();
    } catch {
        return 'threw';
    }
};
for (const bad of [null, undefined, '', 'not a date', '57145-01-01T00:00:00+01:00']) {
    const result = attempt(() => moment(bad as string | null));
    check(`an unreadable serving time (${JSON.stringify(bad)}) gives null, without throwing`, result === null, JSON.stringify(result));
    const deadline = attempt(() => mod.formatVenueDeadline(bad));
    check(`an unreadable deadline (${JSON.stringify(bad)}) gives '', without throwing`, deadline === '', JSON.stringify(deadline));
}
check('control: a readable deadline is not empty', mod.formatVenueDeadline('2026-11-10T11:00:00Z') !== '');

// 2. Grouping.
const view = (groups: any[]) => groups.map(g => [g.dayKey, g.items.map((i: any) => [i.order.id, i.time])]);
const EXPECTED = [
    ['2026-10-31', [[84, '13:31']]],
    ['2026-11-03', [[86, '12:00'], [87, '12:00']]],
    ['2026-11-12', [[91, '09:00'], [89, '23:30']]],
    ['2026-11-13', [[90, '00:30']]],
    ['2029-06-04', [[85, '13:00']]],
    [null, [[92, null]]],
];
const shuffled = [FIXTURE[4], FIXTURE[7], FIXTURE[2], FIXTURE[6], FIXTURE[0], FIXTURE[3], FIXTURE[5], FIXTURE[1]];
for (const [name, input] of [['by id ascending', FIXTURE], ['by id descending (the API\'s order)', [...FIXTURE].reverse()], ['shuffled', shuffled]] as const) {
    check(`fixture grouped per Oslo day, whatever the input order — ${name}`,
        same(view(mod.groupByServingDay(input, 'no', NOW)), EXPECTED), JSON.stringify(view(mod.groupByServingDay(input, 'no', NOW))));
}
check('the group without a serving time has no day key and no heading', (() => {
    const last = mod.groupByServingDay(FIXTURE, 'no', NOW).at(-1);
    return last.dayKey === null && last.heading === null;
})());
check('every other group has a heading', mod.groupByServingDay(FIXTURE, 'no', NOW).slice(0, -1).every((g: any) => typeof g.heading === 'string' && g.heading.length > 0));
check('equal times come out by order id even when the later id comes first',
    same(view(mod.groupByServingDay([order(87, WIRE.o86), order(86, WIRE.o86)], 'no', NOW)), [['2026-11-03', [[86, '12:00'], [87, '12:00']]]]));
check('the repeated 02:30 is ordered by instant, not by the shown time or the id',
    same(view(mod.groupByServingDay([order(5, '2026-10-25T01:30:00Z'), order(9, '2026-10-25T00:30:00Z')], 'no', NOW)),
        [['2026-10-25', [[9, '02:30'], [5, '02:30']]]]));
check('orders without a serving time come last, by id, whatever else is there',
    same(attempt(() => view(mod.groupByServingDay([order(7, null), order(3, 'garbage'), order(5, WIRE.o86), order(2, '')], 'no', NOW))),
        [['2026-11-03', [[5, '12:00']]], [null, [[2, null], [3, null], [7, null]]]]));
// The API drops null fields from its JSON: «no serving time» arrives as an ABSENT key, not as null
// (measured on order 92 of application 84278), whatever IHospitalityOrder says.
check('an order whose serving_time_iso key is absent (how the API sends none) is grouped last, without a heading',
    same(view(mod.groupByServingDay([{id: 8} as any, order(5, WIRE.o86)], 'no', NOW)), [['2026-11-03', [[5, '12:00']]], [null, [[8, null]]]]));
check('only undated orders: one group', same(view(mod.groupByServingDay([order(2, null)], 'no', NOW)), [[null, [[2, null]]]]));
check('no orders: no groups', mod.groupByServingDay([], 'no', NOW).length === 0);
check('the input array is not reordered', (() => {
    const input = [...FIXTURE].reverse();
    const before = input.map(o => o.id).join();
    mod.groupByServingDay(input, 'no', NOW);
    return input.map(o => o.id).join() === before;
})());
const dayKeys = mod.groupByServingDay(FIXTURE, 'no', NOW).map((g: any) => g.dayKey).filter((k: unknown) => k !== null);
check('days ascend', dayKeys.every((k: string, i: number) => i === 0 || dayKeys[i - 1] < k), dayKeys.join());

// 3. Headings.
const heading = (iso: string, lang: string, now = NOW) => mod.formatServingDayHeading(Date.parse(iso), lang, now);
check('no: «Torsdag 12. november»', heading('2026-11-12T11:00:00Z', 'no') === 'Torsdag 12. november', heading('2026-11-12T11:00:00Z', 'no'));
check('nn: «Torsdag 12. november» (mapped to no: Chromium has no Nynorsk data)', heading('2026-11-12T11:00:00Z', 'nn') === 'Torsdag 12. november', heading('2026-11-12T11:00:00Z', 'nn'));
check('en: «Thursday 12 November»', heading('2026-11-12T11:00:00Z', 'en') === 'Thursday 12 November', heading('2026-11-12T11:00:00Z', 'en'));
check('no: Saturday and Tuesday', heading(WIRE.o84, 'no') === 'Lørdag 31. oktober' && heading(WIRE.o86, 'no') === 'Tirsdag 3. november',
    `${heading(WIRE.o84, 'no')} | ${heading(WIRE.o86, 'no')}`);
check('the heading follows the Oslo day: 00:30 on the 13th is «Fredag 13. november»', heading(AFTER_MIDNIGHT, 'no') === 'Fredag 13. november', heading(AFTER_MIDNIGHT, 'no'));
// ICU's own en-GB pattern adds a comma when the year is there («Monday, 4 June 2029»); measured the
// same in Chrome 156 and Node, so it is expected, not hacked around.
check('a year other than the current one is shown (no, en)',
    heading(WIRE.o85, 'no') === 'Mandag 4. juni 2029' && heading(WIRE.o85, 'en') === 'Monday, 4 June 2029', `${heading(WIRE.o85, 'no')} | ${heading(WIRE.o85, 'en')}`);
check('the current year is not shown', !/2026/.test(heading(WIRE.o86, 'no')) && !/2026/.test(heading(WIRE.o86, 'en')));
const NEW_YEAR_AT_THE_VENUE = Date.parse('2026-12-31T23:30:00Z'); // 00:30 on 1 Jan 2027 in Oslo, still 2026 in UTC
check('across New Year the current year is read at the venue: 31 Dec 2026 now carries its year',
    heading('2026-12-31T11:00:00Z', 'no', NEW_YEAR_AT_THE_VENUE) === 'Torsdag 31. desember 2026', heading('2026-12-31T11:00:00Z', 'no', NEW_YEAR_AT_THE_VENUE));
check('...and 1 Jan 2027 does not', heading('2027-01-01T11:00:00Z', 'no', NEW_YEAR_AT_THE_VENUE) === 'Fredag 1. januar', heading('2027-01-01T11:00:00Z', 'no', NEW_YEAR_AT_THE_VENUE));
check('an unknown language falls back to Norwegian', heading(WIRE.o86, 'xx') === 'Tirsdag 3. november', heading(WIRE.o86, 'xx'));

// 4. Zone independence. Children run this file in other zones; the module's answers must not move.
const ZONES = ['Europe/Oslo', 'America/New_York', 'Pacific/Kiritimati', 'Asia/Kolkata', 'Pacific/Pago_Pago', 'UTC'];
const child = (zone: string): {module: unknown; legacy: unknown} | null => {
    const run = spawnSync(process.execPath, [fileURLToPath(import.meta.url), '--digest'], {
        env: {...process.env, TZ: zone, HD_ROOT: root}, encoding: 'utf8',
    });
    try {
        return JSON.parse(run.stdout);
    } catch {
        check(`child run in ${zone} produced a digest`, false, (run.stderr || run.stdout).trim().slice(0, 300));
        return null;
    }
};
const results = ZONES.map(zone => [zone, child(zone)] as const);
const reference = results[0][1];
for (const [zone, got] of results) {
    check(`the module answers the same in a ${zone} browser as in Oslo`, !!got && !!reference && same(got.module, reference.module));
}
check('control: the browser-zone formatting this replaces DOES differ between those zones (the children can see a regression)',
    results.slice(1).some(([, got]) => !!got && !!reference && !same(got.legacy, reference.legacy)));
check('...and in an Oslo browser the venue deadline text is exactly what the browser-zone formatting gave (only the zone changes)',
    !!reference && same((reference.module as any).deadlines, (reference.legacy as any).deadline),
    `${JSON.stringify((reference?.module as any)?.deadlines)} vs ${JSON.stringify((reference?.legacy as any)?.deadline)}`);

// 5. Source patterns.
const overview = read(join(client, 'components/user/application/catering-overview.tsx'));
const venue = read(join(client, 'utils/catering-venue-time.ts'));
const count = (pattern: RegExp, text: string): number => (text.match(pattern) ?? []).length;
check('control: the toLocale pattern recognises the formatting that was removed', /toLocale|Intl\./.test("new Date(iso).toLocaleString('nb-NO', {})"));
check('catering-overview.tsx formats no time itself (no toLocale*, no Intl)', !/toLocale|Intl\./.test(overview));
check('the browser-zone formatServingTime / formatDeadline are gone', !/formatServingTime|formatDeadline\b/.test(overview));
check('the deadline hints use the venue-time formatter', count(/formatVenueDeadline\(actions\.(change|cancel)\.until\)/g, overview) === 2);
check('the groups are built from the non-cancelled orders, in the UI language, at the current time',
    /groupByServingDay\(orders, i18n\.language, now\)/.test(overview) && /filter\(order => order\.status !== 'cancelled'\)/.test(overview));
check('the orders are rendered from the groups, not from the API order', /rows\.map\(\(\{order, time, group, groupIndex, indexInGroup\}\)/.test(overview) && !/\borders\.map\(/.test(overview));
check('outline: one h2 (the card), one h3 (the day), one h4 (the order)',
    count(/<Heading level=\{2\}/g, overview) === 1 && count(/<Heading level=\{3\}/g, overview) === 1 && count(/<Heading level=\{4\}/g, overview) === 1,
    `${count(/<Heading level=\{2\}/g, overview)}/${count(/<Heading level=\{3\}/g, overview)}/${count(/<Heading level=\{4\}/g, overview)}`);
check('the group without a serving time is headed by the lang key', /group\.heading \?\? t\('bookingfrontend\.catering_no_serving_time'\)/.test(overview));
check('the serving line shows the time only, «-» without one', /\{t\('bookingfrontend\.serving_time'\)\}: \{time \?\? '-'\}/.test(overview));
check('control: a second time-zone choice WOULD be counted', count(/timeZone:/g, 'a({timeZone: X}); b({timeZone: Y})') === 2);
check('catering-venue-time.ts chooses its time zone in exactly one place', count(/timeZone:/g, venue) === 1, `${count(/timeZone:/g, venue)}`);
check('...and it is the venue zone', /timeZone: VENUE_TIMEZONE/.test(venue));
check('catering-venue-time.ts has no toLocale* other than the upper-casing of the first letter', count(/toLocale(?!UpperCase)/g, venue) === 0);

const langRow = (key: string): RegExp => new RegExp(`^${key}\\tbookingfrontend\\t(no|en|nn)\\t\\S.*$`, 'm');
check('control: the lang pattern does NOT match a key that is not there', !langRow('catering_no_serving_time').test('catering_no_serving_time_x\tbookingfrontend\tno\tText'));
for (const lang of ['no', 'en', 'nn']) {
    const lines = read(join(root, `src/modules/bookingfrontend/setup/phpgw_${lang}.lang`));
    check(`phpgw_${lang}.lang has bookingfrontend.catering_no_serving_time`,
        new RegExp(`^catering_no_serving_time\\tbookingfrontend\\t${lang}\\t\\S.*$`, 'm').test(lines));
}

if (failures.length) {
    for (const line of failures) console.log(`FAIL ${line}`);
    console.log(`Serving-days harness: FAIL ${failures.length} of ${checks} checks`);
    process.exit(1);
}
console.log(`Serving-days harness: PASS ${checks} checks on node ${process.version}`);
