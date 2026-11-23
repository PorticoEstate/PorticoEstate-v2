#!/usr/bin/env python3
"""
Mutation check for tests/event-owner-exposure/run.php (GH #1492, task 48050; operator ruling forum/pe-general/48190).

One deliberate defect at a time in a TEMP COPY of the files the harness reads (never in the tree). The harness must go
red on that copy, in the arm(s) named for the mutation, after a green baseline on the same copy. Every replacement
must match exactly once. Any other exit (a crash, a fatal error) is CRASH and fails the run.

Usage:  python3 tests/event-owner-exposure/mutate.py      (PHP_BIN overrides the binary)
Exit:   0 every mutation caught by its arm(s), 1 otherwise.
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

# Everything the harness reads from HD_ROOT (the client files are read from this repo when HD_ROOT has none).
FILES = [
    "src/traits/SerializableTrait.php",
    "src/helpers/OwnerIdentifier.php",
    "src/modules/bookingfrontend/models/Event.php",
    "src/modules/bookingfrontend/models/Booking.php",
    "src/modules/bookingfrontend/models/helper/BaseScheduleEntity.php",
    "src/modules/bookingfrontend/models/Organization.php",
    "src/modules/booking/models/Application.php",
    "src/modules/bookingfrontend/services/ScheduleEntityService.php",
    "src/modules/bookingfrontend/controllers/BookingUserController.php",
    "src/modules/booking/inc/class.bobooking.inc.php",
    "src/modules/bookingfrontend/inc/class.uievent.inc.php",
]

T = "src/traits/SerializableTrait.php"
E = "src/modules/bookingfrontend/models/Event.php"
STORED = ("            if ($this->isEmptyIdentifier($actualValue)) {\n"
          "                return false;\n"
          "            }\n")
FILTER = "in_array($actualValue, array_filter($contextValue, fn($v) => !$this->isEmptyIdentifier($v)))"
CONTEXT = ("            if ($this->isEmptyIdentifier($contextValue)) {\n"
           "                return false;\n"
           "            }\n")


def loose_for(field):
    """The guards skipped for one field only: that owner arm is compared loosely again."""
    skip = f"$field !== '{field}' && "
    return [
        (STORED, STORED.replace("if ($this->isEmptyIdentifier($actualValue))", f"if ({skip}$this->isEmptyIdentifier($actualValue))")),
        (FILTER, f"in_array($actualValue, $field === '{field}' ? $contextValue : array_filter($contextValue, fn($v) => !$this->isEmptyIdentifier($v)))"),
        (CONTEXT, CONTEXT.replace("if ($this->isEmptyIdentifier($contextValue))", f"if ({skip}$this->isEmptyIdentifier($contextValue))")),
    ]


# (id, file, description, [(old, new), ...], arms that must go red)
MUTATIONS = [
    ("Ma", T, "the loose compare restored (every guard removed)",
     [(STORED, ""), (FILTER, "in_array($actualValue, $contextValue)"), (CONTEXT, "")],
     ["F1", "F2", "F3", "F4", "F5", "F6", "F7", "F8", "F9", "F10"]),
    ("Mb", T, "the empty-entity check removed", [(STORED, "")], ["F10"]),
    ("Mc", T, "the empty-context check removed", [(CONTEXT, "")], ["F7"]),
    ("Md", T, "the empty elements of a context list kept", [(FILTER, "in_array($actualValue, $contextValue)")], ["F7"]),
    ("Me", T, "the ssn arm left loose", loose_for("customer_ssn"), ["F3", "F4"]),
    ("Mf", T, "the organisation-number arm left loose", loose_for("customer_organization_number"), ["F1", "F2", "F5", "F6", "F9"]),
    # --- the two docblocks back as they were: «when={…}» closed without «)», so the trait never reads the rule
    ("Mg", E, "customer_identifier_type's docblock without its «)»",
     [("\t * })\n\t */\n\tpublic $customer_identifier_type;", "\t *   }\n\t */\n\tpublic $customer_identifier_type;")], ["F11"]),
    ("Mh", E, "customer_organization_number's docblock without its «)»",
     [("\t * })\n\t * @Short\n\t */\n\tpublic $customer_organization_number;", "\t *  }\n\t * @Short\n\t */\n\tpublic $customer_organization_number;")], ["F11"]),
]


def run_harness(tree):
    env = dict(os.environ, HD_ROOT=tree)
    p = subprocess.run([PHP, os.path.join(HERE, "run.php")], cwd=ROOT, env=env, capture_output=True, text=True, timeout=300)
    lines = [ln for ln in p.stdout.splitlines() if ln.strip()]
    return p.returncode, (lines[-1] if lines else p.stderr.strip()[:200]), p.stdout


def main():
    tmp = tempfile.mkdtemp(prefix="event-owner-exposure-mut-")
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
        for mid, rel, desc, pairs, arms in MUTATIONS:
            path = os.path.join(tree, rel)
            original = open(path, encoding="utf-8").read()
            text = original
            for old, new in pairs:
                n = text.count(old)
                if n != 1:
                    print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                    return 1
                text = text.replace(old, new, 1)
            open(path, "w", encoding="utf-8").write(text)
            try:
                rc, tail, stdout = run_harness(tree)
            finally:
                open(path, "w", encoding="utf-8").write(original)
            red = set(re.findall(r"^FAIL  (\S+)$", stdout, re.M))
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if rc == 0:
                print(f"{mid:3} SURVIVED rc=0  {desc}")
                survived.append(mid)
            elif rc != 1 or not m:
                print(f"{mid:3} CRASH    rc={rc}  {tail[:120]}  {desc}")
                crashed.append(mid)
            elif not set(arms) <= red:
                print(f"{mid:3} WRONG ARM red={sorted(red)} expected {arms}  {desc}")
                wrong_arm.append(mid)
            else:
                print(f"{mid:3} caught   {m.group(1):>2} of {m.group(2)} checks fail, red: {', '.join(sorted(red))}  {desc}")

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
