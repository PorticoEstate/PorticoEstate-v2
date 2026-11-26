/**
 * Hermetic check of the application form's overlap message (#1573), helper in
 * client/src/components/building-calendar/util/form-overlap.ts, wired in
 * client/src/components/building-calendar/modules/event/edit/application-crud.tsx.
 *
 *   node tests/application-form-overlap/run.mts
 *
 * #1573: a resident wanted a weekly painting course in «Spikeren» (Lervig bydelstorg), Thursdays 19-21 from
 * 21 January to 25 February 2027. With the repeat switch off, the form took start 21.01 19:00 and end 25.02 21:00
 * as ONE booking running non-stop for five weeks. That overlaps the Friday allocations 12-14 and an event on
 * 2 February, so the form said «Ressurs-overlapping oppdaget» and nothing else. Every Thursday 19-21 was free, so
 * the calendar the resident looked at was empty. The fixture below is the public schedule for that resource and
 * period (times and ids as served, names left out).
 *
 * This harness holds:
 *   1. the reproduction: the five-week span collides, and the FIRST collision is the allocation on Friday
 *      22.01 12:00-14:00 (not just "some" collision), so the form can name it;
 *   2. the intended booking, one Thursday 19-21, does not collide; edges that only touch do not collide;
 *   3. only deny-flagged resources count; an excluded id (the application being edited) does not count; an event
 *      with unreadable times still blocks, as before the change, but a readable collision is named first; resource
 *      names are joined with «og»/«and» in the UI language, Nynorsk also in a browser that has no Nynorsk list data
 *      (Chromium: Node's ICU has it, so the rows on the real Intl.ListFormat cannot see that, see 3d);
 *   4. spansMultipleDays: the five-week span does, a Thursday evening does not, an evening ending at midnight does
 *      not, an overnight booking does;
 *   5. the wiring, by source pattern: the form uses the helper, names the collision with overlap_conflict_at,
 *      adds overlap_multi_day_hint (incl. the repeat-until label and the last date) only when repeat is off and the
 *      time spans days, and the two keys exist in the no, nn and en .lang files with the placeholders the form passes.
 *
 * CLIENT_SRC / SETUP_DIR point the harness at other trees (to run it against base, where it fails).
 * Exit code: 0 all pass, 1 any failure (the helper being absent is a failure: the base RED), 2 the harness crashed.
 */
process.env.TZ = 'Europe/Oslo';

