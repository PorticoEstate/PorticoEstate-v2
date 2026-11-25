#!/usr/bin/env python3
"""
Mutation driver for tests/checkout-total/run.mts (task 50144): each mutant of utils/checkout-total.ts carries ONE
deliberate defect in the checkout's total, and the harness must go red on it. The unmutated file must be green.

    python3 tests/checkout-total/mutate.py

The mutants named by the brief come first: catering dropped from the total, net instead of gross, a constant 25 %.
Exit code: 0 when the original passes and every mutant is caught, 1 otherwise.
"""
import os
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SRC = os.path.join(ROOT, 'src/modules/bookingfrontend/client/src/utils/checkout-total.ts')
RUN = os.path.join(ROOT, 'tests/checkout-total/run.mts')

LINE_ORE = """export const cateringLineOre = (line: Pick<IHospitalityOrderLine, 'amount' | 'tax_percent'>): number | null =>
    hospitalityLineTotalOre(line);"""

MUTANTS = [
    ('catering-dropped-from-total',
     'return catering === null ? null : stored + catering;',
     'return stored;'),
    ('net-instead-of-gross',
     LINE_ORE,
     LINE_ORE.replace('hospitalityLineTotalOre(line);', 'Math.round(Number(line.amount) * 100);')),
    ('constant-25-percent',
     LINE_ORE,
     LINE_ORE.replace('hospitalityLineTotalOre(line);', 'hospitalityLineTotalOre({...line, tax_percent: 25});')),
    ('cancelled-order-counted',
     "orders.filter(order => order.status !== 'cancelled');",
     'orders.filter(() => true);'),
    ('other-applications-catering-counted',
     'cateringTotalOre(cateringOrders.filter(order => ids.has(order.application_id)))',
     'cateringTotalOre(cateringOrders)'),
    ('recurring-instances-ignored',
     'applicationStoredOre(application) * instancesOf(application)',
     'applicationStoredOre(application)'),
    ('unknown-vat-read-as-zero',
     LINE_ORE,
     LINE_ORE.replace('hospitalityLineTotalOre(line);', 'hospitalityLineTotalOre(line) ?? Math.round(Number(line.amount) * 100);')),
    ('listed-lines-not-what-is-counted',
     'ore: cateringLineOre(line),',
     'ore: hospitalityLineTotalOre({...line, tax_percent: 25}),'),
]


def run(env_extra=None):
    env = dict(os.environ)
    if env_extra:
        env.update(env_extra)
    return subprocess.run(['node', RUN], env=env, capture_output=True, text=True)


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
            open(path, 'w', encoding='utf8').write(text.replace(frm, to))
            result = run({'CT_TS': path})
            if result.returncode == 0:
                print(f'FAIL {name}: the harness stayed GREEN on a broken total')
                bad = 1
            else:
                fails = [line for line in result.stdout.splitlines() if line.startswith('FAIL ')]
                print(f'ok   {name}: red ({len(fails) - 1} checks failed; first: {fails[0][:110] if fails else result.stderr[:110]})')
    return bad


if __name__ == '__main__':
    sys.exit(main())
