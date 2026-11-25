#!/usr/bin/env python3
"""
Mutation driver for tests/catering-serving-time-help/run.mts (GH #1478 unit E; task 50460). Each mutant of
service/utils/serving-times.ts carries ONE deliberate defect in the on-site help or in its list, and the harness must
go red on it. The unmutated file must be green. Mutants are written to a temp dir, never to the tree.

    python3 tests/catering-serving-time-help/mutate.py

A mutant counts as caught only when the harness exits 1 (a failing check) AND reports loading that mutant's file.
Exit 2 (the loader could not load the module) is not a catch.

Exit code: 0 when the original passes and every mutant is caught, 1 otherwise.
"""
import os
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SRC = os.path.join(ROOT, 'src/modules/bookingfrontend/client/src/service/utils/serving-times.ts')
RUN = os.path.join(ROOT, 'tests/catering-serving-time-help/run.mts')

# (name, old, new): `old` must occur exactly once in SRC
MUTANTS = [
    # The text names the kitchen's own span, not the first and last time the list offers.
    ('kitchen-span-alone',
     "return bounds ? {kind: 'kitchen', ...bounds} : {kind: 'none'};",
     "return bounds && input.kitchenSpan ? {kind: 'kitchen', ...input.kitchenSpan} : {kind: 'none'};"),
    # The application's own times shown on an empty on-site list: a range the list does not offer.
    ('application-text-on-empty-onsite',
     "return kitchenClosedThatDay ? {kind: 'closed'} : {kind: 'none'};",
     "return kitchenClosedThatDay ? {kind: 'closed'} : input.isDelivery ? {kind: 'none'} : {kind: 'application'};"),
    # The closed message on a kitchen that is open that day, with no overlap with the booking.
    ('closed-on-no-overlap',
     'const kitchenClosedThatDay = !input.isDelivery && input.kitchenHoursJudgeable && input.kitchenSpan === null;',
     'const kitchenClosedThatDay = !input.isDelivery && input.kitchenHoursJudgeable;'),
    # Hours that cannot be judged shown as a range.
    ('unjudgeable-hours-show-range',
     "if (input.isDelivery || !input.kitchenHoursKnown) return {kind: 'application'};",
     "if (input.isDelivery) return {kind: 'application'};"),
    # Hours that cannot be judged read as a closure.
    ('unjudgeable-empty-reads-closed',
     'const kitchenClosedThatDay = !input.isDelivery && input.kitchenHoursJudgeable && input.kitchenSpan === null;',
     'const kitchenClosedThatDay = !input.isDelivery && input.kitchenSpan === null;'),
    # A building with no season rows, close-without-season on: closed on every day. This mutant reads it as no times.
    ('no-season-rows-read-as-no-times',
     'const kitchenClosedThatDay = !input.isDelivery && input.kitchenHoursJudgeable && input.kitchenSpan === null;',
     'const kitchenClosedThatDay = !input.isDelivery && input.kitchenHoursKnown && input.kitchenSpan === null;'),
    # The wall-clock start is built from elapsed minutes after local midnight. Exact on every day except the two DST
    # changes, where the list's starts drift by an hour. Only the DST rows of the grid can catch it.
    ('dst-elapsed-minutes-not-wall-clock',
     "\t\tbooking.to.getMinutes(),\n\t).filter(hm => {\n\t\tconst [h, m] = hm.split(':').map(Number);\n"
     "\t\tconst at = new Date(day.getFullYear(), day.getMonth(), day.getDate(), h, m);",
     "\t\tbooking.to.getMinutes(),\n\t).filter(hm => {\n\t\tconst [h, m] = hm.split(':').map(Number);\n"
     "\t\tconst at = new Date(new Date(day.getFullYear(), day.getMonth(), day.getDate()).getTime() + (h * 60 + m) * 60000);"),
    # The booking's first start is not rounded up to the grid: 07:07 is offered as 07:07, not 07:15.
    ('start-not-rounded-up',
     'let m = Math.ceil(fromMinute / 15) * 15;',
     'let m = fromMinute;'),
]


def run_harness(serving_path):
    env = dict(os.environ, SERVING_TS=serving_path)
    return subprocess.run(['node', RUN], env=env, capture_output=True, text=True)


def main():
    with open(SRC, encoding='utf-8') as f:
        src = f.read()

    control = run_harness(SRC)
    control_ok = control.returncode == 0
    print(f"control (unmutated): exit {control.returncode} {'green' if control_ok else 'RED'}")
    if not control_ok:
        print(control.stdout + control.stderr)

    not_caught = []
    with tempfile.TemporaryDirectory(prefix='serving-time-mutants-') as tmp:
        for name, old, new in MUTANTS:
            count = src.count(old)
            if count != 1:
                print(f"mutant {name}: target text occurs {count} times, not once; not applied")
                not_caught.append(name)
                continue
            path = os.path.join(tmp, f'{name}.ts')
            with open(path, 'w', encoding='utf-8') as f:
                f.write(src.replace(old, new))
            res = run_harness(path)
            loaded = f'serving-times loaded from: {path}' in res.stdout
            caught = loaded and res.returncode == 1
            first_fail = next((line for line in res.stdout.splitlines() if line.startswith('FAIL ')), '')
            if caught:
                print(f"mutant {name}: caught  {first_fail[:170]}")
            else:
                print(f"mutant {name}: NOT caught (exit {res.returncode}, loaded={loaded})")
                print(res.stdout + res.stderr)
                not_caught.append(name)

    if not control_ok or not_caught:
        print(f"RED: control {'green' if control_ok else 'red'}; not caught: {not_caught or 'none'}")
        return 1
    print(f"all {len(MUTANTS)} mutants caught; unmutated green")
    return 0


if __name__ == '__main__':
    sys.exit(main())
