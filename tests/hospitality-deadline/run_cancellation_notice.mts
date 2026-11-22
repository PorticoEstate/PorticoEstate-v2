/**
 * The cancellation notice of the checkout's catering section: utils/catering-cancellation-notice.ts, which
 * counts each order's deadline back from ITS OWN serving time and never from the earliest date of the other
 * applications in the cart (the defect: an unrelated earlier application turned a cancellable order «past»).
 *
 *   node tests/hospitality-deadline/run_cancellation_notice.mts
 *
 * What it holds the module to (fixtures are the measured local ones: offer 4, kitchen resource 1, cancel 2 DAYS):
 *   1. THE REPRO: an unrelated earlier application with no order does not move the order's deadline;
 *   2. two orders, two deadlines: the SOONEST open one is named, whatever the order they arrive in;
 *   3. a past order next to an open one: the open date is kept AND «some past» is raised; all past: no open date;
 *   4. open ⇔ now ≤ deadline: exactly AT the deadline is still open, one millisecond later is past;
 *   5. an order without a serving time counts from its OWN application, never from another;
 *   6. NO BASIS = no date, never «past» and never the group: no serving time and no own dates, own application
 *      not in the list, unreadable values, an offer that is not sent, a lead that is not configured;
 *   7. cancelled / delivered orders contribute nothing (HospitalityOrderGuard::FINAL_ORDER_STATUSES);
 *   8. the deadline IS computeHospitalityDeadline's (open days, hours, weeks), the function the dialog uses;
 *   9. earliestApplicationDate: THIS application's earliest date only (the helper the order dialog shares).
 *
 * Loaded through temp copies named .mts with the one relative import pointed at the copy (as
 * run_deadline_rule.mts does). HD_ROOT (optional) = a tree holding src/, for the mutation driver.
 * The deadline is counted on the wall clock of the machine: the zone is pinned to the venue's below.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
process.env.TZ = 'Europe/Oslo';

import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.HD_ROOT ?? resolve(here, '../..');
const utils = join(root, 'src/modules/bookingfrontend/client/src/utils');

const dir = mkdtempSync(join(tmpdir(), 'cancel-notice-'));
writeFileSync(join(dir, 'hospitality-deadline.mts'), readFileSync(join(utils, 'hospitality-deadline.ts'), 'utf8'));
const source = readFileSync(join(utils, 'catering-cancellation-notice.ts'), 'utf8');
const rewritten = source.replace("from './hospitality-deadline'", "from './hospitality-deadline.mts'");
if (rewritten === source) throw new Error("the module no longer imports './hospitality-deadline' as expected");
writeFileSync(join(dir, 'catering-cancellation-notice.mts'), rewritten);
const N: any = await import(pathToFileURL(join(dir, 'catering-cancellation-notice.mts')).href);
const D: any = await import(pathToFileURL(join(dir, 'hospitality-deadline.mts')).href);

let fail = 0;
let n = 0;
const check = (name: string, ok: boolean, detail = '') => {
    n++;
    if (!ok) {
        fail++;
        console.log(`FAIL ${name} ${detail}`);
    }
};
const ms = (iso: string) => new Date(iso).getTime();
const show = (notice: any) => JSON.stringify(notice && {soonestOpen: notice.soonestOpen?.toISOString() ?? null, somePast: notice.somePast});

// The measured cart (local test database, 2026-11-23): all in Vitalitetsenteret, December = CET (+01:00).
const NOW = ms('2026-11-23T00:02:00+01:00');
const offer = (id: number, value: number | null, unit: string | null, openDays: number[] | null = null) => ({
    id, resource_cancellation_deadline_value: value, resource_cancellation_deadline_unit: unit, open_days_list: openDays,
});
const kantine = offer(4, 2, 'days');
const app = (id: number, ...from: string[]) => ({id, dates: from.map(f => ({from_: f}))});
const order = (application_id: number, serving: string | null | undefined, status = 'pending', hospitality_id = 4) =>
    ({application_id, hospitality_id, status, serving_time_iso: serving});

const A = app(85110, '2026-12-03T12:00:00+01:00');          // the order's own application
const B = app(85111, '2026-11-24T10:00:00+01:00');          // unrelated, EARLIER, no order
const D2 = app(85121, '2026-12-10T12:00:00+01:00');
const E = app(85112, '2026-12-05T12:00:00+01:00');
const C = app(85120, '2026-11-24T12:00:00+01:00');          // its order's deadline (22 Nov 12:00) is past
const oA = order(85110, '2026-12-03T12:00:00+01:00');       // cancel-by 01. des. 12:00
const oD = order(85121, '2026-12-10T12:00:00+01:00');       // cancel-by 08. des. 12:00
const oC = order(85120, '2026-11-24T12:00:00+01:00');       // cancel-by 22. nov. 12:00: past
const DEC1_NOON = ms('2026-12-01T12:00:00+01:00');
const DEC8_NOON = ms('2026-12-08T12:00:00+01:00');

// 1. THE REPRO
{
    const alone = N.cancellationNotice([oA], [kantine], [A], NOW);
    const withB = N.cancellationNotice([oA], [kantine], [A, B], NOW);
    check('repro: the order alone is open until 01. des. 12:00', alone?.soonestOpen?.getTime() === DEC1_NOON && alone.somePast === false, show(alone));
    check('repro: an unrelated EARLIER application does not move it (and does not make it past)',
        withB?.soonestOpen?.getTime() === DEC1_NOON && withB.somePast === false, show(withB));
    check('repro: the earlier application may come first in the list',
        N.cancellationNotice([oA], [kantine], [B, A], NOW)?.soonestOpen?.getTime() === DEC1_NOON);
    check('repro: with the earlier application\'s date the OLD rule would have said past (control: 22 nov < now)',
        D.computeHospitalityDeadline(new Date('2026-11-24T10:00:00+01:00'), 2, 'days', null).getTime() < NOW);
}

// 2. two orders, two deadlines
{
    const forward = N.cancellationNotice([oA, oD], [kantine], [A, D2, B], NOW);
    const backward = N.cancellationNotice([oD, oA], [kantine], [B, D2, A], NOW);
    check('two deadlines: the SOONEST open one', forward?.soonestOpen?.getTime() === DEC1_NOON && forward.somePast === false, show(forward));
    check('two deadlines: the same whatever order they arrive in', show(forward) === show(backward));
    check('two deadlines: the later one alone is its own date', N.cancellationNotice([oD], [kantine], [D2, B], NOW)?.soonestOpen?.getTime() === DEC8_NOON);
}

// 3. a past order next to an open one / all past
{
    const mixed = N.cancellationNotice([oC, oA], [kantine], [C, A, B], NOW);
    check('mixed: the open date is KEPT and some-past is raised', mixed?.soonestOpen?.getTime() === DEC1_NOON && mixed.somePast === true, show(mixed));
    check('mixed: the same the other way round', show(mixed) === show(N.cancellationNotice([oA, oC], [kantine], [A, C, B], NOW)));
    const past = N.cancellationNotice([oC], [kantine], [C], NOW);
    check('all past: no open date, some-past', past?.soonestOpen === null && past.somePast === true, show(past));
    const twoPast = N.cancellationNotice([oC, order(85120, '2026-11-23T10:00:00+01:00')], [kantine], [C], NOW);
    check('all past (two orders): no open date', twoPast?.soonestOpen === null && twoPast.somePast === true, show(twoPast));
    check('no orders: nothing to say', N.cancellationNotice([], [kantine], [A], NOW) === null);
}

// 4. the boundary
{
    const at = N.cancellationNotice([oA], [kantine], [A], DEC1_NOON);
    const after = N.cancellationNotice([oA], [kantine], [A], DEC1_NOON + 1);
    check('boundary: exactly AT the deadline is still open', at?.soonestOpen?.getTime() === DEC1_NOON && at.somePast === false, show(at));
    check('boundary: one millisecond later is past', after?.soonestOpen === null && after.somePast === true, show(after));
    check('boundary: now may be a Date or milliseconds',
        show(N.cancellationNotice([oA], [kantine], [A], new Date(DEC1_NOON + 1))) === show(after));
}

// 5. NULL serving time: the order's OWN application
{
    const oE = order(85112, null);
    const got = N.cancellationNotice([oE], [kantine], [A, B, E], NOW);
    check('no serving time: counted from its OWN application (5 Dec 12:00 - 2 days = 3 Dec 12:00)',
        got?.soonestOpen?.getTime() === ms('2026-12-03T12:00:00+01:00') && got.somePast === false, show(got));
    check('no serving time: the omitted key reads the same as null', show(N.cancellationNotice([order(85112, undefined)], [kantine], [A, B, E], NOW)) === show(got));
    const stored = N.cancellationNotice([order(85110, '2026-12-10T12:00:00+01:00')], [kantine], [A, B], NOW);
    check('a stored serving time wins over the application\'s date (10 Dec, not 3 Dec)', stored?.soonestOpen?.getTime() === DEC8_NOON, show(stored));
}

// 6. NO BASIS = no date: never past, never the group
{
    const F = app(85130);                                      // no dates at all
    const noDates = order(85130, null);
    check('no basis: no serving time and no own dates, with an earlier application in the group: NOTHING (not past, not B)',
        N.cancellationNotice([noDates], [kantine], [F, B], NOW) === null, show(N.cancellationNotice([noDates], [kantine], [F, B], NOW)));
    const next = N.cancellationNotice([noDates, oA], [kantine], [F, B, A], NOW);
    check('no basis: it neither raises some-past nor hides the other order', next?.soonestOpen?.getTime() === DEC1_NOON && next.somePast === false, show(next));
    check('no basis: its own application is not in the list: NOTHING, not the group\'s earliest',
        N.cancellationNotice([order(99999, null)], [kantine], [A, B], NOW) === null);
    check('no basis: no applications at all: nothing',
        N.cancellationNotice([order(85130, null)], [kantine], [], NOW) === null);
    check('no basis: an unreadable serving time counts as none (falls to the own application)',
        N.cancellationNotice([order(85110, 'not a date')], [kantine], [A, B], NOW)?.soonestOpen?.getTime() === DEC1_NOON);
    check('no basis: unreadable serving time AND unreadable own date: nothing',
        N.cancellationNotice([order(85131, 'not a date')], [kantine], [app(85131, 'also not a date'), B], NOW) === null);
    check('no offer: the offer is not in the list (switched off): nothing, and the order does not hide another',
        N.cancellationNotice([order(85110, '2026-12-03T12:00:00+01:00', 'pending', 77)], [kantine], [A, B], NOW) === null);
    for (const [label, value, unit] of [['0', 0, 'days'], ['null value', null, 'days'], ['null unit', 2, null], ['unknown unit', 2, 'fortnights']] as const) {
        check(`no lead configured (${label}): closes nothing, so nothing to say`,
            N.cancellationNotice([oA], [offer(4, value, unit)], [A, B], NOW) === null);
    }
    check('a lead unit is read case-insensitively (as computeHospitalityDeadline)',
        N.cancellationNotice([oA], [offer(4, 2, 'DAYS')], [A], NOW)?.soonestOpen?.getTime() === DEC1_NOON);
}

// 7. final statuses
{
    for (const status of ['cancelled', 'delivered']) {
        check(`a ${status} order contributes nothing (even though its deadline is past)`,
            N.cancellationNotice([order(85120, '2026-11-24T12:00:00+01:00', status)], [kantine], [C], NOW) === null);
        const next = N.cancellationNotice([order(85120, '2026-11-24T12:00:00+01:00', status), oA], [kantine], [C, A], NOW);
        check(`a ${status} order does not raise some-past next to an open one`, next?.soonestOpen?.getTime() === DEC1_NOON && next.somePast === false, show(next));
    }
    for (const status of ['pending', 'confirmed']) {
        check(`a ${status} order counts`, N.cancellationNotice([order(85110, '2026-12-03T12:00:00+01:00', status)], [kantine], [A], NOW) !== null);
    }
    check('the final statuses are exactly the guard\'s two', JSON.stringify(N.FINAL_ORDER_STATUSES) === JSON.stringify(['cancelled', 'delivered']));
}

// 8. the deadline is computeHospitalityDeadline's
{
    const monday = '2026-12-07T12:00:00+01:00';
    const weekdays = offer(4, 2, 'days', [1, 2, 3, 4, 5]);
    const want = D.computeHospitalityDeadline(new Date(monday), 2, 'days', [1, 2, 3, 4, 5]);
    const got = N.orderCancelBy(order(85110, monday), [weekdays], [A]);
    check('open days: a Monday serving, 2 working days back = Thursday 12:00', got?.getTime() === ms('2026-12-03T12:00:00+01:00') && got.getTime() === want.getTime(), got?.toISOString());
    check('open days: the same lead on a calendar-days offer is Saturday, so the mask is read',
        N.orderCancelBy(order(85110, monday), [kantine], [A])?.getTime() === ms('2026-12-05T12:00:00+01:00'));
    check('hours: 48 h back', N.orderCancelBy(order(85110, monday), [offer(4, 48, 'hours')], [A])?.getTime() === ms(monday) - 48 * 3600000);
    check('weeks: 1 week back, the open days do not apply',
        N.orderCancelBy(order(85110, monday), [offer(4, 1, 'weeks', [1, 2, 3, 4, 5])], [A])?.getTime() === ms('2026-11-30T12:00:00+01:00'));
}

// 9. earliestApplicationDate
{
    const early = D.earliestApplicationDate(app(1, '2026-12-05T12:00:00+01:00', '2026-12-03T12:00:00+01:00', '2026-12-09T12:00:00+01:00'));
    check('earliestApplicationDate: the earliest of the application\'s dates', early?.getTime() === ms('2026-12-03T12:00:00+01:00'));
    check('earliestApplicationDate: an application without dates, a null/absent dates list and no application are null',
        D.earliestApplicationDate(app(2)) === null && D.earliestApplicationDate({dates: null}) === null
        && D.earliestApplicationDate({}) === null && D.earliestApplicationDate(undefined) === null);
    check('earliestApplicationDate: reads ONE application: another earlier one is never consulted',
        D.earliestApplicationDate(A)?.getTime() === ms('2026-12-03T12:00:00+01:00'));
}

if (fail) {
    console.log(`Cancellation-notice harness: FAIL ${fail} of ${n} checks`);
    process.exit(1);
}
console.log(`Cancellation-notice harness: PASS ${n} checks on node ${process.version}`);
