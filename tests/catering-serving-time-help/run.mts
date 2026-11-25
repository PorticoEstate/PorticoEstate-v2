/**
 * Hermetic grid check of the on-site Klokkeslett help under the catering dialog's time field (GH #1478, unit E;
 * task 50460).
 *
 *   node tests/catering-serving-time-help/run.mts
 *
 * The decision under test is servingTimeHelp in service/utils/serving-times.ts, fed by offeredServingTimes (the list
 * the select renders) and kitchenOpeningSpanOnDay (the kitchen's own span, read only to tell a closed kitchen from
 * one with no overlap). The module is loaded from TEMP COPIES: its alias imports are rewritten, and node_modules is
 * a symlink so luxon resolves. SERVING_TS (optional) points at another copy of serving-times.ts; mutate.py uses it to
 * prove that a broken help text turns this red.
 *
 * The oracle shares no code with the module. It reads the season records and works on a 15-minute grid of minutes,
 * with the kitchen's rule written out: an instant is open when an ACTIVE season covering the date has a boundary on
 * that weekday holding it, and a boundary reaching 23:45 is open to midnight.
 *
 * Cases:
 *   - grid: 6 kitchen-hour sets x 8 booking windows x 7 weekdays, each run on-site AND as delivery = 672 cases. Each
 *     case checks the list, the kitchen's day span, and the help. The help must name the list's first and last time
 *     on-site, and must never name a range the list does not offer.
 *   - pins: hand-written expectations (not the oracle), including the real kitchen's Tuesday and Monday hours.
 *   - coverage: every help kind must occur in the grid, or the grid did not exercise it.
 *
 * Exit code: 0 all pass, 1 any failure, 2 the loader could not load the module under test.
 */
