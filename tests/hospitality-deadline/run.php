<?php

/**
 * PHP side of the catering deadline parity harness (GH #1423).
 *
 *   php tests/hospitality-deadline/run.php
 *
 * Reads cases.json (produced by gen_cases.py from an INDEPENDENT oracle) and runs it through
 * HospitalityDeadlineCalculator and HospitalityOrderGuard. The twin of run.mts, which runs the
 * deadline/action cases through the TypeScript mirror.
 *
 * Plain PHP on purpose: the pe-api image is built --no-dev, so PHPUnit does not exist there.
 *
 * CALC_FILE / GUARD_FILE (optional) point at alternative copies of the two classes — used by
 * mutate.py to prove that a deliberately broken implementation turns this harness red.
 * CASES_FILE overrides the case file.
 *
 * Exit code: 0 all pass, 1 any failure. Instants are compared as epoch seconds.
 */

date_default_timezone_set('Europe/Oslo');

$root = dirname(__DIR__, 2);
require_once getenv('CALC_FILE') ?: $root . '/src/modules/booking/services/HospitalityDeadlineCalculator.php';
require_once getenv('GUARD_FILE') ?: $root . '/src/modules/booking/services/HospitalityOrderGuard.php';

use App\modules\booking\services\HospitalityDeadlineCalculator;
use App\modules\booking\services\HospitalityOrderGuard;

$casesFile = getenv('CASES_FILE') ?: __DIR__ . '/cases.json';
$cases = json_decode((string)file_get_contents($casesFile), true);
if (!is_array($cases)) {
    fwrite(STDERR, "cannot read cases: {$casesFile}\n");
    exit(2);
}

$oslo = new DateTimeZone('Europe/Oslo');
$parse = static fn(?string $iso): ?DateTimeImmutable =>
    $iso === null ? null : (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Oslo'));
$ts = static fn(?DateTimeInterface $d): ?int => $d?->getTimestamp();

$failures = [];
$counts = ['deadlines' => 0, 'actions' => 0, 'locks' => 0];

$fail = static function (string $section, array $case, string $detail) use (&$failures): void {
    $failures[] = "[{$section}] {$case['name']}: {$detail}";
};

// --- deadlines ------------------------------------------------------------------------------
$calc = new HospitalityDeadlineCalculator();
foreach ($cases['deadlines'] as $c) {
    $counts['deadlines']++;
    $got = $ts($calc->computeDeadline($parse($c['event']), $c['value'], $c['unit'], $c['open_days']));
    if ($got !== $c['expected']) {
        $fail('deadlines', $c, 'got ' . var_export($got, true) . ' want ' . var_export($c['expected'], true)
            . " (event {$c['event']} {$c['value']} {$c['unit']} mask {$c['open_days']})");
    }
}

// The guard calls HospitalityDeadlineCalculator::isOpenAt. Against an older calculator (the
// baseline run on unmodified code) report that as a failure per case instead of crashing, so the
// baseline is a measurement and not a stack trace.
$guardRunnable = method_exists(HospitalityDeadlineCalculator::class, 'isOpenAt');
$guard = new HospitalityOrderGuard($calc);

// --- actions (deadline part of the guard; locks are neutral: ACCEPTED, pending, not billed) ---
foreach ($cases['actions'] as $c) {
    $counts['actions']++;
    if (!$guardRunnable) {
        $fail('actions', $c, 'the calculator has no isOpenAt(), so the guard cannot run');
        continue;
    }
    $ctx = [
        'application_status' => 'ACCEPTED',
        'order_status' => $c['action'] === 'add' ? null : 'pending',
        'billed' => false,
        'paid_at_checkout' => false,
        'anchor' => $parse($c['anchor']),
        'moved_to' => $parse($c['moved_to']),
        'order_by_value' => $c['order_by']['value'],
        'order_by_unit' => $c['order_by']['unit'],
        'cancel_value' => $c['cancel_by']['value'],
        'cancel_unit' => $c['cancel_by']['unit'],
        'open_days' => $c['open_days'],
    ];
    $r = $guard->evaluate($c['action'], $ctx, $parse($c['now']));
    $got = ['allowed' => $r['allowed'], 'reason' => $r['reason'], 'until' => $ts($r['until'])];
    if ($got !== $c['expected']) {
        $fail('actions', $c, 'got ' . json_encode($got) . ' want ' . json_encode($c['expected']));
    }
}

// --- locks (PHP only: the client never evaluates these) -----------------------------------------
foreach ($cases['locks'] as $c) {
    $counts['locks']++;
    if (!$guardRunnable) {
        $fail('locks', $c, 'the calculator has no isOpenAt(), so the guard cannot run');
        continue;
    }
    $ctx = [
        'application_status' => $c['application_status'],
        'order_status' => $c['order_status'],
        'billed' => $c['billed'],
        'paid_at_checkout' => $c['paid_at_checkout'],
        'anchor' => $parse($c['anchor']),
        'moved_to' => $parse($c['moved_to']),
        'order_by_value' => $c['order_by']['value'],
        'order_by_unit' => $c['order_by']['unit'],
        'cancel_value' => $c['cancel_by']['value'],
        'cancel_unit' => $c['cancel_by']['unit'],
        'open_days' => $c['open_days'],
    ];
    $r = $guard->evaluate($c['action'], $ctx, $parse($c['now']));
    $got = ['allowed' => $r['allowed'], 'reason' => $r['reason']];
    if ($got !== $c['expected']) {
        $fail('locks', $c, 'got ' . json_encode($got) . ' want ' . json_encode($c['expected']));
    }
}

$total = array_sum($counts);
if ($failures) {
    foreach (array_slice($failures, 0, 25) as $line) {
        echo $line, "\n";
    }
    if (count($failures) > 25) {
        echo '... and ', count($failures) - 25, " more\n";
    }
    echo 'PHP harness: FAIL ', count($failures), " of {$total} cases (", json_encode($counts), ")\n";
    exit(1);
}
echo "PHP harness: PASS {$total} cases (", json_encode($counts), ") on PHP ", PHP_VERSION, "\n";
