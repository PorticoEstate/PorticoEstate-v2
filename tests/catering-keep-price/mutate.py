#!/usr/bin/env python3
"""Mutation driver for tests/catering-keep-price/run.php.

Each mutant is applied to a temp copy of the files the harness reads (its own src/ tree), and the harness must go RED
on it: exit 1 with FAIL rows. The unmutated copy is the positive control: it must go GREEN, otherwise a red mutant
proves nothing. A mutation whose anchor text is not found exactly once is reported as NOT APPLIED and fails the run,
so a silent no-op cannot pass as a red mutant.

    python3 tests/catering-keep-price/mutate.py

Exit code: 0 control green and every mutant red, 1 otherwise.
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
HARNESS = os.path.join(ROOT, 'tests', 'catering-keep-price', 'run.php')
VENDOR = os.path.join(ROOT, 'vendor', 'autoload.php')
CONTROLLER = 'modules/bookingfrontend/controllers/applications/HospitalityOrderController.php'

MATCH = 'if (!empty($keptByArticle[$articleId])) {'
NEW_PRICE = '$unitPrice = (float)$pricing[\'effective_price\'];'

MUTANTS = {
    # The defect the ruling forbids: every line re-resolved at today's menu price, as on base.
    'M1 re-resolve kept lines (base behaviour)': [
        (CONTROLLER, MATCH, 'if (false) {'),
    ],
    # A kept line is re-priced when its quantity changes.
    'M2 re-price a kept line on a quantity change': [
        (CONTROLLER, MATCH,
         'if (!empty($keptByArticle[$articleId]) && (int)$keptByArticle[$articleId][0][\'quantity\'] === $quantity) {'),
    ],
    # A NEW line is given the price of the first stored line.
    'M3 a new line takes an old price': [
        (CONTROLLER, NEW_PRICE, '$unitPrice = $storedLines[0][\'unit_price\'] ?? (float)$pricing[\'effective_price\'];'),
    ],
    # Lines matched by request position, not by article: a kept line takes the price of whatever stored line sits there.
    'M4 match by request position': [
        (CONTROLLER, MATCH, 'if (isset($storedLines[$i]) || !empty($keptByArticle[$articleId])) {'),
        (CONTROLLER, '$kept = array_shift($keptByArticle[$articleId]);',
         '$kept = $storedLines[$i] ?? array_shift($keptByArticle[$articleId]);'),
    ],
}

REQUIRED = re.compile(r"'((?:modules|traits|helpers)/[^']+\.php)'")


def required_files():
    with open(HARNESS, encoding='utf-8') as fh:
        return sorted(set(REQUIRED.findall(fh.read())))


def build_tree(dest, mutations):
    """Copy the files the harness reads into dest/src and apply the mutations. Returns False if an anchor is not unique."""
    for rel in required_files():
        target = os.path.join(dest, 'src', rel)
        os.makedirs(os.path.dirname(target), exist_ok=True)
        shutil.copyfile(os.path.join(ROOT, 'src', rel), target)
    for rel, old, new in mutations:
        target = os.path.join(dest, 'src', rel)
        with open(target, encoding='utf-8') as fh:
            text = fh.read()
        if text.count(old) != 1:
            print(f"  anchor found {text.count(old)} times (want 1): {old[:70]}")
            return False
        with open(target, 'w', encoding='utf-8') as fh:
            fh.write(text.replace(old, new))
    return True


def run_harness(tree):
    env = dict(os.environ, HD_ROOT=tree, VENDOR_AUTOLOAD=VENDOR)
    proc = subprocess.run(['php', HARNESS], env=env, capture_output=True, text=True, timeout=600)
    fails = [line for line in proc.stdout.splitlines() if line.startswith('FAIL ')]
    summary = next((line for line in proc.stdout.splitlines() if line.startswith('Catering keep-price harness')), '')
    return proc.returncode, len(fails), summary


def main():
    results = []
    with tempfile.TemporaryDirectory(prefix='t50549-mutate-') as work:
        control = os.path.join(work, 'control')
        os.makedirs(control)
        build_tree(control, [])
        code, nfail, summary = run_harness(control)
        print(f"control (unmutated copy): exit {code}, {nfail} FAIL rows | {summary}")
        results.append(('control', code == 0 and nfail == 0))

        for name, mutations in MUTANTS.items():
            tree = os.path.join(work, re.sub(r'\W+', '_', name))
            os.makedirs(tree)
            if not build_tree(tree, mutations):
                print(f"{name}: NOT APPLIED")
                results.append((name, False))
                continue
            code, nfail, summary = run_harness(tree)
            red = code == 1 and nfail > 0
            print(f"{name}: exit {code}, {nfail} FAIL rows -> {'RED' if red else 'NOT RED'} | {summary}")
            results.append((name, red))

    ok = all(passed for _, passed in results)
    print('MUTATION DRIVER: ' + ('PASS' if ok else 'FAIL'))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
