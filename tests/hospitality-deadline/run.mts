/**
 * TypeScript side of the catering deadline parity harness (GH #1423).
 *
 *   TZ=Europe/Oslo node tests/hospitality-deadline/run.mts
 *
 * The twin of run.php: the same cases.json (expected values from the independent Python oracle),
 * run through the client mirror in hospitality-deadline.ts. The PHP-only lock cases are skipped
 * (the client never evaluates billed / paid / application status — the server returns them).
 *
 * The client code is plain TypeScript with type annotations only, so Node strips the types itself
 * (Node >= 22.18). DEADLINE_TS (optional) points at an alternative copy — mutate.py uses it to
 * prove that a deliberately broken mirror turns this harness red. CASES_FILE overrides the case file.
 *
 * The mirror runs in the machine's wall clock, which for the venue must be Europe/Oslo; the runner
 * refuses to run otherwise rather than report a false pass or fail.
 *
 * Exit code: 0 all pass, 1 any failure, 2 bad environment.
 */
import {copyFileSync, mkdtempSync, readFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');

// Europe/Oslo: UTC+2 on 2026-07-01, UTC+1 on 2026-01-01 (getTimezoneOffset is minutes WEST of UTC).
const summer = new Date(2026, 6, 1, 12).getTimezoneOffset();
const winter = new Date(2026, 0, 1, 12).getTimezoneOffset();
if (summer !== -120 || winter !== -60) {
    console.error(`TS harness: process zone is not Europe/Oslo (summer ${summer}, winter ${winter}); run with TZ=Europe/Oslo`);
    process.exit(2);
}

// Import through a temp copy named .mts so the module format is unambiguous whatever the
// nearest package.json says.
const source = process.env.DEADLINE_TS
    ?? join(root, 'src/modules/bookingfrontend/client/src/utils/hospitality-deadline.ts');
const dir = mkdtempSync(join(tmpdir(), 'hd-ts-'));
const copy = join(dir, 'hospitality-deadline.mts');
copyFileSync(source, copy);
const mirror = await import(pathToFileURL(copy).href);

const cases = JSON.parse(readFileSync(process.env.CASES_FILE ?? join(here, 'cases.json'), 'utf8'));

const decodeOpenDays = (mask: number): number[] => {
    const list: number[] = [];
    for (let iso = 1; iso <= 7; iso++) if (mask & (1 << (iso - 1))) list.push(iso);
    return list;
};
const secs = (d: Date | null): number | null => (d === null ? null : Math.floor(d.getTime() / 1000));
const when = (iso: string | null): Date | null => (iso === null ? null : new Date(iso));

const failures: string[] = [];
let deadlines = 0;
let actions = 0;

for (const c of cases.deadlines) {
    deadlines++;
    const got = secs(mirror.computeHospitalityDeadline(when(c.event), c.value, c.unit, decodeOpenDays(c.open_days)));
    if (got !== c.expected) {
        failures.push(`[deadlines] ${c.name}: got ${got} want ${c.expected} (event ${c.event} ${c.value} ${c.unit} mask ${c.open_days})`);
    }
}

for (const c of cases.actions) {
    actions++;
    if (typeof mirror.hospitalityActionWindow !== 'function') {
        failures.push(`[actions] ${c.name}: the mirror has no hospitalityActionWindow`);
        continue;
    }
    const r = mirror.hospitalityActionWindow(c.action, {
        anchor: when(c.anchor),
        movedTo: when(c.moved_to),
        orderBy: c.order_by,
        cancelBy: c.cancel_by,
        openDays: decodeOpenDays(c.open_days),
    }, when(c.now));
    const got = {allowed: r.allowed, reason: r.reason, until: secs(r.until)};
    if (JSON.stringify(got) !== JSON.stringify(c.expected)) {
        failures.push(`[actions] ${c.name}: got ${JSON.stringify(got)} want ${JSON.stringify(c.expected)}`);
    }
}

const total = deadlines + actions;
if (failures.length) {
    for (const line of failures.slice(0, 25)) console.log(line);
    if (failures.length > 25) console.log(`... and ${failures.length - 25} more`);
    console.log(`TS harness: FAIL ${failures.length} of ${total} cases ({"deadlines":${deadlines},"actions":${actions}}; ${cases.locks.length} PHP-only lock cases skipped)`);
    process.exit(1);
}
console.log(`TS harness: PASS ${total} cases ({"deadlines":${deadlines},"actions":${actions}}; ${cases.locks.length} PHP-only lock cases skipped) on node ${process.version}`);
