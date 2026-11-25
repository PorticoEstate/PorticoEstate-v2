<?php

/**
 * REQUEST-LEVEL identity probe (task #47037, validation 46044): after a citizen's request has done
 * everything it does with preferences, is the request STILL the citizen's?
 *
 *   docker exec pe-api php /tmp/t47037/<name>/tests/preferences-reader/probe_identity.php [EXPORT_DIR] [--arms=a,b] [--out=file.json] [--compare=base.json]
 *
 * See bootstrap.php for how to put a tree into the container, and for why this is harmless.
 *
 * Every arm seeds the citizen (Settings['user'] 1022 / en + the Preferences singleton + the
 * Translation language), runs REAL production code for one citizen-reachable request path in this one
 * process, and asserts that Settings['user'], every public field of the Preferences singleton and the
 * Translation language are BYTE-EQUAL at the END. Each arm runs in a transaction that is rolled back.
 *
 *   T       time change: the post-commit tail of AllocationTimeChangeService::request(): the queued
 *           comment's deferred group mail + bell (flushNotifications), then the case officer's own
 *           mail (mailCaseOfficer)                                               → :785 + AllocationTimeChangeNotifier
 *   G1, G2  a comment / a status change / a withdrawal: ApplicationCommentsService::addComment queues,
 *           flushPendingNotifications runs the deferred group mail + bell     → :785
 *           (G2 carries the occurrenceContext a withdrawal and a time change pass)
 *   S       checkout: ApplicationService::sendGroupedApplicationNotifications for a NEW application
 *                                                                              → :425, :711, :785
 *   S-off   EmailService::sendCaseOfficerNotifications                         → :425 (+ :785)
 *   S-sms   EmailService::sendSmsNotifications                                 → :711 (+ :785)
 *   K       catering: HospitalityOrderController::recordApplicantChange, added / changed / cancelled
 *                                                                              → CateringChangeNotifier::loadOfficer
 *
 * Why the arms stop where they do. changeTime()/withdraw()/addStatusChangeComment() COMMIT their own
 * transaction (Db::transaction_commit() commits even when nested), so they cannot run inside a
 * rolled-back arm. Everything they do AFTER the commit — where all the preference reads are — does run,
 * through the same objects and methods. Nothing before the commit reads preferences (rg: the only
 * `phpgwapi.preferences`-with-id references under booking/ and bookingfrontend/ are the sites above and
 * the legacy twins in booking/inc/class.boapplication.inc.php).
 *
 * Positive controls (on every run): CreateObject('phpgwapi.preferences', <officer>) CHANGES the
 * snapshot, CreateObject without an id does not, re-seeding restores it exactly. On a base export the
 * arms FAIL (the flip is real); on the fix they PASS; mutate.php shows each site's own arm go RED.
 *
 * Besides identity every arm records what it mailed (counts, md5 of each address/subject/body) and
 * what error_log said, so a base run and a fix run can be compared: --out writes them, --compare reads
 * the other run's file and reports the arms whose recipients or subjects differ.
 *
 * Exit code: 0 all pass, 1 any failure.
 */

require __DIR__ . '/bootstrap.php';

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

pr_bootstrap($export, pr_files_under_test());
pr_assert_fixture();

use App\modules\booking\services\AllocationTimeChangeMailRenderer;
use App\modules\booking\services\EmailService;
use App\modules\bookingfrontend\controllers\applications\HospitalityOrderController;
use App\modules\bookingfrontend\services\AllocationTimeChangeService;
use App\modules\bookingfrontend\services\applications\ApplicationCommentsService;
use App\modules\bookingfrontend\services\applications\ApplicationService;
use App\modules\phpgwapi\services\MailDiskWriter;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;

$pass = 0;
$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : "  — $detail") . "\n";
};

