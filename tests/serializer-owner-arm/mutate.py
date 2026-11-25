#!/usr/bin/env python3
"""
Mutation check for tests/serializer-owner-arm/run.php (task 49173).

One deliberate defect at a time in a TEMP COPY of the files the harness reads (never in the tree). After a green
baseline on the same copy, a CAUGHT mutation must turn the harness red in the rows named for it. An EQUIVALENT
mutation must leave it green: it removes a guard that another guard covers (the placeholder is refused on both sides,
and with a strict compare the stored side equals a list element only when both are the placeholder), so no input can
tell it apart; if one is caught, that claim is wrong and the run fails. Every replacement must match exactly once. Any
other exit (a crash, a fatal error) is CRASH and fails the run.

Usage:  python3 tests/serializer-owner-arm/mutate.py      (PHP_BIN overrides the binary)
Exit:   0 every CAUGHT mutation caught by its rows and every EQUIVALENT one survived, 1 otherwise.
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

FILES = [
    "src/traits/SerializableTrait.php",
    "src/helpers/OwnerIdentifier.php",
    "src/modules/bookingfrontend/models/Event.php",
    "src/modules/bookingfrontend/models/Booking.php",
    "src/modules/bookingfrontend/models/helper/BaseScheduleEntity.php",
    "src/modules/booking/models/Application.php",
]

T = "src/traits/SerializableTrait.php"
H = "src/helpers/OwnerIdentifier.php"

# --- the text each mutation changes (each must occur exactly once in its file) --------------------------------------
H_IN_LIST = "return in_array((string)$stored, $numbers, true);"
H_SAME = "return (string)$stored === (string)$viewer;"
H_MAP = "$numbers = array_map('strval', array_filter("
H_OWNS_STORED = "if (!self::identifiesOrganization($stored) || !is_scalar($stored)) {"
H_OWNS_LIST = "static fn($number) => is_scalar($number) && self::identifiesOrganization($number)"
T_STORED_PH = ("            if (\\App\\helpers\\OwnerIdentifier::isPlaceholderOrgNumber($actualValue)) {\n"
               "                return false;\n"
               "            }\n")
T_VIEWER_PH = ("            if (\\App\\helpers\\OwnerIdentifier::isPlaceholderOrgNumber($contextValue)) {\n"
               "                return false;\n"
               "            }\n")
T_AS_STORED = ("            if ($this->isViewersIdentifier($actualValue, $contextValue)) {\n"
               "                return true;\n"
               "            }\n")
T_PAD_RETURN = "return $restored !== null && $this->isViewersIdentifier($restored, $contextValue);"
T_PAD_ARM = "$restored = $contextKey === 'user_ssn' ? $this->restoreDroppedSsnZero($actualValue) : null;"
T_PAD_LEN = "strlen($digits) !== 10"
T_PAD_ZERO = " || ltrim($digits, '0') === ''"


def strip_instead_of_pad(guard):
    """The ssn arm compares digit strings with their leading zeros stripped on both sides, instead of the pad."""
    cond = "ltrim((string)$actualValue, '0') === ltrim((string)$contextValue, '0')"
    if guard:
        cond = "ltrim((string)$actualValue, '0') !== '' && ltrim((string)$contextValue, '0') !== '' && " + cond
    return [(T, T_PAD_RETURN,
             "return $contextKey === 'user_ssn' && ctype_digit((string)$actualValue) && ctype_digit((string)$contextValue)\n"
             f"                && {cond};")]


NO_PH_STORED = [(T, T_STORED_PH, ""), (H, H_OWNS_STORED, "if (self::isEmpty($stored) || !is_scalar($stored)) {")]
NO_PH_VIEWER = [(T, T_VIEWER_PH, ""), (H, H_OWNS_LIST, "static fn($number) => is_scalar($number) && !self::isEmpty($number)")]

# (id, description, [(file, old, new), ...], rows that must go red, or "EQUIVALENT")
MUTATIONS = [
    ("K1", "the strict flag dropped from the list compare",
     [(H, H_IN_LIST, "return in_array((string)$stored, $numbers);")], ["O10", "O11"]),
    ("K2", "the scalar compare loose (== for ===)",
     [(H, H_SAME, "return (string)$stored == (string)$viewer;")], ["S6", "S7", "S8", "S9", "S10", "S11", "S12", "A2"]),
    ("K3", "strict without taking an int as its digits (X1 broken)",
     [(H, H_MAP, "$numbers = (array_filter("), (H, H_IN_LIST, "return in_array($stored, $numbers, true);"),
      (H, H_SAME, "return $stored === $viewer;")], ["O2", "G1"]),
    ("K4", "the placeholder refused on neither side (trait and helper)", NO_PH_STORED + NO_PH_VIEWER,
     ["O4", "O8", "OS2"]),
    ("K5", "the pad dropped", [(T, T_PAD_RETURN, "return false;")], ["S4", "A1"]),
    ("K6", "the pad applied to 9 digits instead of 10", [(T, T_PAD_LEN, "strlen($digits) !== 9")], ["S4", "A1"]),
    ("K7", "strip leading zeros on both sides instead of the pad (no guard)", strip_instead_of_pad(False),
     ["S6", "S7", "S9", "S10", "S11", "A2"]),
    ("K8", "strip leading zeros on both sides instead of the pad, an all-zero side refused",
     strip_instead_of_pad(True), ["S9", "S10", "S11"]),
    ("K9", "the strip applied to the organisation arm too",
     [(H, H_IN_LIST, "return in_array(ltrim((string)$stored, '0'), array_map(fn($n) => ltrim($n, '0'), $numbers), true);")],
     ["O10"]),
    ("K10", "the padded value instead of the value as stored, not after it",
     [(T, T_AS_STORED, ""), (T, T_PAD_RETURN, "return $this->isViewersIdentifier($restored ?? $actualValue, $contextValue);")],
     ["S13"]),
    ("K11", "the all-zero guard removed from the pad", [(T, T_PAD_ZERO, "")], ["S14"]),
    ("K12", "an all-zero value refused by every compare, not only by the pad",
     [(H, H_SAME, "return (string)$stored === (string)$viewer && ltrim((string)$stored, '0') !== '';"),
      (H, H_IN_LIST, "return in_array((string)$stored, $numbers, true) && ltrim((string)$stored, '0') !== '';")], ["Z1"]),
    ("K13", "the 10-digit pad applied on every arm, not only the ssn arm (the user_ssn condition dropped)",
     [(T, T_PAD_ARM, "$restored = $this->restoreDroppedSsnZero($actualValue);")], ["X-PAD"]),
    # --- one side's placeholder refusal on its own: the other side's covers it, so these must SURVIVE
    ("E1", "the placeholder refused on the stored side only (the viewer's side no longer)", NO_PH_VIEWER, "EQUIVALENT"),
    ("E2", "the placeholder refused on the viewer's side only (the stored side no longer)", NO_PH_STORED, "EQUIVALENT"),
]


def run_harness(tree):
    env = dict(os.environ, HD_ROOT=tree)
    p = subprocess.run([PHP, os.path.join(HERE, "run.php")], cwd=ROOT, env=env, capture_output=True, text=True, timeout=300)
    lines = [ln for ln in p.stdout.splitlines() if ln.strip()]
    return p.returncode, (lines[-1] if lines else p.stderr.strip()[:200]), p.stdout


def apply(tree, pairs):
    for rel, old, new in pairs:
        path = os.path.join(tree, rel)
        text = open(path, encoding="utf-8").read()
        n = text.count(old)
        if n != 1:
            raise ValueError(f"pattern matched {n} times in {rel} (need exactly 1): {old[:80]!r}")
        open(path, "w", encoding="utf-8").write(text.replace(old, new, 1))


def main():
    tmp = tempfile.mkdtemp(prefix="serializer-owner-arm-mut-")
    try:
        tree = os.path.join(tmp, "tree")
        for rel in FILES:
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copyfile(os.path.join(ROOT, rel), dst)
        originals = {rel: open(os.path.join(tree, rel), encoding="utf-8").read() for rel in FILES}

        rc, tail, _ = run_harness(tree)
        print(f"baseline rc={rc}  {tail}")
        if rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1

        bad = []
        for mid, desc, pairs, rows in MUTATIONS:
            try:
                apply(tree, pairs)
                rc, tail, stdout = run_harness(tree)
            except ValueError as e:
                print(f"{mid}: MUTATION DID NOT APPLY - {e}: {desc}")
                return 1
            finally:
                for r, text in originals.items():
                    open(os.path.join(tree, r), "w", encoding="utf-8").write(text)
            red = set(re.findall(r"^FAIL  (\S+)$", stdout, re.M))
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if rows == "EQUIVALENT":
                if rc == 0:
                    print(f"{mid:3} survived (equivalent, as claimed)  {desc}")
                else:
                    print(f"{mid:3} CAUGHT   but claimed equivalent: rc={rc} red={sorted(red)}  {desc}")
                    bad.append(mid)
            elif rc == 0:
                print(f"{mid:3} SURVIVED rc=0  {desc}")
                bad.append(mid)
            elif rc != 1 or not m:
                print(f"{mid:3} CRASH    rc={rc}  {tail[:120]}  {desc}")
                bad.append(mid)
            elif not set(rows) <= red:
                print(f"{mid:3} WRONG ROWS red={sorted(red)} expected {rows}  {desc}")
                bad.append(mid)
            else:
                print(f"{mid:3} caught   {m.group(1):>2} of {m.group(2)} checks fail, red: {', '.join(sorted(red))}  {desc}")

        if bad:
            print(f"NOT AS CLAIMED: {', '.join(bad)}")
            return 1
        caught = sum(1 for x in MUTATIONS if x[3] != "EQUIVALENT")
        print(f"all {caught} mutations caught by their rows, {len(MUTATIONS) - caught} equivalent ones survived, baseline green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
