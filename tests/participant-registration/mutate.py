#!/usr/bin/env python3
"""
Mutation check for tests/participant-registration/run.php (task 48864).

Each mutation undoes one property of the fix in a COPY of the files under test (never in the tree), runs the harness on
that copy (HD_ROOT) and expects the rows it names to FAIL. A mutation whose text is not found exactly once is itself a
failure, so a renamed line cannot pass as "caught".

    python3 tests/participant-registration/mutate.py          # mutates this repo's copy
    HD_ROOT=/path/to/tree python3 tests/participant-registration/mutate.py

Exit code: 0 when every mutation is caught by its rows, 1 otherwise.
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
HD_ROOT = os.environ.get('HD_ROOT', REPO)

SO = 'src/modules/booking/inc/class.soparticipant.inc.php'
UI = 'src/modules/bookingfrontend/inc/class.uiparticipant.inc.php'
FILES = [SO, UI, 'src/helpers/Sanitizer.php']

# (name, file, regex, replacement, rows that must FAIL)
MUTATIONS = [
    ('M1 suffix match instead of the same number', SO,
     r"self::phone_key\(\(string\)\$row\['phone'\]\) === \$key",
     "str_ends_with(self::phone_key((string)$row['phone']), $key)", ['F3', 'F4']),
    ('M2 no guard for a phone without digits', SO,
     r"if \(\$key !== ''\)", "if (true)", ['F5']),
    ('M3 another reservation of the same type found', SO,
     r'" AND reservation_id = :reservation_id"\n(\t+)\. " ORDER BY id DESC"',
     '" AND (reservation_id = :reservation_id OR 1=1)"\n\\1. " ORDER BY id DESC"', ['N3']),
    ('M4 another reservation type found', SO,
     r'" WHERE reservation_type = :reservation_type"\n(\t+)\. " AND reservation_id = :reservation_id"\n(\t+)\. " ORDER BY id DESC"',
     '" WHERE (reservation_type = :reservation_type OR 1=1)"\n\\1. " AND reservation_id = :reservation_id"\n\\2. " ORDER BY id DESC"',
     ['N3']),
    ('M5 the oldest registration instead of the latest', SO,
     r'ORDER BY id DESC', 'ORDER BY id ASC', ['N4']),
    ('M6 loose == between the digit strings', SO,
     r"\) === \$key\)", ") == $key)", ['N9']),
    ('M7 the request phone written onto a found registration', UI,
     r"!empty\(\$participant\['id'\]\) \? \$participant\['phone'\] : \$phone", "$phone", ['F9']),
    ('M8 doValidate puts the phone in the SQL text again', SO,
     r'" AND phone = :phone\{\$filter\}";(.*?)\'reservation_id\'(\s+)=> \(int\)\$entity\[\'reservation_id\'\],\n\s+\'phone\'\s+=> \(string\)\$entity\[\'phone\'\]',
     lambda m: '" AND phone = \'{$entity[\'phone\']}\'{$filter}";' + m.group(1) + "'reservation_id'" + m.group(2)
     + "=> (int)$entity['reservation_id']", ['F6', 'F10']),
    ('M9 the participant count puts the reservation type in the SQL text again', SO,
     r'"SELECT sum\(quantity\) as cnt"\n(\t+)\. " FROM bb_participant"\n(\t+)\. " WHERE reservation_type = :reservation_type"',
     '"SELECT sum(quantity) as cnt"\n\\1. " FROM bb_participant"\n\\2. " WHERE reservation_type = \'{$reservation_type}\' AND :reservation_type = :reservation_type"',
     ['F10']),
    ('M10 the found registration is not the requester\'s: any row of the reservation', SO,
     r"if \(self::phone_key\(\(string\)\$row\['phone'\]\) === \$key\)", "if (true)", ['F3', 'F8']),
]


def run(root):
    env = dict(os.environ, HD_ROOT=root)
    proc = subprocess.run(['php', os.path.join(HERE, 'run.php')], env=env, capture_output=True, text=True)
    failed = set(re.findall(r'^FAIL ([A-Z]\S*)', proc.stdout, re.M))
    return proc.returncode, failed, proc.stdout


def main():
    rc, failed, out = run(HD_ROOT)
    if rc != 0 or failed:
        print('the unmutated tree does not pass; nothing to mutate:\n' + out)
        return 1
    caught_all = True
    for name, path, pattern, repl, rows in MUTATIONS:
        tmp = tempfile.mkdtemp(prefix='pr-mutant-')
        try:
            for f in FILES:
                os.makedirs(os.path.dirname(os.path.join(tmp, f)), exist_ok=True)
                shutil.copy(os.path.join(HD_ROOT, f), os.path.join(tmp, f))
            target = os.path.join(tmp, path)
            src = open(target, encoding='utf-8').read()
            new, count = re.subn(pattern, repl, src, flags=re.S)
            if count != 1:
                print(f'BROKEN {name}: the text to mutate matched {count} times, not once')
                caught_all = False
                continue
            open(target, 'w', encoding='utf-8').write(new)
            rc, failed, out = run(tmp)
            missing = [r for r in rows if r not in failed]
            if rc != 0 and not missing:
                print(f'CAUGHT {name}: {", ".join(sorted(failed))}')
            else:
                caught_all = False
                print(f'SURVIVED {name}: expected {rows} to fail, failed {sorted(failed)}')
        finally:
            shutil.rmtree(tmp)
    print('\nALL CAUGHT' if caught_all else '\nNOT ALL CAUGHT')
    return 0 if caught_all else 1


if __name__ == '__main__':
    sys.exit(main())
