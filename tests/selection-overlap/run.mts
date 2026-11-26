/**
 * Hermetic check of findSelectionOverlap (#1573), in
 * client/src/components/building-calendar/util/selection-overlap.ts.
 *
 *   node tests/selection-overlap/run.mts
 *
 * #1573: a person picked a time in the building calendar and got «Ressurs-overlapping oppdaget» although the
 * calendar showed no booking there. The selection only touched closed time (the grey background outside opening
 * hours), which the view reported with the same text as an overlap with a real booking.
 *
 * This harness holds the helper to:
 *   1. closed time only -> 'closed', never 'booked';
 *   2. a booking/allocation/event on a deny-overlap resource -> 'booked' (with its title), and 'booked' wins over
 *      closed time when both are touched;
 *   3. without the deny flag, bookings do not block, closed time still does;
 *   4. touching edges is not an overlap; a free selection -> null;
 *   5. the wiring, by source pattern: the view imports the helper and shows outside_opening_hours for 'closed'.
 *
 * Exit code: 0 all pass, 1 any failure (the helper being absent is a failure), 2 the harness itself crashed.
 */
import {existsSync, readFileSync} from 'node:fs';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const clientSrc = process.env.CLIENT_SRC ?? join(repo, 'src/modules/bookingfrontend/client/src');
const HELPER = join(clientSrc, 'components/building-calendar/util/selection-overlap.ts');
const VIEW = join(clientSrc, 'components/building-calendar/views/calendar/full-calendar-view.tsx');

let failures = 0;
const check = (name: string, ok: boolean, detail = ''): void => {
	console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${ok || !detail ? '' : `  -- ${detail}`}`);
	if (!ok) failures++;
};

try {
	if (!existsSync(HELPER)) {
		check('helper exists', false, HELPER);
		process.exit(1);
	}
	const {findSelectionOverlap} = await import(pathToFileURL(HELPER).href);

	const t = (hhmm: string): number => Date.parse(`2027-01-12T${hhmm}:00Z`);
	const closedEvening = {start: t('22:00'), end: Date.parse('2027-01-13T00:00:00Z'), type: 'background', closed: true};
	const closedMorning = {start: Date.parse('2027-01-12T00:00:00Z'), end: t('08:00'), type: 'background', closed: true};
	const event = {start: t('18:00'), end: t('20:00'), type: 'event', title: 'Korøvelse'};
	const allocation = {start: t('16:00'), end: t('18:00'), type: 'allocation', title: 'Idrettslaget'};

	const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);
	const run = (name: string, got: unknown, want: unknown) => check(name, same(got, want), `got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`);

	// 1. closed time only
	run('closed time only, deny flag on -> closed', findSelectionOverlap([closedEvening, event], t('21:00'), t('23:00'), true), {kind: 'closed'});
	run('closed time only, deny flag off -> closed', findSelectionOverlap([closedEvening], t('21:00'), t('23:00'), false), {kind: 'closed'});
	run('all-day selection over closed morning -> closed', findSelectionOverlap([closedMorning], t('07:00'), t('09:00'), true), {kind: 'closed'});

	// 2. real bookings
	run('event on deny resource -> booked', findSelectionOverlap([event], t('19:00'), t('21:00'), true), {kind: 'booked', title: 'Korøvelse'});
	run('allocation on deny resource -> booked', findSelectionOverlap([allocation], t('17:00'), t('17:30'), true), {kind: 'booked', title: 'Idrettslaget'});
	run('booked wins over closed time', findSelectionOverlap([closedEvening, event], t('19:00'), t('23:00'), true), {kind: 'booked', title: 'Korøvelse'});

	// 3. no deny flag
	run('event without deny flag -> allowed', findSelectionOverlap([event], t('19:00'), t('21:00'), false), {kind: null});
	run('event without deny flag but closed time -> closed', findSelectionOverlap([event, closedEvening], t('19:00'), t('23:00'), false), {kind: 'closed'});

	// 4. edges and free time
	run('ends where event starts -> allowed', findSelectionOverlap([event, closedEvening], t('17:00'), t('18:00'), true), {kind: null});
	run('starts where closed time starts ends -> allowed', findSelectionOverlap([closedMorning], t('08:00'), t('09:00'), true), {kind: null});
	run('free selection -> allowed', findSelectionOverlap([event, allocation, closedEvening], t('20:00'), t('22:00'), true), {kind: null});
	run('no candidates -> allowed', findSelectionOverlap([], t('10:00'), t('11:00'), true), {kind: null});

	// 5. wiring
	const view = readFileSync(VIEW, 'utf8');
	check('view imports findSelectionOverlap', /import\s*\{\s*findSelectionOverlap\s*\}\s*from\s*["']@\/components\/building-calendar\/util\/selection-overlap["']/.test(view));
	check('view calls findSelectionOverlap', /findSelectionOverlap\(/.test(view));
	check("view shows outside_opening_hours for 'closed'", /overlap\.kind === 'closed'[\s\S]{0,200}bookingfrontend\.outside_opening_hours/.test(view));
	check("view shows resource_overlap_detected only for 'booked'", /overlap\.kind === 'booked'[\s\S]{0,200}bookingfrontend\.resource_overlap_detected/.test(view)
		&& (view.match(/bookingfrontend\.resource_overlap_detected/g) ?? []).length === 1);
} catch (e) {
	console.error('HARNESS CRASHED:', e);
	process.exit(2);
}

console.log(failures === 0 ? '\nall pass' : `\n${failures} failure(s)`);
process.exit(failures === 0 ? 0 : 1);
