#!/usr/bin/env python3
"""Mutation check for tests/event-form-fallback/run.php (#1498).

    python3 tests/event-form-fallback/mutate.py <scratch-dir>

Writes a copy of EventFormFallback.php per mutant into <scratch-dir>, runs the harness against each copy
with HELPER_FILE, and requires a non-zero exit for every one. The unmutated helper must pass. The scratch
directory must be outside the repo tree.
"""
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
HELPER = os.path.join(REPO, 'src', 'modules', 'booking', 'helpers', 'EventFormFallback.php')
HARNESS = os.path.join(HERE, 'run.php')

# (name, old, new): each old must occur exactly once in the helper.
MUTANTS = [
    ('M1 hasParticipants always true',
     "\t\tforeach ($agegroups as $row)",
     "\t\treturn true;\n\t\tforeach ($agegroups as $row)"),
    ('M2 agegroups fill ignores the event rows',
     "if (empty($event['agegroups']) && self::hasParticipants",
     "if (self::hasParticipants"),
    ('M3 audience fill ignores the event audience',
     "if (empty($event['audience']) && !empty($application['audience']))",
     "if (!empty($application['audience']))"),
    ('M4 female count ignored',
     " || (int)($row['female'] ?? 0) > 0",
     ""),
    ('M5 empty application audience written',
     "if (empty($event['audience']) && !empty($application['audience']))",
     "if (empty($event['audience']))"),
]


def run_harness(helper_path):
    env = dict(os.environ, HELPER_FILE=helper_path)
    return subprocess.run(['php', HARNESS], env=env, capture_output=True, text=True).returncode


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        return 2
    scratch = os.path.abspath(sys.argv[1])
    if scratch == REPO or scratch.startswith(REPO + os.sep):
        print('scratch dir must be outside the repo tree')
        return 2
    os.makedirs(scratch, exist_ok=True)

    with open(HELPER, encoding='utf-8') as fh:
        original = fh.read()

    base_rc = run_harness(HELPER)
    print(f"{'PASS' if base_rc == 0 else 'FAIL'}  unmutated helper: exit {base_rc}")
    ok = base_rc == 0

    for name, old, new in MUTANTS:
        count = original.count(old)
        if count != 1:
            print(f'ERR   {name}: anchor occurs {count} times, expected 1')
            ok = False
            continue
        path = os.path.join(scratch, 'EventFormFallback.mutant.php')
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(original.replace(old, new))
        rc = run_harness(path)
        caught = rc != 0
        print(f"{'CAUGHT' if caught else 'MISSED'}  {name}: harness exit {rc}")
        ok = ok and caught

    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
