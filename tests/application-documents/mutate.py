#!/usr/bin/env python3
"""
Mutation check for tests/application-documents/run.php (task 48864).

Each mutation undoes one property of the fix in a COPY of the source files the harness loads (never in the tree), runs
the harness on that copy (HD_ROOT) and expects the rows it names to FAIL. A mutation whose text is not found exactly once
is itself a failure.

    python3 tests/application-documents/mutate.py
    HD_ROOT=/path/to/tree python3 tests/application-documents/mutate.py

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
AC = 'src/modules/bookingfrontend/controllers/applications/ApplicationController.php'

# (name, regex, replacement, rows that must FAIL); all in ApplicationController
MUTATIONS = [
    ('M1 the list is not checked',
     r"if \(!\$this->applicationHelper->canViewApplication\(\$application, \$request\)\) \{\n(\s+)return ResponseHelper::sendErrorResponse\(\['error' => 'Unauthorized to view this application'\], 403\);",
     "if (false) {\n\\1return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized to view this application'], 403);",
     ['F1', 'F2', 'F3', 'F4', 'F5']),
    ('M2 the download is not checked',
     r"if \(!\$this->applicationHelper->canViewApplication\(\$application, \$request\)\) \{\n(\s+)return ResponseHelper::sendErrorResponse\(\['error' => 'Unauthorized to view this document'\], 403\);",
     "if (false) {\n\\1return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized to view this document'], 403);",
     ['F1', 'F2', 'F3', 'F4', 'F5']),
    ('M3 the download checks the application with the DOCUMENT\'s id',
     r"getApplicationById\(\(int\)\$document->owner_id\)", "getApplicationById((int)$args['id'])", ['N1', 'N2', 'N3', 'N4']),
    ('M4 the list check inverted',
     r"if \(!\$this->applicationHelper->canViewApplication\(\$application, \$request\)\) \{\n(\s+)return ResponseHelper::sendErrorResponse\(\['error' => 'Unauthorized to view this application'\], 403\);",
     "if ($this->applicationHelper->canViewApplication($application, $request)) {\n\\1return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized to view this application'], 403);",
     ['N1', 'N2', 'N3', 'N4']),
    ('M5 the list of a missing application is not refused',
     r"if \(!\$application\) \{\n(\s+)return ResponseHelper::sendErrorResponse\(\['error' => 'Application not found'\], 404\);",
     "if (false) {\n\\1return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);", ['F6']),
    ('M6 the download of a document without its application is not refused',
     r"if \(!\$application\) \{\n(\s+)return ResponseHelper::sendErrorResponse\(\['error' => 'Associated application not found'\], 404\);",
     "if (false) {\n\\1return ResponseHelper::sendErrorResponse(['error' => 'Associated application not found'], 404);", ['F7']),
    ('M7 both overrides gone (the inherited handlers again)',
     r"public function getDocuments\((.*?)public function downloadDocument\(",
     lambda m: "public function getDocumentsGone(" + m.group(1) + "public function downloadDocumentGone(",
     ['F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7']),
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
    src_files = [os.path.relpath(os.path.realpath(f), HD_ROOT) for f in files
                 if os.path.realpath(f).startswith(HD_ROOT + '/src/')]
    if AC not in src_files:
        print(f'{AC} was not loaded by the harness: {src_files}')
        return 1
    caught_all = True
    for name, pattern, repl, rows in MUTATIONS:
        tmp = tempfile.mkdtemp(prefix='ad-mutant-')
        try:
            for f in src_files:
                os.makedirs(os.path.dirname(os.path.join(tmp, f)), exist_ok=True)
                shutil.copy(os.path.join(HD_ROOT, f), os.path.join(tmp, f))
            target = os.path.join(tmp, AC)
            src = open(target, encoding='utf-8').read()
            new, count = re.subn(pattern, repl, src, flags=re.S)
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