// WHICH code is under test, said by the code and not by the flag: where each site lives and whether it still calls the singleton
$sites = [
    'EmailService' => new ReflectionClass(EmailService::class),
    'AllocationTimeChangeNotifier' => new ReflectionClass(\App\modules\bookingfrontend\services\AllocationTimeChangeNotifier::class),
    'CateringChangeNotifier' => new ReflectionClass(\App\modules\bookingfrontend\services\applications\CateringChangeNotifier::class),
];
foreach ($sites as $name => $rc) {
    $src = (string)file_get_contents($rc->getFileName());
    // strip comments so a docblock that MENTIONS the call is not mistaken for one
    $code = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($t) ? $t[1] : $t;
    }
    $n = preg_match_all("/CreateObject\(\s*'phpgwapi\.preferences'\s*,/i", $code);
    echo sprintf("  %-30s %s  calls CreateObject('phpgwapi.preferences', id): %d site(s)\n", $name, str_starts_with($rc->getFileName(), $export) ? 'from the export' : 'FROM ' . $rc->getFileName(), $n);
}
echo '  PreferencesReader: ' . (class_exists(\App\modules\phpgwapi\services\PreferencesReader::class) ? 'present in the export' : 'absent (a base export)') . "\n";

// ---- instrument controls: the snapshot CAN say «unchanged» and CAN say «changed» --------------------------------
echo "controls\n";
pr_seed_citizen();
$s0 = pr_snapshot();
CreateObject('phpgwapi.preferences');
$ok('control A: CreateObject(preferences) WITHOUT an id leaves the snapshot unchanged', pr_snapshot() === $s0);
CreateObject('phpgwapi.preferences', PR_OFFICER);
echo '  after CreateObject(preferences, ' . PR_OFFICER . '): ' . pr_describe() . "\n";
$ok('control B: CreateObject(preferences, officer) CHANGES the snapshot (the instrument can fail)', pr_snapshot() !== $s0);
pr_seed_citizen();
$ok('control C: re-seeding the citizen restores the snapshot exactly', pr_snapshot() === $s0);

