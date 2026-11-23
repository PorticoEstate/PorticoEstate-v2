#!/usr/bin/env python3
"""
Mutation check for run_cancellation_notice.mts (the checkout catering cancellation notice).

One deliberate defect at a time is applied to a TEMP COPY of the files the harness reads (never to
the tree), and the harness must go red for each. The baseline on the same copy must be green first,
and every replacement must match exactly once.

M1 is the defect this notice was written to remove: the anchor is the earliest date of ALL applications
in the cart (the building group) instead of the order's own. It must go red on the repro row.

Usage:  python3 tests/hospitality-deadline/mutate_cancellation_notice.py
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
NODE = os.environ.get("NODE_BIN", "node")
HARNESS = os.path.join(HERE, "run_cancellation_notice.mts")

CLIENT = "src/modules/bookingfrontend/client/src"
DEADLINE = f"{CLIENT}/utils/hospitality-deadline.ts"
NOTICE = f"{CLIENT}/utils/catering-cancellation-notice.ts"
FILES = [DEADLINE, NOTICE]

OWN_APP = "earliestApplicationDate(applications.find(application => application.id === order.application_id))"
ALL_DATES = "{dates: applications.flatMap(application => application.dates ?? [])}"

# (id, file, what, old, new)
MUTANTS = [
    ("M1", NOTICE, "THE DEFECT: the anchor is the earliest date of ALL applications in the cart",
     f"    return servingTimeOf(order.serving_time_iso)\n        ?? {OWN_APP};",
     f"    return earliestApplicationDate({ALL_DATES});"),
    ("M2", NOTICE, "own application not in the list: fall back to the group",
     f"?? {OWN_APP};",
     f"?? earliestApplicationDate(applications.find(application => application.id === order.application_id) ?? {ALL_DATES});"),
    ("M3", NOTICE, "own application has no dates: fall back to the group",
     f"?? {OWN_APP};",
     f"?? {OWN_APP}\n        ?? earliestApplicationDate({ALL_DATES});"),
    ("M4", NOTICE, "final statuses are not excluded",
     "    if (FINAL_ORDER_STATUSES.includes(order.status)) return null;\n", ""),
    ("M5", NOTICE, "the deadline itself is already past (open is exclusive)",
     "if (!isDeadlineOpen(now, cancelBy)) {",
     "if (!((typeof now === 'number' ? now : now.getTime()) < cancelBy.getTime())) {"),
    ("M6", NOTICE, "the LATEST open deadline is named", "cancelBy < soonestOpen", "cancelBy > soonestOpen"),
    ("M7", NOTICE, "a past order hides the open order's date (and the open orders)",
     "return soonestOpen === null && !somePast ? null : {soonestOpen, somePast, past, open};",
     "return soonestOpen === null && !somePast ? null : {soonestOpen: somePast ? null : soonestOpen, somePast, past, open: somePast ? [] : open};"),
    ("M8", NOTICE, "no basis is counted as past",
     "if (anchor === null || !Number.isFinite(anchor.getTime())) return null;",
     "if (anchor === null || !Number.isFinite(anchor.getTime())) return new Date(0);"),
    ("M9", NOTICE, "the stored serving time is ignored",
     f"    return servingTimeOf(order.serving_time_iso)\n        ?? {OWN_APP};",
     f"    return {OWN_APP};"),
    ("M10", NOTICE, "an unreadable serving time is accepted as it is",
     "return Number.isFinite(date.getTime()) ? date : null;", "return date;"),
    ("M11", NOTICE, "the offer's open days are ignored",
     "        offer.open_days_list,\n    );", "        null,\n    );"),
    ("M12", NOTICE, "an offer that is not sent counts as past",
     "if (!offer) return null;", "if (!offer) return new Date(0);"),
    ("M13", NOTICE, "a lead that is not configured counts as past",
     "return cancelBy !== null && Number.isFinite(cancelBy.getTime()) ? cancelBy : null;",
     "return cancelBy !== null && Number.isFinite(cancelBy.getTime()) ? cancelBy : new Date(0);"),
    ("M14", NOTICE, "only cancelled is final (delivered still counts)",
     "['cancelled', 'delivered']", "['cancelled']"),
    ("M15", DEADLINE, "earliestApplicationDate returns the LATEST date",
     "if (!earliest || from < earliest) earliest = from;", "if (!earliest || from > earliest) earliest = from;"),
    # ---- WHICH ORDER (task 47995, record 47209): the past and open lists ----
    ("N1", NOTICE, "only the FIRST past order is named",
     "past.push(order);", "if (past.length === 0) past.push(order);"),
    ("N2", NOTICE, "only the FIRST open order is named",
     "open.push({order, cancelBy});", "if (open.length === 0) open.push({order, cancelBy});"),
    ("N3", NOTICE, "the open orders are sorted LATEST first",
     "open.sort((a, b) => a.cancelBy.getTime() - b.cancelBy.getTime() ||",
     "open.sort((a, b) => b.cancelBy.getTime() - a.cancelBy.getTime() ||"),
    ("N4", NOTICE, "a past order is put in the open list",
     "past.push(order);", "open.push({order, cancelBy});"),
    ("N5", NOTICE, "the open orders are hidden when some order is past (R1 breach), soonestOpen kept",
     "return soonestOpen === null && !somePast ? null : {soonestOpen, somePast, past, open};",
     "return soonestOpen === null && !somePast ? null : {soonestOpen, somePast, past, open: somePast ? [] : open};"),
    ("N6", NOTICE, "an open entry names the WRONG order (the first one it was given)",
     "open.push({order, cancelBy});", "open.push({order: orders[0], cancelBy});"),
    ("N7", NOTICE, "equal deadlines are ordered by the HIGHER id first",
     "|| a.order.id - b.order.id);", "|| b.order.id - a.order.id);"),
    ("N8", NOTICE, "the past orders are left in arrival order",
     "    past.sort((a, b) => a.id - b.id);\n", ""),
    ("N9", NOTICE, "an open entry carries the SOONEST deadline, not its own",
     "open.push({order, cancelBy});", "open.push({order, cancelBy: soonestOpen ?? cancelBy});"),
]


def run(tree):
    env = dict(os.environ)
    env["HD_ROOT"] = tree
    p = subprocess.run([NODE, HARNESS], env=env, capture_output=True, text=True)
    out = (p.stdout + p.stderr).strip().splitlines()
    first_fail = next((l for l in out if l.startswith("FAIL")), "")
    return p.returncode, (out[-1] if out else ""), first_fail


def fresh_tree():
    tree = tempfile.mkdtemp(prefix="hd-notice-mut-")
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
