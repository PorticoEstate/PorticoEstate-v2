#!/usr/bin/env python3
"""
Mutation driver for tests/catering-day-sums/run.mts (GH #1478, unit C2): each mutant of utils/catering-day-sum.ts carries
ONE deliberate defect in the per-day sums, and the harness must go red on it. The unmutated file must be green. Mutants
are written to a temp dir, never to the tree.

    python3 tests/catering-day-sums/mutate.py

The brief's mutant (one line misassigned) comes first. Exit code: 0 when the original passes and every mutant is caught,
1 otherwise.
"""
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SRC = os.path.join(ROOT, 'src/modules/bookingfrontend/client/src/utils/catering-day-sum.ts')
RUN = os.path.join(ROOT, 'tests/catering-day-sums/run.mts')

DAY_SUM = 'sumOreOrNull(day.orders.map(order => order.ore));'

# (name, old, new): old must occur exactly once in the file
MUTANTS = [
    # One line misassigned: the first order of each day reads the amount of the day's last order
    ('misassigned-line',
     DAY_SUM,
     'sumOreOrNull(day.orders.map((order, index) => (index === 0 ? day.orders[day.orders.length - 1] : order).ore));'),
    # One line left out: the last order of each day is in no day sum
    ('last-order-of-each-day-dropped',
     DAY_SUM,
     'sumOreOrNull(day.orders.slice(0, -1).map(order => order.ore));'),
    # An unknown VAT read as zero: the day with an unknown line gets a number, where it must have none
    ('unknown-vat-read-as-zero',
     DAY_SUM,
     'sumOreOrNull(day.orders.map(order => order.ore ?? 0));'),
]


def run(env_extra=None):
    env = dict(os.environ)
    if env_extra:
        env.update(env_extra)
    return subprocess.run(['node', RUN], env=env, capture_output=True, text=True)


def failed_checks(stdout):
    # The summary line «FAIL 41/42» is not a failed check
    return [line for line in stdout.splitlines()
            if line.startswith('FAIL ') and not re.fullmatch(r'FAIL \d+/\d+', line)]


def main():
    original = run()
    if original.returncode != 0:
        print('FAIL the unmutated file is not green')
        print(original.stdout[-2000:], original.stderr[-2000:])
        return 1
    print('ok   original: green (' + original.stdout.strip().splitlines()[-1] + ')')

    text = open(SRC, encoding='utf8').read()
    bad = 0
    with tempfile.TemporaryDirectory() as tmp:
        for name, frm, to in MUTANTS:
            if text.count(frm) != 1:
                print(f'FAIL {name}: the text to mutate is not in the file exactly once')
                bad = 1
                continue
            path = os.path.join(tmp, f'{name}.ts')
            with open(path, 'w', encoding='utf8') as handle:
                handle.write(text.replace(frm, to))
            result = run({'CDS_TS': path})
            if result.returncode == 0:
                print(f'FAIL {name}: the harness stayed GREEN on a broken day sum')
                bad = 1
            else:
                fails = failed_checks(result.stdout)
                first = fails[0][:110] if fails else result.stderr[:110]
                print(f'ok   {name}: red ({len(fails)} checks failed; first: {first})')
    return bad


if __name__ == '__main__':
    sys.exit(main())
