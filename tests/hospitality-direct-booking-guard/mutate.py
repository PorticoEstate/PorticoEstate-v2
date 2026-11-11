#!/usr/bin/env python3
"""
Mutation check for tests/hospitality-direct-booking-guard/run.php.

  python3 tests/hospitality-direct-booking-guard/mutate.py

A green harness proves nothing until it is shown to go RED. This copies HospitalityDirectBookingGuard,
breaks ONE rule in the copy, runs the harness against the copy (GUARD_FILE, or CONTROLLER_FILE for a
mutant of HospitalityController), and requires that:
  1. the mutation text was really found exactly once (a mutation that did not apply would "survive"),
  2. the harness exits 1,
  3. the row(s) that are MEANT to catch this mutant are among the failures.
The real file is never touched. Exit 0 = every mutant killed on its own row, 1 = anything else.
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
GUARD = os.path.join(ROOT, "src/modules/booking/services/HospitalityDirectBookingGuard.php")
CONTROLLER = os.path.join(ROOT, "src/modules/booking/controllers/HospitalityController.php")
PHP = shutil.which("php") or "php"

# (id, description, old text, new text, row-name fragments that MUST fail[, "controller" when the mutant is of
# HospitalityController instead of the guard])
MUTANTS = [
    ("M1", "resource side: an OWN link is ignored",
     "        foreach ($linkRows as $row) {\n            if ((int)($row['hospitality_active'] ?? 0) !== 1) {",
     "        foreach ($linkRows as $row) {\n            if (($row['kind'] ?? '') === 'own') {\n                continue;\n            }\n            if ((int)($row['hospitality_active'] ?? 0) !== 1) {",
     ["res/unset->set(past), own active link"]),
    ("M2", "resource side: a REMOTE link is ignored",
     "if (($row['kind'] ?? '') === 'remote' && (int)($row['link_active'] ?? 0) !== 1) {",
     "if (($row['kind'] ?? '') === 'remote') {",
     ["res/unset->set(past), remote active link"]),
    ("M3", "resource side: a link on an INACTIVE hospitality still counts",
     "            if ((int)($row['hospitality_active'] ?? 0) !== 1) {\n                continue;\n            }\n            if (($row['kind']",
     "            if (($row['kind']",
     ["res/unset->set, own link on INACTIVE hospitality", "res/unset->set, remote link on INACTIVE hospitality"]),
    ("M4", "resource side: an INACTIVE remote row still counts",
     "if (($row['kind'] ?? '') === 'remote' && (int)($row['link_active'] ?? 0) !== 1) {",
     "if (($row['kind'] ?? '') === 'remote' && false) {",
     ["res/unset->set, INACTIVE remote row"]),
    ("M5", "resource side: block a non-transition (set->set is refused too)",
     "        if (self::hasDirectBooking($before) || !self::hasDirectBooking($after)) {\n            return [];",
     "        if (!self::hasDirectBooking($after)) {\n            return [];",
     ["res/set->set(date moved), linked", "res/set->set unchanged, linked"]),
    ("M6", "hospitality side: the hospitality's own resource is not checked",
     "            $becomingLinked[] = (int)$after['resource_id'];",
     "            // mutant: own resource dropped",
     ["hosp/create active, own resource has direct booking"]),
    ("M7", "hospitality side: re-activation does not check the remote rows",
     "                $becomingLinked[] = (int)$resourceId;",
     "                // mutant: remote rows dropped",
     ["hosp/update re-activate, own clean, one remote resource has direct booking"]),
    ("M8", "hospitality side: an INACTIVE hospitality save is checked as if it were active",
     "        if ((int)($after['active'] ?? 0) !== 1) {\n            return [];\n        }\n        $wasActive",
     "        if (false) {\n            return [];\n        }\n        $wasActive",
     ["hosp/create INACTIVE on a direct-booking resource", "hosp/update active->inactive while own moves",
      "hosp/update inactive->inactive, own moved"]),
    ("M9", "hospitality side: block a non-transition (an unrelated edit of an existing conflict is refused)",
     "if (!$wasActive || (int)$before['resource_id'] !== (int)$after['resource_id']) {",
     "if (true) {",
     ["hosp/update active->active, own unchanged"]),
    ("M10", "remote side: a non-transition is refused (re-adding an active row)",
     "            && !$rowWasActive\n",
     "",
     ["remote/re-add an already-active row of an existing conflict"]),
    ("M10b", "remote side: deactivating a row is refused too (the remedy is blocked)",
     "            && $rowWillBeActive\n",
     "",
     ["remote/toggle a dead row to dead"]),
    ("M11", "remote side: an INACTIVE hospitality is checked",
     "(int)($hospitality['active'] ?? 0) === 1\n            && !$rowWasActive",
     "true\n            && !$rowWasActive",
     ["remote/add to an INACTIVE hospitality"]),
    ("M12", "simple_booking is read as direct booking",
     "        $value = $resource['direct_booking'] ?? null;",
     "        $value = ($resource['direct_booking'] ?? null) ?: ($resource['simple_booking'] ?? null);",
     ["has/simple_booking only", "hosp/create active, own resource is simple_booking only",
      "res/unset->unset, simple_booking 0->1 only", "remote/add a simple_booking-only resource"]),
    ("M13", "a FUTURE direct_booking start date does not count",
     "        return !is_numeric($value) || (int)$value !== 0;",
     "        return !is_numeric($value) || ((int)$value !== 0 && (int)$value < time());",
     ["has/FUTURE start date counts", "res/unset->set(FUTURE)", "hosp/create active, own resource has FUTURE",
      "remote/re-activate a dead row, FUTURE"]),
    ("M14", "the empty string counts as a set direct_booking",
     "        if ($value === null || $value === '' || $value === false) {",
     "        if ($value === null || $value === false) {",
     ["has/empty string", "res/before \"\" (cleared) -> set"]),
    # ── F1: the hospitality `active` flag ──────────────────────────────────────────────────────
    ("M15", "active: create reads the flag with a boolean parse again (the F1 defect: \"01\" is skipped)",
     "'active' => self::normaliseActive($body['active'] ?? 1) ?? 1,",
     "'active' => filter_var($body['active'] ?? 1, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,",
     ["active/create guard \"01\"", "active/create guard \"+1\"", "active/create guard \"0001\""]),
    ("M16", "active: update reads the flag with a boolean parse again (re-activating with \"01\" slips through)",
     "(self::normaliseActive($body['active']) ?? 1),",
     "(filter_var($body['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0),",
     ["active/update guard \"01\"", "active/update guard \"+1\"", "active/update guard \"0001\""]),
    ("M17", "active: create fails OPEN on a value that is not a flag",
     "'active' => self::normaliseActive($body['active'] ?? 1) ?? 1,",
     "'active' => self::normaliseActive($body['active'] ?? 1) ?? 0,",
     ["active/create guard \"abc\"", "active/create guard \"01\""]),
    ("M18", "active: update fails OPEN on a value that is not a flag",
     "(self::normaliseActive($body['active']) ?? 1),",
     "(self::normaliseActive($body['active']) ?? 0),",
     ["active/update guard \"abc\"", "active/update guard \"01\""]),
    ("M19", "active: update always overwrites the stored value (an absent key is read as OFF)",
     "($body['active'] ?? null) === null ? (int)$existing['active'] : ",
     "false ? (int)$existing['active'] : ",
     ["active/update: no active key on an INACTIVE hospitality"]),
    ("M20", "active: a loose == compare reads \"01\", \"+1\", \" 1\" and \"0001\" as 1",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || $value === 1 || $value == '1') {",
     ["active/norm \"01\"", "active/norm \"+1\"", "active/norm \"0001\"", "active/norm \" 1\""]),
    ("M21", "active: the words true / on / yes are accepted as ON",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || $value === 1 || $value === '1' || in_array($value, ['true', 'on', 'yes'], true)) {",
     ["active/norm \"true\"", "active/norm \"on\"", "active/norm \"yes\""]),
    ("M22", "active: a value that is not a flag is read as OFF (a typo would de-activate)",
     "        if ($value === false || $value === 0 || $value === '0') {\n            return 0;\n        }\n        return null;",
     "        if ($value === false || $value === 0 || $value === '0') {\n            return 0;\n        }\n        return 0;",
     ["active/norm \"abc\"", "active/norm \"\"", "active/controller hands the repository \"abc\""]),
    ("M23", "active: any positive int is ON by truthiness (2 would be ON, and Postgres would store 2)",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || (is_int($value) && $value > 0) || $value === '1') {",
     ["active/norm int 2", "active/never stored as 2 int 2"]),
    ("M24", "active: a boolean false is not a flag",
     "if ($value === false || $value === 0 || $value === '0') {",
     "if ($value === 0 || $value === '0') {",
     ["active/norm false"]),
    ("M25", "active: a float 1.0 (json \"1.0\") is accepted as ON",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || $value === 1 || $value === 1.0 || $value === '1') {",
     ["active/norm float 1.0"]),
    ("M26", "controller: the RAW value is passed through to the repository (the normalised one is not written back)",
     "\t\t$body['active'] = $active;\n\t\treturn true;",
     "\t\treturn true;",
     ["active/controller hands the repository true", "active/controller hands the repository \"1\"",
      "active/controller hands the repository \"0\"", "active/controller hands the repository false"],
     "controller"),
    ("M27", "controller: a value that is not a flag is accepted and handed on as it came",
     "\t\tif ($active === null) {\n\t\t\treturn false;\n\t\t}",
     "\t\tif ($active === null) {\n\t\t\treturn true;\n\t\t}",
     ["active/controller hands the repository \"01\"", "active/controller hands the repository \"abc\""],
     "controller"),
    ("M28", "controller: store() no longer reads active at all",
     "\t\t\tif (!$this->normaliseActive($body)) {\n\t\t\t\treturn $this->refuseInvalidActive($response);\n\t\t\t}\n\n\t\t\t// A resource with direct booking",
     "\t\t\t// A resource with direct booking",
     ["active/source order: store() reads active before the guard", "active/source order: store() reads active before the repository"],
     "controller"),
    ("M29", "controller: update() no longer reads active at all",
     "\t\t\tif (!$this->normaliseActive($body)) {\n\t\t\t\treturn $this->refuseInvalidActive($response);\n\t\t\t}\n\n\t\t\t// Moving the hospitality",
     "\t\t\t// Moving the hospitality",
     ["active/source order: update() reads active before the guard", "active/source order: update() reads active before the repository"],
     "controller"),
    ("M30", "controller: an ABSENT active is always set (a plain rename of a hospitality would switch it off)",
     "\t\tif (($body['active'] ?? null) === null) {\n\t\t\tunset($body['active']);\n\t\t\treturn true;\n\t\t}",
     "\t\tif (($body['active'] ?? null) === null) {\n\t\t\t$body['active'] = 0;\n\t\t\treturn true;\n\t\t}",
     ["active/controller: an ABSENT active stays absent", "active/controller: a null active is dropped"],
     "controller"),
    ("M31", "controller: a null active is kept in the body (update would write NULL)",
     "\t\t\tunset($body['active']);\n",
     "",
     ["active/controller: a null active is dropped"],
     "controller"),
]


def run(guard_file, controller_file=CONTROLLER):
    env = dict(os.environ)
    env["GUARD_FILE"] = guard_file
    env["CONTROLLER_FILE"] = controller_file
    p = subprocess.run([PHP, os.path.join(HERE, "run.php")], env=env, capture_output=True, text=True)
    return p.returncode, p.stdout + p.stderr


def main():
    tmp = tempfile.mkdtemp(prefix="hdbg-mut-")
    bad = 0
    try:
        with open(GUARD, encoding="utf-8") as fh:
            original = fh.read()
        with open(CONTROLLER, encoding="utf-8") as fh:
            original_controller = fh.read()

        base = os.path.join(tmp, "baseline.php")
        with open(base, "w", encoding="utf-8") as fh:
            fh.write(original)
        base_controller = os.path.join(tmp, "baselineController.php")
        with open(base_controller, "w", encoding="utf-8") as fh:
            fh.write(original_controller)
        rc, out = run(base, base_controller)
        print(f"baseline rc={rc}  {out.strip().splitlines()[-1]}")
        if rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1

        for mid, desc, old, new, must_fail, *target in MUTANTS:
            is_controller = target == ["controller"]
            source = original_controller if is_controller else original
            count = source.count(old)
            if count != 1:
                print(f"{mid:4} NOT APPLIED ({count} matches) - {desc}")
                bad += 1
                continue
            path = os.path.join(tmp, f"{mid}.php")
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(source.replace(old, new))
            lint = subprocess.run([PHP, "-l", path], capture_output=True, text=True)
            if lint.returncode != 0:
                print(f"{mid:4} MUTANT DOES NOT PARSE - {desc}: {lint.stdout.strip()}")
                bad += 1
                continue
            rc, out = run(base, path) if is_controller else run(path, base_controller)
            failed = [l for l in out.splitlines() if l.startswith("FAIL")]
            missing = [m for m in must_fail if not any(m in l for l in failed)]
            if rc != 1:
                print(f"{mid:4} SURVIVED (rc={rc}) - {desc}")
                bad += 1
            elif missing:
                print(f"{mid:4} killed, but NOT on the intended row(s) {missing} - {desc}")
                bad += 1
            else:
                print(f"{mid:4} killed on {len(failed)} row(s), incl. the intended ones - {desc}")
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    print("ALL MUTANTS KILLED ON THEIR OWN ROW" if bad == 0 else f"{bad} MUTANT PROBLEM(S)")
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
