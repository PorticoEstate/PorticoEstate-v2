<?php

/**
 * Rule-table harness for HospitalityDirectBookingGuard (direct booking and hospitality exclude each other).
 *
 *   php tests/hospitality-direct-booking-guard/run.php
 *
 * Runs the PURE rule (no database, no framework, no lang()) over a table of rows:
 *   transition  x  own / remote link  x  active / inactive  x  past / future direct_booking
 *   x  simple_booking-only  x  already-in-conflict-stays-in-conflict.
 * Every row states the expected answer in plain values, not derived from the code under test.
 *
 * Plain PHP on purpose: the pe-api image is built --no-dev, so PHPUnit does not exist there.
 *
 * It also covers the hospitality `active` flag (F1 of the Opus final): the one reading of it, what the
 * guard then decides on it (create and update), and what the controller hands the repository.
 *
 * GUARD_FILE and CONTROLLER_FILE (optional) point at alternative copies of the guard and of
 * HospitalityController — used by mutate.py to prove that each deliberately broken copy turns THIS
 * harness red on the row that is meant to catch it.
 *
 *   php tests/hospitality-direct-booking-guard/run.php --pg-oracle | docker exec -i portico_postgres \
 *       psql -U postgres -d portico_ebe -v ON_ERROR_STOP=1 -f -
 * prints the `active` table as a self-checking SQL block: Postgres casts every raw value to smallint
 * (what PDO would store) and the block fails when that disagrees with the normalised value or with the
 * `pg` column below. Touches no table.
 *
 * Exit code: 0 all pass, 1 any failure. Last line of output is the verdict.
 */

$root = dirname(__DIR__, 2);
require_once getenv('GUARD_FILE') ?: $root . '/src/modules/booking/services/HospitalityDirectBookingGuard.php';
require_once getenv('CONTROLLER_FILE') ?: $root . '/src/modules/booking/controllers/HospitalityController.php';

use App\modules\booking\controllers\HospitalityController;
use App\modules\booking\services\HospitalityDirectBookingGuard as G;

const PAST = 1745413200;       // 2025-04-23, resource 93's real direct_booking
const FUTURE = 4102444800;     // 2100-01-01: still in the future for the life of this harness

$rows = [];
$expect = static function (string $name, $actual, $expected) use (&$rows): void {
    $rows[] = [$name, $actual, $expected];
};

// Helpers that only build plain input arrays.
$res = static fn($direct, $simple = null): array => ['direct_booking' => $direct, 'simple_booking' => $simple];
$own = static fn(int $h, int $hActive = 1, string $name = ''): array =>
    ['hospitality_id' => $h, 'name' => $name ?: "H{$h}", 'hospitality_active' => $hActive, 'kind' => 'own', 'link_active' => 1];
$remote = static fn(int $h, int $hActive = 1, int $rowActive = 1, string $name = ''): array =>
    ['hospitality_id' => $h, 'name' => $name ?: "H{$h}", 'hospitality_active' => $hActive, 'kind' => 'remote', 'link_active' => $rowActive];
$ids = static fn(array $links): array => array_column($links, 'hospitality_id');
$resourceSide = static fn(array $before, array $after, array $links): array =>
    $ids(G::hospitalitiesBlockingResourceSave($before, $after, $links));

// ── hasDirectBooking: what "set" means ───────────────────────────────────────────────────────
$expect('has/null', G::hasDirectBooking($res(null)), false);
$expect('has/empty string (legacy form cleared)', G::hasDirectBooking($res('')), false);
$expect('has/false', G::hasDirectBooking($res(false)), false);
$expect('has/int 0', G::hasDirectBooking($res(0)), false);
$expect('has/string "0"', G::hasDirectBooking($res('0')), false);
$expect('has/past timestamp', G::hasDirectBooking($res(PAST)), true);
$expect('has/past timestamp as string', G::hasDirectBooking($res((string)PAST)), true);
$expect('has/FUTURE start date counts', G::hasDirectBooking($res(FUTURE)), true);
$expect('has/simple_booking only is NOT direct booking', G::hasDirectBooking($res(null, 1)), false);
$expect('has/simple_booking only, direct 0', G::hasDirectBooking($res(0, 1)), false);

