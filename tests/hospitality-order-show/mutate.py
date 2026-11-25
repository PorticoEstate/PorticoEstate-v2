#!/usr/bin/env python3
"""
Mutation check for run.mts (the admin order page's Save, task 49777, §49718, pe-backlog/13923).

A harness that never finishes (HANG) is red too: the run is killed by a timeout and counted.

One deliberate defect at a time is applied to a TEMP COPY of the files the harness reads (never to the tree) and the harness
is pointed at it with HM_ROOT; it must go red for each, on the rows the defect is about. The baseline on the same copy must
be green first, and every replacement must match exactly once. A final arm runs the harness on the tree as it was on base
(7891c67b6) and prints which rows are FIX rows (red there) and which are controls (green there).

M1-M5 are the mutants 49763 declared (fix + one change); M6, M7 are the D2 mutants (the warning dropped → a silent
nothing-sent; the warning shown but the save not stopped); M8-M14 are the ones the D2 rule and the controls call for.
M15-M19 are task 49934's: the time placeholder is a clear only where the list opened on the stored slot (M15 is the old
unconditional mark; M19 is the opposite error, the clear lost where the stored time is visible).

Usage:  python3 tests/hospitality-order-show/mutate.py [--no-base]
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
NODE = os.environ.get("NODE_BIN", "node")
HARNESS = os.path.join(HERE, "run.mts")
BASE = os.environ.get("HM_BASE_SHA", "7891c67b6")
# the base of the follow-up 49934: the tree #49718 merged, whose rows L6c, L6d and E1 are red
FOLLOWUP_BASE = os.environ.get("HM_FOLLOWUP_BASE_SHA", "5cafce39d")

BOOKING = "src/modules/booking"
JS = f"{BOOKING}/html/base/hospitality/order_show/order_show.js"
TWIG = f"{BOOKING}/html/base/hospitality/order_show/order_show.twig"
FILES = [JS, TWIG, f"{BOOKING}/setup/phpgw_no.lang", f"{BOOKING}/setup/phpgw_nn.lang", f"{BOOKING}/setup/phpgw_en.lang"]

# every row group the harness has, so the base arm can name the controls (green on base)
ALL_ROWS = ["S1", "S3", "P1", "C1", "L1", "L2", "L3", "L4", "L5", "L6", "L6c", "L6d", "L6e", "L7", "L7b", "L8", "D2", "D2b", "D2c", "D2p", "E1"]

GATE = "if (!el.dataset.timeTouched) return;"
DATE_KEEP_DATE_MARK = "if (this.value === '') delete wrapper.dataset.dateTouched;"
DATE_LISTENER_CLEAR = "delete wrapper.dataset.timeTouched;\n\t\t\t\t" + DATE_KEEP_DATE_MARK
WARN = "showToast(lang('selectTime'), 'warning');"
TIME_LISTENER = ("timeEl.addEventListener('change', function ()\n\t\t\t{\n\t\t\t\tif (this.value === '' && !openedOnStored) delete wrapper.dataset.timeTouched;\n"
                 "\t\t\t\telse wrapper.dataset.timeTouched = '1';\n\t\t\t});")
PLACEHOLDER_RULE = "if (this.value === '' && !openedOnStored) delete wrapper.dataset.timeTouched;\n\t\t\t\telse wrapper.dataset.timeTouched = '1';"
REDERIVE = "openedOnStored = populateEditTimeSlots(timeEl, this.value, currentValue);"

# (id, what, [(file, old, new), ...], the row groups that MUST go red)
MUTANTS = [
    ("M1", "the gate is removed (the datetime branch sends whatever the select reads, as on base)",
     [(JS, GATE, "")], {"L2", "L3", "L4", "L7b", "L8"}),
    ("M2", "the gate never opens (a time the officer chose is never sent)",
     [(JS, GATE, "return;")], {"L5", "L6"}),
    ("M3", "a date change keeps the time mark (the rebuilt, empty list still counts as chosen)",
     [(JS, DATE_LISTENER_CLEAR, DATE_KEEP_DATE_MARK)], {"L7"}),
    ("M4", "application_id is still assigned before the gate (a no-op write on every save of a shape with one date)",
     [(JS, GATE, "if (!el.dataset.timeTouched) { var de0 = el.querySelector('[data-edit-date]'); var si0 = de0.value; "
                 "if (si0 !== '' && appDatesData[si0]) { payload.application_id = appDatesData[si0].application_id; } return; }")],
     {"L2", "L8"}),
    ("M5", "only the DATE select counts (the date change sets the mark; the time select's own change does not)",
     [(JS, TIME_LISTENER, ""), (JS, DATE_LISTENER_CLEAR, "wrapper.dataset.timeTouched = '1';\n\t\t\t\t" + DATE_KEEP_DATE_MARK)], {"L5", "D2"}),
    ("M6", "D2: the warning is dropped (the save is still stopped: a silent nothing-sent)",
     [(JS, WARN, "")], {"D2", "D2b"}),
    ("M7", "D2: the warning is shown but the save is not stopped (a pending line is saved under it)",
     [(JS, WARN + "\n\t\t\treturn;", WARN)], {"D2b"}),
    ("M8", "D2: the twig loses the text's attribute (the warning would say «selectTime»)",
     [(TWIG, "\t data-lang-select-time=\"{{ lang('booking.select_time') }}\"\n", "")], {"D2", "S1"}),
    ("M9", "D2: a date put back to the placeholder still counts as picked (a warning where no date is picked)",
     [(JS, "if (this.value === '') delete wrapper.dataset.dateTouched;\n\t\t\t\telse wrapper.dataset.dateTouched = '1';",
           "wrapper.dataset.dateTouched = '1';")], {"L7b"}),
    ("M10", "D2: a disabled single date counts as picked (a warning on every save of a one-date order)",
     [(JS, "if (el.dataset.dateTouched && !el.dataset.timeTouched) lacking = true;",
           "if (el.querySelector('[data-edit-date]').value !== '' && !el.dataset.timeTouched) lacking = true;")], {"L2", "L8"}),
    ("M11", "D2: the slot preselected for the stored time counts as the officer's choice",
     [(JS, "populateEditTimeSlots(timeEl, this.value, currentValue);",
           "populateEditTimeSlots(timeEl, this.value, currentValue);\n\t\t\t\tif (timeEl.value !== '') wrapper.dataset.timeTouched = '1';")], {"D2p"}),
    ("M12", "the chosen date's application_id is no longer sent with the time",
     [(JS, "payload.application_id = appDatesData[selectedDateIdx].application_id;", "")], {"L5", "L6"}),
    ("M13", "a deliberate clear is read as «keep» (the placeholder chosen sends nothing)",
     [(JS, "newValue = timeEl.value || null;", "newValue = timeEl.value || orderData[fieldName];")], {"L6"}),
    ("M14", "D2: the warning is shown as an info toast",
     [(JS, WARN, "showToast(lang('selectTime'), 'info');")], {"D2"}),
    ("M15", "the old placeholder mark: any change of the time list marks it chosen (the placeholder is a clear wherever it is chosen)",
     [(JS, PLACEHOLDER_RULE, "wrapper.dataset.timeTouched = '1';")], {"L6c", "L6d", "E1"}),
    ("M16", "the «opened on the stored slot» flag sticks once true (a date change cannot take it back)",
     [(JS, REDERIVE, "openedOnStored = populateEditTimeSlots(timeEl, this.value, currentValue) || openedOnStored;")], {"L6d"}),
    ("M17", "the flag is never re-derived on a date change (only the one-date populate sets it)",
     [(JS, REDERIVE, "populateEditTimeSlots(timeEl, this.value, currentValue);")], {"L6", "L6e", "E1"}),
    ("M18", "any list with slots counts as opened on the stored slot (the list is not asked for the stored time)",
     [(JS, "return openedOnStored;", "return true;")], {"L6c", "L6d", "E1"}),
    ("M19", "the clear is lost where the stored time IS visible (the placeholder is always «nothing chosen»)",
     [(JS, PLACEHOLDER_RULE, "delete wrapper.dataset.timeTouched;")], {"L6", "L6e", "E1"}),
]


def run(tree):
    env = dict(os.environ)
    env["HM_ROOT"] = tree
    try:
        p = subprocess.run([NODE, HARNESS], env=env, capture_output=True, text=True, timeout=int(os.environ.get("HM_TIMEOUT", "90")))
    except subprocess.TimeoutExpired:
        return 124, "killed by the timeout: the page never settled", ["FAIL HANG the harness did not finish"], {"HANG"}
    out = (p.stdout + p.stderr).strip().splitlines()
    fails = [l for l in out if l.startswith("FAIL")]
    groups = set()
    for l in fails:
        m = re.match(r"FAIL ([A-Za-z]+\d+[a-z]?)", l)
        if m:
            groups.add(m.group(1))
    return p.returncode, (out[-1] if out else ""), fails, groups


def fresh_tree():
    tree = tempfile.mkdtemp(prefix="hospitality-order-show-mut-")
    for rel in FILES:
        dst = os.path.join(tree, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copy(os.path.join(ROOT, rel), dst)
    return tree


def main():
    bad = 0
    tree = fresh_tree()
    rc, last, fails, groups = run(tree)
    print(f"BASELINE (current files, unmutated): rc={rc}  {last}")
    if rc != 0:
        print("  the baseline is not green, so no mutant result below means anything:")
        for l in fails[:8]:
            print("   ", l[:160])
        shutil.rmtree(tree)
        sys.exit(1)
    shutil.rmtree(tree)

    for mid, what, edits, want in MUTANTS:
        tree = fresh_tree()
        ok = True
        for rel, old, new in edits:
            path = os.path.join(tree, rel)
            text = open(path, encoding="utf-8").read()
            if text.count(old) != 1:
                print(f"{mid}  REPLACEMENT DID NOT MATCH ONCE ({text.count(old)}x)  {what}")
                ok = False
                break
            open(path, "w", encoding="utf-8").write(text.replace(old, new))
        if not ok:
            bad += 1
            shutil.rmtree(tree)
            continue
        rc, last, fails, groups = run(tree)
        missed = want - groups
        verdict = "RED as expected" if rc != 0 and not missed else ("NOT RED" if rc == 0 else f"red, but NOT on {sorted(missed)}")
        if rc == 0 or missed:
            bad += 1
        print(f"{mid}  rc={rc}  {verdict}  red rows: {sorted(groups)}  — {what}")
        shutil.rmtree(tree)

    if "--no-base" not in sys.argv:
        for base_sha in (BASE, FOLLOWUP_BASE):
            tree = tempfile.mkdtemp(prefix="hospitality-order-show-base-")
            ok = True
            for rel in FILES:
                r = subprocess.run(["git", "-C", ROOT, "show", f"{base_sha}:{rel}"], capture_output=True)
                if r.returncode != 0:
                    print(f"BASE {base_sha}: cannot read {rel}")
                    ok = False
                    break
                dst = os.path.join(tree, rel)
                os.makedirs(os.path.dirname(dst), exist_ok=True)
                open(dst, "wb").write(r.stdout)
            if ok:
                rc, last, fails, groups = run(tree)
                print(f"BASE {base_sha}: rc={rc}  {last}")
                print(f"  FIX rows (red on base):      {sorted(groups)}")
                print(f"  controls (green on base):    {sorted(set(ALL_ROWS) - groups)}")
                if rc == 0:
                    bad += 1
                    print("  the base is GREEN: the harness does not see the defect")
            shutil.rmtree(tree)

    print("ALL MUTANTS RED" if bad == 0 else f"{bad} PROBLEM(S)")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
