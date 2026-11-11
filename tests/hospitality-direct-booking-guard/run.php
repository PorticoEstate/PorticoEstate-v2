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
 * GUARD_FILE (optional) points at an alternative copy of the class — used by mutate.py to prove that
 * each deliberately broken copy turns THIS harness red on the row that is meant to catch it.
 *
 * Exit code: 0 all pass, 1 any failure. Last line of output is the verdict.
 */

$root = dirname(__DIR__, 2);
require_once getenv('GUARD_FILE') ?: $root . '/src/modules/booking/services/HospitalityDirectBookingGuard.php';

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
