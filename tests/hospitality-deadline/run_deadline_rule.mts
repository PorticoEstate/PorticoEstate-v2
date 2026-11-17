/**
 * The change / cancel RULE stated once on Min side (task 39791, GH #1423): utils/catering-deadline-rule.ts.
 *
 *   node tests/hospitality-deadline/run_deadline_rule.mts
 *
 * What it holds the rule to:
 *   1. the offers of the local test database, as measured (kitchen 3/4: cancel 2 DAYS; 5/6: cancel 0 = none,
 *      cutoff 24 h; 5: Saturday closed): which SENTENCE each gets, and the working-days note;
 *   2. every sentence kind is reachable, including the one the local data cannot produce (the order cutoff
 *      LONGER than the cancellation deadline → change and cancel differ);
 *   3. THE RULE AGREES WITH THE GUARD'S MIRROR: for a grid of cutoff x cancellation leads, in every open-days
 *      mask and on every weekday, the instant a change closes at (hospitalityActionWindow('change').until)
 *      is exactly the deadline of the lead the rule names for «change», and for «cancel» likewise. A rule
 *      that named the SHORTER of the two leads for a change would be caught here (control: it differs);
 *   4. not configured (null, 0, unknown unit) closes nothing; the unit is read case-insensitively;
 *   5. weeks ignore the open days, so a weeks-only rule has no working-days note;
 *   6. ruleGroups: equal rules merge, different rules stay apart with their offer names, an order whose offer
 *      is missing says nothing, and nothing derivable is an empty list (no Alert).
 *
 * Loaded through temp copies named .mts with the one relative import pointed at the copy (as
 * run_serving_days.mts does). HD_ROOT (optional) = a tree holding src/, for a mutation driver.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.HD_ROOT ?? resolve(here, '../..');
const utils = join(root, 'src/modules/bookingfrontend/client/src/utils');

const dir = mkdtempSync(join(tmpdir(), 'deadline-rule-'));
writeFileSync(join(dir, 'hospitality-deadline.mts'), readFileSync(join(utils, 'hospitality-deadline.ts'), 'utf8'));
const ruleSource = readFileSync(join(utils, 'catering-deadline-rule.ts'), 'utf8');
const rewritten = ruleSource.replace("from './hospitality-deadline'", "from './hospitality-deadline.mts'");
if (rewritten === ruleSource) throw new Error("the module no longer imports './hospitality-deadline' as expected");
writeFileSync(join(dir, 'catering-deadline-rule.mts'), rewritten);
const R: any = await import(pathToFileURL(join(dir, 'catering-deadline-rule.mts')).href);
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
const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

const offer = (id: number, cutoff: [number | null, string | null], cancel: [number | null, string | null], openDays: number[] | null = null) => ({
    id,
    order_by_time_value: cutoff[0], order_by_time_unit: cutoff[1],
    resource_cancellation_deadline_value: cancel[0], resource_cancellation_deadline_unit: cancel[1],
    open_days_list: openDays,
});

// 1. the local offers, as measured in bb_hospitality / bb_resource (2026-11-10)
const k3 = offer(3, [null, null], [2, 'days']);
const k4 = offer(4, [8, 'hours'], [2, 'days']);
const k5 = offer(5, [24, 'hours'], [0, 'hours'], [1, 2, 3, 4, 5, 7]); // open_days 95: Saturday closed
const k6 = offer(6, [24, 'hours'], [0, 'hours']);
const sentence = (config: any) => R.sentenceOf(R.deriveRule(config));

check('kitchen 4 (cutoff 8 h, cancel 2 days): the 2-day deadline closes BOTH, so ONE sentence «2 days»',
    same(sentence(k4), {kind: 'both', lead: {value: 2, unit: 'days'}}), JSON.stringify(sentence(k4)));
check('kitchen 3 (no cutoff, cancel 2 days): the same one sentence', same(sentence(k3), {kind: 'both', lead: {value: 2, unit: 'days'}}));
check('kitchen 6 (cutoff 24 h, cancel 0 = none): change 24 hours, cancel open until the serving starts',
    same(sentence(k6), {kind: 'split_open', change: {value: 24, unit: 'hours'}}), JSON.stringify(sentence(k6)));
check('kitchen 5: the same two sentences, plus the working-days note (Saturday closed)',
    sentence(k5).kind === 'split_open' && R.deriveRule(k5).workingDays === true && R.deriveRule(k6).workingDays === false);
check('control: a rule with all seven days open never has the note', R.deriveRule(offer(1, [24, 'hours'], [0, null], [1, 2, 3, 4, 5, 6, 7])).workingDays === false);

// 2. every sentence kind is reachable
check('cutoff 48 h LONGER than cancel 8 h: change and cancel differ (split)',
    same(sentence(offer(7, [48, 'hours'], [8, 'hours'])), {kind: 'split', change: {value: 48, unit: 'hours'}, cancel: {value: 8, unit: 'hours'}}));
check('24 hours and 24 hours: both', sentence(offer(7, [24, 'hours'], [24, 'hours'])).kind === 'both');
check('cutoff 1 day, cancel 24 hours (a tie in different units): the 24 hours closes first, so change = cancel = 24 hours (both)',
    same(sentence(offer(7, [1, 'days'], [24, 'hours'])), {kind: 'both', lead: {value: 24, unit: 'hours'}}));
check('cutoff 24 hours, cancel 1 day: change 24 hours, cancel 1 day (split)',
    same(sentence(offer(7, [24, 'hours'], [1, 'days'])), {kind: 'split', change: {value: 24, unit: 'hours'}, cancel: {value: 1, unit: 'days'}}));
check('nothing configured: none', same(sentence(offer(7, [null, null], [null, null])), {kind: 'none'}));
check('only a cutoff: split_open', sentence(offer(7, [12, 'hours'], [null, null])).kind === 'split_open');

// 4+5. not configured, case, weeks
check('0, null and an unknown unit are not a lead',
    R.leadOf(0, 'hours') === null && R.leadOf(null, 'hours') === null && R.leadOf(3, null) === null && R.leadOf(3, 'months') === null && R.leadOf(-1, 'days') === null);
check('the unit is read case-insensitively', same(R.leadOf(2, 'HOURS'), {value: 2, unit: 'hours'}));
check('weeks ignore the open days: no note', R.deriveRule(offer(1, [null, null], [1, 'weeks'], [1, 2, 3, 4, 5])).workingDays === false);
check('control: the same mask with DAYS has the note', R.deriveRule(offer(1, [null, null], [1, 'days'], [1, 2, 3, 4, 5])).workingDays === true);

// 3. the rule agrees with the guard's mirror
{
    const leads: Array<[number | null, string | null]> = [
        [null, null], [0, 'hours'], [8, 'hours'], [24, 'hours'], [36, 'hours'], [48, 'hours'],
        [1, 'days'], [2, 'days'], [3, 'days'], [1, 'weeks'],
    ];
    const masks: Array<number[] | null> = [null, [1, 2, 3, 4, 5], [1, 2, 3, 4, 5, 7], [2, 3, 4, 5, 6]];
    const longAgo = new Date(2020, 0, 1);
    let compared = 0;
    let weekdaysSeen = new Set<number>();
    for (const cutoff of leads) {
        for (const cancel of leads) {
            for (const mask of masks) {
                const config = offer(1, cutoff, cancel, mask);
                const rule = R.deriveRule(config);
                for (let day = 14; day <= 20; day++) { // 14 Dec 2026 is a Monday .. 20 Dec a Sunday
                    const serving = new Date(2026, 11, day, 10, 0);
                    weekdaysSeen.add(serving.getDay());
                    for (const action of ['change', 'cancel'] as const) {
                        const window = D.hospitalityActionWindow(action, {
                            anchor: serving,
                            orderBy: {value: config.order_by_time_value, unit: config.order_by_time_unit},
                            cancelBy: {value: config.resource_cancellation_deadline_value, unit: config.resource_cancellation_deadline_unit},
                            openDays: mask,
                        }, longAgo);
                        const lead = action === 'change' ? rule.change : rule.cancel;
                        const want = lead === null ? serving : D.computeHospitalityDeadline(serving, lead.value, lead.unit, mask);
                        compared++;
                        if (!window.allowed || window.until.getTime() !== want.getTime()) {
                            check(`rule vs guard mirror: ${action}`, false,
                                JSON.stringify({cutoff, cancel, mask, day, lead, until: window.until?.toISOString(), want: want.toISOString()}));
                        }
                    }
                }
            }
        }
    }
    check(`the named lead closes exactly when the guard's mirror says, on ${compared} cases over ${weekdaysSeen.size} weekdays`, compared === leads.length ** 2 * masks.length * 7 * 2 && weekdaysSeen.size === 7);
    // control: had the rule named the SHORTER lead for a change, this grid would have caught it
    const mirror = (c: [number | null, string | null], x: [number | null, string | null]) => {
        const serving = new Date(2026, 11, 16, 10, 0);
        return D.hospitalityActionWindow('change', {
            anchor: serving, orderBy: {value: c[0], unit: c[1]}, cancelBy: {value: x[0], unit: x[1]}, openDays: null,
        }, longAgo).until.getTime();
    };
    const serving = new Date(2026, 11, 16, 10, 0);
    check('control: cutoff 48 h / cancel 8 h — a change closes 48 h before serving, NOT 8 h',
        mirror([48, 'hours'], [8, 'hours']) === serving.getTime() - 48 * 3600000 && mirror([48, 'hours'], [8, 'hours']) !== serving.getTime() - 8 * 3600000);
}

// 6. ruleGroups
{
    const order = (id: number, hospitality_id: number, hospitality_name: string) => ({id, hospitality_id, hospitality_name});
    const offers = [k4, k5, k6, offer(9, [24, 'hours'], [2, 'days'])];
    const one = R.ruleGroups([order(1, 4, 'Kantine'), order(2, 9, 'Lunsj')], offers);
    check('two offers with the same rule: ONE group naming both', one.length === 1 && same(one[0].offerNames, ['Kantine', 'Lunsj']), JSON.stringify(one));
    const two = R.ruleGroups([order(1, 4, 'Kantine'), order(2, 6, 'Mat og drikke'), order(3, 4, 'Kantine')], offers);
    check('two different rules: two groups, each with its offer name once, in the order first met',
        two.length === 2 && same(two[0].offerNames, ['Kantine']) && same(two[1].offerNames, ['Mat og drikke']), JSON.stringify(two));
    const gone = R.ruleGroups([order(1, 4, 'Kantine'), order(2, 77, 'Switched off')], offers);
    check('an order whose offer is not in the list says nothing', gone.length === 1 && same(gone[0].offerNames, ['Kantine']));
    check('nothing derivable (no offers / no orders) is an empty list, never a guess',
        R.ruleGroups([order(1, 77, 'x')], offers).length === 0 && R.ruleGroups([], offers).length === 0 && R.ruleGroups([order(1, 4, 'x')], []).length === 0);
    const working = R.ruleGroups([order(1, 5, 'Fana'), order(2, 6, 'Fana mat')], offers);
    check('same lead, one offer with Saturday closed and one open every day: TWO groups (the note differs)', working.length === 2);
}

if (fail) {
    console.log(`Deadline-rule harness: FAIL ${fail} of ${n} checks`);
    process.exit(1);
}
console.log(`Deadline-rule harness: PASS ${n} checks on node ${process.version}`);
