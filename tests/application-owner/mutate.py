#!/usr/bin/env python3
"""
Mutation check for tests/application-owner/run.php (task 48852).

Each mutation undoes one guard of the fix in a COPY of the source files the harness loads (never in the tree), runs
the harness on that copy (HD_ROOT) and expects the rows it names to FAIL. A mutation whose text is not found exactly once
is itself a failure.

    python3 tests/application-owner/mutate.py
    HD_ROOT=/path/to/tree python3 tests/application-owner/mutate.py

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
HD_ROOT = os.path.realpath(os.environ.get('HD_ROOT', REPO))
AH = 'src/modules/bookingfrontend/helpers/ApplicationHelper.php'
OI = 'src/helpers/OwnerIdentifier.php'
ER = 'src/modules/bookingfrontend/repositories/EventRepository.php'
SO = 'src/modules/booking/inc/class.soevent.inc.php'
AR = 'src/modules/bookingfrontend/repositories/ApplicationRepository.php'

# (name, file, regex, replacement, rows that must FAIL)
MUTATIONS = [
    ('M1 :84 a partial stored without a session id is matched again', AH,
     r"\n\s+&& !OwnerIdentifier::isEmpty\(\$application\['session_id'\]\)", '', ['F6']),
    ('M2 :93 an empty stored ssn is matched again', AH,
     r"!OwnerIdentifier::isEmpty\(\$application\['customer_ssn'\]\)\n\s+&& ", '', ['F5']),
    ('M3 :98 the session orgnr is compared with any stored number', AH,
     r"if \(\$ownedByNumber && \$application\['customer_organization_number'\] === \$orgnr\)",
     "if ($application['customer_identifier_type'] === 'organization_number' && $application['customer_organization_number'] === $orgnr)",
     ['F1', 'F7']),
    ('M4 :105 the list is compared with any stored number', AH,
     r"if \(\$ownedByNumber && \$this->userHelper->organizations\)",
     "if ($application['customer_identifier_type'] === 'organization_number' && $this->userHelper->organizations)",
     ['F2', 'F3', 'F4', 'F7', 'F8']),
    ('M5 identifiesOrganization takes the placeholder for a number', OI,
     r"return !self::isEmpty\(\$value\) && !self::isPlaceholderOrgNumber\(\$value\);", "return !self::isEmpty($value);",
     ['F1', 'F2', 'F7', 'F8', 'EF1', 'EF2', 'SF1', 'SF2', 'LF1']),
    ('M6 identifiesOrganization takes an empty value for a number', OI,
     r"return !self::isEmpty\(\$value\) && !self::isPlaceholderOrgNumber\(\$value\);",
     "return !self::isPlaceholderOrgNumber($value);", ['F3', 'F4']),
    ('M7 the placeholder is recognised only unpadded (no trim)', OI,
     r"is_string\(\$value\) && trim\(\$value\) === self::PLACEHOLDER_ORG_NUMBER",
     "is_string($value) && $value === self::PLACEHOLDER_ORG_NUMBER", ['F7']),
    ('M8 REST list: any truthy session orgnr has own events', ER,
     r"\$ownOrgNumber = \$loggedInAs && OwnerIdentifier::identifiesOrganization\(\$loggedInAs\) \? \$loggedInAs : null;",
     "$ownOrgNumber = $loggedInAs ?: null;", ['EF1', 'EF2']),
    ('M9 REST list: own events only, for a number naming nobody, falls through to the public list', ER,
     r'\$conditions\[\] = "1 = 0";', '$conditions[] = "bb_event.include_in_list = 1";', ['EF2']),
    ('M10 REST list: a falsy session orgnr (\'0\') is taken for a login', ER,
     r"\$ownOrgNumber = \$loggedInAs && OwnerIdentifier::", "$ownOrgNumber = OwnerIdentifier::", ['EN4']),
    ('M11 legacy list: any truthy session orgnr has own events', SO,
     r"\$own_org_number = \$logged_in_as && \\App\\helpers\\OwnerIdentifier::identifiesOrganization\(\$logged_in_as\) \? \$logged_in_as : null;",
     "$own_org_number = $logged_in_as ?: null;", ['SF1', 'SF2']),
    ('M12 legacy list: own events only, for a number naming nobody, falls through to the public list', SO,
     r'\? " AND 1 = 0"', '? " AND include_in_list = 1"', ['SF2']),
    ('M13 legacy list: a falsy session orgnr (\'0\') is taken for a login', SO,
     r"\$own_org_number = \$logged_in_as && \\App", "$own_org_number = \\App", ['SN4']),
    ('M14 application list: the placeholder is listed as a number', AR,
     r"if \(!empty\(\$org\['organization_number'\]\)\n\s+&& OwnerIdentifier::identifiesOrganization\(\$org\['organization_number'\]\)\) \{",
     "if (!empty($org['organization_number'])) {", ['LF1']),
    ('M15 application list: \'0\' is listed as a number (the !empty kept from before dropped)', AR,
     r"if \(!empty\(\$org\['organization_number'\]\)\n\s+&& OwnerIdentifier::",
     "if (OwnerIdentifier::", ['LN3']),
]


def run(root, list_files=False):
    env = dict(os.environ, HD_ROOT=root)
    if list_files:
        env['HARNESS_LIST_FILES'] = '1'
    proc = subprocess.run(['php', os.path.join(HERE, 'run.php')], env=env, capture_output=True, text=True)
    failed = set(re.findall(r'^FAIL ([A-Z]\S*)', proc.stdout, re.M))
    files = re.findall(r'^FILE (.+)$', proc.stdout, re.M)
    return proc.returncode, failed, files, proc.stdout


def main():
    rc, failed, files, out = run(HD_ROOT, list_files=True)
    if rc != 0 or failed:
        print('the unmutated tree does not pass; nothing to mutate:\n' + out)
        return 1
    src_files = sorted({os.path.relpath(os.path.realpath(f), HD_ROOT) for f in files
                        if os.path.realpath(f).startswith(HD_ROOT + '/src/')})
    for needed in (AH, OI, ER, SO, AR):
        if needed not in src_files:
            print(f'{needed} was not loaded by the harness: {src_files}')
            return 1
    caught_all = True
    for name, target_file, pattern, repl, rows in MUTATIONS:
        tmp = tempfile.mkdtemp(prefix='ao-mutant-')
        try:
            for f in src_files:
                os.makedirs(os.path.dirname(os.path.join(tmp, f)), exist_ok=True)
                shutil.copy(os.path.join(HD_ROOT, f), os.path.join(tmp, f))
            # the validator bootstrap requires its siblings by path: copy the whole directory
            validator = 'src/modules/booking/inc/vendor/symfony/validator'
            shutil.rmtree(os.path.join(tmp, validator), ignore_errors=True)
            shutil.copytree(os.path.join(HD_ROOT, validator), os.path.join(tmp, validator))
            target = os.path.join(tmp, target_file)
            src = open(target, encoding='utf-8').read()
            new, count = re.subn(pattern, lambda m: repl, src, flags=re.S)
            if count != 1:
                print(f'BROKEN {name}: the text to mutate matched {count} times, not once')
                caught_all = False
                continue
            open(target, 'w', encoding='utf-8').write(new)
            rc, failed, _, out = run(tmp)
            missing = [r for r in rows if r not in failed]
            if rc != 0 and not missing:
                print(f'CAUGHT {name}: {", ".join(sorted(failed))}')
            else:
                caught_all = False
                print(f'SURVIVED {name}: expected {rows} to fail, failed {sorted(failed)}')
        finally:
            shutil.rmtree(tmp)
    print(f'\n{len(src_files)} source files copied per mutant')
    print('ALL CAUGHT' if caught_all else 'NOT ALL CAUGHT')
    return 0 if caught_all else 1


if __name__ == '__main__':
    sys.exit(main())
