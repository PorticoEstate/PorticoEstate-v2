/**
 * Hermetic check of closingAxisLabel (#1537, GO pe-pm 48080): the label for the closing time at the bottom of the
 * building calendar's time axis, in client/src/components/building-calendar/util/closing-axis-label.ts.
 *
 *   node tests/closing-axis-label/run.mts
 *
 * FullCalendar draws a slot label only while slatTime < slotMaxTime (@fullcalendar/timegrid internal.js:1082), so
 * the closing time itself never gets one. The helper derives that one label. This harness holds it to:
 *   1. the table: the slotMaxTime the view passes -> the label, every expected string written by hand. 22:00, 21:30
 *      and 09:30 keep their clock time; 23:45 and 24:00:00 both give «24:00» (from 23:45 the view already draws to
 *      midnight, see setSlotMaxTime, and midnight reads «24:00», never «00:00»);
 *   2. the same format as the other axis labels: for a clock time the label equals what the view's slotLabelFormat
 *      prints for it, with that format written out here by hand, in each locale the calendar uses. A control shows
 *      the formatter really prints the 2-digit clock, so the comparison can fail;
 *   3. junk in, null out: a missing or malformed slotMaxTime draws no label instead of a wrong one;
 *   3a. off the grid's lines, null out: the grid ends on a slot line (slotDuration 30 minutes), so a slotMaxTime whose
 *      minute is not 00 or 30 (22:15, 21:45) ends on the next line and any label there would be false. 21:30 and 14:30
 *      keep theirs; the end of the day (23:45 on) is the one exception and still reads «24:00»;
 *   4. the wiring, by source pattern: the view imports the helper and its one SLOT_LABEL_FORMAT, passes that format to
 *      slotLabelFormat, and still holds slotMaxTime and eventConstraint as they were on base. The byte-for-byte proof is
 *      the diff; these patterns only stop the wiring from drifting while the helper changes. Also here: SLOT_MINUTES is
 *      the view's slotDuration, the label is drawn only in the time-grid views (the TSX cannot be imported, so the gate
 *      is held by pattern), the label sits in flow after the calendar inside the wrapper, and the wrapper takes the grid
 *      area the calendar had (without it every building calendar is 14px narrower at desktop width).
 *
 * CLIENT_SRC points the harness at another client src/ tree. tests/closing-axis-label/mutate_closing_axis_label.py
 * uses it to run this same file on mutated copies.
 *
 * Exit code: 0 all pass, 1 any failure (the helper being absent is a failure: that is the base RED), 2 the harness
 * itself crashed.
 */
