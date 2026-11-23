<?php

/**
 * REQUEST-LEVEL identity probe of the LEGACY twins (task #48299): after the legacy code of a citizen's
 * request has read other accounts' preferences, is the request STILL the citizen's?
 *
 *   docker exec pe-api php /tmp/t48299/<name>/tests/preferences-reader/probe_legacy.php EXPORT_DIR [--arms=A1,A2] [--out=file.json] [--compare=other.json]
 *
 * EXPORT_DIR holds the code under test (src/modules/booking/inc/class.boapplication.inc.php and
 * src/modules/registration/inc/class.soreg.inc.php); see bootstrap_legacy.php for how it gets there and
 * for the SMS stub. Run it on a BASE export and on the FIX export: base must FAIL, the fix must PASS.
 *
 * Every arm seeds the citizen (Settings['user'] 1022), runs REAL production code for one citizen-reachable
 * path in this one process, and asserts that Settings['user'] (sha1 of serialize), every public field of the
 * Preferences singleton and the Translation language are BYTE-EQUAL at the END. Each arm runs in a
 * transaction that is rolled back. The arms:
 *
 *   A1  send_notification($application, true)  — the tail of uiapplication::add_contact and ::add
 *                                                 → :189 :247 :350            (needs the SMS stub)
 *   A2  sendCaseOfficerNotifications            → :189 + :350
 *   A3  sendSmsNotifications                    → :247 + :350                  (needs the SMS stub)
 *   A4  boevent::send_admin_notification        → :350   (uievent.edit / .cancel)
 *   A5  boallocation::send_admin_notification  → :350   (uiallocation.cancel)
 *   A6  bobooking::send_admin_notification      → :350   (uibooking.cancel)
 *   A7  registration_soreg::lostpw1             → soreg :271 (see below)
 *   A8  FINDING (a): the partials loop of uiapplication::add_contact with TWO partials → :189 :247 :350
 *       The notification sits INSIDE `foreach ($partials['results'] as &$application)`, so partial #2's
 *       bo->update() runs after partial #1's send_notification(). The arm logs the account id the request has
 *       before and after each update(): on base partial #2 is updated as the officer, after the fix as the citizen.
 *
 * A8 IS A REPLICA OF THE LOOP, not the real add_contact(): the real method ends in self::redirect() (exit) and calls
 * Db::transaction_commit() between update() and send_notification() — it commits even when nested, so it cannot run
 * in a rolled-back arm. The replica runs the loop's else-branch (booking/inc/class.uiapplication.inc.php, add_contact,
 * the part that is not direct booking) with the REAL bo->get_partials_list(), bo->update() and
 * bo->send_notification($application, true), in that order, WITHOUT collect_users() and the commit (neither reads a
 * preference). A source-shape guard fails if the sequence it copies changes in uiapplication.inc.php; the guard is
 * shown failing on a doctored copy. The real method is the live HTTP arm.
 *
 * A2 and A3 are private methods, called through reflection so that the mutant of :189 and the mutant of
 * :247 each have an arm of their own. A4-A6 never reach sms.sms (checked in the source).
 *
 * A7 AND THE CONFIG. The test database's registration config has username_is = email, and then
 * lostpw1 sets $info['email'] = $account_lid and never reaches soreg :271 (the `if (!$info['email'])` branch).
 * Configuration is not ours to change, so A7 sets username_is = 'username' on the soreg INSTANCE, in memory,
 * for the one call (the arm prints the stored value). It picks an account that has a preference email and no
 * contact work email, as :271 needs. On this instance the line is unreachable over HTTP; on an instance with
 * another username_is it is the anonymous password-reset path.
 *
 * THE FIXTURE. Application 85034 (case officer 7, building 10). For A1 and A3 two role-holders of the
 * building get notify_on_new = 3 and a SYNTHETIC cellphone inside the arm's transaction, so that the
 * sms stub is exercised (its call count is printed and required). Nothing here prints an address or a number.
 *
 * Positive controls (every run): CreateObject('phpgwapi.preferences', <officer>) CHANGES the state, without
 * an id it does not, re-seeding restores it, the sms guard REFUSES a non-stub object, and the mail count
 * and sms-stub call count of each arm must reach their minimum, so an arm that silently did nothing fails.
 *
 * --out writes what each arm mailed (count, md5 of To/Cc/Bcc/subject/body) and the stub's call count;
 * --compare reads the other run's file: recipients and subjects must be equal arm by arm.
 *
 * Exit code: 0 all pass, 1 any failure, 9 the SMS or mail barrier is not as expected.
 */

