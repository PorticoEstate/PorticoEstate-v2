#!/usr/bin/env python3
"""
Mutation check for tests/event-private-billing/run.php (GH #1492, task 48050).

Same idea as tests/event-history-xss/mutate.py: one deliberate defect at a time, applied to a TEMP COPY of the files
the harness reads (never to the tree). The harness must go red on the SAME copy for each, and in the arm that guards
that line, not just anywhere. The baseline on the copy must be green first, and every replacement must match exactly
once. A mutation counts as caught only when the harness exits 1 with the expected arm(s) marked FAIL. Any other exit
(a crash, a fatal error) is reported as CRASH and fails the run, because a harness that dies has not looked at anything.

Usage:  python3 tests/event-private-billing/mutate.py      (PHP_BIN overrides the binary)
Exit:   0 every mutation caught, 1 otherwise.
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
PHP = os.environ.get("PHP_BIN", "php")

# Everything the harness reads from HD_ROOT.
FILES = [
    "src/helpers/Sanitizer.php",
    "src/modules/booking/inc/class.uievent.inc.php",
    "src/modules/booking/inc/class.customer_identifier.inc.php",
    "src/modules/booking/templates/base/customer_identifier.xsl",
    "src/modules/booking/templates/base/event_edit.xsl",
    "src/modules/phpgwapi/inc/class.xmlhelper.inc.php",
    "src/modules/bookingfrontend/models/Event.php",
    "src/modules/bookingfrontend/models/helper/BaseScheduleEntity.php",
    "src/traits/SerializableTrait.php",
]

U = "src/modules/booking/inc/class.uievent.inc.php"
GUARD = "if (!empty($organization['organization_number']))"

# (id, file, description, old, new, arms that must go red)
MUTATIONS = [
    # --- M1: the save keys on the type extract_form_data() resolved ----------------------------------------
    ("Mm1", U, "the ssn branch reads the POSTED type again",
     "elseif ($event['customer_identifier_type'] == 'ssn')",
     "elseif ($_POST['customer_identifier_type'] == 'ssn')", ["M1b"]),
    ("Mm2", U, "the organisation-number branch reads the POSTED type again",
     "elseif ($event['customer_identifier_type'] == 'organization_number')",
     "elseif ($_POST['customer_identifier_type'] == 'organization_number')", ["M1a", "M1c"]),
    # --- M2: option B, the suggestion only when the organisation has a number -------------------------
    ("Mm3", U, "the suggestion is made with no number (the guard always passes)",
     GUARD, "if (true)", ["M2a", "M2e"]),
    ("Mm4", U, "the suggestion is never made (the guard never passes)",
     GUARD, "if (false)", ["M2d"]),
    ("Mm5", U, "the guard reads the invoice column, not the register's number",
     GUARD, "if (!empty($organization['customer_organization_number']))", ["M2d"]),
    ("Mm6", U, "the render application reads the type it may not have (no !empty)",
     "\t\tif (!empty($customer['customer_identifier_type']))\n",
     "\t\tif ($customer['customer_identifier_type'])\n", ["M2a", "M2b", "M2e"]),
]


def run_harness(tree):
    env = dict(os.environ, HD_ROOT=tree)
    p = subprocess.run([PHP, os.path.join(HERE, "run.php")], cwd=ROOT, env=env, capture_output=True, text=True, timeout=300)
    lines = [ln for ln in p.stdout.splitlines() if ln.strip()]
    return p.returncode, (lines[-1] if lines else p.stderr.strip()[:200]), p.stdout


def main():
    tmp = tempfile.mkdtemp(prefix="event-private-billing-mut-")
    try:
        tree = os.path.join(tmp, "tree")
        for rel in FILES:
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copyfile(os.path.join(ROOT, rel), dst)

        rc, tail, _ = run_harness(tree)
        print(f"baseline rc={rc}  {tail}")
        if rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1

        survived, crashed, wrong_arm = [], [], []
        for mid, rel, desc, old, new, arms in MUTATIONS:
            path = os.path.join(tree, rel)
            original = open(path, encoding="utf-8").read()
            n = original.count(old)
            if n != 1:
                print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                return 1
            open(path, "w", encoding="utf-8").write(original.replace(old, new, 1))
            try:
                rc, tail, stdout = run_harness(tree)
            finally:
                open(path, "w", encoding="utf-8").write(original)
            red = set(re.findall(r"^FAIL  (\S+)$", stdout, re.M))
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if rc == 0:
                print(f"{mid:4} SURVIVED rc=0  {desc}")
                survived.append(mid)
            elif rc != 1 or not m:
                print(f"{mid:4} CRASH    rc={rc}  {tail[:120]}  {desc}")
                crashed.append(mid)
            elif not set(arms) <= red:
                print(f"{mid:4} WRONG ARM red={sorted(red)} expected {arms}  {desc}")
                wrong_arm.append(mid)
            else:
                print(f"{mid:4} caught   {m.group(1):>2} of {m.group(2)} checks fail, red: {', '.join(sorted(red))}  {desc}")

        if survived or crashed or wrong_arm:
            for label, ids in (("SURVIVING", survived), ("CRASHED", crashed), ("CAUGHT BY THE WRONG ARM", wrong_arm)):
                if ids:
                    print(f"{label}: {', '.join(ids)}")
            return 1
        print(f"all {len(MUTATIONS)} mutations caught by their arms, 0 crashes, baseline green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
