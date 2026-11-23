#!/usr/bin/env python3
"""
Mutation check for tests/event-owner-patch/run.php (task 48753; pe-pm forum/pe-general/48812).

One deliberate defect at a time in a TEMP COPY of the files the harness reads (never in the tree). The harness must go
red on that copy, in the arm(s) named for the mutation, after a green baseline on the same copy. Every replacement
must match exactly once. Any other exit (a crash, a fatal error) is CRASH and fails the run.

EQUIVALENT mutants are listed apart and must SURVIVE: they were declared in advance (forum/pe-general/48806). Under
the strict === of the ssn arm, either empty guard alone is enough, because two identical values are either both empty
or both not. If one of them is ever caught, the declaration is wrong and the run fails.

Usage:  python3 -I tests/event-owner-patch/mutate.py      (PHP_BIN overrides the binary)
Exit:   0 every mutation caught by its arm(s) and every equivalent one survived, 1 otherwise.
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
    "src/helpers/OwnerIdentifier.php",
    "src/traits/SerializableTrait.php",
    "src/modules/bookingfrontend/models/Event.php",
    "src/modules/bookingfrontend/models/helper/BaseScheduleEntity.php",
    "src/modules/bookingfrontend/services/EventService.php",
]

S = "src/modules/bookingfrontend/services/EventService.php"
O = "src/helpers/OwnerIdentifier.php"

SSN = ("$isOwnerBySsn = !OwnerIdentifier::isEmpty($ssn)\n"
       "            && !OwnerIdentifier::isEmpty($ownerSsn)\n"
       "            && $ssn === $ownerSsn;")
LIST = "fn($orgNumber) => !OwnerIdentifier::isEmpty($orgNumber) && !OwnerIdentifier::isPlaceholderOrgNumber($orgNumber)"
STORED_EMPTY = "$isOwnerByOrganization = !OwnerIdentifier::isEmpty($ownerOrgNum)\n            && "
STORED_PLACEHOLDER = "&& !OwnerIdentifier::isPlaceholderOrgNumber($ownerOrgNum)\n            "
ORG_ARM = ("$isOwnerByOrganization = !OwnerIdentifier::isEmpty($ownerOrgNum)\n"
           "            && !OwnerIdentifier::isPlaceholderOrgNumber($ownerOrgNum)\n"
           "            && in_array($ownerOrgNum, $userOrgNumbers);")

# (id, file, description, [(old, new), ...], arms that must go red)
MUTATIONS = [
    ("Ma", S, "the ssn arm compared bare again (both guards dropped)",
     [(SSN, "$isOwnerBySsn = $ssn === $ownerSsn;")], ["F1", "F2"]),
    ("Mc", S, "empty elements of the delegate list kept",
     [(LIST, "fn($orgNumber) => !OwnerIdentifier::isPlaceholderOrgNumber($orgNumber)")], ["F4"]),
    ("Md", S, "the placeholder kept in the delegate list",
     [(LIST, "fn($orgNumber) => !OwnerIdentifier::isEmpty($orgNumber)")], ["F5"]),
    ("Me", S, "a stored placeholder number allowed", [(STORED_PLACEHOLDER, "")], ["F5"]),
    ("Mf", S, "an empty stored number allowed", [(STORED_EMPTY, "$isOwnerByOrganization = ")], ["F4"]),
    ("Mg", S, "the org arm compared bare again (every org guard dropped)",
     [(ORG_ARM, "$isOwnerByOrganization = in_array($ownerOrgNum, $userOrgs);")], ["F2", "F3", "F4", "F5"]),
    ("Mh", O, "0 and '0' taken for empty",
     [("return $value === null || $value === false || $value === []",
       "return $value === null || $value === false || $value === [] || $value === 0 || $value === '0'")], ["D1", "D3"]),
    ("Mi", O, "whitespace taken for a value (no trim)", [("trim($value) === ''", "$value === ''")], ["F2"]),
]

EQUIVALENT = [
    ("Eb1", S, "the viewer's ssn guard alone dropped",
     [(SSN, "$isOwnerBySsn = !OwnerIdentifier::isEmpty($ownerSsn)\n            && $ssn === $ownerSsn;")]),
    ("Eb2", S, "the stored ssn guard alone dropped",
     [(SSN, "$isOwnerBySsn = !OwnerIdentifier::isEmpty($ssn)\n            && $ssn === $ownerSsn;")]),
]


def run_harness(tree):
    env = dict(os.environ, HD_ROOT=tree)
    p = subprocess.run([PHP, os.path.join(HERE, "run.php")], cwd=ROOT, env=env, capture_output=True, text=True, timeout=300)
    lines = [ln for ln in p.stdout.splitlines() if ln.strip()]
    return p.returncode, (lines[-1] if lines else p.stderr.strip()[:200]), p.stdout


def apply(tree, rel, pairs):
    path = os.path.join(tree, rel)
    original = open(path, encoding="utf-8").read()
    text = original
    for old, new in pairs:
        n = text.count(old)
        if n != 1:
            return None, f"pattern matched {n} times (need exactly 1): {old[:60]!r}"
        text = text.replace(old, new, 1)
    open(path, "w", encoding="utf-8").write(text)
    return original, None


def main():
    tmp = tempfile.mkdtemp(prefix="event-owner-patch-mut-")
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

        bad = []
        for mid, rel, desc, pairs, arms in MUTATIONS:
            original, err = apply(tree, rel, pairs)
            if err:
                print(f"{mid}: MUTATION DID NOT APPLY - {err}: {desc}")
                return 1
            try:
                rc, tail, stdout = run_harness(tree)
            finally:
                open(os.path.join(tree, rel), "w", encoding="utf-8").write(original)
            red = set(re.findall(r"^FAIL  (\S+)$", stdout, re.M))
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if rc == 0:
                print(f"{mid:3} SURVIVED rc=0  {desc}")
                bad.append(mid)
            elif rc != 1 or not m:
                print(f"{mid:3} CRASH    rc={rc}  {tail[:120]}  {desc}")
                bad.append(mid)
            elif not set(arms) <= red:
                print(f"{mid:3} WRONG ARM red={sorted(red)} expected {arms}  {desc}")
                bad.append(mid)
            else:
                print(f"{mid:3} caught   {m.group(1):>2} of {m.group(2)} checks fail, red: {', '.join(sorted(red))}  {desc}")

        for mid, rel, desc, pairs in EQUIVALENT:
            original, err = apply(tree, rel, pairs)
            if err:
                print(f"{mid}: MUTATION DID NOT APPLY - {err}: {desc}")
                return 1
            try:
                rc, tail, _ = run_harness(tree)
            finally:
                open(os.path.join(tree, rel), "w", encoding="utf-8").write(original)
            if rc == 0:
                print(f"{mid:3} survived as declared (equivalent)  {desc}")
            else:
                print(f"{mid:3} CAUGHT, but declared equivalent rc={rc}  {tail[:120]}  {desc}")
                bad.append(mid)

        if bad:
            print(f"NOT AS DECLARED: {', '.join(bad)}")
            return 1
        print(f"all {len(MUTATIONS)} mutations caught by their arms, {len(EQUIVALENT)} equivalent ones survived, baseline green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