require __DIR__ . '/bootstrap_legacy.php';

use App\modules\phpgwapi\services\MailDiskWriter;

$export = null;
$only = null;
$outFile = null;
$compareFile = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--arms=')) {
        $only = explode(',', substr($a, 7));
    } elseif (str_starts_with($a, '--out=')) {
        $outFile = substr($a, 6);
    } elseif (str_starts_with($a, '--compare=')) {
        $compareFile = substr($a, 10);
    } elseif ($export === null) {
        $export = $a;
    }
}
$export ??= dirname(__DIR__, 2);

pl_bootstrap($export);
pl_seed_citizen();   // the legacy constructors read Settings['user'] preferences
pr_assert_fixture();
phpgw::import_class('booking.uicommon');   // defines pretty_timestamp(), which the admin mails call; a real request has it loaded

const PL_BUILDING = 10;

$pass = 0;
$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : "  — $detail") . "\n";
};

// ---- which code is under test, said by the code: does it still create the singleton for an id? ----------------------
/** The with-id singleton calls left in a class's source (comments stripped, so a docblock that mentions one is not counted). */
$singletonCalls = static function (string $file): int {
    $code = '';
    foreach (token_get_all((string)file_get_contents($file)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($t) ? $t[1] : $t;
    }
    return preg_match_all("/CreateObject\(\s*'phpgwapi\.preferences'\s*,/i", $code);
};
$sites = [
    'booking_boapplication' => (new ReflectionClass(createObject('booking.boapplication')))->getFileName(),
    'registration_soreg' => (new ReflectionClass(createObject('registration.soreg')))->getFileName(),
];
echo "code under test\n";
$guard = [];
foreach ($sites as $class => $file) {
    $n = $singletonCalls($file);
    $guard[$class] = $n;
    echo sprintf("  %-24s %s  CreateObject('phpgwapi.preferences', id) calls: %d\n", $class, str_starts_with($file, realpath($export) . '/') ? 'from the export' : 'FROM ' . $file, $n);
}

echo "origin\n";
foreach ($sites as $class => $file) {
    $ok("[origin] $class is loaded from the export under test, not from the served tree", str_starts_with($file, realpath($export) . '/'), $file);
}

// ---- instrument controls: the state CAN say «unchanged» and CAN say «changed»; the sms guard CAN refuse --------------
echo "controls\n";
pl_seed_citizen();
$s0 = pl_state();
CreateObject('phpgwapi.preferences');
$ok('control A: CreateObject(preferences) WITHOUT an id leaves the state unchanged', pl_state() === $s0);
CreateObject('phpgwapi.preferences', PR_OFFICER);
$moved = pl_state() !== $s0;
echo '  after CreateObject(preferences, ' . PR_OFFICER . '): ' . pr_describe() . "\n";
$ok('control B: CreateObject(preferences, officer) CHANGES the state (the instrument can fail)', $moved);
pl_seed_citizen();
$ok('control C: re-seeding the citizen restores the state exactly', pl_state() === $s0);
$ok('control D: the sms guard REFUSES an object that is not the stub (it can fail)', pl_sms_verdict(new stdClass) !== '', pl_sms_verdict(new stdClass));
$ok("control E: the sms guard ACCEPTS createObject('sms.sms') (it can pass)", pl_sms_verdict(createObject('sms.sms')) === '');
$ok('control F: the stub counts (a call is visible)', (function (): bool {
    sms_sms::$calls = 0;
    (new sms_sms())->websend2pv('x', 'y', 'z');
    $n = sms_sms::$calls;
    sms_sms::$calls = 0;
    return $n === 1;
})());

// ---- fixture helpers -------------------------------------------------------------------------------------------------------
/** @return list<int> the role-holders of the building that have a stored common.email, in id order */
$roleHolders = static function (): array {
    $ids = [];
    foreach (createObject('booking.sopermission_building')->get_roles_at_building(PL_BUILDING) as $r) {
        $ids[(int)$r['user_id']] = true;
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
};
/** Two role-holders of the building get notify_on_new = 3 and a synthetic cellphone (rolled back with the arm). */
$synthRecipients = static function () use ($roleHolders): int {
    $n = 0;
    foreach ($roleHolders() as $u) {
        $s = pr_db()->prepare("SELECT 1 FROM phpgw_preferences WHERE preference_owner = ? AND preference_app = 'common' AND coalesce((preference_json::jsonb)->>'email', '') <> ''");
        $s->execute([$u]);
        if (!$s->fetchColumn()) {
            continue;
        }
        pl_set_pref($u, 'booking', ['notify_on_new' => 3]);
        pl_set_pref($u, 'common', ['cellphone' => '40000000']);
        if (++$n === 2) {
            break;
        }
    }
    return $n;
};
/** The application as the legacy bo reads it from the store (raw, no authorisation of the current user). */
$application = static function (): array {
    return createObject('booking.boapplication')->so->read_single(PR_APPLICATION);
};
$resourceOfBuilding = static function (): int {
    $s = pr_db()->prepare('SELECT resource_id FROM bb_building_resource WHERE building_id = ? ORDER BY resource_id LIMIT 1');
    $s->execute([PL_BUILDING]);
    return (int)$s->fetchColumn();
};
$from = '2026-12-10 12:00:00';
$to = '2026-12-10 14:00:00';
$maildata = ['outseason' => '', 'recurring' => '', 'delete_allocation' => '', 'allocation' => 0];
$systemMessage = ['title' => 'probe #48299', 'name' => 'Probe', 'message' => 'probe #48299'];

/** An account that has a stored preference email and no contact work email (what soreg :271 needs). */
$lostpwAccount = static function (): array {
    $s = pr_db()->prepare(
        "SELECT a.account_id, a.account_lid, p.preference_json FROM phpgw_accounts a"
        . " JOIN phpgw_preferences p ON p.preference_owner = a.account_id AND p.preference_app = 'common'"
        . " WHERE coalesce((p.preference_json::jsonb)->>'email', '') <> '' AND a.account_type = 'u' AND a.account_status = 'A'"
        . " AND NOT EXISTS (SELECT 1 FROM phpgw_contact_comm c JOIN phpgw_contact_comm_descr d ON d.comm_descr_id = c.comm_descr_id"
        . "   WHERE c.contact_id = a.person_id AND d.descr = 'work email' AND coalesce(c.comm_data, '') <> '')"
        . ' ORDER BY a.account_id LIMIT 1'
    );
    $s->execute();
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "ABORT: no account with a preference email and without a contact work email; A7 cannot reach soreg :271\n");
        exit(9);
    }
    return $row;
};