import {existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const clientSrc = process.env.CLIENT_SRC ?? join(repo, 'src/modules/bookingfrontend/client/src');
const HELPER = 'components/building-calendar/util/closing-axis-label.ts';
const VIEW = 'components/building-calendar/views/calendar/full-calendar-view.tsx';
const STYLES = 'components/building-calendar/building-calender.module.scss';

let fail = 0;
const check = (name: string, pass: boolean): void => {
	if (pass) {
		console.log(`ok   ${name}`);
	} else {
		fail++;
		console.log(`FAIL ${name}`);
	}
};

// ---- the expectations, written by hand ----------------------------------------------------------------------------
// the locales the calendar is rendered in (the view passes DateTime.local().locale to FullCalendar)
const LOCALES = ['nb-NO', 'en-GB', 'en-US'];
/** [slotMaxTime as the view passes it, the closing label] */
const ROWS: Array<[string, string]> = [
	['22:00:00', '22:00'],
	['21:30:00', '21:30'],
	['14:30:00', '14:30'],
	['09:30:00', '09:30'],
	['23:30:00', '23:30'],
	['23:45:00', '24:00'],
	['24:00:00', '24:00'],
];
/** slotMaxTime values that are clock times but not on a slot line (minute not 00 or 30): no label, in every locale */
const OFF_SLAT: string[] = ['22:15:00', '21:45:00', '09:10:00', '23:15:00', '23:44:00'];
const JUNK: unknown[] = [undefined, null, '', '22', 'banana', '25:00:00', '24:30:00', '22:75:00'];
/** the view's slotLabelFormat, as the inline literal it was on base (full-calendar-view.tsx:1000) */
const VIEW_SLOT_LABEL_FORMAT = {hour: '2-digit', minute: '2-digit', hour12: false};
/** the base text of the two rules that must not move (whitespace is normalised before the comparison) */
const EVENT_CONSTRAINT = `const eventConstraint = useMemo(() => ({
	businessHours: businessHours,
	startTime: slotMinTime,
	// Use the calculated slotMaxTime which respects boundaries
	// but also extends to midnight when appropriate
	endTime: slotMaxTime
}), [businessHours, slotMinTime, slotMaxTime]);`;
const SET_SLOT_MAX_TIME = `setSlotMaxTime(
	maxTime === "00:00:00" || // No boundaries found
	maxTime >= "23:45:00"     // Season closes very late
		? '24:00:00'          // Allow until midnight
		: maxTime             // Otherwise respect the boundary
);`;

const norm = (s: string): string => s.replace(/\s+/g, ' ').trim();
const show = (v: unknown): string => (v === undefined ? 'undefined' : JSON.stringify(v));

// ---- the helper ----------------------------------------------------------------------------------------------------
type Closing = (slotMaxTime: unknown, locale: string) => string | null;
let closingAxisLabel: Closing | undefined;
let slotLabelFormat: unknown;
let slotMinutes: unknown;

const helperFile = join(clientSrc, HELPER);
if (!existsSync(helperFile)) {
	check(`${HELPER} exists (absent on base: no closing label can be derived)`, false);
} else {
	// The client has no "type" field, so Node reads the .ts helper as CommonJS first and warns when it re-parses it.
	// A temp .mts copy is loaded instead, as tests/format-hours/run.mts does; the copy is removed straight after.
	const copyDir = mkdtempSync(join(tmpdir(), 'closing-axis-label-'));
	try {
		const copy = join(copyDir, 'closing-axis-label.mts');
		writeFileSync(copy, readFileSync(helperFile, 'utf8'));
		const mod = await import(pathToFileURL(copy).href);
		closingAxisLabel = typeof mod.closingAxisLabel === 'function' ? mod.closingAxisLabel : undefined;
		slotLabelFormat = mod.SLOT_LABEL_FORMAT;
		slotMinutes = mod.SLOT_MINUTES;
		check(`${HELPER} exports closingAxisLabel`, closingAxisLabel !== undefined);
	} catch (error) {
		check(`${HELPER} loads (${(error as Error).message})`, false);
	} finally {
		rmSync(copyDir, {recursive: true, force: true});
	}
}

const call = (input: unknown, locale: string): string | null | 'THREW' => {
	if (!closingAxisLabel) return 'THREW';
	try {
		return closingAxisLabel(input, locale);
	} catch {
		return 'THREW';
	}
};

// 1. the table
for (const locale of LOCALES) {
	for (const [input, want] of ROWS) {
		const got = call(input, locale);
		check(`closingAxisLabel('${input}', '${locale}') → «${String(got)}», want «${want}»`, got === want);
	}
	for (const input of OFF_SLAT) {
		const got = call(input, locale);
		check(`closingAxisLabel('${input}', '${locale}') → «${String(got)}», want «null» (not on a slot line)`, got === null);
	}
}

// 2. the same format as the axis labels
check(
	"SLOT_LABEL_FORMAT is the view's slotLabelFormat options",
	JSON.stringify(slotLabelFormat) === JSON.stringify(VIEW_SLOT_LABEL_FORMAT),
);
for (const locale of LOCALES) {
	const axisFormat = new Intl.DateTimeFormat(locale, VIEW_SLOT_LABEL_FORMAT);
	for (const [input, h, m] of [['22:00:00', 22, 0], ['21:30:00', 21, 30], ['09:30:00', 9, 30]] as const) {
		const axis = axisFormat.format(new Date(2000, 0, 3, h, m));
		check(`control [${locale}] the view's format prints ${input} as «${axis}» (2-digit clock)`, /^\d{2}:\d{2}$/.test(axis));
		check(
			`same format [${locale}] ${input} → «${String(call(input, locale))}» equals the axis label «${axis}»`,
			call(input, locale) === axis,
		);
	}
	const midnight = new Intl.DateTimeFormat(locale, VIEW_SLOT_LABEL_FORMAT).format(new Date(2000, 0, 3, 0, 0));
	console.log(`info [${locale}] this engine's own formatter prints midnight as «${midnight}»; the helper must print «24:00» whatever it prints`);
}

// 3. junk in, null out
for (const input of JUNK) {
	const got = call(input, 'nb-NO');
	check(`closingAxisLabel(${show(input)}, 'nb-NO') → null, got «${String(got)}»`, got === null);
}

// 4. the wiring, by source pattern
const view = readFileSync(join(clientSrc, VIEW), 'utf8');
const importClause = /import\s*\{([^}]*)\}\s*from\s*["']@\/components\/building-calendar\/util\/closing-axis-label["']/.exec(view);
const imported = importClause ? importClause[1].split(',').map(name => name.trim()) : [];
check(
	'view imports closingAxisLabel and SLOT_LABEL_FORMAT from building-calendar/util/closing-axis-label',
	imported.includes('closingAxisLabel') && imported.includes('SLOT_LABEL_FORMAT'),
);
check('view passes SLOT_LABEL_FORMAT to the timeGrid slotLabelFormat (one formatter)', /slotLabelFormat:\s*SLOT_LABEL_FORMAT\b/.test(view));
check('view keeps no second inline slotLabelFormat literal', !/slotLabelFormat:\s*\{/.test(view));
check('view renders the label from closingAxisLabel(slotMaxTime, ...)', /closingAxisLabel\(\s*slotMaxTime\s*,/.test(view));
check("slotMaxTime state unchanged: useState('24:00:00')", view.includes("const [slotMaxTime, setSlotMaxTime] = useState('24:00:00');"));
check('slotMaxTime still passed to FullCalendar as slotMaxTime={slotMaxTime}', view.includes('slotMaxTime={slotMaxTime}'));
check('setSlotMaxTime rule unchanged (whitespace-normalised)', norm(view).includes(norm(SET_SLOT_MAX_TIME)));
check('eventConstraint unchanged (whitespace-normalised)', norm(view).includes(norm(EVENT_CONSTRAINT)));
check(
	'the label is drawn only in the time-grid views: viewMode.startsWith(\'timeGrid\') gates closingAxisLabel (dayGrid and list have no time axis)',
	/viewMode\.startsWith\(\s*['"]timeGrid['"]\s*\)\s*\?\s*closingAxisLabel\(/.test(view),
);
check(
	'SLOT_MINUTES is the view\'s slotDuration={"00:30:00"} in minutes (30), so the off-slat rule and the grid agree',
	view.includes('slotDuration={"00:30:00"}') && slotMinutes === 30,
);
check(
	'the label is a div in flow right after the calendar, inside the calendarBody wrapper (a band under the grid, not an overlay)',
	/<div className=\{styles\.calendarBody\}>\s*\{calendar\}\s*\{closingLabel\s*&&\s*<div className=\{styles\.closingAxisLabel\}>\{closingLabel\}<\/div>\}\s*<\/div>/.test(view),
);

// 4a. the stylesheet: the wrapper takes the calendar's grid area, and the band is in flow
const stylesFile = join(clientSrc, STYLES);
const stylesText = existsSync(stylesFile) ? readFileSync(stylesFile, 'utf8') : '';
const ruleBody = (selector: string): string | null => new RegExp(`\\${selector}\\s*\\{([^}]*)\\}`).exec(stylesText)?.[1] ?? null;
const wrapperRule = ruleBody('.calendarBody');
const bandRule = ruleBody('.closingAxisLabel');
check(
	'.calendarBody takes grid-area: calendar-body, the area the calendar had (else it is auto-placed in one column, 14px narrower)',
	wrapperRule !== null && /(^|[;\s])grid-area\s*:\s*calendar-body\s*;/.test(wrapperRule),
);
check('.calendar still names the calendar-body area in grid-template-areas', /grid-template-areas\s*:[^;]*"[^"]*\bcalendar-body\b[^"]*"/.test(stylesText));
check(
	'.closingAxisLabel is in flow: no position declaration (an absolute label would overlay the grid instead of getting its own band)',
	bandRule !== null && !/(^|[;\s])position\s*:/.test(bandRule),
);

console.log(fail === 0 ? 'PASS closing-axis-label: every check holds' : `FAIL closing-axis-label: ${fail} check(s) failed`);
process.exitCode = fail === 0 ? 0 : 1;
