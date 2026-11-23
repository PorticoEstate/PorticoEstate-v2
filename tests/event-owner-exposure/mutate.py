#!/usr/bin/env python3
"""
Mutation check for tests/event-owner-exposure/run.php (GH #1492, task 48050; operator ruling forum/pe-general/48190).

One deliberate defect at a time in a TEMP COPY of the files the harness reads (never in the tree). The harness must go
red on that copy, in the arm(s) named for the mutation, after a green baseline on the same copy. Every replacement
must match exactly once. Any other exit (a crash, a fatal error) is CRASH and fails the run.

Since the owner arm compares strictly (task 49173), Mb, Mc and Md are EQUIVALENT: each removes the empty check of ONE
side, and the other side's check covers it (a strict compare matches an empty value only with the same empty value), so
no input can tell them apart. They must survive; if one is caught, that claim is wrong and the run fails. Mi removes
the checks of both sides together and must be caught. The rows they were written for, F7 and F10 (0 against NULL),
are loose-compare defects that the strict compare refuses on its own.

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
H = "src/helpers/OwnerIdentifier.php"
E = "src/modules/bookingfrontend/models/Event.php"
STORED = ("            if ($this->isEmptyIdentifier($actualValue)) {\n"
          "                return false;\n"
          "            }\n")
PLACEHOLDER_STORED = ("            if (\\App\\helpers\\OwnerIdentifier::isPlaceholderOrgNumber($actualValue)) {\n"
                      "                return false;\n"
                      "            }\n")
PLACEHOLDER_VIEWER = ("            if (\\App\\helpers\\OwnerIdentifier::isPlaceholderOrgNumber($contextValue)) {\n"
                      "                return false;\n"
                      "            }\n")
COMPARE = ("        if (is_array($viewer)) {\n"
           "            return \\App\\helpers\\OwnerIdentifier::ownsByNumber($stored, $viewer);\n"
           "        }\n"
           "\n"
           "        return \\App\\helpers\\OwnerIdentifier::sameIdentifier($stored, $viewer);")
FIELD = "            $actualValue = $this->$field;\n"
NO_STORED_EMPTY = [(T, STORED, ""), (H, "self::isEmpty($stored) || ", ""),
                   (H, "if (!self::identifiesOrganization($stored) ||", "if (self::isPlaceholderOrgNumber($stored) ||")]
NO_VIEWER_EMPTY = [(H, "self::isEmpty($viewer) || ", "")]
NO_LIST_EMPTY = [(H, "static fn($number) => is_scalar($number) && self::identifiesOrganization($number)",
                  "static fn($number) => is_scalar($number) && !self::isPlaceholderOrgNumber($number)")]


def loose_for(field):
    """Every guard skipped for one field only: that owner arm is compared loosely again."""
    return [(T, FIELD, FIELD + f"            if ($field === '{field}') {{\n"
                               "                return is_array($contextValue) ? in_array($actualValue, $contextValue) : $actualValue == $contextValue;\n"
                               "            }\n")]


# (id, description, [(file, old, new), ...], arms that must go red, or "EQUIVALENT")
MUTATIONS = [
    ("Ma", "the loose compare restored (every guard removed)",
     [(T, STORED, ""), (T, PLACEHOLDER_STORED, ""), (T, PLACEHOLDER_VIEWER, ""),
      (T, COMPARE, "        return is_array($viewer) ? in_array($stored, $viewer) : $stored == $viewer;")],
     ["F1", "F2", "F3", "F4", "F5", "F6", "F7", "F8", "F9", "F10"]),
    ("Mb", "the empty-entity check removed (trait and helper)", NO_STORED_EMPTY, "EQUIVALENT"),
    ("Mc", "the empty-context check removed (sameIdentifier)", NO_VIEWER_EMPTY, "EQUIVALENT"),
    ("Md", "the empty elements of a context list kept (ownsByNumber)", NO_LIST_EMPTY, "EQUIVALENT"),
    ("Me", "the ssn arm left loose", loose_for("customer_ssn"), ["F3", "F4"]),
    ("Mf", "the organisation-number arm left loose", loose_for("customer_organization_number"), ["F1", "F2", "F5", "F6", "F9"]),
    # --- the two docblocks back as they were: «when={…}» closed without «)», so the trait never reads the rule
    ("Mg", "customer_identifier_type's docblock without its «)»",
     [(E, "\t * })\n\t */\n\tpublic $customer_identifier_type;", "\t *   }\n\t */\n\tpublic $customer_identifier_type;")], ["F11"]),
    ("Mh", "customer_organization_number's docblock without its «)»",
     [(E, "\t * })\n\t * @Short\n\t */\n\tpublic $customer_organization_number;", "\t *  }\n\t * @Short\n\t */\n\tpublic $customer_organization_number;")], ["F11"]),
    ("Mi", "the empty checks of both sides removed together (Mb + Mc + Md)", NO_STORED_EMPTY + NO_VIEWER_EMPTY + NO_LIST_EMPTY,
     ["F4", "F5"]),
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
        originals = {rel: open(os.path.join(tree, rel), encoding="utf-8").read() for rel in FILES}
        for mid, desc, pairs, arms in MUTATIONS:
            try:
                for rel, old, new in pairs:
                    path = os.path.join(tree, rel)
                    text = open(path, encoding="utf-8").read()
                    n = text.count(old)
                    if n != 1:
                        print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times in {rel} (need exactly 1): {desc}")
                        return 1
                    open(path, "w", encoding="utf-8").write(text.replace(old, new, 1))
                rc, tail, stdout = run_harness(tree)
            finally:
                for rel, text in originals.items():
                    open(os.path.join(tree, rel), "w", encoding="utf-8").write(text)
            red = set(re.findall(r"^FAIL  (\S+)$", stdout, re.M))
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if arms == "EQUIVALENT":
                if rc == 0:
                    print(f"{mid:3} survived (equivalent, as claimed)  {desc}")
                else:
                    print(f"{mid:3} CAUGHT   but claimed equivalent: rc={rc} red={sorted(red)}  {desc}")
                    wrong_arm.append(mid)
            elif rc == 0:
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
        caught = sum(1 for x in MUTATIONS if x[3] != "EQUIVALENT")
        print(f"all {caught} mutations caught by their arms, {len(MUTATIONS) - caught} equivalent ones survived, 0 crashes, baseline green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
