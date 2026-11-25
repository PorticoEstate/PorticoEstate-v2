#!/usr/bin/env python3
"""
The stored × viewer matrix of the owner arms, on a base tree and on this tree (task 49173; the property from #1492's
final, forum/pe-tasks-sonnet/49155): only pairs with an empty or a placeholder side, or a cross-spelling (loosely
equal, not the same string), may change, and every change must be MATCH -> NO. Nobody becomes an owner.

    python3 tests/serializer-owner-arm/matrix.py [BASE_REF]      (default 7cf8b902f; PHP_BIN overrides the binary)

The base tree is `git archive BASE_REF src` in a temp dir; the matrix itself is `run.php --matrix` (each pair's class is
computed from its values, so it is the same on both trees). Prints every changed pair, then the counts.
Exit: 0 the property holds and both matrices ran clean, 1 otherwise.
"""
import os
import subprocess
import sys
import tempfile
import shutil
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
PHP = os.environ.get("PHP_BIN", "php")


def matrix(tree):
    env = dict(os.environ, HD_ROOT=tree)
    p = subprocess.run([PHP, os.path.join(HERE, "run.php"), "--matrix"], cwd=ROOT, env=env, capture_output=True, text=True, timeout=600)
    rows = {}
    for line in p.stdout.splitlines():
        arm, stored, viewer, verdict, why = line.split("\t")
        rows[(arm, stored, viewer)] = (verdict, why)
    return p.returncode, rows


def main():
    ref = sys.argv[1] if len(sys.argv) > 1 else "7cf8b902f"
    tmp = tempfile.mkdtemp(prefix="serializer-owner-arm-matrix-")
    try:
        base = os.path.join(tmp, "base")
        os.makedirs(base)
        archive = subprocess.run(["git", "-C", ROOT, "archive", ref, "src"], capture_output=True, check=True).stdout
        subprocess.run(["tar", "-x", "-C", base], input=archive, check=True)

        rc_base, before = matrix(base)
        rc_tip, after = matrix(ROOT)
        if rc_base or rc_tip or not before or set(before) != set(after):
            print(f"MATRIX DID NOT RUN CLEAN: base rc={rc_base} ({len(before)} pairs), tip rc={rc_tip} ({len(after)} pairs)")
            return 1

        changed = [(k, before[k], after[k]) for k in sorted(before) if before[k][0] != after[k][0]]
        bad = []
        for (arm, stored, viewer), (was, why), (now, _) in changed:
            ok = was == "MATCH" and now == "NO" and why != "-"
            print(f"{'ok ' if ok else 'BAD'} {arm:9} stored {stored:16} viewer {viewer:34} {was:5} -> {now:5} [{why}]")
            if not ok:
                bad.append((arm, stored, viewer))

        directions = Counter(f"{b[0]}->{a[0]}" for _, b, a in changed)
        classes = Counter(b[1] for _, b, _ in changed)
        unchanged_classed = sum(1 for k in before if before[k][0] == after[k][0] and before[k][1] != "-")
        print(f"base {ref}: {len(before)} pairs; changed {len(changed)} ({dict(directions)}); by class {dict(classes)}")
        print(f"unchanged pairs that have a class (empty/placeholder/cross-spelling and still the same verdict): {unchanged_classed}")
        print(f"MATCH on base: {sum(1 for v in before.values() if v[0] == 'MATCH')}, on tip: {sum(1 for v in after.values() if v[0] == 'MATCH')}")
        if bad:
            print(f"PROPERTY BROKEN by {len(bad)} pair(s)")
            return 1
        print("property holds: every change is MATCH -> NO on a pair with an empty or placeholder side, or a cross-spelling")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
