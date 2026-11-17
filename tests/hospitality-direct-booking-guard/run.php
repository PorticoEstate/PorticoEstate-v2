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

// ── hospitality `resource_id`: ONE reading, used for the guard AND the repository ────────────
// The same class of gap as F1, for the other column the guard judges. The guard read resource_id with (int)
// while the repository bound the raw value, and Postgres 16+ casts text to integer more liberally than
// PHP: "0x5D", "0o135", "0b1011101" and "9_3" are 93 there (resource 93 has direct booking) while PHP reads
// 0, 0, 0 and 9. Postgres 15 rejects them, so the laptop cannot show it; a throwaway postgres:18 can.
// The rule: a plain decimal integer in 1..2147483647 (an int, or a string of ASCII digits only), else 422.
//
// Columns: label | raw value as a request body can carry it | norm = G::normaliseResourceId (an int, or null
// = not a resource id, the controller answers 422) | pg15 / pg16 = what Postgres 15 / Postgres 16-and-later
// put in the integer column when PDO binds the raw value as text (an int, 'error' = rejected and nothing
// stored, null = never bound as a value). Re-derivable with --pg-oracle-resource-id on each version.
$resourceIdCases = [
    // accepted: a plain decimal integer in range
    ['int 93', 93, 93, 93, 93],
    ['"93"', '93', 93, 93, 93],
    ['"093" (PHP and Postgres agree)', '093', 93, 93, 93],
    ['"0093"', '0093', 93, 93, 93],
    ['"0000000000000000093" (19 chars, 2 significant)', '0000000000000000093', 93, 93, 93],
    ['int 1', 1, 1, 1, 1],
    ['"1"', '1', 1, 1, 1],
    ['int 2147483647 (the column maximum)', 2147483647, 2147483647, 2147483647, 2147483647],
    ['"2147483647"', '2147483647', 2147483647, 2147483647, 2147483647],
    ['"0002147483647"', '0002147483647', 2147483647, 2147483647, 2147483647],
    // THE DIVERGENCE: Postgres 16+ stores 93, Postgres 15 rejects, PHP (int) reads 0 or 9 -> refused
    ['"0x5D" (PHP reads 0)', '0x5D', null, 'error', 93],
    ['"0X5d" (PHP reads 0)', '0X5d', null, 'error', 93],
    ['"9_3" (PHP reads 9)', '9_3', null, 'error', 93],
    ['"0o135" (PHP reads 0)', '0o135', null, 'error', 93],
    ['"0b1011101" (PHP reads 0)', '0b1011101', null, 'error', 93],
    // every version stores 93 and (int) agrees, but it is not spelled as a plain integer: refused, not reinterpreted
    ['"+93"', '+93', null, 93, 93],
    ['" 93"', ' 93', null, 93, 93],
    ['"93 "', '93 ', null, 93, 93],
    ['"93\n"', "93\n", null, 93, 93],
    ['"\t93"', "\t93", null, 93, 93],
    ['float 93.0 (json "93.0": PDO binds "93")', 93.0, null, 93, 93],
    ['true (PDO binds "1": resource 1)', true, null, 1, 1],
    // not a resource id: zero, negative, out of range, not a number
    ['int 0', 0, null, 0, 0],
    ['"0"', '0', null, 0, 0],
    ['"00"', '00', null, 0, 0],
    ['"+0"', '+0', null, 0, 0],
    ['"-0"', '-0', null, 0, 0],
    ['int -1', -1, null, -1, -1],
    ['"-1"', '-1', null, -1, -1],
    ['"-93"', '-93', null, -93, -93],
    ['int 2147483648 (one past the column)', 2147483648, null, 'error', 'error'],
    ['"2147483648"', '2147483648', null, 'error', 'error'],
    ['int PHP_INT_MAX', PHP_INT_MAX, null, 'error', 'error'],
    ['"99999999999999999999"', '99999999999999999999', null, 'error', 'error'],
    ['""', '', null, 'error', 'error'],
    ['"   "', '   ', null, 'error', 'error'],
    ['"abc"', 'abc', null, 'error', 'error'],
    ['"93abc"', '93abc', null, 'error', 'error'],
    ['"9 3"', '9 3', null, 'error', 'error'],
    ['"9,3"', '9,3', null, 'error', 'error'],
    ['"1e2" (PHP (int) reads 100)', '1e2', null, 'error', 'error'],
    ['"93.0"', '93.0', null, 'error', 'error'],
    ['"0x"', '0x', null, 'error', 'error'],
    ['"٩٣" (Arabic-Indic digits)', "\u{0669}\u{0663}", null, 'error', 'error'],
    ['"９３" (fullwidth digits)', "\u{FF19}\u{FF13}", null, 'error', 'error'],
    ['float 93.5', 93.5, null, 'error', 'error'],
    ['false (PDO binds "")', false, null, 'error', 'error'],
    ['INF (json 1e999)', INF, null, 'error', 'error'],
    ['null', null, null, null, null],
    ['[]', [], null, null, null],
    ['["93"]', ['93'], null, null, null],
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

// --pg-oracle-resource-id: the same idea for `resource_id`, but the answer depends on the Postgres VERSION, so the
// block reads server_version_num and holds the row to the column of the table that is meant for it.
//   php tests/hospitality-direct-booking-guard/run.php --pg-oracle-resource-id | docker exec -i <container> psql -U postgres -v ON_ERROR_STOP=1 -f -
// Run it on the laptop's portico_postgres (15) AND on a throwaway postgres:18; a green PG 15 run alone proves little,
// because 15 rejects the very strings where the gap is. Touches no table.
if (in_array('--pg-oracle-resource-id', $argv ?? [], true)) {
    $quote = static fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
    $values = [];
    foreach ($resourceIdCases as [$label, $raw, $norm, $pg15, $pg16]) {
        if ($pg15 === null && $pg16 === null) {
            continue; // never reaches Postgres as a value (null, an array)
        }
        $bound = is_bool($raw) ? ($raw ? '1' : '') : (string)$raw; // what PDO::execute([...]) sends
        // `norm` is what the guard that is LOADED right now reads (GUARD_FILE may point at a broken copy, which is how
        // this oracle is shown to go red); `tnorm` is what the table above declares it should read.
        $read = G::normaliseResourceId($raw);
        $int = static fn(?int $n): string => $n === null ? 'NULL::bigint' : $n . '::bigint'; // bigint: a broken reading must WARN below, not overflow here
        $values[] = sprintf(
            "(%s, convert_from(decode('%s', 'hex'), 'UTF8'), %s, %s, %s, %s)",
            $quote($label), bin2hex($bound), $int($read), $int($norm), $quote((string)$pg15), $quote((string)$pg16)
        );
    }
    echo "DO \$oracle\$\nDECLARE c record; v int; got text; want text; bad int := 0; n int := 0;\n",
        "  major int := current_setting('server_version_num')::int / 10000;\nBEGIN\n",
        "  FOR c IN SELECT * FROM (VALUES\n    ", implode(",\n    ", $values), "\n  ) AS t(label, bound, norm, tnorm, pg15, pg16) LOOP\n",
        "    n := n + 1;\n",
        "    want := CASE WHEN major >= 16 THEN c.pg16 ELSE c.pg15 END;\n",
        "    BEGIN v := c.bound::integer; got := v::text;\n",
        "    EXCEPTION WHEN invalid_text_representation OR numeric_value_out_of_range THEN v := NULL; got := 'error';\n",
        "    END;\n",
        // the code reads what the table declares (otherwise the two halves of the proof, rows and oracle, would not meet)
        "    IF c.norm IS DISTINCT FROM c.tnorm THEN RAISE WARNING '% : the code reads %, the table declares %', c.label, coalesce(c.norm::text, 'null'), coalesce(c.tnorm::text, 'null'); bad := bad + 1; END IF;\n",
        // the table is right for THIS server (so the other checks mean something)
        "    IF got IS DISTINCT FROM want THEN RAISE WARNING '% : the table says % on PG %, Postgres gave %', c.label, want, major, got; bad := bad + 1; END IF;\n",
        // the point: a value that IS read is the very id Postgres stores
        "    IF c.norm IS NOT NULL AND v IS NOT NULL AND c.norm <> v THEN RAISE WARNING '% : Postgres % stores %, but it is read as %', c.label, major, v, c.norm; bad := bad + 1; END IF;\n",
        // and a value that is read must be one Postgres accepts at all, as a positive id
        "    IF c.norm IS NOT NULL AND (v IS NULL OR v < 1) THEN RAISE WARNING '% : read as %, but Postgres % does not store a resource id for it (%)', c.label, c.norm, major, got; bad := bad + 1; END IF;\n",
        "    RAISE NOTICE '% | PG %: % | read as: %', rpad(c.label, 48), major, rpad(got, 11), coalesce(c.norm::text, 'null (422)');\n",
        "  END LOOP;\n",
        "  IF bad > 0 THEN RAISE EXCEPTION '% disagreement(s) between Postgres % and the code', bad, major; END IF;\n",
        "  RAISE NOTICE 'ORACLE OK (PG %): % raw values, none is read as a different resource than Postgres would store', major, n;\n",
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

// ── resource_id rows ────────────────────────────────────────────────────────────────────────
// The guard judges the resource Postgres will store, so each row below asks the same thing of one case from the
// table: what the one reading says, what the controller hands the repository, what the guard does with it, and
// that no accepted value is read as another resource than any Postgres version stores.
$guardOutcome = static function (callable $call) use ($decision): string {
    try {
        return $decision($call);
    } catch (InvalidArgumentException $e) {
        return 'threw'; // the guard refuses to judge a value that is not a resource id (the controller never lets one through)
    }
};
$normaliseResourceBody = new ReflectionMethod(HospitalityController::class, 'normaliseResourceId');
$resourceBodyAfter = static function (array $body) use ($controller, $normaliseResourceBody): array {
    $ok = $normaliseResourceBody->invokeArgs($controller, [&$body]);
    return [$ok, $body];
};
$storedActive = ['id' => 1, 'active' => 1, 'resource_id' => 11]; // an active hospitality an update moves

foreach ($resourceIdCases as [$label, $raw, $norm, $pg15, $pg16]) {
    $expect("resid/norm {$label}", G::normaliseResourceId($raw), $norm);
    // refused = [false, body unchanged]; accepted = [true, body with the int the repository then binds]
    $expect("resid/controller hands the repository {$label}", $resourceBodyAfter(['resource_id' => $raw, 'name' => 'x']),
        $norm === null ? [false, ['resource_id' => $raw, 'name' => 'x']] : [true, ['resource_id' => $norm, 'name' => 'x']]);
    // a resource id goes on to be judged; anything else is not judged at all (fail closed: the guard throws)
    $expect("resid/create guard {$label}", $guardOutcome(fn() => $guard->checkHospitalityCreate(['resource_id' => $raw, 'name' => 'x'])), $norm === null ? 'threw' : 'checked');
    $expect("resid/update guard {$label}", $guardOutcome(fn() => $guard->checkHospitalityUpdate($storedActive, ['resource_id' => $raw])), $norm === null ? 'threw' : 'checked');
    foreach ([['15', $pg15], ['16+', $pg16]] as [$version, $pg]) {
        // the point: when Postgres stores an id, a value that IS read is that same id ...
        if (is_int($pg) && $norm !== null) {
            $expect("resid/read as the id Postgres {$version} stores {$label}", $norm === $pg, true);
        }
        // ... and a value Postgres rejects, or stores as 0, a negative or past the column, is never read as an id
        if ($pg === 'error' || (is_int($pg) && ($pg < 1 || $pg > G::MAX_RESOURCE_ID))) {
            $expect("resid/never an id on Postgres {$version} {$label}", G::normaliseResourceId($raw), null);
        }
    }
    // PHP's (int) is NOT the reading: where Postgres 16+ stores an id and (int) reads another one, the value is refused
    if (is_string($raw) && is_int($pg16) && (int)$raw !== $pg16) {
        $expect("resid/(int) would read another resource than Postgres 16+ stores, so refused {$label}", G::normaliseResourceId($raw), null);
    }
}
$expect('resid/an ABSENT resource_id stays absent (a rename must not touch the resource)', $resourceBodyAfter(['name' => 'x']), [true, ['name' => 'x']]);
$expect('resid/a null resource_id is refused, not treated as absent (the column is NOT NULL)', $resourceBodyAfter(['resource_id' => null, 'name' => 'x']), [false, ['resource_id' => null, 'name' => 'x']]);
$expect('resid/update: no resource_id key on an ACTIVE hospitality: the stored resource is still judged',
    $guardOutcome(fn() => $guard->checkHospitalityUpdate($storedActive, ['name' => 'x'])), 'checked');
$expect('resid/update: an INACTIVE hospitality moved to a resource has no active link, nothing to check',
    $guardOutcome(fn() => $guard->checkHospitalityUpdate($inactive, ['resource_id' => 11])), 'skipped');
$expect('resid/update: moved AND re-activated, the new resource is judged',
    $guardOutcome(fn() => $guard->checkHospitalityUpdate($inactive, ['resource_id' => 11, 'active' => 1])), 'checked');
$expect('resid/create: no resource_id key at all is not judged against resource 0 (fail closed)',
    $guardOutcome(fn() => $guard->checkHospitalityCreate(['name' => 'x'])), 'threw');
$expect('resid/the column maximum is the largest 4-byte integer', G::MAX_RESOURCE_ID, 2147483647);

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
    // the same for resource_id, and the existence check: a positive id that is no resource must be a 422, not the foreign key's 500
    $expect("resid/source order: {$method}() reads resource_id before the guard", $firstBefore($src, '$this->normaliseResourceId($body)', "directBookingGuard->{$guardCall}("), true);
    $expect("resid/source order: {$method}() reads resource_id before the repository", $firstBefore($src, '$this->normaliseResourceId($body)', $repoCall), true);
    $expect("resid/source order: {$method}() checks the resource exists before the guard", $firstBefore($src, 'repository->resourceExists(', "directBookingGuard->{$guardCall}("), true);
    $expect("resid/source order: {$method}() checks the resource exists after reading it", $firstBefore($src, '$this->normaliseResourceId($body)', 'repository->resourceExists('), true);
}
// The two rulings of the operator about the main resource (Q1 open orders at the old main, Q2 the new main is a delivery
// location): each lives in ONE method of the controller, runs after the direct-booking guard and before the write,
// and "open" is defined once, in the repository, from the order guard's own list of final statuses.
$updateSource = $methodSource('update');
$expect('q/source order: update() asks the Q2 method after the direct-booking guard', $firstBefore($updateSource, 'directBookingGuard->checkHospitalityUpdate(', '$this->mainIsRemoteLocationRefusal('), true);
$expect('q/source order: update() asks the Q1 method after the Q2 method', $firstBefore($updateSource, '$this->mainIsRemoteLocationRefusal(', '$this->openOrdersAtMainRefusal('), true);
$expect('q/source order: update() asks the Q1 method before the repository writes', $firstBefore($updateSource, '$this->openOrdersAtMainRefusal(', 'repository->update('), true);
$expect('q/only an actual move of the main resource asks them (both calls sit inside `if ($mainResourceChanged)`)',
    preg_match('/if \(\$mainResourceChanged\) \{[^}]*\$this->mainIsRemoteLocationRefusal\([^}]*\$this->openOrdersAtMainRefusal\(/s', $updateSource), 1);
$expect('q/a move is told apart from the key arriving unchanged (the whole row may be sent back)',
    str_contains($updateSource, "\$body['resource_id'] !== (int)\$existing['resource_id']"), true);
$expect('q/the broadcast tells other admins to rebuild the Resources tab when the main moved', str_contains($updateSource, "\$mainResourceChanged ? 'main_resource' : 'details'"), true);
$controllerSource = file_get_contents(getenv('CONTROLLER_FILE') ?: $root . '/src/modules/booking/controllers/HospitalityController.php');
$expect('q/"open" is not spelled in the controller (one definition, in the order repository)', str_contains($controllerSource, 'serving_time_iso') || str_contains($controllerSource, "'cancelled'"), false);
$expect('q/the Q1 method counts through the one definition', str_contains($controllerSource, 'orderRepository->countOpenAtLocation('), true);
$orderRepositorySource = file_get_contents(getenv('ORDER_REPOSITORY_FILE') ?: $root . '/src/modules/booking/repositories/HospitalityOrderRepository.php');
$openFn = substr($orderRepositorySource, (int)strpos($orderRepositorySource, 'function countOpenAtLocation'), 1400);
$expect('q/the definition of "open" reads the final statuses from the order guard, not a second list', str_contains($openFn, 'HospitalityOrderGuard::FINAL_ORDER_STATUSES') && !str_contains($openFn, "'cancelled'"), true);
$expect('q/"open" counts an order with NO serving time as open (the safe direction)', str_contains($openFn, 'serving_time_iso IS NULL OR'), true);
$expect('q/"open" compares with the current time as naive UTC, not the session zone', str_contains($openFn, "now() AT TIME ZONE 'UTC'"), true);

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