// ── effectiveLinks: active hospitality AND (own OR active remote row) ───────────────────────
$expect('links/own active', $ids(G::effectiveLinks([$own(1)])), [1]);
$expect('links/remote active', $ids(G::effectiveLinks([$remote(1)])), [1]);
$expect('links/own on an INACTIVE hospitality', $ids(G::effectiveLinks([$own(1, 0)])), []);
$expect('links/remote on an INACTIVE hospitality', $ids(G::effectiveLinks([$remote(1, 0)])), []);
$expect('links/INACTIVE remote row on an active hospitality', $ids(G::effectiveLinks([$remote(1, 1, 0)])), []);
$expect('links/own and remote of ONE hospitality = one entry', $ids(G::effectiveLinks([$own(1), $remote(1)])), [1]);
$expect('links/two hospitalities', $ids(G::effectiveLinks([$remote(2), $own(1)])), [2, 1]);
$expect('links/active own + dead remote still one link', $ids(G::effectiveLinks([$own(1), $remote(1, 1, 0)])), [1]);
$expect('links/names are carried', G::effectiveLinks([$own(7, 1, 'Kantina')]), [['hospitality_id' => 7, 'name' => 'Kantina']]);

// ── RESOURCE side: refuse only the TRANSITION unset -> set while linked ──────────────────────
$expect('res/unset->set(past), own active link', $resourceSide($res(null), $res(PAST), [$own(1)]), [1]);
$expect('res/unset->set(past), remote active link', $resourceSide($res(null), $res(PAST), [$remote(1)]), [1]);
$expect('res/unset->set(FUTURE), remote active link', $resourceSide($res(null), $res(FUTURE), [$remote(1)]), [1]);
$expect('res/unset->set, own link on INACTIVE hospitality', $resourceSide($res(null), $res(PAST), [$own(1, 0)]), []);
$expect('res/unset->set, remote link on INACTIVE hospitality', $resourceSide($res(null), $res(PAST), [$remote(1, 0)]), []);
$expect('res/unset->set, INACTIVE remote row', $resourceSide($res(null), $res(PAST), [$remote(1, 1, 0)]), []);
$expect('res/unset->set, no link at all (control: clean save works)', $resourceSide($res(null), $res(PAST), []), []);
$expect('res/set->set(date moved), linked: existing conflict stays saveable', $resourceSide($res(PAST), $res(FUTURE), [$remote(1)]), []);
$expect('res/set->set unchanged, linked: unrelated edit of an existing conflict', $resourceSide($res(PAST), $res(PAST), [$remote(1)]), []);
$expect('res/set->unset (turn it off), linked: the remedy', $resourceSide($res(PAST), $res(null), [$remote(1)]), []);
$expect('res/unset->unset, simple_booking 0->1 only, linked', $resourceSide($res(null, 0), $res(null, 1), [$remote(1), $own(2)]), []);
$expect('res/simple_booking resource gets direct booking while linked', $resourceSide($res(null, 1), $res(PAST, 1), [$remote(1)]), [1]);
$expect('res/before "" (cleared) -> set, linked', $resourceSide($res(''), $res(PAST), [$remote(1)]), [1]);
$expect('res/before 0 -> set, linked', $resourceSide($res(0), $res(PAST), [$remote(1)]), [1]);
$expect('res/before null -> "" , linked', $resourceSide($res(null), $res(''), [$remote(1)]), []);
$expect('res/before null -> 0, linked', $resourceSide($res(null), $res(0), [$own(1)]), []);
$expect('res/two hospitalities both named', $resourceSide($res(null), $res(PAST), [$own(1), $remote(2)]), [1, 2]);
$expect('res/one hospitality linked twice is named once', $resourceSide($res(null), $res(PAST), [$own(1), $remote(1)]), [1]);

