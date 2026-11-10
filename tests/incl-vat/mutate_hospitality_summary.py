#!/usr/bin/env python3
"""
Mutation check for run_hospitality_summary.mts (catering price summary, task 39792).

One deliberate defect at a time is applied to a TEMP COPY of utils/hospitality-price-summary.ts (never to
the tree) and the harness reads that copy through SUMMARY_TS; it must go red for each. The baseline on
the same copy must be green first, and every replacement must match exactly once.

Usage:  python3 tests/incl-vat/mutate_hospitality_summary.py
"""
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
NODE = os.environ.get("NODE_BIN", "node")
HARNESS = os.path.join(HERE, "run_hospitality_summary.mts")
TARGET = os.path.join(ROOT, "src/modules/bookingfrontend/client/src/utils/hospitality-price-summary.ts")

# (id, what, old, new)
MUTANTS = [
    ("S1", "VAT is not rounded per line (the exact fraction is summed)",
     "const lineVat = inclOre(lineEx, percent) - lineEx;",
     "const lineVat = lineEx * percent / 100;"),
    ("S11", "VAT is always 0",
     "const lineVat = inclOre(lineEx, percent) - lineEx;",
     "const lineVat = 0;"),
    ("S2", "an unknown VAT % is read as 0 %",
     "if (exOre === null || lines.some(line => line.percent === null)) {",
     "if (exOre === null) {"),
    ("S3", "the rate rows are sorted lowest first",
     "sort((a, b) => b.percent - a.percent)",
     "sort((a, b) => a.percent - b.percent)"),
    ("S4", "the total incl. VAT forgets the VAT",
     "inclOre: exOre + vatOre,",
     "inclOre: exOre,"),
    ("S5", "the ex sum is taken from the incl. line",
     "rate.exOre += lineEx;",
     "rate.exOre += lineEx + lineVat;"),
    ("S6", "the VAT of a rate is not accumulated (last line wins)",
     "rate.vatOre += lineVat;",
     "rate.vatOre = lineVat;"),
    ("S7", "an unknown price makes the ex sum 0 instead of unknown",
     "exOre = exOre === null || line.exOre === null ? null : exOre + line.exOre;",
     "exOre = exOre === null ? null : exOre + (line.exOre ?? 0);"),
    ("S8", "an empty percent string reads as 0 %",
     "if (value === null || value === undefined || value === '') return null;",
     "if (value === null || value === undefined) return null;"),
    ("S9", "a 0 % rate gets no row",
     "        rate.exOre += lineEx;",
     "        if (percent === 0) { vatOre += lineVat; continue; }\n        rate.exOre += lineEx;"),
    ("S10", "the ex-VAT amount ignores the quantity",
     "Math.round(Math.round(price * 100) * quantity)",
     "Math.round(Math.round(price * 100))"),
]


def run(path: str) -> subprocess.CompletedProcess:
    env = dict(os.environ, SUMMARY_TS=path)
    return subprocess.run([NODE, HARNESS], cwd=ROOT, env=env, capture_output=True, text=True)


def main() -> int:
    with open(TARGET, encoding="utf8") as f:
        original = f.read()

    tmp = tempfile.mkdtemp(prefix="mutate-summary-")
    baseline = os.path.join(tmp, "baseline.ts")
    with open(baseline, "w", encoding="utf8") as f:
        f.write(original)
    base = run(baseline)
    print("baseline:", base.stdout.strip().splitlines()[-1] if base.stdout.strip() else base.stderr.strip()[-200:])
    if base.returncode != 0:
        print("baseline is not green: refusing to judge mutants")
        return 2

    survivors = []
    for mid, what, old, new in MUTANTS:
        if original.count(old) != 1:
            print(f"{mid}: ANCHOR MATCHES {original.count(old)}x (need exactly 1): {what}")
            return 2
        path = os.path.join(tmp, f"{mid}.ts")
        with open(path, "w", encoding="utf8") as f:
            f.write(original.replace(old, new))
        result = run(path)
        killed = result.returncode != 0
        first = next((l for l in result.stdout.splitlines() if l.startswith("FAIL")), result.stderr.strip()[:120])
        print(f"{mid}: {'KILLED' if killed else 'SURVIVED'}  {what}  {'| ' + first[:110] if killed else ''}")
        if not killed:
            survivors.append(mid)

    print(f"{len(MUTANTS) - len(survivors)}/{len(MUTANTS)} killed" + (f"; survivors: {', '.join(survivors)}" if survivors else ""))
    return 1 if survivors else 0


if __name__ == "__main__":
    sys.exit(main())
