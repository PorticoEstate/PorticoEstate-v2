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
 * harness red on the row that is meant to catch it. MODEL_FILE does the same for the Hospitality model
 * (the 70-code-point description rule lives there) and ORDER_REPOSITORY_FILE for the order repository
 * (read as text only: the controller rows below answer Q1's count from a double).
 *
 * The last block drives store() and update() of the REAL controller, guard and model, end to end:
 *   - the offer description rule (at most 70 Unicode code points, f1998b27b),
 *   - the two main-resource rulings Q1 (open orders at the old main) and Q2 (the new main is an active
 *     delivery location), e929f56d8,
 *   - the order the refusals are asked in.
 * Doubles stand in ONLY for what reaches outside the process: the two repositories (no database; they
 * answer from an array and RECORD every write), the guard's database (an in-memory SQLite: the guard's own
 * lookups run on it), Acl::check(), the WebSocket fork and lang(). Those rows need vendor/ for the Slim
 * PSR-7 request and response: VENDOR_AUTOLOAD, or
 * <root>/vendor/autoload.php (an export has no vendor/: ln -s <checkout>/vendor <export>/vendor). The code
 * under test is loaded from <root>/src through a loader of its own, never from the vendor autoloader's
 * App\ mapping (in an export that points at the served tree); a control row checks it.
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

use App\Database\Db;
use App\modules\booking\controllers\HospitalityController;
use App\modules\booking\models\Hospitality;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\services\HospitalityDirectBookingGuard as G;
use App\modules\phpgwapi\security\Acl;

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
// The direction cannot be shown by a double (the SQL needs a database), so it is read as text: an order is open while its
// serving time is still AHEAD. `<` would count only the orders already served (and leave every open one behind a move).
$expect('q/"open" means the serving time is still AHEAD: `serving_time_iso > (now() ...)`, not `<`',
    str_contains($openFn, "serving_time_iso > (now() AT TIME ZONE 'UTC')"), true);