// ── LINK side, hospitality create / update ──────────────────────────────────────────────────
$byId = [10 => $res(PAST), 11 => $res(null), 12 => $res(null, 1), 20 => $res(FUTURE), 30 => $res(null), 31 => $res(PAST)];
$hospSide = static fn(?array $before, array $after, array $remoteIds = []): array =>
    G::resourcesBlockingHospitalitySave($before, $after, $remoteIds, $byId);
$h = static fn(int $active, int $resourceId): array => ['active' => $active, 'resource_id' => $resourceId];

$expect('hosp/create active, own resource has direct booking', $hospSide(null, $h(1, 10)), [10]);
$expect('hosp/create active, own resource has FUTURE direct booking', $hospSide(null, $h(1, 20)), [20]);
$expect('hosp/create active, own resource clean (control)', $hospSide(null, $h(1, 11)), []);
$expect('hosp/create active, own resource is simple_booking only', $hospSide(null, $h(1, 12)), []);
$expect('hosp/create INACTIVE on a direct-booking resource: no active link', $hospSide(null, $h(0, 10)), []);
$expect('hosp/update active->active, own unchanged: existing conflict stays saveable', $hospSide($h(1, 10), $h(1, 10)), []);
$expect('hosp/update active, own moved to a direct-booking resource', $hospSide($h(1, 11), $h(1, 20)), [20]);
$expect('hosp/update active, own moved to a clean resource (control)', $hospSide($h(1, 10), $h(1, 11)), []);
$expect('hosp/update re-activate, own clean, one remote resource has direct booking', $hospSide($h(0, 11), $h(1, 11), [30, 31]), [31]);
$expect('hosp/update re-activate, own has direct booking', $hospSide($h(0, 10), $h(1, 10), []), [10]);
$expect('hosp/update re-activate, own AND remote have direct booking (own first)', $hospSide($h(0, 10), $h(1, 10), [30, 31]), [10, 31]);
$expect('hosp/update re-activate, everything clean (control)', $hospSide($h(0, 11), $h(1, 11), [30]), []);
$expect('hosp/update active->inactive (the remedy for an existing conflict)', $hospSide($h(1, 10), $h(0, 10), [31]), []);
$expect('hosp/update active->inactive while own moves to a direct-booking resource', $hospSide($h(1, 11), $h(0, 20), [31]), []);
$expect('hosp/update inactive->inactive, own moved to a direct-booking resource', $hospSide($h(0, 11), $h(0, 20)), []);
$expect('hosp/update active->active: remote rows already effective are not re-checked', $hospSide($h(1, 11), $h(1, 11), [31]), []);
$expect('hosp/update re-activate, own also listed remote: named once', $hospSide($h(0, 10), $h(1, 10), [10]), [10]);
$expect('hosp/update re-activate AND move own to a direct-booking resource', $hospSide($h(0, 11), $h(1, 20), []), [20]);
$expect('hosp/unknown resource id is not refused', $hospSide(null, $h(1, 999)), []);

// ── LINK side, remote delivery location add / toggle ────────────────────────────────────────
$active = ['active' => 1];
$inactive = ['active' => 0];
$expect('remote/add (row absent) on a direct-booking resource', G::remoteLinkBlocked($active, false, true, $res(PAST)), true);
$expect('remote/re-activate a dead row, FUTURE direct booking', G::remoteLinkBlocked($active, false, true, $res(FUTURE)), true);
$expect('remote/add a clean resource (control)', G::remoteLinkBlocked($active, false, true, $res(null)), false);
$expect('remote/add a simple_booking-only resource', G::remoteLinkBlocked($active, false, true, $res(null, 1)), false);
$expect('remote/re-add an already-active row of an existing conflict: no-op', G::remoteLinkBlocked($active, true, true, $res(PAST)), false);
$expect('remote/deactivate an existing conflict: the remedy', G::remoteLinkBlocked($active, true, false, $res(PAST)), false);
$expect('remote/add to an INACTIVE hospitality: not an active link', G::remoteLinkBlocked($inactive, false, true, $res(PAST)), false);
$expect('remote/toggle a dead row to dead', G::remoteLinkBlocked($active, false, false, $res(PAST)), false);

