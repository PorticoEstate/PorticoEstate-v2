#!/usr/bin/env python3
"""
Mutation check for the hidden price set stop harness (run.php).

One deliberate defect at a time, applied to a TEMP COPY of the files the harness loads (never to the tree), and
run.php must go red for each. The baseline on the same copy must be green first, and every replacement must match
exactly once. A defect counts as caught only when the harness exits 1 with a FAIL line; any other exit (a crash, a
fatal error, a run that does not finish within MUTATE_TIMEOUT seconds) is a CRASH and fails this run.

EQUIVALENT rows are changes that keep the property, and the harness must stay GREEN on them.

Usage:  python3 tests/hidden-price-set-stop/mutate.py
        (in the pe-api container: docker exec -w /var/www/html pe-api python3 tests/hidden-price-set-stop/mutate.py)
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

R = "src/modules/bookingfrontend/repositories/ArticleRepository.php"
S = "src/modules/bookingfrontend/services/applications/ApplicationService.php"
C = "src/modules/bookingfrontend/controllers/applications/CheckoutController.php"
HARNESS = "tests/hidden-price-set-stop/run.php"

# Everything run.php loads from PP_ROOT.
FILES = [
    R,
    S,
    C,
    "src/modules/bookingfrontend/helpers/PriceSetException.php",
    "src/modules/bookingfrontend/helpers/PriceChoiceException.php",
    "src/helpers/ResponseHelper.php",
]

CHECKOUT_STOP = (
    "                // submit stops there (409), before anything is submitted\n"
    "                $this->applicationService->stopIfPriceSetBeforeSubmit($session_id);\n"
)
VIPPS_STOP = (
    "                // first, and the payment stops there (409), before anything is read for it\n"
    "                $this->applicationService->stopIfPriceSetBeforeSubmit($session_id);\n"
)
HIDDEN_GUARD = (
    "        if (!$this->articleRepository->hidesPriceChoice()) {\n"
    "            return;\n"
    "        }\n"
    "\n"
)
TX_GUARD = (
    "        if ($this->db->inTransaction()) {\n"
    "            throw new \\LogicException('The price set before submit must be stored outside the submit\\'s transaction');\n"
    "        }\n"
    "\n"
)
PRICING_GUARD = (
    "        if (!$this->hidesPriceChoice()) {\n"
    "            return [];\n"
    "        }\n"
)
VIPPS_CATCH = (
    "            } catch (PriceSetException $e) {\n"
    "                // A price was set just now; the applicant sees it before paying\n"
    "                return ResponseHelper::sendErrorResponse($e->toResponseBody(), 409);\n"
)

MUTATIONS = [
    # id, description, file, old, new, expected
    ("M1", "the stop priced a line but does not throw", S,
     "        throw new PriceSetException(array_unique($priced));\n", "", "red"),
    ("M2", "checkout without the stop", C, CHECKOUT_STOP, "", "red"),
    ("M3", "the Vipps route without the stop", C, VIPPS_STOP, "", "red"),
    ("M4", "a line priced at 0 kr is counted (the 409 would repeat on every submit)", R,
     "            if ((float)$amount != 0.0) {\n", "            if (true) {\n", "red"),
    ("M5", "the stop does not check the category is hidden", S, HIDDEN_GUARD, "", "red"),
    ("M6", "the stop runs inside a transaction (its price would be rolled back)", S, TX_GUARD, "", "red"),
    ("M7", "checkout answers the stop with 200", C,
     "                // A price was set just now; the applicant sees it before submitting again\n"
     "                return ResponseHelper::sendErrorResponse($e->toResponseBody(), 409);\n",
     "                // A price was set just now; the applicant sees it before submitting again\n"
     "                return ResponseHelper::sendErrorResponse($e->toResponseBody(), 200);\n", "red"),
    ("M8", "the Vipps route does not catch the stop", C, VIPPS_CATCH, "            } catch (\\LogicException $e) {\n                throw $e;\n", "red"),
    ("M9", "the hidden pricing also prices while shown", R, PRICING_GUARD, "", "red"),
    ("M10", "no cart push when a line is priced", S,
     "        WebSocketHelper::triggerPartialApplicationsUpdate($session_id);\n", "", "red"),
    ("M11", "an application is named once per line, not once", S,
     "        throw new PriceSetException(array_unique($priced));\n",
     "        throw new PriceSetException($priced);\n", "red"),
    ("E1", "prices are never negative: != 0.0 read as > 0.0", R,
     "            if ((float)$amount != 0.0) {\n", "            if ((float)$amount > 0.0) {\n", "green"),
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
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"SETUP: {mid}: the text to replace occurs {count} times in {path}, expected once:\n{old}")
    with open(full, "w", encoding="utf-8") as fh:
        fh.write(text.replace(old, new))


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
    with tempfile.TemporaryDirectory(prefix="hpss-mutate-") as base:
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
