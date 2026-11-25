#!/usr/bin/env python3
"""
Mutation check for run_static.mts (the building search's data-lang-no-results guard, task 48593).

One deliberate defect at a time is applied to a TEMP COPY of the two files that hold the containers today (never to
the tree) and the guard is pointed at it with BS_ROOT; it must go red for each. The baseline on the same copy must be
green first, and every replacement must match exactly once.

Usage:  python3 tests/building-select/mutate.py
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
NODE = os.environ.get("NODE_BIN", "node")
GUARD = os.path.join(HERE, "run_static.mts")

SHOW = "src/modules/booking/html/base/hospitality/show/hospitality_show.js"
COMPONENT = "src/modules/booking/html/base/components/building_select/building_select.twig"
FILES = [SHOW, COMPONENT]

KEY = "data-lang-no-results=\"' + esc(lang('building_select_no_results')) + '\">'"
PICKER = 'id="main-resource-building" class="building-select"'
INLINE = 'id="inline-building-select" class="building-select" style="flex:1;max-width:20rem"'

# (id, file, what, old, new)
MUTANTS = [
    ("G1", SHOW, "the Detaljer resource picker (:669) loses the attribute; the inline search still has it",
     f"{PICKER} {KEY}", f"{PICKER}>'"),
    ("G2", SHOW, "the inline building search (:1151) loses the attribute; the picker still has it",
     f"{INLINE} {KEY}", f"{INLINE}>'"),
    ("G3", COMPONENT, "the shared twig component loses the attribute",
     "data-lang-no-results=\"{{ lang('booking.building_select_no_results') }}\">", ">"),
    ("G4", SHOW, "the picker's attribute is misspelt (data-lang-no-result)",
     f"{PICKER} data-lang-no-results=", f"{PICKER} data-lang-no-result="),
    ("G5", SHOW, "the picker's attribute moved to its child <input>: in the file, but not on the container",
     f"{PICKER} {KEY} +\n\t\t\t\t'<input type=\"text\" class=\"building-select__input ds-input\"",
     f"{PICKER}>' +\n\t\t\t\t'<input type=\"text\" data-lang-no-results=\"' + esc(lang('building_select_no_results')) + '\" class=\"building-select__input ds-input\""),
    ("G6", SHOW, "a NEW container is added without the attribute (a site the guard has never seen)",
     "html += '<div id=\"resource-building-groups\"></div>';",
     "html += '<div id=\"resource-building-groups\"></div>';\n\t\thtml += '<div id=\"third-building-select\" class=\"building-select\"></div>';"),
]


def run(tree):
    env = dict(os.environ)
    env["BS_ROOT"] = tree
    p = subprocess.run([NODE, GUARD], env=env, capture_output=True, text=True)
    out = (p.stdout + p.stderr).strip().splitlines()
    first_fail = next((l for l in out if l.startswith("FAIL")), "")
    return p.returncode, (out[-1] if out else ""), first_fail


def fresh_tree():
    tree = tempfile.mkdtemp(prefix="building-select-mut-")
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