// ── hospitality `active`: ONE reading, used for the guard AND the repository ─────────────────
// F1 of the Opus final: the guard read "01" / "+1" as OFF (a boolean parse) and skipped its check,
// while Postgres stores both as 1 in the smallint column, so a crafted request created or re-activated
// an ACTIVE hospitality on a direct-booking resource. The rule (pe-ceo 42623): accept ONLY true/false,
// 1/0 and "1"/"0"; everything else is refused with a 422, never reinterpreted. null / absent = today's
// default (create: 1; update: the column is left alone).
//
// Columns: label | raw value as a request body can carry it | norm = G::normaliseActive (1, 0, or null
// = not a flag, the controller answers 422) | pg = what Postgres puts in bb_hospitality.active when
// PDO binds the raw value (an int, 'error' = rejected, nothing stored, null = never bound), measured on
// PG 15.0 and re-derivable with --pg-oracle | guard = what checkHospitalityCreate/Update do with it:
// 'checked' = went on to read the resource (so it WOULD refuse a direct-booking one), 'skipped' = returned
// null without looking. A non-flag is checked (fail closed).
$activeCases = [
    // accepted: exactly these six
    ['int 1', 1, 1, 1, 'checked'],
    ['int 0', 0, 0, 0, 'skipped'],
    ['"1"', '1', 1, 1, 'checked'],
    ['"0"', '0', 0, 0, 'skipped'],
    ['true', true, 1, 1, 'checked'],
    ['false', false, 0, 'error', 'skipped'],
    // F1: Postgres stores these as 1 (the boolean parse said OFF) -> refused, not read
    ['"01"', '01', null, 1, 'checked'],
    ['"+1"', '+1', null, 1, 'checked'],
    ['" 1"', ' 1', null, 1, 'checked'],
    ['"1 "', '1 ', null, 1, 'checked'],
    ['"001"', '001', null, 1, 'checked'],
    ['"0001"', '0001', null, 1, 'checked'],
    ['"1\n"', "1\n", null, 1, 'checked'],
    // Postgres stores these as 0
    ['"00"', '00', null, 0, 'checked'],
    ['"+0"', '+0', null, 0, 'checked'],
    ['"-0"', '-0', null, 0, 'checked'],
    // words: the boolean parse read the first three as ON, Postgres rejects every one of them
    ['"true"', 'true', null, 'error', 'checked'],
    ['"TRUE"', 'TRUE', null, 'error', 'checked'],
    ['" Yes "', ' Yes ', null, 'error', 'checked'],
    ['"on"', 'on', null, 'error', 'checked'],
    ['"yes"', 'yes', null, 'error', 'checked'],
    ['"false"', 'false', null, 'error', 'checked'],
    ['"off"', 'off', null, 'error', 'checked'],
    ['"no"', 'no', null, 'error', 'checked'],
    // not a flag at all
    ['""', '', null, 'error', 'checked'],
    ['"   "', '   ', null, 'error', 'checked'],
    ['"abc"', 'abc', null, 'error', 'checked'],
    ['"1abc"', '1abc', null, 'error', 'checked'],
    ['"true1"', 'true1', null, 'error', 'checked'],
    ['int 2 (Postgres would store 2)', 2, null, 2, 'checked'],
    ['"2" (Postgres would store 2)', '2', null, 2, 'checked'],
    ['int -1 (Postgres would store -1)', -1, null, -1, 'checked'],
    ['"-1"', '-1', null, -1, 'checked'],
    ['"-01"', '-01', null, -1, 'checked'],
    ['float 1.0 (json "1.0")', 1.0, null, 1, 'checked'],
    ['float 0.0', 0.0, null, 0, 'checked'],
    ['float 0.5', 0.5, null, 'error', 'checked'],
    ['"1.0"', '1.0', null, 'error', 'checked'],
    ['"1e0"', '1e0', null, 'error', 'checked'],
    ['"0x1"', '0x1', null, 'error', 'checked'],
    ['INF (json 1e999)', INF, null, 'error', 'checked'], // NAN cannot come out of json_decode
    ['[]', [], null, null, 'checked'],
    ['["1"]', ['1'], null, null, 'checked'],
];

