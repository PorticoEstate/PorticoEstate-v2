<?php

/**
 * DIFFERENTIAL (task #47037, after 8ebd2a6a4's): is PreferencesReader the SAME function of the stored
 * preferences as the call each site made before —
 *
 *     CreateObject('phpgwapi.preferences', $id)->read()      (the framework's Readpreferences)
 *
 * — for every value the five sites read, over every account, on the real database?
 *
 *   docker exec pe-api php /tmp/t47037/<name>/tests/preferences-reader/probe_equiv.php [FIX_EXPORT] [BASE_EXPORT]
 *
 * "old" is the framework call itself, asserted to be the verbatim text of BASE_EXPORT's source (when a
 * base export is given); "new" is the fix export's PreferencesReader. See bootstrap.php for how a tree
 * gets into the container and why this is harmless. Prints ids and booleans, NEVER an address: values are
 * compared in memory.
 *
 *   A. THE WHOLE POPULATION. Every account that has a preference row, 25 that have none, one id that does
 *      not exist; each compared through the exact accessor of each site:
 *        :785  getMailAddresses         common.email                           read($id, ['common'])
 *        :425  sendCaseOfficerNotifs    booking.notify_on_new & 1              read($id, ['booking'])
 *        :711  sendSmsNotifications     booking.notify_on_new & 2, common.cellphone   read($id, ['booking','common'])
 *        :96   Catering loadOfficer     common.email, common.lang              read($id, ['common'])
 *        AllocationTimeChangeNotifier   common.email, common.lang              read($id, ['common'])
 *      plus the WHOLE `common` and `booking` arrays (minus Readpreferences' derived common.tz_offset).
 *      Run twice: as stored, and with the forced common.lang REMOVED (a rolled-back transaction), where
 *      each account's own language decides and the forced-language rule (#45917) is the thing measured.
 *   B. PRECEDENCE ARMS on the real rows, each in its own transaction that is ROLLED BACK: a forced
 *      address, a forced notify bit, a default that fills an emptied own value, an own "0" a default must
 *      not replace, a stored backslash.
 *   C. WHAT THE SWEEP CANNOT SEE, said out loud: the apps no site reads (calendar, property, …) are
 *      compared too but only INFORMATIVELY: the one stored `$$name$$` placeholder lives in the default
 *      calendar row, which Preferences substitutes and PreferencesReader does not, by design.
 *
 * Exit code: 0 all pass, 1 any failure.
 */

require __DIR__ . '/bootstrap.php';

use App\modules\phpgwapi\services\PreferencesReader;