// ── the controller itself: store() and update(), run end to end on doubles ───────────────────────
// Everything above judges one method at a time, or reads the controller as text. This block RUNS it: the real controller, the
// real guard rules and the real model answer a request, and the doubles below stand in only for what reaches outside the
// process. A refusal that is computed and not returned, or a check that runs after the write, is a behaviour: only a row that
// drives update() and then asks "was anything written?" can see it.
$vendorAutoload = getenv('VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';
if (!is_file($vendorAutoload)) {
    fwrite(STDERR, "The controller rows need vendor/ (slim/psr7) and found none at {$vendorAutoload}.\n"
        . "In an export: ln -s <checkout>/vendor <export>/vendor, or set VENDOR_AUTOLOAD.\n");
    exit(2);
}
require_once $vendorAutoload;
// The code UNDER TEST comes from <root>/src, through a loader of its own placed FIRST: the vendor autoloader maps App\ to the
// checkout the vendor/ directory sits in, which for an export (vendor/ linked in) is the SERVED tree.
spl_autoload_register(static function (string $class) use ($root): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);
$modelFile = getenv('MODEL_FILE') ?: $root . '/src/modules/booking/models/Hospitality.php';
require_once $modelFile;

/** lang() answers "key|arg|arg": enough to see WHICH text a refusal carries and what it was given, without a translation table. */
function lang($key, ...$args): string
{
    return implode('|', array_merge([$key], array_map('strval', $args)));
}

/** The WebSocket fork is replaced by a recorder (the real one forks and talks to Redis). */
final class HarnessWebSocketHelper
{
    public static array $sent = [];

    public static function sendEntityNotificationAsync(string $entityType, $entityId, string $message, string $action, array $data = []): bool
    {
        self::$sent[] = [$entityType, $entityId, $action, $data['section'] ?? null];
        return true;
    }
}
class_alias(HarnessWebSocketHelper::class, 'App\modules\bookingfrontend\helpers\WebSocketHelper');

/** What the doubles answer from, and what they RECORD. */
final class HarnessWorld
{
    public array $row = [];          // the stored hospitality row
    public array $resourceIds = [];  // the ids that exist (resourceExists)
    public array $open = [];         // hospitality id => [location resource id => number of OPEN orders]
    public array $writes = [];       // [method, id, data] for every create()/update() the repository was asked for, in order
    public array $counts = [];       // [hospitality id, location resource id] for every Q1 count
    public PDO $pdo;                 // the guard's database: bb_resource and bb_hospitality_remote_location
}

/**
 * Hospitality 7, main resource 10 (Kjokken); resources 11-13 are free to move to, 20 has direct booking.
 * $with: 'row' (stored hospitality fields), 'remote' (hospitality id => ids of its ACTIVE delivery locations), 'open' (see HarnessWorld).
 */
$newWorld = static function (array $with = []): HarnessWorld {
    $w = new HarnessWorld();
    $w->row = array_replace(['id' => 7, 'resource_id' => 10, 'name' => 'Kantina', 'description' => null, 'active' => 1, 'modified' => null, 'open_days' => null], $with['row'] ?? []);
    $w->open = $with['open'] ?? [];
    $resources = [10 => ['Kjokken', null], 11 => ['Sal A', null], 12 => ['Sal B', null], 13 => ['Sal C', null], 20 => ['Sal D', FUTURE]];
    $w->resourceIds = array_keys($resources);
    $w->pdo = new PDO('sqlite::memory:');
    $w->pdo->exec('CREATE TABLE bb_resource (id INTEGER PRIMARY KEY, name TEXT, direct_booking INTEGER, simple_booking INTEGER)');
    $w->pdo->exec('CREATE TABLE bb_hospitality_remote_location (hospitality_id INTEGER, resource_id INTEGER, active INTEGER)');
    foreach ($resources as $id => [$name, $direct]) {
        $w->pdo->prepare('INSERT INTO bb_resource (id, name, direct_booking, simple_booking) VALUES (?, ?, ?, NULL)')->execute([$id, $name, $direct]);
    }
    foreach ($with['remote'] ?? [] as $hospitalityId => $resourceIds) {
        foreach ($resourceIds as $resourceId) {
            $w->pdo->prepare('INSERT INTO bb_hospitality_remote_location (hospitality_id, resource_id, active) VALUES (?, ?, 1)')->execute([$hospitalityId, $resourceId]);
        }
    }
    return $w;
};

$controllerOn = static function (HarnessWorld $w): HospitalityController {
    $controller = (new ReflectionClass(HospitalityController::class))->newInstanceWithoutConstructor();
    $inject = static function (string $property, $value) use ($controller): void {
        (new ReflectionProperty(HospitalityController::class, $property))->setValue($controller, $value);
    };
    $inject('userSettings', ['account_id' => 5]);
    $inject('acl', new class extends Acl {
        public function __construct()
        {
        }

        public function check($location, $required, $appname = '')
        {
            return true;
        }
    });
    $inject('repository', new class($w) extends HospitalityRepository {
        public function __construct(private HarnessWorld $w)
        {
        }

        public function getById(int $id): ?array
        {
            return $id === (int)$this->w->row['id'] ? $this->w->row : null;
        }

        public function resourceExists(int $resourceId): bool
        {
            return in_array($resourceId, $this->w->resourceIds, true);
        }

        public function create(array $data): int
        {
            $this->w->writes[] = ['create', null, $data];
            $this->w->row = array_replace($this->w->row, $data, ['id' => 99]);
            return 99;
        }

        public function update(int $id, array $data): bool
        {
            $this->w->writes[] = ['update', $id, $data];
            $this->w->row = array_replace($this->w->row, $data);
            return true;
        }
    });
    $inject('orderRepository', new class($w) extends HospitalityOrderRepository {
        public function __construct(private HarnessWorld $w)
        {
        }

        public function countOpenAtLocation(int $hospitalityId, int $locationResourceId): int
        {
            $this->w->counts[] = [$hospitalityId, $locationResourceId];
            return $this->w->open[$hospitalityId][$locationResourceId] ?? 0;
        }
    });
    // The guard is the REAL one (it is final, and its own SQL runs): only its database is replaced, by an in-memory SQLite that
    // holds the world's resource and remote-location rows. Db hands every call on to the PDO it wraps (Db::__call).
    $inject('directBookingGuard', new G(new class($w->pdo) extends Db {
        public function __construct(private PDO $pdo)
        {
        }

        public function __call($method, $arguments)
        {
            return $this->pdo->$method(...$arguments);
        }
    }));
    return $controller;
};

$requestFactory = new Slim\Psr7\Factory\ServerRequestFactory();
$responseFactory = new Slim\Psr7\Factory\ResponseFactory();
$streamFactory = new Slim\Psr7\Factory\StreamFactory();

/**
 * One request through the controller. $body is what the framework parsed (an array), or a raw JSON string that the controller
 * then decodes itself. Returns what a client and the database would have seen: the status and body, every repository write
 * and Q1 count in order, and every WebSocket broadcast. An Error out of the controller (a mutant that breaks it) is returned
 * as `threw`, so it turns a row red instead of ending the run.
 */
$drive = static function (string $action, $body, ?HarnessWorld $world = null) use ($newWorld, $controllerOn, $requestFactory, $responseFactory, $streamFactory): array {
    $w = $world ?? $newWorld();
    HarnessWebSocketHelper::$sent = [];
    $controller = $controllerOn($w);
    $request = $requestFactory->createServerRequest($action === 'store' ? 'POST' : 'PUT', '/booking/hospitality' . ($action === 'store' ? '' : '/7'));
    $request = is_array($body) ? $request->withParsedBody($body) : $request->withBody($streamFactory->createStream($body));
    $previous = error_reporting(E_ALL & ~E_DEPRECATED); // SerializableTrait still calls setAccessible(), deprecated since PHP 8.5
    try {
        $response = $responseFactory->createResponse();
        $response = $action === 'store' ? $controller->store($request, $response) : $controller->update($request, $response, ['id' => '7']);
        $raw = (string)$response->getBody();
        return ['status' => $response->getStatusCode(), 'raw' => $raw, 'body' => json_decode($raw, true), 'threw' => null,
            'writes' => $w->writes, 'counts' => $w->counts, 'broadcasts' => HarnessWebSocketHelper::$sent];
    } catch (Throwable $e) {
        return ['status' => 0, 'raw' => '', 'body' => null, 'threw' => get_class($e) . ': ' . $e->getMessage(),
            'writes' => $w->writes, 'counts' => $w->counts, 'broadcasts' => HarnessWebSocketHelper::$sent];
    } finally {
        error_reporting($previous);
    }
};

// The code under test really is this tree's (the loader above), and the file a mutant replaced really is the one that runs.
$loadedFrom = static fn(string $class): string => (string)realpath((string)(new ReflectionClass($class))->getFileName());
$expect('env/the model that runs is MODEL_FILE or this tree\'s', $loadedFrom(Hospitality::class), (string)realpath($modelFile));
$expect('env/the controller that runs is CONTROLLER_FILE or this tree\'s', $loadedFrom(HospitalityController::class),
    (string)realpath(getenv('CONTROLLER_FILE') ?: $root . '/src/modules/booking/controllers/HospitalityController.php'));
$expect('env/the guard that runs is GUARD_FILE or this tree\'s', $loadedFrom(G::class),
    (string)realpath(getenv('GUARD_FILE') ?: $root . '/src/modules/booking/services/HospitalityDirectBookingGuard.php'));
foreach ([HospitalityRepository::class, HospitalityOrderRepository::class, Acl::class] as $class) {
    $expect("env/{$class} comes from this tree, not from the vendor autoloader's App\\ mapping", str_starts_with($loadedFrom($class), (string)realpath($root) . '/src/'), true);
}

// ── the offer description: at most 70 Unicode CODE POINTS (f1998b27b, GH #1423) ───────────────────
// POST and PUT /booking/hospitality refuse a longer one with 422 description_too_long. The count is in code points (not bytes,
// not UTF-16 units, not graphemes), the string is counted as it will be stored (no trim), NULL and '' are allowed, and PUT
// judges the description only when the body carries the key (the admin saves one field per request).
$EMOJI = "\u{1F600}";            // ONE code point; 4 bytes in UTF-8, 2 units in UTF-16
$ACUTE = "\u{0301}";             // a combining acute accent: a code point of its own, and no width of its own
$AEOEAA = "\u{E6}\u{F8}\u{E5}";  // 3 code points, 6 bytes
// [label, value, verdict]; the arithmetic in each label is done by hand, in code points.
$descriptionCases = [
    ['70 x "a" (the limit)', str_repeat('a', 70), 'allowed'],
    ['71 x "a" (one over)', str_repeat('a', 71), 'refused'],
    ['70 code points of ae-oe-aa (140 bytes)', str_repeat($AEOEAA, 23) . "\u{E6}", 'allowed'],
    ['71 code points of ae-oe-aa (142 bytes)', str_repeat($AEOEAA, 23) . "\u{E6}\u{F8}", 'refused'],
    ['one emoji', $EMOJI, 'allowed'],
    ['70 x emoji (70 code points, 280 bytes)', str_repeat($EMOJI, 70), 'allowed'],
    ['71 x emoji (71 code points)', str_repeat($EMOJI, 71), 'refused'],
    ['69 x "a" + an emoji = 70', str_repeat('a', 69) . $EMOJI, 'allowed'],
    ['70 x "a" + an emoji = 71', str_repeat('a', 70) . $EMOJI, 'refused'],
    ['69 x "a" + a combining acute = 70 code points', str_repeat('a', 69) . $ACUTE, 'allowed'],
    ['70 x "a" + a combining acute = 71 code points (70 graphemes)', str_repeat('a', 70) . $ACUTE, 'refused'],
    ['35 x decomposed e-acute = 70 code points', str_repeat('e' . $ACUTE, 35), 'allowed'],
    ['36 x decomposed e-acute = 72 code points (36 graphemes)', str_repeat('e' . $ACUTE, 36), 'refused'],
    ['70 x precomposed e-acute (U+00E9) = 70 code points', str_repeat("\u{E9}", 70), 'allowed'],
    ['69 x "a" + a trailing space = 70', str_repeat('a', 69) . ' ', 'allowed'],
    ['70 x "a" + a trailing space = 71 (no trim)', str_repeat('a', 70) . ' ', 'refused'],
    ['a leading space + 69 x "a" = 70', ' ' . str_repeat('a', 69), 'allowed'],
    ['a leading space + 70 x "a" = 71 (no trim)', ' ' . str_repeat('a', 70), 'refused'],
    ['70 x "a" + a trailing newline = 71 (no trim)', str_repeat('a', 70) . "\n", 'refused'],
    ['70 spaces', str_repeat(' ', 70), 'allowed'],
    ['71 spaces (a trim would make it empty)', str_repeat(' ', 71), 'refused'],
    ['NULL', null, 'allowed'],
    ['the empty string', '', 'allowed'],
];
// One line per request, so a row reads as the whole answer: the status, the refusal (code + text), and what was written.
$descriptionOutcome = static function (array $o, $sent): string {
    if ($o['threw'] !== null) {
        return 'THREW ' . $o['threw'];
    }
    if ($o['status'] >= 400) {
        return sprintf('%d %s (%s) | %d write(s)', $o['status'], $o['body']['code'] ?? '-', $o['body']['error'] ?? '-', count($o['writes']));
    }
    $write = $o['writes'][0] ?? null;
    $what = $write === null ? 'NOTHING WRITTEN' : (array_key_exists('description', $write[2])
        ? ($write[2]['description'] === $sent ? 'description handed on as sent' : 'DESCRIPTION CHANGED')
        : 'no description key');
    return sprintf('%d | %d write(s): %s %s', $o['status'], count($o['writes']), $write[0] ?? '-', $what);
};
$tooLong = '422 description_too_long (booking.hospitality_description_too_long|70) | 0 write(s)';
$saved = ['store' => '201 | 1 write(s): create description handed on as sent', 'update' => '200 | 1 write(s): update description handed on as sent'];
$withDescription = static fn(string $action, $value): array => $action === 'store'
    ? ['resource_id' => 10, 'name' => 'Kantina', 'description' => $value]
    : ['description' => $value];

foreach ($descriptionCases as [$label, $value, $verdict]) {
    foreach (['store', 'update'] as $action) {
        $expect("desc/{$action} {$label}", $descriptionOutcome($drive($action, $withDescription($action, $value)), $value),
            $verdict === 'refused' ? $tooLong : $saved[$action]);
    }
}

// PINNED CURRENT BEHAVIOUR, not a ruling (RECORD 45803, forum/pe-backlog/45803): Hospitality::descriptionTooLong() answers false
// for anything that is not a string, so a non-string description is not refused by this rule, and the controller hands it to the
// repository exactly as it came: the repository double RECEIVES the array, the int, the bool. Where RECORD 45803 expects a 422
// for a non-string, non-null description, these rows are the ones that go red, deliberately. What the REAL repository then does
// with the value (the PDO bind; RECORD 45803 reads it as a 500) is not shown here: a double takes anything.
foreach ([['int 5', 5], ['float 1.5', 1.5], ['true', true], ['false', false], ['an empty array', []],
    ['an array holding a 500-character string', ['x' => str_repeat('a', 500)]]] as [$label, $value]) {
    foreach (['store', 'update'] as $action) {
        $expect("desc/{$action} pinned, see RECORD 45803: a non-string description ({$label}) is not judged, the repository receives it as it came",
            $descriptionOutcome($drive($action, $withDescription($action, $value)), $value), $saved[$action]);
    }
}

// The key is judged only when it is there.
$storedLong = $newWorld(['row' => ['description' => str_repeat('a', 128)]]); // an offer saved before the limit existed
$o = $drive('update', ['name' => 'Nytt navn'], $storedLong);
$expect('desc/update a name-only PUT on an offer whose STORED description is 128 code points: not judged, saves, the text is left alone',
    [$o['status'], $o['writes'], mb_strlen((string)($o['body']['description'] ?? ''), 'UTF-8')],
    [200, [['update', 7, ['name' => 'Nytt navn', 'modified_by' => 5]]], 128]);
$o = $drive('update', ['name' => 'Nytt navn', 'description' => str_repeat('a', 70)], $newWorld(['row' => ['description' => str_repeat('a', 128)]]));
$expect('desc/update that same offer, the description itself edited to 70: saves',
    [$o['status'], $o['writes']], [200, [['update', 7, ['name' => 'Nytt navn', 'description' => str_repeat('a', 70), 'modified_by' => 5]]]]);
$o = $drive('update', ['name' => 'Nytt navn', 'description' => str_repeat('a', 71)], $newWorld(['row' => ['description' => str_repeat('a', 128)]]));
$expect('desc/update that same offer, the description itself edited to 71: refused, nothing written (the other fields are not saved either)',
    [$o['status'], $o['body']['code'] ?? null, $o['writes']], [422, 'description_too_long', []]);
$o = $drive('store', ['resource_id' => 10, 'name' => 'Kantina']);
$expect('desc/store no description key at all: created without one',
    [$o['status'], $o['writes']], [201, [['create', null, ['resource_id' => 10, 'name' => 'Kantina', 'created_by' => 5]]]]);

// Over the wire: the controller decodes the JSON itself when the framework parsed nothing, and a JSON emoji is a surrogate pair.
$json70 = json_encode(['description' => str_repeat($EMOJI, 70)]);
$surrogatePair = chr(92) . 'ud83d' . chr(92) . 'ude00'; // backslash, u, d83d, backslash, u, de00: spelled out so no editor or tool turns it into the emoji
$expect('desc/wire control: the JSON body really spells the emoji as a surrogate-pair escape, not as the character', str_contains($json70, $surrogatePair), true);
$expect('desc/update over the wire, 70 emoji as surrogate pairs (280 bytes of text, 140 UTF-16 units): saves',
    $descriptionOutcome($drive('update', $json70), str_repeat($EMOJI, 70)), $saved['update']);
$expect('desc/update over the wire, 71 emoji as surrogate pairs: refused',
    $descriptionOutcome($drive('update', json_encode(['description' => str_repeat($EMOJI, 71)])), str_repeat($EMOJI, 71)), $tooLong);

// ── the refusals that come BEFORE the description ─────────────────────────────────────────────────
// One request that is wrong twice answers once: invalid_resource_id (read first), and the description is never looked at.
foreach (['update', 'store'] as $action) {
    $body = ['resource_id' => '0x5D', 'name' => 'Kantina', 'description' => str_repeat('a', 71)];
    $o = $drive($action, $body);
    $expect("combo/{$action} a bad resource_id AND a 71-character description: exactly one 422, invalid_resource_id, nothing written",
        [$o['status'], $o['body']['code'] ?? null, substr_count($o['raw'], '"code"'), $o['writes']], [422, 'invalid_resource_id', 1, []]);
}

// ── Q1 and Q2: moving the main resource of hospitality 7 (main 10) ────────────────────────────────
// What a client and the database see, and nothing else: status, refusal, every write, every broadcast.
$move = static fn(array $o): array => [
    'status' => $o['threw'] ?? $o['status'],
    'code' => $o['body']['code'] ?? null,
    'order_count' => $o['body']['order_count'] ?? null,
    'resource_ids' => $o['body']['resource_ids'] ?? null,
    'error' => $o['body']['error'] ?? null,
    'writes' => $o['writes'],
    'broadcasts' => $o['broadcasts'],
];
$refusedMove = static fn(string $code, string $error, array $extra = []): array =>
    array_replace(['status' => 422, 'code' => $code, 'order_count' => null, 'resource_ids' => null, 'error' => $error, 'writes' => [], 'broadcasts' => []], $extra);

// Q1: two open orders are served at the current main (10).
$w = $newWorld(['open' => [7 => [10 => 2]]]);
$o = $drive('update', ['resource_id' => 11], $w);
$expect('move/Q1 a main move with open orders at the old main: 422 main_resource_has_open_orders, order_count 2, NO repository write, no broadcast',
    $move($o), $refusedMove('main_resource_has_open_orders', 'booking.main_resource_has_open_orders|2', ['order_count' => 2]));
$expect('move/Q1 counts the open orders of THIS hospitality at the OLD main (7 at 10), once', $o['counts'], [[7, 10]]);
$expect('move/Q1 the same refusal when the id arrives as the string "11"',
    $move($drive('update', ['resource_id' => '11'], $newWorld(['open' => [7 => [10 => 2]]])))['code'], 'main_resource_has_open_orders');

// Q2: 12 is an ACTIVE delivery location of hospitality 7; nothing is open at 10.
$o = $drive('update', ['resource_id' => 12], $newWorld(['remote' => [7 => [12]]]));
$expect('move/Q2 a move onto an active delivery location: 422 main_resource_is_remote_location, resource_ids [12], NO repository write, no broadcast',
    $move($o), $refusedMove('main_resource_is_remote_location', 'booking.main_resource_is_remote_location|Sal B', ['resource_ids' => [12]]));

// Q2 wins over Q1: both apply.
$o = $drive('update', ['resource_id' => 12], $newWorld(['remote' => [7 => [12]], 'open' => [7 => [10 => 2]]]));
$expect('move/Q2 wins over Q1 when both apply: main_resource_is_remote_location, NO repository write',
    $move($o), $refusedMove('main_resource_is_remote_location', 'booking.main_resource_is_remote_location|Sal B', ['resource_ids' => [12]]));

// The direct-booking guard speaks first: 20 has direct booking, is an active delivery location, and orders are open at 10.
$o = $drive('update', ['resource_id' => 20], $newWorld(['remote' => [7 => [20]], 'open' => [7 => [10 => 2]]]));
$expect('move/the direct-booking guard speaks before Q2 and Q1: resource_has_direct_booking, NO repository write',
    [$o['status'], $o['body']['code'] ?? null, $o['body']['resource_ids'] ?? null, $o['writes'], $o['counts']], [422, 'resource_has_direct_booking', [20], [], []]);

// No move: neither refusal fires. Both are ARMED here (orders are open at 10 and 10 is also listed as an active delivery location).
$armed = static fn(): HarnessWorld => $newWorld(['open' => [7 => [10 => 2]], 'remote' => [7 => [10]]]);
$saved200 = static fn(array $data, string $section): array => ['status' => 200, 'code' => null, 'order_count' => null, 'resource_ids' => null, 'error' => null,
    'writes' => [['update', 7, $data]], 'broadcasts' => [['hospitality', 7, 'updated', $section]]];
$expect('move/no main change, the same resource_id (int) sent back with the rest of the row: saves, neither refusal',
    $move($drive('update', ['resource_id' => 10, 'name' => 'Nytt navn'], $armed())), $saved200(['resource_id' => 10, 'name' => 'Nytt navn', 'modified_by' => 5], 'details'));
$expect('move/no main change, the same resource_id as the string "10": saves, neither refusal',
    $move($drive('update', ['resource_id' => '10', 'name' => 'Nytt navn'], $armed())), $saved200(['resource_id' => 10, 'name' => 'Nytt navn', 'modified_by' => 5], 'details'));
$expect('move/no main change, no resource_id at all (a rename): saves, neither refusal',
    $move($drive('update', ['name' => 'Nytt navn'], $armed())), $saved200(['name' => 'Nytt navn', 'modified_by' => 5], 'details'));

// A valid move: nothing open at 10, 12 is a delivery location (not the one moved to), and the NEW main has open orders, which Q1 must not mind.
$w = $newWorld(['remote' => [7 => [12]], 'open' => [7 => [11 => 5]]]);
$o = $drive('update', ['resource_id' => '11'], $w);
$expect('move/a valid move: saves, ONE repository update with resource_id 11 as an int, the other admins told to rebuild the Resources tab',
    $move($o), $saved200(['resource_id' => 11, 'modified_by' => 5], 'main_resource'));
$expect('move/a valid move: Q1 counted the OLD main (7 at 10), not the new one and not the hospitality id', $o['counts'], [[7, 10]]);

// The doubles are meant to leave nothing to the real database. Db::getInstance() would create the singleton (in a CLI with no
// configuration that is a silent in-memory SQLite, which answers every query with nothing), so a path that reached for it
// would pass its rows for the wrong reason: the singleton must still be unset after every request above.
$expect('env/no request above reached for the database singleton (Db::$instance is still unset)',
    (new ReflectionProperty(Db::class, 'instance'))->getValue() === null, true);

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