/**
 * The sequence A8 copies out of uiapplication::add_contact, as literal fragments that must occur in this order inside
 * the function. Returns '' when the shape is as copied, else the first fragment that is missing or out of order.
 */
const PL_ADD_CONTACT_SHAPE = [
    "foreach (\$partials['results'] as &\$application)",
    "unset(\$application['frontend_modified']);",
    "\$application['status'] = 'NEW';",
    "\$application['created'] = 'now';",
    "\$application['modified'] = 'now';",
    "\$application['session_id'] = null;",
    "\$receipt = \$this->bo->update(\$application);",
    "CreateObject('booking.souser')->collect_users(\$application['customer_ssn']);",
    "Db::getInstance()->transaction_commit();",
    "\$this->bo->send_notification(\$application, true);",
];
$addContactShape = static function (string $source): string {
    $start = strpos($source, 'function add_contact()');
    if ($start === false) {
        return 'function add_contact() not found';
    }
    $end = strpos($source, "\n\tfunction ", $start + 10);
    $body = substr($source, $start, $end === false ? null : $end - $start);
    $at = 0;
    foreach (PL_ADD_CONTACT_SHAPE as $fragment) {
        $pos = strpos($body, $fragment, $at);
        if ($pos === false) {
            return "missing or out of order: $fragment";
        }
        $at = $pos + strlen($fragment);
    }
    return '';
};
/** A second application of the building that has dates and resources, to be the citizen's second partial. */
$secondPartial = static function (): int {
    $s = pr_db()->prepare(
        'SELECT a.id FROM bb_application a'
        . ' WHERE a.id <> ? AND a.building_name IS NOT NULL AND a.id IN (SELECT application_id FROM bb_application_date)'
        . ' AND a.id IN (SELECT ar.application_id FROM bb_application_resource ar JOIN bb_building_resource br ON br.resource_id = ar.resource_id WHERE br.building_id = ?)'
        . ' ORDER BY a.id LIMIT 1'
    );
    $s->execute([PR_APPLICATION, PL_BUILDING]);
    $id = (int)$s->fetchColumn();
    if (!$id) {
        fwrite(STDERR, "ABORT: no second application at building " . PL_BUILDING . " to be the second partial\n");
        exit(9);
    }
    return $id;
};
/**
 * The stored row of an application (without its three clock columns) and its comment rows (author, type, text — no id or
 * time), as one digest. Does update() or send_notification() write the identity the request runs as anywhere?
 */
