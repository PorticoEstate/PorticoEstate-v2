<?php

/**
 * ACCESSOR EQUIVALENCE of the four legacy sites (task #48299), on the real test database: for every account, does the
 * value each site READS come out the same from the old singleton as from PreferencesReader?
 *
 *   docker exec pe-api php /tmp/t48299/<name>/tests/preferences-reader/probe_equiv_legacy.php [EXPORT_DIR]
 *
 * probe_equiv.php (task #47037) already compared the WHOLE common and booking arrays of the singleton's read() and of
 * the reader over every account. This one compares what the four sites do with them, exactly as the sites write it:
 *
 *   :189   isset(booking.notify_on_new) && (booking.notify_on_new & 1)  → common.email
 *   :247   isset(booking.notify_on_new) && (booking.notify_on_new & 2)  → common.cellphone
 *   :350   !empty(common.email)                                          → common.email
 *   soreg  isset(common.email) && common.email ? common.email : ''     (the old site reads ->data, the others ->read())
 *
 * Old side: CreateObject('phpgwapi.preferences', $id)->read() and the singleton's ->data (this flips Settings['user'] on
 * every account — which is the defect, and irrelevant to a value comparison). New side: PreferencesReader::read($id, apps)
 * with the app lists the sites use. An operand the site would throw on (a stored value that is not numeric) is compared
 * as the exception class, since the site would throw the same way on both sides.
 *
 * Reads only: nothing is written, no transaction is needed. Output is counts; an account id is printed only for a mismatch.
 *
 * Positive controls: the comparison is run on a PAIR of different accounts (old of one, new of the other) and must report
 * differences, and the coverage counts show that the values compared were not all empty.
 *
 * Exit code: 0 no mismatch and the controls fired, 1 otherwise.
 */

require __DIR__ . '/bootstrap.php';

use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\PreferencesReader;

$export = $argv[1] ?? dirname(__DIR__, 2);
pr_bootstrap($export, pr_files_under_test());
$_SERVER['HTTP_HOST'] ??= 'pe-api.test';   // a CLI process has no Host header; Preferences::standard_substitutes reads it
error_reporting(E_ALL & ~E_DEPRECATED);

$stmt = pr_db()->prepare('SELECT account_id FROM phpgw_accounts WHERE account_id > 0 ORDER BY account_id');
$stmt->execute();
$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
echo 'accounts: ' . count($ids) . "\n";

$bit = static function (mixed $v, int $b): string {
    try {
        return (string)($v & $b);
    } catch (\Throwable $e) {
        return get_class($e);
    }
};

/** What the four sites read from a preferences array, in one comparable value per site. */
$sites = static function (array $booking_common, array $common, array $data) use ($bit): array {
    $notify = $booking_common['booking']['notify_on_new'] ?? null;
    $email = $booking_common['common']['email'] ?? null;
    return [
        's189' => [isset($booking_common['booking']['notify_on_new']) ? $bit($notify, 1) : 'unset', $email],
        's247' => [isset($booking_common['booking']['notify_on_new']) ? $bit($notify, 2) : 'unset', $booking_common['common']['cellphone'] ?? null],
        's350' => [!empty($common['common']['email']), $common['common']['email'] ?? null],
        'soreg' => isset($data['common']['email']) && $data['common']['email'] ? $data['common']['email'] : '',
    ];
};

$old = [];
$new = [];
$coverage = ['email' => 0, 'cellphone' => 0, 'notify_bit1' => 0, 'notify_bit2' => 0, 'forced_or_default_only' => 0];
foreach ($ids as $id) {
    $p = CreateObject('phpgwapi.preferences', $id);
    $read = $p->read();
    $data = Preferences::getInstance()->data;
    $old[$id] = $sites($read, $read, $data);
    $bc = PreferencesReader::read($id, ['booking', 'common']);
    $c = PreferencesReader::read($id, ['common']);
    $new[$id] = $sites($bc, $c, $c);
    $coverage['email'] += !empty($read['common']['email']) ? 1 : 0;
    $coverage['cellphone'] += !empty($read['common']['cellphone']) ? 1 : 0;
    $coverage['notify_bit1'] += $old[$id]['s189'][0] === '1' ? 1 : 0;
    $coverage['notify_bit2'] += $old[$id]['s247'][0] === '2' ? 1 : 0;
}

$pass = 0;
$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : "  — $detail") . "\n";
};

foreach (['s189' => ':189', 's247' => ':247', 's350' => ':350', 'soreg' => 'soreg :271'] as $key => $label) {
    $mismatch = [];
    foreach ($ids as $id) {
        if ($old[$id][$key] !== $new[$id][$key]) {
            $mismatch[] = $id;
        }
    }
    $ok("$label: what the site reads is identical, old singleton against reader, for all " . count($ids) . ' accounts', $mismatch === [], count($mismatch) . ' mismatch(es), first account ids: ' . implode(',', array_slice($mismatch, 0, 5)));
}

// positive control: the comparison CAN differ. Old value of account N against new value of the next account that has a different email.
$pairs = 0;
$prev = null;
foreach ($ids as $id) {
    if ($prev !== null && $old[$prev]['soreg'] !== $new[$id]['soreg']) {
        $pairs++;
    }
    $prev = $id;
}
$ok("control: comparing one account's old value with ANOTHER account's new value reports differences (the comparison can fail)", $pairs > 0, "$pairs differing neighbour pairs");
echo 'coverage (accounts, counts): with email ' . $coverage['email'] . ', with cellphone ' . $coverage['cellphone'] . ', notify bit 1 ' . $coverage['notify_bit1'] . ', notify bit 2 ' . $coverage['notify_bit2'] . "\n";
$ok('coverage: the values compared were not all empty (email, cellphone and notify bit 1 each occur)', $coverage['email'] > 0 && $coverage['cellphone'] > 0 && $coverage['notify_bit1'] > 0);

echo 'EQUIV: ' . ($fail ? "FAIL $fail of " . ($pass + $fail) : "PASS $pass") . "\n";
exit($fail ? 1 : 0);
