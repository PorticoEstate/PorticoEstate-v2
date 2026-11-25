#!/usr/bin/env python3
"""
Mutation driver for tests/catering-day-sums/run.mts (GH #1478, unit C2). Each mutant carries ONE deliberate defect, in
the per-day sums (utils/catering-day-sum.ts), in the checkout's day grouping (utils/checkout-total.ts) or in the offers
line of the checkout's catering section (components/checkout/hospitality/hospitality-section.tsx). The harness must go
red on each. The unmutated files must be green. Mutants are written to a temp dir, never to the tree; each is handed to
run.mts through the environment variable named in its entry.

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
CLIENT = os.path.join(ROOT, 'src/modules/bookingfrontend/client/src')
DAY_SUM_TS = os.path.join(CLIENT, 'utils/catering-day-sum.ts')
CHECKOUT_TOTAL_TS = os.path.join(CLIENT, 'utils/checkout-total.ts')
SECTION_TSX = os.path.join(CLIENT, 'components/checkout/hospitality/hospitality-section.tsx')
RUN = os.path.join(ROOT, 'tests/catering-day-sums/run.mts')

DAY_SUM = 'sumOreOrNull(day.orders.map(order => order.ore));'

# (name, source file, environment variable that hands run.mts the mutated copy, old, new): old must occur exactly once
# in the source file
MUTANTS = [
    # One line misassigned: the first order of each day reads the amount of the day's last order
    ('misassigned-line', DAY_SUM_TS, 'CDS_TS',
     DAY_SUM,
     'sumOreOrNull(day.orders.map((order, index) => (index === 0 ? day.orders[day.orders.length - 1] : order).ore));'),
    # One line left out: the last order of each day is in no day sum
    ('last-order-of-each-day-dropped', DAY_SUM_TS, 'CDS_TS',
     DAY_SUM,
     'sumOreOrNull(day.orders.slice(0, -1).map(order => order.ore));'),
    # An unknown VAT read as zero: the day with an unknown line gets a number, where it must have none
    ('unknown-vat-read-as-zero', DAY_SUM_TS, 'CDS_TS',
     DAY_SUM,
     'sumOreOrNull(day.orders.map(order => order.ore ?? 0));'),
    # A cancelled order counted in its day's sum: the day grouping takes every order, the cancelled ones too. The total
    # still leaves them out, so the footing check goes red as well
    ('cancelled-order-counted-in-a-day-sum', CHECKOUT_TOTAL_TS, 'CTOTAL_TS',
     'groupByServingDay(activeCateringOrders(orders), lang, nowMs).map(group => ({',
     'groupByServingDay(orders, lang, nowMs).map(group => ({'),
    # The line before the first order without an available hospitality in the gate: canAdd no longer needs one
    ('offers-line-without-hospitality-term', SECTION_TSX, 'SECTION_TSX',
     'const canAdd = allowAdd && hospitalities.length > 0;',
     'const canAdd = allowAdd;'),
    # The line before the first order names no offer
    ('offers-line-names-no-offer', SECTION_TSX, 'SECTION_TSX',
     'cateringOffersText(hospitalities.map(hospitality => hospitality.name), lang)',
     'cateringOffersText([], lang)'),
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

    sources = {}
    bad = 0
    with tempfile.TemporaryDirectory() as tmp:
        for name, source, env_name, frm, to in MUTANTS:
            if source not in sources:
                sources[source] = open(source, encoding='utf8').read()
            text = sources[source]
            if text.count(frm) != 1:
                print(f'FAIL {name}: the text to mutate is not in the file exactly once')
                bad = 1
                continue
            path = os.path.join(tmp, f'{name}{os.path.splitext(source)[1]}')
            with open(path, 'w', encoding='utf8') as handle:
                handle.write(text.replace(frm, to))
            result = run({env_name: path})
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
