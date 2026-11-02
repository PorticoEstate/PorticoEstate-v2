#!/usr/bin/env python3
"""
Mutation check for the catering deadline parity harness (GH #1423).

A green harness proves nothing unless a BROKEN implementation turns it red. This applies one
deliberate defect at a time to a TEMP COPY of the PHP calculator, the PHP guard or the TypeScript
mirror (never to the tree), runs the harness against the copy, and requires that:

  * the unmutated baseline passes on both sides, and
  * every mutation makes the side it breaks FAIL (exit 1), while the side it did not touch still
    passes (so a failure is attributable to the mutation, not to the environment).

Every replacement must match EXACTLY ONCE, otherwise the mutation did not apply and the script
stops: a mutation that silently changed nothing would "survive" for the wrong reason.

Usage:  python3 tests/hospitality-deadline/mutate.py          (PHP_BIN / NODE_BIN override binaries)
Exit:   0 every mutation was caught, 1 a mutation SURVIVED or the baseline is red.
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
CALC = os.path.join(ROOT, "src/modules/booking/services/HospitalityDeadlineCalculator.php")
GUARD = os.path.join(ROOT, "src/modules/booking/services/HospitalityOrderGuard.php")
TS = os.path.join(ROOT, "src/modules/bookingfrontend/client/src/utils/hospitality-deadline.ts")

PHP = os.environ.get("PHP_BIN", "php")
NODE = os.environ.get("NODE_BIN", "node")

# (id, file key, expected failing side, description, old, new)
MUTATIONS = [
    ("M1p", "calc", "php", "PHP: 'open' becomes now < deadline (exactly AT the deadline closes)",
     "return $deadline === null || $now <= $deadline;", "return $deadline === null || $now < $deadline;"),
    ("M1t", "ts", "ts", "TS: 'open' becomes now < deadline",
     "return t <= deadline.getTime();", "return t < deadline.getTime();"),
    ("M2p", "calc", "php", "PHP: 'weeks' is not a unit again (the old server gap)",
     "if ($unit !== 'hours' && $unit !== 'days' && $unit !== 'weeks') {", "if ($unit !== 'hours' && $unit !== 'days') {"),
    ("M2t", "ts", "ts", "TS: weeks as 7*24 h of milliseconds (the old client behaviour)",
     "if (u === 'weeks') return subtractCalendarDays(eventTime, value * 7);",
     "if (u === 'weeks') return new Date(eventTime.getTime() - value * 7 * 86400000);"),
    ("M3p", "calc", "php", "PHP: the open-days mask is ignored",
     "$openDays = self::normalizeMask($openDays);\n        $holidaySet", "$openDays = self::ALL_DAYS_OPEN;\n        $holidaySet"),
    ("M3t", "ts", "ts", "TS: the open-days list is ignored",
     "    const open = openDaySet(openDaysList);\n    const holidaySet", "    const open = new Set(ALL_OPEN_DAYS);\n    const holidaySet"),
    ("M4t", "ts", "ts", "TS: plain days as milliseconds (breaks across DST)",
     "            : subtractCalendarDays(eventTime, value);", "            : new Date(eventTime.getTime() - value * 86400000);"),
    ("M6p", "calc", "php", "PHP: working hours step with modify('-1 hour') again (sticks at the DST gap)",
     "$prev = $cursor->setTimestamp($cursor->getTimestamp() - 3600);", "$prev = $cursor->modify('-1 hour');"),
    ("M5p", "guard", "php", "PHP: change checks only the order cutoff, not the cancellation deadline",
     "case self::ACTION_CHANGE:\n                $closers[] = [$cancelBy, self::REASON_CANCEL_DEADLINE];\n", "case self::ACTION_CHANGE:\n"),
    ("M5t", "ts", "ts", "TS: change checks only the order cutoff",
     "closers.push([cancelBy, 'cancellation_deadline_passed'], [cutoff, 'order_cutoff_passed']);", "closers.push([cutoff, 'order_cutoff_passed']);"),
    ("M8p", "guard", "php", "PHP: the serving time is no outer bound",
     "$closers[] = [$anchor, self::REASON_SERVING_PASSED];", ""),
    ("M8t", "ts", "ts", "TS: the serving time is no outer bound",
     "closers.push([anchor, 'serving_time_passed']);", ""),
    ("M9p", "guard", "php", "PHP: a tie between X and C is reported as the order cutoff",
     "$closers[] = [$cancelBy, self::REASON_CANCEL_DEADLINE];\n                $closers[] = [$cutoff, self::REASON_ORDER_CUTOFF];",
     "$closers[] = [$cutoff, self::REASON_ORDER_CUTOFF];\n                $closers[] = [$cancelBy, self::REASON_CANCEL_DEADLINE];"),
    ("M9t", "ts", "ts", "TS: a tie between X and C is reported as the order cutoff",
     "closers.push([cancelBy, 'cancellation_deadline_passed'], [cutoff, 'order_cutoff_passed']);",
     "closers.push([cutoff, 'order_cutoff_passed'], [cancelBy, 'cancellation_deadline_passed']);"),
    ("M10p", "guard", "php", "PHP: a moved serving time is not checked against its own cutoff",
     "if (!HospitalityDeadlineCalculator::isOpenAt($now, $newCutoff)) {", "if (false) {"),
    ("M10t", "ts", "ts", "TS: a moved serving time is not checked against its own cutoff",
     "if (!isDeadlineOpen(now, newCutoff)) {", "if (false) {"),
    ("M11p", "guard", "php", "PHP: a draft change is locked by the cancellation deadline",
     "if ($isDraft && $action === self::ACTION_CHANGE) {", "if (false) {"),
    ("M12p", "guard", "php", "PHP: a draft cannot be removed from the cart",
     "if ($action === self::ACTION_CANCEL && $isDraft) {", "if (false) {"),
    ("M13p", "guard", "php", "PHP: a billed order can still be changed",
     "if (!empty($ctx['billed'])) {", "if (false) {"),
    ("M14p", "guard", "php", "PHP: a paid-at-checkout order can still be changed",
     "if (!empty($ctx['paid_at_checkout'])) {", "if (false) {"),
    ("M15p", "guard", "php", "PHP: a REJECTED application is not closed",
     "if (!in_array($status, self::OPEN_APPLICATION_STATUSES, true)) {", "if (false) {"),
]


def run(php_files, ts_file):
    """Run both sides against the given files; returns (php_rc, ts_rc, php_tail, ts_tail)."""
    env = dict(os.environ)
    env["CALC_FILE"], env["GUARD_FILE"] = php_files
    p = subprocess.run([PHP, os.path.join(HERE, "run.php")], env=env, capture_output=True, text=True)
    env = dict(os.environ)
    env["TZ"] = "Europe/Oslo"
    env["DEADLINE_TS"] = ts_file
    t = subprocess.run([NODE, os.path.join(HERE, "run.mts")], env=env, capture_output=True, text=True)
    last = lambda s: (s.strip().splitlines() or [""])[-1]
    return p.returncode, t.returncode, last(p.stdout + p.stderr), last(t.stdout + t.stderr)


def main():
    tmp = tempfile.mkdtemp(prefix="hd-mut-")
    try:
        originals = {"calc": CALC, "guard": GUARD, "ts": TS}
        copies = {}
        for key, src in originals.items():
            dst = os.path.join(tmp, "orig_" + os.path.basename(src))
            shutil.copyfile(src, dst)
            copies[key] = dst

        php_rc, ts_rc, php_tail, ts_tail = run((copies["calc"], copies["guard"]), copies["ts"])
        print(f"baseline  php rc={php_rc}  {php_tail}\n          ts  rc={ts_rc}  {ts_tail}")
        if php_rc != 0 or ts_rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1

        survived = []
        for mid, key, side, desc, old, new in MUTATIONS:
            text = open(originals[key], encoding="utf-8").read()
            n = text.count(old)
            if n != 1:
                print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                return 1
            mutated = os.path.join(tmp, f"{mid}_" + os.path.basename(originals[key]))
            open(mutated, "w", encoding="utf-8").write(text.replace(old, new, 1))

            files = dict(copies)
            files[key] = mutated
            php_rc, ts_rc, php_tail, ts_tail = run((files["calc"], files["guard"]), files["ts"])

            broken_rc, other_rc = (php_rc, ts_rc) if side == "php" else (ts_rc, php_rc)
            caught = broken_rc == 1 and other_rc == 0
            status = "caught  " if caught else "SURVIVED"
            print(f"{mid:5} {status} php rc={php_rc} ts rc={ts_rc}  {desc}")
            if not caught:
                survived.append(mid)

        if survived:
            print("SURVIVING MUTATIONS:", ", ".join(survived))
            return 1
        print(f"all {len(MUTATIONS)} mutations caught; baseline green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
