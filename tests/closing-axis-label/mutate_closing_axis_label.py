#!/usr/bin/env python3
"""
Mutation check for tests/closing-axis-label/run.mts (#1537, GO pe-pm 48080).

One deliberate defect at a time, applied to a TEMP COPY of the helper (util/closing-axis-label.ts). The harness runs on
each copy through CLIENT_SRC; the working tree is never written. The unmutated copy must be green first, every
replacement must match exactly once, and a mutant counts as KILLED only when all hold:
  - the harness exits 1 (0 = it stayed green; 2 = it crashed, and a crash kills nothing);
  - it prints no CRASH line;
  - it prints FAIL on every row the defect breaks ("must fail" below);
  - it prints ok on every row the defect leaves alone ("must stay ok" below).
So a mutant that turns everything red is not a kill of its own rows.

  M1  exclusive bound reinstated: the label is the slot one slot before the closing time, the same exclusive bound
      FullCalendar's slat loop uses (slatTime < slotMaxTime). Breaks the clock rows; midnight is a separate path.
  M2  midnight mapping dropped: the end of the day is written as the engine's own midnight, '00:00'. Breaks the
      midnight rows; the clock rows stay.
  M3  late-close threshold made exclusive: 23:45 no longer counts as the end of the day, so it prints '23:45'.
      Breaks 23:45 only; 24:00:00 stays.

Usage:  python3 tests/closing-axis-label/mutate_closing_axis_label.py
Exit:   0 when the original is green and every mutant is killed on its own rows, 1 otherwise.
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
CLIENT_SRC = os.path.join(ROOT, "src", "modules", "bookingfrontend", "client", "src")
HARNESS = os.path.join(HERE, "run.mts")
NODE = os.environ.get("NODE_BIN", "node")

HELPER = "components/building-calendar/util/closing-axis-label.ts"
VIEW = "components/building-calendar/views/calendar/full-calendar-view.tsx"
FILES = [HELPER, VIEW]

LOCALES = ["nb-NO", "en-GB", "en-US"]
CLOCK_ROWS = ["22:00:00", "21:30:00", "09:30:00"]
MIDNIGHT_ROWS = ["23:45:00", "24:00:00"]
JUNK = ["undefined", "null", '""', '"22"', '"banana"', '"25:00:00"', '"24:30:00"', '"22:75:00"']


def clock_row(time, locale):
    return f"closingAxisLabel('{time}', '{locale}')"


def junk_row(value):
    return f"closingAxisLabel({value}, 'nb-NO')"


def rows(times):
    return [clock_row(t, loc) for t in times for loc in LOCALES]


JUNK_ROWS = [junk_row(v) for v in JUNK]

# (id, what the defect is, the text in the helper to replace, its replacement, rows that must FAIL, rows that must stay ok)
MUTANTS = [
    (
        "M1",
        "exclusive bound reinstated: the slot before the closing time",
        "new Date(2000, 0, 3, hours, minutes)",
        "new Date(2000, 0, 3, hours, minutes - 30)",
        rows(CLOCK_ROWS),
        rows(MIDNIGHT_ROWS) + JUNK_ROWS,
    ),
    (
        "M2",
        "midnight mapping dropped: the end of the day reads the engine's '00:00'",
        "endOfDay && part.type === 'hour' ? '24' : part.value",
        "part.value",
        rows(MIDNIGHT_ROWS),
        rows(CLOCK_ROWS) + JUNK_ROWS,
    ),
    (
        "M3",
        "late-close threshold made exclusive: 23:45 is not the end of the day",
        "hours * 60 + minutes >= END_OF_DAY_FROM_MINUTES",
        "hours * 60 + minutes > END_OF_DAY_FROM_MINUTES",
        rows(["23:45:00"]),
        rows(CLOCK_ROWS + ["24:00:00"]) + JUNK_ROWS,
    ),
]


def helper_text():
    with open(os.path.join(CLIENT_SRC, HELPER), encoding="utf8") as handle:
        return handle.read()


def build_copy(name, helper):
    """A temp tree holding the two files the harness reads, with the helper text given."""
    tmp = tempfile.mkdtemp(prefix=f"closing-axis-{name}-")
    for rel in FILES:
        dst = os.path.join(tmp, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        if rel == HELPER:
            text = helper
        else:
            with open(os.path.join(CLIENT_SRC, rel), encoding="utf8") as handle:
                text = handle.read()
        with open(dst, "w", encoding="utf8") as handle:
            handle.write(text)
    return tmp


def run_harness(src_dir):
    proc = subprocess.run(
        [NODE, HARNESS],
        env={**os.environ, "CLIENT_SRC": src_dir},
        capture_output=True,
        text=True,
    )
    return proc.returncode, proc.stdout + proc.stderr


def check_rows(output, must_fail, must_ok):
    lines = output.splitlines()
    missing_fail = [r for r in must_fail if not any(line.startswith(f"FAIL {r}") for line in lines)]
    missing_ok = [r for r in must_ok if not any(line.startswith(f"ok   {r}") for line in lines)]
    return missing_fail, missing_ok


def main():
    base = helper_text()
    tmp = build_copy("original", base)
    try:
        code, out = run_harness(tmp)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    if code != 0 or "PASS closing-axis-label" not in out or "CRASH" in out:
        print("FAIL the unmutated copy is not green")
        print(out)
        return 1
    print("ok   original: green (harness exit 0)")

    survivors = 0
    for mutant_id, what, old, new, must_fail, must_ok in MUTANTS:
        if base.count(old) != 1:
            print(f"FAIL {mutant_id}: the text to mutate is not in {HELPER} exactly once")
            survivors += 1
            continue
        tmp = build_copy(mutant_id, base.replace(old, new))
        try:
            code, out = run_harness(tmp)
        finally:
            shutil.rmtree(tmp, ignore_errors=True)

        missing_fail, missing_ok = check_rows(out, must_fail, must_ok)
        crashed = "CRASH" in out or code == 2
        killed = code == 1 and not crashed and not missing_fail and not missing_ok
        verdict = "killed" if killed else "SURVIVED"
        print(f"{verdict:<9} {mutant_id}: {what} (harness exit {code}; "
              f"{len(must_fail)} rows must FAIL, {len(must_ok)} rows must stay ok)")
        for row in missing_fail:
            print(f"          not red where it must be: {row}")
        for row in missing_ok:
            print(f"          not ok where it must be: {row}")
        if crashed:
            print("          the harness crashed on this copy: a crash kills nothing")
        if not killed:
            survivors += 1

    if survivors:
        print(f"FAIL {survivors} mutant(s) not killed on their own rows")
        return 1
    print(f"PASS all {len(MUTANTS)} mutants killed on their own rows")
    return 0


if __name__ == "__main__":
    sys.exit(main())
