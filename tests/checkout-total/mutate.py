#!/usr/bin/env python3
"""
Mutation driver for tests/checkout-total/run.mts (tasks 50144, 50232): each mutant of utils/checkout-total.ts (or of
cart-section.tsx, for the recurring section and for the wiring of the catering read, or of hospitality-hooks.ts) carries
ONE deliberate defect in the checkout's total, and the harness must go red on it. The unmutated files must be green.
Mutants are written to a temp dir, never to the tree.

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
CART = os.path.join(ROOT, 'src/modules/bookingfrontend/client/src/components/checkout/cart-section.tsx')
HOOKS = os.path.join(ROOT, 'src/modules/bookingfrontend/client/src/components/checkout/hooks/hospitality-hooks.ts')
# Which file a mutant changes, and the variable run.mts reads its alternative copy from
TARGETS = {'total': (SRC, 'CT_TS', '.ts'), 'cart': (CART, 'CART_TSX', '.tsx'), 'hooks': (HOOKS, 'HOOKS_TS', '.ts')}
RUN = os.path.join(ROOT, 'tests/checkout-total/run.mts')

LINE_ORE = """export const cateringLineOre = (line: Pick<IHospitalityOrderLine, 'amount' | 'tax_percent'>): number | null =>
    hospitalityLineTotalOre(line);"""

# (name, old, new[, target]); target defaults to 'total' (utils/checkout-total.ts)
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
    # Declaration ⑥ (pe-pm 50167): a recurring application's catering is counted ONCE, never × its instances
    ('recurring-catering-multiplied',
     'const catering = cateringTotalOre(cateringOrders.filter(order => ids.has(order.application_id)));',
     'const catering = applications.reduce<number | null>((sum, application) => {\n'
     '        const own = cateringTotalOre(cateringOrders.filter(order => order.application_id === application.id));\n'
     '        return sum === null || own === null ? null : sum + own * instancesOf(application);\n'
     '    }, 0);'),
    # ... and LISTED: the recurring section renders the catering it counts
    ('recurring-section-lists-no-catering',
     '''                        <HospitalitySection
                            applicationIds={recurringApplications.map(a => a.id)}
                            applications={recurringApplications}
                            allowAdd={false}
                        />''',
     '                        null',
     'cart'),
    # Task 50232: a catering read that FAILED must make the total unknown («-»), never a sum that leaves it out.
    # The mapping itself (cartCateringInput): an unreadable read passes the orders on, as the cart did before
    ('unreadable-read-passes-orders-on',
     "state === 'unreadable' ? null : orders;",
     'orders;'),
    # ... the total: a null catering input read as «no catering»
    ('null-catering-input-read-as-none',
     'if (cateringOrders === null) return null;',
     'cateringOrders = cateringOrders ?? [];'),
    # ... the state of the reads: a failed read not counted, or hidden behind one that is still going
    ('failed-read-not-counted-in-state',
     "reads.some(read => read.isError) ? 'unreadable' : reads.some(read => read.isPending) ? 'pending' : 'read'",
     "reads.some(read => read.isPending) ? 'pending' : 'read'"),
    ('failed-read-hidden-behind-pending',
     "reads.some(read => read.isError) ? 'unreadable' : reads.some(read => read.isPending) ? 'pending' : 'read'",
     "reads.some(read => read.isPending) ? 'pending' : reads.some(read => read.isError) ? 'unreadable' : 'read'"),
    # ... the wiring in cart-section (the state of 9d308841c: the orders go in whatever the state is) ...
    ('cart-passes-orders-whatever-the-state',
     'cartCateringInput(catering.state, enableHospitality ? catering.orders : [])',
     'enableHospitality ? catering.orders : []',
     'cart'),
    # ... or the mapping is called but not told the state
    ('cart-tells-the-mapping-the-read-went-fine',
     'cartCateringInput(catering.state, ',
     "cartCateringInput('read', ",
     'cart'),
    # ... or the hook stops reporting a failed read
    ('hook-reports-no-failed-read',
     'state: cartCateringState(results),',
     "state: 'read',",
     'hooks'),
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

    bad = 0
    with tempfile.TemporaryDirectory() as tmp:
        for name, frm, to, *rest in MUTANTS:
            source, env_name, suffix = TARGETS[rest[0] if rest else 'total']
            text = open(source, encoding='utf8').read()
            if text.count(frm) != 1:
                print(f'FAIL {name}: the text to mutate is not in the file exactly once')
                bad = 1
                continue
            path = os.path.join(tmp, f'{name}{suffix}')
            open(path, 'w', encoding='utf8').write(text.replace(frm, to))
            result = run({env_name: path})
            if result.returncode == 0:
                print(f'FAIL {name}: the harness stayed GREEN on a broken total')
                bad = 1
            else:
                fails = [line for line in result.stdout.splitlines() if line.startswith('FAIL ')]
                print(f'ok   {name}: red ({len(fails) - 1} checks failed; first: {fails[0][:110] if fails else result.stderr[:110]})')
    return bad


if __name__ == '__main__':
    sys.exit(main())