// ---- the arms -------------------------------------------------------------------------------------------------------
$context = ['resource_name' => 'Storsal', 'date' => '10.12.2026', 'old_time' => '12:00–14:00', 'new_time' => '12:30–16:00'];
$applicationRow = static function (): array {
    $s = pr_db()->prepare('SELECT * FROM bb_application WHERE id = ?');
    $s->execute([PR_APPLICATION]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
$line = static fn(string $name, string $qty, string $price): array => [
    'article_name' => $name, 'unit' => 'stk', 'quantity' => $qty, 'unit_price' => $price,
    'amount' => number_format((float)$qty * (float)$price, 2, '.', ''), 'comment' => null,
];
$order = static fn(array $lines, array $over = []): array => array_merge([
    'id' => 7, 'status' => 'pending', 'hospitality_name' => 'Kantina', 'location_name' => 'Storsal',
    'serving_time_iso' => '2026-11-10 13:00:00', 'comment' => null, 'special_requirements' => null,
    'lines' => $lines, 'total_amount' => array_sum(array_map(static fn($l) => (float)$l['amount'], $lines)),
], $over);

/** The catering controller of the citizen's request, with its real notifier and a real order repository. */
$catering = static function (string $kind) use ($applicationRow, $line, $order): void {
    $rc = new ReflectionClass(HospitalityOrderController::class);
    $c = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('orderRepo')->setValue($c, new \App\modules\booking\repositories\HospitalityOrderRepository());
    $rc->getProperty('notifier')->setValue($c, new \App\modules\bookingfrontend\services\applications\CateringChangeNotifier());
    $before = $order([$line('Kaffe', '3.00', '45.00')]);
    $after = $order([$line('Kaffe', '4.00', '45.00')]);
    $args = match ($kind) {
        'added' => [null, $after],
        'changed' => [$before, $after],
        'cancelled' => [$before, $order([$line('Kaffe', '3.00', '45.00')], ['status' => 'cancelled'])],
    };
    // bookingUserId null: no case entry is written (as for a hand-made ?secret= request), the officer is still told
    $rc->getMethod('recordApplicantChange')->invoke($c, $kind, $applicationRow(), $args[0], $args[1], null);
};

/**
 * name => [site, callable, expect]. expect(list of recorded mails): null = fine, a string = what is wrong.
 * The minimum a mail count must reach keeps an arm that silently did nothing from passing.
 */
$arms = [
    'T' => ['AllocationTimeChangeNotifier + :785', function () use ($context): void {
        $svc = new AllocationTimeChangeService();
        $rc = new ReflectionClass($svc);
        $comments = $rc->getProperty('commentsService')->getValue($svc);
        $comments->addComment(PR_APPLICATION, 'probe #47037 time change', 'comment', 'Probe Søker', $context);
        $rc->getMethod('flushNotifications')->invoke($svc, 498808);
        $rc->getMethod('mailCaseOfficer')->invoke($svc, AllocationTimeChangeMailRenderer::KIND_REQUESTED, PR_APPLICATION, $context, 'probe #47037', 'Probe Søker');
    }, static fn(array $m): ?string => count($m) >= 2 ? null : 'expected the group mail(s) AND the officer mail, got ' . count($m)],

    'G1' => [':785', function (): void {
        $svc = new ApplicationCommentsService();
        $svc->addComment(PR_APPLICATION, 'probe #47037 comment', 'comment', 'Probe Søker');
        $svc->flushPendingNotifications();
    }, static fn(array $m): ?string => count($m) >= 1 ? null : 'expected at least one group mail, got none'],

    'G2' => [':785 (occurrence context)', function () use ($context): void {
        $svc = new ApplicationCommentsService();
        $svc->addComment(PR_APPLICATION, 'probe #47037 withdrawal', 'comment', 'Probe Søker', ['resource_name' => $context['resource_name'], 'date' => $context['date'], 'time' => $context['old_time']]);
        $svc->flushPendingNotifications();
    }, static fn(array $m): ?string => count($m) >= 1 ? null : 'expected at least one group mail, got none'],

    'S' => [':425 + :711 + :785', function (): void {
        pr_db()->exec("UPDATE bb_application SET status = 'NEW' WHERE id = " . PR_APPLICATION);   // rolled back: only a NEW application fans out to the officers
        $svc = new ApplicationService();
        (new ReflectionMethod($svc, 'sendGroupedApplicationNotifications'))->invoke($svc, [['id' => PR_APPLICATION, 'parent_id' => null]]);
    }, static fn(array $m): ?string => count($m) >= 1 ? null : 'expected at least the applicant\'s mail, got none'],

    'S-off' => [':425 (+ :785)', function () use ($applicationRow): void {
        $app = $applicationRow();
        $stmt = pr_db()->prepare('SELECT resource_id FROM bb_application_resource WHERE application_id = ?');
        $stmt->execute([PR_APPLICATION]);
        $app['resources'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        (new EmailService())->sendCaseOfficerNotifications($app);
    }, static fn(array $m): ?string => null],

    'S-sms' => [':711 (+ :785)', function () use ($applicationRow): void {
        $config = (new ReflectionMethod(EmailService::class, 'getBookingConfig'))->invoke(new EmailService());
        if (!empty($config['application_sms_created'])) {
            throw new \LogicException('ABORT: application_sms_created is set, sendSmsNotifications would SEND an SMS');
        }
        (new EmailService())->sendSmsNotifications($applicationRow());
    }, static fn(array $m): ?string => null],

    'K-added' => [':96', fn() => $catering('added'), static fn(array $m): ?string => count($m) === 1 ? null : 'expected exactly the officer\'s mail, got ' . count($m)],
    'K-changed' => [':96', fn() => $catering('changed'), static fn(array $m): ?string => count($m) === 1 ? null : 'expected exactly the officer\'s mail, got ' . count($m)],
    'K-cancelled' => [':96', fn() => $catering('cancelled'), static fn(array $m): ?string => count($m) === 1 ? null : 'expected exactly the officer\'s mail, got ' . count($m)],
];

$results = [];
foreach ($arms as $name => [$sitesHit, $arm, $expect]) {
    if ($only !== null && !in_array($name, $only, true)) {
        continue;
    }
    echo "arm $name  [$sitesHit]\n";
    pr_seed_citizen();
    MailDiskWriter::$captured = [];
    $snapBefore = pr_snapshot();
    $describeBefore = pr_describe();
    $log = tempnam(sys_get_temp_dir(), 'pr_log_');
    $prevLog = ini_set('error_log', $log);
    $db = pr_db();
    $db->transaction_begin();
    $error = null;
    try {
        $arm();
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $stillOpen = (bool)$db->inTransaction();
    $db->transaction_abort();
    ini_set('error_log', $prevLog === false ? '' : $prevLog);
    $logged = array_values(array_filter(array_map('trim', file($log) ?: [])));
    @unlink($log);

    $snapAfter = pr_snapshot();
    echo "  before: $describeBefore\n  after:  " . pr_describe() . "\n";
    $mails = MailDiskWriter::$captured;
    $problems = $expect($mails);

    $ok("[$name] IDENTITY: Settings[user] + the Preferences singleton + the Translation language are byte-equal at the END of the request", $snapAfter === $snapBefore, pr_describe());
    $ok("[$name] the arm ran to the end and rolled back (nothing committed)", $error === null && $stillOpen, ($error ?? '') . ($stillOpen ? '' : ' (the transaction was closed by the code under test)'));
    $ok("[$name] it did the work (mail count: " . count($mails) . ')', $problems === null, (string)$problems);
    // The Redis publish is pointed at an unresolvable host on purpose (bootstrap.php): those lines are expected and do not count.
    $redis = array_values(array_filter($logged, static fn(string $l): bool => str_contains($l, 'Redis notification error')));
    $errs = array_values(array_filter($logged, static fn(string $l): bool => !str_contains($l, 'Redis notification error') && preg_match('/fail|error|exception/i', $l)));
    echo '  recorded: ' . count($mails) . ' mail(s); error_log: ' . count($logged) . ' line(s), ' . count($redis) . ' expected Redis-unreachable, ' . count($errs) . ' failure-like' . ($errs ? ' — first: ' . substr(preg_replace('/\S+@\S+/', '<addr>', $errs[0]), 0, 200) : '') . "\n";

    $results[$name] = ['mails' => pr_mail_digest($mails), 'failure_log_lines' => count($errs), 'identity_equal' => $snapAfter === $snapBefore];
}

if ($outFile !== null) {
    file_put_contents($outFile, json_encode($results, JSON_PRETTY_PRINT));
    echo "wrote $outFile\n";
}
if ($compareFile !== null) {
    $other = json_decode((string)file_get_contents($compareFile), true) ?: [];
    echo "compare with $compareFile (recipients + subject must be equal arm by arm; body md5 is informational)\n";
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
        $ok("[$name] same mails (count, To/Cc/Bcc, subject) as the compared run", $sortedThis === $sortedThat, count($r['mails']) . ' vs ' . count($other[$name]['mails']));
        $bodiesThis = array_column($r['mails'], 'body_md5');
        $bodiesThat = array_column($other[$name]['mails'], 'body_md5');
        sort($bodiesThis);
        sort($bodiesThat);
        echo '  [' . $name . '] bodies ' . ($bodiesThis === $bodiesThat ? 'identical' : 'DIFFER') . " (informational)\n";
    }
}

echo 'PROBE: ' . ($fail ? "FAIL $fail of " . ($pass + $fail) : "PASS $pass") . "\n";
exit($fail ? 1 : 0);
