#!/usr/bin/env python3
"""
Mutation check for the POST /partials harness (run.php).

One deliberate defect at a time, applied to a TEMP COPY of the files the harness loads (never to the tree), and
run.php must go red for each. The baseline on the same copy must be green first, and every replacement must match
exactly once. A defect counts as caught only when the harness exits 1 with a FAIL line; any other exit (a crash, a
fatal error, a run that does not finish within MUTATE_TIMEOUT seconds) is a CRASH and fails this run.

EQUIVALENT rows are changes that keep the property (an id that cannot reach the update branch, an order the
repository will not write), and the harness must stay GREEN on them: they show it judges the branch the repository
really takes, not the spelling of the fix. E1 and M10 make the same controller change; only the repository's
condition differs, and only M10 must be red.

Usage:  python3 tests/partials-post-id/mutate.py
        (in the pe-api container: docker exec -w /var/www/html pe-api python3 tests/partials-post-id/mutate.py)
Env (optional): PP_ROOT = tree to copy from (default: this repo). PP_AUTOLOAD = composer autoload.php (default:
<PP_ROOT>/vendor/autoload.php, then <this repo>/vendor/autoload.php). PHP_BIN, MUTATE_TIMEOUT (seconds, default 60).
"""
import os
import re
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
REPOSITORY = "src/modules/bookingfrontend/repositories/ApplicationRepository.php"
ARTICLES = "src/modules/bookingfrontend/repositories/ArticleRepository.php"

# Everything run.php loads or reads from PP_ROOT (it prints the classes it loaded; a missing one is a FAIL there).
FILES = [
    C,
    REPOSITORY,
    ARTICLES,
    "src/modules/bookingfrontend/controllers/DocumentController.php",
    "src/modules/bookingfrontend/helpers/WebSocketHelper.php",
    "src/WebSocket/Services/RedisService.php",
    "src/helpers/ResponseHelper.php",
]

UNSET = "            unset($data['id'], $data['purchase_order']);\n"
UNSET_ORDER = "            unset($data['purchase_order']);\n"
SAVE = "            $id = $this->applicationService->savePartialApplication($data);\n"
BRANCH = "if (!empty($data['id']))"
ORDER_BRANCH = "if (!empty($data['purchase_order']['lines']))"
ARTICLES_INSERT = "INSERT INTO bb_purchase_order (application_id) VALUES (:application_id)"
ARTICLES_COMMENT = "            // Process new articles format if present\n"
# a sent id is nulled, a create without one stays without one (so the N rows see nothing added)
NULL_ID = "            if (array_key_exists('id', $data)) { $data['id'] = null; }\n" + UNSET_ORDER

R = re.compile  # a regex mutation: (pattern, replacement); the pattern must match exactly once


class Multi:
    """Several exact replacements in one file, applied in order; every one must match exactly once."""

    def __init__(self, *pairs):
        self.pairs = pairs


