#!/usr/bin/env python3
"""
Mutation check for run_serving_days.mts (Min side catering list per day, task 36875).

One deliberate defect at a time is applied to a TEMP COPY of the files the harness reads (never to
the tree), and the harness must go red for each. The baseline on the same copy must be green first,
and every replacement must match exactly once.

Expected survivor, not hidden: dropping the nn -> no mapping in localeFor. Node ships Nynorsk data
and answers «torsdag» either way; it is Chromium that has none and falls back to en-US (measured in
Chrome 156), which a Node harness cannot reproduce.

Usage:  python3 tests/hospitality-deadline/mutate_serving_days.py
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
NODE = os.environ.get("NODE_BIN", "node")
HARNESS = os.path.join(HERE, "run_serving_days.mts")

CLIENT = "src/modules/bookingfrontend/client/src"
VENUE = f"{CLIENT}/utils/catering-venue-time.ts"
OVERVIEW = f"{CLIENT}/components/user/application/catering-overview.tsx"
FILES = [
    f"{CLIENT}/utils/hospitality-deadline.ts",
    VENUE,
    OVERVIEW,
    "src/modules/bookingfrontend/setup/phpgw_no.lang",
    "src/modules/bookingfrontend/setup/phpgw_en.lang",
    "src/modules/bookingfrontend/setup/phpgw_nn.lang",
]

# (id, file, what, old, new)
MUTANTS = [
    ("M1", VENUE, "the venue zone is UTC", "timeZone: VENUE_TIMEZONE", "timeZone: 'UTC'"),
    ("M2", VENUE, "no zone chosen: the browser's", "{...options, timeZone: VENUE_TIMEZONE}", "{...options}"),
    ("M3", VENUE, "the day key is a UTC date", "dayKey: `${year}-${month}-${day}`", "dayKey: new Date(ms).toISOString().slice(0, 10)"),
    ("M4", VENUE, "sorted by id only", "a.moment.ms - b.moment.ms || a.order.id - b.order.id", "a.order.id - b.order.id"),
    ("M5", VENUE, "no tie-break on id", "a.moment.ms - b.moment.ms || a.order.id - b.order.id", "a.moment.ms - b.moment.ms"),
    ("M6", VENUE, "sorted by the shown time, not the instant",
     "a.moment.ms - b.moment.ms || a.order.id - b.order.id",
     "a.moment.dayKey.localeCompare(b.moment.dayKey) || a.moment.time.localeCompare(b.moment.time) || a.order.id - b.order.id"),
    ("M7", VENUE, "orders without a serving time come first", "groups.push({dayKey: null", "groups.unshift({dayKey: null"),
    ("M8", VENUE, "one group for everything", "if (last && last.dayKey === moment.dayKey) {", "if (last) {"),
    ("M9", VENUE, "midnight reads 24:00", "hourCycle: 'h23'", "hourCycle: 'h24'"),
    ("M10", VENUE, "the current year is read in UTC", "venueParts(nowMs).year", "String(new Date(nowMs).getUTCFullYear())"),
    ("M11", VENUE, "the year is always shown", "const showYear = venueParts(ms).year !== venueParts(nowMs).year;", "const showYear = true;"),
    ("M12", VENUE, "the year is never shown", "const showYear = venueParts(ms).year !== venueParts(nowMs).year;", "const showYear = false;"),
    ("M13", VENUE, "the heading is not capitalised", "return first.toLocaleUpperCase(locale) + rest.join('');", "return text;"),
    ("M14", VENUE, "an unreadable serving time is not caught in servingMoment",
     "    const ms = Date.parse(iso);\n    if (!Number.isFinite(ms)) return null;\n    const {year,", "    const ms = Date.parse(iso);\n    const {year,"),
    ("M15", VENUE, "an unreadable deadline is not caught", "    if (!Number.isFinite(ms)) return '';", ""),
    ("M16", VENUE, "the deadline keeps the browser zone while the serving time moves",
     "return venueFormat('nb-NO', {\n        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',\n    }).format(ms);",
     "return new Date(ms).toLocaleDateString('nb-NO', {\n        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',\n    });"),
    ("M17", VENUE, "the nn -> no mapping is dropped (EXPECTED SURVIVOR: Node has Nynorsk data)",
     "const locale = localeFor(lang);", "const locale = lang === 'en' ? 'en-GB' : lang === 'nn' ? 'nn' : 'nb-NO';"),
    ("C1", OVERVIEW, "cancelled orders are grouped too", "groupByServingDay(orders, i18n.language, now)", "groupByServingDay(ordersQuery.data ?? [], i18n.language, now)"),
    ("C2", OVERVIEW, "the order heading stays h3 next to the day h3", "<Heading level={4} data-size=\"xs\">", "<Heading level={3} data-size=\"xs\">"),
    ("C3", OVERVIEW, "the order formats a time itself", "{time ?? '-'}", "{time ?? new Date().toLocaleTimeString()}"),
    ("C4", OVERVIEW, "the group without a time has no heading", "?? t('bookingfrontend.catering_no_serving_time')", "?? ''"),
    ("C5", OVERVIEW, "the deadline hint formats in the browser zone",
     "formatVenueDeadline(actions.change.until)", "new Date(actions.change.until).toLocaleString('nb-NO')"),
    ("C6", OVERVIEW, "the language is not the UI's", "groupByServingDay(orders, i18n.language, now)", "groupByServingDay(orders, 'no', now)"),
    ("L1", "src/modules/bookingfrontend/setup/phpgw_nn.lang", "the nn translation is missing",
     "catering_no_serving_time\tbookingfrontend\tnn\tUtan serveringstid", "catering_no_serving_time_x\tbookingfrontend\tnn\tUtan serveringstid"),
]


def run(tree):
    env = dict(os.environ)
    env["HD_ROOT"] = tree
    p = subprocess.run([NODE, HARNESS], env=env, capture_output=True, text=True)
    out = (p.stdout + p.stderr).strip().splitlines()
    first_fail = next((l for l in out if l.startswith("FAIL")), "")
    return p.returncode, (out[-1] if out else ""), first_fail


def fresh_tree():
    tree = tempfile.mkdtemp(prefix="hd-days-mut-")
    for rel in FILES:
        dst = os.path.join(tree, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copyfile(os.path.join(ROOT, rel), dst)
    return tree


def main():
    base = fresh_tree()
    rc, last, _ = run(base)
    print(f"baseline  rc={rc}  {last}")
    shutil.rmtree(base, ignore_errors=True)
    if rc != 0:
        print("BASELINE IS RED - mutation results would mean nothing")
        return 2

    killed, survived, bad = 0, [], 0
    for ident, rel, what, old, new in MUTANTS:
        tree = fresh_tree()
        path = os.path.join(tree, rel)
        text = open(path, encoding="utf-8").read()
        if text.count(old) != 1:
            print(f"{ident:4} BAD MUTANT  {what}: pattern matches {text.count(old)} times")
            bad += 1
            shutil.rmtree(tree, ignore_errors=True)
            continue
        open(path, "w", encoding="utf-8").write(text.replace(old, new))
        rc, last, first_fail = run(tree)
        shutil.rmtree(tree, ignore_errors=True)
        if rc != 0:
            killed += 1
            print(f"{ident:4} killed    {what}\n       {first_fail or last}")
        else:
            survived.append((ident, what))
            print(f"{ident:4} SURVIVED  {what}")

    print(f"\n{killed}/{len(MUTANTS)} killed, {len(survived)} survived, {bad} bad")
    for ident, what in survived:
        print(f"  survivor {ident}: {what}")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