import {existsSync, readFileSync} from 'node:fs';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const clientSrc = process.env.CLIENT_SRC ?? join(repo, 'src/modules/bookingfrontend/client/src');
const setupDir = process.env.SETUP_DIR ?? join(repo, 'src/modules/bookingfrontend/setup');
const HELPER = join(clientSrc, 'components/building-calendar/util/form-overlap.ts');
const FORM = join(clientSrc, 'components/building-calendar/modules/event/edit/application-crud.tsx');

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
	const {findFirstFormConflict, joinNames, spansMultipleDays} = await import(pathToFileURL(HELPER).href);

	const SPIKEREN = 445, POLSESVINGEN = 443, MOTEPLASSEN = 444;
	// Public schedule for Spikeren, weeks of 18.01-22.02 2027 (Lervig bydelstorg, building 133).
	const schedule = [
		{type: 'allocation', id: 929998, from_: '2027-01-22T12:00:00+01:00', to_: '2027-01-22T14:00:00+01:00', resources: [{id: SPIKEREN}]},
		{type: 'allocation', id: 929999, from_: '2027-01-29T12:00:00+01:00', to_: '2027-01-29T14:00:00+01:00', resources: [{id: SPIKEREN}]},
		{type: 'allocation', id: 931916, from_: '2027-02-02T16:00:00+01:00', to_: '2027-02-02T19:30:00+01:00', resources: [{id: POLSESVINGEN}, {id: MOTEPLASSEN}, {id: SPIKEREN}]},
		{type: 'event', id: 103436, from_: '2027-02-02T16:00:00+01:00', to_: '2027-02-02T20:00:00+01:00', resources: [{id: MOTEPLASSEN}, {id: POLSESVINGEN}, {id: SPIKEREN}]},
		{type: 'allocation', id: 930000, from_: '2027-02-05T12:00:00+01:00', to_: '2027-02-05T14:00:00+01:00', resources: [{id: SPIKEREN}]},
		{type: 'allocation', id: 930001, from_: '2027-02-12T12:00:00+01:00', to_: '2027-02-12T14:00:00+01:00', resources: [{id: SPIKEREN}]},
		{type: 'allocation', id: 930002, from_: '2027-02-19T12:00:00+01:00', to_: '2027-02-19T14:00:00+01:00', resources: [{id: SPIKEREN}]},
		{type: 'allocation', id: 930003, from_: '2027-02-26T12:00:00+01:00', to_: '2027-02-26T14:00:00+01:00', resources: [{id: SPIKEREN}]},
	];
	const at = (iso: string) => new Date(iso);
	const firstId = (start: string, end: string, deny: number[], exclude?: number) =>
		findFirstFormConflict(schedule, at(start).getTime(), at(end).getTime(), deny, exclude)?.id ?? null;
	const eq = (name: string, got: unknown, want: unknown) => check(name, got === want, `got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`);

	// 1. the reproduction from the screenshot in #1573
	const reportedStart = '2027-01-21T19:00:00+01:00', reportedEnd = '2027-02-25T21:00:00+01:00';
	eq('#1573: five-week non-stop span collides, first with Fri 22.01 12-14', firstId(reportedStart, reportedEnd, [SPIKEREN]), 929998);
	eq('#1573: the span is several days', spansMultipleDays(at(reportedStart), at(reportedEnd)), true);

	// 2. what the resident meant: each Thursday 19-21 is free
	for (const day of ['01-21', '01-28', '02-04', '02-11', '02-18', '02-25']) {
		eq(`Thursday 2027-${day} 19-21 is free`, firstId(`2027-${day}T19:00:00+01:00`, `2027-${day}T21:00:00+01:00`, [SPIKEREN]), null);
	}
	eq('Tue 02.02 19:30-21:00 hits the event (ends 20:00), not the allocation (ends 19:30)', firstId('2027-02-02T19:30:00+01:00', '2027-02-02T21:00:00+01:00', [SPIKEREN]), 103436);
	eq('Tue 02.02 20:00-22:00 only touches the event: free', firstId('2027-02-02T20:00:00+01:00', '2027-02-02T22:00:00+01:00', [SPIKEREN]), null);
	eq('Fri 22.01 10:00-12:00 ends where the allocation starts: free', firstId('2027-01-22T10:00:00+01:00', '2027-01-22T12:00:00+01:00', [SPIKEREN]), null);
	eq('Fri 22.01 14:00-16:00 only touches the allocation: free', firstId('2027-01-22T14:00:00+01:00', '2027-01-22T16:00:00+01:00', [SPIKEREN]), null);

	// 3. only deny-flagged resources; excluded id
	eq('no deny-flagged resource selected: nothing collides', firstId(reportedStart, reportedEnd, []), null);
	eq('deny flag only on Møteplassen: first is the 02.02 allocation', firstId(reportedStart, reportedEnd, [MOTEPLASSEN]), 931916);
	eq('editing allocation 929998 itself: next one is named', firstId(reportedStart, reportedEnd, [SPIKEREN], 929998), 929999);

	// 3b. an event with unreadable times still blocks (as before), but a readable collision is named first
	const broken = {type: 'event', id: 1, from_: 'not a date', to_: 'not a date', resources: [{id: SPIKEREN}]};
	eq('unreadable event alone still blocks', findFirstFormConflict([broken], at('2027-01-21T19:00:00+01:00').getTime(), at('2027-01-21T21:00:00+01:00').getTime(), [SPIKEREN])?.id ?? null, 1);
	eq('readable collision is named before an unreadable one', findFirstFormConflict([broken, ...schedule], at(reportedStart).getTime(), at(reportedEnd).getTime(), [SPIKEREN])?.id ?? null, 929998);
	eq('unreadable event on another resource does not block', findFirstFormConflict([broken], 0, 1, [MOTEPLASSEN])?.id ?? null, null);

	// 3c. resource names joined in the form's language
	eq('one name', joinNames(['Spikeren'], 'no'), 'Spikeren');
	eq('bokmål list uses «og»', joinNames(['Møteplassen', 'Pølsesvingen', 'Spikeren'], 'no'), 'Møteplassen, Pølsesvingen og Spikeren');
	eq('nynorsk list uses «og»', joinNames(['Møteplassen', 'Spikeren'], 'nn'), 'Møteplassen og Spikeren');
	eq('english list uses «and»', joinNames(['Møteplassen', 'Pølsesvingen', 'Spikeren'], 'en'), 'Møteplassen, Pølsesvingen, and Spikeren');

	// 3d. a browser with no Nynorsk list data. Chromium has none: in Chrome 158
	// Intl.ListFormat.supportedLocalesOf(['nn', 'nb', 'no', 'en']) is ['nb', 'no', 'en'], so a bare 'nn' falls through to
	// the browser's default locale and an en-US Chrome printed «Møteplassen, Pølsesvingen, and Spikeren» in a Nynorsk
	// sentence. Node's ICU has 'nn', which is why the rows above cannot see it. The stand-in below puts that browser on top
	// of the real ICU data: a locale without data is dropped, and when none is left the browser default (en-US) answers.
	const RealListFormat = Intl.ListFormat;
	const asked: Array<string | string[] | undefined> = [];
	class ChromiumListFormat {
		constructor(locales?: string | string[], options?: Intl.ListFormatOptions) {
			asked.push(locales);
			const wanted = typeof locales === 'string' ? [locales] : (locales ?? []);
			const withData = wanted.filter(tag => !/^nn(-|$)/i.test(tag));
			return new RealListFormat(withData.length > 0 ? withData : 'en-US', options);
		}
	}
	const three = ['Møteplassen', 'Pølsesvingen', 'Spikeren'];
	const installListFormat = (impl: typeof Intl.ListFormat): void => { (Intl as {ListFormat: typeof Intl.ListFormat}).ListFormat = impl; };
	installListFormat(ChromiumListFormat as unknown as typeof Intl.ListFormat);
	try {
		// controls: the stand-in can fail the way Chrome does, and can succeed the way Chrome does with a fallback
		eq('control: the Chromium stand-in answers a bare «nn» in English', new Intl.ListFormat('nn', {style: 'long', type: 'conjunction'}).format(three), 'Møteplassen, Pølsesvingen, and Spikeren');
		eq('control: the Chromium stand-in answers [«nn», «nb»] with «og»', new Intl.ListFormat(['nn', 'nb'], {style: 'long', type: 'conjunction'}).format(three), 'Møteplassen, Pølsesvingen og Spikeren');

		asked.length = 0;
		eq('no Nynorsk list data: nynorsk list uses «og»', joinNames(three, 'nn'), 'Møteplassen, Pølsesvingen og Spikeren');
		const nynorskAsked = asked.flat();
		check('no Nynorsk list data: «nn» is asked for with «nb» after it', nynorskAsked.includes('nn') && nynorskAsked.indexOf('nb') > nynorskAsked.indexOf('nn'), JSON.stringify(asked));
		eq('no Nynorsk list data: two nynorsk names use «og»', joinNames(['Møteplassen', 'Spikeren'], 'nn'), 'Møteplassen og Spikeren');
		eq('no Nynorsk list data: bokmål list uses «og»', joinNames(three, 'no'), 'Møteplassen, Pølsesvingen og Spikeren');
		eq('no Nynorsk list data: english list uses «and»', joinNames(three, 'en'), 'Møteplassen, Pølsesvingen, and Spikeren');
		eq('no Nynorsk list data: an unset language falls back to bokmål «og»', joinNames(three, undefined), 'Møteplassen, Pølsesvingen og Spikeren');
	} finally {
		installListFormat(RealListFormat);
	}

	// 4. spansMultipleDays
	eq('Thursday 19-21 is one day', spansMultipleDays(at('2027-01-21T19:00:00+01:00'), at('2027-01-21T21:00:00+01:00')), false);
	eq('20:00 to midnight is one evening', spansMultipleDays(at('2027-01-21T20:00:00+01:00'), at('2027-01-22T00:00:00+01:00')), false);
	eq('22:00 to 02:00 is two days', spansMultipleDays(at('2027-01-21T22:00:00+01:00'), at('2027-01-22T02:00:00+01:00')), true);
	eq('end before start is not "several days"', spansMultipleDays(at('2027-01-22T10:00:00+01:00'), at('2027-01-21T10:00:00+01:00')), false);

	// 5. wiring
	const form = readFileSync(FORM, 'utf8');
	check('form imports the helper', /import\s*\{\s*findFirstFormConflict\s*,\s*joinNames\s*,\s*spansMultipleDays\s*\}\s*from\s*["']@\/components\/building-calendar\/util\/form-overlap["']/.test(form));
	check('form calls findFirstFormConflict', /findFirstFormConflict\(/.test(form));
	check('form names the collision with overlap_conflict_at {res, when}', /t\('bookingfrontend\.overlap_conflict_at',\s*\{res:[^}]*when\s*\}\)/.test(form));
	check('form adds the repeat hint only when not recurring and spanning days',
		/if\s*\(\s*!isRecurring\s*&&\s*spansMultipleDays\(startTime,\s*endTime\)\s*\)\s*\{\s*parts\.push\(t\('bookingfrontend\.overlap_multi_day_hint'/.test(form));
	check('form passes the repeat switch label as {toggle}', /toggle:\s*t\('bookingfrontend\.make_recurring'\)/.test(form));
	check('form passes the repeat-until label and the last date as {until} and {last}', /until:\s*t\('bookingfrontend\.repeat_until_date'\)/.test(form) && /last:\s*DateTime\.fromJSDate\(endTime\)/.test(form));
	check('form joins resource names with joinNames in the UI language', /res:\s*joinNames\(resourceNames,\s*i18n\?\.language\)/.test(form));
	check('form clears only its own overlap error', /errors\.resources\?\.type === 'overlap'/.test(form) && !/errors\.resources\?\.message === t\('bookingfrontend\.resource_overlap_detected'\)/.test(form));

	for (const lang of ['no', 'nn', 'en']) {
		const rows = readFileSync(join(setupDir, `phpgw_${lang}.lang`), 'utf8').split('\n');
		const row = (key: string) => rows.find(r => r.startsWith(`${key}\tbookingfrontend\t${lang}\t`))?.split('\t')[3] ?? '';
		const conflict = row('overlap_conflict_at'), hint = row('overlap_multi_day_hint');
		check(`${lang}: overlap_conflict_at has {{res}} and {{when}}`, conflict.includes('{{res}}') && conflict.includes('{{when}}'), JSON.stringify(conflict));
		check(`${lang}: overlap_multi_day_hint has {{from}}, {{to}}, {{toggle}}, {{until}}, {{last}}`, ['{{from}}', '{{to}}', '{{toggle}}', '{{until}}', '{{last}}'].every(p => hint.includes(p)), JSON.stringify(hint));
	}
} catch (e) {
	console.error('HARNESS CRASHED:', e);
	process.exit(2);
}

console.log(failures === 0 ? '\nall pass' : `\n${failures} failure(s)`);
process.exit(failures === 0 ? 0 : 1);
