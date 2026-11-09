#!/usr/bin/env python3
"""
Mutation check for the PATCH parent_id harness (run.php).

One deliberate defect at a time, applied to a TEMP COPY of the files the harness loads (never to the tree), and
run.php must go red for each. The baseline on the same copy must be green first, and every replacement must match
exactly once. A defect counts as caught only when the harness exits 1 with a FAIL line; any other exit (a crash, a
fatal error, a run that does not finish within MUTATE_TIMEOUT seconds) is a CRASH and fails this run.

EQUIVALENT rows are changes that keep the property, and the harness must stay GREEN on them: they show it judges
what reaches the service, not the spelling of the check.

Usage:  python3 tests/patch-parent-id/mutate.py
        (in the pe-api container: docker exec -w /var/www/html pe-api python3 tests/patch-parent-id/mutate.py)
Env (optional): PP_ROOT = tree to copy from (default: this repo). PP_AUTOLOAD = composer autoload.php (default:
<PP_ROOT>/vendor/autoload.php, then <this repo>/vendor/autoload.php). PHP_BIN, MUTATE_TIMEOUT (seconds, default 60).
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
ROOT = os.path.abspath(os.environ.get("PP_ROOT", REPO))
PHP = os.environ.get("PHP_BIN", "php")
TIMEOUT = int(os.environ.get("MUTATE_TIMEOUT", "60"))
AUTOLOAD = os.environ.get("PP_AUTOLOAD") or next(
    (p for p in (os.path.join(ROOT, "vendor", "autoload.php"), os.path.join(REPO, "vendor", "autoload.php")) if os.path.isfile(p)),
    "")

C = "src/modules/bookingfrontend/controllers/applications/ApplicationController.php"
HARNESS = "tests/patch-parent-id/run.php"

# Everything run.php loads from PP_ROOT.
FILES = [
    C,
    "src/modules/bookingfrontend/controllers/DocumentController.php",
    "src/modules/bookingfrontend/helpers/ApplicationHelper.php",
    "src/modules/bookingfrontend/helpers/WebSocketHelper.php",
    "src/WebSocket/Services/RedisService.php",
    "src/helpers/ResponseHelper.php",
]

GUARD = "            if (is_array($data) && array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {\n"
DIGITS = "preg_match('/^[1-9][0-9]{0,9}\\z/', $parentId)"
RANGE = "$parentId >= 1 && $parentId <= 2147483647"
ASK = "                if (!$parent || !$this->applicationHelper->canModifyApplication($parent, $request)) {\n"
REFUSE = (
    "                    return ResponseHelper::sendErrorResponse(\n"
    "                        ['error' => 'Link to parent application refused'],\n"
    "                        403\n"
    "                    );\n"
    "                }\n"
)
STORE = "                $data['parent_id'] = $parentId;\n"
BLOCK = (
    GUARD
    + "                $parentId = $data['parent_id'];\n"
    + "                if (is_string($parentId) && " + DIGITS + " === 1) {\n"
    + "                    $parentId = (int)$parentId;\n"
    + "                }\n"
    + "                $parent = is_int($parentId) && " + RANGE + "\n"
    + "                    ? $this->applicationService->getApplicationById($parentId)\n"
    + "                    : null;\n"
    + ASK + REFUSE + STORE
    + "            }\n"
)
SAVE = "            $this->applicationService->patchApplication($data);\n"


class Multi:
    """Several exact replacements in one file, applied in order; every one must match exactly once."""

    def __init__(self, *pairs):
        self.pairs = pairs


# (id, description, old, new, expected)   expected: "red" or "green"; every edit is in C
MUTATIONS = [
    ("M1", "no check: parent_id is passed on as sent", GUARD, "            if (false) {\n", "red"),
    ("M2", "the patched application is checked instead of the one parent_id names",
     ASK, ASK.replace("canModifyApplication($parent,", "canModifyApplication($application,"), "red"),
    ("M3", "canViewApplication() is asked instead of canModifyApplication()",
     ASK, ASK.replace("canModifyApplication", "canViewApplication"), "red"),
    ("M4", "only an integer parent_id is checked; a string passes as sent",
     GUARD, "            if (is_array($data) && array_key_exists('parent_id', $data) && is_int($data['parent_id'])) {\n", "red"),
    ("M5", "a refused parent_id is dropped and the rest applied (200) instead of refused",
     ASK + REFUSE + STORE,
     ASK
     + "                    unset($data['parent_id']);\n"
     + "                } else {\n"
     + "                    $data['parent_id'] = $parentId;\n"
     + "                }\n", "red"),
    ("M6", "the check runs after the save", Multi((BLOCK, ""), (SAVE, SAVE + BLOCK)), None, "red"),
    ("M7", "null is checked like an id, so clearing parent_id is refused",
     GUARD, "            if (is_array($data) && array_key_exists('parent_id', $data)) {\n", "red"),
    ("M8", "the value as sent is stored, not the id that was checked", STORE, "", "red"),
    ("M9", "an id that names no application is let through",
     ASK, ASK.replace("if (!$parent ||", "if ($parent &&"), "red"),
    ("M10", "no upper bound: an id beyond the integer column reaches the lookup", RANGE, "$parentId >= 1", "red"),
    ("M11", "the digits test ends with $, which allows a trailing newline",
     DIGITS, DIGITS.replace("\\z/", "$/"), "red"),
    ("M12", "spaces are trimmed before the digits test",
     DIGITS, "preg_match('/^[1-9][0-9]{0,9}\\z/', $parentId = trim($parentId))", "red"),
    ("M13", "an id that names no application gets its own answer (404)",
     ASK,
     "                if (!$parent) {\n"
     "                    return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);\n"
     "                }\n"
     + ASK, "red"),
    ("M14", "the refusal names the id it refused",
     "['error' => 'Link to parent application refused']",
     "['error' => 'Link to parent application ' . json_encode($data['parent_id']) . ' refused']", "red"),
    ("E1", "EQUIVALENT: isset() instead of array_key_exists() and !== null",
     GUARD, "            if (is_array($data) && isset($data['parent_id'])) {\n", "green"),
    ("E2", "EQUIVALENT: no lower bound; 0 and negative ids name no application", RANGE, "$parentId <= 2147483647", "green"),
    ("E3", "EQUIVALENT: another refusal text",
     "['error' => 'Link to parent application refused']", "['error' => 'Forbidden']", "green"),
]


def replace_once(text, old, new, where):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"MUTATION SETUP: {where}: expected exactly one match, found {count}")
    return text.replace(old, new)


def copy_tree(dest):
    for rel in FILES + [HARNESS]:
        src = os.path.join(ROOT if rel.startswith("src/") else REPO, rel)
        if not os.path.isfile(src):
            raise SystemExit(f"SETUP: {src} is missing")
        os.makedirs(os.path.dirname(os.path.join(dest, rel)), exist_ok=True)
        shutil.copyfile(src, os.path.join(dest, rel))


def apply(dest, mid, old, new):
    edits = list(old.pairs) if isinstance(old, Multi) else [(old, new)]
    full = os.path.join(dest, C)
    for i, (o, n) in enumerate(edits):
        with open(full, encoding="utf-8") as fh:
            text = fh.read()
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(replace_once(text, o, n, f"{mid} edit {i + 1}"))


def run(dest):
    env = dict(os.environ, PP_ROOT=dest, PP_AUTOLOAD=AUTOLOAD, XDEBUG_MODE="off")
    try:
        p = subprocess.run([PHP, os.path.join(dest, HARNESS)], env=env, capture_output=True, text=True, timeout=TIMEOUT)
    except subprocess.TimeoutExpired:
        return "CRASH", f"no result within {TIMEOUT}s"
    fails = [line for line in p.stdout.splitlines() if line.startswith("FAIL ")]
    summary = (p.stdout.strip().splitlines() or ["(no output)"])[-1]
    if p.returncode == 0 and not fails:
        return "green", summary
    if p.returncode == 1 and fails:
        return "red", f"{summary}; first: {fails[0][:150]}"
    return "CRASH", f"exit {p.returncode}: {(p.stderr or p.stdout).strip()[-300:]}"


def main():
    if not AUTOLOAD:
        raise SystemExit("SETUP: no composer autoload.php found; set PP_AUTOLOAD")
    problems = 0
    with tempfile.TemporaryDirectory(prefix="pp-mutate-") as base:
        clean = os.path.join(base, "clean")
        copy_tree(clean)
        verdict, detail = run(clean)
        print(f"{'ok ' if verdict == 'green' else 'BAD'} baseline  green expected, got {verdict}  [{detail}]")
        if verdict != "green":
            return 1
        for mid, desc, old, new, expected in MUTATIONS:
            dest = os.path.join(base, mid)
            shutil.copytree(clean, dest)
            apply(dest, mid, old, new)
            verdict, detail = run(dest)
            ok = verdict == expected
            problems += 0 if ok else 1
            print(f"{'ok ' if ok else 'BAD'} {mid:<4} {expected} expected, got {verdict}  {desc}  [{detail}]")
    caught = sum(1 for m in MUTATIONS if m[4] == "red")
    print(f"{len(MUTATIONS)} mutations ({caught} must be red, {len(MUTATIONS) - caught} equivalent), {problems} not as expected")
    return 0 if problems == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
