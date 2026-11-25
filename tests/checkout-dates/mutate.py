#!/usr/bin/env python3
"""
Mutation check for run.mts (the date texts of the checkout's catering section in the UI language, task 47995 6a).

One deliberate defect at a time is applied to a TEMP COPY of the three files the harness reads (never to the
tree), and the harness must go red for each. The baseline on the same copy must be green first, and every
replacement must match exactly once.

Usage:  python3 tests/checkout-dates/mutate.py
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
NODE = os.environ.get("NODE_BIN", "node")
HARNESS = os.path.join(HERE, "run.mts")

CLIENT = "src/modules/bookingfrontend/client/src"
DEADLINE = f"{CLIENT}/utils/hospitality-deadline.ts"
SECTION = f"{CLIENT}/components/checkout/hospitality/hospitality-section.tsx"
MODAL = f"{CLIENT}/components/checkout/hospitality/hospitality-order-modal.tsx"
FILES = [DEADLINE, SECTION, MODAL]

# (id, file, what, old, new)
MUTANTS = [
    ("D1", DEADLINE, "English maps to nb-NO",
     "if (lang === 'en') return 'en-GB';", "if (lang === 'en') return 'nb-NO';"),
    ("D2", DEADLINE, "formatDayMonth is hard-wired to nb-NO",
     "return date.toLocaleDateString(localeFor(lang), {day: dayStyleFor(lang), month: 'short'});",
     "return date.toLocaleDateString('nb-NO', {day: dayStyleFor(lang), month: 'short'});"),
    ("D3", DEADLINE, "formatDeadlineDateTime forgets its locale (the machine's default decides)",
     "return date.toLocaleDateString(localeFor(lang), {\n        day: dayStyleFor(lang), month: 'short', year",
     "return date.toLocaleDateString(undefined, {\n        day: dayStyleFor(lang), month: 'short', year"),
    ("D4", DEADLINE, "English keeps the zero-padded day",
     "return lang === 'en' ? 'numeric' : '2-digit';", "return '2-digit';"),
    ("D5", DEADLINE, "the clock of a notice loses its zero padding",
     "const time = `${String(date.getHours()).padStart(2, '0')}:", "const time = `${String(date.getHours())}:"),
    ("D6", DEADLINE, "Norwegian gets numeric days (no longer byte-identical)",
     "return lang === 'en' ? 'numeric' : '2-digit';", "return 'numeric';"),
    ("D7", SECTION, "the section's serving time goes back to a nb-NO literal",
     "const dateStr = formatDayMonth(date, lang);",
     "const dateStr = date.toLocaleDateString('nb-NO', {day: '2-digit', month: 'short'});"),
    ("D8", MODAL, "the dialog's deadline text goes back to a nb-NO literal",
     ".replace('%1', formatDeadlineDateTime(cancelBy, i18n.language)),",
     ".replace('%1', cancelBy.toLocaleDateString('nb-NO', {day: '2-digit', month: 'short'})),"),
    ("D9", MODAL, "the dialog's day label is hard-wired again",
     "const locale = localeFor(lang);", "const locale = 'nb-NO';"),
    ("D10", SECTION, "the section's notice names the serving time without the UI language",
     "formatDayMonthTime(anchor, lang)", "formatDayMonthTime(anchor, 'no')"),
]


def run(tree):
    env = dict(os.environ)
    env["CD_ROOT"] = tree
    p = subprocess.run([NODE, HARNESS], env=env, capture_output=True, text=True)
    out = (p.stdout + p.stderr).strip().splitlines()
    first_fail = next((l for l in out if l.startswith("FAIL")), "")
    return p.returncode, (out[-1] if out else ""), first_fail


def fresh_tree():
    tree = tempfile.mkdtemp(prefix="checkout-dates-mut-")
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
    return 1 if (bad or survived) else 0


if __name__ == "__main__":
    sys.exit(main())