$rowDigest = static function (int $id): string {
    $s = pr_db()->prepare('SELECT * FROM bb_application WHERE id = ?');
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    unset($row['created'], $row['modified'], $row['frontend_modified']);
    ksort($row);
    $c = pr_db()->prepare('SELECT author, type, comment FROM bb_application_comment WHERE application_id = ? ORDER BY id');
    $c->execute([$id]);
    return md5(serialize([$row, $c->fetchAll(PDO::FETCH_ASSOC)]));
};

/**
 * name => [paths, callable, expect]. expect(list of recorded mails, sms stub calls, synthetic recipients): null = fine,
 * a string = what is wrong. The minimums keep an arm that silently did nothing from passing.
 */
$arms = [
    'A1' => [':189 :247 :350', function () use ($synthRecipients, $application): int {
        $n = $synthRecipients();
        createObject('booking.boapplication')->send_notification($application(), true);
        return $n;
    }, static fn(array $m, int $sms, int $n): ?string => count($m) >= 2 && $sms >= $n && $n === 2 ? null : "expected the applicant's mail AND the officers' BCC mail and one sms-stub call per synthetic recipient (2); got " . count($m) . " mail(s), $sms sms-stub call(s), $n synthetic"],

    'A2' => [':189 :350', function () use ($synthRecipients, $application): int {
        $n = $synthRecipients();
        $bo = createObject('booking.boapplication');
        (new ReflectionMethod($bo, 'sendCaseOfficerNotifications'))->invoke($bo, $application());
        return $n;
    }, static fn(array $m, int $sms, int $n): ?string => count($m) === 1 && $sms === 0 ? null : 'expected exactly the officers\' BCC mail and no sms; got ' . count($m) . " mail(s), $sms sms-stub call(s)"],

    'A3' => [':247 :350', function () use ($synthRecipients, $application): int {
        $n = $synthRecipients();
        $bo = createObject('booking.boapplication');
        (new ReflectionMethod($bo, 'sendSmsNotifications'))->invoke($bo, $application());
        return $n;
    }, static fn(array $m, int $sms, int $n): ?string => $sms >= $n && $n === 2 && count($m) === 0 ? null : "expected one sms-stub call per synthetic recipient (2) and no mail; got $sms sms-stub call(s), " . count($m) . " mail(s), $n synthetic"],

    'A4' => [':350', function () use ($resourceOfBuilding, $from, $to): int {
        $_POST['org_from'] = $from;
        $_POST['org_to'] = $to;
        $event = ['customer_organization_name' => 'Probe', 'contact_name' => 'Probe Sok', 'contact_email' => 'probe@example.invalid', 'contact_phone' => '',
            'building_id' => PL_BUILDING, 'building_name' => 'Probe bygg', 'from_' => $from, 'to_' => $to, 'resources' => [$resourceOfBuilding()]];
        createObject('booking.boevent')->send_admin_notification(true, $event, 'probe #48299', null);
        return 0;
    }, static fn(array $m, int $sms, int $n): ?string => count($m) >= 1 && $sms === 0 ? null : 'expected at least one staff mail and no sms; got ' . count($m) . " mail(s), $sms sms-stub call(s)"],

    'A5' => [':350', function () use ($resourceOfBuilding, $from, $to, $maildata, $systemMessage): int {
        $allocation = ['building_id' => PL_BUILDING, 'building_name' => 'Probe bygg', 'resources' => [$resourceOfBuilding()], 'organization_name' => 'Probe', 'from_' => $from, 'to_' => $to];
        createObject('booking.boallocation')->send_admin_notification($allocation, $maildata, $systemMessage);
        return 0;
    }, static fn(array $m, int $sms, int $n): ?string => count($m) >= 1 && $sms === 0 ? null : 'expected at least one staff mail and no sms; got ' . count($m) . " mail(s), $sms sms-stub call(s)"],

    'A6' => [':350', function () use ($resourceOfBuilding, $from, $to, $maildata, $systemMessage): int {
        $booking = ['building_id' => PL_BUILDING, 'building_name' => 'Probe bygg', 'group_name' => 'Probe', 'resources' => [$resourceOfBuilding()], 'from_' => $from, 'to_' => $to];
        createObject('booking.bobooking')->send_admin_notification($booking, $maildata, $systemMessage, null);
        return 0;
    }, static fn(array $m, int $sms, int $n): ?string => count($m) >= 1 && $sms === 0 ? null : 'expected at least one staff mail and no sms; got ' . count($m) . " mail(s), $sms sms-stub call(s)"],

    'A7' => ['soreg :271', function () use ($lostpwAccount): int {
        $acct = $lostpwAccount();
        $_REQUEST['logindomain'] = 'default';
        // the real route runs as `registration`: its template root, not the bookingfrontend one this process started with
        \App\helpers\Template::getInstance()->set_root(PHPGW_SERVER_ROOT . '/registration/templates/base');
        $so = createObject('registration.soreg');
        echo '  registration config on this database: username_is = ' . var_export($so->config['username_is'] ?? null, true) . "; set to 'username' on this instance, in memory only\n";
        $so->config['username_is'] = 'username';
        $error = $so->lostpw1($acct['account_lid']);
        $GLOBALS['pl_a7'] = ['account_id' => (int)$acct['account_id'], 'error' => $error, 'pref_email' => json_decode((string)$acct['preference_json'], true)['email'] ?? ''];
        return 0;
    }, static function (array $m, int $sms, int $n): ?string {
        $a = $GLOBALS['pl_a7'] ?? null;
        if (count($m) !== 1) {
            return 'expected exactly the reset mail; got ' . count($m) . ' mail(s)' . ($a && $a['error'] ? ', error: ' . substr($a['error'], 0, 80) : '');
        }
        $to = md5(strtolower(trim((string)$m[0]['to'])));
        return $to === md5(strtolower(trim(stripslashes((string)$a['pref_email'])))) ? null : 'the reset mail did not go to the account\'s stored preference email';
    }],

    'A8' => ['finding (a): :189 :247 :350, partial #2', function () use ($secondPartial, $rowDigest): int {
        $second = $secondPartial();
        $session = 'probe48299-' . bin2hex(random_bytes(4));
        $db = pr_db();
        $db->prepare("UPDATE bb_application SET status = 'NEWPARTIAL1', session_id = ? WHERE id IN (?, ?)")->execute([$session, PR_APPLICATION, $second]);
        $bo = createObject('booking.boapplication');
        $partials = $bo->get_partials_list($session);
        $who = static fn(): array => [
            'settings' => (int)(\App\modules\phpgwapi\services\Settings::getInstance()->get('user')['account_id'] ?? 0),
            'helper' => (int)booking_account_helper::current_account_id(),   // what authorize_write() reads, fresh, on every call
        ];
        $trace = [];
        foreach ($partials['results'] as $i => $application) {
            unset($application['frontend_modified']);
            $application['status'] = 'NEW';
            $application['created'] = 'now';
            $application['modified'] = 'now';
            $application['session_id'] = null;
            $t = ['id' => (int)$application['id'], 'before_update' => $who()];
            $bo->update($application);
            $t['after_update'] = $who();
            $t['row'] = $rowDigest((int)$application['id']);
            $bo->send_notification($application, true);
            $t['after_notify'] = $who();
            $t['row_notify'] = $rowDigest((int)$application['id']);
            $trace[$i] = $t;
        }
        $GLOBALS['pl_a8'] = ['partials' => count($partials['results']), 'trace' => array_values($trace)];
        return 0;
    }, static function (array $m, int $sms, int $n): ?string {
        $a = $GLOBALS['pl_a8'] ?? null;
        if (!$a || $a['partials'] !== 2) {
            return 'expected two partials of the session; got ' . ($a['partials'] ?? 'none');
        }
        foreach ($a['trace'] as $k => $t) {
            echo '  partial #' . ($k + 1) . ' (application ' . $t['id'] . '): account id before update ' . $t['before_update']['settings'] . '/' . $t['before_update']['helper']
                . ', after update ' . $t['after_update']['settings'] . '/' . $t['after_update']['helper']
                . ', after send_notification ' . $t['after_notify']['settings'] . '/' . $t['after_notify']['helper'] . '  (Settings/authorize_write)' . "\n";
        }
        $p1 = $a['trace'][0];
        $p2 = $a['trace'][1];
        $GLOBALS['pl_a8_checks'] = [
            'control: partial #1 is updated as the citizen (nothing has flipped yet)' => $p1['before_update']['settings'] === PR_CITIZEN && $p1['before_update']['helper'] === PR_CITIZEN && $p1['after_update']['settings'] === PR_CITIZEN,
            "FINDING (a): partial #2's bo->update() RUNS as the citizen — Settings['user']['account_id'] before it and after it, and what authorize_write() reads" => $p2['before_update']['settings'] === PR_CITIZEN && $p2['before_update']['helper'] === PR_CITIZEN && $p2['after_update']['settings'] === PR_CITIZEN && $p2['after_update']['helper'] === PR_CITIZEN,
        ];
        return count($m) >= 2 ? null : 'expected at least one mail per partial; got ' . count($m);
    }],
];