if (in_array('--pg-oracle', $argv ?? [], true)) {
    $quote = static fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
    $values = [];
    foreach ($activeCases as [$label, $raw, $norm, $pg]) {
        if ($pg === null) {
            continue; // never reaches Postgres as a value: an array is not bindable
        }
        $bound = is_bool($raw) ? ($raw ? '1' : '') : (string)$raw; // what PDO::execute([...]) sends
        $values[] = sprintf(
            "(%s, convert_from(decode('%s', 'hex'), 'UTF8'), %s, %s)",
            $quote($label), bin2hex($bound), $norm === null ? 'NULL::int' : $norm . '::int', $quote((string)$pg)
        );
    }
    echo "DO \$oracle\$\nDECLARE c record; v smallint; got text; bad int := 0; n int := 0;\nBEGIN\n",
        "  FOR c IN SELECT * FROM (VALUES\n    ", implode(",\n    ", $values), "\n  ) AS t(label, bound, norm, pg) LOOP\n",
        "    n := n + 1;\n",
        "    BEGIN v := c.bound::smallint; got := v::text;\n",
        "    EXCEPTION WHEN invalid_text_representation OR numeric_value_out_of_range THEN v := NULL; got := 'error';\n",
        "    END;\n",
        "    IF got IS DISTINCT FROM c.pg THEN RAISE WARNING '% : pg column says %, Postgres gave %', c.label, c.pg, got; bad := bad + 1; END IF;\n",
        // F1 itself: a value that is read (norm not null) must be the value Postgres would store
        "    IF c.norm IS NOT NULL AND v IS NOT NULL AND c.norm <> v THEN RAISE WARNING '% : Postgres stores %, but it is read as %', c.label, v, c.norm; bad := bad + 1; END IF;\n",
        // and a value Postgres would store as anything but 0/1 is never read as a flag
        "    IF c.norm IS NOT NULL AND v IS NOT NULL AND v NOT IN (0, 1) THEN RAISE WARNING '% : Postgres would store %, but it is read as a flag', c.label, v; bad := bad + 1; END IF;\n",
        "    RAISE NOTICE '% | postgres: % | normalised: %', rpad(c.label, 34), rpad(got, 6), coalesce(c.norm::text, 'null (422)');\n",
        "  END LOOP;\n",
        "  IF bad > 0 THEN RAISE EXCEPTION '% disagreement(s) between Postgres and the normalised value', bad; END IF;\n",
        "  RAISE NOTICE 'ORACLE OK: % raw values, none is read as a different flag than Postgres would store', n;\n",
        "END \$oracle\$;\n";
    exit(0);
}

// The guard instance has NO database: reaching a lookup throws the typed-property Error, which is how a
// row sees that the guard went on to check the resource ('checked'). Any other Error is a harness bug.
$guard = (new ReflectionClass(G::class))->newInstanceWithoutConstructor();
$decision = static function (callable $call): string {
    try {
        return $call() === null ? 'skipped' : 'refused';
    } catch (Error $e) {
        if (str_contains($e->getMessage(), 'must not be accessed before initialization') && str_contains($e->getMessage(), '::$db')) {
            return 'checked';
        }
        throw $e;
    }
};
// The controller step: HospitalityController::normaliseActive(&$body) reads `active` once and writes the
// 0|1 back, which is what the repository then binds. Private, so reached by reflection, no constructor.
$controller = (new ReflectionClass(HospitalityController::class))->newInstanceWithoutConstructor();
$normaliseBody = new ReflectionMethod(HospitalityController::class, 'normaliseActive');
$bodyAfter = static function (array $body) use ($controller, $normaliseBody): array {
    $ok = $normaliseBody->invokeArgs($controller, [&$body]);
    return [$ok, $body];
};
$inactive = ['id' => 1, 'active' => 0, 'resource_id' => 10]; // the stored hospitality an update re-activates (F1's H2)