import {mkdtempSync, readFileSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const utils = join(root, 'src/modules/bookingfrontend/client/src/service/utils');
const servingSource = process.env.SERVING_TS ?? join(utils, 'serving-times.ts');

// ---- loader ---------------------------------------------------------------------------------------------------------

const BUILDING_TYPE_IMPORT = "import {Season} from '@/service/types/Building';";
const BUSINESS_HOURS_IMPORT = "from '@/service/utils/business-hours'";
const read = (path: string) => readFileSync(path, 'utf8');

const dir = mkdtempSync(join(tmpdir(), 'serving-time-help-'));
symlinkSync(join(root, 'src/modules/bookingfrontend/client/node_modules'), join(dir, 'node_modules'), 'dir');
writeFileSync(join(dir, 'business-hours.mts'), read(join(utils, 'business-hours.ts')).replace(BUILDING_TYPE_IMPORT, ''));
const servingCopy = read(servingSource).replace(BUILDING_TYPE_IMPORT, '').replace(BUSINESS_HOURS_IMPORT, "from './business-hours.mts'");
if (servingCopy.includes('@/service/')) {
    console.error(`loader: an alias import is left in ${servingSource}; the copy would not load`);
    process.exit(2);
}
writeFileSync(join(dir, 'serving-times.mts'), servingCopy);

type Help =
    | {kind: 'kitchen'; from: string; to: string}
    | {kind: 'application'}
    | {kind: 'closed'}
    | {kind: 'none'};
type Span = {from: string; to: string} | null;

interface ServingModule {
    generateTimeSlots(fromHour: number, fromMinute: number, toHour: number, toMinute: number): string[];
    offeredServingTimes(
        booking: {from: Date; to: Date},
        resourceId: string,
        seasons: unknown[] | undefined,
        closeWhenNoSeasons: boolean,
    ): string[];
    kitchenOpeningSpanOnDay(day: Date, resourceId: string, seasons: unknown[] | undefined, closeWhenNoSeasons: boolean): Span;
    servingTimeHelp(input: {isDelivery: boolean; offered: string[]; kitchenHoursKnown: boolean; kitchenSpan: Span}): Help;
}

let mod: ServingModule;
try {
    mod = (await import(pathToFileURL(join(dir, 'serving-times.mts')).href)) as ServingModule;
} catch (error) {
    console.error(`loader: could not load ${servingSource}: ${(error as Error).message}`);
    process.exit(2);
}
console.log(`serving-times loaded from: ${servingSource}`);

// ---- fixtures -------------------------------------------------------------------------------------------------------

const RESOURCE = '482';
const WEEK = [1, 2, 3, 4, 5, 6, 7];

type Boundary = {wday: number; from: string; to: string}; // 'HH:MM'; wday 1 = Monday .. 7 = Sunday
type SeasonRecord = {active: boolean; from: string; to: string; boundaries: Boundary[]}; // from/to: 'YYYY-MM-DD'

const daily = (wdays: number[], from: string, to: string): Boundary[] => wdays.map(wday => ({wday, from, to}));

const KITCHEN_SETS: {name: string; seasons: SeasonRecord[]}[] = [
    {
        name: 'all-days-08-22',
        seasons: [{active: true, from: '2026-01-01', to: '2029-12-31', boundaries: daily(WEEK, '08:00', '22:00')}],
    },
    {
        name: 'gap-08-11-and-13-16',
        seasons: [{
            active: true,
            from: '2026-01-01',
            to: '2029-12-31',
            boundaries: [...daily(WEEK, '08:00', '11:00'), ...daily(WEEK, '13:00', '16:00')],
        }],
    },
    {
        name: 'tue-wed-open-to-midnight',
        seasons: [{
            active: true,
            from: '2026-01-01',
            to: '2029-12-31',
            boundaries: [...daily([1, 4, 5, 6, 7], '08:00', '22:00'), ...daily([2, 3], '15:00', '23:45')],
        }],
    },
    {
        name: 'weekend-only',
        seasons: [{active: true, from: '2026-01-01', to: '2029-12-31', boundaries: daily([6, 7], '14:00', '19:00')}],
    },
    {
        name: 'closed-sunday',
        seasons: [{active: true, from: '2026-01-01', to: '2029-12-31', boundaries: daily([1, 2, 3, 4, 5, 6], '09:00', '17:00')}],
    },
    {
        name: 'autumn-season-plus-inactive-summer',
        seasons: [
            {
                active: true,
                from: '2026-08-01',
                to: '2026-12-31',
                boundaries: [...daily([1, 2, 3, 4, 5], '09:00', '17:00'), ...daily([6, 7], '14:00', '19:00')],
            },
            {active: false, from: '2026-01-01', to: '2026-12-31', boundaries: daily(WEEK, '00:00', '23:45')},
        ],
    },
];

const WINDOWS: [string, string][] = [
    ['18:00', '20:00'],
    ['08:00', '10:00'],
    ['22:30', '23:00'],
    ['07:00', '09:00'],
    ['06:00', '07:00'],
    ['07:07', '09:00'],
    ['11:30', '13:30'],
    ['15:00', '23:59'],
];

/** The module's Season objects, built from the same records the oracle reads. */
function toSeasons(records: SeasonRecord[]): unknown[] {
    return records.map((s, i) => ({
        id: i + 1,
        name: `season ${i + 1}`,
        building_id: 10,
        from_: `${s.from}T00:00:00`,
        to_: `${s.to}T23:59:59`,
        active: s.active ? 1 : 0,
        status: 'published',
        resources: [{id: Number(RESOURCE)}],
        boundaries: s.boundaries.map(b => ({wday: b.wday, from_: `${b.from}:00`, to_: `${b.to}:00`})),
    }));
}

// ---- oracle (no code shared with the module) -----------------------------------------------------------------------

const pad = (n: number) => String(n).padStart(2, '0');
const hhmm = (minute: number) => `${pad(Math.floor(minute / 60))}:${pad(minute % 60)}`;
const minuteOf = (hm: string) => {
    const [h, m] = hm.split(':').map(Number);
    return h * 60 + m;
};
const isoDate = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const isoWeekday = (d: Date) => ((d.getDay() + 6) % 7) + 1;

function oracleOpen(records: SeasonRecord[], day: Date, minute: number): boolean {
    const date = isoDate(day);
    const wday = isoWeekday(day);
    const at = hhmm(minute);
    return records
        .filter(s => s.active && s.from <= date && date <= s.to)
        .some(s => s.boundaries.some(b => b.wday === wday && b.from <= at && (b.to >= '23:45' || at <= b.to)));
}

/** Starts of a booking on the 15-minute grid, the first one rounded UP. */
function oracleStarts(from: Date, to: Date): number[] {
    const toMin = to.getHours() * 60 + to.getMinutes();
    const out: number[] = [];
    for (let m = Math.ceil((from.getHours() * 60 + from.getMinutes()) / 15) * 15; m <= toMin; m += 15) out.push(m);
    return out;
}

function oracleDaySpan(records: SeasonRecord[], day: Date): Span {
    const open: number[] = [];
    for (let m = 0; m <= 23 * 60 + 45; m += 15) if (oracleOpen(records, day, m)) open.push(m);
    return open.length ? {from: hhmm(open[0]), to: hhmm(open[open.length - 1])} : null;
}

/** The help the specification requires, written from the rules, not from the module. */
function expectedHelp(offered: string[], span: Span, isDelivery: boolean): Help {
    if (isDelivery) return offered.length ? {kind: 'application'} : {kind: 'none'};
    if (offered.length) return {kind: 'kitchen', from: offered[0], to: offered[offered.length - 1]};
    return span === null ? {kind: 'closed'} : {kind: 'none'};
}

// ---- checks ---------------------------------------------------------------------------------------------------------

let passed = 0;
const failures: string[] = [];
const check = (name: string, ok: boolean, detail = '') => {
    if (ok) passed++;
    else failures.push(detail ? `${name} :: ${detail}` : name);
};
const showHelp = (h: Help) => (h.kind === 'kitchen' ? `kitchen ${h.from}-${h.to}` : h.kind);
const showSpan = (s: Span) => (s ? `${s.from}-${s.to}` : 'null');
const bookingOn = (day: Date, [from, to]: [string, string]) => {
    const [fh, fm] = from.split(':').map(Number);
    const [th, tm] = to.split(':').map(Number);
    return {
        from: new Date(day.getFullYear(), day.getMonth(), day.getDate(), fh, fm),
        to: new Date(day.getFullYear(), day.getMonth(), day.getDate(), th, tm),
    };
};

const kinds = new Map<string, number>();
let gridCases = 0;
for (const set of KITCHEN_SETS) {
    const seasons = toSeasons(set.seasons);
    for (const window of WINDOWS) {
        for (let i = 0; i < 7; i++) {
            const day = new Date(2026, 10, 23 + i); // Mon 23 Nov 2026 .. Sun 29 Nov 2026
            const booking = bookingOn(day, window);
            const label = `${set.name} ${window.join('-')} ${isoDate(day)}`;

            const expectedOffered = oracleStarts(booking.from, booking.to)
                .filter(m => oracleOpen(set.seasons, day, m))
                .map(hhmm);
            const offered = mod.offeredServingTimes(booking, RESOURCE, seasons, true);
            check(`${label} list`, offered.join(',') === expectedOffered.join(','),
                `got [${offered.join(',')}] expected [${expectedOffered.join(',')}]`);

            const expectedSpan = oracleDaySpan(set.seasons, day);
            const span = mod.kitchenOpeningSpanOnDay(day, RESOURCE, seasons, true);
            check(`${label} day span`, showSpan(span) === showSpan(expectedSpan),
                `got ${showSpan(span)} expected ${showSpan(expectedSpan)}`);

            const onsite = mod.servingTimeHelp({isDelivery: false, offered, kitchenHoursKnown: true, kitchenSpan: span});
            const wantOnsite = expectedHelp(expectedOffered, expectedSpan, false);
            check(`${label} on-site`, showHelp(onsite) === showHelp(wantOnsite),
                `got ${showHelp(onsite)} expected ${showHelp(wantOnsite)}`);
            kinds.set(onsite.kind, (kinds.get(onsite.kind) ?? 0) + 1);

            // Delivery: the list is the booking's own slots, whatever the kitchen's hours.
            const deliverySlots = mod.generateTimeSlots(
                booking.from.getHours(), booking.from.getMinutes(), booking.to.getHours(), booking.to.getMinutes(),
            );
            const expectedSlots = oracleStarts(booking.from, booking.to).map(hhmm);
            check(`${label} delivery slots`, deliverySlots.join(',') === expectedSlots.join(','),
                `got [${deliverySlots.join(',')}] expected [${expectedSlots.join(',')}]`);
            const delivery = mod.servingTimeHelp({isDelivery: true, offered: deliverySlots, kitchenHoursKnown: true, kitchenSpan: span});
            const wantDelivery = expectedHelp(expectedSlots, expectedSpan, true);
            check(`${label} delivery`, showHelp(delivery) === showHelp(wantDelivery),
                `got ${showHelp(delivery)} expected ${showHelp(wantDelivery)}`);
            kinds.set(delivery.kind, (kinds.get(delivery.kind) ?? 0) + 1);
            gridCases += 2;
        }
    }
}

// Pins: expectations written by hand. The list and span still come from the module under test.
const checksBeforePins = passed + failures.length;
const monTueSet = toSeasons(KITCHEN_SETS[2].seasons);
const allDaysSet = toSeasons(KITCHEN_SETS[0].seasons);
const weekendSet = toSeasons(KITCHEN_SETS[3].seasons);
const splitSet = toSeasons(KITCHEN_SETS[1].seasons);
const WED_25 = new Date(2026, 10, 25);
const TUE_24 = new Date(2026, 10, 24);
const MON_23 = new Date(2026, 10, 23);

function pinOnsite(name: string, seasons: unknown[], day: Date, window: [string, string], want: Help) {
    const booking = bookingOn(day, window);
    const offered = mod.offeredServingTimes(booking, RESOURCE, seasons, true);
    const span = mod.kitchenOpeningSpanOnDay(day, RESOURCE, seasons, true);
    const got = mod.servingTimeHelp({isDelivery: false, offered, kitchenHoursKnown: true, kitchenSpan: span});
    check(`pin ${name}`, showHelp(got) === showHelp(want), `got ${showHelp(got)} expected ${showHelp(want)}`);
}

// The brief's example: a booking 18-20 in a kitchen open 08-22 names 18:00-20:00, not 08:00-22:00.
pinOnsite('18-20 in 08-22 names the offered 18:00-20:00', allDaysSet, WED_25, ['18:00', '20:00'],
    {kind: 'kitchen', from: '18:00', to: '20:00'});
// Kitchen open that day, no overlap with the booking: the no-times line, not the closed message.
pinOnsite('22:30-23:00 in 08-22 is none (open, no overlap)', allDaysSet, WED_25, ['22:30', '23:00'], {kind: 'none'});
// The kitchen has no opening on that weekday: the closed message.
pinOnsite('Wed 25 in weekend-only is closed', weekendSet, WED_25, ['10:00', '12:00'], {kind: 'closed'});
// The real kitchen (resource 482): Tuesday opens 15:00 and runs to midnight; Monday 08:00-22:00.
pinOnsite('real kitchen Tue 24 Nov names 15:00-23:45', monTueSet, TUE_24, ['06:00', '23:59'],
    {kind: 'kitchen', from: '15:00', to: '23:45'});
pinOnsite('real kitchen Mon 23 Nov names 08:00-22:00', monTueSet, MON_23, ['06:00', '23:59'],
    {kind: 'kitchen', from: '08:00', to: '22:00'});
// The gap is spanned by the text and refused by the list (accepted in 50337).
pinOnsite('gap kitchen 07-17 names 08:00-16:00 across the gap', splitSet, WED_25, ['07:00', '17:00'],
    {kind: 'kitchen', from: '08:00', to: '16:00'});

// Hours cannot be judged: no seasons, close-without-season on. The list is empty and the text must say no times.
{
    const booking = bookingOn(WED_25, ['18:00', '20:00']);
    const offered = mod.offeredServingTimes(booking, RESOURCE, [], true);
    const got = mod.servingTimeHelp({isDelivery: false, offered, kitchenHoursKnown: false, kitchenSpan: null});
    check('pin unjudgeable on-site is none, not a range', offered.length === 0 && showHelp(got) === 'none',
        `list ${offered.length}, got ${showHelp(got)}`);
}
// No seasons, close-without-season off: the list is the booking's own slots and the text is the booking's times.
{
    const booking = bookingOn(WED_25, ['18:00', '20:00']);
    const offered = mod.offeredServingTimes(booking, RESOURCE, [], false);
    const got = mod.servingTimeHelp({isDelivery: false, offered, kitchenHoursKnown: false, kitchenSpan: null});
    check('pin open-always kitchen is the booking times', showHelp(got) === 'application', `got ${showHelp(got)}`);
}
// Delivery: the booking's own slots, named as the booking's times; a window with no grid start names nothing.
check('pin delivery 18-20 is application',
    showHelp(mod.servingTimeHelp({
        isDelivery: true, offered: mod.generateTimeSlots(18, 0, 20, 0), kitchenHoursKnown: true, kitchenSpan: null,
    })) === 'application');
check('pin delivery 18:07-18:10 has no grid start: none',
    showHelp(mod.servingTimeHelp({
        isDelivery: true, offered: mod.generateTimeSlots(18, 7, 18, 10), kitchenHoursKnown: true, kitchenSpan: null,
    })) === 'none');

const KINDS = ['kitchen', 'application', 'closed', 'none'] as const;
for (const kind of KINDS) {
    check(`grid exercises the ${kind} help`, (kinds.get(kind) ?? 0) > 0, `0 grid cases returned ${kind}`);
}

const pinChecks = passed + failures.length - checksBeforePins;
console.log(`grid: ${gridCases} cases (${KITCHEN_SETS.length} kitchen sets x ${WINDOWS.length} windows x 7 weekdays, on-site and delivery)`);
console.log(`help kinds in the grid: ${KINDS.map(k => `${k}=${kinds.get(k) ?? 0}`).join(' ')}`);
console.log(`pins: ${pinChecks}`);
console.log(`checks: ${passed + failures.length}, passed: ${passed}, failed: ${failures.length}`);
if (failures.length) {
    for (const f of failures.slice(0, 40)) console.log(`FAIL ${f}`);
    if (failures.length > 40) console.log(`... ${failures.length - 40} more`);
    process.exit(1);
}
console.log('GREEN');
