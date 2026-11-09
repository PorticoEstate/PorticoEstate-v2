#!/usr/bin/env python3
"""
Mutation check for the checkout parent application harness (run.php).

One deliberate defect at a time, applied to a TEMP COPY of the files the harness loads (never to the tree), and
run.php must go red for each. The baseline on the same copy must be green first, and every replacement must match
exactly once. A defect counts as caught only when the harness exits 1 with a FAIL line; any other exit (a crash, a
fatal error, a run that does not finish within MUTATE_TIMEOUT seconds) is a CRASH and fails this run.

EQUIVALENT rows are changes that keep the property, and the harness must stay GREEN on them: they show it judges
what is written and answered, not the spelling of the check.

Usage:  python3 tests/checkout-parent-strict/mutate.py
        (in the pe-api container: docker exec -w /var/www/html pe-api python3 tests/checkout-parent-strict/mutate.py)
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

S = "src/modules/bookingfrontend/services/applications/ApplicationService.php"
C = "src/modules/bookingfrontend/controllers/applications/CheckoutController.php"
HARNESS = "tests/checkout-parent-strict/run.php"

# Everything run.php loads from PP_ROOT.
FILES = [
    S,
    C,
    "src/modules/bookingfrontend/models/Application.php",
    "src/modules/booking/models/Application.php",
    "src/traits/SerializableTrait.php",
    "src/modules/bookingfrontend/helpers/ParentApplicationChoiceException.php",
    "src/modules/bookingfrontend/helpers/PriceChoiceException.php",
    "src/helpers/ResponseHelper.php",
]

LOOKUP = (
    "            $parentId = self::applicationIdOrNull($parentId);\n"
    "            $parentApplication = null;\n"
    "            if ($parentId !== null) {\n"
    "                foreach ($applications as $app) {\n"
    "                    if ((int)$app['id'] === $parentId) {\n"
)
NOT_FOUND = (
    "            if (!$parentApplication) {\n"
    "                throw new ParentApplicationChoiceException('Parent application not found');\n"
    "            }\n"
)
BUILDING = "            if ($parentApplication['building_id'] != $buildingId) {\n"
DIGITS = "if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}\\z/', $value) === 1) {"
RANGE = "return is_int($value) && $value >= 1 && $value <= 2147483647 ? $value : null;"
APPLY = "                $buildingParentIds = $this->validateBuildingParentIdConstraints($updatedApplications, $buildingParentIds);\n"
EARLY = "                $this->validateBuildingParentIdConstraints($applications, $buildingParentIds);\n"
GUARD = (
    "        if (isset($data['building_parent_ids']) && !is_array($data['building_parent_ids'])) {\n"
    "            throw new ParentApplicationChoiceException('Parent application not found');\n"
    "        }\n"
)
LEGACY_MATCH = "if ($legacyParentId !== null && (int)$app['id'] === $legacyParentId && empty($app['recurring_info'])) {\n"
LEGACY_SET = "                        $buildingParentIds[$app['building_id']] = $legacyParentId;\n"
CATCH = (
    "            } catch (ParentApplicationChoiceException $e) {\n"
    "                // The choice of parent application is refused; nothing was changed\n"
    "                return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 400);\n"
)


class Multi:
    """Several exact replacements in one file, applied in order; every one must match exactly once."""

    def __init__(self, *pairs):
        self.pairs = pairs


def legacy(loop):
    """The legacy parent_id match in one of the two copies, compared loosely and storing the value as sent."""
    head = f"                foreach ({loop} as $app) {{\n                    "
    return Multi(
        (head + LEGACY_MATCH + LEGACY_SET,
         head + "if ($app['id'] == $data['parent_id'] && empty($app['recurring_info'])) {\n"
         + "                        $buildingParentIds[$app['building_id']] = $data['parent_id'];\n"),
    )


# (id, description, file, old, new, expected)   expected: "red" or "green"
MUTATIONS = [
    ("M1", "the parent is looked up as before: the value as sent, compared with ==", S,
     LOOKUP,
     "            $parentApplication = null;\n"
     "            if (true) {\n"
     "                foreach ($applications as $app) {\n"
     "                    if ($app['id'] == $parentId) {\n", "red"),
    ("M2", "the value is not read as an id at all, but compared with ===", S,
     LOOKUP,
     "            $parentApplication = null;\n"
     "            if (true) {\n"
     "                foreach ($applications as $app) {\n"
     "                    if ($app['id'] === $parentId) {\n", "red"),
    ("M3", "the value as sent is written, not the checked id", S, APPLY,
     "                $this->validateBuildingParentIdConstraints($updatedApplications, $buildingParentIds);\n", "red"),
    ("M4", "a parent that is not one of the applications is let through", S,
     Multi((NOT_FOUND, NOT_FOUND.replace("if (!$parentApplication) {", "if (!$parentApplication && $parentId === null) {")),
           (BUILDING, BUILDING.replace("if ($parentApplication[", "if ($parentApplication && $parentApplication["))),
     None, "red"),
    ("M5", "the building check is dropped", S, BUILDING, "            if (false) {\n", "red"),
    ("M6", "a string is read as an id when is_numeric()", S, DIGITS, "if (is_numeric($value)) {", "red"),
    ("M7", "the digit pattern ends with $ instead of \\z", S, DIGITS, DIGITS.replace("\\z/", "$/"), "red"),
    ("M8", "a leading zero is let into the digit pattern", S, DIGITS, DIGITS.replace("[1-9][0-9]{0,9}", "[0-9]{1,10}"), "red"),
    ("M9", "a float is read as an id", S, RANGE,
     "if (is_float($value) && $value == (int)$value) {\n            $value = (int)$value;\n        }\n\n        " + RANGE, "red"),
    ("M10", "the legacy key matches loosely and keeps the value sent (contact-info copy)", S,
     legacy("$applications"), None, "red"),
    ("M11", "the legacy key matches loosely and keeps the value sent (checkout copy)", S,
     legacy("$updatedApplications"), None, "red"),
    ("M12", "a building_parent_ids that is not a map is not refused up front", S, GUARD, "", "red"),
    ("M13", "the refusal is a plain Exception", S, NOT_FOUND,
     NOT_FOUND.replace("new ParentApplicationChoiceException(", "new Exception("), "red"),
    ("M14", "the refusal repeats the value sent", S, NOT_FOUND,
     NOT_FOUND.replace("'Parent application not found'", "'Parent application ' . json_encode($buildingParentIds[$buildingId]) . ' not found'"), "red"),
    ("M15", "the controller does not answer the refusal itself (it falls to the generic 500)", C, CATCH, "", "red"),
    ("M16", "the application's id is compared without the int cast (ids handed out as strings no longer match)", S,
     LOOKUP, LOOKUP.replace("if ((int)$app['id'] === $parentId) {", "if ($app['id'] === $parentId) {"), "red"),
    # equivalent: the property holds, the harness must stay green
    ("E1", "the id is compared with == once it is an int", S,
     LOOKUP, LOOKUP.replace("if ((int)$app['id'] === $parentId) {", "if ($app['id'] == $parentId) {"), "green"),
    ("E2", "no upper bound on the id (no application of the session is beyond the column)", S,
     RANGE, "return is_int($value) && $value >= 1 ? $value : null;", "green"),
    ("E3", "no early check in the contact-info step (the later one refuses inside the same transaction)", S,
     EARLY, "", "green"),
    ("E4", "preg_match() taken as truthy instead of === 1", S, DIGITS, DIGITS.replace(") === 1) {", ")) {"), "green"),
]


def copy_tree(dest):
    for rel in FILES + [HARNESS]:
        src = os.path.join(ROOT if rel != HARNESS else REPO, rel)
        if not os.path.isfile(src):
            raise SystemExit(f"SETUP: {src} is missing")
        os.makedirs(os.path.dirname(os.path.join(dest, rel)), exist_ok=True)
        shutil.copy2(src, os.path.join(dest, rel))


def apply(dest, mid, path, old, new):
    full = os.path.join(dest, path)
    with open(full, encoding="utf-8") as fh:
        text = fh.read()
    pairs = old.pairs if isinstance(old, Multi) else ((old, new),)
    for before, after in pairs:
        count = text.count(before)
        if count != 1:
            raise SystemExit(f"SETUP: {mid}: the text to replace occurs {count} times in {path}, expected once:\n{before}")
        text = text.replace(before, after)
    with open(full, "w", encoding="utf-8") as fh:
        fh.write(text)


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
    with tempfile.TemporaryDirectory(prefix="cps-mutate-") as base:
        clean = os.path.join(base, "clean")
        copy_tree(clean)
        verdict, detail = run(clean)
        print(f"{'ok ' if verdict == 'green' else 'BAD'} baseline  green expected, got {verdict}  [{detail}]")
        if verdict != "green":
            return 1
        for mid, desc, path, old, new, expected in MUTATIONS:
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