foreach ($activeCases as [$label, $raw, $norm, $pg, $guardDecision]) {
    $expect("active/norm {$label}", G::normaliseActive($raw), $norm);
    $expect("active/create guard {$label}", $decision(fn() => $guard->checkHospitalityCreate(['resource_id' => 10, 'name' => 'x', 'active' => $raw])), $guardDecision);
    $expect("active/update guard {$label}", $decision(fn() => $guard->checkHospitalityUpdate($inactive, ['active' => $raw])), $guardDecision);
    // refused = [false, body unchanged]; accepted = [true, body with the int the repository binds]
    $expect("active/controller hands the repository {$label}", $bodyAfter(['active' => $raw, 'name' => 'x']),
        $norm === null ? [false, ['active' => $raw, 'name' => 'x']] : [true, ['active' => $norm, 'name' => 'x']]);
    // the point of F1: when Postgres would store a 0 or 1, a value that IS read is read as that same flag
    if ($pg === 0 || $pg === 1) {
        $expect("active/never read as another flag than Postgres stores {$label}", $norm === null || $norm === $pg, true);
    }
    // a value Postgres would store as anything but 0/1 is refused, never stored
    if (is_int($pg) && $pg !== 0 && $pg !== 1) {
        $expect("active/never stored as {$pg} {$label}", G::normaliseActive($raw), null);
    }
}

// null and absent: today's default (create 1, update leaves the column alone), NOT a refusal and NOT a 0
$expect('active/controller: an ABSENT active stays absent (a rename must not touch the flag)', $bodyAfter(['name' => 'x']), [true, ['name' => 'x']]);
$expect('active/controller: a null active is dropped, so create defaults and update leaves it alone', $bodyAfter(['active' => null, 'name' => 'x']), [true, ['name' => 'x']]);
$expect('active/create: no active key = the column default, ON, checked',
    $decision(fn() => $guard->checkHospitalityCreate(['resource_id' => 10, 'name' => 'x'])), 'checked');
$expect('active/create: null active = the column default, ON, checked',
    $decision(fn() => $guard->checkHospitalityCreate(['resource_id' => 10, 'name' => 'x', 'active' => null])), 'checked');
$expect('active/update: no active key on an INACTIVE hospitality stays inactive, nothing to check',
    $decision(fn() => $guard->checkHospitalityUpdate($inactive, ['name' => 'x'])), 'skipped');
$expect('active/update: null active on an INACTIVE hospitality stays inactive, nothing to check',
    $decision(fn() => $guard->checkHospitalityUpdate($inactive, ['name' => 'x', 'active' => null])), 'skipped');

// store() and update() need a request, an ACL and a database, so they cannot run here. What can be
// checked is the part F1 got wrong: that each of them reads `active` BEFORE the guard and BEFORE the
// repository (a source-order check, labelled as such; the live proof covers the behaviour).
$methodSource = static function (string $method): string {
    $m = new ReflectionMethod(HospitalityController::class, $method);
    return implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
};
$firstBefore = static fn(string $src, string $first, string $then): bool =>
    strpos($src, $first) !== false && strpos($src, $then) !== false && strpos($src, $first) < strpos($src, $then);
foreach ([['store', 'checkHospitalityCreate', 'repository->create('], ['update', 'checkHospitalityUpdate', 'repository->update(']] as [$method, $guardCall, $repoCall]) {
    $src = $methodSource($method);
    $expect("active/source order: {$method}() reads active before the guard", $firstBefore($src, '$this->normaliseActive($body)', "directBookingGuard->{$guardCall}("), true);
    $expect("active/source order: {$method}() reads active before the repository", $firstBefore($src, '$this->normaliseActive($body)', $repoCall), true);
}

// ── run ─────────────────────────────────────────────────────────────────────────────────────
$failures = [];
foreach ($rows as [$name, $actual, $expected]) {
    if ($actual !== $expected) {
        $failures[] = "FAIL [{$name}] got " . json_encode($actual) . ' want ' . json_encode($expected);
    }
}
foreach ($failures as $line) {
    echo $line, "\n";
}
$total = count($rows);
if ($failures) {
    echo 'RED: ' . count($failures) . " of {$total} rows failed\n";
    exit(1);
}
echo "GREEN: all {$total} rows pass\n";
exit(0);