$fix = $argv[1] ?? dirname(__DIR__, 2);
$base = $argv[2] ?? null;
set_time_limit(0);
pr_bootstrap($fix, pr_files_under_test());
if (!class_exists(PreferencesReader::class, false)) {
    fwrite(STDERR, "ABORT: no PreferencesReader in $fix — this probe tests the fix export\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : "  — $detail") . "\n";
};

// "old" is the call the base export makes, said by its source and not by this file
if ($base !== null) {
    $emailSrc = (string)file_get_contents("$base/src/modules/booking/services/EmailService.php");
    $cateringSrc = (string)file_get_contents("$base/src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php");
    $ok('OLD is the base export\'s own call at :425 and :711 (`CreateObject(\'phpgwapi.preferences\', $user_id)->read()` twice)', substr_count($emailSrc, "CreateObject('phpgwapi.preferences', \$user_id)->read();") === 2);
    $ok('OLD at :785 (`CreateObject(\'phpgwapi.preferences\', $user)->read()`)', substr_count($emailSrc, "CreateObject('phpgwapi.preferences', \$user)->read();") === 1);
    $ok('OLD at CateringChangeNotifier :96 (`CreateObject(\'phpgwapi.preferences\', $accountId)->read()`)', substr_count($cateringSrc, "CreateObject('phpgwapi.preferences', \$accountId)->read();") === 1);
}

$db = pr_db();
$col = function (string $sql, array $p = []) use ($db): array {
    $s = $db->prepare($sql);
    $s->execute($p);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$q1 = function (string $sql, array $p = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
};

pr_seed_citizen();
$old = static fn(int $id): array => CreateObject('phpgwapi.preferences', $id)->read();

/** Each site's own accessor, over the apps it asks the reader for. site => [apps, fn(prefs): mixed] */
$bit = static function (array $p, int $mask): ?bool {
    try {
        return isset($p['booking']['notify_on_new']) ? (bool)($p['booking']['notify_on_new'] & $mask) : null;
    } catch (\Throwable $e) {
        return null;
    }
};
$accessors = [
    ':785 common.email' => [['common'], static fn(array $p) => $p['common']['email'] ?? null],
    ':425 booking.notify_on_new & 1' => [['booking'], static fn(array $p) => $bit($p, 1)],
    ':711 booking.notify_on_new & 2' => [['booking', 'common'], static fn(array $p) => $bit($p, 2)],
    ':711 common.cellphone' => [['booking', 'common'], static fn(array $p) => $p['common']['cellphone'] ?? null],
    ':96 + AllocationTimeChangeNotifier common.email' => [['common'], static fn(array $p) => $p['common']['email'] ?? null],
    ':96 + AllocationTimeChangeNotifier common.lang' => [['common'], static fn(array $p) => $p['common']['lang'] ?? null],
];
/** md5 an address-like value so nothing sensitive is ever printed or stored */
$fingerprint = static fn(mixed $v): string => $v === null ? 'null' : md5(var_export($v, true));
$canon = static function (mixed $v) use (&$canon): mixed {
    if (!is_array($v)) {
        return $v;
    }
    $v = array_map($canon, $v);
    ksort($v);
    return $v;
};

/** Compare old and new for the given ids; returns [mismatching ids per accessor, whole-array mismatches, informative mismatches]. */
$sweep = function (string $label, array $ids) use ($old, $accessors, $fingerprint, $canon, $ok): void {
    $t = microtime(true);
    $mism = array_fill_keys(array_keys($accessors), []);
    $whole = [];
    $informative = 0;
    $informativeApps = [];
    $errOld = $errNew = [];
    $withEmail = 0;
    $langs = [];
    foreach ($ids as $id) {
        try {
            $o = $old($id);
        } catch (\Throwable $e) {
            $errOld[] = $id;
            continue;
        }
        foreach ($accessors as $name => [$apps, $get]) {
            try {
                $n = PreferencesReader::read($id, $apps);
                if ($fingerprint($get($o)) !== $fingerprint($get($n))) {
                    $mism[$name][] = $id;
                }
            } catch (\Throwable $e) {
                $errNew[] = $id;
            }
        }
        $n2 = PreferencesReader::read($id, ['common', 'booking']);
        $oWhole = array_intersect_key($o, ['common' => 1, 'booking' => 1]);
        // Readpreferences OVERWRITES common.tz_offset with an offset computed from common.timezone; the reader
        // returns whatever is stored. No site reads it; it is set aside on BOTH sides.
        unset($oWhole['common']['tz_offset']);
        $n2NoTz = $n2;
        unset($n2NoTz['common']['tz_offset']);
        if ($canon($oWhole) !== $canon($n2NoTz)) {
            $whole[] = $id;
        }
        if (isset($n2['common']['email']) && $n2['common']['email'] !== '') {
            $withEmail++;
        }
        $l = var_export($n2['common']['lang'] ?? null, true);
        $langs[$l] = ($langs[$l] ?? 0) + 1;
        // informative: every app, tz_offset set aside on both sides; WHICH apps differ is recorded
        $nAll = PreferencesReader::read($id);
        $oAll = $o;
        unset($oAll['common']['tz_offset'], $nAll['common']['tz_offset']);
        $differing = array_filter(array_unique(array_merge(array_keys($oAll), array_keys($nAll))), static fn($app) => $canon($oAll[$app] ?? null) !== $canon($nAll[$app] ?? null));
        foreach ($differing as $app) {
            $informativeApps[$app] = ($informativeApps[$app] ?? 0) + 1;
        }
        if ($differing) {
            $informative++;
        }
    }
    ksort($langs);
    echo '  (' . round(microtime(true) - $t, 1) . "s) [$label] " . count($ids) . " ids; accounts with a common.email: $withEmail; lang distribution: " . json_encode($langs) . "\n";
    foreach ($accessors as $name => $_) {
        $ok("[$label] old == new for $name over all " . count($ids) . ' ids', $mism[$name] === [], 'mismatching ids: ' . implode(',', array_slice($mism[$name], 0, 30)) . ' (' . count($mism[$name]) . ')');
    }
    $ok("[$label] old == new for the WHOLE common and booking arrays (tz_offset aside)", $whole === [], 'mismatching ids: ' . implode(',', array_slice($whole, 0, 30)) . ' (' . count($whole) . ')');
    $ok("[$label] nobody threw (old: " . count($errOld) . ', new: ' . count($errNew) . ')', $errOld === [] && $errNew === []);
    ksort($informativeApps);
    echo "  [$label] informative: " . $informative . ' of ' . count($ids) . ' ids differ in some app; apps that differ (id counts): ' . json_encode($informativeApps) . "\n";
    $ok("[$label] the only app that ever differs is one NO site reads (calendar: it carries the stored `\$\$` placeholder)", array_diff(array_keys($informativeApps), ['calendar']) === [], json_encode($informativeApps));
};

// ================= A. the whole population ===========================================================================
$withRow = $col('SELECT DISTINCT preference_owner FROM phpgw_preferences WHERE preference_owner > 0 ORDER BY 1');
$without = $col("SELECT account_id FROM phpgw_accounts WHERE account_id > 0 AND account_id NOT IN (SELECT preference_owner FROM phpgw_preferences WHERE preference_owner > 0) ORDER BY account_id LIMIT 25");
$ids = array_merge($withRow, $without, [99999999]);
echo 'A. population: ' . count($withRow) . ' accounts with a preference row, ' . count($without) . ' without any, 1 non-existent id (counts taken now, not selectors)' . "\n";
$sweep('as stored', $ids);

$snapshotRows = function () use ($db): string {
    $s = $db->prepare('SELECT preference_owner, preference_app, preference_json::text FROM phpgw_preferences WHERE preference_owner IN (-1, -2) ORDER BY 1, 2');
    $s->execute();
    return md5(json_encode($s->fetchAll(PDO::FETCH_NUM)));
};
$rowsBefore = $snapshotRows();
$forcedLangBefore = (string)$q1("SELECT preference_json::jsonb->>'lang' FROM phpgw_preferences WHERE preference_owner = -1 AND preference_app = 'common'");
$ok('the forced common.lang exists to be removed (control: the arm below changes something)', $forcedLangBefore !== '');
$db->transaction_begin();
try {
    $db->exec("UPDATE phpgw_preferences SET preference_json = preference_json - 'lang' WHERE preference_owner = -1 AND preference_app = 'common'");
    $ok('...inside the transaction the forced language IS gone (the instrument sees the change)', (string)$q1("SELECT preference_json::jsonb->>'lang' FROM phpgw_preferences WHERE preference_owner = -1 AND preference_app = 'common'") === '');
    $sweep('forced lang removed, rolled back', $ids);
} finally {
    $db->transaction_abort();
}
$ok('after ROLLBACK every forced and default row is as found', $snapshotRows() === $rowsBefore);

// ================= B. precedence arms, each ROLLED BACK ==============================================================
echo "B. precedence arms on the real rows (each in its own transaction, rolled back)\n";
$notifyAccounts = $col("SELECT preference_owner FROM phpgw_preferences WHERE preference_owner > 0 AND preference_app = 'booking' AND jsonb_exists(preference_json::jsonb, 'notify_on_new') ORDER BY 1 LIMIT 6");
$cellAccounts = $col("SELECT preference_owner FROM phpgw_preferences WHERE preference_owner > 0 AND preference_app = 'common' AND jsonb_exists(preference_json::jsonb, 'cellphone') ORDER BY 1 LIMIT 6");
$emailAccounts = $col("SELECT preference_owner FROM phpgw_preferences WHERE preference_owner > 0 AND preference_app = 'common' AND jsonb_exists(preference_json::jsonb, 'email') ORDER BY 1 LIMIT 6");
$pick = array_values(array_unique(array_merge($notifyAccounts, $cellAccounts, $emailAccounts, [PR_OFFICER, 1030], array_slice($without, 0, 3), [99999999])));
$one = $notifyAccounts[0] ?? PR_OFFICER;
$upsert = static fn(int $owner, string $app, string $json): string => "INSERT INTO phpgw_preferences (preference_owner, preference_app, preference_json) VALUES ($owner, '$app', '$json') ON CONFLICT (preference_owner, preference_app) DO UPDATE SET preference_json = EXCLUDED.preference_json";
$arms = [
    'a FORCED address beats every own one' => [$upsert(-1, 'common', '{"email":"forced@example.test","lang":"no"}')],
    'a forced notify bit 0 beats the own one' => ["UPDATE phpgw_preferences SET preference_json = jsonb_set(preference_json, '{notify_on_new}', '\"0\"') WHERE preference_owner = -1 AND preference_app = 'booking'", $upsert(-1, 'booking', '{"notify_on_new":"0"}')],
    'no forced notify; a default 3 fills accounts without one' => ["DELETE FROM phpgw_preferences WHERE preference_owner = -1 AND preference_app = 'booking'", $upsert(-2, 'booking', '{"notify_on_new":"3"}')],
    '…and one account\'s own notify_on_new emptied: the default fills it' => ["DELETE FROM phpgw_preferences WHERE preference_owner = -1 AND preference_app = 'booking'", $upsert(-2, 'booking', '{"notify_on_new":"3"}'),
        "UPDATE phpgw_preferences SET preference_json = jsonb_set(preference_json, '{notify_on_new}', '\"\"') WHERE preference_owner = $one AND preference_app = 'booking'"],
    '…and the same account\'s own "0": the default must NOT replace it' => ["DELETE FROM phpgw_preferences WHERE preference_owner = -1 AND preference_app = 'booking'", $upsert(-2, 'booking', '{"notify_on_new":"3"}'),
        "UPDATE phpgw_preferences SET preference_json = jsonb_set(preference_json, '{notify_on_new}', '\"0\"') WHERE preference_owner = $one AND preference_app = 'booking'"],
    'a stored backslash in an own address and cellphone is unquoted' => [
        "UPDATE phpgw_preferences SET preference_json = jsonb_set(preference_json, '{email}', to_jsonb('o\\''brien@example.test'::text)) WHERE preference_owner = " . ($emailAccounts[0] ?? PR_OFFICER) . " AND preference_app = 'common'",
        "UPDATE phpgw_preferences SET preference_json = jsonb_set(preference_json, '{cellphone}', to_jsonb('+47\\\\99'::text)) WHERE preference_owner = " . ($cellAccounts[0] ?? PR_OFFICER) . " AND preference_app = 'common'"],
    'a default cellphone fills accounts without one' => [$upsert(-2, 'common', '{"cellphone":"+4700000000","lang":"nn"}')],
];
foreach ($arms as $label => $sqls) {
    $db->transaction_begin();
    try {
        foreach ($sqls as $sql) {
            $db->exec($sql);
        }
        $sweep($label, $pick);
    } finally {
        $db->transaction_abort();
    }
}
$ok('after the last ROLLBACK every forced and default row is as found', $snapshotRows() === $rowsBefore);

echo 'PROBE: ' . ($fail ? "FAIL $fail of " . ($pass + $fail) : "PASS $pass") . "\n";
exit($fail ? 1 : 0);