# (id, file, description, old, new, expected)   expected: "red" or "green"
MUTATIONS = [
    ("M1", C, "the body id is honoured (only the order is removed)", UNSET, UNSET_ORDER, "red"),
    ("M2", C, "the id and the order are removed only after the save", Multi((UNSET, ""), (SAVE, SAVE + UNSET)), None, "red"),
    ("M3", C, "only an integer id is removed",
     UNSET, "            if (is_int($data['id'] ?? null)) { unset($data['id']); }\n" + UNSET_ORDER, "red"),
    ("M4", C, "only a numeric id is removed",
     UNSET, "            if (is_numeric($data['id'] ?? null)) { unset($data['id']); }\n" + UNSET_ORDER, "red"),
    ("M5", C, "the id is cast to an integer instead of removed",
     UNSET, "            $data['id'] = (int)($data['id'] ?? 0);\n" + UNSET_ORDER, "red"),
    ("M6", C, "another key is removed instead of the id", UNSET, "            unset($data['ID'], $data['purchase_order']);\n", "red"),
    ("M7", C, "the server no longer sets session_id (the body's wins)",
     "            $data['session_id'] = $session_id;\n", "", "red"),
    ("M8", C, "the server no longer sets status (the body's wins)",
     "            $data['status'] = 'NEWPARTIAL1';\n", "", "red"),
    ("M9", REPOSITORY, "the repository's order `if` can never be true (the order detector must see it)",
     ORDER_BRANCH, "if (!empty($data['purchase_order']['lines']) && false)", "red"),
    ("M10", None, "the id is set to null, and the repository branches on the key being there",
     Multi((C, UNSET, NULL_ID), (REPOSITORY, BRANCH, "if (array_key_exists('id', $data))")), None, "red"),
    ("M11", C, "the body order is honoured (only the id is removed)", UNSET, "            unset($data['id']);\n", "red"),
    ("M12", C, "only the body order's customer_id and status are removed",
     UNSET, "            unset($data['id'], $data['purchase_order']['customer_id'], $data['purchase_order']['status']);\n", "red"),
    ("M13", ARTICLES, "the order the articles path makes takes a status and a customer",
     ARTICLES_INSERT, "INSERT INTO bb_purchase_order (application_id, status, customer_id) VALUES (:application_id, 0, 1)", "red"),
    ("M14", REPOSITORY, "savePartialApplication() reads the body order outside the cut `if`",
     ARTICLES_COMMENT, "            $data['customer_id'] = $data['purchase_order']['customer_id'] ?? null;\n" + ARTICLES_COMMENT, "red"),
    ("E1", C, "EQUIVALENT: a sent id is set to null instead of removed",
     UNSET, NULL_ID, "green"),
    ("E2", REPOSITORY, "EQUIVALENT: the repository branches on isset() instead of !empty()",
     BRANCH, "if (isset($data['id']))", "green"),
    ("E3", C, "EQUIVALENT: a sent order is set to null instead of removed",
     UNSET, "            unset($data['id']);\n"
            "            if (array_key_exists('purchase_order', $data)) { $data['purchase_order'] = null; }\n", "green"),
]


def replace_once(text, old, new, where):
    if isinstance(old, re.Pattern):
        result, count = old.subn(new, text)
    else:
        count = text.count(old)
        result = text.replace(old, new)
    if count != 1:
        raise SystemExit(f"MUTATION SETUP: {where}: expected exactly one match, found {count}")
    return result


def copy_tree(dest):
    for rel in FILES + ["tests/partials-post-id/run.php"]:
        src = os.path.join(ROOT if rel.startswith("src/") else REPO, rel)
        if not os.path.isfile(src):
            raise SystemExit(f"SETUP: {src} is missing")
        os.makedirs(os.path.dirname(os.path.join(dest, rel)), exist_ok=True)
        shutil.copyfile(src, os.path.join(dest, rel))


def apply(dest, mid, path, old, new):
    edits = []
    if path is None:  # Multi over several files: (file, old, new) triples
        edits = [(f, o, n) for f, o, n in old.pairs]
    elif isinstance(old, Multi):
        edits = [(path, o, n) for o, n in old.pairs]
    else:
        edits = [(path, old, new)]
    for i, (rel, o, n) in enumerate(edits):
        full = os.path.join(dest, rel)
        with open(full, encoding="utf-8") as fh:
            text = fh.read()
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(replace_once(text, o, n, f"{mid} edit {i + 1} in {rel}"))


def run(dest):
    env = dict(os.environ, PP_ROOT=dest, PP_AUTOLOAD=AUTOLOAD, XDEBUG_MODE="off")
    try:
        p = subprocess.run([PHP, os.path.join(dest, "tests/partials-post-id/run.php")], env=env,
                           capture_output=True, text=True, timeout=TIMEOUT)
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
        for mid, path, desc, old, new, expected in MUTATIONS:
            dest = os.path.join(base, mid)
            shutil.copytree(clean, dest)
            apply(dest, mid, path, old, new)
            verdict, detail = run(dest)
            ok = verdict == expected
            problems += 0 if ok else 1
            print(f"{'ok ' if ok else 'BAD'} {mid:<4} {expected} expected, got {verdict}  {desc}  [{detail}]")
    caught = sum(1 for m in MUTATIONS if m[5] == "red")
    print(f"{len(MUTATIONS)} mutations ({caught} must be red, {len(MUTATIONS) - caught} equivalent), {problems} not as expected")
    return 0 if problems == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