$results = [];
foreach ($arms as $name => [$paths, $arm, $expect]) {
    if ($only !== null && !in_array($name, $only, true)) {
        continue;
    }
    echo "arm $name  [$paths]\n";
    pl_seed_citizen();
    MailDiskWriter::$captured = [];
    sms_sms::$calls = 0;
    $GLOBALS['pl_a7'] = null;
    $before = pl_state();
    $describeBefore = pr_describe();
    $log = tempnam(sys_get_temp_dir(), 'pl_log_');
    $prevLog = ini_set('error_log', $log);
    $db = pr_db();
    $db->transaction_begin();
    $error = null;
    $synth = 0;
    try {
        $synth = $arm();
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $stillOpen = (bool)$db->inTransaction();
    $db->transaction_abort();
    ini_set('error_log', $prevLog === false ? '' : $prevLog);
    $logged = array_values(array_filter(array_map('trim', file($log) ?: [])));
    @unlink($log);

    $after = pl_state();
    $mails = MailDiskWriter::$captured;
    $smsCalls = sms_sms::$calls;
    echo "  before: $describeBefore\n  after:  " . pr_describe() . "\n";
    echo '  Settings[user] sha1 ' . substr($before['settings_user_sha1'], 0, 12) . ' → ' . substr($after['settings_user_sha1'], 0, 12)
        . '; singleton sha1 ' . substr($before['singleton_sha1'], 0, 12) . ' → ' . substr($after['singleton_sha1'], 0, 12)
        . '; Translation ' . $before['translation'] . ' → ' . $after['translation'] . "\n";
    $problems = $error === null ? $expect($mails, $smsCalls, (int)$synth) : null;

    $ok("[$name] IDENTITY: sha1(serialize(Settings['user'])) is byte-equal at the END", $after['settings_user_sha1'] === $before['settings_user_sha1'], pr_describe());
    $ok("[$name] IDENTITY: the Preferences singleton's state and the Translation language are byte-equal at the END", $after['singleton_sha1'] === $before['singleton_sha1'] && $after['translation'] === $before['translation'], pr_describe());
    $ok("[$name] the arm ran to the end and rolled back (nothing committed)", $error === null && $stillOpen, ($error ?? '') . ($stillOpen ? '' : ' (the transaction was closed by the code under test)'));
    $ok("[$name] it did the work (mails: " . count($mails) . ", sms-stub calls: $smsCalls)", $problems === null && $error === null, (string)($problems ?? $error));
    if ($name === 'A8') {
        foreach ($GLOBALS['pl_a8_checks'] ?? [] as $label => $cond) {
            $ok("[A8] $label", $cond);
        }
    }
    $redis = array_values(array_filter($logged, static fn(string $l): bool => str_contains($l, 'Redis notification error')));
    $errs = array_values(array_filter($logged, static fn(string $l): bool => !str_contains($l, 'Redis notification error') && preg_match('/fail|error|exception/i', $l)));
    echo '  recorded: ' . count($mails) . " mail(s); sms-stub calls: $smsCalls; error_log: " . count($logged) . ' line(s), ' . count($redis) . ' expected Redis-unreachable, ' . count($errs) . ' failure-like' . ($errs ? ' — first: ' . substr(preg_replace('/\S+@\S+/', '<addr>', $errs[0]), 0, 200) : '') . "\n";

    // A7's reset link carries md5(time() . lid . randomstring(32)): a new token on every run, base against base too
    $normalised = array_map(static fn(array $m): array => ['body' => preg_replace('/reg_id=[0-9a-f]{32}/', 'reg_id=<token>', (string)$m['body'])] + $m, $mails);
    $results[$name] = ['mails' => pr_mail_digest($normalised), 'sms_stub_calls' => $smsCalls, 'failure_log_lines' => count($errs), 'identity_equal' => $after === $before]
        + ($name === 'A8' ? ['a8_rows' => [array_column($GLOBALS['pl_a8']['trace'] ?? [], 'row'), array_column($GLOBALS['pl_a8']['trace'] ?? [], 'row_notify')]] : []);
}

// ---- the source guard: after the fix no with-id singleton call is left in the two files --------------------------------------
echo "source guard\n";
$ok("booking_boapplication creates no Preferences singleton for an id (:189 :247 :350)", $guard['booking_boapplication'] === 0, $guard['booking_boapplication'] . ' call(s) left');
$ok("registration_soreg creates no Preferences singleton for an id (:271)", $guard['registration_soreg'] === 0, $guard['registration_soreg'] . ' call(s) left');

echo "add_contact shape guard (A8 copies this sequence)\n";
$uiSource = (string)file_get_contents(PHPGW_SERVER_ROOT . '/booking/inc/class.uiapplication.inc.php');
$ok('uiapplication::add_contact still has the sequence A8 replicates', $addContactShape($uiSource) === '', $addContactShape($uiSource));
$doctored = str_replace("\$application['session_id'] = null;", "\$application['session_id_'] = null;", $uiSource);
$ok('control: the shape guard FAILS on a doctored copy (a line of the sequence renamed)', $addContactShape($doctored) !== '', 'it accepted the doctored copy');
$ok('control: the shape guard FAILS when send_notification comes before update', $addContactShape(str_replace("\$receipt = \$this->bo->update(\$application);", "// moved", $uiSource)) !== '');

if ($outFile !== null) {
    file_put_contents($outFile, json_encode($results, JSON_PRETTY_PRINT));
    echo "wrote $outFile\n";
}
if ($compareFile !== null) {
    $other = json_decode((string)file_get_contents($compareFile), true) ?: [];
    echo "compare with $compareFile (recipients + subject + sms-stub calls must be equal arm by arm; body md5 is informational)\n";
    foreach ($results as $name => $r) {
        if (!isset($other[$name])) {
            $ok("[$name] present in the compared run", false);
            continue;
        }
        $key = static fn(array $m): array => array_map(static fn($x) => [$x['to'], $x['cc'], $x['bcc'], $x['subject_md5']], $m);
        $sortedThis = $key($r['mails']);
        $sortedThat = $key($other[$name]['mails']);
        sort($sortedThis);
        sort($sortedThat);
        $ok("[$name] same mails (count, To/Cc/Bcc, subject) and same sms-stub calls as the compared run", $sortedThis === $sortedThat && $r['sms_stub_calls'] === $other[$name]['sms_stub_calls'], count($r['mails']) . ' vs ' . count($other[$name]['mails']) . ' mail(s), ' . $r['sms_stub_calls'] . ' vs ' . $other[$name]['sms_stub_calls'] . ' sms-stub call(s)');
        if (isset($r['a8_rows'])) {
            $ok("[$name] update() and send_notification() left the SAME application row and comment rows for each partial as the compared run (no stored column carries the acting identity)", $r['a8_rows'] === ($other[$name]['a8_rows'] ?? null), 'rows differ: update() writes something that depends on who the request runs as');
        }
        $bodiesThis = array_column($r['mails'], 'body_md5');
        $bodiesThat = array_column($other[$name]['mails'], 'body_md5');
        sort($bodiesThis);
        sort($bodiesThat);
        echo '  [' . $name . '] bodies ' . ($bodiesThis === $bodiesThat ? 'identical' : 'DIFFER') . " (informational)\n";
    }
}

echo 'PROBE: ' . ($fail ? "FAIL $fail of " . ($pass + $fail) : "PASS $pass") . "\n";
exit($fail ? 1 : 0);
